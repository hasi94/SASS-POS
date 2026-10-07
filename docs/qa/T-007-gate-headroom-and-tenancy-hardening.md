# QA Report: T-007 gate headroom and tenancy hardening

Branch `ai/T-007-gate-headroom-and-tenancy-hardening`, QA commit `292bdaa` on top of `7e622ad`. All durations carry a D-12 state and the date 2026-10-07 (machine clock +05:30).

## Verdict: PASS

0 defects. 5 notes under "Regression risks / notes", two of which correct statements in the plan (R14's wording and the factory-direct red count).

**Machine-cold: not observed.** The machine booted at 03:39 on 2026-10-07 (`LastBootUpTime`) and my first gate-sized run was at about 10:58, so no run of mine was the first after a boot or a long idle. No `[state: MACHINE-COLD]` figure appears anywhere below, and none was claimed.

## Acceptance criteria coverage

| AC | Test(s) / measurement | Result |
|----|----|----|
| AC-1 | Cold pass below: five steps, caches removed, each tagged `[state: CACHE-COLD]` | PASS (MACHINE-COLD not observed) |
| AC-2 | `composer config process-timeout` = 900, budget 882, S = 3 x 103.89 = 311.67, "machine-cold not observed, factor 3 applied". limit/S = **2.888**, budget/S = 2.830, both >= 2. At T-006 (`cef6a17`): `composer config process-timeout` = **300**, 300 / 311.67 = **0.963 (< 1)** (diff) | PASS |
| AC-3 | `disableProcessTimeout` only in the `dev` script (line 64); `check`, `test`, `test:fast`, `test:slow`, `config` never disable it; `GateStepTimerTest` "never disables the process timeout" green | PASS |
| AC-4 | `COMPOSER_PROCESS_TIMEOUT=2 composer test` `[state: WARM]`: exit 3, `FLEXIPOS-GATE-TIMEOUT step=test ... elapsed=1.32s budget=1s limit=2s source=env ... NO TEST RESULT WAS PRODUCED` | PASS |
| AC-5 | `GateStepTimerTest` "passes a failing step through ... no sentinel" (green in the suite). Whole-gate form with a planted failing test **not run by me** | PASS (per-step only) |
| AC-6 | Cold `composer check`, exit 0, sentinel absent (below) | PASS |
| AC-7 (diff) | Same command in a scratch worktree at `cef6a17` (vendor junctioned): exit 1, Composer's own "exceeded the timeout of 2 seconds", **0** occurrences of `FLEXIPOS-GATE` | PASS (red at the old tip, as required) |
| AC-8 | Forced kill at `COMPOSER_PROCESS_TIMEOUT=54` (budget 52, ended at elapsed=52.44s `[state: WARM]`, 113 cases done, including `HostTestAnalysisEnforcementTest` and `DeptracBoundaryEnforcementTest`, the probe-planting files). No `*Probe.php` and no generated package survived; `git status` clean. The following `composer test` runs (AC-10 and a cold `composer check`) exit 0. **Limit of the evidence:** the kill did not land inside a probe window, so "a survivor is cleaned" rests on T-005's recorded proof, not on this run | PASS |
| AC-9 | Last line of every completed run, e.g. `FLEXIPOS-GATE-MARGIN step=test at=2026-10-07T05:43:01+00:00 elapsed=96.39s limit=900s ratio=9.34 source=manifest` | PASS |
| AC-10 | Whole-step form, `COMPOSER_PROCESS_TIMEOUT=156` `[state: WARM]`: suite 463/2403 green, exit 0, `FLEXIPOS-GATE-LOW-HEADROOM step=test ratio=1.66 source=env`, then `MARGIN ... elapsed=93.84s limit=156s ratio=1.66`. Normal limit (900): warning absent in the cold run and the warm run. Forced limit >= 5: 156 here, 8 in `GateStepTimerTest` | PASS |
| AC-11 | By reading: the only durations in changed non-test text are in the `composer.json` provenance line (240.87s / 87.34s, labelled, dated 2026-10-07) and `gate-step.php` (floor of 2 s and 1 s as formulas, 1.45s dated and with its command). `scripts-descriptions.test` carries no number | PASS |
| AC-12 | `check` = probes:clean, lint, stan, deptrac, test (printed from the manifest); `test:fast`/`test:slow` flags intact | PASS |
| AC-13, AC-14, AC-15, AC-19, AC-21, AC-39 | `TenantStanzaForgeryTest` (dev) green; independently, my table rows F1 x3 and F2 x4 on all seven routes, exact class `TenantConnectionMismatch`, never `NoTenantResolved` while a tenant is resolved | PASS |
| AC-16 | `TenantStanzaForgeryTest` surface search (dev), green | PASS |
| AC-17, AC-18 | `TenancyResolutionTest`, `TenancyTenantSwitchTest`, `QaTenantIsolationProofTest` held-connection cases, green | PASS |
| AC-20, AC-22 | `TENANT_MARKER` occurs in code only inside three assertions that it is gone (grep of `packages` and `modules`, vendor excluded). AC-22 by reading: `TenantConnectionAuthority` docblock names F3 not defended and AC-13/AC-14 as the proof | PASS |
| AC-23 | `QaTenantIsolationProofTest` "gives every route the same three answers" x 7 routes (no tenant / 7 forgeries / genuine / held past scope) | PASS |
| AC-24, AC-25 | `TenantConnectionChokePointTest` (dev), green; my token-level search over `src/Tenancy` (code only, comments excluded): no `ConnectionEstablished`, no `WeakMap` beside a `Connection`, with controls proving it saw the factory and the legitimate credential `WeakMap` | PASS |
| AC-26 | "does not retain a tenant connection after the switch that built it" (25 switches, `WeakReference`, `gc_collect_cycles()`) | PASS |
| AC-27 | Shape A (held handle): "refuses a factory-built connection held into another tenant's scope, with either dispatcher". Shape B (switch, purged handle): "refuses a connectUsing connection queried after a tenant switch when the dispatcher is faked". Post-scope: "refuses a connection no manager owns ... after its scope has ended" (build and factory). Both shapes kept and labelled; see Note 2 for what was measured red at T-006 | PASS |
| AC-28 | Same table body and same expected map run under `Event::fake()` x 7 routes; token search above | PASS |
| AC-29, AC-31 | Retained T-003 cases ("purges every tenant connection variant", "rolls back a transaction left open"), unchanged, green | PASS |
| AC-30 | Every refusal in the table swept: `(string)$e`, message and `getTraceAsString()` for both the real and a forged password and their prefixes; detector control proves a leak would be seen ("could see a credential on a refusal surface") | PASS |
| AC-32 (diff) | Scratch mutations served by a prepended autoloader, nothing in the tree touched (see "AC-32" below) | PASS |
| AC-33 | Cold `composer check`, normal limit: exit 0, sentinel absent, AC-9 line recorded below | PASS (CACHE-COLD only; no MACHINE-COLD run to report) |
| AC-34 | Full suite 463 passed, 0 failed; every edited pre-existing test is listed under AC-41 | PASS |
| AC-35 | **No review round exists yet.** No finding is closed, so none lacks a criterion. To be repeated after the reviewer reports | PENDING (nothing to map) |
| AC-36 | `git diff cef6a17 HEAD -- docs/backlog.md`: one added row (the T-007 registration). FP-160, FP-161, FP-172 still `TODO` | PASS |
| AC-37 | Cold gate green, vocabulary sweep included | PASS |
| AC-38 | Both documents searched for `TENANT_MARKER`, `ConnectionEstablished`, `connection extension`, `guard level`, `beforeExecuting`, `choke point`, `flexipos_tenant`, `guardedConnections`: 0 hits each, whitespace collapsed. `database.md`, `architecture.md` and `CLAUDE.md` unchanged. "No document affected" holds | PASS |
| AC-40 | Each guard row has the paired legitimate row in the same table; AC-32 shows each red with the guard off | PASS |
| AC-41 | Listed line by line below | PASS |
| AC-42 | Every digit-plus-`s`/`sec`/`second` in the added lines of the changed files accounted for below | PASS |
| AC-43 | S comes from runs made after the last change to the gate's own files (`composer.json`, `tools/gate-step.php`). One dev docblock sentence in `src/` is still to change after this report; it cannot move S | PASS (see Note 5) |
| AC-44 | AC-4 forces 2 s against a 900 limit and produces the sentinel; the 900 limit alone produces none | PASS |
| AC-45 | Derived limit **69**, run CACHE-COLD below: `lint`, `stan`, `deptrac` completed, test step ended, exit 3, sentinel present | PASS |

### AC-1: the cold pass, 2026-10-07

Command and removals, in `modules/host`: `rm -rf storage/framework/cache/phpstan storage/framework/cache/.deptrac.cache storage/framework/cache/.deptrac-fixture.cache storage/framework/cache/.deptrac-shipped.cache storage/framework/cache/.deptrac-testcode.cache`. That is the PHPStan result cache directory plus all four deptrac cache files (the spec names two; the other three belong to child runs inside the test step, so removing them is the colder reading). No PHPUnit or Pest result cache exists (`find` for `*.result.cache` outside `vendor`: none). Each step timed with `date +%s%N` around `composer run-script <step>`:

| step | wall | state | exit |
|---|---|---|---|
| probes:clean | 1.96 s | [state: CACHE-COLD] | 0 |
| lint | 3.48 s | [state: CACHE-COLD] | 0 |
| stan | 45.65 s | [state: CACHE-COLD] | 0 |
| deptrac | 3.32 s | [state: CACHE-COLD] | 0 |
| test (463 cases, 2403 assertions) | 103.89 s | [state: CACHE-COLD] | 0 |

Slowest of lint/stan/deptrac is stan; its two repeats (caches deleted again before each): 45.68 s and 39.61 s, both `[state: CACHE-COLD]`, exit 0. **A = worst of three = 45.68 s.** `[state: WARM]` for comparison: `composer test` 102.33 s on 2026-10-07 (this run also showed 2 reds, see "Test run summary").

### AC-2

Slowest step = test, 103.89 s `[state: CACHE-COLD]`. Machine-cold not observed, factor 3 applied: S = 311.67 s. The manifest's own `cache-cold-slowest-step-seconds` is 106 (dev's figure, same date), which is above my 103.89, so the standing manifest assertion stays satisfied and nothing needs re-writing; that file is not mine to edit anyway.

