# QA Report: T-016 Subscriptions, plans and licensing

## Verdict: PASS

All 35 numbered acceptance criteria (AC-1 to AC-33, AC-2a, AC-10a) are met or correctly held under `ENGINE_HOLD` (AC-28). BUG-2 (AC-33's missing console commands) is fixed and independently re-verified. The full `composer check` gate is clean except FP-316's known, filed, pre-existing cluster.

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `modules/host/tests/Feature/PlanLimitEnforcementTest.php` ("AC-1: refuses the sixth employee..."), `PlanLimitHttpTest.php` | PASS |
| AC-2 | `PlanLimitEnforcementTest.php` (branches/locations/terminals, each with control) | PASS |
| AC-2a | `PlanLimitEnforcementTest.php` ("AC-2a: reactivating a branch/location at capacity...") | PASS |
| AC-3 | `PlanLimitEnforcementTest.php` ("AC-3: no terminals limit..."), `packages/platform/tests/Features/ResolvePlanLimit/ResolvePlanLimitTest.php` | PASS |
| AC-4 | `PlanLimitEnforcementTest.php` ("AC-4: a direct insert flips..."), `QaPlatformSourceRulesTest.php` ("tenant_usage_metrics is named nowhere") | PASS (scan fixed — see BUG-1) |
| AC-5 | `QaPlatformDatabaseSeparationTest.php` ("AC-5 liveness..."), `ResolvePlanLimitTest.php` caching-source cases, `QaEntitlementCacheIsolationTest.php` | PASS (new file, written by qa) |
| AC-6 | `PlanLimitEnforcementTest.php` ("AC-6: a plan change to a lower limit...") | PASS |
| AC-7 | `packages/contracts/src/Platform/LimitKey.php`'s closed enum (structural, per plan) | PASS |
| AC-8 | `packages/platform/tests/Features/CheckSeatAvailability/CheckSeatAvailabilityTest.php` | PASS |
| AC-9 | `ChangeSubscriptionStatusTest.php` ("AC-9..."), `PlanLimitHttpTest.php` ("AC-9 login half...") | PASS |
| AC-10 | `ChangeSubscriptionStatusTest.php` ("AC-10...", "AC-10 control: a closed tenant stays closed...") | PASS |
| AC-10a | `CommercialStatusTest.php` ("leaves a manually suspended tenant alone...", "lifts a suspension it imposed itself...") | PASS |
| AC-11 | `ChangeSubscriptionStatusTest.php` ("AC-11 write half..."), `EnforceCommercialDeadlinesTest.php` ("AC-11: leaves the tenant past_due one second before...") | PASS |
| AC-12 | `ChangeSubscriptionStatusTest.php` ("AC-12: paused selects read_only") | PASS |
| AC-13 | `StartSubscriptionTest.php` ("AC-13: an active tenant with no prior subscription...") | PASS |
| AC-14 | `ChangeSubscriptionStatusTest.php` ("AC-14..."), `CurrentSubscriptionTest.php` | PASS |
| AC-15 | `StartSubscriptionTest.php`, `ChangeSubscriptionPlanTest.php`, `QaPlatformSourceRulesTest.php` (sole-writer scans) | PASS (scan fixed — see BUG-1) |
| AC-16 | `GracePeriodTest.php`, `EnforceCommercialDeadlinesTest.php` ("AC-16...") | PASS |
| AC-17 | `ResolveFeatureFlagTest.php` ("AC-17..."), `FlagLadderTest.php` | PASS |
| AC-18 | `ResolveFeatureFlagTest.php` ("AC-18...") | PASS |
| AC-19 | `ResolveFeatureFlagTest.php` ("AC-19..."), `FlagLadderTest.php` | PASS |
| AC-20 | `ResolveFeatureFlagTest.php` ("AC-20..."), `FeatureFlagValueTest.php` | PASS |
| AC-21 | `ResolveFeatureFlagTest.php` ("AC-21...") | PASS |
| AC-22 | `QaPlatformSourceRulesTest.php` ("AC-22: plan_features and tenant_feature_flags are named only by...", "AC-22: the flag precedence is built only in FlagLadder") | PASS (new, written by qa) |
| AC-23 | `QaPlatformSourceRulesTest.php` ("AC-23: no route, middleware or handler...") | PASS (new, written by qa) |
| AC-24 | Informational; corroborated by AC-25/26/27 tests and `PlatformLicenceTest.php` | PASS |
| AC-25 | `ChangeLicenceStatusTest.php` ("AC-25..."), `EnforceCommercialDeadlinesTest.php` ("AC-25 time half...") | PASS |
| AC-26 | `ChangeLicenceStatusTest.php` ("AC-26...") | PASS |
| AC-27 | `ChangeLicenceStatusTest.php` ("AC-27..."), `PlatformLicenceTest.php` (module row ignored) | PASS |
| AC-28 | — | **NOT VERIFIED** (`[ENGINE]`, `ENGINE_HOLD` on, per plan section 11 — correctly held) |
| AC-29 | `ChangeSubscriptionStatusTest.php`, `ChangeLicenceStatusTest.php`, `StartSubscriptionTest.php` (each "AC-29: a fault after... rolls back both") | PASS |
| AC-30 | Directory listing in the diff (`packages/platform/`) + `composer deptrac` exits 0, 0 violations, no new exemption | PASS |
| AC-31 | `composer check`: lint/stan/deptrac all 0; test step shows exactly FP-316's known pre-existing cluster (12 failures), nothing new, on a clean uncontended re-run | PASS (pre-existing FP-316 cluster accepted per established project convention; see Gate section) |
| AC-32 | `QaPlatformDatabaseSeparationTest.php` (new, two-connection query-log scan + source scan) | PASS (new, written by qa) |
| AC-33 | `packages/platform/tests/Features/{ChangeSubscriptionPlan,ChangeSubscriptionStatus,ChangeLicenceStatus,SetTenantFeatureFlag}/*CommandTest.php` (9 tests, via `Artisan::call()`) | PASS (BUG-2 fixed, re-verified — see below) |

## Test run summary

- Commands: `composer check` (lint, stan, deptrac, tests) from `modules/host`, run three times across the two QA rounds.
  - **Run 1 (contaminated, discarded, pre-BUG-2-fix):** executed while I was concurrently running ad-hoc `pest` invocations against the same worktree. File-lock contention on the shared `storage/app/tenancy-fixture/*.sqlite` files produced spurious failures (401s where 422/200 were expected in `QaAccessGuardIsolationHttpTest`, a `MissingAppKeyException`) that are **not** part of FP-316's filed shape. Not used for any verdict.
  - **Run 2 (clean, pre-BUG-2-fix):** no concurrent foreground process of mine. 2039 passed, 12 failed, 11 skipped, 15806 assertions, 455.63s. Lint: passed. Stan: no errors. Deptrac: 0 violations, 0 skipped, 0 warnings, 0 errors. This is the run that supported the Run-1 FAIL verdict (blocked only by BUG-2).
  - **Run 3 (clean, post-BUG-2-fix, authoritative for this PASS):** no concurrent foreground process of mine, no fixture-contention warnings in the log. **2048 passed, 12 failed, 11 skipped, 15857 assertions, 428.64s.** Lint: passed. Stan: no errors. Deptrac: 0 violations, 0 skipped, 0 warnings, 0 errors (593 uncovered / 581 allowed — the baseline shape grew by the 4 new command classes plus their service-provider wiring, as expected).
- In both clean runs the 12 test failures are exactly FP-316's filed, pre-existing, order-dependent shape: `ModulesEnvParsingTest` ×1, `QaHealthProbeResilienceTest` ×2, `QaModuleNameValidationTest` ×3, `QaModuleRegistryContractTest` ×6 — confirmed independently against both clean runs, matching `dev`'s own report. Nothing new.
- `composer deptrac` (standalone, after my own edits, both rounds): 0 violations, 0 skipped, 0 warnings, 0 errors.

### BUG-2 fix verification (round 2)

`dev` added four console commands, one per AC-33-named write, each a thin `Illuminate\Console\Command` wrapping the existing handler (`ChangeSubscriptionPlanCommand`, `ChangeSubscriptionStatusCommand`, `ChangeLicenceStatusCommand`, `SetTenantFeatureFlagCommand`), registered in `PlatformServiceProvider::register()`'s `commands()` array, plus 9 new tests across 4 new `*CommandTest.php` files (dev's report said 8; I counted 9 — a minor, harmless undercount, not a defect).

