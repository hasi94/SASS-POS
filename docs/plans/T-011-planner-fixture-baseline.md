# Plan: T-011 Give the planner corpus its own fixture baseline, and ship the `settings.updated_by` contract migration it unblocks

Spec: `docs/specs/T-011-planner-fixture-baseline.md`. The spec header is the authority for the criterion range (AC-1 to AC-26), and this plan states no total (FP-181). Decisions: `docs/decisions/T-011-planner-fixture-baseline.md` (D-1 to D-13). Backlog rows FP-304 and T-011, carrying T-010 AC-10 (owed since T-008 D-5). Branch `ai/T-011-planner-fixture-baseline`, cut from the T-010 tip.

**Gate baseline this plan is written against, measured 2026-10-21 on this branch before any change, and CONTAMINATED:** `composer check` **exit 0 · 1542 passed · 11 skipped · 9919 assertions · test step 470.51s against a 900s limit (ratio 1.91, source=manifest) · wall 631s.** The gate printed its own `FLEXIPOS-GATE-LOW-HEADROOM` line: the ratio is **below the required 2x**.

**The figure is contaminated, and here is by what.** Foreign `php.exe` processes running when the gate started, by pid:

| pid | command line | still running at the end |
|---|---|---|
| 1884 | `php artisan serve --port 8010` | yes |
| 6932 | `php -S 127.0.0.1:8010 …\99mobility_refferelportal\api\…\server.php` | yes |
| 31784 | `php vendor/bin/phpunit --colors=never` | no, finished during the run |
| 51744 | `php artisan test` | yes |
| 49868 | `php …phpunit --configuration=D:\Flexiata\Scriber\backend\phpunit.xml --no-output` | yes |
| 47048 | `php vendor/bin/phpunit --group=slow` | no, finished during the run |

Two test runs from other projects ran for part of this gate, and two more ran for all of it. No clean figure for this tree can be taken while they run, and I could not take one. **The 1.91 is not evidence that this tree is near its limit. It is not evidence that it isn't, either.** T-010's risk table made a ratio below 2.0 an escalation to the lead, and that rule applies here: see section 8, risk 1. Every step below is measured against the gate as it stood when that step began, and `dev` lists the foreign `php.exe` processes by pid before each gate run.

One spot measurement taken after the gate, with four of the six still running: `QaRealApplierOnStandInTest` alone took **21.02s** for its one case (fifteen files applied to two file-backed stand-in tenants through the real applier, about 0.7s per file per tenant). `TenantBaselineBuildsTest`, which applies the whole tree in-memory in one `migrate` call, took 0.36s. That difference drives the cost decisions in Rulings 2 and 6.

`ENGINE_HOLD` is on and G-1 is unmet. Section 11 is the authority for the `[ENGINE]` set.

---

## 0. Rulings: `ba`'s six open questions, answered by measurement

### Ruling 1: the fixture baseline is **six** synthetic expand files in `tests/Fixtures/TenantMigrations/Baseline/`, and the six is deliberate (open question 1)

**Location and names.** Directory `modules/host/tests/Fixtures/TenantMigrations/Baseline/`, a sibling of `FX1` to `FX6` (D-1). The path relative to the base path, `tests/Fixtures/TenantMigrations/Baseline`, is the form `config('schema.tenant_migration_path')` takes. It has one home, `runFixtureBaselinePath()` in E1 (section 3). Six files, one trivial table each:

```
2026_10_21_000001_fxbase_creates_fxbase_one_table.php     -> fxbase_one
…
2026_10_21_000006_fxbase_creates_fxbase_six_table.php     -> fxbase_six
```

Each file: `@phase expand`, `IdentifierColumn::primary($table)`, one `string('label', 60)`, no `down()`, nothing else. The `fxbase` token keeps the names out of `MigrationTreeDisciplineTest`'s `fx\d+` pattern, which is not the mechanism for keeping them out of the shipped tree (AC-1's own checksum check is). It also keeps them out of the product tree's naming style. **No file is copied from the product tree** (AC-2 asserts this by checksum).

**Why six, and why that is a choice rather than a coincidence.** I measured the corpus for literal version numbers. Outside the `tenantMigrationFileCount()` sites (E2), the T-008 corpus still carries **literal versions written against T-008's six-file baseline**. They are `6` as "the top of the baseline" and `7` as "FX-1's version", in `DetectSchemaDriftHandlerTest`, `RepairSchemaVersionHandlerTest`, `MigrateTenantLockTest`, `QaPlannedStatusReachabilityTest`, `QaPrintedInstructionRoundTripTest` (job targets and `tenancy.required_schema_version`), and `PlanMigrationRunHandlerTest` (the destructive case's tenant at `7`). With today's fourteen-file shipped tree they pass as arbitrary mid-tree positions, so T-010's sweep left them alone. A baseline of fewer than six turns most of them red. That would need edits AC-24 does not sanction. A baseline of more than six leaves them passing with the wrong meaning.

**Six restores every one of them to the meaning its author wrote, with zero edits to them.** It also repairs one discriminating control that the fourteen-file tree had silently weakened. `PlanMigrationRunHandlerTest`'s destructive-refusal case puts its tenant at `7` so that, in its own words, "the contract guard has nothing to say". Against fourteen shipped files FX-5 sits at 16 and the tenant at 7 is below 15, so the contract guard *would* fire if the destructive branch were deleted. Only the message assertion on `schema.backups.available` still discriminates today. On a six-file baseline FX-5 is at 8, the applied range is `[8]`, the contract guard is silent, and the case is back to the design its comment states.

**The cost, stated rather than hidden.** Those literals are now a second home for B. The spec's convention ("B is never written as a literal in a test") binds every test this task writes, and section 7 holds new tests to it. The pre-existing literals are not this task's to edit (AC-24 does not list them). The `Baseline/README` section (step 1) names the coupling and the files. **Recommended row for the lead** (FP-181 instance): *"Derive the remaining literal versions in the planner corpus from `runFixtureBaselineVersion()`. The fixture baseline's size, six, is load-bearing for T-008-era literals in six files (listed in `tests/Fixtures/TenantMigrations/README.md`). Changing the baseline's size before this row is done silently changes what those cases test."*

**Tables the corpus reads by name (the E3 set, measured).** I searched every kernel corpus file for each product baseline and T-010 table name and file name, and read each runner test that applies a set. Exactly **two** assertions name a product-tree table or file while their subject is the runner:

