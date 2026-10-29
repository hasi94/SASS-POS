# QA Report: T-019 Plugin framework (FP-213)

## Verdict: PASS (2 minor test-coverage defects filed for follow-up, not blocking)

Branch `ai/T-019-plugin-framework`, dev commit `87a30a7`. Reviewed against
`docs/specs/T-019-plugin-framework.md` (AC-1 to AC-48, as amended by D-14/D-15),
`docs/decisions/T-019-plugin-framework.md` (D-1 to D-15), and
`docs/plans/T-019-plugin-framework.md` (18 steps, step 17 mandatory to `qa`).

No other agent was found running a gate or pest process in this worktree.
Two pre-existing, idle processes (`queue:work`, `php -S` dev server) were
present throughout but never touched by either side of this run.

## Gate run (personally measured, solo)

`cd modules/host && composer check`, one full run, no overlap with any other
process:

- lint, stan, deptrac: all clean (the run reached the test step, which only
  happens once every earlier step exits 0).
- **Tests: 2499 passed, 12 failed, 21 skipped, 17712 assertions, 468.95s.**
- Gate headroom: `ratio=1.92` (manifest-sourced limit), below the 2x floor —
  matches dev's own flag of FP-328 continuing to narrow; not something this
  task's scope fixes.

This matches dev's claimed numbers (2499/12/21, ~482s) within normal run-to-run
variance. The 12 failures are entirely inside the project's known FP-316
cluster (module-registry/bootstrap-adjacent: `QaModuleRegistryContractTest`,
with the rotating-shape signature also seen in `QaModuleNameValidationTest`,
`QaHealthProbeResilienceTest`, `ModulesEnvParsingTest` when I re-ran that
cluster in isolation — the names rotate across runs/parallelism, the count of
12 does not; this is the exact signature recorded from T-014's close-out).
Net delta against T-018's close-out baseline (2306/12/20): **+193 passed,
+0 failed, +1 skipped** — the +1 skip is `PluginSchemaEngineTest`'s AC-48 case,
predicted by plan step 14 and confirmed by direct code read (`->skip(fn():
bool => EngineConnection::onHold(), ...)`). None of the 12 failures touch
`packages/plugin-sdk`, `packages/platform`, or `packages/contracts` — not a
regression introduced by this task.

**Caveat on AC-46's literal wording.** The outer `composer check` process does
not exit 0 while FP-316's 12 pre-existing failures stand (the test step's exit
code propagates verbatim through `gate-step.php`). This has been true since
FP-316 was introduced and was accepted the same way at T-014/T-016/T-017/T-018
close-out (net delta zero/explained, not literal shell exit 0). Recording this
explicitly rather than silently asserting "exits 0" when it does not, literally.

## Step 17's 13 mandatory mutations

All thirteen applied to real source, observed, reverted, and confirmed clean
(`git status` empty, and for several, `git diff` on the touched file
confirmed byte-identical) before moving to the next.

