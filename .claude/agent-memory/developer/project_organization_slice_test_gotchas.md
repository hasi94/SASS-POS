---
name: organization-slice-test-gotchas
description: Four recurring traps writing organization/settings slices and their tests (T-014 steps 13-14)
metadata:
  type: project
---

- `VerticalSliceLayoutTest` wants every `Features/<X>` slice to own a `tests/Features/<X>/*Test.php`; a sibling slice's test file does not count. Give each slice its own folder when it is created, not after the gate.
- A Laravel Route caches its controller, and so its handler: rebinding a contract mid-test (`app()->instance(...)`) does not reach a route already hit. Bind one switchable double before the first request and flip a flag on it.
- `AuditWriter` sorts `changed_fields` by name; do not guess the order in a whole-row audit expectation, print it first.
- Stan (Pest typing): `->not->toContain()->not->toContain()` chains and `->and()` over a `mixed` value fail. Use one `expect()` per needle and cast to `(string)` first.
- Bash `sed` with `$` in the pattern is unreliable in this shell; edit with the Edit tool or a PHP script. A file written under `scratchpad/stageNN/..` by relative path can land in the staged tree and be installed into the repo root: check `git status` after an installer runs.
