---
name: t020-round2-review-fix-and-worktree-deletion
description: T-020 review round 1 fix re-verification — all five findings (R-1, R-2, W-4, W-6, S-4) confirmed correct by diff + real test runs + one live mutation; worktree contamination escalated from modified files to an entire module directory being deleted mid-session by a third process.
metadata:
  type: project
---

**Worktree sharing can escalate past "uncommitted files" to "a whole directory deleted out from
under you," mid-QA-round, with no process visible at the time.** Building on
[[t020-branches-terminals-closeout]] and [[never-spawn-two-agents-into-one-directory]]: this round,
`git status` first showed the same 6 known-not-mine files (5 `modules/demo/*`, one contracts
docblock). First `composer test` attempt went void the familiar way (two concurrent `pest`
processes observed via `Get-CimInstance Win32_Process`, SQLite `TenantConnectionFailed` +
`unlink(): Resource temporarily unavailable`, 274 failed). Confirmed no pest/gate process running,
retried — second attempt was **worse**: `git status` now showed the entire `modules/demo/` tree as
`deleted` (11 files, not modified), confirmed by `ls modules/demo` failing directly on disk, and the
run died with `UnknownModuleException: Unknown module "demo"` cascading into 2082 failures. No
process was running at either check — this was a *persistent* state left by whatever other session
is active, not a transient race. **Lesson: after a void run, re-check `git status` AND the
suspect path's actual on-disk existence before retrying — a second "files modified" status can
mean "files gone," and a bare process-list check at retry time proves nothing about what happened
seconds earlier or what state was left behind.** Did not touch, stage, or revert any of it; reported
to the lead as a coordination failure, not a T-020 defect, and relied on targeted per-file test runs
(which don't touch `modules/demo` or go through the full bootstrap) to get real signal instead.

**A review-round fix commit is efficiently verified by reading its diff against the review's own
wording, then running (not just reading) every test file it touched, plus one live mutation on the
specific assertion the review said was previously missing.** For T-020's `d1e9a17` (R-1, R-2, W-4,
W-6, S-4 + a docblock fix), `git show d1e9a17` gave an exact diff per finding; each matched its
review item's wording precisely (e.g. R-1's `$this->table()` swap literally adds the `name`/
`instance_id` columns the review named). Rather than re-deriving new negative controls from
scratch, I mutation-tested the *one* new assertion the review's R-2 item specifically said was
missing before (the "still open" vote-event write) by temporarily deleting that one line from
`DecideWorkflowTaskHandler.php`, confirming the new test catches it for the right reason
(`Expecting null not to be null` at the `workflow_events` lookup), then reverting with a clean
`git diff` check. This is cheaper than re-running the plan's full step-16 mutation list and is
sufficient when the task is specifically "did review's fix land correctly," not "re-audit
everything."

**The gate's own `process-timeout` being raised to 7800s (FP-328, landed across 4 lead commits)
is why `composer test` alone now completes in ~5-11 minutes instead of failing at 900s** — this
unblocks fast targeted re-verification loops going forward on this project; it's infrastructure,
not any one task's defect, and should not be re-litigated by QA on future tasks unless it fails
again.
