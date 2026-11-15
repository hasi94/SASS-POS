# The FX fixture migration sets

T-008 step 8. Six migrations, one per directory, supplied to the runner as
alternative migration sets through
`Flexipos\Kernel\Schema\Support\MigrationSet`. Without them the runner could
only ever be tested against the one real baseline, which is applied to an empty
database exactly once and so proves nothing about **upgrades**.

One directory per fixture, because a version is a position in a set: a set is
built by naming the baseline and then the FX directories that follow it, in the
order they are applied, and a directory holding several unrelated fixtures could
not be composed that way.

| Set | Phase | What it is for |
|---|---|---|
| Baseline | expand, six files | The **fixture baseline** every FX set is composed onto (T-011, FP-304). Not the product's tenant tree: see below. |
| FX-1 | expand | A new table after the baseline — D-1's reversal path (AC-38), and AC-39's observation point. |
| FX-2 | expand | Fails on one tenant only, told which by database name (AC-44, AC-46). |
| FX-3 | expand, `@requires-offline` | Observes the mapping status from inside `up()` (AC-52). |
| FX-4 | contract | Adds the foreign key onto FX-1's table (AC-58), and D-10's positive control: contract but **not** destructive. |
| FX-5 | contract | Drops a column — `is_destructive` (AC-59, D-10). |
| FX-6 | expand | Calls a drop, which AC-33 forbids. Wrong on purpose. |

**Nothing here is ever applied to a database the test did not create**, and
FX-5 and FX-6 are never applied at all: step 9's refusals are proven by
asserting zero job rows and zero connections, never by letting the destructive
file run to see what happens.

**No FX file ever names another connection.** AC-37 forbids it, and these files
are also the controls proving that rule silent on clean fixtures. Where a
criterion's observable lives in the master — the planned job count AC-39 wants,
the mapping status AC-52 wants — the migration calls
`FixtureMigrationLog::observe()` and the test installs the probe that takes the
reading. The cross-connection read belongs to the test.

`FixtureMigrationLog` is deliberately **beside** the directories and not inside
one: every `*.php` file in a set directory is a migration.

## The fixture baseline (`Baseline/`)

T-011, FP-304. `runFixtureSet()` composes the FX directories onto
`Baseline/`, six synthetic expand files that each create one trivial
`fxbase_*` table, and **not** onto the product's tenant tree. Before this, the
corpus's meaning changed every time the product shipped a file, and a contract
step anywhere in the shipped tree refused every plan from a fleet at 0 before
any FX migration was reached. The product never reads this directory; the one
kernel case that still measures the shipped tree is
`packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php`.

File *n* is version *n*, and no file is copied from the product tree.

**Six is load-bearing, and that is a coupling.** Several T-008-era corpus
cases write versions as literals against a six-file baseline: `6` as "the top
of the baseline" and `7` as "FX-1's version". They live in
`DetectSchemaDriftHandlerTest`, `RepairSchemaVersionHandlerTest`,
`MigrateTenantLockTest`, `QaPlannedStatusReachabilityTest`,
`QaPrintedInstructionRoundTripTest` and `PlanMigrationRunHandlerTest`.
Changing the number of files here silently changes what those cases test, so
do not change it until those literals are derived from
`runFixtureBaselineVersion()` (a follow-up row for the lead).
