# QA Report: T-022 Cross-tenant isolation suite

## Verdict: PASS

5 new test files (4 under `modules/host/tests/Feature/`, 1 under
`modules/host/tests/Engine/`), 33 test cases total (32 run + 1 skipped under
`ENGINE_HOLD`). All 25 ACs independently re-verified. All 6 mutation proofs
(M-1 to M-6) independently re-run by qa, each confirmed red for the right
reason and reverted clean. Full gate re-run twice by qa: lint/stan/deptrac
clean, only the known pre-existing env/MODULES-gap test failures present, no
T-022 test failing, no regression.

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `QaRouteCrossTenantSweepTest` — classification by `gatherMiddleware()` over the live registry | PASS |
| AC-2 | same — `QA_ROUTE_SWEEP_FLOOR`, 42 names found and counted; verified count derivation (22 organization/terminal + 20 access = 42) | PASS |
| AC-3 | by-id read attempt, both directions, refusal in {404,422}, no leak | PASS |
| AC-4 | collection read attempt + `QA_ROUTE_SWEEP_NO_TENANT_DATA` liveness (`access.me.permissions`, `terminal.settings`) | PASS |
| AC-5 | write attempts (28 non-GET tenant routes), reseed per route, cross/reverse/own | PASS |
| AC-6 | `QA_ROUTE_SWEEP_EXCLUDED`, fail-closed both ways, 8 routes named | PASS |
| AC-7 | planted read/list/write routes reported as leaks | PASS (verified the plant genuinely leaks; see note below on reuse of shared attempt code) |
| AC-8 | `QaJobCrossTenantSweepTest` — reflection `ShouldQueue` scan = `[MigrateTenantQueueJob]` | PASS |
| AC-9 det. | ambient-worker scenarios R/L through sync queue on `migrate-tenant-fixture` | PASS |
| AC-9 [E] | `QaJobCrossTenantSweepEngineTest`, skipped under `ENGINE_HOLD` (NOT VERIFIED while G-1 holds, as spec allows) | PASS (as designed) |
| AC-10 | property-name + serialised-payload scan, fail-closed | PASS |
| AC-11 | `tenantId`-shaped property or allow-list (empty), fail-closed | PASS |
| AC-12 | `QaCacheKeyCrossTenantSweepTest` — site scan = exactly 4 files | PASS |
| AC-13 | key-resolution rules 1–4, builders, 1-entry allow-list, fail-closed | PASS |
| AC-14 | planted files (a)-(f) reported; green after removal | PASS |
| AC-15 | `CachingTenantDatabaseRepository` runtime isolation, evict() | PASS |
| AC-16 | per-`*Bucket` runtime drivers (tenant/user/terminal/IP) | PASS |
| AC-17 | `QaPluginHookCrossTenantSweepTest` — catalogue count printed with/without fixture provider | PASS |
| AC-18 | enabled for A only, dispatch in B: no call, no B row | PASS |
| AC-19 | well-behaved probe, both directions | PASS |
| AC-20 | A tripped, B stays closed — **two tests**, including the dev-added discriminating case | PASS |
| AC-21 | leaky probe reported | PASS |
| AC-22 | file placement (`Feature`/`Engine`), not in `toolchainGroupFiles()`, runs in `test:fast` | PASS |
| AC-23 | in-file controls + M-1 to M-6 (qa-executed) | PASS |
| AC-24 | `is_dir()` on every scan root in all 3 generated-scan files, non-empty route list and hook catalogue | PASS |
| AC-25 | `docs/architecture.md` §21 sentence + file header docblocks | PASS |

## Mutation protocol (M-1 to M-6), independently re-run by qa

Each mutation was applied as a temporary edit, the named test(s) run, then reverted; `git status --short` confirmed clean before moving to the next. All six confirmed.