### AC-45: derived limit

`limit = round(sqrt(A x T))`, A = 45.68 s (worst of three `[state: CACHE-COLD]` stan samples: 45.65, 45.68, 39.61), T = 103.89 s `[state: CACHE-COLD]` test step.
`sqrt(45.68 x 103.89) = 68.89` -> **69**. Reserve = max(2, ceil(0.02 x 69)) = 2, so budget = **67**.

- `limit - A` = **23.32 s** (the analysis steps are unwrapped, so the limit is what binds them).
- `T - budget` = **36.89 s** (the test step is wrapped, so the budget is what binds it).
- Ratios on the limit basis: limit/A = 1.511, T/limit = 1.506.

Run: `COMPOSER_PROCESS_TIMEOUT=69 composer check`, all five caches removed first, 2026-10-07, wall 114.84 s `[state: CACHE-COLD]`, exit 3. Pint passed, PHPStan `[OK] No errors`, Deptrac `Violations 0`, then
`FLEXIPOS-GATE-TIMEOUT step=test at=2026-10-07T05:36:31+00:00 elapsed=67.44s budget=67s limit=69s source=env - ... NO TEST RESULT WAS PRODUCED.`
No analysis step overran, so **no run was discarded**.

### AC-33: final cold gate

`composer check`, caches removed, no `COMPOSER_PROCESS_TIMEOUT` set, 2026-10-07T11:13 +05:30: wall **156.27 s** `[state: CACHE-COLD]`, exit **0**, 463 passed / 2403 assertions, sentinel absent. AC-9 line:
`FLEXIPOS-GATE-MARGIN step=test at=2026-10-07T05:43:01+00:00 elapsed=96.39s limit=900s ratio=9.34 source=manifest` `[state: CACHE-COLD]`.