| Site | What it names | Subject | E3 edit |
|---|---|---|---|
| `PlanMigrationRunHandlerTest.php:413` | `create_schema_meta_table`, the first unpublished migration in the refusal | the AC-32 refusal | `runFixtureSet(1)->catalogue()[0]->migration`: the first entry, derived, never typed |
| `QaRealApplierOnStandInTest.php:91-103` | `tenantTreeTables()` and `tenantMigrationFileCount() + 1` | the shipped *applier* | `runFixtureBaselineTables()` and `runFixtureBaselineVersion() + 1`. The B half is E2 |

**What did not need E3, and why.** `MigrateTenantHandlerTest`'s two `schema_meta` cases plant the table themselves through `migrateFixtureSchemaMeta()` (a bare PDO `create table if not exists`) and run the **fake** applier. `schema_meta` there is the kernel's own identity contract (`SchemaMetaIdentity::TABLE`), not a table the baseline must have created. They are unchanged. No corpus case has a product table as its subject, so nothing moves to the named case under E3's second clause. **But one piece of coverage does move, and it is named:** `QaRealApplierOnStandInTest` was, incidentally, the only stand-in proof that *the shipped tree* applies through the real applier. After E3 it proves that of the fixture baseline. AC-11's named case takes over the shipped-tree half (Ruling 2), so the coverage moves and is not lost.

### Ruling 2: AC-11 is feasible on the stand-in, and the fixture is `migrate-tenant-fixture.php` with the real applier rebound (open question 2)

**Yes, the whole shipped tree applies to the suite's stand-in tenants, and it is already measured.** `QaRealApplierOnStandInTest` does exactly this today: `migrateFixtureBoot()`, `migrateFixtureServer()`, two `migrateFixtureTenant()` SQLite files, `ArtisanTenantMigrationApplier` bound over the fake, and the full runner graph (plan, claim, `Tenancy::enter` on the Migrating purpose, `migrate --path=<file> --realpath` per file, mirror). It applies all fourteen shipped files plus FX-1 to two tenants in 21.02s (header). The contract step's SQLite path, a table rebuild through Laravel 12's grammar, was measured green on a populated table in T-010 step 3b. **AC-11 does not degrade under D-13.**

**The cost, and how it is paid.** AC-11 applies V - 1 files and then V to two tenants, which is about the same cost as `QaRealApplierOnStandInTest` today. E3 moves that test onto the six-file fixture baseline, so it drops to about seven applications per tenant. The two roughly cancel. Two further decisions keep it bounded:

- **AC-11's lower-remedy control is decided at plan level**, not by a second real-applier run. The fleet is put at V - 2 in the mapping, which is exactly what a lower-target remedy would leave, and the plan to the top is shown refused. Running a lower remedy for real would add about 18s and prove nothing the mapping does not, because the refusal reads only `tenant_databases.schema_version`. **Routed to `ba` (section 9, item 4)**, because the spec says "a remedy with a lower target".
- **Steps 6 and 7 never use the real applier to bring a tenant to V - 1.** They build one template database by applying files 1 to V - 1 in a single `migrate` call and copy it per tenant (Ruling 6). The real applier runs only the V step, which is the subject.

### Ruling 3: the command-level tests get the fixture baseline by setting `schema.tenant_migration_path` in the test, through one E1 helper; one other product path reads the tree, and no corpus test reaches it (open question 3)

**The existing path authority is `config('schema.tenant_migration_path')`** (`modules/host/config/schema.php:26`). `TenantMigrateCommand::handle()` reads it **at call time** through the injected `ConfigRepository` and passes `basePath(<value>)` to `MigrationSet::baseline()` (`TenantMigrateCommand.php:73`). So a test that sets the key before `Artisan::call('tenant:migrate', …)` gives the command the fixture baseline. The command, the kernel and the config file are all left untouched (D-4, AC-25).

**One helper, so the key has one test-side home.** E1 gains `runFixtureCommandsReadTheFixtureBaseline()`, which does `config()->set('schema.tenant_migration_path', runFixtureBaselinePath())`. The value is relative, because the command calls `basePath()` on it, and an absolute value would be joined onto the base path and broken. The E4 files call the helper in their `beforeEach` and never spell the key. The AC-8 sweep can then say "the key appears in the corpus only in the E1 support file", which is simpler and stronger than telling reads from writes.

**E4, measured: every corpus file that invokes `tenant:migrate`.** Applied uniformly, including where a case happens to exit before the set is built, because an un-pointed command in the corpus *is* a second shipped-tree reader:

- `RunTenantMigrations/RunTenantMigrationsHandlerTest.php`: three `Artisan::call('tenant:migrate', …)`. The `--all` two go red without it: the catalogue published is `runFixtureSet()`, so the command's shipped set would be refused as unpublished.
- `RunTenantMigrations/DispatchMigrationRunTest.php`: one call, `--all --queue`.
- `RunTenantMigrations/QaPrintedInstructionRoundTripTest.php`: every case calls the command back.
- `RunTenantMigrations/RunTerminalStateTest.php`: one call, `--run`.

**The queue job needs nothing.** `MigrateTenantQueueJob` carries the set's name and directory list from whoever planned it (`forJob(…, MigrationSet $set, …)`) and rebuilds it with `MigrationSet::named()`. It never reads the configuration. `MigrateTenantQueueJobTest` builds from `runFixtureSet()` and follows E1 automatically.

**Does any other code path read the product tree? Yes, three, and only the first is reached from the corpus.**

| Product reader | Reads the tree when | Reached by a corpus test? |
|---|---|---|
| `TenantMigrateCommand` | every planning invocation | yes, the four E4 files |
| `SchemaStatusCommand` (`ReportSchemaStatus`, line 105) | only with `--drift` | **no**. I searched every `*Test.php` for `--drift` and found none. `tenant:schema-status` without it never builds a set. Recorded, not edited |
| `PublishCatalogueCommand` | `schema:publish` | not from the corpus. The host's `SchemaCataloguePublishTest` drives it with scratch paths, and its subject is the product tree |

`ArtisanTenantMigrationApplier` reads no path. It applies `$set->pathFor()`, and its docblock says so. The test-side readers are `tenantMigrationPath()`, `migrationTreeDirectory()` and `tenantMigrationFileCount()` in `modules/host/tests/Helpers.php`.

### Ruling 4: tenant migrations are forward-only, so the new migration declares no `down()` (open question 4, AC-21)

