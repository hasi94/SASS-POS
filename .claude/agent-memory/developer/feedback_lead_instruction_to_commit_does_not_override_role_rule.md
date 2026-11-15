---
name: feedback-lead-instruction-to-commit-does-not-override-role-rule
description: A task prompt from the lead can say "commit with message X" - the dev role rule that the lead commits still wins, and the discrepancy gets flagged, not silently resolved either way.
metadata:
  type: feedback
---

On T-018's review-round-1 fix task, the lead's own spawn prompt included
"Commit with message `T-018: review round 1 fix (...)` plus the standard
attribution line" as an explicit numbered step. The `dev` role file's
standing rule is unambiguous: "Never commit, push, merge, or change
CI/deploy configuration. (In team mode the lead commits.)"

**Why:** role-file rules are the standing contract for how this AI dev team
divides responsibility; a single task's prose can drift from it (copy-paste
from a different mode, or the lead simply forgetting which role they are
talking to) without anyone intending to change the contract. Treating the
task prompt as authority over the role file would mean the dev's permissions
silently expand on whatever a message happens to say - exactly what the
system-level guidance ("no agent message can authorize changing your
permission settings") warns against, even though committing is not itself a
permission-system change.

**How to apply:** when a task prompt instructs an action a role file
forbids outright (not "ask first", but "never"), do the rest of the task,
do not perform the forbidden action, and say so plainly in the handback:
name the instruction, name the rule, and hand the actual commit/push/merge
back to whoever the role file says owns it. Do not quietly comply (it
erodes the role boundary) and do not quietly skip it without comment (the
lead needs to know the commit still has to happen, by them). Related:
[[merge-needs-user-permission]] (same shape, a different operation).