### AC-32: mutations

Method: a bootstrap file (`--bootstrap=`) that requires the normal autoloader, then prepends a loader serving a scratch copy of one production file from the scratchpad. No production file was edited. Control first: byte-identical copies served the same way, `MUTANT LOADED` printed for both, `QaTenantIsolationProofTest` 37 passed. Targets: `QaTenantIsolationProofTest` (37 cases).

| mutation | result |
|---|---|
| 1. the `db.factory` decoration removed from `TenancyServiceProvider` | 31 failed, 6 passed. AC-23 table x 7, AC-28 table x 7, AC-27 shapes all red. (The first case took 13.7 s because it really tried to connect to TEST-NET-3, which is the same proof that the guard moves the refusal ahead of the driver) |
| 2. both guard attachments (`beforeExecuting`, `beforeStartingTransaction`) commented out of `TenantConnectionFactory::guardEveryQuery` | 10 failed, 27 passed. AC-23: "the factory called directly" row, real dispatcher; AC-28: same row, faked dispatcher; AC-27: the held-handle case and the connectUsing-after-switch case; the post-scope case for the factory route; four older held-connection cases |

The AC-23 and AC-28 tables only went red under mutation 2 after I added a state to them (a connection no manager owns, queried after its scope ended). Before that addition the table passed under mutation 2, because every other state is decided at construction. The build() post-scope row stays green under mutation 2 (the accident in Note 2), which is why the factory route carries the proof.

