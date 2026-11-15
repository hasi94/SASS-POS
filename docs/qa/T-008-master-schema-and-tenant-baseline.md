# QA Report: T-008 master schema, tenant baseline and per-tenant migration runner

Branch `ai/T-008-master-schema-and-tenant-baseline`. Code tip certified: `91d0b2c` (docs since: `3f32a1f`, `5a2d7c7`, no code change), plus the QA test files listed at the end, committed on top. Engine: none was reachable or touched (see "The hold"). Stand-in: in-memory/file SQLite 3.39.2. Every duration carries its D-12 state and date (2026-10-15, WARM unless stated; no CACHE-COLD or MACHINE-COLD run was made).

## Counts, out of 68 criteria

**PASS 13 · PARTIAL 49 · NOT VERIFIED (held) 4 · FAIL 2 · out of 68.**

**This task is not verified.** No criterion was answered by a real engine, so every one of the 52 `[E]` criteria has an engine half that is NOT VERIFIED (ENGINE_HOLD, gate G-1). Of the 52: 46 are PARTIAL (their stand-in half passes, the engine half is held), 4 are NOT VERIFIED outright (AC-7, AC-8, AC-9, AC-65), and 2 are FAIL on a finding that needs no engine (AC-16, AC-49). The 3 remaining PARTIALs are non-`[E]` (AC-41, AC-63, AC-64). A green gate under the hold means "nothing failed", not "the engine criteria pass" (AC-64).

## Verdict: FAIL, and not verified

FAIL because two criteria are definitively not met by the code (AC-16 and AC-49: the behaviour does not exist, which no engine would change), because the runner cannot migrate three of the four statuses its planner accepts (BUG-1), and because the engine hold can be cleared by a shell variable (BUG-2). Independently of those, the task cannot be called verified: 49 criteria carry an engine half that has never run.

## The gate under the hold (AC-64, AC-63)

| Run | State | Result |
|---|---|---|
| `composer check` at `91d0b2c`, QA's own run (10:50 to 10:52, 2026-10-15) | WARM | **exit 0, 720 passed, 11 skipped, 3682 assertions**, test step 92.21 s, `FLEXIPOS-GATE-MARGIN ... ratio=9.74`, sentinel absent, `engine:clean` printed "on hold ... no connection was opened" |
| `composer test` with QA's files added (10:18 to 10:19) | WARM | **exit 1, 753 passed, 9 failed, 11 skipped, 3932 assertions**, 100.99 s, ratio 8.89. The 9 failures are exactly QA's nine defect reproductions below, nothing else |
| `composer lint` / `composer stan` / `composer deptrac` with QA's files | WARM | `pint` passed, PHPStan `[OK] No errors`, Deptrac `Violations 0, Errors 0` |

**11 tests skipped, every one declared** with the reason `ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold`: `MigrateTenantEngineTest` 4, `ReservedDatabaseNameGuardTest` 3, `SchemaDriftEngineTest` 2, `PerTenantLockEngineTest` 1, `ThreeTenantFixtureTest` 1. **The 11 skipped tests are not counted as evidence for any criterion in this report.** The five inverted cases (one per engine file: "refuses to reach the engine ...") all ran and passed, which is AC-63(b)'s control with the hold set.

