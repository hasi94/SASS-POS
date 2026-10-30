# QA Report: T-020 Workflow engine

## Verdict: PASS

Production code is correct everywhere it was mutation-tested (15/15 plan-mandated mutations
behaved as the plan predicts). Two plan-mandated regression tests (AC-9 approver-resolution
timing, and the `branch_ids` fail-closed case) were **missing** from the shipped suite despite a
fully green, lint/stan/deptrac-clean gate — a real defect in test coverage, not in behaviour. Per
QA process and my test-file-only edit permission, I wrote both tests myself, confirmed they catch
the exact regressions the plan names, confirmed they pass against the real (correct) code, and
confirmed lint/stan/deptrac/the package suite are clean with the addition. See BUG-1/BUG-2 below.

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `WorkflowEntityTypeTest`, `WorkflowTenantSchemaTest` (columns, keys) | PASS |
| AC-2 | `WorkflowTenantSchemaTest` (raw insert backstop), `RegisterWorkflowDefinitionTest` (`keyAlreadyRegistered`, active/inactive/soft-deleted) | PASS |
| AC-3 | `WorkflowTenantSchemaTest` (FK/unique index), `DefinitionValidatorTest` (`duplicateSequence`, `sequenceGap`) | PASS |
| AC-4 | `WorkflowSchemaEngineTest` — **NOT VERIFIED**, `ENGINE_HOLD` in force; skip is explicit and named (`EngineOnHold`), not silent. Stand-in half verified in `WorkflowTenantSchemaTest` | PASS (as scoped) |
| AC-5, AC-7 | `ConditionEvaluatorTest` | PASS |
| AC-6 | `ConditionEvaluatorTest`, `EvaluateWorkflowTest` | PASS |
| AC-8 | `DefinitionValidatorTest` (`listValueRequired`, control `["a"]` accepted) | PASS |
| AC-9 | `DatabaseRoleHoldersTest`, `ApproverResolverTest`, `EvaluateWorkflowTest` (*my added* membership-timing case), `DecideWorkflowTaskTest` (step-2 change). Four types resolve, three refused (`approverTypeUnavailable`) at write time, verified directly | PASS |
| AC-10 | `QuorumRuleTest` (every Ruling-7 row), `DecideWorkflowTaskTest` | PASS |
| AC-11 | `DecideWorkflowTaskTest` | PASS |
| AC-12 | `StepActivatorTest`, `DecideWorkflowTaskTest` | PASS |
| AC-13 | `WorkflowProviderTest`, `EvaluateWorkflowTest`, `QaWorkflowSourceRulesTest` (no plugin-framework reference) | PASS |
| AC-14, AC-16 | `EvaluateWorkflowTest` | PASS |
| AC-15 | `EvaluateWorkflowTest` (missing field, engine error+rollback, tie, caller's action counter); `QaWorkflowSourceRulesTest` (no returning catch) | PASS |
| AC-17, AC-19, AC-20 | `DecideWorkflowTaskTest`; AC-19 read also in `CancelWorkflowInstanceTest` | PASS |
| AC-18 | `DecideWorkflowTaskTest` (exact event sequence), `CancelWorkflowInstanceTest`, `QaWorkflowSourceRulesTest` (sole writer, insert-only) | PASS |
| AC-21 | `CancelWorkflowInstanceTest` | PASS |
| AC-22 | `WorkflowTenantSchemaTest` (writable), `RegisterWorkflowDefinitionTest` (stored), `QaWorkflowSourceRulesTest` (no reader, no schedule) | PASS |
| AC-23 | `ListWorkflowDefinitionsTest` | PASS |
| AC-24 | `ListPendingWorkflowTasksTest` | PASS |
| AC-25 | `DecideWorkflowTaskCommandTest` | PASS |
| AC-26 | `QaWorkflowNoHttpSurfaceTest` — verified with a real mounted route, not just the self-control | PASS |
| AC-27 | `WorkflowPipelineTest` | PASS |
| AC-28 | `QaWorkflowSourceRulesTest` | PASS |
| AC-29 | Full gate (below) | PASS |
| AC-30 | Deptrac (0 violations) + my own independent re-run of the step-1 negative control (a real planted file in `modules/demo/src`) | PASS |
| AC-31 | `QaWorkflowSourceRulesTest` scan, `git diff --stat` on `packages/plugin-sdk`/`Contracts\Plugins` confirmed empty, `HookModeTest` untouched and green | PASS |

## My 15 mutation-and-revert checks (plan step 16)

All applied to real source, confirmed red for the named reason, reverted, confirmed green, confirmed
`git status` clean before moving on.

| # | Mutation | Target AC | Result |
|---|---|---|---|
| 1 | `WorkflowEngine::evaluate()`'s catch returns `NotRequired` instead of rethrowing | AC-15 | Red (5 failures incl. engine-error, ambiguous-tie, alreadyPending, action-counter cases) → reverted → green |
| 2 | Missing field in `ConditionEvaluator` returns `false` instead of throwing | AC-6 | Red (AC-6 case) → reverted → green |
| 3 | `DefinitionValidator` accepts a scalar for `in`/`not_in` | AC-8 | Red (AC-8 case, crashes on the next line as expected since the list assumption is now broken) → reverted → green |
| 4 | Empty condition list returns `false` instead of always matching | AC-7 | Red (AC-7 case) → reverted → green |
| 5 | `QuorumRule::Majority` need = `intdiv(n,2)` (no `+1`) | AC-10 | Red (5 cases incl. `n=4, 2 approve`) → reverted → green |
| 6 | `QuorumRule::Any` rejects on first rejection | AC-10 | Red (exact AC-10 `any` case, both `QuorumRuleTest` and `DecideWorkflowTaskTest`) → reverted → green |
| 7 | `QuorumRule::All` only rejects when `rejected === n` | AC-10 | Red (exact step-2-reject case) → reverted → green |
| 8 | `where status='pending'` guard + explicit status check removed in `DecideWorkflowTaskHandler` | AC-17 | Red (exact AC-17 double-decide case) → reverted → green |
| 9 | Closed-instance check removed in `DecideWorkflowTaskHandler` | AC-20 | Red (2 cases: `DecideWorkflowTaskTest` + `CancelWorkflowInstanceTest`) → reverted → green |
| 10 | `DefinitionRepository::candidatesFor()` sorts priority descending | AC-16 | Red (exact AC-16 case) → reverted → green |
| 11 | `DefinitionSelector` picks `$matches[0]` on a tie instead of throwing | AC-15 | Red (exact tie case) → reverted → green |
| 12 | Approvers memoised on `approver_ref` shape (simulating "resolved once", not per step-activation) | AC-9 | **No red anywhere in the 94-test suite — BUG-1.** Fixed by adding the missing test (see below); re-verified red with the new test, reverted, green |
| 13 | `CancelWorkflowInstanceHandler` writes no `cancelled` event | AC-21/AC-18 | Red (missing event, `ErrorException` reading a null row) → reverted → green |
| 14 | Requester not excluded from resolved approver set | self-approval | Red (exact self-approval case) → reverted → green |
| 15 | `branch_ids` block removed from `DefinitionSelector::matches()` | branch-scoping | **No red anywhere in the 94-test suite — BUG-2.** Fixed by adding the missing test (see below); re-verified red with the new test, reverted, green |

## Fail-closed (D-3) — verified directly

Confirmed by reading `WorkflowEngine::evaluate()`: it opens one transaction, runs the handler, and
has exactly one `catch (Throwable)` that rethrows as `WorkflowEvaluationFailed` — never a `return`.
Mutation 1 above proves this empirically: forcing that catch to return `NotRequired` is caught by
five independent existing tests (not a single weak assertion). `QaWorkflowSourceRulesTest`'s
`qaWorkflowReturningCatchFiles()` scan independently confirms no returning-catch textually exists in
`EvaluateWorkflowHandler.php` or `WorkflowEngine.php`, with a real planted violation in
`packages/workflow/src` (not just its own temp-directory fixture) correctly detected by me.

## Tie-handling (AC-15/AC-16) — verified directly

Mutation 11 confirms: two active definitions at the same priority throw `ambiguousDefinitions`
rather than silently picking one. `EvaluateWorkflowTest` also proves two same-priority definitions
whose conditions do not overlap (e.g. different branches) are legal — only a real tie throws.

## Approver-type refusal (AC-9, Ruling 6c) — verified directly

Read `ApproverResolver::resolve()` and `DefinitionValidator`: `manager_of_requester`,
`department_head`, `branch_manager` are refused at **definition-write time** with
`approverTypeUnavailable` (`DefinitionValidatorTest`), and the engine also fails closed if one is
ever reached at step-activation time (`ApproverResolverTest`'s hand-inserted-row case). `role`,
`employee`, `any_of`, `all_of` resolve against real seeded role/employee data in
`ApproverResolverTest` and the full-pipeline `EvaluateWorkflowTest`/`DecideWorkflowTaskTest` cases.

## Self-approval, branch-scoping, duplicate refusal, assignee-only decide

- **Self-approval removal:** mutation 14, red/reverted (above).
- **Branch-scoping fail-closed:** was untested (BUG-2); now tested and verified both directions
  (another branch → `NotRequired`; no branch → `conditionNotEvaluable`; the named branch → matches).
- **Duplicate-request refusal (`alreadyPending`):** `EvaluateWorkflowTest`'s Ruling-9 case, read
  directly and exercised — a second `evaluate()` for the same pending `(entity_type, entity_id)`
  throws; a cancel then re-evaluate creates a new instance.
- **Only the assignee decides, no operator override:** confirmed by reading
  `DecideWorkflowTaskCommand.php` — `--as` is passed straight through as `actorEmployeeId`, which
  `DecideWorkflowTaskHandler` checks against `assignee_employee_id` with `notAssignee`. No override
  flag exists. `DecideWorkflowTaskTest`'s `notAssignee` case exercises this.

## `Module_Demo` has no deptrac edge to `packages/workflow` (AC-30) — verified directly

Planted `modules/demo/src/QaPlantedWorkflowEdge.php` importing `Flexipos\Workflow\Engine\WorkflowEngine`
directly → `composer deptrac` reported exactly one violation (`DependsOnDisallowedLayer`, naming the
file and the disallowed `Package_Workflow` layer). Changed the same file to import only
`Flexipos\Contracts\Workflow\WorkflowGate` → 0 violations. Deleted the planted file, re-ran deptrac →
0 violations (back to the gate's own baseline).

## `ENGINE_HOLD` / AC-4 (not-verified handling)

Read `WorkflowSchemaEngineTest.php`: the hold-check test runs for real and asserts `EngineOnHold` is
thrown; the real engine test is `->skip(fn() => EngineConnection::onHold(), 'ENGINE_HOLD: see
Flexipos\Testing\Engine\EngineOnHold')` — an explicit, named skip, not a silent one. This is the
shipped skip that raises the suite's skip count by exactly 1 (21 → 22), confirmed in the full run.

## Two QA sweep files mutation-tested against the real scanned directory (not just their own fixture)

For `QaWorkflowSourceRulesTest`, planted real violations directly under `packages/workflow/src` (not
a temp directory) and confirmed the test's *first* assertion — which already scans the real
directory before exercising its own temp-fixture control — goes red:
- A file importing `Flexipos\Contracts\Plugins\HookGateway` under `packages/workflow/src` → red on
  line 88 (the real-directory scan), not just the planted-temp-dir control. Removed, green again.
- A file reading `$x->sla_due_at` under `packages/workflow/src` → red on line 262 (the real
  `packages/*/src` + `modules/*/src` scan). Removed, green again.

For `QaWorkflowNoHttpSurfaceTest`: the primary assertion already runs against the live route table
(`Route::getRoutes()`), and its control mounts a real route rather than a string fixture — already a
genuine check, no gap found.

## Test run summary

- **Lint:** `composer lint` (Pint `--test`) → `{"tool":"pint","result":"passed"}`.
- **Stan:** `composer stan` (PHPStan level 6) → `[OK] No errors`.
- **Deptrac:** `composer deptrac` → 0 violations, 0 skipped, 1042 allowed (independently re-run three
  times during this QA round, including with my two planted AC-30 probes).
- **Full gate test step** (`composer check`'s `test` step, `tools/gate-step.php test -- vendor/bin/pest`),
  run once clean from `modules/host`, no competing `php.exe`/`pest` process at start:
  **2614 passed, 12 failed, 22 skipped, 18149 assertions, 613.11s.**
  `FLEXIPOS-GATE-LOW-HEADROOM step=test ratio=1.47` (manifest-derived).
- The 12 failures are **exactly** the pre-existing FP-316 cluster, confirmed against this project's
  own recorded signature (12 failures, confined to `QaModuleRegistryContractTest` ×6,
  `QaModuleNameValidationTest` ×3, `QaHealthProbeResilienceTest` ×2, `ModulesEnvParsingTest` ×1, all
  module-registry/bootstrap-adjacent, nothing workflow-related). This matches dev's own report
  (2614/12/22, 617.93s, ratio 1.46) within normal run-to-run variance.
- After writing the two missing tests (BUG-1/BUG-2 fix), re-ran `packages/workflow/tests` in
  isolation: **96 passed** (94 + 2), plus a clean lint/stan/deptrac re-run. A second attempt at a
  full `composer check` run later in the session returned 248 failures with `SQLSTATE[HY000]:
  General error: 11 database disk image is malformed` and repeated file-lock errors on the shared
  SQLite tenancy fixtures — at that moment `git status` showed `modules/demo/composer.json`,
  `routes/api.php`, `DemoServiceProvider.php`, `PingController.php` and `PingRouteTest.php` modified,
  none by me. **This confirms a second agent became active in this same worktree mid-session** (the
  exact contamination risk the task brief warned about), and that run's numbers are void — I did not
  touch those files and am not reporting that run's numbers. The first, clean full run above (before
  the interference appeared) stands as the measured result. I recommend the lead have `composer check`
  re-run once more, either once the worktree is exclusively held or from an isolated clone, purely to
  get an official number that includes my 2 added tests (expected: 2616 passed, same 12 FP-316
  failures, same 22 skipped) — this is not a blocker for my own sign-off, given the isolated evidence
  above (lint/stan/deptrac clean, 96/96 package tests green with the addition).

## Defects

### BUG-1 (minor — test-coverage gap, found and fixed in this QA round)
- **Steps:** Mutate `ApproverResolver::resolve()` to memoise its result keyed only by `approver_ref`
  (simulating "resolved once, not per step-activation"). Run the full `packages/workflow` suite.
- **Expected:** The plan's own step-8 bullet ("AC-9, membership timing: instance 1 is created; E5 is
  then assigned to role one; instance 2's step-1 tasks include E5 and instance 1's are unchanged")
  and the test-strategy table (`AC-9 | step 8 (instance-to-instance membership change)`) both say this
  is tested.
- **Actual:** All 94 tests passed. No test exercises a membership change between two `evaluate()`
  calls.
- **Suspected location:** missing test in `packages/workflow/tests/Features/EvaluateWorkflow/EvaluateWorkflowTest.php`.
- **Disposition:** production code is correct (confirmed by reading `ApproverResolver` — it resolves
  live, with no cache, every time `StepActivator::resolve()` is called). I added the missing test
  myself (test-file-only edit, within QA's permitted scope) and re-confirmed the mutation now goes
  red. No dev action needed.

### BUG-2 (minor — test-coverage gap, found and fixed in this QA round)
- **Steps:** Delete the `branch_ids` block entirely from `DefinitionSelector::matches()`. Run the
  full `packages/workflow` suite.
- **Expected:** Ruling 4 ("another branch gives `NotRequired`; no branch gives
  `conditionNotEvaluable`") and the task brief's own instruction ("verify branch-scoping: a
  branch-scoped rule with no branch on the request fails closed") both require a test here.
- **Actual:** All 94 tests passed. No test anywhere in `packages/workflow/tests` references
  `branch_ids` behaviourally (only in schema/fixture/contract shape tests).
- **Suspected location:** missing test in `EvaluateWorkflowTest.php`.
- **Disposition:** production code is correct (confirmed by reading `DefinitionSelector::matches()`
  and by the new test passing against the unmutated code). I added the missing test myself, which
  independently exercises all three branch_ids cases (another branch, no branch, the named branch),
  and re-confirmed the mutation now goes red. No dev action needed.

## Regression risks / notes

- **Gate headroom (FP-328) is at ratio 1.47, below the 2x floor** (dev's own report said 1.46; the
  small difference is normal run-to-run variance). This is a known, separately-tracked operational
  risk (per the task brief, not mine to fix this round), but it means the margin for the *next* task
  that adds cases is thinner still.
- **Worktree sharing:** confirmed mid-session that a second agent/process became active in this
  worktree (modified `modules/demo/*` files I never touched, and corrupted the shared SQLite tenancy
  fixture well enough to produce `disk image malformed`). No prior full-gate run of mine was affected
  (the clean run reported above pre-dates the interference), but this is worth flagging to the lead:
  this worktree was not exclusively held for the whole QA round, contrary to the task brief's
  caution. I did not revert or touch any of the other agent's files.
- **Deviations noted and accepted, no re-litigation needed:** `DefinitionRepository.php` (undeclared
  but justified re-validation-on-read, T-019 Ruling 2 precedent); console `$this->components->info()`
  per row instead of `twoColumnDetail()` (confirmed working via `ListWorkflowDefinitionsTest` and
  `ListPendingWorkflowTasksTest` command-test assertions, which read real command output).
- **AC-9's three refused approver types** (`manager_of_requester`, `department_head`, `branch_manager`)
  are a documented, `ba`-confirmed scope boundary (D-13), not a defect — verified refused at
  definition-write time and fail-closed at step-activation time, both directly.
