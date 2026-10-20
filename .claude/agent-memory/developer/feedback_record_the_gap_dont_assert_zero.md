---
name: record-the-gap-dont-assert-zero
description: When a widened instrument would redden pre-existing conditions another task owns, ship it paired with a named, self-liquidating list instead of weakening it or asserting zero
metadata:
  type: feedback
---

A check that is correct but reddens conditions the current task did not cause ships
**paired with an explicit named list** of the known-open cases, each entry carrying the
row reference that will close it — never softened, never left asserting `[]`.

**Why:** T-009 W-2. The registry guard's expectations were `toBe([])` while three
registered Deptrac `tests` paths collected nothing (every file a Pest file in the global
namespace). "Zero violations" from them was true and meaningless. Asserting the extended
check bare would have failed T-009 for FP-303's backlog; deleting the check would have
rebuilt the false guard with a longer body.

**How to apply:** the expectation becomes `toBe(recordedGaps(...))` derived from the list,
so anything *not* on the list is still reported — prove that with a probe outside the list
(the generated fixture module here). Then make the list self-liquidating: a separate case
asserts each entry's *underlying condition still holds*, read from disk, not from the
registry. Namespacing one tree reddened it with "X declares a namespace and is no longer
global", which forces the entry's deletion and tightens the main expectation by itself.
Related: [[feedback_defer_in_a_comment_or_file_a_row]],
[[feedback_delete_the_capability_not_the_bound]], [[feedback_exempt_by_property_not_by_name]].

Mechanics worth keeping: **PHPStan matches on files, Deptrac matches on namespaces.** A
directory in `deptrac.yaml: paths` whose files declare no namespace contributes no
collectable token, so no layer can own it and no rule can name it as depender. The only
collected test tree here is the host's own, via `autoload-dev` `Tests\` plus a dedicated
`HostTests` layer. Keep the path registered anyway when a namespacing task is queued —
removing it makes that task responsible for restoring it.