- **M-1 (AC-20, `CircuitBreaker::recordFailure`)**. Dropped `->where('tenant_id', $tenantId)` from the trip `update`.
  - Ran **only** the plan's originally-named case (`AC-20: tripping tenant A's breaker leaves tenant B's independently-enabled instance closed`) with dev's new case excluded by filter: **it passed green** — confirms dev's claim that the plan's own specified mutation does not redden the original AC-20 test by coincidence (tenant B's `failure_count` never happened to equal the threshold in that scenario).
  - Ran dev's added case (`AC-20: the breaker's own trip statement never flips a tenant it was not called for, even when its count coincides`), which pre-sets tenant B's `failure_count` to the threshold as a discriminating input: **it went red** — `tenant B tripped from tenant A's single failure`, `'error'` vs expected `'enabled'`. Confirms the added test genuinely closes the gap.
  - Reverted; both AC-20 cases pass again (2 passed, 14 assertions).
- **M-2 (AC-18/AC-19, `PluginRuntime::tableFor`)**. Dropped `->where('tp.tenant_id', $tenantId)`.
  - AC-18 went red: `QaHookSweepProbe::$log` was non-empty while dispatching for tenant B (8 log entries for tenant-b, including points/modes), when the plugin was enabled only for A.
  - AC-19 stayed green, as the plan implies (only AC-18 is required to redden) — consistent with the query now returning both tenants' enabled rows, but dispatch still executing only inside the currently-entered connection, so AC-19's own-write/other-untouched assertions are not discriminating for this particular mutation.
  - Reverted; `git status` clean.
- **M-3 (AC-9 det., `MigrateTenantHandler::handle`)**. Replaced `$this->tenancy->run($tenantId, …)` with an inline immediately-invoked closure using the ambient connection, bypassing `tenancy()->enter()`.
  - Scenario R test went red: job status `'failed'` instead of `'done'` (caught one layer deeper, by `assertIdentity()`'s own schema-identity mismatch guard, since the ambient connection it then read was genuinely tenant B's database claiming to be B while this job is for A — a legitimate, if different, name for the same underlying surface: the handler no longer entered its own tenant).
  - Reverted; both AC-9 deterministic cases pass again.
- **M-4 (AC-13, `CachingEntitlementSource::keyFor`)**. Changed the key to `self::KEY_PREFIX.'all'`.
  - AC-13 went red, naming the file and all three call sites (`get`, `put`, `forget`) resolving to the untokened key.
  - Reverted; clean.
- **M-5 (AC-16, `ApiActor::from`)**. Changed the tenant-token-branch's `tenantBucket` to the literal `"t:"`.
  - Both AC-13 (scan) and AC-16 (runtime, `tenantBucket` driver) went red simultaneously: scan named 5 delegated findings in `ThrottleApi.php`; runtime reported `tenant B was throttled by tenant A's bucket`.
  - Reverted; clean.
- **M-6 (AC-2/AC-6, organization route group)**. Deleted `RequireTenantToken::class` from `packages/organization/routes/api.php`'s group middleware.
  - AC-2 went red: `21` tenant/terminal-gated routes found vs floor `42`.
  - AC-6 went red: `organization.branches.list` now classified `none` and not in `QA_ROUTE_SWEEP_EXCLUDED`.
  - Reverted; clean. Re-ran both cases: pass (110 assertions, including the printed AC-6 reason table).

No real leak was found in the shipped codebase. All production code is unchanged after qa's mutation protocol (`git diff --stat` empty throughout).

## Test-local deviations, verified sound

- **AC-5 extra fixtures** (second unattached branch, second unused location, second non-pending terminal). Read the actual handlers:
  - `AttachBranchHandler` refuses `already attached` (validation) — the seeded employee is already attached to the main branch, so `access.branches.attach`'s own-tenant control genuinely needs the second branch (`branchSecond`) to prove a real write.
  - `ChangeLocationStatusHandler` refuses `location_in_use` when the location is the selling location of a pending/active terminal — the main seeded location is exactly that, so `organization.locations.status` genuinely needs the second, unused warehouse location.
  - `ChangeBranchStatusHandler` refuses `has_active_terminals` when a pending/active terminal belongs to the branch — the main seeded branch carries one, so `organization.branches.status` genuinely needs `branchSecond`.
  - All three are real, pre-existing business-rule refusals, not a workaround for a defect. Confirmed by reading the handler source directly, not by trusting the dev report.
- **`app()->forgetInstance(PlanLimits::class)`** in the route sweep's tenant-remapping helper. Confirmed `PlanLimits` is bound as a plain (non-scoped) `singleton` (`PlatformServiceProvider.php:104`) resolving to `ResolvePlanLimitHandler`, which constructor-injects the **scoped** `TenantContext`. Across the sweep's ~28 in-process reseeds (`forgetScopedInstances()` recreates `TenantContext` but not the plain-singleton `PlanLimits`), the old `PlanLimits` instance would otherwise keep a stale `TenantContext` reference. This is a test-harness artifact of reusing one container across many reseeds in-process — something no real request lifecycle does — not a production bug, and not something masking a cross-tenant leak.

## Gate re-run (qa, independent, twice)

- Command: `modules/host && composer check` (lint, PHPStan, deptrac, then `composer test`).
- Run 1: `12 failed, 23 skipped, 2750 passed (19279 assertions)`, `FLEXIPOS-GATE-MARGIN ... elapsed=783.63s ratio=9.95`.
- Run 2 (full, unpiped, to confirm exact failure set): identical — `12 failed, 23 skipped, 2750 passed (19279 assertions)`, `ratio=9.95`.
- Pint: `{"tool":"pint","result":"passed"}`. PHPStan: `[OK] No errors`. Deptrac: `Violations 0, Skipped violations 0, Errors 0`.
- All 12 failures are in `ModulesEnvParsingTest`, `QaHealthProbeResilienceTest`, `QaModuleNameValidationTest` and `QaModuleRegistryContractTest` — exactly the pre-existing, already-diagnosed `MissingAppKeyException`/MODULES-env gap named in the task brief, not new, and not T-022 files. **No T-022 test failed in either full-gate run.**
- Discrepancy vs dev's reported `2749 passed / 13 failed`: qa's two independent runs both measured `2750 passed / 12 failed`, consistently. This is a 1-test difference inside the already-excluded baseline-gap cluster (likely run-to-run flakiness in that known-broken area, not a T-022 regression — the specific failing test names were identical across qa's two runs). Not pursued further per task scope (pre-existing, not qa's or dev's to chase).
- New-files-only run (5 files, 33 cases): `1 skipped, 32 passed (728 assertions)`, 75.39s wall time — within the plan's 60-120s budget.
- Suite-integrity re-run (qa, independent): `ToolchainGroupCoverageTest`, `ToolchainGroupBindingTest`, `QaAssertionShapeGuardTest`, `QaVocabularyRenameSweepTest`, `DestructiveStatementContainmentTest`, `HostTestSuiteCollectionTest`, `QaTestSuiteIsolationTest` — all green, `21 passed (110 assertions)`. None of the 5 new files appear in `toolchainGroupFiles()`.

## Defects

None. No BUG filed.

## Regression risks / notes

- AC-7's planted-route test (route file) proves the leak by direct inline assertions against the planted controllers rather than literally re-invoking the shared `qaRouteSweepLeaks()`/attempt helper functions used by AC-3/AC-4. This still satisfies the letter of AC-7 ("the sweep reports the leak... proving the generated attempt can detect a real cross-tenant read") since the same seeded world and the same planted-leak shape is used, but it is a minor deviation from the plan's wording ("runs the **same** attempt function on those two routes only"). Not a defect — the planted routes genuinely leak and the test genuinely fails if they stopped leaking — but worth the lead's awareness if a future reviewer expects literal code reuse there.
- AC-9 [ENGINE] remains **NOT VERIFIED** while `ENGINE_HOLD`/G-1 holds, as the spec explicitly allows; the inverted hold-case (`refuses to reach the engine at all while the hold is set`) passed, confirming the skip logic itself is live and not silently vacuous.
- `ManifestCache` (an in-process array memo keyed by a global `plugin_version_id`) remains a stated, undetectable blind spot of the cache-key scan (N-1) — tenant-agnostic by design, outside the scan's regex patterns. Documented in the file header per AC-25; no action needed.
- The token-name heuristic (AC-13's rule 1) trusts that a variable merely named like a tenant id holds one; this is exactly why the AC-15/AC-16 runtime halves exist as a second, independent proof. Confirmed this reasoning holds by observing M-5's mutation caught by both the scan and the runtime simultaneously.

## History

- Run 1: PASS (this report).