| # | Mutation | Result |
|---|---|---|
| 1 | Validator coerces mode case (`strtolower`) | **Caught.** `ManifestValidatorTest > refuses every unknown mode spelling` red on `Before`/`BEFORE`; reverted, green. |
| 2 | Runtime trusts the stored manifest instead of isolating a re-validation failure | **Caught**, but only with a materially different mutation than the literal wording. A shallow "catch and ignore" mutation doesn't compile usefully; I wrote the realistic bug (decode-and-register the hooks from the raw JSON on validator failure, skipping re-validation). `PluginRuntimeTest > isolates a plugin whose stored manifest fails re-validation...` went red (the corrupted plugin's `add` contribution appeared). Reverted, green. |
| 3 | Runtime loads `status in ('enabled','disabled')` | **Caught.** `GatewaySyncModesTest > ...(AC-17) with ('disabled')` red; reverted, green. |
| 4 | A `before` veto no longer stops the action (`if (false && $veto !== null)`) | **Caught.** `...vetoes and stops the action... (AC-23)` red (action ran); reverted, green. |
| 5 | Every filter receives the original payload, not the chained one | **Caught.** `...chains two filters in registration order (AC-24)` red (`'abx'` instead of `'ABx'`); reverted, green. |
| 6 | Breaker's `>=` becomes `>` | **Caught.** `...trips on exactly the fifth... (AC-31)` red; reverted, green. |
| 7 | `recordSuccess` is a no-op | **Caught.** `...counts consecutive failures, and a success resets... (AC-30)` red; reverted, green. |
| 8 | Breaker keyed by plugin only (drop `tenant_id`) | **Survived — real finding, filed as BUG-1 below.** The existing `...isolates one tripped (tenant, plugin) pair... (AC-32)` test stayed green under this mutation. I wrote and ran a disposable probe test reusing the *same* `pluginId` across `tenant-a`/`tenant-b` (not committed) — it went red under the mutation and green on the correct code, proving production `CircuitBreaker.php` is correct and the gap is in the existing test's data setup, not the implementation. |
| 9 | After-row written outside the transaction | **Non-discriminating in its literal form**, then **caught** with the actually-faithful version. Moving the `runAfters()` call to after the `$connection->transaction()` call (sequentially) doesn't change behaviour under test, because when the action throws, the exception propagates out of `dispatch()` before that line is ever reached either way — the "action throws" test can't tell the two forms apart. The version that genuinely reproduces the bug (writing the outbox row via its own autocommitted statement *before* the action's transaction opens, so it survives the action's later rollback) **did** turn `GatewayAfterModeTest > leaves zero outbox rows when the action throws...` red (1 row instead of 0). Reverted, green. |
| 10 | Enable on an already-`enabled` row re-runs the migration runner | **Caught.** `EnablePluginTest > ...(AC-14)` red (`$second->noop` false); reverted, green. |
| 11 | Licence validity ignores `status` (drop the `active` check in `isCurrent()`) | **Caught.** `...gives the same licenceRequired reason for an expired, revoked, or suspended licence (AC-16)` red (2 of 3 dataset rows); reverted, green. |
| 12 | Uninstall matches tables by prefix (a `LIKE` scan) instead of reading the plugin's own migration files | **Caught.** `UninstallPluginTest > leaves a prefix-collision plugin's tables alone` red (`plg_fixture_echo_two_notes` dropped); reverted, green. |
| 13 | `EchoAfter` drops its marker check | **Survived — real finding, filed as BUG-2 below.** `plg_fixture_echo_after_effects.event_id` is the table's primary key, and the handler writes with `insertOrIgnore`; the database enforces idempotency on that key regardless of the application-level `exists()` check. Both the "no-skip" and the "skip" (`$skipIdempotencyCheck=true`) paths in `PluginAfterHookConsumerTest`'s AC-29 test produce the same row counts whether or not the application check is present, because the redelivered envelope always carries the same literal event id and the primary key absorbs the duplicate either way. |

Section 9's pre-existing-files list matches `git show --stat 87a30a7` exactly
(12 source/config/doc files, 6 test/helper files, `composer.lock` unchanged) —
no undeclared edit found. `LicenceStatus::PLUGIN_TYPE` is an additive,
one-line constant; dev's own report already disclosed it as a narrowly-scoped
deviation, and it is consistent with everywhere else that reads it.

## Other required verifications

- **AC-47 (deptrac), independently re-run, not just read.** Planted
  `modules/demo/src/ZzQaDeptracProbe/BadImport.php` importing
  `Flexipos\PluginSdk\Gateway\Gateway` directly → `composer deptrac` reported
  `DependsOnDisallowedLayer ... Package_PluginSdk`, 1 violation. Swapped the
  import to `Flexipos\Contracts\Plugins\HookGateway` only → 0 violations.
  Deleted the probe directory, confirmed `git status` clean and deptrac green
  again (0 violations, 923 allowed — the baseline count).
- **`QaPluginSourceRulesTest` mutation-tested against the real scanned
  directory**, not just its own temp-dir control (the exact T-017/T-018
  round-1 trap): planted a real `proc_open(...)` call in
  `packages/plugin-sdk/src/Gateway/ZzQaProbe.php` → the AC-36 case failed,
  naming that real path. Planted a real `$x->update(['failure_count' =>
  1])` call in the same real directory → the breaker-column-writer case
  failed, naming that real path. Both files deleted afterward, `git status`
  clean, both cases green again. `QaPluginNoHttpSurfaceTest` already asserts
  against the live `Route::getRoutes()` table (its own control mounts a real
  route in the real route collection, not a fake list) — no further mutation
  needed there.
- **The veto-form bug fix dev reported** (`Gateway::runBefore()` originally
  only treated a thrown `VetoSignal` as a veto, missing the "returns a
  `Veto` object" form). Read `HookInvoker.php`/`Gateway.php`: line 125-127 of
  `Gateway.php` explicitly checks `$invocation->value instanceof Veto` as a
  second veto path, distinct from the `VetoSignal`-thrown path. Confirmed two
  separate tests genuinely exercise both forms: `EchoBefore` (the fixture
  used by the main AC-23 test) **returns** a `Veto` object; a second test,
  `...vetoes the same way whether the before handler returns or throws`,
  uses `ThrowingVetoBeforeHook`, which **throws** `VetoSignal`, and asserts
  the same outcome shape. Both pass. The fix and its test coverage are real.
- **Circuit breaker trip/refuse/reset mechanics**, run directly, not just
  read: `CircuitBreakerTest`'s 8 cases all green, including trip on exactly
  the 5th consecutive failure (not the 4th), isolation across tenant and
  plugin, a failure still counting when the caller's own transaction rolls
  back, and — the specific ask — `...is only returned to enabled by
  plugin:reset, not by time passing (AC-33)`: the test moves the clock
  forward 30 days and asserts the row is *still* `error`, then calls
  `plugin:reset` and asserts the row clears. No automatic/time-based path
  exists anywhere in `CircuitBreaker.php` (confirmed by reading it: `reset()`
  is the only method that writes `status='enabled'`).
- **`after`-mode genuinely rides T-018's EventBus/Outbox**, not a parallel
  mechanism: `Gateway::runAfters()` calls `Outbox::record(new NewEvent(...))`
  (T-018's one writer) inside the same transaction as the action.
  `PluginAfterHookConsumer implements EventConsumer`, declared through
  `PluginSdkServiceProvider implements DeclaresEventConsumers` — the same
  provider contract T-018's own consumers use.
  `PluginAfterHookConsumerTest > ...end to end through the real commands`
  calls the actual `Artisan::call('eventbus:relay', ['--once' => true, ...])`
  and `Artisan::call('eventbus:consume', ['--group' => ['plugins.after'],
  '--once' => true])` — T-018's real commands, not a stand-in — and the
  marker row lands. `ConsumerRegistry::groups()` includes `plugins.after`,
  confirmed by a direct test. Retry/dead-letter is inherited unchanged: a
  tripped plugin's `after` event is left unacknowledged and reaches T-018's
  dead-letter path (`PluginAfterHookConsumer::handle()` throws
  `PluginDispatchRefused` rather than acking).
- **ENGINE_HOLD respected for both halves.** AC-28 (Redis): correctly
  inherited NOT VERIFIED per T-018 D-1 (no Redis service/client/compose exists
  in this repo — unchanged fact, not re-measured here since nothing about
  that fact changed). AC-48 (MySQL/MariaDB): `PluginSchemaEngineTest.php`'s
  real-engine case carries `->skip(fn(): bool => EngineConnection::onHold(),
  'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold')` — read directly,
  and independently confirmed by the gate's own skip count (21 vs T-018's 20,
  exactly +1, matching the plan's own prediction). Neither case is silently
  omitted; both are marked and explained.

## Acceptance criteria coverage

All 48 ACs map to at least one passing, genuinely-exercising test per the
plan's own table (section 7), spot-checked above for the higher-risk ones
(AC-14, AC-16, AC-17, AC-23, AC-24, AC-27, AC-29, AC-30 to AC-34, AC-37, AC-47).
AC-28 and AC-48 are correctly NOT VERIFIED (engine-gated, explained above).
No AC found unproven; the two defects below are about the *strength* of two
existing tests, not about an AC being unproven — in both cases I independently
confirmed via a disposable, non-committed probe test that the production
behaviour the AC asks for is actually correct.

## Defects

### BUG-1 (minor, test-coverage gap — not a production defect)
- **File:** `packages/plugin-sdk/tests/Breaker/CircuitBreakerTest.php`, the
  AC-32 case (`...isolates one tripped (tenant, plugin) pair from another
  plugin and another tenant...`).
- **Steps:** `breakerRow()` generates a fresh random plugin name
  (`'fixture-breaker-'.bin2hex(random_bytes(3))`) on every call, so `rowA`
  (tenant-a) and `rowB` (tenant-b) are never the *same* plugin. AC-32
  explicitly requires isolation for "the same plugin for a different
  tenant", which this test never actually exercises.
- **Expected:** mutating `CircuitBreaker.php` to key both queries by
  `plugin_id` alone (dropping `tenant_id`) should turn this test red.
- **Actual:** the test stays green under that mutation.
- **Independent confirmation production code is correct:** I wrote a
  disposable probe reusing the *same* registered plugin for both
  `tenant-a` and `tenant-b`, then tripped it for `tenant-a` only. On the real
  code: tenant-b's row stayed `enabled`/`failure_count=0` (probe green). On
  the mutated code: tenant-b's row flipped to `error` (probe red). Probe was
  not committed (deleted after use); the finding is the test gap, not a
  functional regression.
- **Recommended fix:** add an optional plugin id/name parameter to
  `breakerRow()`, or a second explicit case, that enables the *same*
  registered plugin for two different tenants and asserts isolation that way.

### BUG-2 (minor, test-coverage gap — not a production defect)
- **File:** `packages/plugin-sdk/tests/Runtime/PluginAfterHookConsumerTest.php`,
  the AC-29 case (`...is idempotent by event id, and the control without the
  check gives two (AC-29)`).
- **Steps:** `plg_fixture_echo_after_effects.event_id` is the table's
  primary key (see its migration), and `EchoAfter::after()` writes with
  `insertOrIgnore`. Redelivering the same envelope therefore cannot create a
  second row regardless of whether `EchoAfter`'s own `exists()` check runs —
  the primary key absorbs the duplicate either way.
- **Expected:** removing `EchoAfter`'s `exists()` check should make the
  redelivery case show 2 markers for `evt-1` (demonstrating the application
  check is what provides idempotency).
- **Actual:** with the check removed, the full test (including its own
  `$skipIdempotencyCheck=true` "control" branch) still passes, because the
  "control" branch's final assertion checks the *cumulative* table row count
  across both `evt-1` and `evt-2` (1 + 1 = 2), not a genuine double-insert of
  the same event id — so the control was never discriminating in the first
  place, with or without my mutation.
- **No functional regression:** idempotency genuinely holds in production
  today, just enforced by the schema rather than demonstrated by this test.
- **Recommended fix:** either drop the primary key from the demonstration (use
  a non-unique marker column) or change the control to redeliver a *third*,
  never-before-seen event id while the check is disabled, and assert a
  distinct, unconditional second insert path (e.g. assert the handler's own
  method is called twice via a spy, not by counting rows a unique key already
  protects).

## Regression risks / notes

- FP-328's gate headroom (1.92, below the 2x floor) is a pre-existing,
  project-wide concern this task did not introduce and was not in scope to
  fix; flagged by dev and independently confirmed here.
- FP-316's 12 known failures are unrelated to this task's own packages;
  confirmed by file/package, not just by count.
- `AC-46`'s literal "exits 0" wording and the established project convention
  of tolerating FP-316 by explained net-delta are in tension; this has held
  since before T-014 and I am not treating it as a new deviation introduced
  by this task.
- Neither BUG-1 nor BUG-2 blocks sign-off: both are gaps in the *proof*, not
  the *behaviour*, independently confirmed by disposable probes against the
  real production code, which is correct in both cases.

Run 1: PASS (2 minor test-coverage defects filed for dev follow-up, not blocking).
