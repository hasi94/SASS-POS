# QA Report: T-032 Chart of accounts, fiscal years and periods (backend)

Tip under test: `997a682` on `ai/T-032-chart-of-accounts`. ENGINE_HOLD on: no engine connection was made.

## Verdict: PASS

No production defect found. All 66 acceptance criteria are covered by at least one passing automated test on the SQLite stand-in, except the `[ENGINE]` clauses, which are NOT VERIFIED (below). 22 of 22 production mutations were killed. Five as-built differences from the spec are reported as notes, not bugs.

## Acceptance criteria coverage

Tests live in `packages/finance/tests` (F), `packages/catalog/tests` (C), `packages/customers/tests` (U), `modules/host/tests` (H) and my new `packages/finance/tests/Qa/QaFiscalLifecycleTest.php` (Q).

| AC | Test(s) | Result |
|----|---------|--------|
| 1-9 account create and field rules | F `Accounts/FinanceAccountInputTest`, `FinanceAccountRulesTest`, `Features/CreateAccount/CreateAccountTest`; Q trailing-newline and null-byte code probe | PASS |
| 10-13 hierarchy, parent order, depth, cycle, re-parent rewrite | F `FinanceAccountRulesTest`, `CreateAccountTest`, `ReparentAccountTest`, `UpdateAccountTest` | PASS (AC-12 two-connection half `[ENGINE]`) |
| 14, 15 postable/header, type change | F `FinanceAccountRulesTest`, `UpdateAccountTest` | PASS |
| 16, 17, 19 control accounts | F `FinanceAccountRulesTest`, `CreateAccountTest`, `UpdateAccountTest` | PASS (AC-17 race `[ENGINE]`) |
| 18 system accounts | F `UpdateAccountTest`, `DeleteAccountTest`, `DeactivateAccountTest`, `ReparentAccountTest`; mutations M11, M12 | PASS |
| 20-24 lifecycle, delete, deactivate | F `UpdateAccountTest`, `DeleteAccountTest`, `DeactivateAccountTest`, `ReactivateAccountTest`; mutations M13, M14 | PASS (AC-21 lock proof `[ENGINE]`) |
| 25 usage ports | F `FakeUsagePorts`-bound cases in the slice tests above, `DatabaseChartOfAccountsTest` | PASS |
| 26-30 seed | F `Seed/SeedChartTest` | PASS |
| 31-34 years, dates, contiguity, names | F `FinanceCalendarTest`, `CreateFiscalYearTest`; Q lifecycle, name-cap probe; mutations M9, M10 | PASS |
| 35 concurrent year create | F lock-order and verify-chain cases | PASS on stand-in; race `[ENGINE]` NOT VERIFIED |
| 36, 37 rename, delete year | F `RenameFiscalYearTest`, `RenameFiscalPeriodTest`, `DeleteFiscalYearTest`; Q delete probe | PASS |
| 38-43 transitions | F `FinancePeriodTransitionsTest`, `TransitionFiscalPeriodTest`; Q lifecycle and blocker probe; mutations M1-M3, M6, M8, M18 | PASS |
| 44 lock order | F `TransitionFiscalPeriodTest` (observed lock order); mutation M16 | PASS on stand-in; two-connection `[ENGINE]` NOT VERIFIED |
| 45, 46 year close and reopen | F `CloseFiscalYearTest`, `ReopenFiscalYearTest`; Q lifecycle, later-year, reason probes; mutations M7, M17 | PASS |
| 47-49, 51 gate | F `DatabasePeriodGateTest`; Q gate probes (16 malformed inputs); mutations M4, M5 | PASS |
| 50 gate shared lock | F `DatabasePeriodGateTest` (source construction); mutation M15 | PASS on stand-in; two-connection `[ENGINE]` NOT VERIFIED |
| 52, 53, 54 owed columns, FKs, planner | H `OwedAccountColumnsMigrationsTest`, `MigrationTreeDisciplineTest`, `SchemaCataloguePublishTest`, `TenantBaselineBuildsTest` | PASS |
| 55-57 assignment | C `*ProductAccountsTest`, `*CategoryAccountsTest`; U `*CustomerAccountsTest`; mutations M19-M22 | PASS (AC-57 lock proof `[ENGINE]`) |
| 58, 59 dead rows, reads | C and U `Update*AccountsTest`, `Create*AccountsTest` | PASS |
| 60 prior suites still pass | C `Schema`, U `Schema`, H `OwedAccountReferrersEndToEndTest`; dev's full-gate run | PASS |
| 61-63 reads and contract | F `ListAccountsTest`, `GetAccountTreeTest`, `GetAccountTest`, `ListFiscalYearsTest`, `ListFiscalPeriodsTest`, `FindPeriodByDateTest`, `DatabaseChartOfAccountsTest` | PASS |
| 64 permissions | F `FinanceAuthorizationMatrixTest`, `FinanceAuthorizeTest`; Q null/unprivileged actor probe | PASS |
| 65 audit | F every write slice test; transition audit-row test | PASS |
| 66 schema, forward-only, collation, precision | F `FinanceTenantSchemaTest`, `FinanceWidthsTest`; H `TenantDecimalPrecisionTest`, `IdempotencyKeysSchemaTest`, `QaFinanceSourceRulesTest` | PASS |

