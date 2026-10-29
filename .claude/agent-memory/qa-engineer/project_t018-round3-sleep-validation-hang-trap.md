---
name: t018-round3-sleep-validation-hang-trap
description: T-018 review round 2 fix verification (R-3/W-8/S-5) — reverting a loop-guard validation to mutation-test it can hang a real process forever, and the kill-permission wall this session hit
metadata:
  type: project
---

T-018's review round 2 found a new Critical (R-3: `claim()` looked up `deliveryCounts()`
by XCLAIM's *requested* ids instead of its *actually-claimed* ids, throwing
`EventBusUnreachable` for a legitimate zero-claim) plus two Warnings (W-8: a DB fault on
the outbox "mark published" update was still reachable because W-3's catch-boundary
narrowing had a gap; S-5: `--sleep`/`poll_seconds` reached `Sleep::for()` unvalidated).
`dev` fixed all three in one commit (`bd83ed5`) and red-verified R-3 but not W-8/S-5
individually — see [[project_t018-review-round1-fix-verification]] and
[[project_t018-eventbus-mutation-and-sqlite-lock-trap]] for the sibling close-outs this
one follows.

**Reverting a "throw before the loop starts" validation (S-5) to mutation-test it does
not reproduce a clean assertion failure — it reproduces a real infinite loop, because
the test's own Sleep-faking removes the only thing that would have bounded it.** Both
`ConsumeEventsCommand` and `RelayOutboxCommand`'s pass-loop only exits via `--once` or
`--max-passes > 0`; the default is `0` ("unlimited"). The S-5 tests deliberately omit
both flags (to prove the loop is never entered at all once the fix throws first). With
the fix reverted, `Sleep::for()` is faked (`Sleep::fake()` in the test harness) and
returns instantly, so the loop spins at full CPU with zero wall-clock delay between
passes — not a slow hang, a true tight loop. Pest moved the command to a background
process after its 120s default timeout, and it accumulated 750+ CPU-seconds over 35+
minutes before this report was filed, still running. **This is a stronger, more
informative red than the plan likely anticipated** (a hang proves the fix prevents a
production hazard a consumer/relay worker could hit from a bad config value, not just a
wrong return value) but it is operationally expensive and, in this environment,
impossible to clean up directly.

**Both `Stop-Process -Force` and `taskkill /F` on the hung PID were denied by the
session's auto-mode permission classifier** ("Interfere With Workloads" / "judged
dangerous"), with no override available to a QA-role agent. The file on disk had
already been restored via `git checkout --` before the hang was noticed (process memory
retains the old code regardless of what's on disk), so the repository was never at risk
— only a leftover OS process survives. The only available remedy was naming the PID and
asking the lead/user to kill it manually via Task Manager; do the same in any future
task that reverts a loop-guard validation while `Sleep`/similar timing is faked in the
test harness. Consider, before next doing this: run the single affected test file (not
the whole directory) with a short explicit `--max-passes=1` style control first if the
test suite's own assertion doesn't already bound it, or accept the hang is informative
and budget for the manual-kill step up front rather than being surprised by it mid-task.

**Why:** this was the first time in this task's three QA rounds that a reverted fix
produced a hang instead of a clean red/green pair, and the first time a kill attempt was
denied outright rather than merely slow — worth remembering so a future round budgets
time for both the hang and the inability to self-clean it up.

**How to apply:** before reverting any "validate before entering a bounded-by-flag
loop" fix, check whether the test exercising it supplies `--once`/`--max-passes`-style
bounds itself; if it deliberately omits them (to prove the loop is never entered), expect
a hang on revert, not a timely assertion failure, and plan to report the PID for manual
cleanup rather than relying on killing it yourself. Related:
[[project_t018-review-round1-fix-verification]],
[[project_t018-eventbus-mutation-and-sqlite-lock-trap]],
[[never-spawn-two-agents-into-one-directory]] (same family of "a stray process in the
worktree distorts a measurement" lesson, different cause here).
