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

- **A capability SQLite lacks is faked at the driver seam, never by weakening
  the production check.** `Illuminate\Database\Connection::resolverFor($driver,
  $callback)` registers a connection class for a driver name that has no
  connector at all, so the resolver can build its own PDO and override
  `getDriverName()`, `getPdo()` or `selectOne()`. That is how a refused
  `GET_LOCK` (AC-49) and a server rejecting a credential with SQLSTATE 28000
  (AC-16) are exercised with no engine: everything above the seam is the shipped
  code, and the granted/reachable case is the control beside it. The fake driver
  name must also be added to `tenancy.allowed_drivers` for that test.

- **`ALTER TABLE ... ADD COLUMN ... STORED` is accepted only while the table is
  empty.** Measured 2026-10-15 on SQLite 3.39.2 through Laravel 12's SQLite
  grammar: empty table accepted, one row -> `cannot add a STORED column`, and
  accepted again after a DELETE. It is a row-count check, not a static
  restriction. `->storedAs()` and a unique index over the generated column both
  work otherwise, including the case-variant duplicate refusal and the
  `where <col> = ?` lookup. So an expand migration adding a generated column
  passes on the fresh in-memory stand-in and fails for anyone who seeds the
  table first.
- **A packed `inet_pton` value round-trips through a Laravel `binary` column on
  the stand-in**, both families, and `where ip = ?` on the packed value matches.
  Only SQL `length()`/`typeof()` lie (1 and `text` for IPv4), because they stop
  at the first NUL - assert on the PHP value after `inet_ntop`.

**Why:** it decides what can be proven while the engine is on hold, which is
most of T-008. A stand-in proof is never `[E]` evidence - those criteria stay
NOT VERIFIED until G-1 - but "the tree applies, in order, with every key finding
its table" is worth having on every gate run.

**How to apply:** when a migration needs an engine-only statement, branch on the
driver in one named helper and state the SQLite difference there; when a test
migrates, name the connection explicitly and assert it is SQLite *before*
migrating, which is what makes the test structurally unable to reach
`127.0.0.1:3306`. Related: [[project-shared-mariadb-engine-is-production]].

**CORRECTION, measured 2026-10-20 (T-010 step 2): "SQLite has no ALTER TABLE
ADD CONSTRAINT" is true of RAW SQL only.** The raw statement is a syntax
error, as the bullet above says - but taking a foreign key through the
**schema builder** (Schema::table with ->foreign()->references()->on())
SUCCEEDS on SQLite through Laravel 12 grammar, which rebuilds the table:
pragma foreign_key_list reports the key, sqlite_master shows it inside the
recreated CREATE TABLE, and an insert naming an absent parent row is refused
with "FOREIGN KEY constraint failed". So a contract migration that takes a
foreign key does NOT have to be driver-gated, and an [ENGINE] label applied
for that reason is wrong. JsonValidity gate is still right: a raw
ADD CONSTRAINT ... CHECK has no builder equivalent.

**But the rebuild never re-checks existing rows (measured T-011 step 6, 2026-10-21).**
With the data-clean check cut out of the settings.updated_by migration, the rebuild
applied over an unknown id with `foreign_keys` ON and OFF alike: 3 `__temp__`
statements ran, the key was added, and `pragma foreign_key_check("settings")` then
reported 1 violation. SQLite migrations are also not transactional. So on the
stand-in a contract step that adds a key needs its own pre-check, and that check is
the only guard. `SettingsUpdatedByKeyTest` pins this ("Ruling 7, measured").
