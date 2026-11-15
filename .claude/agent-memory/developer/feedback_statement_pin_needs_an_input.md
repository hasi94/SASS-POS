---
name: statement-pin-needs-an-input
description: A query-log pin on a re-checking statement is evidence only when a behavioural input exists at which the re-check binds; find the input, do not settle for the pin.
metadata:
  type: feedback
---

When a statement re-checks terms its own feeding read already filtered on, pin
the executed SQL from the query log **and** find the input at which the
re-check actually binds. The pin alone proves the terms are written; the input
proves they are load-bearing.

**Why:** on T-008 step 11 the completing `UPDATE` of a migration job re-checks
`status` and `claimed_by`, and the read selects the job *as this worker*. The
pin was easy; the binding input took three attempts. Advancing the clock past
the lease did nothing, because the completion checks the holder and not the
expiry. What binds is *another worker taking the job over* - and for the
runner's loop guard, that worker also having a lease in the past. A reviewer
who deletes the "odd" SQL-shaped assertion as duplication removes the only
evidence; a reviewer who sees a behavioural case beside it does not.

**How to apply:** for each pinned statement, write down what has to happen
between the read and the write for the terms to matter. If nothing can, the
terms are decoration and the statement should lose them - that is a finding
about the design, not about the test. See [[a-prefilter-masks-the-statement]]
for the mask this answers, and [[mutation-must-move-the-observable]] for the
measurement discipline.
