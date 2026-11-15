---
name: t019-round2-review-fix-verification
description: T-019 review round 1 fix re-verification — a one-of-five-branches test-coverage gap behind a "fixed all five" claim, and shared-worktree config:cache contamination from a concurrent composer check
metadata:
  type: project
---

**When a fix touches N parallel branches (e.g. a 5-mode dispatch loop) and the
dev's own red-verification covers only one, mutation-test every branch
independently before trusting "all N now do X."** T-019's R-1 fix added
`HookInvoker::refuse()` calls to all five of `Gateway.php`'s skip branches
(`runBefore`, `runFilters`, `runActionOrOverride`, `runAdds`, `runAfters`) —
the production code was genuinely correct in all five, confirmed by direct
read. But dev's new regression test (`CircuitBreakerTest`'s e2e trip case)
only exercises a `before`-mode fixture. Removing the `refuse()` call from
`runFilters`, `runActionOrOverride`, or `runAfters` left the full
`packages/plugin-sdk` suite green; only removing it from `runBefore` went
red. Filed as BUG-3 (minor, non-blocking — same disposition as T-019 Run 1's
BUG-1/BUG-2: a proof gap, not a behaviour gap, since the shipped code for all
five branches is identical in shape and independently confirmed correct).
Lesson: "fixed in all N places" claims from a dev need N independent
mutation probes, not one — a positive control on branch 1 does not imply
coverage of branches 2-N just because they look structurally identical.

**Shared-worktree gate contamination recurs via `config:cache`, not just test
DB state.** Twice in this round, `reviewer`'s concurrent `composer check` run
in the same worktree left a stale config cache behind after it finished,
breaking my own scoped `pest` runs with `RuntimeException: Cached
configuration disagrees with the process environment: config:cache froze
MODULES="demo,qacachefixture", but the environment now sets MODULES="demo"`.
This is a *different* contamination vector than T-018's SQLite-lock or
T-010/T-016's "looks like a real bug but is gate-vs-ad-hoc-pest contention" —
here the artifact survives *after* the other process exits, so simply waiting
for the PID to end is necessary but not sufficient: run `php artisan
config:clear` before resuming any test run of your own, even once the other
process's PID is confirmed gone. Detected two independent live `composer
check` PIDs across this one round (69476, then 60164) via
`Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Select
ProcessId,CommandLine` — this is the reliable way to confirm a conflicting
gate run before starting your own, in an environment with no `ListAgents`
tool available to a QA engineer.