T-008 **D-8**: *"Do tenant migrations have a `down()` and does the runner roll back? No. Forward-only. The runner has no rollback. A mistake is corrected by a new migration."* Measured: none of the fourteen shipped tenant files declares `down()`, and `SourceRules::expandOnlyAdds()`'s docblock says so (*"the shipped tree has no `down()` at all (D-8)"*). **AC-21 takes its first branch.** The migration declares no undo and no criterion expects one. AC-13's static test asserts the absence, with the shipped tree's own absence as the population it agrees with.

### Ruling 5: the shipped-tree entry point, the sweep's scope, and the exclusions (open question 5, AC-8)

**Entry point:** `runFixtureShippedSet(): MigrationSet` in `packages/kernel/tests/Support/migration-run-fixture.php`. It returns `MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')))`. The name says what it reads. AC-4's control and AC-9's derivation go through it.

**The named case is one file:** `packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php`, every case tagged `->group('shipped-tree')`. It holds AC-9, AC-10 and AC-11 **and also AC-4's control and AC-5's control (a)**. Both of those must call the shipped-tree entry point, and the clean way to keep "one reader" true is for every corpus check that needs the shipped tree to live in the one file that is allowed to read it. `ba` should know AC-4 and AC-5's controls live there (section 9, item 1).

**Sweep scope, by directory: `packages/kernel/tests/**/*.php`.** This is where every caller of `runFixtureSet()` lives (measured: no file outside `packages/kernel/tests` calls it). It is the corpus AC-7 defines ("every kernel test that composes a set through `runFixtureSet()` or through the migrate-tenant fixture"). The domain is enumerated from the directory and printed. **Reader needles:** `tenant_migration_path`, `tenantMigrationPath(`, `migrationTreeDirectory(`, `tenantMigrationFileCount(`, `runFixtureShippedSet(`, and the literal product directory name `migrations-tenant`. The needles are written split in the sweep's own source so that file does not report itself, which is QaAccessCloseOutSweepsTest's precedent. **Allowed readers:** the E1 file `Support/migration-run-fixture.php` (the definition and the E4 setter) and `ShippedTenantTreeTest.php`. **Excluded by name inside the scope, and printed:** `Schema/Support/MigrationSetTest.php`, whose subject is `MigrationSet` over the real path. Anything else fails, with the file named.

**The exclusions list, confirmed against the files, and it needs one correction.** Of `ba`'s five, only `MigrationSetTest` is inside the scope. The other four are **outside the swept directory**, and the sweep prints them as a named out-of-scope list so the boundary is visible: the catalogue-publish test, the migration-tree discipline tests, the engine-only tests plus `ThreeTenantFixture` (D-12), and `AccessTenantFixture`. The list `ba` wrote is **incomplete for a whole-repository scope**. I measured nine more host files that read the shipped path because their subject is the product tree: `TenantBaselineBuildsTest`, `TenantAccessKeysTest`, `QaTenantBaselineStandInShapeTest`, `QaSchemaSignatureInstrumentTest`, `QaDatetimePrecisionSourceTest`, `QaAccessSchemaShapeTest`, `FixtureMigrationSetsTest`, `ThreeTenantFixtureMappingTest`, and `Helpers.php` itself. This task's two new host files (steps 6 and 7) join them. That is the reason for a directory scope rather than a whole-tree scope with a growing exclusion list. **The residual, which the sweep's output states in terms:** a planner-input reader added *outside* `packages/kernel/tests` is not caught. Routed to `ba` (section 9, item 1).

**One in-scope reader the sweep would find today, and E2 removes it:** `PlanMigrationRunHandlerTest.php:99-100` builds `new ThreeTenantFixture(<shipped path>)` and maps the fleet at `MigrationSet::baseline(<shipped path>)->highestVersion()`. That is the shipped file count by another spelling, used as a fleet version, which is E2's definition. It becomes `runFixtureBaselineDirectory()` and `runFixtureBaselineVersion()`. `ThreeTenantFixture::mapInto()` never reads the directory (only `build()` does, and the engine tests call that), so passing the fixture directory changes nothing but the source.

### Ruling 6: what the runner's error recording stores, so D-10 is testable (open question 6)

Read from `MigrateTenantHandler::recordFailure()`, `describe()`, `blameFor()`, `MigrationErrorText::sanitise()`, `TenantMigrationFailed` and `ArtisanTenantMigrationApplier::applyFile()`:

- **`tenant_migration_jobs`:** `error_class` holds the **cause's** class (`RuntimeException` for the pre-check). `error_message` holds the runner's wrapper (*"The migration [<name>] failed on this tenant; …"*), then a space, the cause's class, `": "`, and the **cause's message verbatim**. `error_migration` holds the migration name.
- **`tenant_databases.last_migration_error`:** the same `error_message`.
- **Sanitising** removes only the tenant's resolved credential, its credential reference, and any `scheme://…` token. It then truncates to 2000 characters. **It does not remove data values.** A ULID from `settings.updated_by` would pass through untouched.
- **The command output** prints `failed: <error_message>` per tenant. The runner writes no log line of its own (no `Log::` or `logger()` anywhere in `MigrateTenant` or `RunTenantMigrations`).

**So D-10 is the migration's own discipline, and the test proves it at every sink.** The pre-check throws `RuntimeException` (global, so no `use` import and no AC-37(d) issue) with a message naming the table and column, the **count** of offending rows and the remedy, and no value. The test plants a distinctive 26-character sentinel id and asserts it is absent from `error_message`, `error_class`, `error_migration`, `last_migration_error`, the handler's returned `errorMessage` and the command output. **Liveness, shape 5:** in the same capture, assert that `error_message` contains the count and the migration's name. With two offending rows, assert it contains `2`, so the count is not a constant.

### Ruling 7: on the SQLite stand-in, the pre-check is the only guard **in both enforcement states**. This is stronger than AC-18 states, and `dev` confirms it by measurement

Read from `vendor/laravel/framework` 12.69.2, `SQLiteGrammar::compileAlter()` lines 357-370. When `pragma foreign_keys` is on, the grammar emits `pragma foreign_keys = 0` before the rebuild and `= 1` after. It **never runs `pragma foreign_key_check`**. Separately, `Schema\Grammars\Grammar::$transactions` is `false` and `SQLiteGrammar` does not override it, so the migrator does **not** wrap a SQLite migration in a transaction. So on the stand-in, a rebuild over dirty data succeeds silently **whether enforcement is on or off**. AC-18's mutation (a) (delete the pre-check, AC-17 goes red) should therefore hold in both states, not only "with enforcement off". Because there is no transaction, a rebuild that failed halfway would leave the table half-altered, which is a second reason the check must come first. **This is a source reading, not a run.** Step 6 measures it (mutation (a) with enforcement on), and if it does not hold, the spec's narrower statement stands and nothing else changes.

