---
name: tenant-tree-top-version-is-pinned-everywhere
description: Shipping any tenant migration edits ~25 sites in eight files that wrote the baseline top as the literal 6 - and shipping a CONTRACT step in the shipped tree breaks the whole planner test corpus by design
metadata:
  type: project
---

Measured 2026-10-20 while shipping T-010's eight tenant tables.

**The literal `6` is everywhere, and it is not one concept but three.** Before
adding a tenant migration, grep both test trees for the current top version as
a bare number and classify every hit:

- *the baseline's table count* - `tenantBaselineTables()`, which T-010 split so
  it keeps its six and a new `tenantTreeTables()` carries the whole tree;
- *the baseline's file count* - the catalogue's row count, `highestVersion()`,
  "the version after the baseline". It stopped equalling the table count the
  moment a migration created no table (a contract step), so it is now
  `tenantMigrationFileCount()`, which globs the directory;
- *"a tenant that is up to date"* - a fixture's `schema_version`, a job's
  `to_version`, a printed `--to=N`. This is the big one: ~20 sites across
  `packages/kernel/tests/Schema/Features/{MigrateTenant,PlanMigrationRun,
  RunTenantMigrations}` and the value one above it (`7`) means "the fixture
  migration stacked on top".

The architect's plan found three sites. Running the suite found seventeen more.
Budget for that: the first full run after a migration lands is the inventory.

**A contract step in the SHIPPED tree is a different order of problem.**
`PlanMigrationRunHandler::assertContractStepsUnblocked()` refuses to plan a run
containing a contract step while any tenant sits below V-1. The planner test
corpus is built on fixture tenants that are deliberately *behind*, so with a
contract step at the top of the shipped tree **38 pre-existing kernel tests
fail and cannot be fixed by renumbering** - their scenario is now correctly
refused. Those tests have to plan a fixture-only set, or target V-1. Decide
that before writing the migration, not after.

The rule the planner enforces is about *planning a run*, never about *shipping
a file*, and the blocking set is empty in production while no tenant database
exists - which is exactly why this trap is invisible until the suite runs.

**How to apply:** ship expand migrations and contract migrations in different
commits, and measure the suite after the expand set before writing the
contract step. Related: [[master-table-list-is-pinned-three-times]],
[[project-sqlite-standin-vs-engine]].
