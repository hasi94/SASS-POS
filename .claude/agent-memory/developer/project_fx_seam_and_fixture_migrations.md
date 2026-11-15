---
name: fx-seam-and-fixture-migrations
description: How T-008's migration sets are composed (version = position, never merged-sorted) and why an FX migration may not read another connection.
metadata:
  type: project
---

`MigrationSet` (kernel `Schema/Support`) composes the shipped tenant tree with FX fixture directories;
the FX files live under `modules/host/tests/Fixtures/TenantMigrations/FX1..FX6`, one directory each.

**Why:** the baseline is applied to an empty database exactly once, so without an alternative set nothing
about *upgrades* - a later migration reaching a populated tenant, a failure on one tenant, a contract
step depending on an expand version - is observable.

**How to apply:**
- **A version is a position in the set**: numbered from one across the whole set, directories in
  declaration order, file names sorted only *within* a directory. Never merge-sort across the set - an
  FX file named `0001_…` would take version 1 and renumber the baseline under every tenant. Appending a
  directory must leave earlier versions untouched; that is the property to assert.
- A missing or repeated directory **refuses**; an empty one does not. The set's size is the version a
  tenant ends up carrying.
- **No FX file may name another connection** (AC-37), and those files double as the controls proving the
  rule silent. Where the observable lives in the master (planned job count, mapping status), the
  migration calls `FixtureMigrationLog::observe()` and the *test* installs the probe that reads it.
- `FixtureMigrationLog` sits beside the FX directories, never inside one: every `*.php` in a set
  directory is taken as a migration.
- `ThreeTenantFixture` (packages/testing) is split in two on purpose: `mapInto()` writes master rows and
  runs under the hold on sqlite; `build()`/`drop()` are engine work, assert the hold first, and go
  through `EngineDatabase` by name. See [[the-shared-mariadb-engine-is-production]].
