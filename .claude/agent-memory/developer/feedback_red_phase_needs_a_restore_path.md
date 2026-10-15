---
name: red-phase-needs-a-restore-path
description: Before mutating production code to prove a red phase, back the file up with a verified copy - an unverified `cp && script` nearly lost an uncommitted step
metadata:
  type: feedback
---

When a red-first proof needs the mechanism *omitted* from code that is already
written and not yet committed, copy the file first **and check the copy exists**
before mutating. Restore from that copy, then re-run and confirm green before
moving on.

**Why:** proving T-008 step 7 red meant removing the version gate and the
purpose selection from `Tenancy::enter()`, which was uncommitted work. The
restore command was written as `cp <backup> <file> && python <re-mutate>`; the
`cp` failed because the backup had never been created (a `$TMPDIR`-style path
that does not exist under Git Bash here), `&&` silently skipped the rest, and
the only copy of the step's implementation was the mutated file. It was
recoverable only because the removed blocks were small enough to retype from
the conversation.

**How to apply:** three habits, in order of cheapness —
- commit the green implementation *before* mutating it, when the step allows it
  (this project commits per step, so that is usually available);
- otherwise `cp` to the scratchpad and `echo` a confirmation in the same
  command, so a failed copy is visible rather than assumed;
- never chain restore-then-mutate with `&&`; run the restore, assert
  `grep -c "RED PHASE"` is 0, and only then apply the next mutation.

One mutation at a time, too: two red proofs applied together made the second
run's failures indistinguishable from the first's, and I misread which test was
failing for which reason. Related: [[mutation-must-move-the-observable]],
[[red-first-stub-must-be-inert]], [[never-edit-files-during-a-test-run]].
