---
name: absence-is-a-third-state
description: A config default that folds "unset" into a legal value hides a safety hole; separate the states in the config file, refuse at the consulting site, never at boot.
metadata:
  type: feedback
---

When a configuration key guards something, **absence and the permissive value must not be the same
fact**. `(int) env('X', 0)` made "nobody chose a floor" identical to "no floor wanted", and the serving
version gate then had no way to refuse the first while serving the second.

**Why:** the hole is reached by *omitting* a line in a deployment, which is the failure mode that
actually happens; a wrong value gets reviewed, a missing one does not. Ruled as R-14/§13.1 on T-008.

**How to apply:**
- Separate the states **in the config file**, not in the consumer: `is_numeric($v) ? (int) $v : null`,
  with a statement above the `return` and the three states named in the comment. An empty string counts
  as absent - `X=` in an .env is what a half-finished deployment actually has.
- Refuse **where the value is consulted**, never at boot. A boot-time refusal also stops the command
  that fixes the condition (`tenant:migrate`) and every queue worker: that is fail-*stuck*. Exempt
  whatever purpose is the remedy, and assert the exemption.
- Keep the permissive value as a *recorded* opt-out and assert it stays served, or the fix silently
  becomes "this key is mandatory", which is a different decision.
- Render the misconfiguration distinguishably **at the wire**: same 503, but no `Retry-After`, because
  that header promises waiting helps. Control: the sibling exception rendered in the same case still
  carries it.
- The suite must then name the value explicitly in `phpunit.xml` (see [[the-suite-absorbs-a-changed-default]]),
  and the absent state is only reachable in a test by unsetting `$_SERVER`, `$_ENV` *and* `putenv` and
  re-requiring the config file - phpunit's `<env>` writes only two of the three.