## Test run summary

- Gate: `composer check`, `[state: CACHE-COLD]`, 2026-10-07: exit 0, **463 passed / 2403 assertions**, 0 failed, 0 skipped. Reconciliation against the tip you gave (441 / 2201): +22 cases, +202 assertions. The 22 cases are my additions to `QaTenantIsolationProofTest`: 14 table cases (7 routes x 2 dispatchers), 2 post-scope cases (build and factory), 1 factory-held, 1 connectUsing-after-switch, 1 build wedge, 1 R7 residual, 1 token search, 1 detector control (14+2+1+1+1+1+1+1 = 22). The retention case was already in the 441. Assertions: the old isolation file alone was 15 cases / 62 assertions (HEAD version run on its own), the new one is 37 / 262, i.e. +22 / +200; `QaCredentialSurfaceSweepTest` gained +2 assertions (the shape check and the value check). 200 + 2 = 202.
- First full run of my edits, `[state: WARM]`: 461 passed, **2 failed**. Both were `composer stan` reporting 5 x `method.notFound` in my file (`disconnect()` called on `ConnectionInterface`, returned by `DB::build()`/`connectUsing()`), seen through `HostTestAnalysisEnforcementTest` and `QaTestCodeAnalysisCoverageTest`, which run PHPStan over the tests directory. My focused Pest runs had not run PHPStan. Fixed with a `qaIsoClose()` helper; both tests, and `composer stan`, green afterwards. Not a product defect.
- Lint: Pint `passed` over `..` and `../../packages` as part of `check`.
- Time cost of my file: first draft ran 149 cases in 143 s because fixtures were rebuilt per case; now built once per file and each route run through all states in one case, 37 cases in about 4 s.

## AC-41: every edited test file, line by line (diff `cef6a17..HEAD`)

New files (not edits): `GateStepTimerTest.php`, `TenantConnectionChokePointTest.php`, `TenantStanzaForgeryTest.php`.

