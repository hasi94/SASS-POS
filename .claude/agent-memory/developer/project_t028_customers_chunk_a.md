---
name: t028-customers-chunk-a
description: T-028 Customers chunk A (steps 0-4) — what shipped, the measured baseline, and the one predicted-red gap the plan missed
metadata:
  type: project
---

Measured 2026-11-10. T-028 "Customers" plan (`docs/plans/T-028-customers.md`) chunk A
(steps 0-4: baseline, package plumbing, `CheckConstraint::oneSided`, the eight tenant
migrations + `CustomersTenantSchemaTest`, `TenantDecimalPrecisionTest`) shipped clean in
one `dev` session. Step 0 baseline: `composer check` exit 2, 13 failed (pre-existing,
unrelated)/31 skipped/3258 passed, `test` step 1193.01s. End-of-chunk-A gate: same 13
pre-existing failures, 31 skipped, 3280 passed, `test` step 955.52s (no growth concern).

**A predicted-red gap the plan's section 9 table did not name**:
`modules/host/tests/Feature/IdempotencyKeysSchemaTest.php` has a test literally named
`'is the catalogue top entry, phase expand, version 43'` that hardcodes the *previous*
task's (T-027's) top migration name and catalogue version as a bare assertion. Any task
that republishes the catalogue with new migrations on top turns it red — T-027 itself
must have edited this same test when it landed on T-021's idempotency_keys migration.
It is not a `SchemaCataloguePublishTest`-style self-correcting test; it must be hand-
edited every time, and the next task after T-028 will have to do the same again (rename
is tempting but was left alone here, matching its own "stale on purpose" survival
comment added in this session).

**Why:** whoever writes the plan's "predicted red" table (`architect`) does not always
catch every test in the tree that pins "today's top migration" by literal value — this
one is easy to miss because its name doesn't mention "catalogue" or "T-027".

**How to apply:** on any future task (T-029+) that adds tenant migrations and
republishes the schema catalogue, grep for the current top migration's literal name/
version across `modules/host/tests` before trusting the plan's own predicted-red list
to be complete — `IdempotencyKeysSchemaTest.php` is the one known repeat offender.
Related: [[gate_reads_live_filesystem_never_edit_during_it]].

**`CheckConstraint::oneSided` implementation gotcha** (packages/kernel/src/Schema/
Support/CheckConstraint.php): `MigrationHelperAndImportRulesTest`'s AC-33(c) rule forbids
`DB::statement()`'s first argument from being anything but a string literal (or a
literal-led concatenation) — calling `DB::statement(self::oneSidedStatement(...))` is
reported as a violation because the argument is a method call, not a literal. The fix,
matching `CheckConstraint::between()`'s own existing pattern exactly: inline-build the
SQL string with concatenation starting on a literal inside `oneSided()` itself, and keep
the separate `oneSidedStatement()` pure method (used only by the exact-text test) out of
the call path entirely. Any new `CheckConstraint`-style helper must follow this same
split or it fails that static rule.
