---
name: t022-crosstenant-sweep-closeout
description: T-022 cross-tenant isolation suite close-out — discriminating-input mutation trap on CircuitBreaker, scoped-vs-singleton staleness in test reseed helpers, AC-count-matches-reality verification technique.
metadata:
  type: project
---

T-022 (FP-216) shipped 5 permanent generated sweeps (routes, jobs, cache keys,
plugin hooks, +1 engine file) proving the platform's "no tenant reaches
another tenant's database" property generically, gated into `composer check`.
QA verdict: PASS, 0 defects, all 25 ACs and all 6 dev-prepared mutations
(M-1 to M-6) independently re-verified by re-running them myself. See
[[project_isolated-clone-technique-for-contaminated-worktrees]] and
[[project_t019-plugin-framework-closeout]] for related breaker/plugin
mechanics this task reused.

**A planned mutation can silently fail to redden by coincidence — verify the
plan's own claim before trusting the dev-added patch.** M-1 removed
`CircuitBreaker::recordFailure`'s `tenant_id` filter from the trip `UPDATE`.
The plan's own named test (tripping A, asserting B stays `enabled`) passed
*green* under that mutation, because B's `failure_count` never happened to
already equal the threshold — the WHERE clause without the tenant filter
still filtered on `failure_count >= threshold`, and B's count was 0. Dev
noticed this and added a second test that pre-sets B's `failure_count` to the
threshold directly (bypassing the gateway, calling `CircuitBreaker` itself),
giving the mutation a discriminating input. I reproduced both halves
independently: ran the original-only test under the mutation (green, proving
dev's claim correct) and the new test under the same mutation (red). This is
the generalizable lesson: **a negative control that passes by construction
only when the two tenants' pre-mutation state values happen to coincide is
not proven by "I ran the mutation and it went red" — rerun it with the
*counter*-condition absent, too.**

**A plain `singleton` that constructor-injects a `scoped` dependency goes
stale across many in-process reseeds.** `PlanLimits` → `ResolvePlanLimitHandler`
is bound `$this->app->singleton(...)` (not scoped), but constructor-injects
the `scoped` `TenantContext`. A route-sweep helper that calls
`forgetScopedInstances()` ~28 times in one process (once per write-route
reseed) recreates `TenantContext` each time, but the old `PlanLimits` object
keeps referencing the *first* `TenantContext` it was built with, unless
`app()->forgetInstance(PlanLimits::class)` is also called. Confirmed this by
reading `PlatformServiceProvider.php` and `ResolvePlanLimitHandler.php`
directly rather than trusting the dev comment. This is a test-harness-only
hazard (no real request lifecycle reseeds a container mid-request) and not a
production defect — but it is exactly the shape to check whenever a QA sweep
reuses one Laravel application across many simulated tenants/requests in a
single test process: grep every `app()->singleton(...)` binding that also
constructor-injects something `scoped`.

**Verifying "N business routes are tenant-gated" by hand-deriving the count
from the route list, not by trusting either the spec or the dev report.**
AC-2's floor was 42 (organization 22 + access 20). I independently summed the
route names listed in `QA_ROUTE_SWEEP_FLOOR` by category and got 42,
matching. Also summed `QA_ROUTE_SWEEP_RECIPES` (28 non-GET tenant routes) by
the same method and got 28. Cheap, catches a copy-paste count drift that
`toBe(42)` alone would not reveal if the constant list itself silently lost a
name while gaining another.

**Re-running the fast suite's gate twice measured a genuine ±1 flake inside
the already-known baseline-gap cluster** (`ModulesEnvParsingTest`,
`QaHealthProbeResilienceTest`, `QaModuleNameValidationTest`,
`QaModuleRegistryContractTest` — all pre-existing `MissingAppKeyException`/
`MODULES`-env gaps, unrelated to T-022). Two independent full runs both gave
`12 failed / 2750 passed / 23 skipped`, differing from dev's self-reported
`13 failed / 2749 passed` by exactly one test, with the identical failing
test file names both times. Treated as a known, out-of-scope flake rather
than chased, per the task's explicit environment note — but worth recording:
dev's own count and qa's independently-reproduced count can legitimately
differ by a small amount in this specific pre-existing cluster without it
meaning either party ran the gate wrong.