### Ruling 8: no kernel source change (AC-25)

None is needed. Every mechanism the work requires already exists: the positional `MigrationSet`, the configuration authority the command reads at call time, the `TenantMigrationApplier` seam, and `runFixtureConnectionsUsedBy()`. **No file under `packages/kernel/src` is edited.** If a step finds otherwise, it stops and reports to `architect`. T-010 rejected option (b) for exactly this.

---

## 1. Approach summary

1. Add a six-file synthetic expand-only **fixture baseline** beside `FX1` to `FX6` (Ruling 1). Rewrite `runFixtureSet()` to compose FX directories onto it rather than onto `config('schema.tenant_migration_path')`. The set name stays `baseline+fxN`, so no assertion on set names moves.
2. Re-derive the corpus's "baseline size" sites from the fixture baseline (E2), re-point the two assertions that named product tables (E3), and point the four command-level files at the fixture baseline through the configuration authority (E4). **All of this lands while the shipped tree is still fourteen expand files**, so it is proved independent before anything depends on it.
3. Ship the contract migration as a new file (pre-check, then the key, no driver gate). Republish the catalogue fixture, move `.env.example`, and make the E5 product-tree edits. **The step that ships it edits zero corpus files.** That is FP-304's "done when", measured in practice rather than asserted.
4. Add the one named shipped-tree case (AC-9 to AC-11, with AC-4's and AC-5's controls), the migration's behaviour tests (AC-13 to AC-20), and the sweeps (AC-1, AC-7, AC-8).

No kernel source, no config file, no new dependency.

## 2. Alternatives rejected

- **A frozen copy of today's shipped tree as the fixture baseline.** D-2 rejects it: drift with a delay, and tested as if it were the product.
- **A fixture baseline smaller than six** (more "minimal"). It turns T-008-era literal versions red in six files that AC-24 does not sanction editing (Ruling 1).
- **Setting the configuration key inside `migrateFixtureBoot()`** (one site, no E4 edits). Every migrate-fixture test, the named case included, would then read the fixture path through `runFixtureShippedSet()`, and AC-4's control could no longer tell the two apart. E4 per file is explicit in the diff and keeps the named case honest.
- **A whole-repository AC-8 sweep with an exclusion list.** It needs at least eleven host exclusions today, and the list grows with every product-tree test. A directory scope plus a printed boundary and a stated residual is the shape-7-honest version (Ruling 5).
- **Bringing step 7's tenants to V - 1 through the real applier.** About 0.7s per file per tenant (header) times V - 1 files times three tenants, against a gate already below 2x. A template database copied per tenant keeps the real applier on the step that is the subject.
- **A `highestPlannableVersion()`-style helper, or `MigrationSet::baseline($path, $upTo)`.** Both were killed in T-010 (step 3a; correction (c), option (b)).

---

## 3. Files to change / create

**Who writes what.** `dev` writes every file below, following T-010's precedent, where `dev` wrote the fixtures its steps depended on. Files named `Qa*` are `qa`-owned for accountability: `qa` adopts or rewrites them, and runs section 5 step 8's mutations. Ownership here records who is accountable for a file, not who typed it.

### Product (the one new file and its two companions)

| Path | What |
|---|---|
| `modules/host/database/migrations-tenant/2026_10_21_000401_add_updated_by_foreign_key_to_settings_table.php` *(new)* | `@phase contract`. Pre-check first, then `Schema::table('settings', fn ($t) => $t->foreign('updated_by')->references('id')->on('employees'))`. No driver branch, no `down()`. `dev` may choose another name provided it **sorts after `2026_10_20_000308_…`** and contains no `fx` token. Shape in section 4 |
| `modules/host/database/catalogue/tenant_schema_versions.json` *(change)* | gains one row, written by `php artisan schema:publish --write-fixture`. **Only with `--write-fixture`**, which returns before the handler and touches no database (`PublishCatalogueCommand.php:55-60`). Without the flag, the command publishes to whatever master the shell's `.env` names, and the local engine is held |
| `modules/host/.env.example` *(change)* | `APP_REQUIRED_SCHEMA_VERSION` moves to the catalogue's new highest. The value is written here and derived in every test (T-008 AC-21) |

### Test support (E1, and the fixture tree)

| Path | What |
|---|---|
| `modules/host/tests/Fixtures/TenantMigrations/Baseline/*.php` *(new, six)* | Ruling 1 |
| `modules/host/tests/Fixtures/TenantMigrations/README.md` *(change)* | a Baseline row, plus a paragraph naming the six-file coupling and the literal-bearing files (Ruling 1) |
| `packages/kernel/tests/Support/migration-run-fixture.php` *(E1)* | `runFixtureSet()` rewritten onto the fixture baseline. New: `runFixtureBaselinePath()` (relative, the one home for the string), `runFixtureBaselineDirectory()`, `runFixtureBaselineVersion()` (B, as `runFixtureSet()->highestVersion()`), `runFixtureBaselineTables()` (**derived** with `SourceRules::tablesCreatedIn()` over the baseline files, not a hand list), `runFixtureShippedSet()` (the entry point), `runFixtureContractVersion(MigrationSet): ?int` (the highest contract-phase version, or null), and `runFixtureCommandsReadTheFixtureBaseline()` (E4). The docblock is rewritten to say why the baseline is not the product tree, citing FP-304 |
| `packages/kernel/tests/Support/migrate-tenant-fixture.php` *(E1)* | `migrateFixtureRun()`'s default `$target = 6` becomes `?int $target = null`, derived from `runFixtureBaselineVersion()`. `migrateFixtureApplyLog()`'s docblock sentence "of the shipped baseline" becomes "of the fixture baseline". No behavioural change beyond following `runFixtureSet()` |

### Pre-existing tests edited (AC-24; the diff is the count)