## Test run summary

All run by me at tip `997a682`, with `config:clear` first and no overlapping runs.

- Finance package plus `QaFinanceSourceRulesTest` (and my 9 tests at that point): `vendor/bin/pest ../../packages/finance/tests tests/Feature/QaFinanceSourceRulesTest.php` -> **610 passed (3245 assertions), 182 s**.
- Hard-coded-list, schema and catalogue tests: `AuditCatalogueTest`, `BranchForeignKeysContractRunTest`, `IdempotencyKeysSchemaTest`, `MigrationTreeDisciplineTest`, `OwedAccountColumnsMigrationsTest`, `OwedAccountReferrersEndToEndTest`, `QaAccessHttpSurfaceTest`, `QaToolchainRegistryCoverageTest`, `SchemaCataloguePublishTest`, `TenantBaselineBuildsTest`, `TenantDecimalPrecisionTest`, plus catalog and customers `Schema` and `Finance` directories -> **126 passed (1748 assertions), 476 s**.
- After adding two more QA cases and a Pint fix: `QaFinanceSourceRulesTest` + `packages/finance/tests/Qa` -> 28 passed (158 assertions). `Qa` alone: 11 passed (69 assertions).
- Pint: `pint --test .. ../../packages` -> passed.
- PHPStan (result cache cleared, run twice, the second after my new tests were added): `[OK] No errors`.
- Deptrac: 0 violations, 0 errors, 0 warnings (1357 uncovered, 2210 allowed).
- Not run, by instruction: the full 5900-test suite (no production change was made). The dev's number at the predecessor state (13 failed / 63 skipped / 5835 passed, the 13 known baseline failures) is not re-verified by me. None of the 13 known-baseline tests are in any of the runs above, and none failed in them.
- Skipped: engine tests (`FinanceSchemaEngineTest`, 9 cases) under ENGINE_HOLD.
- An early overlapping pair of my own pest runs (I accidentally launched one twice) produced spurious failures in `DeactivateAccountTest`. I killed both and reran alone; the clean run is green. Not a defect.

## Mutation table (production code, each restored; `git diff` clean afterwards, only `packages/finance/tests/Qa/` is new)

| # | Mutation | Target | Result |
|---|---|---|---|
| M1 | adjacency `!== 1` -> `> 2` (allows same-status) | PeriodTransitions | KILLED (1 fail) |
| M2 | reopen-order (later period higher) check disabled | PeriodTransitions::checkOrder | KILLED (2 fail) |
| M3 | close-order (earlier period lower) check disabled | PeriodTransitions::checkOrder | KILLED (2 fail) |
| M4 | gate allows a closed period | DatabasePeriodGate | KILLED |
| M5 | gate allows soft-closed to everyone | DatabasePeriodGate | KILLED |
| M6 | `expected_status` compare-and-set removed | TransitionFiscalPeriodHandler | KILLED |
| M7 | closed-year guard removed | TransitionFiscalPeriodHandler | KILLED |
| M8 | close blockers skipped | TransitionFiscalPeriodHandler | KILLED |
| M9 | year contiguity allows a gap (`!==` -> `<`) | FiscalCalendar::contiguous | KILLED (2 fail) |
| M10 | year-name cap 46 -> 47 | CreateFiscalYearHandler | KILLED |
| M11 | system-account field immutability off | AccountRules::system | KILLED (9 fail) |
| M12 | system-account delete/deactivate allowed | AccountRules::systemRemoval | KILLED (2 fail) |
| M13 | delete ignores soft-deleted referrers | ReferrerCensus | KILLED |
| M14 | deactivate counts soft-deleted referrers | ReferrerCensus | KILLED |
| M15 | gate shared lock dropped | PeriodRows::periodCoveringSharedLock | KILLED (source-construction test) |
| M16 | neighbour periods locked descending (lock order) | PeriodRows::periodsBetweenForUpdate | KILLED (4 fail) |
| M17 | year close: all-periods-closed check off | CloseFiscalYearHandler | KILLED |
| M18 | reopen reason made optional | PeriodTransitions::reason | KILLED (2 fail) |
| M19 | owed-account assignment accepts inactive account | DatabaseChartOfAccounts::assignable | KILLED |
| M20 | assignment accepts a header (non-postable) | DatabaseChartOfAccounts::assignable | KILLED |
| M21 | assignment accepts wrong kind | DatabaseChartOfAccounts::assignable | KILLED |
| M22 | assignment accepts a soft-deleted account | DatabaseChartOfAccounts::live | KILLED |

