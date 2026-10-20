# QA Report: T-009 Authentication

Branch `ai/T-009-authentication`, spec `docs/specs/T-009-authentication.md` (53 criteria), plan `docs/plans/T-009-authentication.md`. Run 2, 2026-10-20 (Run 1 2026-10-16; re-run after BUG-1 `bd56265` and D-38 `105feb7`).

## Verdict: PASS for what could be verified. NOT VERIFIED as a task on three criteria.

Both halves are true and are reported separately, in the shape T-008 shipped.

- **PASS (verifiable part).** Every criterion that can be executed on the SQLite stand-in passes, `composer check` exits 0, and no acceptance criterion fails. **0 critical, 0 major, 1 minor** open defect: BUG-1 is CLOSED (behaviour), and BUG-2 (minor, a comment names a test that does not exist) is new. No criterion fails on either.
- **NOT VERIFIED (engine hold, `ENGINE_HOLD` on, G-1 unmet).** **AC-22** in full (graded covered by reading only, and reading raises a question, see Note A); the **cross-engine half of AC-50**; the **collation half of AC-2**. No green test is offered for any of the three.
- **Filed, named, not failed:** FP-201 (every signing key retired: all logins fail, `login_history` is completely silent, the only trace is one error-level log line), FP-297 (proxy address recorded, blocks public launch), FP-199 (throttling, blocks public launch).

## Proof grading used below

| Grade | Meaning |
|---|---|
| **IP** | Independently proven: runs on the stand-in and fails if the behaviour is absent |
| **CORR** | Corroborating: true here, the engine is the real subject (AC-2 lookup, AC-27, AC-50 column list) |
| **READ** | Covered by reading only (AC-22), or a statement of accepted behaviour (AC-48) |
| **NV** | NOT VERIFIED, engine hold |

Test file short names. `LT` LoginTest, `LR` LoginRefusalTest, `RLA` RecordLoginAttemptTest, `ITP` IssueTokenPairTest, `VAT` VerifyAccessTokenTest, `RTT` RefreshTokenTest, `STT` SelectTenantTest, `LOT` LogoutTest, `TSP` TenantSelectionPolicyTest (all under `packages/auth/tests/`); `HAE` HttpAuthEndpointsTest, `TTM` TenantTokenMiddlewareTest, `MAS` MasterAuthSchemaTest (host, dev-owned); **QA-owned, new this run:** `QSC` QaSecretSinkCaptureTest, `QSG` QaSuspendedMappingGateTest, `QAS` QaAuthHttpSurfaceTest (all `modules/host/tests/Feature/`).

Dev-owned tests are cited by title from the passing run. I did **not** re-mutate each of them; where I did mutate, the row says so and the ledger below lists it. Where a row says "control: ..." the control is named because the criterion's own text did not.

## Acceptance criteria coverage