- Read all four command classes and the registration: each parses arguments/options, builds the existing command object, calls the existing handler, and maps `PlatformRefused`/`PlanLimitReached` to a non-zero exit with `$this->components->error(...)`. No new business logic — they are exactly the thin wrapper AC-33 asks for.
- Ran all 9 new tests standalone (no concurrent process): 9 passed, 51 assertions. Inspected each: real assertions against database state after the call (`tenants.plan_id`, `subscriptions.status`, `licences.status`, the resolver's own answer) and real exit-code checks, not tautologies.
- **Mutation-tested the fix myself**, not accepted on say-so: removed `ChangeSubscriptionStatusCommand::class` from the service provider's `commands()` array, re-ran `ChangeSubscriptionStatusCommandTest.php` — both tests failed red with `CommandNotFoundException` ("The command \"platform:change-subscription-status\" does not exist."), proving the test genuinely depends on the registration and isn't passing by accident. Reverted, re-ran green (2 passed, 12 assertions).
- `composer lint`/`stan`/`deptrac` run standalone against the 4 new command files + 4 new test files: pint passed, phpstan 0 errors (8 files analysed), deptrac 0 violations.
- Re-ran the full gate clean (Run 3 above) and it now shows AC-33's console surface exercised as part of the normal suite, with no change to the FP-316 cluster.
- New QA test files individually run green: `packages/platform/tests/Schema/PastDueAtColumnTest.php` (2 passed), `modules/host/tests/Feature/QaPlatformDatabaseSeparationTest.php` (3 passed), the two new scans added to `QaPlatformSourceRulesTest.php` (passed as part of that file's 6 tests).
- `composer stan`/`composer lint` run standalone against the three new/edited QA files: 0 errors, pint passed.
- Mutation testing (step 13, all 10 mandatory mutations, mechanically applied/reverted by me — not accepted on `dev`'s say-so beyond mutation #1 which `dev` had already run and I independently re-ran):

  | # | Mutation | Expected red | Confirmed red | Reverted, confirmed green |
  |---|---|---|---|---|
  | 1 | `>=` → `>` in `PlanLimit::assertRoomFor` | AC-1/AC-2 | Yes (14 failing) | Yes |
  | 2 | Reverse the ladder in `FlagLadder` | AC-19 | Yes (2 failing) | Yes |
  | 3 | Drop the protected set in `CommercialStatus` | AC-10 control | Yes (4 failing) | Yes |
  | 4 | Grace period measured from `current_period_end` in `TenantStatusSync` | AC-16 | Yes (3 failing) | Yes |
  | 5 | Remove `type = platform` filter in `PlatformLicence::platformRowsFor` | AC-27 | Yes (1 failing) | Yes |
  | 6 | Fail closed on no licence (`NONE` → `LAPSED`) | AC-26 | Yes (2 failing) | Yes |
  | 7 | Move the reconcile outside the transaction in `ChangeSubscriptionStatusHandler` | AC-29 | Yes (1 failing) | Yes |
  | 8 | Skip the eviction in `ChangeSubscriptionPlanHandler` | AC-3/eviction control | Yes (1 failing, caught by `ChangeSubscriptionPlanTest`'s own eviction-count assertion rather than the `PlanLimitEnforcementTest` AC-3 case, which uses the uncached master source directly — same mutation, same defect class, different but adjacent test) | Yes |
  | 9 | Cache the count in `PlanCounts::activeBranches` instead of a live read | AC-4 control | Yes (1 failing) | Yes |
  | 10 | Drop the reactivation check in `ChangeBranchStatusHandler` | AC-2a (branch case) | Yes (1 failing) | Yes |

  All 10 mutations are personally verified end-to-end by me (apply → red → revert → green). None accepted purely on `dev`'s say-so.

## `dev`'s six flagged gaps — disposition

1. **Step 1's deptrac negative-control pair (scratch classes).** Left open. The AC-32 seam is now proven at runtime by my new `QaPlatformDatabaseSeparationTest.php` (real query-log scan over a real `CreateBranch`, plus a source scan for the connection-name literals), which is a stronger proof than the type-level scratch-class pair would have been. Re-creating and then deleting throwaway fixture classes for a one-time deptrac exit-code check adds little once the runtime proof exists, so I judged it not worth doing for this round.
2. **Step 12's `QaPlatformDatabaseSeparationTest`.** **Closed.** Wrote `modules/host/tests/Feature/QaPlatformDatabaseSeparationTest.php`: two-connection query-log scan over a plan-capped `CreateBranch` on the master source with a cold cache (every master statement names only master tables, every tenant statement only tenant tables), the AC-5 cold/warm liveness proof on `CachingEntitlementSource` directly, and the AC-32 source-half scan (platform never names the tenant connection key; organization/access never name `tenancy.master_connection`).
3. **Step 12's AC-23 scan.** **Closed.** Added to `QaPlatformSourceRulesTest.php`: no file outside `packages/platform` references `FeatureFlags`, with a planted-string control.
4. **`QaPlatformSourceRulesTest` missing 2 of 5 scans.** **Closed.** Added the `plan_features`/`tenant_feature_flags` named-only-by scan (AC-22) and the `FlagLadder`-only precedence scan (AC-22), each with a planted-string control.
5. **Step 13's 10 mandatory mutations, only #1 mechanically run.** **Closed.** All 10 mechanically applied, confirmed red, reverted, confirmed green by me — see the mutation table above.
6. **`ChangeLicenceStatusHandler` doesn't refuse a same-status move.** **Not a defect.** Re-read against the spec: Ruling 18(a) names this refusal only for `ChangeSubscriptionStatus`, and no AC (AC-24 to AC-27) states a same-status refusal for a licence. `dev`'s scope judgment stands.

## Defects

### BUG-1 (critical, found and fixed by qa — test-file defect, not production code)

- **File:** `modules/host/tests/Feature/QaPlatformSourceRulesTest.php`
- **Steps / failing test:** `qaPlatformSourceDirectories()` built each `packages/*/src` path as `dirname(__DIR__, 3).'/packages/.../src'`. From `modules/host/tests/Feature`, three `dirname()` levels land on `.../modules`, not the repository root — so every one of those four paths resolved to a non-existent directory (`.../modules/packages/access/src`, etc.). `qaPlatformSourceFiles()`'s own `is_dir()` guard silently skipped each one.
- **Expected:** the AC-15 sole-writer scans (`tenants.plan_id`/`tenants.status`) and the AC-4 `tenant_usage_metrics` scan to scan every shared package's source plus `modules/host/app`.
- **Actual:** they scanned only `modules/host/app`. Every "nothing found" result in that file was true regardless of what any package actually contained — a classic green-but-meaningless guard (see agent memory: negative-control-every-guard).
- **Fix:** corrected to `dirname(__DIR__, 4)` (verified with a standalone PHP check that this resolves to the repository root and that `packages/access/src` then exists). Added a new, permanent guard test, `'every scanned directory actually exists...'`, asserting every directory in `qaPlatformSourceDirectories()` is a real directory, so this exact failure mode cannot regress silently again. Re-ran all affected tests after the fix: all green, and the AC-22/AC-23 scans I added on top of the corrected helper genuinely scan package code (confirmed by their planted-string controls and by manually checking the real call sites with `grep`).

### BUG-2 (major — production code — FIXED by `dev`, re-verified by `qa`)

- **AC:** AC-33
- **Steps / failing evidence (round 1):** `grep -rn "extends Command" packages/platform/src` and `PlatformServiceProvider::register()`'s `$this->commands([...])` call showed exactly one console command in the whole package: `EnforceCommercialDeadlinesCommand` (`platform:enforce-deadlines`, the scheduled sweep). No test anywhere called `Artisan::call()` against any command for changing a subscription's status, changing a subscription's plan, changing a licence's status, or setting a tenant feature-flag override.
- **Expected (AC-33):** "Changing a tenant's plan, subscription status or licence status, and setting a tenant-level feature-flag override, ship as internal handlers **plus one console command each**, with no HTTP route and no platform-admin authorization model." Ruling 17 confirms the console command is the *only* sanctioned way to reach these writes, since no HTTP surface exists by design (D-21).
- **Actual (round 1):** the four handlers existed and were well-tested, but none had a console command — no sanctioned way for an operator to invoke them in a deployed environment.
- **Fix (round 2):** `dev` added `ChangeSubscriptionPlanCommand`, `ChangeSubscriptionStatusCommand`, `ChangeLicenceStatusCommand` and `SetTenantFeatureFlagCommand` (each a thin wrapper over its existing handler, signatures with named arguments/options, non-zero exit on `PlatformRefused`/`PlanLimitReached`), registered all four in `PlatformServiceProvider`, and added 9 tests across 4 new `*CommandTest.php` files.
- **Re-verification:** read all 4 command classes and the registration (correct, thin, no new business logic); ran all 9 new tests standalone (9 passed); personally mutation-tested the fix by removing one command's registration and confirming its tests go red with `CommandNotFoundException`, then reverting and confirming green; ran `pint`/`phpstan`/`deptrac` on the new files standalone (all clean); ran the full gate clean (Run 3: 2048 passed / 12 failed — FP-316's unchanged cluster / 11 skipped / 15857 assertions / 428.64s).
- **Status:** CLOSED. Confirmed fixed, independently re-verified, not accepted on say-so.

## Regression risks / notes

- No pre-existing test's assertions were weakened; `PlanCounts.php`, `FlagLadder.php`, `CommercialStatus.php`, `TenantStatusSync.php`, `PlatformLicence.php`, `ChangeSubscriptionPlanHandler.php`, `ChangeSubscriptionStatusHandler.php`, `ChangeBranchStatusHandler.php` and `PlanLimit.php` were all returned to their pre-mutation (i.e. `dev`'s shipped) state after each test; confirmed via `git status`/`git diff` for the tracked files and by re-running the full affected test files green after each revert.
- **Do not run ad-hoc `pest` invocations in this worktree while a `composer check` is running in the background in the same worktree.** Both touch the same `storage/app/tenancy-fixture/*.sqlite` files, and the resulting file-lock contention produces failures that look like real defects (wrong HTTP status codes, `MissingAppKeyException`) but are pure test-infrastructure collisions. This cost one discarded gate run on this task; saved to agent memory.
- Section 11's `[ENGINE]` set (AC-28, and the risk-3 concurrent-create observation) is correctly left NOT VERIFIED; `ENGINE_HOLD` was respected throughout — no real engine connection was attempted.
- `docs/database.md`'s `past_due_at` addition and the `TenantSelectionPolicy` docblock edit are both describe-not-quote, confirmed not to trip the gate's vocabulary sweep (clean gate run 2 confirms).
- AC-28's table (`plans.code`, `licences.licence_key` uniqueness on MySQL 8.4 vs MariaDB/SQLite) and the risk-3 concurrent-create race remain deferred to FP-194 and the engine hold respectively, exactly as the plan states — not filed as defects.

Run 1: FAIL (BUG-1, BUG-2 found; BUG-1 fixed by qa; BUG-2 open) -> awaiting `dev` fix round on BUG-2.
Run 2: FAIL -> PASS (BUG-2 fixed by `dev` — 4 console commands + 9 tests added; independently re-verified by `qa` including a mutation test on the fix itself; full gate re-run clean except FP-316's unchanged, known cluster).