**Only 10 of the 52 `[E]` criteria have an engine test of any kind** (AC-38, 43, 44, 49, 52, 54, 55, 61, 63's version line, 65). For the other 42 no engine test exists in the branch, skipped or otherwise. Lifting the hold will not by itself verify them; an engine matrix still has to be written. `dev`'s handover listed these as "carried forward"; this is the size of that.

AC-64 (diff): at the T-007 tip `06f97fc` there are 298 `it()`/`test()` declarations and 0 skips; at HEAD all 298 are present by name except one rename, `GateStepTimerTest` "five gate steps" to "six gate steps", whose pin was amended (`@engine:clean` added to the exact-list assertion), not loosened. `TenantDatabaseTest`'s property pin grew by two integer fields (stricter). `phpunit.xml` gained env entries only. Verdict on the diff half: PASS.

## The hold, and the incident this report carries

On 2026-10-14 a T-008 test run destroyed 16 of the owner's databases on the shared local engine (others named in the code: `dayananda_hidetest`, `demo_pos`, `flexhms_glow`), other clients' systems among them, no backup. Mechanism: `dev` proved the name guard red by stubbing `ReservedDatabaseName` to return true for every name, then ran an engine-wide `dropAllReserved()` sweep; the filter returned all 56 schemas and the sweep dropped them alphabetically until stopped at `f`. Two mitigations in the earlier plan were invalidated: killing the PHP client does not abort an in-flight `DROP DATABASE` (the server completes it), and nothing on that engine can be assumed to be used by us alone (another project was running migrations during the run). What replaced them: no sweep exists anywhere; exactly one file may issue a drop (`EngineDatabase`, enforced by `DestructiveStatementContainmentTest`); two independent prefix rules; drops by exact name only; enumeration in PHP, never SQL `LIKE`; `engine:clean` is report-only; `ENGINE_HOLD` on. G-1 (a verified non-empty dump of all 43 surviving databases on `D:`) is unmet as of the lead's check an hour before this run. I did not connect to `127.0.0.1:3306`, did not write to `E:`, and did not clear the hold.

AC-63 markers: (1) flag with the incident date beside it in `modules/host/phpunit.xml`: present. (2) skip messages name `EngineOnHold`: present; the class docblock carries the date and "sixteen", **but not the clearing condition G-1** (see BUG-9). (3) inverted tests: present, five, all pass. (4) this report. Control "hold cleared and engine stopped, an engine test fails": NOT VERIFIED (needs the hold cleared). **The hold itself is clearable from a shell, see BUG-2.**

## Acceptance criteria coverage

Legend. **PASS**: non-`[E]`, run, control seen. **PARTIAL**: a stand-in half or static half passes, named in the row; for `[E]` rows the engine half is NOT VERIFIED (ENGINE_HOLD, G-1). **NOT VERIFIED**: no passing non-engine evidence, engine half held. **FAIL**: defect, with BUG number. "Stand-in" means SQLite; it is never evidence of an engine answer. "QA:" marks a test file QA added in this run. No `[E]` criterion was verified on any engine and none is recorded as verified on MariaDB 10.4 or MySQL 8.4.

| AC | Test(s) | Result |
|----|---------|--------|
| 1 [E] | `MasterSchemaBuildsTest` applies the tree, exact table equality, second run no-op; `MigrationTreeDisciplineTest`; QA independent recount: 25 live = 17 application + 8 system, none extra, none missing | PARTIAL. Engine half NOT VERIFIED (ENGINE_HOLD, G-1) |
| 2 [E] | QA: `QaMasterStandInShapeTest` (columns present, no `password`/`remember_token`) | PARTIAL. `CHAR(26) ascii_bin` NOT VERIFIED (held) |
| 3 [E] | QA: `QaMasterStandInShapeTest` (all 29 `tenant_databases` columns, exact set) | PARTIAL. `BIGINT`/`VARCHAR(255)` types NOT VERIFIED (held) |
| 4 [E] | QA: `QaMasterStandInShapeTest` (columns, FK `server_id`) | PARTIAL. Types and default NOT VERIFIED (held) |
| 5 [E] | QA: `QaMasterStandInShapeTest` (indexes; 2nd mapping per tenant refused; same name on two servers accepted) | PARTIAL. Engine half NOT VERIFIED (held) |
| 6 [E] | `MasterSchemaBuildsTest` type-based detector, planted-column control | PARTIAL. Engine half NOT VERIFIED (held) |
| 7 [E] | none passing; FK declarations seen in QA `QaMasterStandInShapeTest` are declarations only | NOT VERIFIED (ENGINE_HOLD, G-1) |
| 8 [E] | none (unique keys declared; refusal incl. case-insensitive email is the engine's) | NOT VERIFIED (ENGINE_HOLD, G-1) |
| 9 [E] | none (keys declared; refusals are the engine's) | NOT VERIFIED (ENGINE_HOLD, G-1) |
| 10 [E] | QA: `QaMasterStandInShapeTest` (auto-increment PK, both indexes) | PARTIAL. JSON round trip and invalid-JSON refusal NOT VERIFIED (held) |
| 11 [E] | `MasterSchemaBuildsTest` "leaves every application table empty" (control: a row is counted) | PARTIAL. Engine half NOT VERIFIED (held) |
| 12 [E] | `MasterTenantDatabaseRepositoryTest` (shipped config, fields, three nulls) | PARTIAL. Real column types NOT VERIFIED (held) |
| 13 [E] | QA: `QaMappingRecordSerialisationTest` (six functions after the secret is resolved; control with `__toString` reports in all seven outputs). No shipped test covered this | PARTIAL. Engine half NOT VERIFIED (held) |
| 14 | `CachingTenantDatabaseRepositoryTest` (same tenant twice, 299 s vs 301 s, key `tenant:conn:<id>`, version inside) | PASS |
| 15 | `CachingTenantDatabaseRepositoryTest` plus QA: `QaCredentialNotCachedAfterOpenTest` (precondition: connection really opened with the password in the stanza and the mapping cached; control planted) | PASS. The shipped case alone never resolved a secret; QA added the precondition |
| 16 [E] | none. `Tenancy::enter()` has one attempt and no evict/re-read; QA: `QaCredentialRotationRetryWiringTest` is red | **FAIL, BUG-4.** Engine half also NOT VERIFIED (held) |
| 17 | `CachingTenantDatabaseRepositoryTest` (evict one tenant, others stay cached) | PASS |
| 18 | `TenantMigrationTargetTest`, `TenantDatabaseTest` property pin, control: runner's type passes the opposite check | PASS |
| 19 | `TenancyServingVersionGateTest`, `TenancyVersionFloorStatesTest`, `TenantSchemaBehindRenderingTest` (below/at/above, explicit 0 served, absent refused 503 without `Retry-After`, migration purpose exempt, no connection opened) | PASS (stand-in suffices, D-40) |
| 20 | `TenancyServingVersionGateTest` (exactly one warning across four queries) | PASS |
| 21 | `SchemaCataloguePublishTest` (`.env.example` = 6 = catalogue highest; scratch tree one higher and one lower both red; labelled arithmetic kept as documentation) | PASS |
| 22 [E] | `MigrationTreeDisciplineTest`, `TenantBaselineBuildsTest`, config path not auto-discovered | PARTIAL. Engine half NOT VERIFIED (held) |
| 23 [E] | `TenantBaselineBuildsTest` (exact six + `migrations`, `schema_meta` empty); QA `QaTenantBaselineStandInShapeTest` | PARTIAL. Engine half NOT VERIFIED (held) |
| 24 [E] | `MigrationSourceRulesTest` source half with control; `TenantBaselineBuildsTest` live-shape half on stand-in | PARTIAL. Live engine half NOT VERIFIED (held) |
| 25 [E] | `TenantBaselineBuildsTest` (sentinel refuses duplicate company key; different key accepted) | PARTIAL. Engine half NOT VERIFIED (held) |
| 26 [E] | `TenantBaselineBuildsTest` | PARTIAL. Engine half NOT VERIFIED (held) |
| 27 [E] | QA: `QaTenantBaselineStandInShapeTest` (exact 15 columns, no claim mark, indexes, defaults pending/0) | PARTIAL. `DATETIME(3)` readback, JSON NOT VERIFIED (held) |
| 28 [E] | `TenantBaselineBuildsTest` "business row and its event in one transaction, or neither" | PARTIAL. Non-transactional-engine control and engine half NOT VERIFIED (held) |
| 29 [E] | QA: `QaTenantBaselineStandInShapeTest` (indexes, auto-increment) | PARTIAL. JSON NOT VERIFIED (held) |
| 30 [E] | `TenantBaselineBuildsTest` (no FK to `branches`/`employees`, four columns nullable, unknown key refused); (diff) docblock read, names the four columns and their owners | PARTIAL. Engine half NOT VERIFIED (held) |
| 31 [E] | `SchemaCataloguePublishTest`, `PublishCatalogueHandlerTest` | PARTIAL. Engine half NOT VERIFIED (held) |
| 32 [E] | `PlanMigrationRunHandlerTest` (runner refuses unpublished/changed set: zero job rows, zero tenant connections) | PARTIAL. Engine half NOT VERIFIED (held) |
| 33 | `MigrationSourceRulesTest`, `MigrationHelperAndImportRulesTest`, `FixtureMigrationSetsTest` (FX-6 reported, FX-4 not) | PASS |
| 34 | same files; (branch) check committed in `e749aea`, before the first shipped migration `38e9537` | PASS for the shipped tree. **Rule can be evaded by one natural spelling, BUG-5** |
| 35 | `MigrationSourceRulesTest`, `MigrationHelperAndImportRulesTest` (a to e, one control each, helper not reported, `ascii_bin` outside helper reported) | PASS. Raw-SQL `timestamp` not detected, BUG-6 |
| 36 [E] | QA: `QaDatetimePrecisionSourceTest` (every `dateTime` in both shipped trees declares precision 3, control) | PARTIAL. InnoDB, utf8mb4, `ascii_bin` NOT VERIFIED (held) |
| 37 | `MigrationSourceRulesTest`, `FixtureMigrationSetsTest`, `MigrationTreeDisciplineTest` | PASS |
| 38 [E] | QA: `QaRealApplierOnStandInTest` (shipped `ArtisanTenantMigrationApplier` run for real on two SQLite tenants; FX-1 table exists in both); `MigrateTenantEngineTest` skipped | PARTIAL. C, and the engine, NOT VERIFIED (held) |
| 39 [E] | `PlanMigrationRunHandlerTest` (one run, one job per tenant, all pending, zero tenant connections) | PARTIAL. FX-1 `up()` reading the job table NOT VERIFIED (held) |
| 40 [E] | `PlanMigrationRunHandlerTest`, `ThreeTenantFixtureMappingTest` (active/read_only below target; archived silently omitted; ascending size) | PARTIAL. Engine half NOT VERIFIED (held). **Planned `read_only` tenants cannot then be migrated, BUG-1** |
| 41 | `MigrateTenantQueueJobTest` (identifiers present, no credential-shaped token, `migrations` queue, control planted) | PARTIAL. The "when the work is dispatched" step has no code path, BUG-8 |
| 42 [E] | `RunTenantMigrationsHandlerTest` (A's then B's database name; `NoTenantResolved` after) | PARTIAL. Engine half NOT VERIFIED (held) |
| 43 [E] | `MigrateTenantHandlerTest`; QA `QaRealApplierOnStandInTest` reads the tenant's own `migrations` table (7 rows) | PARTIAL. Engine half NOT VERIFIED (held) |
| 44 [E] | `RunTenantMigrationsHandlerTest` (A, B done; C failed with class/message/migration; version unchanged; exit 1; all-green exit 0) | PARTIAL. Engine half NOT VERIFIED (held) |
| 45 [E] | `MigrateTenantHandlerTest` `MigrationErrorText` sanitiser with leak-scan control | PARTIAL. Rejected credential and unreachable host NOT VERIFIED (held) |
| 46 [E] | `RetryFailedJobsHandlerTest`, `ClaimMigrationJobHandlerTest` (cap), QA real-applier re-run leaves both `migrations` tables identical by row and batch | PARTIAL. Engine half NOT VERIFIED (held). An offline failure leaves `failed`, which cannot be retried, BUG-1 |
| 47 [E] | `ClaimMigrationJobHandlerTest` (live lease refused, expired lease retaken, done not re-applied) | PARTIAL. Master here is SQLite; NOT VERIFIED on the engine (held) |
| 48 [E] | `ClaimMigrationJobHandlerTest`: the statement pin, quoted below, and mutation-checked by QA | PARTIAL. Two real workers NOT VERIFIED (held) |
| 49 [E] | `PerTenantLockTest` (name only), `PerTenantLockEngineTest` skipped. QA: `QaPerTenantLockWiringTest` red: nothing calls `PerTenantLock::acquire()` | **FAIL, BUG-3.** Engine half also NOT VERIFIED (held). Stays NOT VERIFIED as a behaviour; not made green |
| 50 [E] | `ClaimMigrationJobHandlerTest`, `ThreeTenantFixtureMappingTest` (cap of 1, other server claimable) | PARTIAL. Three real workers NOT VERIFIED (held) |
| 51 [E] | `PlanMigrationRunHandlerTest`, `TenancyServingVersionGateTest` (bulk omits silently; named accepts four, refuses the rest naming the status) | PARTIAL. Engine half NOT VERIFIED (held). **Accepted `provisioning`/`failed` cannot then be entered, BUG-1** |
| 52 [E] | `MigrateTenantHandlerTest` (enter on `active` first, then `migrating` inside the scope; `failed` after an offline failure; ordinary expand writes no status) | PARTIAL. FX-3 observing from inside `up()` NOT VERIFIED (held) |
| 53 [E] | (a) `MigrateTenantHandlerTest` incl. the mask control; (b) QA: `QaMigratingMappingRefusedForServingTest` (the unasserted half, closed by QA's file, **not** by the dataset row the spec says `dev` adds to `TenancyResolutionTest`, which is still absent) | PARTIAL. Literal in-run form is out of this task; `[E]` NOT VERIFIED (held). Half (a) alone is not reported as passing |
| 54 [E] | `RepairSchemaVersionHandlerTest` (mapping corrected, nothing re-applied) | PARTIAL. Engine half NOT VERIFIED (held) |
| 55 [E] | `DetectSchemaDriftHandlerTest`, `RepairSchemaVersionHandlerTest` | PARTIAL. Engine half NOT VERIFIED (held) |
| 56 [E] | `MigrateTenantHandlerTest` (mismatch refused, nothing altered; matching, absent row and absent table proceed) | PARTIAL. Engine half NOT VERIFIED (held). Interlock proper not delivered (D-28) |
| 57 [E] | `ReportSchemaStatusHandlerTest`, `TenantSchemaBehindRenderingTest` | PARTIAL. Engine half NOT VERIFIED (held) |
| 58 [E] | `PlanMigrationRunHandlerTest` (bulk, provisioning not blocking, strict multi-expand case, message names `--to=6`); QA: `QaContractGuardNamedRunTest` (named run, left-out tenant blocks, control) | PARTIAL. Engine half NOT VERIFIED (held). Blocking set narrower than the criterion, BUG-7 |
| 59 [E] | `PlanMigrationRunHandlerTest` (plan-time refusal, zero jobs, zero connections, FX-4 not refused, key true plans FX-5) | PARTIAL. Engine half NOT VERIFIED (held) |
| 60 [E] | `TenantMigrationTargetTest` (record connects as the migrate pair) | PARTIAL. DDL refused for the app credential NOT VERIFIED (held) |
| 61 [E] | `ReservedDatabaseNameGuardTest`: thirteen forbidden names refused, positive controls, no `LIKE` scan with fixture and prose control, `DestructiveStatementContainmentTest` | PARTIAL. Create/drop/leave-nothing-behind and non-prefixed databases unchanged NOT VERIFIED (held) |
| 62 [E] | QA: `QaSchemaSignatureInstrumentTest` (the reduction, normalisation listed, equal signatures accepted, extra-index control; prints `SIGNATURE-INSTRUMENT engine=sqlite version=3.39.2`). Nothing existed before, BUG-10 | PARTIAL. MariaDB signatures NOT VERIFIED (held); MySQL half deferred (D-24) |
| 63 | markers 1 to 3 read; 5 inverted tests pass; 11 declared skips | PARTIAL. Marker 2 incomplete (BUG-9); hold clearable from the shell (BUG-2); the cleared-hold control NOT VERIFIED |
| 64 | gate exit 0, 720 passed, 11 skipped, 3682 assertions at `91d0b2c`; T-007 declarations all present | PARTIAL. Engine-dependent part NOT VERIFIED (held) |
| 65 [E] | none passing. Lock behaviour cannot be exercised, and the runner does not take the lock (BUG-3) | NOT VERIFIED (ENGINE_HOLD, G-1) |
| 66 | this report's calibration table | PASS |
| 67 | this report's calibration table; "before" is T-007 QA's recorded measurement, not re-measured today (see notes) | PASS |
| 68 [E] | QA: `QaStrictModeConfiguredTest` (master stanza `strict` true; tenant stanza Tenancy writes `strict` true; sentinel has none) | PARTIAL. `@sql_mode` and the refused over-long value NOT VERIFIED (held) |

## Test run summary

- Commands: `composer check` (QA run, `91d0b2c`); `composer test`, `composer lint`, `composer stan`, `composer deptrac` (with QA files); `vendor/bin/pest <file>` for single files; mutation by prepended autoloader (see AC-48). All from `modules/host`, PHP `E:\xampp\php\php.exe`.
- Certified tree `91d0b2c`: 720 passed, 11 skipped, 0 failed, 3682 assertions, exit 0. With QA files: 753 passed, 9 failed (QA's defect reproductions), 11 skipped, 3932 assertions.
- QA added 42 cases in 15 files: 33 pass, 9 fail by design. One of my first drafts tripped the project's own `QaAssertionShapeGuardTest` (a two-argument negated `toContain`); it caught it, I fixed my file.

## The four specific checks

**1. The statement pin (AC-48), quoted, not counted.** `packages/kernel/tests/Schema/Features/ClaimMigrationJob/ClaimMigrationJobHandlerTest.php`, inside the case "grants the claim with exactly one update statement, so the read grants nothing" (line 149), lines 182 to 186:

```php
$where = substr($updates[0], (int) strpos($updates[0], ' where '));

expect($where)->toContain('"attempts" < ?')
    ->and($where)->toContain('"status" = ?')
    ->and($where)->toContain('"lock_expires_at" <= ?');
```

It looks like duplication beside the seven behavioural cases and it is the only evidence of the re-check. QA mutated the production `claim()` statement (copy served by a prepended autoloader, `MUTANT LOADED` printed, byte-identical control first: 7 passed). (m1) both `attempts` and the status closure removed from the `UPDATE` only: **1 failed (this pin), 6 passed**. (m2) only the status closure removed: **1 failed (this pin), 6 passed**. (m3) `attempts <` changed to `<=` in the `UPDATE` only: **1 failed (this pin), 6 passed**. Deleting the pin would leave the file green under all three. Limits: the strings are SQLite's quoting (`"x"`), which is correct while the claim runs on the suite master and would need the backtick form if this assertion is ever pointed at the engine; and the sibling pin on the completing statement in `MigrateTenantHandlerTest` ("completes the job with one statement whose where re-checks the status and the holder") was read, not mutated. Do not delete either.

**2. The master recount.** Independent: `migrate --database=master` on a fresh SQLite master gives **25 live tables = 17 application + 8 system, none extra, none missing**. Created by our files: **24** (19 migration files: the 3 stock files, one of them rewritten, make 8 tables including `users`; the 16 new files make 16): 17 application (`users` rewritten in place plus 16 new) and 7 of AC-1's 8 system tables; the eighth, `migrations`, is the framework's. The split turns on `users` counting as application, as `ba` said. Design recount: `docs/database.md` section 4 yields **38 table rows plus `tenant_databases` = 39 application tables** by a mechanical extraction of the table names (not 34, section 4.10's figure), so the task builds 17 of 39 by deliberate deferral, and AC-1 governs. Not a defect.

**3. The renamed case.** "serves every tenant when no version floor is configured" (added `23b3beb`, renamed `cf685e2`, absent at HEAD: `git log -S` and `git grep` on HEAD) is now "serves every tenant when the floor is explicitly zero" in `TenancyServingVersionGateTest.php:156`, with the absent state tested separately in `TenancyVersionFloorStatesTest.php` (which also has "serves a tenant at schema version 0 when the floor is explicitly zero", line 181). Both states have a test; an earlier report's old name is not a missing case.

**4. The lock criteria.** AC-49 and AC-65 were not made green. No single-process test of the lock was written. What QA found instead is that the lock is not wired at all (BUG-3); the red wiring check passes the day anything calls `acquire()` and says nothing about correctness.

## Defects

Hand-off format `BUG-n | AC-x | failing test | expected | actual`. SendMessage is disabled in this session, so the lead dispatches. Severity counts: **critical 0, major 5, minor 5.**

### BUG-1 (major)
`BUG-1 | AC-40, AC-51, AC-46, AC-52 | packages/kernel/tests/Schema/Features/MigrateTenant/QaPlannedStatusReachabilityTest.php (3 red dataset rows: read_only, provisioning, failed; the active row is the control) | expected: a tenant in any status the planner accepts (`active`, `read_only` in a bulk run; also `provisioning`, `failed` when named) is migrated by the job planned for it | actual: the job fails with "cannot be served: its database mapping status is [read_only], not [active]"`.
Suspected location: `Tenancy::enter()` step 4 refuses every status but `active` for the Migrating purpose too (`TenantMigrationTarget::isActive()`), while `PlanMigrationRunHandler::BULK_STATUSES` and `NAMED_ONLY_STATUSES` plan the other three. Consequences: a bulk run plans `read_only` tenants that always fail; FP-206's `migrate_remainder` on a `provisioning` tenant can never run; and a tenant whose offline migration failed is set to `failed` (D-12) and then cannot be retried by `tenant:migrate --retry`, because retry re-enters the scope. No test uses any status but `active` in the execution path.

### BUG-2 (major, safety)
`BUG-2 | AC-63(b) | modules/host/tests/Feature/QaEngineHoldIsNotClearableFromTheShellTest.php (1 red case) | expected: the hold in phpunit.xml cannot be overridden by the shell | actual: `ENGINE_HOLD=0` or `off` in the shell makes `EngineConnection::onHold()` false`.
Measured with a read-only probe test against the real `phpunit.xml`: ambient unset gives `true`; `ENGINE_HOLD=0` gives `false`, `$_SERVER` `'0'`; `ENGINE_HOLD=off` gives `false`. PHPUnit's `<env>` never overwrites an existing variable without `force="true"`. Measured further against a scratch copy with `force="true"` on `<env>` alone: **still `false`**, because `EngineConnection::environment()` reads `$_SERVER` before `$_ENV`/`getenv()` and `<env>` does not set `$_SERVER`. Fix needs `force="true"` on both an `<env>` and a `<server>` entry for `ENGINE_HOLD`, or a reader that does not prefer the ambient value. `ENGINE_HOLD=0 composer test` would also make the engine tests connect to `127.0.0.1:3306`; `tools/clean-engine.php` reads the same variable and would open a PDO to the engine as the first gate step. The same precedence trap is already documented in `MasterSchemaBuildsTest` for `DB_MASTER_DRIVER` and guarded there.

### BUG-3 (major)
`BUG-3 | AC-49 (and AC-65's lock half) | packages/kernel/tests/Schema/Support/QaPerTenantLockWiringTest.php (1 red case) | expected: the runner takes the per-tenant lock and reports a locked tenant as not run | actual: `PerTenantLock::acquire()` has no caller in `packages/kernel/src`; `MigrateTenantHandler` and `RunTenantMigrationsHandler` never lock and have no "locked" outcome`.
`PerTenantLock` is a correct class with a unit test of its name and its refusal on a driver without `GET_LOCK`, and `PerTenantLockEngineTest` calls it directly. Two runners can enter one tenant.

### BUG-4 (major)
`BUG-4 | AC-16 | packages/kernel/tests/Feature/QaCredentialRotationRetryWiringTest.php (1 red case) | expected: on a rejected cached credential the tenant's cached entries are dropped, the mapping re-read and the connection retried exactly once; a second failure surfaces `TenantConnectionFailed` after exactly two attempts | actual: `Tenancy::enter()` makes one attempt; nothing under `packages/kernel/src/Tenancy` evicts or re-reads`.
The only `evict()` callers are the runner's handlers (D-19). The backlog's "carried into FP-203" item for this path was not built, and the plan's mutation-input note for AC-16 had no step behind it. Wiring check only; a behavioural test needs an authentication failure the stand-in cannot produce.

### BUG-5 (major)
`BUG-5 | AC-34 | modules/host/tests/Feature/QaSourceRuleEvasionShapesTest.php, case "reports a foreign key added to an existing table through foreignId and references alone" | expected: reported | actual: `[]``.
`$table->foreignUlid('updated_by')->references('id')->on('employees')` inside `Schema::table()` in an expand migration creates the constraint (`ForeignIdColumnDefinition::references()` calls `Blueprint::foreign()`; verified in the vendored source) but `SourceRules::FOREIGN_KEY_CALLS` matches only `->foreign(`, `->constrained(` and `add foreign key`. This is the spelling D-5's four deferred keys will most naturally take; the whole defence of D-5 is that the rule forces them into a contract step. The shipped trees are clean (PASS on the tree); the checker is not complete. Control in the same file: the `->foreign(` and `->constrained(` shapes are reported.

### BUG-6 (minor)
`BUG-6 | AC-24 (source half), AC-35(c) | modules/host/tests/Feature/QaSourceRuleEvasionShapesTest.php, cases "reports a tenant_id column added by a raw statement" and "reports a timestamp column declared in raw SQL" | expected: reported | actual: `[]``.
`DB::statement('alter table settings add column tenant_id char(26) null')` and `... add column seen_at timestamp null` pass: the `tenant_id` rule needs the quoted literal alone, the `timestamp` rule needs `->timestamp(`. Controls (the builder shapes) are reported. Live `information_schema` checks would catch both once the engine runs.

### BUG-7 (minor)
`BUG-7 | AC-58 (D-46) | no red test written; probe: bulk run with an `active` tenant at the version before the contract step and a second tenant at 0 in status suspended, failed, migrating, moving, restoring or archived | expected: refused, the blocking set being "the whole fleet the master holds" with only `provisioning` excluded | actual: planned in all six cases`.
`PlanMigrationRunHandler::tenantsBelow()` reads `whereIn('status', BULK_STATUSES)` (`active`, `read_only`). The spec and D-45 say the inference errs safe, refusing more and never fewer. Latent: no shipped contract migration exists. The named-run half is correct, and was untested until QA's `QaContractGuardNamedRunTest`.

### BUG-8 (minor)
`BUG-8 | AC-41 | none | expected: "when the work is dispatched, each tenant is one queue job on the migrations queue" | actual: `MigrateTenantQueueJob` is never dispatched by production code; `tenant:migrate` executes jobs in-process (`--concurrency` is only recorded on the run row)`.
The payload and queue name are correct and tested; the dispatch step the criterion starts from has no code path.

### BUG-9 (minor)
`BUG-9 | AC-63 marker 2 | none | expected: the `EngineOnHold` docblock carries the date, the sixteen databases and the single clearing condition (gate G-1: a verified dump of all surviving databases on `D:`) | actual: date and "sixteen" present; the stated condition is "until the owner has decided whether file-level recovery will be attempted"; "G-1" appears in no skip message and not in the class`.
A reader of any skip line cannot find the condition.

### BUG-10 (minor, closed by QA's file)
`BUG-10 | AC-62 | modules/host/tests/Feature/QaSchemaSignatureInstrumentTest.php | expected: the signature reduction, comparison and extra-index control exist and run | actual: nothing existed in the branch although the plan said it was built`.
QA built it (it takes any `Connection`, so the engine run reuses it). The engine run is NOT VERIFIED.

## Regression risks and notes

- **Existing masters keep the old `users`.** `0001_01_01_000000_create_users_table` was rewritten in place (D-18). Any master database that already ran the stock migration has it recorded in `migrations`, so `migrate` is a no-op there and the integer-id `users` table stays. AC-1 starts from empty; nothing warns about the rest.
- **Gate cost.** QA's real-applier case runs the shipped migrator one file per call on SQLite and costs 6.5 s (fsync per statement); the other 41 cases cost about 2 s together. Test step 92.21 s (tip) to 100.99 s (with QA files), ratio 9.74 to 8.89.
- **AC-53 half (b)** is asserted only by a QA file; the dataset row in the T-007 `TenancyResolutionTest.php` that the spec assigns to `dev` is not there.
- **Comments name a banned collation.** `Tenancy.php`, `EngineConnection.php`, `config/database.php` and `SourceRules` docblocks mention `utf8mb4_0900_ai_ci`; the rule reads code with comments stripped, so this passes. AC-35(d) says "names" and a literal reader would see four hits.
- The two-argument negated assertion trap exists in this repo's guard (`QaAssertionShapeGuardTest`); it works.
- QA's attempt to re-measure the gate at the T-007 tip in a scratch worktree (`composer install` there, then `composer test` twice) was **invalid** (10 failed, 423 warnings from path-dependent tests in a deep scratch path) and is discarded; no figure from it is used.

## Calibration (AC-66)

| Item | Count | Command / source |
|---|---|---|
| Master tables created by our files | 24 (17 application + 7 system); 25 live on the stand-in | `grep Schema::create` over `modules/host/database/migrations`; QA probe `migrate --database=master` |
| Tenant tables created | 6 (+ framework `migrations` = 7 live) | same, `migrations-tenant`; QA signature test |
| Migration files added / changed | master 16 added, 1 rewritten (`users`); tenant 6 added; 1 catalogue fixture JSON | `git diff --name-status 06f97fc 91d0b2c` |
| Production source files | added 73 (kernel 63, testing 7, host config/tools/catalogue 3), changed 10 (kernel 4, config 1, bootstrap 2, composer.json, .env.example, phpunit.xml) | same |
| Test files added | 31 test files, 39 fixture/support files, 6 test-tree files changed; QA added 15 more | same |
| Tests and assertions | T-007 tip 463 passed / 2403 assertions; tip `91d0b2c` 731 cases (720 + 11 skipped) / 3682 (**+268 / +1279**); with QA files 773 (753 + 9 + 11) / 3932 | `composer test` |
| `[E]` criteria and engines | 52 (`grep` recount: ba's 52 confirmed); engines run on: **none** | spec grep; this report |
| Commits | 40 since `06f97fc` (16 touch code, 18 touch `docs/`, 7 are dev memory) | `git log 06f97fc..HEAD` |

## Calibration (AC-67)

- QA rounds: **1** (this one). Defects by severity: critical 0, major 5, minor 5. Review rounds: **0** (no `docs/reviews/T-008*` exists).
- Gate wall time. **Before** (T-007 tip, QA run recorded 2026-10-07): `composer check` 156.27 s `[CACHE-COLD]`; test step 96.39 s `[CACHE-COLD]` (ratio 9.34) and 102.33 s `[WARM]`. **After** (this run, 2026-10-15): `composer check` about 104 s wall `[WARM]` (10:50:15 to 10:51:59); test step 92.21 s `[WARM]` (ratio 9.74); with QA files test step 100.99 s `[WARM]` (ratio 8.89). The "before" is a measurement from eight days ago on the same machine, not re-run today. No cold run was made for "after". No figure is a pass threshold.
- Criteria found unachievable as worded, with the replacement (from the decisions file): AC-13 (string cast of an object with no `__toString`: six functions, string cast on the control only, D-26); AC-19 (version gate would refuse the runner: serving purpose only, D-23); AC-35(d) (collation ban vs `ascii_bin`: one exempt helper, D-22); AC-48 (read-then-write: one granting statement plus the pin, D-25, D-47); "on both engines" in AC-10/27/29/62/65 (one engine, D-24); AC-1 table list (`sessions`, not `personal_access_tokens`, D-27); AC-56 (interlock not delivered, D-28); AC-21's control (equality both directions, D-42); AC-40 vs AC-51 (bulk filters, named refuses, D-48); AC-53's literal form (split into halves, D-49); AC-19/20 `[E]` marker dropped (D-40); AC-35(a) tightened to precision and scale (D-38); AC-52 order stated (D-50).

## QA files

Green (28 cases): `modules/host/tests/Feature/` `QaMasterStandInShapeTest`, `QaTenantBaselineStandInShapeTest`, `QaSchemaSignatureInstrumentTest`, `QaDatetimePrecisionSourceTest`; `packages/kernel/tests/Feature/` `QaCredentialNotCachedAfterOpenTest`, `QaMappingRecordSerialisationTest`, `QaStrictModeConfiguredTest`, `QaMigratingMappingRefusedForServingTest`; `packages/kernel/tests/Schema/Features/PlanMigrationRun/QaContractGuardNamedRunTest`; `.../RunTenantMigrations/QaRealApplierOnStandInTest`.
Red by design, one defect each (9 cases fail): `QaPlannedStatusReachabilityTest` (BUG-1), `QaEngineHoldIsNotClearableFromTheShellTest` (BUG-2), `QaPerTenantLockWiringTest` (BUG-3), `QaCredentialRotationRetryWiringTest` (BUG-4), `QaSourceRuleEvasionShapesTest` (BUG-5, BUG-6). They turn green when `dev` fixes the defect and must not be skipped or weakened.

Run 1: FAIL (not verified).

---

# Round 2 (2026-10-15, tip 63c635f)

## Verdict: FAIL on a technicality of one open minor, NOT VERIFIED as a task

Round-2 line, same method as round 1: **PASS 13 · PARTIAL 49 · NOT VERIFIED (held) 6 · FAIL 0 · out of 68** (13+49+6+0=68).

- AC-16 and AC-49 move FAIL to NOT VERIFIED: the wiring now exists (BUG-3, BUG-4 closed), so there is no longer a finding that needs no engine. They are **not** PASS and **not** PARTIAL-by-wiring. The advisory lock is re-entrant inside one session, so a single-session test cannot show exclusion (AC-49, AC-65), and the credential half of AC-16 needs an authentication failure SQLite cannot produce. **Wiring did not make them pass; an engine will not either, without a two-session and a real-auth-failure test nobody has written.**
- AC-7, AC-8, AC-9, AC-65 unchanged NOT VERIFIED. AC-51, AC-52, AC-58 stay `[E]`, NOT VERIFIED on the engine half; what landed is their stand-in half (PARTIAL).
- **Planning number, unchanged: only 10 of the 52 `[E]` criteria have an engine test at all.** Clearing G-1 runs 10 skipped tests; the other 42 need a matrix nobody has written. The dump does not finish the task.
- Step 13d (reachability check) is **not built**; not recorded as covered.
- A green gate under the hold means nothing failed, not that engine criteria pass.

## Gate (D-12 state: warm, 2026-10-15, my own run)
`composer check` exit 0: 818 passed, 11 skipped, 4258 assertions, matches the lead's figure. Pest step 100.56s, whole check 1m50s, ratio 8.92 against the 900s limit. `clean-engine` printed "nothing was read and no connection was opened". No connection to MySQL by me; nothing written to E:. Working tree clean after every mutation (restored with git checkout, verified by git status).

## BUG status
| BUG | Result | Evidence I produced |
|---|---|---|
| 1 | CLOSED | `permits()` is the one authority; mutating the planner away from it fails 3 tests when the authority moves (see attack 1) |
| 2 | CLOSED | `onHold()` returns `EngineOnHold::HELD`, a constant. `ENGINE_HOLD=0` in the shell: the two hold tests still pass |
| 3 | CLOSED | Mutant: `takeLock` returns true without acquiring: 4 failed (3 behavioural + wiring scan) |
| 4 | CLOSED | Mutant no-evict: 2 failed. Mutant no-retry (rethrow): 2 failed |
| 5, 6 | CLOSED | gate green; rules not narrowed (not re-mutated) |
| 7 | CLOSED | Mutant: blocking = `bulkStatuses()`: 8 failed (7 dataset rows + the "two different rules" case) |
| 8, 9 | CLOSED | dispatch has a caller (`RunTenantMigrationsHandler::dispatch`, `TenantMigrateCommand`); hold class names G-1 and its clearing condition |
| 10 | CLOSED (round 1) | |
| restore rule | CLOSED, signature reproduced | below |

### Attack 2: restore rule control signature (reproduced)
Mutant: `restoredStatus()` body replaced by `return self::TENANT_ACTIVE;` (the literal). Result: **2 failed, 2 passed**: (a) read_only and (b) provisioning FAIL; (c) active and (d) failed-to-active PASS. Exactly the designed signature, so the test is specific to the promotion defect. Restored; clean.

### Attack 1: derived-set proof (partially broken)
- Mutant A: planner `NAMED_ACCEPTED` replaced by a second literal copy `["active","read_only","provisioning","failed"]`. **All 11 relevant tests pass.** Equality passes (same values); the source scan passes because it searches the single-quoted needle `'active'`, so a double-quoted spelling or a concatenation evades it. This is the hole `dev` described, constructed.
- Mutant B: A plus `suspended` added to the authority. **3 fail** (equality, the authority-driven plan dataset, and the `suspended` refusal row). So a second copy is caught the moment the authority moves, which is the case that matters. What is NOT caught is the copy sitting there agreeing today.
- Incidental guard: Pint fails the double-quote form (`single_quote`) and removing the only use of the import (`no_unused_imports`); the concatenation form with the import kept was not isolated cleanly (other fixers also fired on my sed-edited file, so I do not claim lint is a reliable second tooth).

### BUG-11 (minor)
- Test: `PlannedStatusAuthorityTest` "writes no accepted status of its own anywhere in the planner source".
- Expected: a second literal list of the accepted statuses in the planner fails the scan.
- Actual: passes for `"active"` (double quotes) or `'act'.'ive'`; needle is `"'".$status."'"`.
- Fix suggestion (not mine to edit): scan with a quote-agnostic regex (`/['"]active['"]/`) and also fail on any array literal of two or more of the statuses. Low severity because drift is caught by the dataset tooth (Mutant B).

## QA files this round
None added; mutation only, all restored.

Run 2: FAIL (BUG-11 minor open) -> task still NOT VERIFIED.

---

# Round 3 (2026-10-15, tip eb2f10f)

## Verdict: FAIL (one new minor, BUG-12), NOT VERIFIED as a task

Round-3 line, same method: **PASS 13 · PARTIAL 49 · NOT VERIFIED (held) 6 · FAIL 0 · out of 68** (13+49+6+0=68). Nothing moved: steps 13e and 13f are guard-rails and a terminal-state rule, not engine criteria. **Only 10 of the 52 `[E]` criteria have an engine test; clearing G-1 verifies 10, the other 42 need a matrix nobody has written.** Step 13d is deferred to FP-197 and is not recorded as covered. A green gate under the hold means nothing failed, not that engine criteria pass. No MySQL connection by me, nothing written to E:.

## Gate (D-12 state: warm, 2026-10-15, my own run, tip eb2f10f)
`composer check`: 832 passed, 11 skipped, 4368 assertions, no failures, test step 103.90 s, ratio 8.64 against 900 s. Matches the lead. With my one new file: `composer test` = 1 failed (BUG-12, red by design), 11 skipped, 844 passed, 4444 assertions, 102.63 s `[WARM]`. `composer lint` and `composer stan` pass with the new file.

## Step 13e, measured by me
- **Mutant A reproduces exactly as `dev` reports.** Planner's accepted list replaced by a second literal copy `["active","read_only","provisioning","failed"]`: **3 failed, 38 passed**, all three in `TenantStatusPolicyTest` (plans and admits a moved status; reports the moved list; narrows the authority). `PlannedStatusAuthorityTest` stays green. Control first: 130 passed on the clean tip. So the scan was blind to it and the moved key is not. BUG-11 is CLOSED by deleting the scan, correctly.
- Mutant C (planner reads the enum constant instead of the policy): 13 failed. Mutant D (door reads the enum constant): 2 failed. Both readers follow the policy or the case is red.
- Guard-rails: adding a `Config\Repository` parameter to the policy constructor: `no constructor argument reaches serving / by type` case red (1 failed, 7 passed). Serving answering from the migratable property: 2 failed (serving stays exactly one; shipped container). Each guard-rail has a tooth.

## Step 13f, measured by me (clean control 23 passed)
- Status ignores outstanding work: 3 failed. `finished_at` written regardless: 2 failed. `finished_at` restamped on a terminal run: 1 failed (the 17-minute clock case). Each mutant is caught by the case designed for it.

## BUG-11
CLOSED. Evidence above.

## Printed-instruction sweep (the defect class)
Inventory of strings that tell an operator to run something: `tenant:migrate --run=<id>` (three sites: dispatch closing line, outstanding warning, status report), `tenant:migrate --to=<V-1>` (contract-guard refusal), `schema:publish --write-fixture` (catalogue mismatch), `config:clear && config:cache` (framework commands), and docblock remedies for the version floor.
New file `packages/kernel/tests/Schema/Features/RunTenantMigrations/QaPrintedInstructionRoundTripTest.php` (13 cases): takes each instruction **from the output that carried it**, parses it, calls it back through Artisan, and uses the guard's own sentence as the instrument (with a control that a bare invocation produces it).
- The three `--run` sites: round-trip green. Reverting `dev`'s guard exemption reddens all three, so the cases bite.
- **Exemption narrowness: confirmed.** `--to`, `--retry-failed`, `--queue`, `--concurrency`, `--worker`, `--per-server` alone are all still refused; `--run=<unknown>` and `--run=` (empty) exit 1 and write no run or job row, so the exemption cannot be a bulk run in disguise. Removing the guard entirely reddens 8 cases.
- `schema:publish --write-fixture`: option exists in the signature. Not called back by me; the flag is real, the claim is true.

### BUG-12 (minor)
- Test: `QaPrintedInstructionRoundTripTest` "runs the remedy the contract-guard refusal prints" (red by design).
- Expected: the remedy `MigrationRunRefused::contractStepBlocked()` prints, `` Run `tenant:migrate --to=6` first``, is an invocation that runs.
- Actual: `tenant:migrate --to=6` alone is refused by the command's own argument guard ("Name the tenants with --tenant, or ask for every eligible tenant with --all"), exit 1, nothing planned. The same class as the `--run` defect, in a message that predates step 13f. `PlanMigrationRunHandlerTest` line 370 asserts only `toContain('tenant:migrate --to=6')`, which is why it was green.
- Suspected location: `packages/kernel/src/Schema/Exceptions/MigrationRunRefused.php` line 69 (message should say `tenant:migrate --all --to=N` or name the tenants); the test at `PlanMigrationRunHandlerTest.php:370` should assert the corrected text. Severity minor: the refusal still names the obstacle and the operator can add `--all`; the guidance is wrong, not the guard.

## QA files this round
`packages/kernel/tests/Schema/Features/RunTenantMigrations/QaPrintedInstructionRoundTripTest.php`: 13 cases, 12 green, 1 red by design (BUG-12). Must not be skipped or weakened.

Run 3: FAIL (BUG-12 minor open) -> task still NOT VERIFIED.

## Round 4 (2026-10-15) - confirmation of BUG-12
- Tip `c77a4af`. Lead's gate: exit 0, 845 passed, 11 skipped, 4444 assertions. I did not re-run the full gate; I ran the two affected files.
- Clean: `PlanMigrationRunHandlerTest` + `QaPrintedInstructionRoundTripTest` = 25 passed, 214 assertions (my round-trip case green, untouched).
- Mutant (message reverted to `tenant:migrate --to=N`, restored afterwards, tree clean): 2 failed, 23 passed - the handler assertion and my round-trip case both redden.
- `MigrationRunRefused.php:78` now prints `tenant:migrate --all --to=<N-1>`; handler test line 373 asserts it. `--all` is correct: the guard clears only when every tenant carries the expand.
- BUG-12 CLOSED. No MySQL connection made; ENGINE_HOLD untouched.

### Final verdict: PASS for the work verified; task as a whole NOT VERIFIED
Defects: 12 found, 12 closed, 0 open. Engine criteria under G-1 are held: NOT VERIFIED, not PASS. Only 10 of 52 engine criteria have an engine test; clearing G-1 verifies 10, the other 42 need a matrix nobody has written. Step 13d deferred to FP-197, not covered. A green gate under the hold means nothing failed, not that engine criteria pass.

Run 4: PASS (BUG-12 closed) -> task still NOT VERIFIED.

## Round 5 (2026-10-15) - confirmation after review fixes (R-W1..R-W3, S-1..S-5)

Command: `composer check` from modules/host, run by me. Result: 857 passed, 11 skipped, 4508 assertions, matches dev's report. Test step 128.6s (D-12 state: warm, margin ratio 7.00 vs 900s limit, 2026-10-15). Lint, stan, deptrac stages preceded it in the same run.

1. R-W2 moved assertion: confirmed on the log, not deleted. New cases register `Log::listen`, render the exception, and assert exactly one line containing the tenant id and "schema version" (TenantSchemaBehind), and the config key (RequiredSchemaVersionNotConfigured). The body cases now assert the PUBLIC_MESSAGE is present and the id/key are absent.
2. S-4: both bindings (`TenantDatabaseRepository`, `TenantMigrationTargetRepository`) call `MappingSource::isConfigStandIn`, which tests `EnvSecretResolver::LOCAL_ENVIRONMENTS` directly, so the list is shared, not copied. Allow-list, not a `!== production` test. Test covers both bindings in production, an unanticipated environment name, the permitted environments, and master left alone in production.
3. Exception family: `MappingSourceNotPermitted` is not enumerated by any test; the R-W3 count test covers the other family. Not worth a test now (a single throw site, documented in the class). Note only.
4. Dropped source-text scan: agreed. Four behavioural cases cover both bindings, so nothing is lost.

Defects: none new. 15 found across QA and review, all closed.

Run 5: PASS -> task still NOT VERIFIED. Engine criteria under G-1 are held: NOT VERIFIED, not PASS. Only 10 of 52 engine criteria have an engine test; the other 42 need a matrix nobody has written. Step 13d deferred to FP-197. A green gate under the hold means nothing failed.
