# Review: T-009 Authentication — login against the master, and tenant access

**Round 1 · 2026-10-20 · Verdict: CHANGES REQUESTED** (one Critical)

Reviewed `0cde20c^..HEAD`. **Counts re-measured by the reviewer, not taken from the brief:** the lead
said 44 commits; it is **45** — `f9d82e5` landed after the brief was written. 113 files, +15500/−12,
exact. `main..HEAD` is **122**, not 121, same off-by-one and same cause: **T-008 is approved but
unmerged and sits beneath this branch**, so only the T-009 range was reviewed.

**Gate run by the reviewer:** `composer check` → **exit 0, 1145 passed, 11 skipped, 6802 assertions**,
271.62 s (ratio 3.31 against the 900 s limit). Pint clean, PHPStan `[OK] No errors`, Deptrac 0
violations / 0 skipped. Reproduces QA's Run 2 figure to the assertion. Secret scan of the full diff:
clean; the only PEM-shaped literal is `"not-a-real-key"` in a resolver test.

> **The headline: one Critical that escapes the application entirely, and otherwise a standard the
> reviewer had not seen on this project before.** The two-part QA verdict holds and is not habitual.

---

## Critical

### R-1 · Passwords and refresh tokens are accepted from the **query string**

`modules/host/app/Http/Controllers/Auth/LoginController.php:53-54`, `RefreshController.php:52`,
`SelectTenantController.php:44`, `LogoutController.php:36`.

`$request->input($key)` is **not** a body read. Laravel's implementation
(`Illuminate/Http/Concerns/InteractsWithInput.php:110-115`) is:

```php
return data_get($this->getInputSource()->all() + $this->query->all(), $key, $default);
```

The query bag is merged as a fallback. Confirmed against the vendored framework by constructing a real
`Illuminate\Http\Request` with an empty JSON body and the values only in the URL:

```
POST /api/v1/auth/login?email=owner@example.com&password=hunter2
  -> input('email') = "owner@example.com" ; input('password') = "hunter2"
POST /api/v1/auth/refresh?refresh_token=SECRET-REFRESH-VALUE
  -> input('refresh_token') = "SECRET-REFRESH-VALUE"
```

So all four endpoints authenticate, rotate and select from URL parameters. **This violates the spec's
own absolute NFR, "Tokens never travel in URLs."** `docs/deployment-vps.md:31` puts nginx in front and
nginx logs the request URI by default, so a credential submitted this way is written in clear text to a
file nothing in this system prunes — plus proxy logs, browser history and `Referer`.

**Why nothing caught it, and this is the important part.** AC-26's harness is the strongest instrument
on the branch and it enumerates exactly four sinks — `login_history`, `security_events`, the
application log, exception messages — plus HTTP response bodies as a liveness check. **The request URL
is a fifth sink and it is in nobody's list.** The gate is green and would stay green while every
password on the platform was written to `access.log`; a mutant moving a credential into the URL would
redden nothing in 1145 tests. The team's own standard for this class of harm is D-38, where a password
reaching `login_history.email` was serious enough to change the spec mid-task. This is the same harm in
a sink that is larger, permanent and outside the application.

**Fix.** Read the body and only the body. `$request->post()` is insufficient because a JSON request
never populates the `request` bag. Add one accessor to the class that already exists for this purpose,
`modules/host/app/Http/Requests/AuthRequestContext.php`:

```php
public static function body(Request $request, string $key): mixed
{
    return data_get($request->getInputSource()->all(), $key);
}
```

then replace the five `input()` reads. **Test to pin it:** for each endpoint, send the credential only
in the query string with an empty JSON body and assert the ordinary refusal, with the same value in the
body succeeding in the same test as the positive control. Mutant: revert one controller to `input()`
and the test must redden.

---

## Warnings

### W-1 · FP-300's lockout race — the filing's reasoning is wrong, and the fix is three lines

`packages/auth/src/Features/Login/LoginHandler.php:232-283`. The increment is a genuine atomic
`UPDATE … failed_attempts + 1`; the lock decision is a separate `SELECT` plus an **unconditional**
reset-and-lock, so "lock applied once" is not structural and AC-17's "exactly one `security_events`
row" cannot hold under concurrency.

