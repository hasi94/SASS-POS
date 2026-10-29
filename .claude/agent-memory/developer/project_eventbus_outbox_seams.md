---
name: project_eventbus_outbox_seams
description: T-018's fixture trap (a wrapper that re-wipes a tenant on every call), a signed-by-default Carbon method, Artisan::output()'s drain semantics, and two PHPStan-vs-runtime-check frictions found building packages/eventbus
metadata:
  type: project
---

Five things measured building the event bus/outbox package (T-018), each cost a red run to find.

- **A convenience wrapper around `AccessTenantFixture` must not call `boot()` more than
  once per test.** `AccessTenantFixture::boot()` deletes and re-copies every tenant's
  database from the template on *every call* - it is meant to run once, in
  `beforeEach()`. A helper like `eventbusEnterTenant($tenant) { boot(); enter($tenant); }`
  looks convenient and is correct for a single-tenant test, but the moment a test calls it
  a second time (switching tenants, or re-entering the same one after `leave()`) it
  silently wipes out whatever was already written - with no exception, no row, and no
  failed assertion pointing at the cause (the SELECT after re-entry just finds nothing, and
  `filesize()`/`filemtime()` on the fixture file look unchanged because the template and
  the real file are both the same page-aligned SQLite size). Fix: the wrapper only calls
  `AccessTenantFixture::enter()`; the test's own `beforeEach()` calls `boot()` exactly once.
- **`CarbonImmutable::diffInSeconds()` is signed by default in this Carbon version, and the
  sign follows argument position, not which instant is earlier.** `$now->diffInSeconds($past)`
  returns a *negative* number when `$now` is after `$past` - confirmed by direct measurement
  (`"2026-01-01 00:01:30"->diffInSeconds("00:00:00")` gives `-90`, the reverse call gives
  `+90`). A `max(0, $signed)` pattern "fixing" an age calculation silently floors every
  negative result to `0` instead of erroring, which reads as "nothing is pending" rather
  than "the sign is backwards" - the bug this shape produced in `ReportOutboxAgeHandler`.
  Pass `absolute: true` explicitly rather than relying on operand order.
- **`Illuminate\Support\Facades\Artisan::output()` drains the underlying `BufferedOutput`
  (`fetch()` empties the buffer).** Calling it twice in one test returns the real output the
  first time and an empty string the second - including when the second call is just a
  debug `fwrite(STDERR, ...)` before the real assertion. Capture it into a variable once and
  reuse the variable.
- **Pest's `test()` helper has no `markTestSkipped()`** (PHPStan: `method.notFound` on
  `TestCall|HigherOrderTapProxy`). Call `$this->markTestSkipped()` from inside the `it()`
  closure body instead - and if a shared helper function needs to call it on the test's
  behalf, type that parameter `PHPUnit\Framework\TestCase`, not the project's `Tests\TestCase`:
  PHPStan infers a Pest closure's `$this` as the base PHPUnit type even when the file
  declares `uses(Tests\TestCase::class)`, and passing `$this` to a helper typed on the
  narrower subclass is a real `argument.type` error, not a style nit. `markTestSkipped()` is
  declared `: never`, so a helper function that calls it in one branch and returns a real
  value in the other needs no explicit third return path - PHPStan already knows that branch
  never completes.
- **Two PHPStan-vs-runtime-check frictions, both resolved without widening or ignoring
  anything (per [[project-backend-toolchain-constraints]]'s "do not suppress" rule):**
  - `$x?->nonNullableProp ?? $default` is flagged `nullsafe.neverNull` even when `$x` is
    genuinely nullable (confirmed by an isolated reproduction file, not just reading the
    message). The fix is not to silence it: use an explicit `$x !== null ? $x->prop :
    $default` ternary, which is also strictly better - plain `->` on a null `$x` would emit
    a real "Attempt to read property on null" warning that `?->` was silently swallowing.
  - `is_a($value, Interface::class, true)` is flagged `function.alreadyNarrowedType`
    ("always true") when `$value`'s inferred type already carries a trusted
    `class-string<Interface>` generic from an upstream `@return` (e.g. a published
    contract's `eventConsumers(): list<class-string<EventConsumer>>`) - even though the
    check is a genuine runtime guard against an implementer that lies about that generic
    (exactly the case a "refuses a class that does not implement the interface" test has to
    produce). Fix: move the check behind a same-file private method whose parameter is
    typed plain `string`, not the narrower generic - a real type boundary, not an inline
    `@var` override, so PHPStan evaluates the check fresh inside it. For the *test* that
    needs to hand in a non-implementing class, route it through a one-off local provider
    whose own `eventConsumers()` is declared `@return list<class-string>` rather than
    reusing a shared, tightly-typed fixture provider - threading a bad value through a
    provider typed to match the interface exactly reopens the same fight at the call site.

- **A third PHPStan friction, found fixing review round 1's R-1 (an error reply from a
  dynamically-typed Redis client must become a thrown failure):** calling a by-reference
  out-parameter method (`predis`' `executeRaw(array $args, &$error)`) on a `mixed`/`object`
  receiver leaves PHPStan treating the out-parameter's type as unchanged after the call - an
  `if ($error)` right after `$error = false; $client->executeRaw($args, $error);` is reported
  `if.alwaysFalse`, because PHPStan has no declared signature to model the mutation through.
  Typing the receiver parameter natively as the real interface does not help (still
  `alwaysFalse`) and is a runtime risk anyway (the real client never implements a
  project-local interface, so a native type hint on it would throw `TypeError`). The fix
  that is neither a suppression nor a risk: declare a never-instantiated interface
  documenting the method's real by-ref signature, split the by-ref call into its own
  private method whose native parameter stays untyped (`object`) but whose **docblock**
  narrows it to that interface (`@param PredisRawClient $client`) - a real type boundary one
  call frame away from the `method_exists()` guard. PHPStan then models the mutation and the
  `alwaysFalse` report disappears. Narrowing the *same* method's parameter instead of a
  split-off one reproduces the `function.alreadyNarrowedType` friction already on file here
  (the `method_exists()` guard in that same method becomes "always true") - the split is
  what keeps the guard and the mutation tracking in two different frames, each legitimate in
  its own.

**Why:** every one of these was found by a red run, not by reading - the tenant-fixture one
especially, because its only symptom was "SELECT finds nothing" with every other signal
(file size, mtime, no exception) looking normal.

**How to apply:** before writing a new convenience wrapper over an existing test fixture,
check whether the fixture's own "reset" method is idempotent-safe to call more than once per
test - most are not, by design. Before accepting a `max(0, ...)` or similar clamp as correct,
check the *sign convention* of whatever date-diff call feeds it. Related:
[[project-backend-toolchain-constraints]], [[project_http_test_seams]],
[[feedback_subagenthandback_is_terminal_not_a_status_update]].
