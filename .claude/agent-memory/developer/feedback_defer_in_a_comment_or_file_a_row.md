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

**When a step's own "gate: green" requirement collides with another role's file ownership,
the plan's step-2 fallback is the precedent: make the edit, mark it in the file as yours and
declared, and list it line by line in the commit message under AC-41.** On T-007 step 4,
deleting `Tenancy::TENANT_MARKER` turned a `Qa*` test into an undefined-constant Error and the
new content binding made two more `Qa*` assertions assert the opposite of the new invariant -
three of the five reds on the first full run, measured rather than predicted. The plan assigned
those lines to `qa` in a *later* step while requiring this step to be green; there is no
messaging tool in a `dev` session, so the two cannot both hold. Prefer the shapes the plan has
already pre-justified (its R6-style "this edit is required, not a surprise" list) so the other
role is not surprised, and say plainly in the report that the later step may rewrite all of them.

**A gate requirement can also force a mechanism change, and that is the kind of deviation to
lead with rather than bury.** The same step's whole-stanza digest refused a *legitimate*
`DB::build()`, because the framework makes the caller supply `name`. The fix (exclude one key)
weakens the mechanism by exactly one accepted residual the plan already listed - so the right
move was to make that residual an executable test and put the deviation at the top of the
commit message, not in a footnote.
