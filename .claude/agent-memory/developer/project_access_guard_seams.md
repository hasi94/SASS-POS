---
name: access-guard-seams
description: how T-010's three access safeguards are wired, the null-actor rule they introduced, and the transaction probe that reads the property
metadata:
  type: project
---

`packages/access` carries three safeguards as `Support/` classes, each the
single home for one rule: `SelfChange` (AC-34, called by the six section E
handlers), `Escalation` (AC-36, called by AssignRole, SetEmployeePermission on
an allow, and SetRolePermissions for keys being *added*), and `LastOwner`
(AC-37, called by RemoveRoleAssignment when the role is a platform role,
ChangeEmployeeStatus in the leaving direction, and DeleteEmployee always).

**An unknown actor is refused `no_escalation`.** AC-36 is written about an
actor and says nothing about an operation with none; folding "no actor" into
"no guard" is the shape this project forbids. The consequence is that any
fixture calling a granting handler needs a privileged actor -
`AccessSeed::actorHolding($code, $keys, $branchId, $system)` exists for that -
and three pre-existing test files were edited to seed one.

**Why `holdsEverywhere()` is on the concrete resolver and not the contract.**
`decide($id, $key, null)` answers `branch_required` for a branch-scoped key, by
design, so it cannot express AC-36's all-branches question. The method reuses
the two contribution readers and `covers()`; nothing outside the package may
ask it.

**Why:** these are the seams every later slice and every HTTP controller will
meet, and two of them (the null-actor rule, the concrete-resolver dependency)
are judgement calls declared in commit messages rather than in a decision file.

**How to apply:** a new operation that grants, removes or changes access gets
the matching guard call and a case in the matching test file - each test has a
static sweep that reads which handlers call the guard and reddens if the two
lists differ. Same mechanism for `AuditWriter`: fourteen handlers call
`$this->audit->write(...)` and `AuditWriterTest`'s sweep derives its domain
from that string. See [[project_tenant_fixture_on_the_standin]].

**AC-71's probe shape, worth reusing:** `$connection->listen()` recording
`transactionLevel()` per statement, asserted > 0 for each write - plus a
liveness control running the identical listener on an unwrapped statement and
asserting level 0. The forced mid-way failure is the listener throwing when it
sees the last of the three inserts.
