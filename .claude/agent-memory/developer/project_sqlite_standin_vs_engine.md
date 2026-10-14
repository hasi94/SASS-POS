---
name: project-sqlite-standin-vs-engine
description: How far the suite's in-memory SQLite can stand in for MariaDB when writing FlexiPOS migrations, and the four statements it refuses
metadata:
  type: project
---

The suite's connections are in-memory SQLite (`phpunit.xml`), and under
`ENGINE_HOLD` that is the **only** database a test may touch. Measured
2026-10-14 while writing T-008's two migration trees:

- **A migration that names `ascii_bin` is an error on SQLite, not a no-op.**
  Its only collations are `BINARY`, `NOCASE`, `RTRIM`, and Laravel's SQLite
  grammar *does* emit `collate '…'` (`modifyCollate` is in its `$modifiers`).
  Same for `ALTER TABLE … ADD CONSTRAINT` (SQLite has none) and a second
  `AUTO_INCREMENT` column. Each of those three statements is therefore applied
  only when the driver is mysql/mariadb, in one shared place that says why.
- **`Blueprint` keeps its connection `protected` and has no getter.** Read the
  driver from `Schema::getConnection()->getDriverName()`; it is the migration's
  own connection by construction, because AC-37 forbids a migration from naming
  another.
- **`Artisan::call('migrate')` in a test applies to `DB_CONNECTION`, which
  phpunit.xml sets to `sqlite` - not to `master`.** It exits 0 and creates
  nothing the test can see. Always pass `--database=master` (and
  `--path=database/migrations-tenant` for the tenant tree, onto a connection the
  test configures itself - never T-003's `tenant` sentinel stanza).
- **SQLite agrees with MariaDB on NULLs being distinct in a unique index**, so a
  stored-generated sentinel column and the duplicate it refuses can be proven
  there. Generated `STORED` columns and `pragma foreign_key_list` work too, but
  foreign keys are ignored unless the connection sets
  `'foreign_key_constraints' => true`.

**Why:** it decides what can be proven while the engine is on hold, which is
most of T-008. A stand-in proof is never `[E]` evidence - those criteria stay
NOT VERIFIED until G-1 - but "the tree applies, in order, with every key finding
its table" is worth having on every gate run.

**How to apply:** when a migration needs an engine-only statement, branch on the
driver in one named helper and state the SQLite difference there; when a test
migrates, name the connection explicitly and assert it is SQLite *before*
migrating, which is what makes the test structurally unable to reach
`127.0.0.1:3306`. Related: [[project-shared-mariadb-engine-is-production]].
