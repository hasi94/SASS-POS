---
name: t020-workflow-engine-closeout
description: T-020 workflow-engine step-16 close-out — two plan-mandated AC-9/branch_ids tests silently missing despite a passing suite, mid-run worktree contamination from a second agent, and a reliable PowerShell PID-wait technique
metadata:
  type: project
---

**A plan's own test-strategy table is a claim, not a guarantee — mutation-test every row, including
the ones that look obviously covered.** T-020's plan (step 8 bullets, "AC-9, membership timing") explicitly
mandated two test cases: (1) resolving approvers at step-activation time, not definition time — a
membership change between two `evaluate()` calls must be seen by the second instance and not the
first; (2) a `branch_ids`-scoped definition must fail closed (`conditionNotEvaluable`) when the
context carries no branch, and match only the named branch otherwise. Dev's shipped test suite
(94 passing tests, lint/stan/deptrac all clean) had **zero** test exercising either behaviour — not
a weak test, an absent one. Proof: I mutated `ApproverResolver::resolve()` to memoize per-approver_ref
(simulating "resolved once, not per activation") and separately deleted the entire `branch_ids` block
from `DefinitionSelector::matches()`; both mutations left all 94 workflow tests green. The production
code was correct in both cases (confirmed by direct read and by the behaviour once tested) — this was
a pure test-coverage gap, invisible from a green gate, caught only by doing the plan's own step-16
mutation list literally rather than trusting "the AC is in the mapping table." See
[[t010-mutation-survivors-and-harness]] and [[t019-round2-review-fix-verification]] for the same
family of finding in prior tasks.

**QA may write the missing tests itself (test files only), not just report the gap.** Per the general
QA process ("map every AC to a test; write the missing ones"), I added both cases directly to
`EvaluateWorkflowTest.php` (not a new `Qa*` file — those are reserved for the plan's own
qa-accountable sweep files), re-ran the same two mutations against the new tests to confirm they now
go red for the right reason, reverted the mutations, and confirmed `git diff --stat` touched only the
one test file. Ran Pint before finishing — a raw `\Flexipos\Access\Tests\Support\AccessSeed::employee()`
call fails `ordered_imports`/`fully_qualified_strict_types`; add proper `use` imports instead of an
inline FQCN, even in a quick addition.

**Worktree contamination recurs mid-task, not just between QA rounds.** A second full gate run late
in this session returned 248 failed / "SQLSTATE[HY000]: General error: 11 database disk image is
malformed" / repeated `unlink(): Resource temporarily unavailable` on the shared SQLite tenancy-fixture
files. `git status` at that moment showed `modules/demo/composer.json`, `routes/api.php`,
`DemoServiceProvider.php`, `PingController.php` and `PingRouteTest.php` modified — none of them mine.
Another agent was concurrently active in this exact worktree, racing the same SQLite fixture files my
run needed. **Do not report numbers from a run where `git status` shows files you did not touch** —
treat it as void, not as a regression, and fall back to the last clean run (here, the first full gate:
2614 passed / 12 failed / 22 skipped / 18149 assertions / 613.11s / ratio 1.47, matching dev's own
claim almost exactly). Do not revert the other agent's in-progress changes to "clean up" — they are not
yours to touch. See [[never-spawn-two-agents-into-one-directory]] for the T-018/T-019 precedent; this
is the same failure mode arriving *mid-QA-session* rather than at session start, so a `git status`
sanity check belongs right before trusting *any* gate run's numbers, not just at session start.

**A PowerShell `Get-Process`/`Get-CimInstance` wait-loop driven by an inline `-Command "...$_..."`
string through Git Bash gives false "process ended" positives.** Twice in this round, `until ! powershell
-NoProfile -Command "... Where-Object { \$_.CommandLine -like ... } ..."; do sleep N; done` reported the
gate process had ended while `Get-Process -Id <pid>` (checked immediately after, via a clean `.ps1` file)
proved it was still running and consuming CPU. Root cause not fully isolated, but reliably avoided: write
the wait loop as a small `.ps1` file (`param([int]$TargetPid)` — never `$Pid`, which is a reserved
automatic variable and throws `VariableNotWritable` — looping `Get-Process -Id $TargetPid` with
`Start-Sleep`), invoke it with `-File`, and treat only that result as authoritative. Also: a Bash
foreground command that runs past ~180s auto-backgrounds itself with its own task id even without
`run_in_background: true` — that auto-backgrounded id is the one whose completion notification is
real; a separately-launched PID-polling loop is not a substitute proof of completion.

**FP-316's signature held exactly, fourth-plus confirmed sighting.** 12 failures, all four files
(`QaModuleRegistryContractTest` ×6, `QaModuleNameValidationTest` ×3, `QaHealthProbeResilienceTest` ×2,
`ModulesEnvParsingTest` ×1), nothing workflow-related. See [[t014-branches-terminals-closeout]] and
[[t017-audit-framework-closeout]].
