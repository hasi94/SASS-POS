---
name: an-instruction-must-be-runnable
description: When code prints "now run X", assert X in the test - the command's own argument guard refused the invocation two of its messages already told operators to use
metadata:
  type: feedback
---

A message that tells an operator what to run next is a claim about the CLI,
and it is only true if some test actually invokes that string.

**Why:** T-008 step 13f printed `tenant:migrate --run=<id>` as the way to
finish a run whose jobs were still outstanding. The command's first guard is
*"name --tenant, or ask for every eligible tenant with --all"*, and a bare
`--run` satisfied neither - so the invocation was refused by the command that
printed it. The same sentence had already been shipped by `dispatch()`'s
closing line ("Nothing has been migrated yet. Run tenant:migrate --run=... once
the queue has drained"), green in its own test, because that test asserted the
*output text* and never called the command back. One console assertion, two
false instructions, and it took writing a case that actually ran the printed
invocation to see it.

**How to apply:**

- when a case asserts an instruction appears in the output, make the next
  assertion in that case *execute* the instruction and expect it to be accepted
  (not necessarily to succeed - just not to be refused by argument parsing);
- an argument guard that exists to catch a forgotten flag should exempt every
  option that names the work as narrowly: `--run` names one run, so it is as
  specific as `--tenant` and plans nothing;
- such a fix is a production change outside the step's two bullets - declare it
  in the commit and in the test file rather than letting it ride, per
  [[defer-in-a-comment-or-file-a-row]].