- `modules/host/tests/Helpers.php:61`: added `'modules/host/tests/Feature/GateStepTimerTest.php'` to `toolchainGroupFiles()`. Required by the registry sweep; nothing removed.
- `TenancyFrameworkContractTest.php`: one new case (+59 lines) and two `use` lines. No existing line changed.
- `TenancyCredentialLeakTest.php`: `use Tenancy` removed (-1); `:275` `->set('...'.Tenancy::TENANT_MARKER, 'tenant-b')` became `->set('database.connections.tenant.flexipos_tenant_authenticator', 'tenant-a')` (F1 forgery, with a 6-line comment); `:287` expectation `toContain('tenant-b')` changed to `toContain('tenant-a')` (the message names the resolved tenant, not the forger's value); **added** `not->toContain('flexipos_tenant_authenticator')`. Listed class: reads the marker. The `tenant-b` to `tenant-a` flip is a changed expected value, reported as such, not a loosening.
- `TenancyResolutionTest.php:56`: `->and($stanza[Tenancy::TENANT_MARKER])->toBe('tenant-a')` replaced by three assertions (authority verifies `tenant-a`; the authenticator is not `tenant-a`; no scalar in the stanza equals `tenant-a`). Strictly more asserted. `use` swapped.
- `TenancyTenantSwitchTest.php:103`: `->and(tenancyTenantStanza()[Tenancy::TENANT_MARKER])->toBe('tenant-b')` replaced by `$fromClean['flexipos_tenant_authenticator']` equals `'authenticator:string'` and authority verifies `tenant-b`. `use` swapped.
- `tenancy-fixture.php`: `tenancyComparableStanza()` substitutes the authenticator by type (+8 lines, R6.2). Nothing removed.
- `QaCredentialSurfaceSweepTest.php:381`: removed `toContain('flexipos_tenant')` and `toContain('qa-cred-a')`; added a shape check that the live stanza holds exactly one 32-hex value, `str_contains($shown, $key)`, `str_contains($shown, $token)`, and `toContain('qa_cred_app')`. Net +2 assertions. The old `flexipos_tenant` search is **deleted, not kept**: it passed on the substring of `flexipos_tenant_authenticator`, which is the accident-pass class (a search that succeeds for the wrong reason). The new assertion matches the live key and the live value, so it cannot pass on a prefix.
- `QaTenantIsolationProofTest.php` (mine): header comment rewritten (the "two cases expected red" paragraph was T-003 history); `qaIsoRoot()` memoised; fixtures built once per file, per-test directory delete removed and moved to `afterAll`; `:198` stanza (`TENANT_MARKER` line replaced by a 32-character token of the right shape); the driver-reach control rewritten per R6.1 (unopenable path from the mapping before entering; asserts `TenantConnectionFailed`, not `TenantConnectionMismatch`/`NoTenantResolved`, nothing resolved afterwards; strictly stronger than "not a `TenancyException`"); the declining paragraph above the dispatcher-faked case rewritten (its two assertions are unchanged and green); the `guardedConnections` reflection test replaced by the `WeakReference` retention case (AC-26); four stale BUG-2 comments reworded; then the 819 added lines described above. **No `expect` was deleted other than those inside the two replaced tests.**

## AC-42: every digit followed by `s`, `sec` or `second` in added lines

Searched `git diff cef6a17 HEAD -U0` over `packages` and `modules` for a digit then optional space then `s|sec|secs|second(s)`:

- `packages/kernel` (all tests and source): **0 hits.** My files contain none.
- `modules/host/composer.json`: `240.87s` and `87.34s` in `machine-cold-factor-provenance`; dated 2026-10-07 and state-labelled in the same sentence.
- `modules/host/tools/gate-step.php`: "max(2 seconds, 2 % of the limit)" and "max(1 second, ...)" are the reserve and budget formulas (the one absolute duration, a floor, stated as such in the header); `elapsed=1.45s` carries MEASURED 2026-10-07 and its re-measure command. My own recipe-3 sample at the same limit was `elapsed=1.32s` (budget 1 s), a single sample inside the same spread, so I am not reporting it as contradicting dev's.
- `modules/host/tests/Feature/GateStepTimerTest.php`: `budget=1s` and `limit=2s` (expected output of a forced limit of 2), `limit=61s` (a forced value echoed back), and the comment "4.5 s" (the fixture child's sleep, an input of the case, with `8`/`6`/`4` beside it).
All eight are inputs, formulas or dated measurements; none is an undated measurement.

## Defects

None.

## Regression risks / notes

1. **`DB::build(['name' => 'tenant'] + ...)` wedges the process, and AC-27's build() half cannot be asserted at the query.** `DatabaseManager::build()` writes the caller's array into `dynamicConnectionConfigurations['tenant']` and nothing public clears it; `configuration()` prefers it to `config()`. After any build() of the tenant name, even of the genuine stanza, the next tenant's `enter()` is refused with `TenantConnectionMismatch` while entering, until the process restarts. Fail-closed, pre-existing (the T-003 case pinning it for a refused build() is in the same file), accepted in plan R1; but it is a worker-wide outage if a module ever does it. Pinned by "refuses to enter another tenant once the tenant name has been built", which also shows that clearing the retained array restores service. Worth a backlog row; not a T-007 defect. AC-27's literal wording for build() ("queried after a switch to tenant B") is satisfied only at the entering stage; the query-stage shape is covered by connectUsing (shape B) and by the factory route (shape A).
2. **R14's wording is half right; measured at the T-006 tip.** I ran dev's step-3 `TenantConnectionChokePointTest` (commit `abb6c41`) against production code from `cef6a17` (scratch worktree, prepended autoloader, since removed): **16 failed, 36 passed (157 assertions)**. In that run: the held-connectUsing-after-switch case is red and returned `'tenant-b'` from inside tenant B's scope; the factory-built held handle is red and returned `'tenant-a'` inside tenant B's scope; the build()-after-scope case is **green**. So connectUsing after a switch *does* demonstrate a defect (a connection built for A answered as B; a repointing, weaker than A's rows in B's scope, but unguarded); build() after the scope ends does not, as R14 says. I kept both shapes and labelled them in the file.
3. **Factory-direct red count: four, not five.** In the same T-006 run the "connection factory directly" route is red in exactly four cases (no tenant and foreign stanza, each with a real and a faked dispatcher) and **green in both success cases**, as the architect reconstructed. The 16 reds are: 4 factory-direct, 4 build/connectUsing under the faked dispatcher (no tenant and foreign, two routes), 2 AC-27 held cases, 6 installation/absence cases. 16 + 36 = 52, equal to the file's case count, so no case is missing from the arithmetic.
4. **A stanza with another connection name and no authenticator is an ordinary framework connection.** The factory only guards names `tenant` and `tenant::*` and stanzas that carry the authenticator key. `make($stanza_without_key, 'other')` is handed to the inner factory untouched. Correct for a general-purpose factory (the master connection must still build), but it means isolation against code that can name another tenant's host, database and credentials rests on the credential not being obtainable, which is the "mapping source is trusted" residual already written beside the mechanism. Not pinned by a test, because pinning it would assert a hole.
5. **Dev's pending docblock sentence.** It does not alter the gate's scripts or the wrapper, so S and the AC-45 limit do not depend on it; but a comment-only edit under `packages/kernel/src` still changes the Pint/PHPStan input, so the final tip should get one more `composer check`, which is the reviewer-round re-run anyway.

Fragile areas seen: the focused-run-then-gate gap (PHPStan analyses the tests directory, so a green Pest file is not a green gate); Pest's `toBe($expected, $message)` silently drops the message; `Event::fake()` retains connections (handled with `gc_collect_cycles()` in `afterAll`); `DB::build()` on the tenant name leaves a process-wide stanza that makes later rows of a table interdependent unless reset between rows (`qaIsoFresh()`).

Run history: Run 1: PASS (463 / 2403, cold gate exit 0).

---

# Round 2 (review round 1: APPROVE, no Critical findings)

## Verdict: PASS

Tip under test: `9b59793` plus two uncommitted test-only edits of mine (committed with this section). Defects: 0 critical, 0 major, 0 minor.

## My items

- **W-2 fixed.** `packages/kernel/tests/Support/tenancy-fixture.php` comment now reads "Present since FP-161 ... TenancyTenantSwitchTest asserts this substitution fires, and AC-19 asserts the raw stanza separately, so nothing is hidden." It no longer contradicts `TenancyTenantSwitchTest.php:106`.
- **S-6 fixed.** `QaCredentialSurfaceSweepTest.php:241` fabricated payload now uses `flexipos_tenant_authenticator`. The old key `'flexipos_tenant'` survives in test code only at `QaTenantIsolationProofTest.php:996` (a forgery row labelled "the retired marker key") and `TenantStanzaForgeryTest.php:95` (a forged key list); both are deliberate negatives, not an emulated dump shape.
- **S-7 closed by the planted form, not by quoting run 1.** I planted `modules/host/tests/Integration/QaAcFiveFailingProbeTest.php` (`expect(1)->toBe(2)`) and ran the whole `composer check` at the normal limit (900 s). Result: exit 1; `FAILED Tests\Integration\QaAcFiveFailingProbeTest > it QA AC-5 planted failing probe`; `Tests: 1 failed, 473 passed (2476 assertions)`; the only `FLEXIPOS-GATE-` line is the margin line (`step=test elapsed=140.52s limit=900s ratio=6.40`) and it is last; Composer's own "returned with error code 1" follows. No sentinel. Probe removed, `git status` clean of it. **Caveat:** the file name is deliberately NOT the registered `QaCollectionProbeTest.php`. My first attempt used that name and `probes:clean` (the first step of `check`) deleted it, so that run was exit 0 with 473 passed, a useful incidental confirmation that the cleaner works but not a planted failure. The planted file is therefore "the probe mechanism's shape" (a collected test under `tests/Integration`, removed afterward), not a registered probe. AC-5 whole-gate form: **PASS** `[state: WARM]` 2026-10-07. Reviewer's run 1 remains corroborating evidence in kind.

## Gate result, reconciled

| Run | Command | Result | Duration |
|---|---|---|---|
| 1 | `composer check`, registered probe name (cleaned away) | exit 0, 473 / 2475, margin line last, no sentinel | 108.71 s test step, ratio 8.26 `[state: WARM]` 2026-10-07 |
| 2 | `composer check`, planted failure (S-7) | exit 1, 473 passed + 1 planted failed (2476), no sentinel | 140.26 s, ratio 6.40 `[state: WARM]` 2026-10-07 |
| 3 | `composer check`, clean tree | exit 0, **473 passed / 2475 assertions**, margin line last, no sentinel | 100.90 s, ratio 8.90 `[state: WARM]` 2026-10-07 |

Run 3 equals the 473 / 2475 baseline exactly (my edits are a comment and one array key; no test added or removed). Run 4 (an intermediate no-op, directory missing) also ended 473 / 2475, exit 0. Nothing went red apart from the planted failure, so neither known cause (W-3 timeouts, the 2.0 s kill ceiling) was hit and no process listing was needed. The durations are higher than 82.61 s from the tip's run; I did not measure the cause and make no claim about contention.

## Findings mapping (AC-35)

Critical findings: **none in round 1, so nothing is "closed on measurement"**; AC-35's Critical clause is vacuously met.

| ID | Outcome | Evidence |
|---|---|---|
| W-1 | fixed | `9b689e7`: false `build()` claim corrected in `TenantStanzaForgeryTest.php`; two further copies in dev agent memory corrected |
| W-2 | fixed | this round, my commit (fixture comment) |
| W-3 | backlog row (FP-172's sibling), filed by lead; out of scope per AC-12 | not a T-007 defect; not hit in my runs |
| W-4 | backlog row for the tenant-name wedge, filed by lead; behaviour already pinned by an existing refusal case | pre-existing, fail-closed |
| S-1 | fixed | `9b689e7`: two `at=` assertions requiring `+00:00`, mutation-verified (`gmdate`->`date` reddens both) |
| S-2 | fixed | `9b689e7` (stale line citations replaced by symbol) per dev's closure; I did not re-audit the citations |
| S-3 | fixed | `9b689e7`: structural reason (all selecting values in the digest) replaces the defeasible one |
| S-4 | accepted as-is | reviewer's own verdict: keep the distinct exit status; both directions already asserted |
| S-5 | fixed | `9b689e7`: `prefix`-only row refused on all seven routes, `name`-only served; mutation-verified both ways |
| S-6 | fixed | this round, my commit |
| S-7 | closed | this round, planted whole-gate run above |

Not independently re-verified by me: the content of S-2 and the mutation claims in S-1/S-3/S-5 (taken from the `9b689e7` message; the tests they added are in the green 473). The dev change to the kill-case ceiling (`9b59793` message, "replace the flaky ceiling") touches only the plan document in that commit; `GateStepTimerTest` stayed green in all three of my runs, which says nothing about its load behaviour.

Run history: Run 1: PASS (463 / 2403). Run 2: PASS (473 / 2475).

---

# Round 3 (R18 implemented in `3b93389`; tip `d7415e2`)

## Verdict: PASS

Defects: 0 critical, 0 major, 0 minor. Round 2 was PASS; this round confirms one code change and corrects two records of mine.

## Gate, reconciled

- `composer check` on a clean tree of mine, `[state: WARM]` (same machine, same day, caches from my round-2 runs; not otherwise measured), 2026-10-07: **473 passed / 2473 assertions**, exit 0 of the composer run (no `returned with error` and no `FAILED` line in the captured output). Test step 88.89 s Pest duration, margin line `step=test elapsed=89.16s limit=900s ratio=10.09 source=manifest` `[state: WARM]` 2026-10-07. The margin line is the last line of output; `FLEXIPOS-GATE-TIMEOUT` occurs 0 times and the only `FLEXIPOS-GATE-` line is the margin.
- Reconciliation against round 2 (473 / 2475): cases unmoved, assertions -2. Counted from the diff of the kill case: three `toContain` on `budget=1s`, `limit=2s`, `source=env` became one contiguous-triple `toContain` (-2); `child done` absent added (+1); the `>= 1.0` plus `< 2.0` pair became one `>= budget` (-1); the sentinel, step, exit and NO TEST RESULT assertions are unchanged. -2 +1 -1 = -2. Matches dev's 473 / 2473 and my measurement.
- `modules/host/tools/gate-step.php` md5 `50326167d3860ad8f4e586e6ddc95d77`, equal to the hash dev recorded; the wrapper is untouched. `3b93389` touches exactly one file (`GateStepTimerTest.php`, +56/-14); `d7415e2` is memory only. Line 199 is `toBeGreaterThanOrEqual((float) $matches[2])`, a lower bound; no upper bound on elapsed remains in the case.
- `git status` shows one modified file, `docs/plans/...` (architect's, in flight; not mine and not touched).

## AC-41 additions (diff `5beb820..d7415e2`, test files)

`GateStepTimerTest.php`, kill case `it('ends a step that outlasts its own budget ...')`:
- child program gains `echo "child done"` after the sleep; `$limit`, `$reserve`, `$budget` derived in-test from the forced limit by the wrapper's documented rule (`max(2, ceil(0.02*limit))`, `max(1, limit-reserve)`), call passes `(string) $limit`.
- removed: separate `toContain` for `budget=1s`, `limit=2s`, `source=env`.
- added: `toContain(' budget='.$budget.'s limit='.$limit.'s source=env')`; `not->toContain('child done')`.
- changed: the elapsed regex now captures the budget (`elapsed=... budget=(\d+)s`); the `>= 1.0` and `< 2.0` pair replaced by `>= (float) $matches[2]`. Net: one upper bound deleted, one lower bound kept, comments rewritten.
- `toBeLessThan` elsewhere in the file (ratio < factor, budget < limit) is unchanged and is not a wall-clock bound.

## AC-35 mapping, corrected

| Row | Was | Now |
|---|---|---|
| Kill-case ceiling (the "replace the flaky ceiling" item) | backlog row (lead) | **fixed in code, `3b93389`** (R18). The `9b59793` commit touched only the plan; its message read as a code outcome and was not one. My round-2 closing note was correct. |

I checked the other rows (W-1..W-4, S-1..S-7): none depends on the kill-case ceiling or on assertion counts, so none shifts. W-3 (timeouts) stays a backlog row; the precondition window below is filed, not a W-3 closure. Correction to the round-2 sentence "GateStepTimerTest stayed green in all three of my runs, which says nothing about its load behaviour": it still says nothing about load, but the ceiling it referred to no longer exists.

## Dev's two qualifications, preserved as declared

I agree with both characterisations and re-ran only the gate, not the mutations, so the mutation claims below are dev's, taken from the `3b93389` message and report; what I verified is the restored wrapper hash and the green gate.

1. **Budget mutation.** Dev's first budget mutation passed because the mutation was wrong, not the test: at forced limit 2 the budget sits on its floor of 1 for every reserve >= 1, so mutating the ratio or the reserve floor is invisible at that input. Only moving the budget's own floor (1 -> 2) changes the observable. An input where those terms do not bind, not a hole in the case; but it means the case does **not** pin the ratio or the reserve floor, and I do not claim it does.
2. **`child done` absent is corroborating, not independently proven.** The tree kill happens inside the vendored component before the exception reaches our handler, so no mutation of our code isolates it. It is supported by direct-invocation evidence only. Reported as "mutation-proved" for the other three assertions in dev's list (sentinel suppressed, exit code changed, budget floor moved, timer removed: four mutations, all red per dev), and as corroborating for this one.

## Observation filed, not fixed

`GateStepTimerTest.php:287` (low-headroom case): a precondition window, not an assertion problem. A 4.5 s child under limit 8 needs elapsed between 4 s and 6 s for the warning to fire on a run that also exits 0; under extreme contention an overshoot past the budget would redden the exit-code expectation. Failure mode is loud (sentinel visible), not a silent pass, the property R18 accepts for AC-4's recipe. Not changed, to avoid a drive-by; becoming a backlog row (lead). I did not reproduce it.

## Nothing in dev's report measured wrong

The counts (473 / 2473, -2 in the kill case), the one-file diff, the wrapper hash and the lower-bound line all reproduce.

Run history: Run 1: PASS (463 / 2403). Run 2: PASS (473 / 2475). Run 3: PASS (473 / 2473).