**Where the reviewer differs, and it matters:** the race **does not widen the guessing window at all**.
`isLocked()` is read at the *start* of each request, so N simultaneous wrong passwords all clear the
lock check and all reach the hasher however atomic the counter is. A perfectly atomic lockout behaves
identically for a burst. **The lockout's resistance to parallel guessing is not provided by this code
at all** — it is FP-199 (per-IP throttling), already filed and already blocking public launch.
**FP-300's real residue is duplicate `lockout` event rows, not a bypass.** Not Critical; leaving it for
the engine task is acceptable.

But the remedy is already in the neighbouring file. `RefreshTokenHandler::rotate()` (319-327) and
`revokeFamily()` (203-210) both use the correct shape — a conditional `UPDATE` whose affected-row count
is the decision. Applying it here deletes a query, makes exactly one request able to observe the
threshold, and converts AC-22's review half from "the increment is atomic, the lock is not" into "both
are". Sequential behaviour is unchanged, which is also the change's weakness: it should land **with**
the engine task's concurrent test, not instead of it.

### W-2 · Step 5b's Deptrac half is inert — the seventh instance, inside the fix for the sixth

`modules/host/deptrac.yaml:24-27`, `QaToolchainRegistryCoverageTest.php:146-152,233-241`.

PHPStan's half is real value. The Deptrac half buys nothing for this package: **every PHP file under
`packages/auth/tests` is in the global namespace**, and the only three class declarations
(`AuthFixtureCountingHasher`, `AuthFixtureRecordingLogger`, `TenantSelectionPolicyConsumer`) are global.
Every collector is `^Flexipos\\…`, so **no layer collects any token in that directory.**

The irony is in the comment the change shipped with — `deptrac.yaml:25-26` says "a layer is matched by
namespace and not by directory", which is exactly why the registration cannot work, and it was made
anyway. `qaDeptracGaps()` already has the right instrument (the probe-class check) and applies it to
`src` only.