| Category | Files |
|---|---|
| **E2** | `PlanMigrationRunHandlerTest` (lines 99-100, 140, 158, 161, 318, 336, 356, 373, 386-387), `QaContractGuardNamedRunTest`, `ContractGuardBlockingSetTest`, `MigrateTenantHandlerTest`, `RunTenantMigrationsHandlerTest` (94, 193), `DispatchMigrationRunTest` (81), `QaRealApplierOnStandInTest` (101, 103). Every `tenantMigrationFileCount()` in `packages/kernel/tests` except `MigrationSetTest:191` becomes `runFixtureBaselineVersion()` |
| **E3** | `PlanMigrationRunHandlerTest:413`, `QaRealApplierOnStandInTest:98` (Ruling 1's table) |
| **E4** | the four files of Ruling 3: one `runFixtureCommandsReadTheFixtureBaseline()` line in each `beforeEach` |
| **E5** | `TenantBaselineBuildsTest` (the inventory gains `settings.updated_by -> employees.id`; `settings.updated_by` leaves the unguarded map and gains its own assertion that it is still a nullable identifier, with the map's count moving with the map); `MigrationTreeDisciplineTest:138` (the literal file-count pin becomes **derived**: file count equals table count plus the number of tenant files whose `SourceRules::tablesCreatedIn()` is empty, which is the first time those two differ, as its own comment predicted) |

**Not edited, and recorded so `qa` does not look for them:** `MigrationSetTest` (excluded, still true), `SchemaCataloguePublishTest` (already derives its counts and the `.env.example` comparison), every literal-version site Ruling 1 names, `ClaimMigrationJobHandlerTest` (writes rows and builds no set), and any product migration file already in the tree.

### New tests

| Path | Criteria | Owner |
|---|---|---|
| `packages/kernel/tests/Schema/Features/PlanMigrationRun/FixtureBaselineTest.php` | AC-2, AC-3, AC-5 (main and control (b)), AC-6 | `dev` |
| `packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php` | AC-4, AC-5 control (a), AC-9, AC-10, AC-11. Group `shipped-tree` | `dev` |
| `packages/kernel/tests/Schema/Features/PlanMigrationRun/QaFixtureBaselineSweepsTest.php` | AC-1, AC-7, AC-8 | `qa` (written by `dev` in step 3) |
| `modules/host/tests/Feature/SettingsUpdatedByKeyTest.php` | AC-13, AC-14, AC-15, AC-16, AC-17 (the migration-level on and off halves), AC-20 | `dev` |
| `modules/host/tests/Feature/SettingsUpdatedByContractRunTest.php` | AC-17 (the runner-level half and D-10), AC-18, AC-19 | `dev` |

**A trap, measured:** `QaAccessCloseOutSweepsTest::closeOutTaskTestFiles()` pulls any host `Feature/*Test.php` **that mentions `AccessTenantFixture`** into Ruling 7's domain. Any file in that domain that mentions `QueryException` must also call `AccessTenantFixture::enter(`. The two new host files therefore **must not name that fixture, even in a comment**. AC-16 uses a private stanza (section 5, step 6), which is how every pre-T-010 foreign-key test already works (`TenantBaselineBuildsTest:76` and others).

---

## 4. Data / API changes

**Schema:** one contract-phase key, `settings.updated_by → employees.id`. No table, no column, no index of its own (SQLite creates none for a foreign key; on MySQL the engine creates one implicitly, which is `[ENGINE]`). The column stays `CHAR(26)`, nullable.

**The migration's shape** (illustrative; `dev` owns the text):

```php
/*
 * <docblock: the column; T-008 D-5 owed it to the employees module; T-010 AC-10
 *  deferred it behind FP-304; the pre-check runs first because (a) on the SQLite
 *  stand-in the rebuild suspends key checks and runs no foreign_key_check, and no
 *  SQLite migration is transactional, so dirty data would be carried into the
 *  keyed table silently, and (b) on an engine a session with foreign_key_checks
 *  off would do the same; the failure names a count and never a value (D-10);
 *  it never deletes, nulls or repairs a value (D-7); the two-run requirement
 *  and the remedy the planner prints, described in words, with no version
 *  number written (D-6); no down(), forward-only (T-008 D-8); no driver gate
 *  (T-010 step 3b measured the five rebuild properties)>
 *
 * @phase contract
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $offending = Schema::getConnection()->table('settings')
            ->whereNotNull('updated_by')
            ->whereNotExists(fn (Builder $q) => $q->from('employees')->whereColumn('employees.id', 'settings.updated_by'))
            ->count();

        if ($offending > 0) {
            throw new RuntimeException(sprintf('settings.updated_by: %d row(s) name no employees.id …', $offending));
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
```

Four checks `dev` makes before step 4 closes, because each could fail quietly. (i) The catalogue row is `contract` and **`is_destructive: false`**. FX-4 is the same shape, and if this one were destructive, AC-10's positive control would be refused by the wrong guard (mask shape 3). (ii) `SourceRules::inspect()` is empty **with no exemption**. `Schema::getConnection()->table(…)` is a query builder, not a `Schema::table(` block, so `schemaBlocks()` does not see it, but confirm by running the rule. (iii) The comparison is exact: `=` on SQLite text is binary unless a collation says otherwise. `IdentifierColumn` sets none on SQLite, and AC-20 proves it. (iv) No word `truncate` appears in code (`isDestructive()` matches it).

**Run shape:** the catalogue's highest version moves up by one, and that version is a contract step. A fleet holding servable tenants below V - 1 runs the remedy `MigrationRunRefused::contractStepBlocked()` prints, then the run that includes V. `provisioning` does not block, so FP-206 still provisions to the top in one run. **No number is written here** (D-6): V is whatever the catalogue says after this task.

**Endpoints, config keys, master schema:** none. **Data migration:** none, because no tenant database exists. No escalation trigger is met.

---

## 5. Implementation steps

Each step ends green on `composer check` from `modules/host`, with a zero-delta-or-explained comparison against the gate as it stood when the step began. **Before each gate run, `dev` lists the foreign `php.exe` processes by pid and command line and puts them beside the figure** (header). No probe or scratch file is left in the tree when a step closes. Scratch trees that tests build at run time live under `storage/app/` and are removed in `afterEach`.

- [x] **Step 1: the fixture baseline and E1.** Six files, the README, and E1 as section 3 describes. `FixtureBaselineTest`'s AC-2 and AC-3 cases.

  *AC-2:* read the catalogue of `runFixtureSet()` and print it. Assert it is non-empty, every entry is `Expand` and not destructive, and the versions are 1..B. Assert each file name's trailing sequence number equals its catalogue version: that is what "numbered consecutively" can mean, since `MigrationSet` numbers by position and can produce no gap of its own. Assert no baseline checksum equals any shipped checksum, and `runFixtureBaselineTables()` is disjoint from `tenantTreeTables()`. *Controls:* the same check function fed a scratch list containing a `contract` entry reports it; fed a name list with a gap (sequence 1, 3) reports it; fed a checksum taken from a shipped file reports it. Do these as pure-function inputs and not as files in the tree. *AC-3:* the full `toRow()` equality of `runFixtureSet()` with `MigrationSet::baseline(runFixtureBaselineDirectory())`. Assert `runFixtureSet(1, 4)` puts FX-1 at B + 1 and FX-4 at B + 2. *Controls:* `(4, 1)` gives the other order, and `runFixtureSet(99)` throws `MigrationSetRefused`.

  **Then run the gate and record the red list.** It must be **exactly** the E2, E3 and E4 sites of section 3, all of them in `packages/kernel/tests`. **If any other file goes red, stop and report to `architect`.** That would mean a reader or a literal this plan did not measure, and it is the T-010 step 3 lesson (three sites predicted, twenty found). Do not edit it under a category it does not belong to. *Done when:* the two new cases are green, the red list is recorded in the step's commit message, and it matches section 3. *(AC-2, AC-3.)*

- [x] **Step 2: E2, E3, E4.** The sites of section 3, nothing else. A fleet that starts at 0 still starts at 0. **Negative control, and it is the AC-6 mutation run early:** make `assertContractStepsUnblocked()` skip contract entries in a scratch edit. The FX-4 refusal cases in `PlanMigrationRunHandlerTest` must go red. Revert. This proves the corpus still reaches the guard on the fixture baseline. *Done when:* the gate is green, the shipped tree is still fourteen files, and `git diff --stat` touches only the files section 3 lists for E1 to E4. *(AC-24 E1 to E4.)*

- [x] **Step 3: the corpus criteria and the sweeps, before the migration exists.** In `FixtureBaselineTest`:

  *AC-5:* a fleet of fixture tenants at 0. Plan `runFixtureSet(1)` to its top. The plan is written, every job runs 0 → B + 1, and every `from_version` is 0 (control (b)).

  *AC-6:* `runFixtureSet(4)` with a non-`provisioning` tenant below B, the version before FX-4. The message names FX-4's migration **and** `(version B+1)` **and** the tenant. Zero run rows, zero job rows, and zero tenant connections (`runFixtureConnectionsUsedBy`). The message contains exactly one catalogue-shaped migration name (`/\d{4}_\d{2}_\d{2}_\d{6}_\w+/`), and it is FX-4's. That shows it names no product file without reading the product tree. Positive controls: every tenant at B is planned, and only `provisioning` tenants behind is planned. Then `runFixtureSet(5)` with the fleet at B + 1 (FX-1 applied, FX-5 not) is refused destructively, and FX-4 is not.

  *`QaFixtureBaselineSweepsTest`:* **AC-1** scans product source (`modules/host/{app,bootstrap,config,database,routes}`, `modules/host/.env.example`, `packages/*/src`, `modules/*/src` outside the host) for `runFixtureBaselinePath()`'s value and the `fxbase` token, prints the file counts, and asserts none found. It also asserts `runFixtureBaselineDirectory()` lies under `base_path('tests')`. *Control:* the scan function fed a source string naming the path reports it. **AC-7** enumerates `packages/kernel/tests/**/*.php` calling `runFixtureSet(`, prints the list, asserts it is non-empty, and asserts none of those files contains `tenantMigrationFileCount(` or the configuration key. **AC-8** is Ruling 5 exactly, with the printed out-of-scope list and the stated residual. *Mutation control (shape 7):* the same function fed a path inside the scope whose source composes on the shipped tree reports it, as an in-memory pair in the Ruling 7 sweep's style. `qa` also plants one real scratch file once in step 8.

  *Done when:* green, and the AC-8 sweep's allowed-reader list prints exactly the E1 file. The named file does not exist yet, and the sweep must not require it to; it requires "no reader outside the allowed set". *(AC-1, AC-5 main and (b), AC-6, AC-7, AC-8.)*

- [ ] **Step 4: ship the migration, and edit no corpus file doing it.** The migration file, the catalogue fixture (through `--write-fixture` only), `.env.example`, and E5. **This step's diff must touch zero files under `packages/kernel/tests`. That is FP-304's "done when" measured in practice:** the product shipped a contract step and the corpus did not move. If the gate goes red in the corpus, the fixture baseline has a leak. Stop and report; do not patch the corpus. *Negative controls:* the catalogue fixture one row short, and `.env.example` one off, each turn `SchemaCataloguePublishTest` red (the existing moved-value tests; run them, don't rewrite them). Put a scratch extra key `settings.scope_id -> employees.id` in a scratch migration: `TenantBaselineBuildsTest`'s exact inventory goes red. Delete the scratch migration and confirm green. *Done when:* the gate is green, `git diff --stat` shows the three product files, E5's two files and nothing in `packages/kernel/tests`, and the four checks of section 4 are recorded. *(AC-12, AC-13's shape in part, AC-23, AC-24 E5.)*

- [ ] **Step 5: the named case.** `ShippedTenantTreeTest.php`, group `shipped-tree`, using `runFixtureShippedSet()` only.

  *AC-9:* V = `runFixtureContractVersion(runFixtureShippedSet())`. If it is null, the case **fails** with *"the shipped-tree gate coverage has nothing to measure: the shipped tenant tree holds no contract-phase migration"*. It never skips. *Control:* point the configuration at a scratch tree under `storage/app/` holding copies of the shipped expand files only. The derivation returns null, and the failure function, called on null, throws `AssertionFailedError`.

  *AC-4:* point the configuration at a scratch tree with an extra contract file at its top, then at one with extra expand files. For each of `()`, `(1)`, `(4)` and `(1, 5)`, `runFixtureSet` gives `toRow()`-identical output to the real path. *Control:* `runFixtureShippedSet()` **does** change under both scratch trees.

  *AC-5 control (a):* `runFixtureShippedSet()->with('fx1', …)` with the fleet at 0 is refused by the shipped contract step, naming V.

  *AC-10:* publish `runFixtureShippedSet()`, map active tenants at 0, and plan to the top. The refusal names the shipped migration (`entryFor(V)->migration`), V, and each blocking tenant. Zero rows, zero tenant connections. (a) With the tenants at V - 1, the plan is written and every job's `to_version` is V. (b) A `provisioning` tenant at 0 does not block.

  *AC-11:* `migrateFixtureBoot()`, rebind `ArtisanTenantMigrationApplier`, and use two `migrateFixtureTenant()` files at 0. Plan to the top and capture the refusal. Take the invocation **from the message** with a parser local to this file (do not import `QaPrintedInstructionRoundTripTest`'s function). Assert its `--to` equals the derived V - 1. `Artisan::call('tenant:migrate', $parameters)` **without** E4, so the command reads the shipped tree. Assert: exit 0; each mapping's `schema_version` is V - 1; each tenant's tables, read with a bare PDO, are `tenantTreeTables()` plus `migrations`; each tenant's `migrations` table lacks `entryFor(V)`. Then a plan to the top is written, run it, and each tenant is at V with `pragma foreign_key_list("settings")` showing both keys. Assert on exit codes and database state, **not** on `Artisan::output()`, because the applier's nested `migrate` calls share the console's output buffer. *Control:* with the mapping at V - 2, which is what a lower-target remedy leaves, the second plan is refused (Ruling 2; routed to `ba`). The existing round-trip test keeps its own FX-4 subject, untouched.

  **Watch the clock.** Record this file's duration in the step's commit. The expectation, from the header's measurement, is roughly 20s for AC-11. If it exceeds 30s, stop and report before going on. *Done when:* green, the AC-8 sweep's allowed list now prints the E1 file and this file, and the duration is recorded. *(AC-4, AC-5 (a), AC-9, AC-10, AC-11 stand-in half.)*

- [ ] **Step 6: the migration's behaviour, at migration level.** `SettingsUpdatedByKeyTest.php`. A private in-memory SQLite connection per case, `foreign_key_constraints` set per case, as `TenantBaselineBuildsTest` does. Apply files 1 to V - 1 of the shipped catalogue in **one** `migrate --path=[…] --realpath` call, seed, then apply V's file alone. V and the file are found from the catalogue by the migration's name (the file is this test's subject).

  - *AC-13:* the catalogue row (contract, not destructive). `tablesCreatedIn()` is empty. `SourceRules::inspect()` is empty with no exemption. Exactly one `Schema::` block, and it is `table('settings'`. No `function down`. No driver token (`getDriverName`, `->driver`). *Control:* a fixture source string carrying a driver check is reported. The docblock carries the column, D-5, the pre-check's reason and the two-run requirement. T-008's `contract_step_adds_foreign_key` and its expand twin are cited from `MigrationSourceRulesTest`, not re-tested.
  - *AC-14:* clean data, including an employee with `deleted_at` set. Also an empty `settings`, and an empty `employees` with every `updated_by` null. The key is absent before (inspection control) and present after alongside `settings.key -> settings_registry.key`.
  - *AC-15:* T-010 step 3b's five properties on a populated table with both scope kinds, each with its damaged-fixture control (an altered row, a table without `scope_id_key`, a table without the registry key), so none passes by construction.
  - *AC-16:* enforcement on: an unknown employee is refused with a foreign-key failure and the row count is unchanged; a real employee is accepted. *Control:* enforcement off, and the unknown row is accepted. The test's docblock says the production stanza is FP-305's.
  - *AC-17, migration half, both states:* dirty data. The migration throws. `settings` is byte-identical (rows plus the `sqlite_master` SQL). Only the registry key is present. No `__temp__` table remains. The message has the count and no value.
  - *AC-20:* a case-differing id fails; a soft-deleted employee's id succeeds.
  - **Ruling 7's measurement:** with the pre-check removed in a scratch copy, the dirty-data rebuild succeeds with enforcement **on** as well as off. Record the result in the commit. If it does not hold, the spec's narrower statement stands.

  *Done when:* green. *(AC-13, AC-14, AC-15, AC-16, AC-17 migration half, AC-20, AC-21.)*

- [ ] **Step 7: the migration through the runner.** `SettingsUpdatedByContractRunTest.php`. `migrateFixtureBoot()` with the real applier rebound. **Tenants at V - 1 by template:** apply files 1 to V - 1 once, in one `migrate` call, to a SQLite file under `storage/app/`. Then copy it over each `migrateFixtureTenant()` file, with the mapping at V - 1. `dev` measures the template build and keeps it under 2s; building it in memory and writing it with `VACUUM INTO` is one way. The real applier runs only V.

  - *AC-17, runner half:* one dirty tenant. Its job is `failed` with `error_migration` naming V's migration. Its mapping stays V - 1, and its own `migrations` table lacks V. D-10 is checked at every sink of Ruling 6, with the sentinel absent and the count present (liveness), and with two rows giving `2`.
  - *AC-18:* the dirty tenant alone (a named run). Capture every statement on the tenant connection, print it, and assert it is non-empty. It contains the pre-check (`select count(*)` with `not exists`) and **no** `__temp__`, `drop table "settings"` or `alter table` statement. *Liveness (shape 5):* the same probe over a clean tenant's run **does** capture the rebuild statements.
  - *AC-19:* A and C clean, B dirty, one run. A and C reach V. B stays at V - 1, failed. The run is `completed_with_failures`, and A's and C's `settings` and `migrations` are as after their own success. Then clean B and use the existing retry route (`RetryFailedJobsHandler`, or `tenant:migrate --run=<id> --retry-failed`). B reaches V, and A's and C's `migrations` tables are byte-identical to before the retry.

  *Done when:* green, with this file's duration recorded. *(AC-17 runner half, AC-18, AC-19.)*

- [ ] **Step 8: close-out, `qa`.** Mandatory mutations, each shown red and reverted:
  1. The contract guard ignores contract steps: AC-6 and AC-10 go red.
  2. AC-18 (a): delete the pre-check, and AC-17 goes red, with enforcement off and, per Ruling 7, on.
  3. AC-18 (b): move the key before the pre-check, and AC-18 goes red.
  4. A real scratch file in `packages/kernel/tests` composing FX onto `runFixtureShippedSet()` or the configuration key fails AC-8, naming it.
  5. A scratch extra contract file in the shipped tree leaves every `runFixtureSet` catalogue identical (AC-4 by hand).
  6. A scratch extra key onto `employees` reddens `TenantBaselineBuildsTest`'s inventory (AC-23).

  Then the diff checks of AC-1, AC-7, AC-12, AC-13, AC-24 and AC-25 (no file under `packages/kernel/src`; the contract-guard blocking-set tests edited only at E2), the final `composer check` with its process list, and section 11 written into the QA report as NOT VERIFIED. *(AC-24, AC-25, AC-26.)*

---

## 6. Mask shapes, from T-010 section 6, and where each bites here

- **Shape 3 (a sibling guard refuses with the same class).** The destructive and contract refusals both throw `MigrationRunRefused`. Every refusal assertion here pins the string only its own branch produces: the version and tenant for the contract guard, `schema.backups.available` for the destructive one. AC-10's positive control depends on the new migration being **non-destructive** (section 4, check (i)).
- **Shape 5 (an absent thing reports the same as an absent observer).** Zero tenant connections, no alter statement, no value in a message, and no `__temp__` table are each paired with a liveness reading in the same file (steps 3, 6 and 7).
- **Shape 6 (a valid input cannot discriminate an ordering).** "Pre-check first" is invisible on clean data, so AC-18 uses the dirty tenant and the moved-statement mutation.
- **Shape 7 (an instrument over the wrong domain).** AC-7 and AC-8 publish their directory, print their lists, state their residual, and each has an item-nobody-listed mutation. The AC-8 domain decision is Ruling 5.
- **Shape 8 (the mutation was not a mutation).** For mutation 2, confirm the scratch migration actually differs as executed: the captured statement list must lack the pre-check `select`. Otherwise a red or green result means nothing.

---

## 7. Test strategy

**Unit-level (pure functions inside the new test files):** the AC-2 baseline checks, the AC-9 derivation, and the AC-1, AC-7 and AC-8 scanners, each fed in-memory controls.

**Corpus (kernel, stand-in master only):** AC-3, AC-5, AC-6, AC-10, plus every pre-existing planner and runner case, now on the fixture baseline.

**Stand-in tenants, real applier:** AC-11 (the shipped tree end to end), AC-17 runner half, AC-18, AC-19.

**Migration level, private stand-in:** AC-13 to AC-17, AC-20.

**Diff (`qa`):** AC-1's "one product file", AC-7's superset of case names, AC-12's unchanged checksums, AC-13's "highest and last", AC-24, AC-25.

**Which tests prove which criteria** is a table in the QA report. Grades as in T-010: **independently proven** (stand-in, fails if absent) for everything except section 11; **corroborating** for AC-11's two-engine half and AC-22's stand-in half. No new test writes B, V or V - 1 as a literal.

---

## 8. Risks and rollback

| Risk | Size | Mitigation |
|---|---|---|
| **1. Gate headroom.** The baseline printed `LOW-HEADROOM`, ratio 1.91, under contamination by six foreign processes | **the one that could stop the task** | **Escalated to the lead now, labelled:** the figure is contaminated and no clean one could be taken (header). This task's net time is estimated near zero (Ruling 2: AC-11 adds about 20s, and E3 removes about 10s from `QaRealApplierOnStandInTest`), and steps 5 and 7 have stop thresholds. Every gate run carries its process list. A clean re-measure, with the other projects' runners stopped, is the lead's call |
| 2. A corpus reader or literal this plan did not measure | medium (T-010 found 17 beyond 3) | Step 1 records the red list and compares it with section 3 *before* any edit; a mismatch is a stop |
| 3. The six-file coupling to T-008-era literals | small now, latent | Stated in the README and Ruling 1; follow-up row for the lead |
| 4. AC-8's directory scope misses a reader added outside `packages/kernel/tests` | accepted, stated | Printed residual; `ba` asked to align AC-8 (section 9) |
| 5. The new host tests accidentally enter Ruling 7's sweep domain | small, measured | Section 3's trap: they never name that fixture |
| 6. Ruling 7's "both states" reading of the grammar is wrong | small | Measured in step 6; the spec's narrower text stands either way |
| 7. `schema:publish` without `--write-fixture` touches the held engine | small, severe | Section 3 names the flag as mandatory |

**Rollback.** Everything is on the branch, unpushed. No tenant database exists, so no migration has run outside a test. Steps 1 to 3 are test-tree only. Step 4's three product files revert together, and reverting them alone leaves the corpus green: that is the property this task establishes.

---

## 9. What I need `ba` to change in the spec (none blocking; I have no message channel to `ba`, so the lead routes these)

1. **AC-8.** Scope the sweep to `packages/kernel/tests` (Ruling 5). `MigrationSetTest` is the only in-scope exclusion, and the other four named exclusions are out of scope and printed as such. Add the measured host product-tree readers to the printed out-of-scope list, and state the residual. Note that AC-4's and AC-5 (a)'s controls live in the named file, because they must read the shipped tree.
2. **AC-24 E2 and E5.** E2 should name the `MigrationSet::baseline(<shipped path>)->highestVersion()` spelling (`PlanMigrationRunHandlerTest:99-100`) as the same concept as the helper. E5 should name `MigrationTreeDisciplineTest`'s file-count pin, which moves (derived) because files and tables now differ by one. AC-23's "scratch-key liveness control" does not exist as a separate case. The exact-list inventory refuses any extra key by construction, and `qa` shows it with a scratch key (step 8, mutation 6).
3. **AC-17's "on" half** is proved at migration level, and the runner-level recording at the stanza default (off). Ruling 7: AC-18 (a) likely holds in both states, pending step 6's measurement.
4. **AC-11's lower-remedy control** is decided at plan level, with the mapping at V - 2, and not by a second real-applier run (Ruling 2, with its cost).
5. **AC-2's "numbered consecutively"** means file-name sequence equals catalogue version, since the catalogue cannot have a gap.

## 10. Questions for the human

None. No escalation trigger is met: no real data, credential, payment or policy choice is involved. The gate-headroom item (section 8, risk 1) is the lead's, not the human's.

## 11. The `[ENGINE]` criteria

| Criterion | What ships now | What the engine owes |
|---|---|---|
| AC-22 | the pre-check as one driver-neutral query-builder statement (diff review and AC-13's no-driver-token check); the key added and enforced on the stand-in (AC-14, AC-16) | the key added and enforced on MySQL 8.4 and MariaDB 10.4 (a bad insert refused, a good one accepted), and the pre-check returning the same offending set on both |
| AC-11 | the printed remedy run end to end on stand-in tenants with the real applier | the same run on both engines, compared |

None is skipped, stubbed or re-worded. `qa` records each row as NOT VERIFIED with this table as the reason. The engine-only tests that still read the shipped tree are FP-311's (D-12) and are not in this table.

## 12. Messages

**`architect` → lead.** (1) The gate baseline is contaminated, with ratio 1.91 below the 2x the gate requires (header, risk 1). Please decide whether to re-measure with the other projects' runners stopped before step 5. (2) The spec items of section 9, for `ba`. (3) Backlog, after this task: FP-304 closed; FP-209 loses `settings.updated_by` and no longer waits; FP-311 stands; the follow-up row in Ruling 1 for the literal versions; `docs/database.md`'s `settings` row notes the key as shipped. The T-010 plan's hedged version figures are superseded by the catalogue (D-6).

**`architect` → `dev`.** Step 1's red-list comparison is the step that matters most. Stop on any surprise. Step 4 must not touch `packages/kernel/tests`. If it needs to, the fixture baseline has a leak, and that is a stop, not a fix.