22 of 22 killed, 0 survived. (Runs used `--stop-on-failure`, so "fail" counts are lower bounds.)

## NOT VERIFIED (need a real engine, ENGINE_HOLD)

- AC-3 engine half: real-collation pairs `ab1`/`AB1` and a soft-deleted code.
- AC-12, AC-21: two-connection proof that parent and account rows are read under a row lock.
- AC-17: two simultaneous creates of a single-holder control type, exactly one wins.
- AC-35: two simultaneous year creates for the same or overlapping start, exactly one wins.
- AC-44: two simultaneous transitions of one period, one winner and one `status_changed`.
- AC-50: close waits for an in-flight posting; posting after the close sees the new status.
- AC-57: concurrent delete or deactivation of an account cannot leave a live referrer.
- Applying the eight T-032 migrations (versions 74-81) on a real MySQL 8.4 or MariaDB 10.4 (the real `ascii_bin` collation, the FK creation, the contract pre-check on real data). The stand-in checks the migration source and the planner classification only.
- On the stand-in, lock calls (`lockForUpdate`, `sharedLock`) compile to nothing, so these clauses are proven only by source-construction and call-order tests, which M15 and M16 show do bite.

## Defects

None.

## As-built differences from the spec (reported, not bugs, each pinned by a passing test)

1. Year names are capped at 46 characters on create (spec AC-34 says 1-50), because periods are named `<year> P01` and a period name is at most 50. Pinned in `QaFiscalLifecycleTest` (lengths 1 and 46 accepted; 47, 50, 51 refused) and by M10. **Inconsistency worth a decision**: `RenameFiscalYear` accepts up to 50 characters, so a year can be renamed to a name longer than create allows. The test pins this too.
2. The finance-side refusal case is named `FinanceRefusal::NoFiscalPeriod` (value `no_fiscal_period`), as reported by the lead; I did not find a behavioural difference from the spec's reason code.
3. Reopening a year that is not closed is refused `invalid_transition`, not a year-specific code. Pinned.
4. Reopening a closed year does not touch its periods: they stay `closed` and the gate keeps refusing them until each is reopened (last first). Pinned in the lifecycle test. The spec does not say that a year reopen unlocks periods, so this is consistent, but it is a usability point for the API task.
5. Period names are not unique (two periods can be renamed to the same name). Spec AC-34 only gives a length for period names. Pinned.

## Regression risks / notes

- Guards depending on the usage ports (AC-14, 15, 19, 22, 23, 37, 41) refuse nothing in production until FP-261 binds `AccountUsage`, `FiscalYearUsage` and `PeriodCloseBlockers`; tests prove them only with `FakeUsagePorts`. Forward dependency, as the spec states.
- Reapplying an unchanged account id on a product, category or customer skips the assignability check (`AccountFields::assertAssignable`). Acceptable because deactivation and deletion are refused while a record references the account; it would matter only if that guard were relaxed.
- A year rollover with no next year fails the gate closed for every posting (D-28, follow-up already filed).
- The shared scratch directory is used by other agents; I ran every pest invocation with no overlap and stopped my two accidental duplicates.
- New QA test file: `packages/finance/tests/Qa/QaFiscalLifecycleTest.php` (11 cases, Pint and PHPStan clean): whole-year lifecycle through the real handlers, closed-year and later-year reopen rules, reason validation, null/unprivileged actors, gate permission matrix and 16 malformed gate dates, account-code newline/null-byte traps, year-name cap, delete-year rules with a bound usage fake, rename cap and name uniqueness, and the close-blocker/reopen asymmetry.

Run 1: PASS