**Fix:** extend the probe-class check to the tests directory, or namespace the auth tests
`Flexipos\Auth\Tests\`. **Do not delete the path** — PHPStan's half is earning its keep.

### W-3 · A soft-deleted account can still select a tenant

`packages/auth/src/Features/SelectTenant/SelectTenantHandler.php:163-172`. `actorEmail()` reads
`users.email` by `sub` with **no `deleted_at` filter and no status check**, unlike `LoginHandler::findUser()`
(296-299) and `RefreshTokenHandler::findUser()` (226-237), which apply both. The spec's Assumptions say
soft-deleted rows "count as absent everywhere", and this is the one place they do not.

Bounded: AC-48 accepts a disabled user passing verification until `exp`, and AC-36 revokes the new
family at first use, so the window is one access-token lifetime (~15 min), not 14 days. But the
docblock at 152-159 argues the null case is unreachable **considering only hard deletion** — soft
deletion is exactly what is reachable. **Fix:** `->whereNull('deleted_at')` at line 165, and correct
the docblock.

### W-4 · FP-297 will route attacker-controlled input into a throwing parser

`packages/auth/src/Support/IpAddress.php::pack()` throws on an unparseable address with the value in
the message. Today unreachable (`REMOTE_ADDR` only). When FP-297 adds `TrustProxies`, `$request->ip()`
becomes derived from `X-Forwarded-For`. Symfony's `getClientIps()` validates with `FILTER_VALIDATE_IP`
and discards invalid entries, so this likely stays safe — but it is one framework behaviour away from a
500 on the *history-write* path, after the password has been checked, with the attacker's string in the
message. **An acceptance item for FP-297, not a defect now.**

---

## Suggestions

- **S-1** `ReferencedPrivateKeyResolver.php:48` consults `$_SERVER` first, and under PHP-FPM `$_SERVER`
  carries request headers as `HTTP_*`. A `private_key_ref` of `env:HTTP_X_ANYTHING` would make an
  attacker-supplied header the signing key. Requires a bad `signing_keys` row, so operator-controlled;
  the cheap guard is refusing a name matching `/^HTTP_/`. Belongs to the key-rotation task.
- **S-2** `EmailAddress.php:125` — `preg_match('/\s/u', …)` without `(*UCP)` matches ASCII whitespace
  only, so a non-breaking space inside an address is "address-shaped". No criterion reaches it.
- **S-3** `login_history` has indexes on `(email, created_at)` and `(user_id, created_at)` but none on
  `tenant_id`, though AC-39/40 write it on every selection row.
- **S-4** Sixteen `.claude/agent-memory/**` files are in the production diff — worth a deliberate
  decision rather than drift.
- **S-5** The missing `TenantDatabaseNotActive` renderer is a **separate, unconditional defect** from
  FP-301's policy question: a commercial state should not page the on-call as an application error
  whatever the owner decides. Suggest splitting it out.

---

## Ruling on AC-52 — **PASS**, in the letter as well as the spirit

QA was right to refuse to rule and right to escalate. The antecedent of "it" in the third sentence is
AC-52 — the stanza claim — not the suite. Read that way the criterion is clean: **no file under
`packages/kernel/tests` appears anywhere in the T-009 diff**, so no stanza test was touched.

The five edited files belong to **AC-50**, and all six changed lines were checked: a header comment; the
`17 → 21` and `25 → 29` count literals, which AC-50 *requires* to move because it adds four tables; the
expected-table list gaining those four; and step 5b's guard, which is a **strengthening** shown red
first. No assertion weakened, no exemption added, nothing skipped. The inverse test is whether the edit
could hide anything — it cannot, because `MigrationTreeDisciplineTest` still asserts **exact equality**
on the table list, so a twenty-second table still fails.

---

## The four questions the lead asked

**1. Does the two-part verdict hold, or is it laundering something? It holds, and it is not habitual.**
The test is whether anything moved from the NOT VERIFIED column into the PASS column. It did not, and
the reviewer looked for where it would show: the three NOT VERIFIED items have **no green test
offered** (verified by looking, not by reading the claim), and **no skipped-and-counted placeholder** —
skips are 11, unchanged from T-008, and T-009 adds none, where a laundering branch would typically add
a skip so the row looks covered. The temptation was AC-2: `email_lower` makes the stand-in behave
case-insensitively, a genuinely new capability that would have been easy to write up as proven. The
spec pre-empted it and QA graded it CORR + NV. **Honest cost of the shape:** "PASS" is the first word a
reader sees, and 48-of-53 will be quoted without its three asterisks. Presentational, not laundering.
Keep the shape.

**2. Mis-gradings? No row graded too strongly.** AC-27 correctly CORR with the discipline held
(assertions read the PHP value through `inet_ntop`, never `length(ip)` in SQL). **One row the reviewer
would grade *more* strongly: AC-31** — its three absences are properties of statement order in two
files (`LoginHandler:153`, `IssueTokenPairHandler:55`), which is stronger than the tests alone
establish, and worth saying because a refactor moving either line breaks a criterion whose tests would
still pass in the common case. **Nothing marked NOT VERIFIED is actually proved**; AC-22's lock half is
if anything *less* proved than NV suggests.

**3. The security surface.** The one generic 401 is genuinely single-sourced —
`JsonResponse::fromJsonString(GenericRefusal::json(), …)` rather than re-encoding, so "byte-identical
on the wire" is the same fact as "byte-identical in the package". No path discloses which mechanism
refused. The dummy hash is correct and the docblock catches the part usually missed: with
`hashing.verify = true` a non-Argon2id sentinel turns the unknown-address path into a 500 **in
production only**. **Rotation and family revocation are the best code on the branch** — `rotate()`
consumes with a conditional `UPDATE … whereNull('used_at')` and refuses unless exactly one row was
affected, so two requests racing one token leave exactly one winner, decided by the engine and not by a
read in PHP; replay revokes the whole family in one statement so it cannot be half-revoked. `alg` is
never read from the header, so alg-confusion is structurally impossible rather than guarded. D-36's
line holds in all four slices. **And R-1, which gets out of the application entirely.**

**4. What nobody questioned**, in descending cost:
1. **Which sinks count as "a log"** (R-1). Six times this task an instrument was found to prove less
   than its name implied, and each time the response was to strengthen the instrument *within its
   existing frame*. Nobody re-asked the prior question — **is the list of sinks complete?** The six
   mask shapes are all about an instrument that does not discriminate; **this is a seventh and
   different shape: an instrument pointed at the wrong set of places, which no amount of mutation
   testing inside the set can reveal.**
2. **What the lockout is actually for.** Nobody wrote down that `isLocked()` is read at the start of
   each request, so lockout cannot constrain a simultaneous burst however atomic the counter becomes.
   FP-300 is filed against the less important half. A concurrent AC-22 test will show duplicate
   `lockout` rows **and** N successful password evaluations, and only the first is a surprise.
3. **Whether step 5b's Deptrac half does anything** (W-2) — a change made in response to a finding
   about invisible registries, shipped with a comment explaining the mechanism that makes it inert, now
   pinned by a guard asserting the inert registration is present.
4. **`actorEmail()`'s missing soft-delete filter** (W-3) — the docblock argues at length that the case
   is unreachable and considers only hard deletion. The argument is thorough, which is probably why
   nobody re-checked its premise.

---

## Done well

- **The ordering arguments are real engineering.** "Validate, resolve the key, then judge" appears in
  three handlers with the reasoning written out, and in `SelectTenantHandler` with a sharper one:
  because `RecordLoginAttempt` refuses an unrecognised reason at construction, judging membership
  before resolving the key would surface the gap as **a crash inside the refusal path**. That is
  someone reasoning about the failure mode of their own guard.
- **`SigningKeyring` refusing the plan's cache** — a deviation taken deliberately, argued from the
  spec's own NFR, with the property the cache was meant to preserve pinned by a test instead.
- **Mask shape 4 implemented in production code rather than in a test** — the `last_accessed_at` stamp
  is keyed on the membership's own id with no status re-filter, so a broken policy read cannot be
  invisible. Most teams would have written another assertion.
- **QA's refusal to overclaim AC-47** — three separate statements of what the Deptrac evidence is and
  is not, both probes re-run rather than cited, and a mutant built specifically to demonstrate the
  evasion the type graph cannot see. **The strongest single piece of evidence on this branch.**
- **QA mutating its own fix** and finding the `clearResolvedInstance` line reddened 0 of 6. Finding
  that the *repair* was untested is a level above finding the original defect.
- **Test quality is genuinely high.** The lockout test was spot-checked expecting ceremony and instead
  asserts exact counts, the event type, the IP round-tripped through `inet_ntop`, and
  `array_column(…)->toBe(array_fill(0, 5, 'bad_password'))` — exact, not `toContain`.

---

## What the reviewer did not check

Stated plainly, because an honest gap is worth more than an endorsement.

- **Did not re-run any of QA's mutants.** Both ledgers are taken on QA's report. The claims they
  support were verified by reading the code they constrain, but **not one file was independently
  reddened.** If a mutant result is wrong, the reviewer would not know.
- **Did not re-run the Deptrac probes.** The layers and rulesets were read and the withholding
  confirmed by inspection; the scratch probe that proves the withholding *bites* was not recreated. QA
  did it twice and the reviewer relies on that.
- **Nothing executed against MySQL.** `ENGINE_HOLD` respected. The three NOT VERIFIED criteria remain
  unverified by the reviewer too, and it agrees they should.
- **R-1 is proved at the framework level, not end to end.** The merge was confirmed by reading
  Laravel's source and by constructing a real `Request` in the scratchpad. The application was **not**
  booted and a login dispatched with credentials in the URL — that is the test QA should write and how
  the fix should be gated. The mechanism is certain; the only thing that could falsify the consequence
  is middleware stripping the query string, and the `api` group holds only `SubstituteBindings`.
- **Did not verify the 231 Deptrac "Uncovered" tokens are all pre-existing** — sampled as vendor
  dependencies with no owning layer, which is this project's normal state.
- **Reachability as deployed** — route caching, reverse proxy, TLS — remains unproven by the gate.

---

## Path to APPROVE

Fix **R-1** (four controllers plus one accessor, the QA test, and its mutant). On re-review only that
item and its pinning test will be checked. W-1 to W-4 do not block, but **W-1 and W-3 are small enough
to take in the same pass**, and **W-2 should be resolved before anyone cites the toolchain guard as
covering test directories.**
