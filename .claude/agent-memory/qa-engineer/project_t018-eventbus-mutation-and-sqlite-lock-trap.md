---
name: t018-eventbus-mutation-and-sqlite-lock-trap
description: T-018 close-out — all 11 plan-specified mutations held (unlike T-014's vacuous one), the SQLite single-writer-lock trap when mutation-testing "opens its own transaction", and a clean first-pass PASS
metadata:
  type: project
---

T-018 (event bus and outbox, FP-212) was a rare clean first-pass PASS: all 37 ACs
covered, all 11 of step 12's mandatory mutations behaved exactly as the plan predicted
(contrast [[project_t014-branches-terminals-closeout]], where one of nine mutations was
vacuous for data-shape reasons specific to that task), zero defects filed, zero `dev`
round-trips needed.

**Mutating "the writer opens its own transaction on a fresh connection" against a SQLite
fixture produces a lock error, not a clean "row survives rollback" demonstration.**
`AccessTenantFixture`'s tenant databases are SQLite files with the default rollback
journal (no WAL). Opening a genuinely separate `Connection`/PDO instance pointed at the
same file, then inserting inside its own `DB::transaction()` while the caller's own
transaction on the first connection is still open, does not race or silently succeed —
SQLite's single-writer file lock makes the second connection's write block until its
busy-timeout, then throw `SQLSTATE[HY000]: database is locked`
(`Illuminate\Database\QueryException`, which extends `PDOException` which extends the
built-in `RuntimeException`). Two practical consequences: (1) a test that only catches
`RuntimeException` around the forced-rollback scenario will swallow this lock exception
and still report PASS — for the wrong proximate reason, not because the transaction
guarantee held; (2) the *other*, committed-twin control case (no catch block) fails
loudly and slowly (took ~67s per affected test in this run). The mutation still
demonstrably "goes red" (the file as a whole fails), which satisfies the letter of "make
it red", but report the real mechanism rather than a tidied-up one: on a real MySQL/Redis
production backend there is no such file lock, and the same mutation would instead let
the outbox row survive a caller's rollback *silently* — the lock error here is SQLite
being accidentally stricter, not the production defect manifesting cleanly. If a future
task needs to mutation-test this same "own transaction" shape again, expect the same
slow, locked-not-silent result on this fixture and budget for it (it added about 2
minutes to that one mutation step).

**Independent AC-37 deptrac re-verification technique, confirmed working a second time**
(first used in prior tasks, see [[project_t017-audit-framework-closeout]] for the sibling
text-scan version): plant a real file in the real scanned module directory
(`modules/demo/src`, not a scratch temp dir) importing the internal class under test,
run `vendor/bin/deptrac analyse --no-cache`, confirm the named violation; swap the import
to the published contract, confirm 0 violations and the allowed-edge count rises by
exactly 1; delete the probe; confirm `git status --porcelain` on the whole repo is empty
and deptrac is back to the exact pre-probe violation/allowed counts. Do this for every
`Module_X loses Package_Y` narrowing a plan claims as an AC instrument — it is cheap (a
few seconds per deptrac run) and catches a stale or no-op boundary edit that a text-only
read of the YAML diff would miss.

**When a plan's own step text says a boundary-check re-proof is "report only, not a
committed test" and gives a reason (joining the toolchain group for one assertion), and a
generic instrument already exists proving the mechanism fires at all
(`DeptracBoundaryEnforcementTest` in this project) — agree with it rather than manufacture
a new committed test.** Flag the "maybe promote later" thought as a non-blocking
Suggestion in the report, not a defect. This mirrors
[[project_t009-round2-ingress-and-pin-blind-spots]]'s point that QA files must still pass
Stan/Pint, generalized: QA's job is to confirm an instrument is *adequate*, not to always
add a parallel committed one when the plan already reasoned about the trade-off.

**Why:** this project's QA cycles usually find at least one real defect or one
non-discriminating mutation; a round with neither is worth recording precisely so a
future qa does not read "no defects" as "the checklist was skipped" — the 11/11 mutation
confirmation, the two independent full-gate runs with identical 2278/12/19 numbers, and
the scratch-directory re-verifications are the evidence trail.

**How to apply:** before mutation-testing any "opens its own transaction" / "separate
connection" shape on a SQLite-backed fixture, expect a lock error rather than a silent
survival, and report both the mechanism and why it still counts as a valid red. Reuse the
plant-in-real-directory technique for every future `Module_X` boundary-narrowing AC.
Related: [[project_t017-audit-framework-closeout]], [[project-negative-control-every-guard]],
[[project_t014-branches-terminals-closeout]].
