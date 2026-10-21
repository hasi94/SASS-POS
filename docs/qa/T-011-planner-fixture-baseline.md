# QA Report: T-011 Planner fixture baseline, and the settings.updated_by contract migration

Branch `ai/T-011-planner-fixture-baseline`, tip `b1f5350`. Spec `docs/specs/T-011-planner-fixture-baseline.md` (criterion range AC-1 to AC-26, as that header states; this report states no other total). Diff base `fb4996e` (`git diff fb4996e...HEAD`). Plan step 8 is this report.

## Verdict: PASS for what could be verified. NOT VERIFIED (engine hold G-1): AC-22 and AC-11's two-engine comparison. 0 open defects (0 critical, 0 major, 0 minor). Notes N-1 to N-4 below are not defects.

- **PASS (verifiable part).** Every criterion that runs on the SQLite stand-in is covered by a green test whose negative control was shown red, by dev's evidence or by my mutation ledger below.
- **NOT VERIFIED, engine hold.** AC-22 (key added and enforced on MySQL 8.4 and MariaDB 10.4, same pre-check offending set on both) and the real-engine comparison half of AC-11. No green test is offered for either; the stand-in runs corroborate only. Reason: `ENGINE_HOLD` on, G-1 unmet; plan section 11. The pre-check is one query-builder statement with no driver branch (diff review: `whereNotNull` plus `whereNotExists` plus `whereColumn`, then `count()`; AC-13's test also pins no driver token).

## Grades

IP independently proven (stand-in, fails if absent); CORR corroborating; READ read or diff only; NV not verified. Dev-owned tests cited by title; "QA mutant" only where I mutated.

## Acceptance criteria coverage

| AC | Test(s) | Control / mutant | Grade |
|----|---------|------------------|-------|
| 1 | `QaFixtureBaselineSweepsTest` "finds no product source that names the fixture baseline" (356 product files scanned, 0 naming it, baseline dir under `tests/`) and its control. Diff: the product tree gains exactly one file (`git diff --name-status` on `migrations-tenant`: one `A`) | QA M7: scratch `modules/host/app` file naming the path -> red naming it | IP |
| 2 | `FixtureBaselineTest` "is six synthetic expand files numbered so that file n is version n" (prints entries) and "reports a contract entry, a numbering gap and a product checksum"; QA `QaFixtureBaselineCoverageTest` "keeps the fixture baseline at six files" | dev's three in-memory controls; the six pin is the one place the size is written (AC-2, D-14) | IP |
| 3 | `FixtureBaselineTest` "composes the FX directories onto the fixture baseline by position" and the nonexistent-FX control | `(4,1)` reversal control; QA C2 (set reads configured path) -> red | IP |
| 4 | `ShippedTenantTreeTest` "builds every runFixtureSet catalogue identically whatever the shipped tree holds" (contract top, expand extras, four compositions, control that the shipped entry point moves); QA `QaFixtureBaselineCoverageTest` "builds every runFixtureSet composition from a configured tenant path that does not exist" (six compositions, no tree read) | QA C2 red; QA M5-by-hand: a real extra contract file in the shipped tree left 454 corpus tests green, only the four named-file cases (shipped-tree subject) red | IP |
| 5 | `FixtureBaselineTest` "plans FX-1 for a fleet at 0, every job from 0 to B + 1"; `ShippedTenantTreeTest` "refuses FX-1 composed on the shipped tree" (control a) | M1 turns control a red | IP |
| 6 | `FixtureBaselineTest` refusal (names FX-4, its version, the tenant, exactly one catalogue-shaped name, zero rows, master-only connections), positive controls, FX-5 destructive and FX-4 non-destructive pair | **QA M1: guard skips contract entries -> 17 red** (FixtureBaselineTest "refuses FX-4 ..." and the old `PlanMigrationRunHandlerTest` and `ContractGuardBlockingSetTest` cases) | IP |
| 7 | `QaFixtureBaselineSweepsTest` AC-7 sweep (20 corpus files printed, non-empty) and control. Diff (case names): see "AC-7 accounting" | QA M8: scratch corpus file mixing `runFixtureSet` and the product file count -> red | IP, see deviation grading |
| 8 | AC-8 sweep (62 kernel test files, allowed readers printed, `MigrationSetTest` excluded and printed, 22 out-of-scope readers printed, gap stated) and control; QA `QaFixtureBaselineCoverageTest` E4 sweep | **QA M4a/b/c**: real scratch kernel files (shipped entry point, the config key, the product directory name) each -> red naming the file | IP; stated gap stands |
| 9 | `ShippedTenantTreeTest` "derives V ... fails rather than skips" | scratch expand-only tree returns null and the failure branch throws | IP |
| 10 | `ShippedTenantTreeTest` refusal at 0 (names step, V, both tenants, zero rows, master only), V-1 positive control, provisioning control | M1 red | IP |
| 11 | `ShippedTenantTreeTest` "runs the remedy the refusal prints with the shipped applier, then applies V" (remedy parsed from the message, asserted equal to derived V-1, run for real, both keys present after) and the V-2 plan-level control | M1 red; two-engine half NV | IP (stand-in) + NV |
| 12 | `SchemaCataloguePublishTest` (3 cases), catalogue prefix check below | **QA**: `.env.example` one off -> red; catalogue one row short -> 2 red; checksum recomputed independently (below) | IP |
| 13 | `SettingsUpdatedByKeyTest` "is the last file of the tree, a non-destructive contract step that only adds the key" and driver control | QA M7 (also alters employees), M12 (driver gate), M13 (expand phase) -> red | IP |
| 14 | `SettingsUpdatedByKeyTest` clean data / empty tables | QA M10 (soft-deleted counted unknown) -> 9 red | IP |
| 15 | `SettingsUpdatedByKeyTest` five properties + damaged controls; QA `QaSettingsUpdatedByEdgeTest` "keeps every column definition and every index" (columns via `table_xinfo`, all indexes, employees untouched) | QA M6 (drops a unique index after the key) -> red in dev's and mine | IP |
| 16 | `SettingsUpdatedByKeyTest` enforcement on/off pair | enforcement-off control in file | IP |
| 17 | `SettingsUpdatedByKeyTest` (both enforcement states, byte-identical, no leftover, no value), `SettingsUpdatedByContractRunTest` (job failed, mapping stays V-1, every sink free of the value, count present, two rows -> 2); QA edge test (counts rows not ids, empty string and blank unknown, same answer on a second refusal, applies once clean) | QA M2a (throw disabled) 9 red; M8 (repairs dirty values) 10 red; M11 (message carries a value) 5 red; M4 distinct count 2 red; **M5 (empty string treated clean) survived all dev tests, killed only by my edge test** | IP |
| 18 | `SettingsUpdatedByContractRunTest` "issues the check and no statement that alters settings on a dirty tenant" (statements printed, clean-tenant liveness) | **QA M3 (key before pre-check) -> 4 red incl. this case; M2a -> red**. Plan's mutation (a) with the check truly cut out, enforcement on and off: dev's printed "Ruling 7, measured" case passes in both states (rebuild runs, one foreign_key_check violation left) | IP |
| 19 | `SettingsUpdatedByContractRunTest` A,C clean / B dirty / retry | M2a, M3, M8 red | IP |
| 20 | `SettingsUpdatedByKeyTest` letter case / soft-deleted | QA M9 (case-insensitive) -> red; M10 -> red | IP |
| 21 | `SettingsUpdatedByKeyTest` "declares no down(), and neither does any other file" with control | diff: no `down` in the file | IP |
| 22 [ENGINE] | none | | **NV** |
| 23 | `TenantBaselineBuildsTest` inventory (gains the line) and unguarded map; `MigrationTreeDisciplineTest`; `SchemaCataloguePublishTest` | **QA M6-by-hand: scratch key `settings.scope_id -> employees.id` -> inventory red showing the extra line** | IP |
| 24 | audit below | | IP (diff) |
| 25 | `git diff --name-only fb4996e...HEAD -- packages/kernel/src packages/testing packages/access/src modules/host/app modules/host/config modules/host/bootstrap` -> 0 files; the blocking-set tests edited only at E2 | | READ (diff) |
| 26 | gate below; AC-22 and AC-11 two-engine reported NV | | see gate |

## Diff audits

**AC-24 / D-11 sanctioned edits.** Every changed pre-existing test is E1 to E5; no assertion weakened.
- E1: `migration-run-fixture.php` (set rewritten onto the baseline, new helpers, shipped entry point), `migrate-tenant-fixture.php` (default target derived; docblock word).
- E2: `tenantMigrationFileCount()` -> `runFixtureBaselineVersion()` in `MigrateTenantHandlerTest`, `ContractGuardBlockingSetTest`, `PlanMigrationRunHandlerTest` (lines 99-100 became the baseline directory and version), `QaContractGuardNamedRunTest`, `DispatchMigrationRunTest`, `RunTenantMigrationsHandlerTest`, `QaRealApplierOnStandInTest`. Every fleet that started at 0 still starts at 0 (`runFixtureTenant(..., 0, ...)` lines are context, unchanged).
- E3: `PlanMigrationRunHandlerTest` product table name -> first catalogue entry of the set; `QaRealApplierOnStandInTest` tables and count -> baseline tables and version (the shipped-tree application is now `ShippedTenantTreeTest` AC-11).
- E4: one `runFixtureCommandsReadTheFixtureBaseline();` line in four files (`DispatchMigrationRunTest`, `QaPrintedInstructionRoundTripTest`, `RunTenantMigrationsHandlerTest`, `RunTerminalStateTest`).
- E5: `TenantBaselineBuildsTest` (inventory +1 line; unguarded map 8 -> 7 with the column's nullable-identifier assertion added) and `MigrationTreeDisciplineTest` (file count pin derived as tables plus tableless files). See N-2.
- Nothing under `packages/kernel/src`, `packages/testing`, `packages/access`, `modules/host/app` or config changed; the only product-tree file added is the migration, plus the catalogue row and `.env.example` value (14 -> 15).

**AC-7 accounting (diff).** Case declarations (`it(`/`test(` titles) at `fb4996e` versus now over all 200 test files under `tests/` directories: 1181 old declarations, **0 removed or renamed**, skip/todo/incomplete counts unchanged in every file, none added in the diff. Set of case names is a superset.

**AC-7 deviation graded (dev's flag).** The AC-7 domain omits the support file that defines `runFixtureSet()` and AC-8's two allowed readers. Accepted: the allowed readers are exactly the files whose subject is the shipped tree, and neither uses the product file count today (`ShippedTenantTreeTest` has zero uses). Residual: a future `tenantMigrationFileCount()` inside the named file would be allowed by both sweeps. Noted as N-1.

**Checksum (AC-12).** `MigrationCatalogue::checksumOf()` is `hash('sha256', str_replace(["\r\n","\r"], "\n", $source))`. I recomputed it independently with a separate script over all 15 files as committed: 0 mismatches against the committed catalogue (version, migration name and checksum). New row: version 15, phase contract, `is_destructive` false, checksum `2793134a...a06bc`. The 14 older rows are byte-equal to the `fb4996e` fixture (prefix equality true). `.env.example` value equals the highest version (15).

**Sweep domains.** AC-1: 356 files printed. AC-7: 20 files printed. AC-8: 62 files, equal to `find packages/kernel/tests -name '*.php' | wc -l` = 62, so the scope is the directory it claims (`dirname(__DIR__, 3)` from the sweep file). All non-empty and asserted so.

## QA tests written (new files only, no production or dev-test edit)

| File | Cases | Why |
|---|---|---|
| `packages/kernel/tests/Schema/Features/PlanMigrationRun/QaFixtureBaselineCoverageTest.php` | 5 | E4 is enforced by nothing in dev's tests: the AC-8 sweep searches for the config key and a file that forgets the helper never writes it. This enumerates callers of the migrate command and the drift option and requires the helper as a real call (a comment mention does not count), with controls. Also a no-tree-read AC-4 independence case and the single deliberate "six" pin (AC-2, D-14) |
| `modules/host/tests/Feature/QaSettingsUpdatedByEdgeTest.php` | 3 | rebuild keeps every column definition and index and leaves `employees` alone; counts rows (not distinct ids), empty string and blank as unknown; refusal is repeatable and the file applies once clean |

Negative controls (each shown red, then restored; `git diff --stat` empty after each): helper removed from `RunTerminalStateTest` -> E4 case red naming it; `runFixtureSet` reading the configured path -> independence case red (`MigrationSetRefused`); migration mutants M4, M5, M6, M7, M13 killed by the edge test.

## Mutation ledger

Method: in-place edit with byte-exact restore, one mutant at a time, tests named; the product tree was restored after every run and `git status` shows no modified tracked file.

| # | Mutant | Result |
|---|---|---|
| M1 | `assertContractStepsUnblocked()` skips contract entries (kernel source) | 17 failed: AC-6, AC-5(a), AC-10, AC-11 and its control, the old contract-guard cases |
| M2a | pre-check throw disabled (query kept) | 9 failed (AC-17 both layers, D-10, AC-18, AC-19, edge) |
| M3 | key added before the pre-check | 4 failed: AC-17 migration and runner, AC-18, AC-19 |
| M4 | count distinct ids | 2 failed (AC-18 shape, edge test) |
| M5 | empty string treated clean | 1 failed, **only my edge test** (dev suite survived) |
| M6 | drops a unique index after the key | 7 failed |
| M7 | also alters `employees` | 2 failed |
| M8 | repairs dirty values | 10 failed |
| M9 | case-insensitive comparison | 1 failed (AC-20) |
| M10 | soft-deleted employees unknown | 9 failed |
| M11 | message carries the offending value | 5 failed |
| M12 | driver gate | 16 failed |
| M13 | expand phase | 4 failed |
| M4a/b/c | real scratch kernel files reading the shipped tree three ways | AC-8 sweep red, file named, each time |
| M5-hand | a real extra contract file in the shipped tree, planner corpus run | 454 passed; only the 4 shipped-tree-subject cases red (as designed) |
| M6-hand | scratch extra key onto `employees` | inventory red with the extra line |
| M7/M8 | scratch product source naming the baseline; scratch corpus file using the product file count | AC-1 / AC-7 red |
| AC-12 | `.env.example` one off; catalogue one row short | 1 and 2 red |

## Defects

None open.

## Notes (not defects)

- N-1: AC-7/AC-8 allow `ShippedTenantTreeTest` and the support file to read the shipped tree by design; a misuse inside them would pass both sweeps. Stated gap of AC-8 (readers outside `packages/kernel/tests`) also stands.
- N-2: `MigrationTreeDisciplineTest`'s file-count pin is now an identity (tables plus tableless files) with the table count still pinned at 14; a second tableless file would not redden it. Sanctioned by E5; weaker than an absolute number by design.
- N-3: the six-file coupling (older literals assume six) is guarded now by my pin; follow-up row belongs to the lead (D-14).
- N-4: AC-15's damaged-fixture controls cover properties 1 to 3; properties 4 and 5 are covered by M6 and the migration-level tests rather than their own damaged fixture.

## Gate

`cd modules/host && composer check`, once, alone, after the report was written and all probes removed: **exit 0**. Pint passed; PHPStan `[OK] No errors`; Deptrac Violations 0, Skipped 0; Pest **1590 passed, 11 skipped, 10327 assertions**, test step 395.28 s against the 900 s limit, **ratio 2.28** (source=manifest), no low-headroom line. Dev's last figure was 1582 passed; the +8 are my two files (5 + 3).

Foreign `php.exe` processes by pid before the run (none mine), still three after: 17320 `vendor/bin/phpunit --colors=never`; 16980 `artisan test`; 6540 `phpunit --configuration=...Scriber\backend\phpunit.xml --no-output` (other projects' test runs).

Load control `QaRealApplierOnStandInTest` (unchanged by this task apart from E2/E3): **5.68 s** in the gate against its 4.07 s quiet baseline, so the machine was about 1.4x loaded. The ratio is above the 2.0 floor, so nothing is counted against the tree and no escalation arises from this figure; the tree's own ratio would be no lower than reported.

History: Run 1: PASS (verifiable part), 0 defects.
