---
name: lock-clause-pin-on-the-standin
description: how to pin a lockForUpdate on the SQLite stand-in (MySqlGrammar + pretend), and why a guard's locked read must include the row being removed
metadata:
  type: project
---

SQLite's grammar compiles `lockForUpdate()` to nothing, so a query log on the
stand-in cannot see a lock. The pin that works: swap in
`new MySqlGrammar($connection)` with `setQueryGrammar()`, run the guard under
`$connection->pretend(...)` (nothing executes, selects return []), restore the
grammar in `finally`, and assert the logged SQL ends with ` for update`.
Used in `LastOwnerTest` (T-010 R-D); removing the lock reddens it.

**Gotcha:** pretend returns no rows, so any early-return read before the
locked one hides it. The guard had to become one locked read.

**Why the locked set includes self:** two removals that each lock only "the
other owners" lock disjoint rows and deadlock on the write; locking the full
set in one query gives both the same lock order, so the second blocks and
then refuses. The engine half stays [ENGINE]/NV.

**How to apply:** any read-then-write guard (FP-206, T-009 Note A shape).
Used again for `VerifyPinHandler`'s employee read (review W-1).

**Two traps met on the second use:** `pretend()` logs SQL with the bindings
already substituted (`` `code` = 'cashier' ``, not `?`). And editing a
tenant migration moves its checksum in
`database/catalogue/tenant_schema_versions.json`. Regenerate it with a
scratch script that requires `vendor/autoload.php` and writes
`MigrationCatalogue::encodeFixture(MigrationCatalogue::forTree(...))`.
That needs no app boot and no database. Then confirm only that file's row
moved.