| AC | Test(s) | Control (where the criterion's text did not name one) | Grade |
|----|---------|---------|-------|
| 1 | LT "returns the four parts of a login ..." | LT "is red when the same no-leak assertion is run against a polluted body" (named in text) | IP |
| 2 | LT "signs in whatever the case and the surrounding whitespace"; MAS "refuses a case-variant duplicate email on the stand-in" | LT "does not confuse two addresses ..." (named in text). Stand-in lookup only; **collation half NV** | CORR + NV |
| 3 | LT "stamps the login and clears a run of failures" | the user starts at `failed_attempts = 4` (in text) | IP |
| 4 | LT "reports must_change_password as the account holds it" (true and false) | false case is the control (in text) | IP |
| 5 | LT "signs in a user with no memberships and names no tenant" | none needed (positive presence assertions) | IP |
| 6 | LR "refuses a wrong password and an unknown address identically"; HAE byte comparison against `GenericRefusal::json()` | correct password returns 200 in the same test (in text) | IP |
| 7 | LR "refuses an account that is not active, and admits the same account once it is" (dataset); LR "treats a soft-deleted account ..." | flip back to active (in text); `failure_reason` asserted per state (shape 3) | IP |
| 8 | LR "hashes exactly once against a fixed dummy digest ..." | LR "is red when the hasher double counts zero ..." (in text) | IP |
| 9 | LT "refuses an unreadable body before the hasher, the history and the account" (dataset); LT 192/1025; HAE "answers an unreadable login with 422" | LT "accepts both boundary lengths as input and fails them as credentials" (in text) | IP |
| 10 | LT "has no field a client could use ..." **plus QAS "ignores a tenant, a database, a host or a scope named in the body ..."** | same request run with and without the extras, answers compared. **QA mutant M14 (controller reads `tenant_id`) leaves all 31 slice tests green and reddens only QAS** | IP |
| 11 | LT "lists only the active, undeleted membership ..." | five-entry valid fixture (in text) | IP |
| 12 | LT "marks exactly trial, active and past_due as selectable"; LT "follows the selectable set it was given, and ignores a policy nobody bound"; TSP | unused-second-policy negative control (plan step 6) | IP |
| 13 | LT "picks the default by AC-13s three rules in order"; LT "lets the default follow the policy too" | fall-through cases are in the dataset | IP |
| 14 | LT "signs in a member of one suspended shop and offers them nothing to open" | AC-12 dataset supplies the selectable contrast | IP |
| 15 | LT "gives platform staff the same platform token and the same empty list" (list half only); **QAS "gives platform staff no way into a shop they are not a member of ..." (select half, new)** | the same staff member WITH a membership is admitted. **QA mutant M13 (staff bypass in `findMembership`) reddens only QAS: the select half had no test** | IP |
| 16 | LR "does not lock on four wrong passwords, and the fifth right one resets the count" | is AC-17's control (in text) | IP |
| 17 | LR "locks on the fifth wrong password, for fifteen minutes, without changing the status" | AC-16 (in text) | IP |
| 18 | LR "refuses the right password during a lock, and does not extend it" | `locked_until` unchanged assertion | IP |
| 19 | LR "refuses one second before the lock expires and accepts at the instant it does" (dataset: T-1s, T, after T) | both sides in one dataset (in text) | IP |
| 20 | LR "counts afresh after a lock has expired" | fourth failure does not lock, fifth does | IP |
| 21 | LR "locks one account without touching another, and creates nothing for an unknown address" | user B succeeds while A is locked | IP |
| 22 | **none offered** | **no control exists** | **READ, NV** |
| 23 | RLA "writes exactly one row for a successful login ..."; RLA "carries each cause into the column ..." (dataset); RLA "refuses a command whose two halves disagree ..."; **QAS "writes exactly one login_history row per evaluated attempt for each of the five outcomes, and none without a key"**; HAE "answers a login with no active signing key with 503 and writes no history row" | the no-key zero has its positive control in the same test: key restored, same request, one row appears (QAS and HAE both). **Not filed as a defect against AC-23, no seventh reason asked for.** "No other value is writable" holds **through the application only**: the column is a free `VARCHAR(40)`, a direct insert accepts anything | IP  **Run 2 re-grade (D-38), still IP:** the stored `email` is now the trimmed value if address-shaped, else the marker `(not an email)`. Placement verified by reading: the conversion is the last statement of `RecordLoginAttempt`'s one private constructor (after the three refusals), and `succeeded()`, `failed()` and `securityEvent()` all reach it (`securityEvent` passes null, which skips it), so a blank address is still an `InvalidArgumentException`, not a row. Cases the dev matrix covers and I re-ran green: `user@example.com`, padded, `user@exmaple.con` byte for byte, `hunter2`, `correct horse battery staple`, `a@b@c.com`, `user@localhost`, `@example.com`, `user@` all exact. **Gap found by mutation and closed by QA:** deleting the `! str_ends_with($domain, '.')` clause left the whole suite green (233/233), so `user@example.` was unguarded; `QaEmailShapeEdgesTest` now pins it (mutant reddens it) together with `user@.`, `user@.example.com`, a tab inside, and case/typo preservation. Marker-only-on-the-column: lookup, `user_id`, `failure_reason`, lockout counting decided before the command exists (read in `LoginHandler`), covered by the dev AC-23 (5) cases; I did not re-derive them independently beyond the mutations in the ledger. |
| 24 | RLA "leaves the row behind when the caller throws after recording the attempt"; RLA "writes outside any transaction of its own, and the probe can see one when there is one" | `transactionLevel()` probe with its own liveness control (plan step 7 mutation, 1 failed / 18 passed per dev; not re-run) | IP |
| 25 | RLA "writes the lockout event beside the bad_password row that caused it"; LR lockout test | event absent for attempts 1 to 4 (RLA "writes no event for the four attempts before the fifth") | IP |
| 26 | **QSC "leaves no password, refresh token or access token in login_history, security_events, the log or an exception message"**, QSC "records the operational failure of each issuing slice at error level and names no secret in it" | **mandatory positive control, QSC "finds a sentinel in every sink ..." and "finds a sentinel written by each real writer in that writer's sink and in no other"**. Runtime capture, no source text read. Four prod mutants M1 to M4 each reddened exactly its own sink | IP  **Run 2 re-grade against the amended scope: IP, narrower than before, and must not be quoted as 'no secret can reach login_history'.** What passes: no credential submitted AS a credential (password, refresh token, access token) in any sink, and, new, no non-address-shaped email value stored (a password typed into the email field is stored as the marker; mutant `forHistory()` returns raw reddens 9 tests incl. the sentinel scan). **What is explicitly NOT guaranteed and is not a defect:** an address-shaped secret such as `P@ss.word1` typed into the email field is stored as typed; LR asserts that residual on purpose (`LoginRefusalTest.php:198-200`). It is `ba`'s deliberate scope line, not filed, not closed here. |
| 27 | QAS "round-trips a v4 and a v6 caller through login_history, users and refresh_tokens"; RLA "round-trips an address of either family through the one binary column"; LT "round-trips an IPv6 caller ..." | every assertion reads the PHP value after `inet_ntop`; **none uses `length(ip)` or `typeof(ip)` in SQL**. QA mutant M9 (store the printed address) reddens QAS. Column is `VARBINARY(16)` on the engine only | **CORR** |
| 28 | ITP "mints exactly the platform claim set, and fails the same comparison with one claim more"; `TokenClaimsTest` | extra-claim token rejected (in text) | IP |
| 29 | VAT (eight refusal cases, each with its valid-token control); QAS and QSG exp boundary through the middleware | valid token beside each refusal (in text); VAT "refuses a token that claims the other type while wearing this one shape" separates the `token_type` check from set-equality (shape 3). QA mutant M10 (clock minus 1s) reddens the QAS boundary test | IP |
| 30 | STT "issues a second tenant token without disturbing the first, in a family of its own"; STT "leaves the platform family of the login neither used nor revoked"; LOT "revokes only the family it was given ..." | F0 and F2 stay usable after F1 revoked | IP |
| 31 | LT "fails closed with no active key, writing no row and stamping nothing" + "produces all three of AC-31s absences as presences when a key is active"; HAE no-key test; QSC (log at error level, five lines for five failures) | the three absences each have the key-present presence (shape 5) | IP |
| 32 | ITP "stores the sha-256 hex of the refresh token and never the token"; `RefreshTokenValueTest` length; **QSC "scans every column of every master table for the plaintext of what was issued"**; QSC "stores the refresh token as the sha-256 ... with the shape AC-32 lists" | **scan pointed at the hash finds it in exactly `refresh_tokens.token_hash`** (in text); QSC "reports a plaintext planted in a master column" finds a header-planted value in both `login_history.user_agent` and `refresh_tokens.user_agent`. Tables enumerated from the database (>= 20, not typed). QA mutant M5 (plaintext into `refresh_tokens.user_agent`) reddened the scan with five hits | IP |
| 33 | RTT "rotates a platform token inside its family ..."; "rotates again" | `used_at` on the presented row AND a new row in the family (shape 2) | IP |
| 34 | RTT "revokes the entire family on a replay, records token_reuse, and the newest token dies with it" | newest token works before the replay (in text) | IP |
| 35 | RTT "refuses a token that is not presentable, and issues nothing" (dataset); "accepts the same token one second before it expires"; "refuses a non-string ..." | exp-1s accepted (in text) | IP |
| 36 | RTT "refuses and revokes the family when the account stops being active" (dataset); "does not cut off a live session because the account is locked" | token worked before the change | IP |
| 37 | RTT "rotates a tenant token for the same tenant and never another"; "refuses a tenant that may no longer be selected without consuming the token ..."; "refuses a membership that is gone ..."; "re-checks the tenant against the policy it was given ..." | same token succeeds after reinstatement (liveness, shape 5); QAS matrix drives both refusals over HTTP | IP |
| 38 | RTT "gives a refresh request nowhere to carry a tenant, a database or a scope"; "refuses an access token presented as a refresh token, and the reverse"; **QAS ignored-fields test (HTTP, tenant and platform refresh)** | clean request compared | IP |
| 39 | STT "issues a tenant token for the resolved tenant, stamps the membership and records the attempt"; "omits the employee claim ... and carries it when it does"; "reads the master only, never tenant_databases ..." | query log must contain `tenants` and `tenant_users` statements (liveness, AC-52 note) | IP |
| 40 | STT "refuses every shape of no_access identically, and admits the valid case in the same test"; "refuses a non-selectable tenant by name ..." (dataset) | valid case in the same test (in text) | IP |
| 41 | STT "refuses an unreadable code with 422 and writes no history row"; HAE | STT "writes a history row for the same fixture when the code can be read" | IP |
| 42 | HAE "refuses tenant selection without a platform token, and with a tenant one"; QAS exp boundary | the valid platform token in the same test | IP |
| 43 | STT "names the tenant the code resolved to, whatever else a caller knows"; **QAS ignored-fields test (code A, body names B)** | selecting B by code gives B, so "token says A" is the code winning. QA mutant M14 proves the HTTP layer was the blind spot | IP |
| 44 | STT "issues a second tenant token without disturbing the first ..." | A token still verifies after B is issued | IP |
| 45 | TTM "resolves the tenant the token names and never the one the request names" | marker table read out of A's file; the same request without extras resolves A (in text) | IP |
| 46 | TTM "refuses a platform token, a missing token and an expired token, and never runs the handler" | the spy is called exactly once by the valid token in the same test (in text) | IP |
| 47 | **QSG (six tests)** behavioural half; deptrac type-reference half re-run (below) | active mapping calls the handler once; **the only difference between control and refusal is the `status` key (asserted by `array_diff_assoc`)**; three mutants reddened (T6, T7, T8) | IP for the behavioural half; boundary check proves type references only |
| 48 | HAE "keeps an access token valid until exp after the account is disabled" | account cannot log in again | **READ** (accepted behaviour; the test documents it) |
| 49 | LOT (seven tests); HAE "answers every logout the same way and revokes the family of a real one" | another user's family untouched (LOT) | IP |
| 50 | MAS (nine tests); `MasterSchemaBuildsTest`, `MigrationTreeDisciplineTest`, `QaSchemaSignatureInstrumentTest`, `QaMasterStandInShapeTest` | shipped-tree instrument is `MigrationTreeDisciplineTest` (opened and confirmed: it scans the shipped tree; `MigrationSourceRulesTest` scans fixtures only and is NOT cited for the shipped tree). Column list is the stand-in's, not `information_schema` | CORR + **NV** (cross-engine half, FP-194) |
| 51 | `VerticalSliceLayoutTest`; `composer deptrac` exit 0, 0 violations, 0 skipped | no new exemption (0 skipped) | IP |
| 52 | T-003 stanza tests unmodified (no `packages/kernel/tests` file in the T-009 diff); STT query-log + placeholder-stanza test | see Note B on the second sentence | IP with a flag |
| 53 | this gate | n/a | IP |

Totals: 53 criteria. Independently proven 48 (AC-52 flagged). Corroborating 3 (AC-2 lookup, AC-27, AC-50 columns). Covered by reading 2 (AC-22, AC-48). NOT VERIFIED 3 (AC-22 whole, AC-2 collation half, AC-50 cross-engine half).

## AC-47: what each piece proves, and what it must never be quoted as proving

1. **Boundary check, re-run by QA, not cited.** Baseline `composer deptrac`: 0 violations, exit 0. A scratch class in `packages/auth/src/Support/` that imports `TenantConnectionPurpose` and reads its constant: **1 violation, exit 1**. A second scratch class holding a double-quoted FQCN, a single-quoted FQCN, `in_array($s, ['active','read_only'])` and `new $class()`: **0 violations, exit 0**. Both probes deleted, deptrac back to 0 / exit 0, `git status` clean. **This proves authentication holds no type reference to the three status types. It does not prove, and must never be reported as proving, that authentication holds no copy of the status list.**
2. **Behavioural half, QSG.** A tenant token issued by authentication's own login and selection (so authentication, which never reads the mapping, issued it in good faith) meets a mapping row for the same tenant and the same database file whose only difference is `status`. Active: handler runs exactly once. `suspended`: the exception is `TenantDatabaseNotActive`, its maker call site (`trace[0].file`) is under `packages/kernel/src`, its caller (`trace[1].class`) is a kernel class, no `Flexipos\Auth\` frame sits between the maker and the middleware, the spy count does not move, the tenant stanza is back to the placeholder. Back to active: served again (count 2). A second test runs 14 statuses (including `ACTIVE`, ` active`, `active `, the empty string) and compares the outcome to the kernel's own `SERVING_STATUSES`, which is the oracle (no list typed in the test).
3. **This closes the gap `dev` named**: the unmapped-tenant case proves the kernel refuses, not that it refuses on status. QSG's refusal differs from its control in one key.
4. **Mutants (prod copied, never edited).** T0 byte-identical: green. **T6** middleware catches `TenantDatabaseNotActive` and returns 403: 5 of 6 reddened. **T7** handler runs before `enter()`: 6 of 6 reddened (handler count 1 on a refusal; the exception became `NoTenantResolved`). **T8** authentication keeps a private `['active','read_only']` list and refuses first: 5 of 6 reddened on the exception class. The one test that stays green under T6 and T8 is the expiry-boundary test, which never reaches the status gate, as it should. T8 is the "copied list" evasion Deptrac cannot see, and QSG sees it.
5. **Residual (b), moved-value** is dev's (steps 8, 10, 11: LT "follows the selectable set it was given ...", STT "accepts and refuses by the policy it was given ...", RTT "re-checks the tenant against the policy it was given ..."). Cited, not re-mutated by QA. Residual (c) diff review is the reviewer's.

### BUG-2 (minor, new in Run 2)
- **Where:** `packages/auth/tests/Support/auth-fixture.php:424`, the `authFixtureRecordingLogger()` comment.
- **Actual:** it says "`AuthFixtureRecordingLoggerTest` fails if any of them stops landing here". No such file or class exists (grep over `*.php` and `*.md`: this line is the only occurrence). The guard is `packages/auth/tests/Support/RecordingLoggerCoverageTest.php`. The task brief repeated the wrong name.
- **Impact:** same class as BUG-1 (a comment claiming a guard it does not name correctly), but the guard exists and works; a reader searching for the named test finds nothing.
- **Fix:** rename the reference. `dev` owns the file. Not blocking.

## Test run summary

- Command: `cd modules/host && composer check` (Pint, PHPStan, Deptrac, Pest), three runs.
- **Baseline at the start of my scope (step 12 tip): exit 0, 1094 passed, 11 skipped, 6260 assertions**, 149 s.
- **Final: exit 0, 1114 passed, 11 skipped, 6703 assertions**, 218 s (cold-ish machine; the figure moved 149 to 218 s with no code change besides my files, treat as machine variance, ratio 4.5 against the 900 s limit). Pint passed, PHPStan "No errors", Deptrac 0 violations.
- **Run 2 gate: exit 0, 1145 passed, 11 skipped, 6802 assertions** (Pint, PHPStan 'No errors', Deptrac 0 violations), 153 s. `dev` claimed 1134/11/6790 and it reproduced exactly on my first run at their tip (1134 passed, 11 skipped, 6790 assertions, 194 s). Delta from 1134/6790 is mine: `QaEmailShapeEdgesTest` +10 tests / +10 assertions, `QaRecordingLoggerFacadeCacheTest` +1 / +2. Chain from Run 1: 1114/6703 -> BUG-1 1120/6709 (+6, `RecordingLoggerCoverageTest`) -> D-38 1134/6790 (+14/+81) -> QA 1145/6802.
- **Delta in Run 1: +20 tests, +443 assertions, fully attributed:** `QaSecretSinkCaptureTest` 7 tests / 173 assertions; `QaSuspendedMappingGateTest` 6 / 120; `QaAuthHttpSurfaceTest` 7 / 150. 173 + 120 + 150 = 443. No unattributed increase.
- **Against the original 857 / 11 / 4508:** +257 tests, +0 skipped, +2195 assertions. Skipped is unchanged at 11 (all engine skips from T-008); T-009 adds none.
- **Progression (plan's figures, re-reconciled):** steps 1, 2: 857/11/4508 · step 3: 866/11/4598 · step 4a: unchanged · step 4: 872/11/4629 · step 5: 927/11/4917 · step 5b: unchanged · steps 6 and 7: 962/11/5096 · steps 8 and 9: 1012/11/5600 · steps 10 and 11: 1077/11/6090 · step 12: 1094/11/6260 (+17 tests, +170 assertions = `HttpAuthEndpointsTest` 12 + `TenantTokenMiddlewareTest` 5) · **step 13 (QA): 1114/11/6703**.
- Gate used throughout is `composer check`, never `test:fast`. `ENGINE_HOLD` untouched; no connection to 127.0.0.1:3306; nothing under `E:` written.

## Mutation ledger (all run by QA, each from a byte-identical green control first, `served=yes` printed by a shutdown hook)

| Mutant | What it forbids | Result |
|---|---|---|
| M0, T0 | byte-identical copy | green |
| M1 | `Log::info` with the submitted password | QSC red: `log<-password_real`, `log<-password_wrong` (JSON-escaped form hit) |
| M2 | password into `login_history.user_agent` | QSC red: `login_history<-password_*` |
| M3 | presented refresh token into the `token_reuse` event detail | QSC red: `security_events<-platform_refresh_1` |
| M4 | token inside a previous exception's message | QSC red: `exceptions<-bogus_bearer` |
| M5 | plaintext refresh token in `refresh_tokens.user_agent` | QSC AC-32 scan red, five hits |
| M9 | store the printed IP, not the packed one | QAS AC-27 red |
| M10 | verifier clock minus one second | QAS exp boundary red |
| M11 | `SelectTenantController` drops the no-key catch | QAS exception matrix red (500 for 503) |
| M12 | `LoginController` drops its validation copy | **all 12 HAE cases and all QAS cases stay green** (confirms dev's weakness 3) |
| M13 | platform-staff bypass in `findMembership` | QAS AC-15 red; **no pre-existing test noticed** |
| M14 | `SelectTenantController` reads `tenant_id` | QAS ignored-fields red; **all 31 SelectTenant slice tests stay green** |
| T6, T7, T8 | AC-47 mutants above | QSG red 5 / 6 / 5 of 6 (T0 identical: 6 of 6 green) |
| planted 7th exception class | an unmapped type | QAS exhaustiveness red naming `QaProbeSeventh`; file removed, `git status` clean |

## Run 2 mutation ledger (each from a green control, source restored by `git checkout` / copy-back, `git status` clean of production changes)

| Mutant | Result |
|---|---|
| fixture: delete `app()->instance('log', ...)` | RecordingLoggerCoverageTest 5 of 6 red |
| fixture: delete `Facade::clearResolvedInstance('log')` | existing tests 0 red; QaRecordingLoggerFacadeCacheTest red |
| `forHistory()` returns the raw value | 9 red of 233 |
| `substr_count(...) !== 1` replaced by false | 1 red |
| domain-ends-with-dot clause removed | **0 red** before QA; QaEmailShapeEdgesTest red after |
| domain-starts-with-dot clause removed | 1 red |
| conversion moved into the makers (`trim` replaced by `forHistory()`) | 1 red |

dev's other mutants (always-marker, 192-char marker) were not re-run; the 191 length assertion at `RecordLoginAttemptTest.php:243` was not independently re-mutated. AC-22, AC-50 cross-engine half and AC-2 collation half are unchanged: NOT VERIFIED, no test offered. AC-22 stays READ with FP-300 open. AC-27 stays CORR. FP-201 stays a known filed gap. The AC-52 second-sentence item stays flagged for the reviewer.

## Defects

### BUG-1 (minor) - CLOSED in Run 2 (`bd56265`)

**Re-test result:** the fixture now binds `LoggerInterface::class` and `log` and calls `Facade::clearResolvedInstance('log')`; the comment's claim is true. All four routes plus `channel()` land in the recorder (`RecordingLoggerCoverageTest`, 6 cases). Discrimination measured, not assumed: deleting the `log` registration reddens 5 of 6 (the injected case stays green, as dev said); deleting the `clearResolvedInstance` line reddens **0 of 6** because every test starts on a fresh app with an empty facade cache, so that line was untested. QA added `QaRecordingLoggerFacadeCacheTest` (facade already resolved to the real logger, liveness-controlled by asserting the pre-state); it is green and goes red when the line is deleted. Fixture restored by `git checkout` after each mutation. The original report text follows for the record.
- **Where:** `packages/auth/tests/Support/auth-fixture.php`, `authFixtureRecordingLogger()` and its doc comment.
- **Steps:** bind the recorder, then log through four routes: `Log::error`, `logger()->error`, `app(LoggerInterface::class)->error`, `app('log')->error`.
- **Expected (per the comment):** "one instance registration replaces what a constructor injection receives and what the `Log` facade resolves", so all four are recorded.
- **Actual:** only the constructor-injected `LoggerInterface` call is recorded. The facade, the helper and `app('log')` bypass it (`Container::instance()` drops the alias).
- **Impact:** every AC-26-style log scan written on that recorder (SelectTenant "keeps the operational log entry free of anything secret", the Login and Refresh equivalents) would pass a leak made through the facade. Nothing in `packages/auth/src` or `modules/host/app` logs through those routes today (grepped: no `Log::`, no `logger(`), so **no leak is hidden now**, and QSC's file-channel harness sees all four routes independently. AC-26 is therefore proven; the defect is a false claim in a comment plus a latent blind spot in a shared fixture.
- **Fix:** correct the comment, or bind the recorder under `'log'` as well. `dev` owns the file. No `[FIX]` task could be created from this role (no task tool in this run); `lead` please file it.

No critical or major defect found. No BUG was filed against AC-23 for the missing no-key row, and no seventh `failure_reason` was requested.

## What `ba` and `architect` asked QA to report

- **No-key failure reaches the application log at error level, no password or token in it (AC-31, AC-26).** Verified, not inherited from dev. QSC drives login, selection and refresh with a `retiring` key (verifies, cannot sign): **503 each, one `.ERROR: No signing key is active; authentication cannot issue tokens.` line each, zero new `login_history` rows, zero `security_events` rows**, and a fully retired key gives a fourth. Across the full scenario the log holds exactly five such lines for five failures (four over HTTP, one from a direct handler call). The same scan finds no password, no refresh token, no access token and no private-key body. The refresh that failed to sign consumed nothing: with the key restored the same token rotates (200).
- **What actually happens for selection and refresh with no active key (Open question 7).** Both resolve the key first and fail with 503 and nothing written, as D-36 requires. With the key `retired` rather than `retiring`, selection answers **401** (the platform token no longer verifies) and refresh still answers 503.
- **FP-201 silence: a known, filed gap, not covered.** With every key retired, login is 503 and `login_history` and `security_events` stay empty; the one error-level line is the only trace. QSC pins the silence and the single trace; FP-201 owns the readiness check and alert.

## dev's seven weaknesses: confirmed or refuted

1. **Reachability as deployed.** *Partly refuted, partly confirmed.* Refuted: ran `route:cache` (child, `MODULES=demo`, SQLite env) with these routes present. Cached successfully, `route:list` shows all four auth routes, and dispatching from the cached table answered `POST /login {}` 422 `email_missing` and `POST /tenant` 401; cache then cleared, `bootstrap/cache` back to `packages.php` and `services.php`. `OPTIONS /api/v1/auth/login` with an `Origin` answers 204 with `access-control-allow-origin: *`. The `api` middleware group holds exactly `SubstituteBindings` (measured: no throttle, which is FP-199), and no auth route has a bound parameter, so there is no interaction. **Confirmed (pinned, not fixed):** `X-Forwarded-For` is ignored and `login_history.ip` records `REMOTE_ADDR`, i.e. the proxy. `docs/deployment-vps.md` line 31 documents an nginx `proxy_pass` with `X-Forwarded-*`, so this is real. QAS pins it and says so; FP-297 (blocks public launch) owns it. The route-cache run is a one-off measurement, not a gate test: a gating test would need a child process and an edit to the shared toolchain registry in `tests/Helpers.php`. A reverse proxy and TLS in front are not exercised. **NV.**
2. **AC-47 residual carried by one test.** *Confirmed, now carried by six* (QSG) plus the three moved-value tests; three mutants prove it discriminates.
3. **Duplicate validation is invisible.** *Confirmed by mutation (M12):* deleting the controller's copy leaves 12 of 12 HAE cases and every QAS case green. `dev`'s ruling stands: if one is deleted it is the controller's.
4. **`login_history.ip` is corroborating and the grade does work.** *Confirmed.* QAS asserts both families on the PHP value, M9 reddens it, FP-297 owns the production gap. The weakest-evidence field is also the one with an open production defect; the grade is right.
5. **Expiry boundary only "well past" at HTTP.** *Confirmed, now closed:* QAS (platform route) and QSG (tenant route) assert 200 at `exp - 1 s` and 401 at exactly `exp`; M10 reddens.
6. **Three criteria with no executable proof.** *Confirmed:* AC-22, AC-50 cross-engine, AC-2 collation.
7. **Nothing asserts the exception map is exhaustive.** *Confirmed and half-closed.* dev's count ("six catch blocks, five types") does not match the tree: **13 catch clauses in 5 files (3 + 4 + 4 + 2) over 7 exception classes.** QAS drives every class through a real endpoint, asserts non-500, and asserts by `glob` of the exceptions directory that the driven set equals the defined set; a planted seventh class turns it red, and a handler bound to throw an unmapped type is shown to be a 500 (control). **Residual, stated:** a new throw site of an existing type on an endpoint whose controller does not catch it still reaches the client as a 500 and nothing here notices.

## Notes (not defects; things nobody asked about)

- **A. AC-22 by reading, and the reading raises a question.** The increment is one `UPDATE users SET failed_attempts = failed_attempts + 1`, which is atomic, so the "no lost updates" clause is satisfied by shape. But the lock decision is a **separate SELECT** of the counter followed by an **unconditional** `UPDATE ... failed_attempts = 0, locked_until = ...` (`LoginHandler::countFailure`). Two requests that both cross the threshold both lock and both write a `lockout` event, and a reset can erase an increment made between another request's read and its reset. "Lock applied once" (AC-17 wants exactly one `lockout` row) is therefore **not structural**. Whether the criterion is met is exactly what the engine proof was deferred for; I report it so the engine task starts there. No test offered.
- **B. AC-52's second sentence.** Five pre-existing files were edited in the T-009 range: `MasterSchemaBuildsTest` (a comment), `MigrationTreeDisciplineTest` (17 to 21 tables), `QaSchemaSignatureInstrumentTest` (25 to 29), `tests/Helpers.php` (the expected table list gains the four), `QaToolchainRegistryCoverageTest` (plan step 5b, a strengthening, shown red first). No assertion is weakened and no T-003 test is touched. The criterion's wording ("to make it pass") is broken in the letter by necessary count updates that AC-50 itself forces; flagged for the reviewer rather than called a pass or a fail.
- **C. A suspended shop's till receives HTTP 500.** The kernel's `TenantDatabaseNotActive` has no renderer (its sibling `TenantSchemaBehind` gets a 503). AC-47 asks only for refusal by the existing gate, so this passes, but a commercial state (a shop that has stopped paying) will also log as an application error. QSG pins the 500 on purpose; a task that adds the renderer changes that test deliberately. Suggest a backlog row.
- **D. CORS is the framework default `*` on `api/*`.** Tokens travel in the `Authorization` header, not cookies, so this is not a CSRF exposure, but it is the default and nobody decided it.
- **E. (RESOLVED by D-38, Run 2; original text kept)** `login_history.email` stores what was typed in the email field, trimmed. A user who types a password there leaves it in the table (AC-23: "as submitted", up to 191 characters). AC-26 is about the password field and holds. Worth a decision from `ba` before launch.
- **F. No rate limit on any of the four endpoints.** FP-199, already filed and marked as blocking public launch.
- **G. The sentinel harness refuses a needle shorter than 16 characters** and scans four encodings (raw, two JSON-escape forms, URL). A raw-only scan would have missed a password logged in a context array: the M1 hit was the escaped form.
- **H. `AC-26` also scans the HTTP responses for the password** (never echoed) and confirms the issued tokens ARE in the responses, as a last liveness check on the scan.

## Regression risks

- Any change to `Tenancy::enter()`'s refusal ordering, `RequireTenantToken` placement of `enter()`/`leave()`, or a `config()` channel for the serving list will turn QSG red, intentionally.
- QAS "records the connecting address, not a forwarded one" is **expected to change when FP-297 lands**. Update it; do not revert FP-297.
- QSC scans the whole `master` schema by enumeration; a new master table holding a free-text column that receives a request value will be scanned automatically.
- SQLite refuses `ADD COLUMN ... STORED` once `users` holds a row: migrate first, seed second, always (plan step 3). This is a stand-in artefact, not a defect in `email_lower`.
- Route-cache, reverse-proxy and TLS reachability remain unproven by the gate.

## Commands run for the evidence

`composer check` (baseline, then final, exit 0 each); `pest tests/Feature/{QaSecretSinkCaptureTest,QaSuspendedMappingGateTest,QaAuthHttpSurfaceTest}.php`; mutants through `pest --configuration=phpunit.qamut.xml` (file deleted after use); `vendor/bin/deptrac analyse` with scratch probes (deleted); `artisan route:cache` / `route:list` / `route:clear` with a dispatch from the cached table.

## History

Run 1: PASS (verifiable part), NOT VERIFIED on AC-22, AC-50 cross-engine half, AC-2 collation half. BUG-1 minor open.
Run 2: PASS (verifiable part) unchanged shape; NOT VERIFIED on AC-22, AC-50 cross-engine, AC-2 collation. BUG-1 closed, BUG-2 minor open, AC-23 and AC-26 re-graded (D-38), 1145/11/6802.
