---
name: feedback-defer-in-a-comment-or-file-a-row
description: When a known gap is worth a backlog row versus a measured deferral documented beside the mechanism, and that pushing back on an instruction with a measurement is wanted rather than tolerated
metadata:
  type: feedback
---

**A known gap gets a backlog row when an ordinary action reaches it; it gets a measured
deferral written beside the mechanism when triggering it takes something nobody does.**

**Why:** T-005 shipped two gaps in the same guard, and the lead ruled them differently.
The probe-planter walk reads `*Test.php` only, so somebody adding a planter to a
`Helpers.php` is missed by an ordinary edit - that earned FP-175. The same rule omits
Pint from its scope list, but reaching that gap needs a probe planted outside all three
analysis registries, `@lint` reddens immediately naming the file, and the one probe
outside those registries was measured to sit under `storage/`, outside Pint's reach
when Pint is given a directory. Ruling: "a row for something nobody can trigger is
backlog noise". The deferral stays in the docblock, and **recording *why* the fourth
tool is deliberately absent is what makes it defensible rather than an oversight**.

**How to apply:** before raising a row for a residual, name the action that reaches it
and who would plausibly perform it. If the answer is "nobody without planting something
by hand", document it where the mechanism lives, with the measurement and the reason for
the omission, and do not widen the rule - a widened guard that is green on today's tree
is the thing to defer ([[feedback-guard-must-not-disturb-what-it-measures]], item 6).
Offer the choice rather than deciding alone when the call is close; the lead would rather
rule than find a row they have to triage.

**The companion rule, confirmed explicitly rather than merely tolerated: push back on an
instruction with a measurement instead of complying silently.** On the same task the
lead's own instruction ("reuse `filesSpawningChildProcesses()`") would have made the
guard's storage exclusion vacuous, because the file declaring that planter spawns
nothing. Measuring it, deviating by extracting the walk, and declaring the deviation with
the reason was the wanted behaviour - "pushing back with a measurement rather than
silently complying is what I want from this role". The same held for reporting another
role's broken gate instead of reaching across to fix it
([[feedback-clean-up-probe-files-immediately]]). Both only work because the deviation was
*declared*: silent deviation is the failure, not deviation.
