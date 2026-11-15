---
name: delete-the-capability-not-the-bound
description: When a destructive capability is redundant, delete it instead of bounding it with a ceiling - and make the deletion a static test with a positive control.
metadata:
  type: feedback
---

A ceiling, a count limit or a second filter does **not** make a destructive
capability acceptable if something else already does its job. Delete the
capability.

**Why:** on 2026-10-14 the architect and lead ruled on T-008's
`tools/clean-engine.php`, which swept `information_schema` and dropped every
reserved-prefix database behind `CLEAN_ENGINE_MAX_SWEEP = 32` plus a second
prefix literal. Both bounds *would* have stopped the incident of
[[red-first-stub-must-be-inert]]. It was still removed, because the ceiling is a
guess — *a filter that wrongly returns twenty foreign names passes it* — and
`EngineDatabase::recreate()` already drops the one name it is about to create,
so the sweep bought nothing. My safety work had been "bound it well"; the ruling
is "it should not exist".

**How to apply:**
- Before hardening a destructive path, ask what *else* already guarantees the
  same property. If something does, the path is dead code with a blast radius.
- A constraint stated in prose decays. Turn it into a static scan over every
  owned source file (comments stripped via `token_get_all`), with a **positive
  control fixture** and a control that the owning file still contains the real
  shape — otherwise the scan passes by matching nothing.
- Scan the scanner too: a test file that exempts itself can hide what it looks
  for. Build its forbidden fixtures by string concatenation
  (`'drop'.' database …'`) so the file's own source never carries the shape.
- Symfony Finder's `exclude()` only prunes at the root it was given, so it will
  walk `modules/host/vendor`. Write the recursive walk with an explicit prune
  list when the scan is repository-wide.
- Asymmetric redundant rules are deliberate. Do not "improve" the second rule
  into a copy of the first: two rules that agree exactly are one rule called
  twice.
