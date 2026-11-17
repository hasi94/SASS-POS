# Plan: T-033 Journal entries and lines, posted through one service

**Revision 3.** Synced to spec `docs/specs/T-033-journal-entries.md` **revision 3** (AC-1 to AC-82 plus AC-6a, AC-15a, AC-16a, AC-33a, AC-33b, AC-73a) and decisions `docs/decisions/T-033-journal-entries.md` D-1 to D-39. Revision 2's findings F-15 to F-21 are closed by D-32 to D-38 (section 0.5). New points for the lead are F-22 to F-24 (section 0.3); none blocks chunk A.

Backlog FP-261. Branch `ai/T-033-journal-entries` (worktree `.claude/worktrees/journal-entries`, tip `5a6b8a0` = merge of T-032 DONE `f569008`). Gate directory `modules/host`.

**Measured against the T-032 code as merged into this branch**, not against T-032's plan or a sibling worktree. Every `file:line` below was read at `5a6b8a0`. T-032 is DONE including its former chunk E (owed account columns `003205` to `003208`) and chunk F (`QaFinanceSourceRulesTest`, `FinanceSchemaEngineTest`).

### What changed from revision 2

1. **AC-6a / D-32:** `post()` refuses `source_type = reversal` with `invalid_source_type` (was a recommendation; now a criterion). Ruling 3 step 2, step B2, test `PostingInputTest`.
2. **Check order (D-21 as amended by D-26 and D-38):** shape, then amounts, then balance, then actor, then replay, then gate, then accounts, then references, then number. For `reverse`: shape, actor, original, replay, date rule, gate, accounts. Rulings 3 and 8.
3. **AC-55 / D-35:** `reverse()` does not run the reference (liveness) check for the entry branch or any line dimension. Ruling 8, step C5.
4. **AC-68 / D-37:** `FinanceRefusal::EntryNotFound = 'entry_not_found'`; the read handlers throw only `FinanceRefused`. Ruling 12, step D2.
5. **AC-81 / D-33:** section 8 is now the **complete** list of edits to existing files, re-grepped at `5a6b8a0`. New since rev 2: `BranchForeignKeysContractRunTest` (one more contract stop), the `branches`-referencing table list in `TenantBaselineBuildsTest`, four unscoped `value('next_number')` reads in Sales tests, and the `QaFinanceSourceRulesTest` slice lists, unlocked-caller allow-list and AC-60 exemption. **Dropped** from rev 2: the edit to `FinanceSeed::clearSeed` (a new `JournalSeed` clears journal rows first) and the edit to `FinanceAuthorizationMatrixTest` (a new `JournalAuthorizationMatrixTest`). See F-22 for the parenthetical in AC-81.
6. **AC-45 / D-34:** the one combined `oneSided` CHECK is the build; its SQL text states both conditions. Ruling 10.
7. **AC-69 / D-36:** ordering by `number` is string order, correct while numbers have equal width and an unchanged prefix. Ruling 12, step E3.
8. **AC-62** gains the T-032 subtype guard (`AccountRules::resubtype`, T-032 D-32) and the control-type lock among its real-binding proofs.
9. `docs/database.md` §7 **already** names `finance.periods.post_adjustment` (T-032 fixed it, line 1718). Step E3's §7 edit and the rev 2 follow-up are withdrawn (F-23).
10. Merge preconditions P-1 (T-032 DONE and merged, T-030 inside) are **met**: `git merge-base --is-ancestor` holds for `f569008` and `c354a0e`.

---

## 0. Preconditions, verification, findings

### 0.1 Preconditions

- **P-1: met.** T-032 DONE (`f569008`) is merged (`5a6b8a0`); T-030 `c354a0e` (with T-029) is an ancestor. No merge is needed before chunk A.
- **P-2: no hard-coded catalogue version in T-033's own files.** The catalogue top is **81** (`modules/host/database/catalogue/tenant_schema_versions.json`, last entry `2026_11_15_003208_add_owed_account_foreign_keys`, phase contract; T-032 is versions 74 to 81). T-033's migrations carry no version; T-033's tests look versions up **by migration name** (`MigrationSet::versionOf`) or derive the top (`highestVersion()`). Only the existing house literals that already pin a number get the measured value after `schema:publish --write-fixture`: `IdempotencyKeysSchemaTest.php:124` (`toBe(81)`), `.env.example:73` (`APP_REQUIRED_SCHEMA_VERSION=81`, which `SchemaCataloguePublishTest.php:231-240` requires to equal the top), and the table count in `MigrationTreeDisciplineTest.php:152`. Projected, never typed into a T-033 test: `003301` 82, `003302` 83, `003303` 84, `003304` 85.
- **P-3: file prefix.** `2026_11_16_0033xx` sorts after every existing tenant file (the last is `2026_11_15_003208`). No rename.
- **P-4: no step edits a committed migration or writes the catalogue JSON by hand.** The JSON changes only through `php artisan schema:publish --write-fixture`; every T-033 schema change is a new file.
- **Already approved by the lead:** `'journal_entry' => 'JE-'` in `DatabaseDocumentNumbers::DEFAULT_PREFIXES` (D-25); the additive kernel helper `CheckConstraint::balanced()` (D-24); the idempotency key `unique(source_type, source_id, source_purpose_key)` with `source_purpose_key` a stored generated `COALESCE(source_purpose, '-')`.

### 0.2 Facts measured against T-032 as built (`5a6b8a0`)

| # | Fact | Evidence | Effect on the plan |
|---|---|---|---|
| 1 | Finance is `packages/finance` (`Flexipos\Finance\`). Layer `Package_Finance` → `Package_Kernel`, `Package_Contracts`; `Package_Finance_Tests` may also reach `Package_Access` | `modules/host/deptrac.yaml:185-193, 523-533` | Journal code in `packages/finance/src/Journal/`; no deptrac edit. Every collaborator is reached by contract: `DocumentNumbers` (`contracts/src/Organization/DocumentNumbers.php:36`), `EmployeeDirectory`, `AuditTrail`, `PeriodGate`, `ChartOfAccounts` |
| 2 | Slices: `src/Features/<Slice>/{<Slice>, <Slice>Handler[, <Slice>Result]}.php`, mirrored by `tests/Features/<Slice>/<Slice>Test.php`; 19 slices | `packages/finance/src/Features/*` | Four journal slices in `src/Features/` (D-39) |
| 3 | `FinanceServiceProvider::register()` binds with `bind`: `ChartOfAccounts`, `PeriodGate`, and the three ports to `NoAccountUsage`, `NoFiscalYearUsage`, `NoPeriodCloseBlockers` | `src/FinanceServiceProvider.php:46-50` | Step C1 adds `PostingService`; step D1 replaces lines 48 to 50 in place |
| 4 | Ports: `AccountUsage::hasPostings(string $accountId): bool`; `FiscalYearUsage::hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool`; `PeriodCloseBlockers::blockersFor(string $periodId, string $startDate, string $endDate): array` | `contracts/src/Finance/AccountUsage.php:16`, `FiscalYearUsage.php:19`, `PeriodCloseBlockers.php:20` | Journal ports answer from the dates (inclusive), ignore the ids |
| 5 | Port callers and the lock they hold first: `DeleteAccountHandler` `liveForUpdate` (:59) then `hasPostings` (:75); `UpdateAccountHandler` `liveForUpdate` (:85), then `hasPostings` lazily (:130) for `retype` (:152), **`resubtype` (:163)**, `recode` (:167), `toHeader` (:184), `recontrol` (:188); `DeleteFiscalYearHandler` `yearForUpdate` (:51), `periodsOfYearForUpdate` (:75) then `hasEntries` (:90); `TransitionFiscalPeriodHandler` `yearForUpdate` (:97), `periodsBetweenForUpdate` (:113) then `blockersFor` (:182) | `src/Features/*` | Every caller holds its exclusive lock on the account or period **before** it asks the port; a poster holding a shared lock makes it wait (Ruling 9) |
| 6 | `AccountRules`: `retype(bool, bool, bool, bool, ?AccountType, AccountType)` (:259) → `type_change_refused`, field `type`, `context.condition`; **`resubtype(bool $isSystem, bool $hasPostings, bool $hasReferrers)`** (:297) → `type_change_refused`, field `subtype`, `condition = has_postings`; `recode(bool, bool)` (:322) → `code_locked`; `recontrol(bool, bool, bool)` (:342) → `control_type_locked`; `toHeader(bool, bool, bool)` (:379) → `has_postings` | `src/Accounts/AccountRules.php` | AC-62's real-binding proofs (step D1) |
| 7 | `PeriodGate::clear(mixed $date, ?string $actorId): PeriodClearance`. Contract docblock: run inside the caller's transaction; **lock order: call it once per transaction, or for several dates in ascending date order** | `contracts/src/Finance/PeriodGate.php:20-38` | `PostingService`'s docblock carries the same caller rule (Ruling 2) |
| 8 | `DatabasePeriodGate::clear`: `LogicException` when `transactionLevel() < 1` (:56); `Input::date` → `PeriodGateRefused::invalidDate` (:61-63); one read `PeriodRows::periodCoveringSharedLock` (:66); closed refuses all (:74); soft-closed refuses unless `PermissionResolver::decide(actor, PERIODS_POST_ADJUSTMENT, null)->allowed`, null actor never (:78, :85-89) | `src/Periods/DatabasePeriodGate.php` | Ruling 7 step 3 unchanged |
| 9 | **Single-row gate read:** `periodCoveringSharedLock` takes the latest period with `start_date <= date`, `limit(1)->sharedLock()`, and checks `end_date >= date` in PHP (`coveringOrNull`) | `src/Periods/PeriodRows.php:119-125, 216-219` | AC-29's day-after-last-period is `no_fiscal_period`; the gate's lock footprint is one index entry (NOT VERIFIED) |
| 10 | `ChartOfAccounts::postable(string $id): AccountSnapshot` folds the id and reads `AccountRows::anySharedLock` **only when a transaction is open**, else `byIdUnlocked`; refusals `account_not_found`/`account_not_live`/`account_inactive`/`account_not_postable`, `field = null` | `src/Accounts/DatabaseChartOfAccounts.php:75-93`, `AccountRows.php:67-70`; `contracts/src/Finance/AccountRefused.php:20-45` | The service always calls it inside the transaction and supplies the field path (`lines.{i}.account_id`) |
| 11 | `AccountRows::allForUpdate()` locks the whole chart `orderBy('id')` (re-parent) | `src/Accounts/AccountRows.php:126-129` | The poster's ascending `strcmp` order over upper-case ULIDs matches this order on `ascii_bin` ids (AC-25) |
| 12 | T-032 lock notes (D-31 d, D-33; `docs/database.md:1498`): global order `fiscal_years` before `fiscal_periods`, ascending `start_date`; account locks never interleave with period locks; reactivate takes parent shared before child exclusive; retype takes child exclusive before parent shared (a known retried pair with deactivate-parent); deadlock freedom not claimed | `docs/decisions/T-032-chart-of-accounts.md:71, 75` | The poster takes only **shared** locks on periods and accounts, so it never closes a cycle with those pairs; it adds the period-then-accounts order, which no T-032 transaction reverses (Ruling 9) |
| 13 | Year names capped at 46 at create and rename (`NAME_MAX`, T-032 D-31 a) | `docs/database.md:1496` | `JournalSeed` uses `FinanceSeed`'s year helpers or names of at most 46 characters |
| 14 | `FinanceRefusal` has 46 cases including `validation`, `invalid_date`, `not_permitted`, `page_size_invalid`, `cursor_invalid`, `reason_required`; **no `entry_not_found`** | `src/Exceptions/FinanceRefusal.php:17-98` | Step D2 adds `EntryNotFound` (D-37). No test pins `FinanceRefusal::cases()` (grep) |
| 15 | `FinanceRefused` has `reason`, `field`, `context` (:27-29), `because(...)` (:38), `validation(...)` (:50) | `src/Exceptions/FinanceRefused.php` | Handler-level refusals |
| 16 | `Authorize` is an **instance** service: `require(?string $actorId, string $key): void` throws `FinanceRefused(not_permitted)` | `src/Support/Authorize.php:28-33` | Handlers inject it (rev 2 wrote it as a static call) |
| 17 | `FinancePermissions`: seven constants (:27-39), `MODULE = 'finance'` (:42), `KEYS` (:49) | `src/Registry/FinancePermissions.php` | +3 keys appended to `KEYS` (step D2) |
| 18 | `FinanceAuditActions::ALL` lists 15 handler `ACTION` constants | `src/Registry/FinanceAuditActions.php:41-55` | +`DatabasePostingService::ACTION_POSTED`, `ACTION_REVERSED`; no test pins the count (grep) |
| 19 | `Input::text($value, int $max, bool $required, string $field)` refuses a control character in the raw value, then trims, then **refuses blank** (:67-95); `Input::date` strict `\A…\z` + `checkdate` (:160); `Input::id` 26-char ULID folded upper (:179); `Limits` has `DESCRIPTION = 500`, `ENUM = 30` (:30, :42) | `src/Support/Input.php`, `Limits.php` | Journal input normalises blank to null **before** `Input::text` (D-30) and maps `FinanceRefused` to `PostingRefused` codes |
| 20 | `Support\Page` is a two-part cursor (`nextAfterCode`, `nextAfterId`) | `src/Support/Page.php:20-23` | `ListJournalEntriesResult` with a three-part cursor |
| 21 | `DatabaseDocumentNumbers::next(string, ?string)` (:75) locks the row `FOR UPDATE` on `(branch_id_key, document_type)`, creates a missing company row with `DEFAULT_PREFIXES[$type] ?? null`, padding 6, `never`, and catches its **own** `UniqueConstraintViolationException` (:121-149); `DEFAULT_PREFIXES` = sale `S`, refund `R`, cash_shift `SH` (:64-68); `WIDTH = 30` (:56) | `packages/organization/src/Numbering/DatabaseDocumentNumbers.php` | One array line (D-25). The service's own unique-violation catch (Ruling 7 step 7) sees only journal keys |
| 22 | `CheckConstraint::oneSided($table, $debit, $credit)` emits **one** constraint `{table}_{debit}_{credit}_one_sided` = `CHECK ((debit = 0 OR credit = 0) AND debit >= 0 AND credit >= 0)`, engine-only (`mysql`/`mariadb`), literal verb, `assertIdentifier`; pure `oneSidedStatement()` | `packages/kernel/src/Schema/Support/CheckConstraint.php:83-114` | AC-45 accepted as one combined check (D-34) |
| 23 | `AuditTrail::record(string $module, ?string $actorId, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?string $branchId = null)`; no actor-type parameter | `contracts/src/Audit/AuditTrail.php:24-33` | D-27 as written |
| 24 | `EmployeeDirectory::isLive(string): bool` | `contracts/src/Access/EmployeeDirectory.php:20` | AC-73a |
| 25 | Tenant tables present: `employees` (`000304`), `branches` (`000501`), `locations` (`000502`), `products` (`002704`), `customers` (`002802`), `accounts`, `fiscal_years`, `fiscal_periods`, `number_sequences`. **Absent: `departments`, `suppliers`** | `Schema::create` sweep of `migrations-tenant/` | D-16/F-13 holds: those two dimensions unguarded and unchecked |
| 26 | `fiscal_years.retained_earnings_entry_id` is `IdentifierColumn::nullable` with no key; `FinanceTenantSchemaTest` pins "exactly the six documented foreign keys" (:262) and the key **absent** (:286) | `003202:42`; `packages/finance/tests/Schema/FinanceTenantSchemaTest.php` | Step A3 edits both |
| 27 | Stored generated columns are house practice on both engines and the stand-in (`accounts.control_marker` `storedAs`) | `003201:75` | `source_purpose_key` uses the same mechanism |
| 28 | The latest contract-shape migration counts orphans with `whereNotExists(... from(<target>) ...)` and refuses naming table, column and count | `003208:46-90` | `003304` follows it |
| 29 | brick/math ^0.14 in `catalog`, `customers`, `pricing`, `sales`; **not** in finance | `packages/*/composer.json` | Finance adds `brick/math: ^0.14` (already locked) |
| 30 | `QaFinanceSourceRulesTest` exists and pins: `QA_FINANCE_WRITE_SLICES` and `QA_FINANCE_READ_SLICES` must equal the slice directories exactly (:40-49, :404-430); every `->…Unlocked(` call in `packages/finance/src` outside `AccountRows`/`PeriodRows` must be on the exact `QA_FINANCE_UNLOCKED_CALLERS` allow-list, and every read slice must be on it (:62-73, :270-292, :496-511); outside Finance's `src` no file may name `table('accounts'|'fiscal_years'|'fiscal_periods')` except the seed, and the exemption is asserted **exact** (:319-338, :611-623) | `modules/host/tests/Feature/QaFinanceSourceRulesTest.php` | Steps A3, C1, D2 sync it (section 8) |
| 31 | `BranchForeignKeysContractRunTest` walks a fleet to the top one contract stop at a time, each stop found by name: `bfkRunT032V()` (:133-142), "exactly five runs" (:317-390), fresh tenant "six runs" with an explicit `$stops` list (:392-420) | `modules/host/tests/Feature/BranchForeignKeysContractRunTest.php` | `003304` is a new contract step: one more stop (step A3) |
| 32 | `TenantBaselineBuildsTest`: whole-tree FK list (:300-490), the list of tables keyed onto `branches` (:493-522), `number_sequences` count `3` (:268) | file | +14 FK lines, +2 tables, count 4 |
| 33 | Absolute or unscoped `number_sequences` reads: `DocumentNumbersTest` `dnRowCount()` (:90-92; asserted :139, :171, :211, :264); `CompleteSaleTest` `count()` (:375) and `value('next_number')` (:293, :562); `OpenCashShiftTest` `count()` (:259) and `value('next_number')` (:140, :339) | grep | The seeded `journal_entry` row is the first row of every fixture tenant: each goes red (section 8). Whole-table snapshots compared before and after (`CloseCashShiftTest:337`, `VoidSaleTest:474`) stay green |
| 34 | Dynamic, expected green with no edit: `AuditCatalogueTest:48-49` (module list, `finance` present), `QaAccessHttpSurfaceTest` (keys read from the registry, :304; branch-scoped list :684 unaffected), `VerticalSliceLayoutTest`, `ShippedTenantTreeTest` (contract versions derived), `OwedAccountColumnsMigrationsTest` (scoped to `2026_11_15_0032*`), `LoyaltySaleIdMigrationsTest:427` (`>=`), `SchemaCataloguePublishTest` (derived counts, then the committed fixture) | grep | Run targeted, no edit |

### 0.3 Points for the lead (non-blocking; the plan builds the recommendation)

- **F-22 (AC-81 parenthetical vs. the measured list).** AC-81's parenthetical names five kinds of edit (default bindings, the key count, the foreign-key count, the absent key, absolute `number_sequences` counts). The house's "whoever ships next updates this" pins that a new migration and new slices force are wider: the tenant table list and its count (`Helpers.php tenantTreeTables()`, `MigrationTreeDisciplineTest`), the catalogue top (`IdempotencyKeysSchemaTest`), the decimal map (`TenantDecimalPrecisionTest`), the per-task exclusion lists (`QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`), the contract-run stops (`BranchForeignKeysContractRunTest`), the `branches`-referencing table list, unscoped `value('next_number')` reads in two Sales tests, and the slice lists, unlocked-caller allow-list and AC-60 exemption of `QaFinanceSourceRulesTest`. **Recommend: read AC-81 as "each listed in plan section 8", with section 8 as the authority, and have `ba` widen the parenthetical in a later revision.** None of these edits loosens an assertion; each one adds the T-033 rows to an exact list.
- **F-23 (spec text out of date, informational).** Spec "Current behaviour" line 5 and Assumption 6 say `docs/database.md` §7 still names `finance.post_closed_period`. As merged, §7 (line 1718) already names `finance.periods.post_adjustment`. No action in this plan; `ba` may correct the spec text.
- **F-24 (confirm the AC-60 exemption over a blind spot).** `003304` must read `fiscal_years` to count orphans. `003208` avoids T-032's AC-60 scan by passing table names as variables, which the scan cannot see. **Plan: `003304` names `table('fiscal_years')` literally and is added to the scan's exact exemption list** (an explicit, reviewed exemption beats passing through a known blind spot). Confirm.

### 0.4 Revision 2 assumptions that changed against the merged code

1. Rev 2 read T-032 from a sibling worktree with uncommitted chunk D; all rows above are now re-read from committed, merged code. Nothing in rev 2's facts was contradicted. Additions: rows 5 (lock-before-port in every caller), 6 (`resubtype`), 7 (gate lock-order docblock), 9 (single-row gate read), 11, 12, 13, 28, 30 to 34.
2. `Authorize` is an instance service, not a static call (row 16).
3. `QaFinanceSourceRulesTest` exists now, and its exact lists go red as soon as a journal file calls an `*Unlocked` method or a journal slice directory exists (row 30), so its sync moves into steps C1 and D2, not E1.
4. `BranchForeignKeysContractRunTest` was missing from rev 2's section 8 (row 31).
5. The `branches`-referencing list and the four `value('next_number')` reads were missing (rows 32, 33).
6. `FinanceSeed::clearSeed` and `FinanceAuthorizationMatrixTest` need no edit: T-033 adds `JournalSeed` and `JournalAuthorizationMatrixTest` (no completeness assertion exists in the T-032 matrix, only its own list).
7. §7 of `docs/database.md` is already correct (F-23).

### 0.5 Findings closed

| Finding | Closed by | What the plan builds |
|---|---|---|
| F-1 to F-14 (rev 1) | D-24 to D-31 | As in rev 2 (FKs in the create step, CHECK text on the stand-in, seed plus allocator default, audit names and null actor, three new codes, reverse never conflicts, race loser, `opening` without key, normalisation, AC-63 source scan, AC-64 stand-in plan, unguarded `department_id`/`supplier_id`, late offline sale note) |
| F-15 `post()` with `reversal` | D-32, AC-6a | `invalid_source_type` at shape time |
| F-16 existing tests | D-33, AC-81 | Section 8, complete; see F-22 |
| F-17 one combined CHECK | D-34, AC-45 | `oneSided` only |
| F-18 reversal dimensions | D-35, AC-55 | No reference check in `reverse` |
| F-19 `number` order | D-36, AC-69 | Accepted, documented |
| F-20 read-handler refusals | D-37, AC-68 | `FinanceRefused` only; `FinanceRefusal::EntryNotFound` |
| F-21 `invalid_actor` position | D-38, AC-73a | After balance, before replay |
| Slice placement, adjustment key | D-39 | `src/Features/`; `finance.periods.post_adjustment` |

### 0.6 Rulings

**Ruling 1: placement.**
- `packages/contracts/src/Finance/` (new, 10): `PostingService`, `PostingRequest`, `PostingLine`, `ReversalRequest`, `PostedEntry`, `PostingRefusal`, `PostingRefused`, `JournalEntryStatus`, `JournalSourceType`, `JournalDimension`.
- `packages/finance/src/Journal/`: `DatabasePostingService`, `PostingInput`, `ReversalInput`, `ParsedPosting`, `ParsedReversal`, `Amounts`, `Fingerprint`, `JournalRows` (every journal read, named by lock mode), `JournalReferences` (AC-15a), `JournalWriter` (the **only** writer of journal rows), `Ports/{JournalAccountUsage, JournalFiscalYearUsage, JournalPeriodCloseBlockers}`.
- `packages/finance/src/Features/{GetJournalEntry, ListJournalEntries, PostManualJournalEntry, ReverseJournalEntry}/`.
- No new package, deptrac edge, route, command, job, outbox event or cache.

**Ruling 2: the published contract** (AC-1, AC-4, AC-16, AC-16a; plain PHP, no vendor import; `QaFinanceSourceRulesTest:738` keeps `contracts/src/Finance` pure).

| Symbol | Shape |
|---|---|
| `PostingService` | `post(PostingRequest $r): PostedEntry`; `reverse(ReversalRequest $r): PostedEntry`. Docblock: run inside the caller's open transaction (`LogicException` otherwise); call it **after every other lock the caller takes, including its own document number**; **several calls in one transaction must be in ascending `entry_date` order** (the gate's rule, fact 7); never opens, retries or commits a transaction; checks no permission |
| `PostingLine` | readonly `mixed $accountId, mixed $debit = '0', mixed $credit = '0', mixed $memo = null, array $dimensions = []`. Never throws |
| `PostingRequest` | readonly `mixed $sourceType, $sourceId, $sourcePurpose, $entryDate, $description, $branchId, $lines, ?string $actorId`; `static fromArray(array $body, ?string $actorId, list<string> $exclude = []): self` refuses `unknown_field` naming **every** unknown key at entry and line level in one throw (`$exclude` lets `PostManualJournalEntry` drop `source_type`, AC-71). Omitted side → `'0'`; explicit `null` kept and refused later (`invalid_amount`) |
| `ReversalRequest` | readonly `mixed $entryId, $entryDate, $reason, ?string $actorId`, with `fromArray` (keys `entry_id`, `entry_date`, `reason`) |
| `PostedEntry` | readonly `id, number, periodId, entryDate, totalDebit, totalCredit, status (JournalEntryStatus), replayed` (AC-4) |
| `JournalEntryStatus` | `draft`, `posted`, `reversed` |
| `JournalSourceType` | D-7's 26 values in the document's order (`reversal` included: it is the stored type of a reversal entry; `post()` refuses it, AC-6a) |
| `JournalDimension` | `branch_id`, `location_id`, `department_id`, `employee_id`, `customer_id`, `supplier_id`, `product_id`, fixed order (input, fingerprint, references, writer) |
| `PostingRefusal` | 27 codes: `unknown_field`, `invalid_source_type`, `invalid_source_id`, `invalid_source_purpose`, `invalid_date`, `invalid_description`, `invalid_branch`, `invalid_lines`, `too_few_lines`, `too_many_lines`, `invalid_account_id`, `invalid_memo`, `invalid_dimension`, `invalid_amount`, `line_both_sides`, `line_zero`, `entry_unbalanced`, `total_out_of_range`, `invalid_actor`, `idempotency_conflict`, `unknown_reference`, `entry_not_found`, `not_reversible`, `already_reversed`, `reversal_before_original`, `reason_required`, `invalid_reason` |
| `PostingRefused` | `RuntimeException`, private constructor, named constructors. `public readonly PostingRefusal\|PeriodRefusal\|AccountRefusal $reason` (gate and account codes pass through unchanged, AC-23, AC-28), `?string $field`, `list<string> $fields` (`unknown_field`), `array $context` (both totals for `entry_unbalanced`; stored id for `idempotency_conflict`/`already_reversed`; field path and line index for `unknown_reference`). `getPrevious()` is the gate or account exception |

**Ruling 3: `post()` order** (D-21 as amended by D-26 and D-38). Each pure step stops at the first failing field in request order, lines ascending, dimensions in `JournalDimension` order.
- (0) `LogicException` when `transactionLevel() < 1`, before parse or read (AC-2).
- (1) Unknown keys (`fromArray`; dimension keys re-checked in `post` for a directly constructed request, AC-15, AC-16).
- (2) **Shape**, request order: `source_type` exact `tryFrom`, **then `reversal` refused `invalid_source_type`** (AC-6, AC-6a, D-32); `source_id` required unless `manual`/`opening`, ULID folded upper (AC-7); `source_purpose` `\A[a-z][a-z0-9_]{0,29}\z`, never without `source_id` (AC-8); `entry_date` via `Input::date` (AC-9); `description` (AC-10, AC-14); `branch_id` (AC-11); `lines` list, 2 to 200 (AC-12); per line `account_id` (AC-13), `memo` (AC-14, 255), dimensions (AC-15).
- (3) **Amounts** per line: `is_string`, `\A(0|[1-9][0-9]{0,13})(\.[0-9]{1,4})?\z`, then exactly one side positive (AC-17 to AC-19).
- (4) **Balance**: exact `BigDecimal` sums at scale 4 → `entry_unbalanced` with both totals; then `total_out_of_range` above `99999999999999.9999` (AC-20, AC-21).
- (5) **Actor**: null passes; otherwise ULID and `EmployeeDirectory::isLive`, else `invalid_actor` (AC-73a, D-38). The first read of the call.
- (6) **Replay** lookup (Ruling 7 step 2).
- (7) **Gate**, (8) **accounts** ascending, (9) **references** (AC-15a), (10) **number**, (11) **write**.
Every service refusal is `PostingRefused`, never `FinanceRefused`.

**Ruling 4: amounts** (`Journal\Amounts`, pure, brick/math), unchanged: Finance's own stricter copy of the decimal rules (no int input, no leading zeros, `\A…\z`, `RoundingMode::Unnecessary`); output always four decimals; `fromStored(mixed)` parses string or int exactly and a stand-in float through `BigDecimal::of((string) $value)`. No `(float)`, `floatval`, `round(`, `number_format(` in `Journal/`.

**Ruling 5: idempotency key and fingerprint** (AC-34 to AC-41, D-6, D-23, D-29, D-30), unchanged.
- Key: `unique(source_type, source_id, source_purpose_key)`, `source_purpose_key VARCHAR(30)` stored generated `COALESCE(source_purpose, '-')`. `docs/database.md:132` requires the sentinel of a non-identifier column to be a value the application rejects on write: AC-8's pattern rejects `'-'`. `source_id` stays plain nullable, so `manual`/`opening` without one escape the key (AC-41).
- Fingerprint: `payload_fingerprint CHAR(64)`, lower-case hex SHA-256 of canonical JSON (`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`) of `[entry_date, branch_id, description, [[account_id, debit4, credit4, memo, …7 dimensions], …]]` over stored values; line order significant; actor and clock excluded.
- Reversal: key `(reversal, original_id, '-')`; fingerprint over `[entry_date, trimmed reason]`. Because `post()` refuses `reversal` (AC-6a), only `reverse()` can occupy that key; it is also the database backstop for one reversal per entry (AC-57).

**Ruling 6: numbering through `DocumentNumbers`** (D-8, D-25, AC-32 to AC-33b), unchanged. `next('journal_entry', null)`, always company-wide, inside Ruling 7's savepoint after every check. Seed `003303` inserts the row if absent (`branch_id` null so `branch_id_key = '-'`, `document_type = journal_entry`, prefix `JE-`, padding 6, `never`, next 1). `DocumentNumbersRefused` is a deployment fault and propagates.

**Ruling 7: the `post()` algorithm.**
1. Ruling 3 steps 0 to 5.
2. **Replay**, only when `source_id` is non-null: `JournalRows::byKeyUnlocked` (plain read: no gap lock, so two first posts of one key cannot deadlock on it). Same fingerprint → `PostedEntry` from the stored row, `replayed = true`; different → `idempotency_conflict` with the stored id. No gate, accounts, number or audit (AC-34, AC-37, AC-38).
3. `PeriodGate::clear(entry_date, actor)`; `PeriodGateRefused` → `PostingRefused(reason: PeriodRefusal, field: entry_date)`.
4. Distinct account ids, ascending `strcmp` on upper-case ULIDs (fact 11), `ChartOfAccounts::postable` once each; `AccountRefused` → `PostingRefused(reason: AccountRefusal, field: lines.{first index}.account_id)`.
5. `JournalReferences::check` (AC-15a): one `whereIn('id')->whereNull('deleted_at')` read per target (`branches` for entry and line `branch_id`, `locations`, `employees`, `customers`, `products`); `department_id`, `supplier_id` skipped. Plain reads, by design: the FK guarantees existence, a concurrent soft delete leaves a valid historical reference, and a shared lock on hot catalog and customer rows would add contention to every sale. First missing reference in request order → `unknown_reference` with path.
6. **Savepoint** (nested `$connection->transaction(fn)`, one attempt): `DocumentNumbers::next`; insert the entry; insert **all lines in one statement**; `AuditTrail::record`.
7. `UniqueConstraintViolationException` from the savepoint: the savepoint rolled back, so the sequence increment is undone. Re-read the key `JournalRows::byKeyForUpdate`: a row → replay or `idempotency_conflict`; none → rethrow (a duplicate `number` is an invariant failure) (AC-40, AC-33a). The allocator's own catch (fact 21) never reaches here.
8. Anything else propagates. No retry, no deadlock catch: the caller's `transaction(fn, attempts: 2)` owns retry.
A fresh `PostedEntry` is built from the computed strings, never re-read (risk 4).

**Ruling 8: the `reverse()` algorithm** (AC-49 to AC-58, D-9, D-14, D-23, D-28, D-35).
1. Transaction guard; unknown keys.
2. **Shape** in request order: `entry_id` (malformed → `entry_not_found`), `entry_date` (`invalid_date`), `reason` (missing or blank → `reason_required`; over 500 or a control character → `invalid_reason`).
3. **Actor** (`invalid_actor`, D-38: after the pure checks, before any entry read).
4. **Original** by id, plain read (`JournalRows::entryByIdUnlocked`): none → `entry_not_found`; `source_type = reversal` or status `draft` → `not_reversible`.
5. **Replay** on `(reversal, original_id, '-')`, plain: same fingerprint → that reversal, `replayed = true`; different → `already_reversed` (never `idempotency_conflict`).
6. `entry_date < original.entry_date` → `reversal_before_original` (same day accepted).
7. **Gate** on the reversal date; then **accounts** of the original's lines through `postable`, ascending (D-14). **No reference check**: the entry `branch_id` and every line dimension are copied as stored, and no `unknown_reference` is raised (AC-55, D-35); the FKs still hold because soft-deleted rows still exist.
8. Original `FOR UPDATE` (`JournalRows::entryForUpdate`); status no longer `posted` → re-read the reversal key `FOR UPDATE` → replay or `already_reversed`.
9. Savepoint: number; insert the reversal (`source_type = reversal`, `source_id` and `reversal_of_id` = original, `branch_id` = original's, `description` = reason) and its lines (sides swapped; same account, memo, dimensions, `line_no`) in one statement; `UPDATE journal_entries SET status = 'reversed', reversed_by_id = ?, updated_at = ? WHERE id = ? AND status = 'posted'`, asserting exactly 1 row; one audit row `journal_entry_reversed`.
10. Unique violation from the savepoint → step 8's re-read.

**Ruling 9: lock order** (re-verified against facts 5, 7 to 12).

| Order | `post` | `reverse` |
|---|---|---|
| L0 | the caller's own locks, including its own document number | the caller's own locks |
| L1 | period row, shared (gate, one index entry) | period of the reversal date, shared |
| L2 | accounts, shared, ascending id | accounts of the original lines, shared, ascending |
| L2b | (none) | original entry `FOR UPDATE` |
| L3 | `journal_entry` sequence row `FOR UPDATE` (hottest; held to the caller's commit) | same |
| L4 | insert entry and lines | insert reversal and lines; compare-and-set on the original |

Against T-032 as built: every port caller takes its exclusive lock on the account (`DeleteAccountHandler:59`, `UpdateAccountHandler:85`) or the year and periods (`DeleteFiscalYearHandler:51, :75`, `TransitionFiscalPeriodHandler:97, :113`) **before** it asks the port, so a poster holding the shared lock makes it wait (AC-26, AC-30) and a poster arriving later waits at L1 or L2. The poster takes only shared locks on periods and accounts, so it cannot close a cycle with T-032's own known pairs (retype, reactivate, re-parent; T-032 D-33), and no T-032 transaction takes an account lock and then a period lock, so period-then-accounts is never reversed. Ascending account order equals `AccountRows::allForUpdate`'s `orderBy('id')`. `reverse` takes L1 and L2 **before** L2b because a close holds the period exclusively and then scans entries in it. Two posters order on L1 S, L2 S, L3 X and cannot deadlock. The ports read with `sharedLock()`. Remaining engine risk (NOT VERIFIED): a caller that posts twice in one transaction takes new L2 locks after holding L3; against a concurrent account admin whose port read lands on a key it holds, InnoDB can roll one back (1213), data stays correct, retries absorb it (risk 3). All of it is NOT VERIFIED under ENGINE_HOLD, as T-032's own lock claims are.

**Ruling 10: schema.** Two expand files with all their own keys, uniques, indexes and CHECKs: `CheckConstraint::oneSided('journal_lines', 'debit', 'credit')` (constraint `journal_lines_debit_credit_one_sided`, text `CHECK ((debit = 0 OR credit = 0) AND debit >= 0 AND credit >= 0)`, which states both AC-45 conditions, D-34) and new `CheckConstraint::balanced('journal_entries', 'total_debit', 'total_credit')` (constraint `journal_entries_total_debit_total_credit_balanced`, same engine-only shape, literal verb, `assertIdentifier`, pure `balancedStatement()`). One backfill seed. One contract file for `fiscal_years.retained_earnings_entry_id` in the `003208` shape (fact 28): orphan count first via `whereNotExists(from('journal_entries'))`, refuse naming table, column and count but never a value; skip a key already present; no `down()`; names `table('fiscal_years')` literally (F-24).

**Ruling 11: the three ports** (`Journal\Ports\*`, AC-59 to AC-64, D-12), unchanged. `sharedLock()` reads of journal tables only:
- `hasPostings`: `journal_lines WHERE account_id = ? LIMIT 1` on `(account_id, entry_id)`, any status; malformed or unknown → false.
- `hasEntries($yearId, $start, $end)`: `journal_entries WHERE entry_date BETWEEN ? AND ? LIMIT 1`, any status.
- `blockersFor($periodId, $start, $end)`: `COUNT(*) WHERE status = 'draft' AND entry_date BETWEEN ? AND ?` → `[PeriodBlocker('draft_journal_entries', n)]` or `[]`.
The provider replaces its lines 48 to 50; the `No*` classes stay for tests.

**Ruling 12: handlers** (AC-68 to AC-74, D-10, D-37). `Authorize::require` (injected instance, fact 16) is the first statement, before any transaction or read.

| Slice | Key | Notes |
|---|---|---|
| `GetJournalEntry` | `finance.journal.view` | by `id`, or by `(source_type, source_id, source_purpose)` through `source_purpose_key`; inputs validated case-sensitively (`validation`); lines by `line_no`; amounts via `Amounts::fromStored`; reads through `JournalRows::*Unlocked`; unknown → `FinanceRefused(entry_not_found)`. Throws only `FinanceRefused` |
| `ListJournalEntries` | `finance.journal.view` | page 1 to 200, default `finance.page_size`; out of range `page_size_invalid`; keyset cursor `(after_entry_date, after_number, after_id)` all or none, else `cursor_invalid`; filters `from`, `to` (strict `Y-m-d`), `status`, `source_type`, `period_id`, `account_id` (`EXISTS` on lines), `branch_id`; invalid → `validation`; order `entry_date DESC, number DESC, id DESC`. **`number` compares as a string: correct while every number has the same width (below 1,000,000 entries at padding 6) and the prefix is unchanged; a change to padding or prefix must revisit this order (D-36).** Summary rows only; `from > to` → empty page. Throws only `FinanceRefused` |
| `PostManualJournalEntry` | `finance.journal.post_manual` | `PostingRequest::fromArray($body, $actor, exclude: ['source_type'])`, type fixed to `manual`; `transaction(fn, attempts: 2)`; refusals: `FinanceRefused(not_permitted)`, otherwise the service's `PostingRefused` |
| `ReverseJournalEntry` | `finance.journal.reverse` | `transaction(fn, attempts: 2)` around `PostingService::reverse`, actor = the employee |

`FinancePermissions` gains `JOURNAL_VIEW`, `JOURNAL_POST_MANUAL`, `JOURNAL_REVERSE` (module `finance`, group `journal`, not branch-scoped), appended to `KEYS`. `FinanceRefusal` gains `EntryNotFound = 'entry_not_found'` (the same string as `PostingRefusal::EntryNotFound`; precedent: `account_not_found` in both `FinanceRefusal` and `AccountRefusal`).

**Ruling 13: audit** (AC-65 to AC-67, D-19, D-27), unchanged. `AuditTrail::record('finance', $actor, ACTION, 'journal_entry', $id, null, $after)` inside the savepoint; `after` = `number`, `source_type`, `source_id`, `source_purpose`, `entry_date`, `period_id`, `total_debit`, `total_credit`, `line_count`, `is_auto`, plus `reason`, `original_id` for a reversal. `ACTION_POSTED = 'journal_entry_posted'`, `ACTION_REVERSED = 'journal_entry_reversed'` on `DatabasePostingService`, collected into `FinanceAuditActions::ALL`.

**Ruling 14: test cost**, unchanged: pure datasets for most cases; one fixture-backed file per service operation and per slice; every refusal asserts no entry, no line, no audit row and an unchanged `next_number`. About 245 cases, about 60 % pure. A chunk adding more than 10 % to the measured `test` step stops and reports to `lead`.

**Ruling 15: keeping T-032's source scan exact** (fact 30). `JournalRows` never calls its own `*Unlocked` methods through `->` (the scan exempts only `AccountRows` and `PeriodRows`). Every `->…Unlocked(` call in a Finance file is added to `QA_FINANCE_UNLOCKED_CALLERS` in the same step that writes it, method by method: `Journal/DatabasePostingService.php` (`byKeyUnlocked`, `entryByIdUnlocked`), `Journal/JournalReferences.php` (its plain reads, if named `*Unlocked`), `Features/GetJournalEntry/GetJournalEntryHandler.php`, `Features/ListJournalEntries/ListJournalEntriesHandler.php`. The slice lists gain `PostManualJournalEntry`, `ReverseJournalEntry` (write) and `GetJournalEntry`, `ListJournalEntries` (read). The AC-60 exemption gains `003304` (F-24).

---

## Approach summary

1. Preconditions are met at `5a6b8a0`; start chunk A with a short baseline read (V0 = 81).
2. Add `journal_entries` and `journal_lines` with every own key, unique and engine CHECK in the expand step (the combined `oneSided` line check, the new `balanced` entry check), a seeded `journal_entry` sequence row, and one contract key on `fiscal_years`.
3. Publish `PostingService` with `mixed`-typed value objects, so every refusal comes out of one deterministic pass (shape, amounts, balance, actor, replay, gate, accounts, references) as one `PostingRefused` that carries period and account codes unchanged; `post()` refuses the `reversal` type.
4. Exact brick/math string arithmetic; no float anywhere.
5. Idempotency is a stored-sentinel unique key plus a SHA-256 fingerprint of the normalised payload; replay runs before the gate; a key collision is resolved inside a savepoint, so nothing that did not commit consumes a number.
6. One lock order inside the caller's transaction (period S, accounts S ascending, [original X], sequence X), compatible with every T-032 handler's lock-then-port order; the service never retries.
7. Reversal is the only update (compare-and-set on `status`) and copies dimensions without re-checking them.
8. Rebind T-032's three ports to `sharedLock` journal reads; four permission-checked slices; source scans prove the one-writer rule; an engine test (held) carries every `[ENGINE]` clause.

## Alternatives rejected

- A Finance-owned allocator: a second writer of `number_sequences` (Ruling 6).
- A nullable `source_purpose` directly in the unique key: NULLs are distinct on both engines (AC-39).
- Key-only replay without a fingerprint: a retry with different content would silently return the old entry (AC-35).
- Re-checking the key under the sequence lock instead of a savepoint: needs `DocumentNumbers` split into lock and increment, a contract change, for the same result.
- A shared-lock replay pre-check: two first posts of one key take gap locks and deadlock on insert.
- Shared locks on dimension targets: contention on hot product and customer rows for no integrity gain.
- Value objects that validate in their constructors: refusals would escape the fixed order.
- Two separate line CHECKs through a second kernel helper: `oneSided` already states both (D-34).
- Writing `003304` with variable table names like `003208`: passes T-032's AC-60 scan through its blind spot (F-24).
- Editing `FinanceSeed::clearSeed`, `FinanceAuthorizationMatrixTest` or `FinanceWidthsTest`: T-032 files; `JournalSeed`, `JournalAuthorizationMatrixTest` and `JournalWidthsTest` instead.
- A trigger for the balance rule (D-20); `account_balances` now (D-11); a separate journal package (the ports must rebind inside Finance).

## Files to change / create

`dev` writes every file; `Qa*` files are `qa`-accountable. Edits to existing files are all in section 8.

### `packages/contracts/src/Finance/` (new, 10)
Ruling 2.

### `packages/finance`

| Path | What |
|---|---|
| `composer.json` (edit) | `brick/math: ^0.14` |
| `src/Support/Limits.php` (edit) | `MEMO = 255`, `REASON = 500`, `NUMBER = 30`, `FINGERPRINT = 64`, `MIN_LINES = 2`, `MAX_LINES = 200` |
| `src/Exceptions/FinanceRefusal.php` (edit) | `EntryNotFound` |
| `src/Journal/{DatabasePostingService, PostingInput, ReversalInput, ParsedPosting, ParsedReversal, Amounts, Fingerprint, JournalRows, JournalReferences, JournalWriter}.php` | Rulings 3 to 9 |
| `src/Journal/Ports/{JournalAccountUsage, JournalFiscalYearUsage, JournalPeriodCloseBlockers}.php` | Ruling 11 |
| `src/Features/GetJournalEntry/{GetJournalEntry, GetJournalEntryHandler, GetJournalEntryResult}.php` | Ruling 12 |
| `src/Features/ListJournalEntries/{ListJournalEntries, ListJournalEntriesHandler, ListJournalEntriesResult}.php` | Ruling 12 |
| `src/Features/PostManualJournalEntry/{PostManualJournalEntry, PostManualJournalEntryHandler}.php` | Ruling 12 |
| `src/Features/ReverseJournalEntry/{ReverseJournalEntry, ReverseJournalEntryHandler}.php` | Ruling 12 |
| `src/FinanceServiceProvider.php` (edit) | bind `PostingService`; replace the three port bindings; docblock |
| `src/Registry/{FinancePermissions, FinanceAuditActions}.php` (edit) | three keys; two actions |
| `tests/Contracts/JournalContractsTest.php` | B1 |
| `tests/Journal/{PostingInputTest, ReversalInputTest, AmountsTest, FingerprintTest, PostingServicePostTest, PostingServiceIdempotencyTest, PostingServiceGateAndAccountsTest, PostingServiceReferencesTest, PostingServiceReverseTest, JournalPortsTest}.php` | chunks B to D |
| `tests/Features/{GetJournalEntry, ListJournalEntries, PostManualJournalEntry, ReverseJournalEntry}/<Slice>Test.php` | D2 |
| `tests/Features/JournalAuthorizationMatrixTest.php` | D2 (the T-032 matrix's shape, for the four journal slices) |
| `tests/Schema/{JournalTenantSchemaTest, JournalWidthsTest}.php` | A2 |
| `tests/Support/JournalSeed.php` | builds on `FinanceSeed` (year, accounts, actors per key); `clear()` deletes `journal_lines`, `journal_entries`, nulls `fiscal_years.retained_earnings_entry_id`, then calls `FinanceSeed::clearSeed()`; raw-insert `draft` helper (tests only) |

### `packages/kernel`
- `src/Schema/Support/CheckConstraint.php` (edit): `balanced()`, `balancedStatement()`.
- `tests/Schema/Support/CheckConstraintTest.php` (edit).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php` (edit): `'journal_entry' => 'JE-'`.
- `tests/Numbering/DocumentNumbersTest.php` (edit, section 8).

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_16_003301_create_journal_entries_table.php` | `@phase expand` |
| `database/migrations-tenant/2026_11_16_003302_create_journal_lines_table.php` | `@phase expand` |
| `database/migrations-tenant/2026_11_16_003303_seed_journal_entry_number_sequence.php` | `@phase backfill` |
| `database/migrations-tenant/2026_11_16_003304_add_retained_earnings_entry_foreign_key.php` | `@phase contract` |
| `database/catalogue/tenant_schema_versions.json` | regenerated by `schema:publish --write-fixture`, never hand-edited |
| `tests/Feature/JournalMigrationsTest.php` | A3 |
| `tests/Feature/QaJournalSourceRulesTest.php` | E1 |
| `tests/Engine/JournalSchemaEngineTest.php` | E2 |

### Documents (step E3)
`docs/database.md` §6.10 (new "As built (T-033)" block; the T-032 block's "Not built" sentence about `retained_earnings_entry_id` and the "until FP-261 binds" sentence become past), §3.3 (the ledger's double-post guard leaves the "do not yet carry it" count), §12.2 (late-period note); `docs/architecture.md` §3 (Finance owns the journal). §7 needs no edit (F-23).

## Data / API changes

**Schema** (tenant only, no master change):

| Table | Columns | Keys, indexes, checks |
|---|---|---|
| `journal_entries` | `id` (`IdentifierColumn::primary`), `number` VARCHAR(30), `entry_date` DATE, `period_id` ULID, `branch_id` ULID NULL, `source_type` VARCHAR(30), `source_id` ULID NULL, `source_purpose` VARCHAR(30) NULL, `source_purpose_key` VARCHAR(30) stored `COALESCE(source_purpose, '-')`, `status` VARCHAR(30), `total_debit`, `total_credit` DECIMAL(18,4), `description` VARCHAR(500) NULL, `reversal_of_id`, `reversed_by_id` ULID NULL, `payload_fingerprint` CHAR(64), `posted_at` DATETIME(3), `posted_by` ULID NULL, `is_auto` BOOL default false, `created_by` ULID NULL, `created_at`, `updated_at` DATETIME(3). No `deleted_at`, tenant, currency or `plugin_name` | unique `number`; unique `(source_type, source_id, source_purpose_key)`; index `(entry_date, number)`; index `(period_id, status)`. FKs (RESTRICT, 6): `period_id` → `fiscal_periods`, `branch_id` → `branches`, `posted_by`, `created_by` → `employees`, `reversal_of_id`, `reversed_by_id` → `journal_entries`. `balanced` CHECK `[ENGINE]` |
| `journal_lines` | `id`, `entry_id`, `line_no` SMALLINT UNSIGNED, `account_id`, `debit`, `credit` DECIMAL(18,4) default 0, `memo` VARCHAR(255) NULL, `branch_id`, `location_id`, `department_id`, `employee_id`, `customer_id`, `supplier_id`, `product_id` (ULID NULL), `created_at` DATETIME(3). No unit, quantity, currency, `project_ref`, `tax_id`, `tax_base` | unique `(entry_id, line_no)`; index `(account_id, entry_id)`; indexes `customer_id`, `supplier_id`, `employee_id`. FKs (7): `entry_id` → `journal_entries` CASCADE; `account_id` → `accounts`, `branch_id` → `branches`, `location_id` → `locations`, `employee_id` → `employees`, `customer_id` → `customers`, `product_id` → `products` (RESTRICT). `department_id`, `supplier_id` unguarded and owed. `oneSided` CHECK `[ENGINE]` |
| `number_sequences` | one row: `branch_id` null, `document_type` `journal_entry`, `prefix` `JE-`, `padding` 6, `reset_period` `never`, `next_number` 1 | insert-if-absent |
| `fiscal_years` | none | FK `retained_earnings_entry_id` → `journal_entries` RESTRICT (contract) |

FK inventory +14 (6 + 7 + 1). Decimal columns +4, all (18,4). Tables +2 (69 → 71). Catalogue +4 rows (projected 82 to 85; `APP_REQUIRED_SCHEMA_VERSION` = the measured top).

**Contracts:** Ruling 2. **Permissions:** `finance.journal.view`, `finance.journal.post_manual`, `finance.journal.reverse`. **Audit actions:** `journal_entry_posted`, `journal_entry_reversed`. **Refusals:** `PostingRefusal` (new), `FinanceRefusal::EntryNotFound`. **HTTP, console, jobs, outbox, cache:** none. **Dependency:** brick/math in Finance's `composer.json` only (already locked at 0.14.8; the lock diff is the path package's `require`).

## Implementation steps

Working rules (from T-032): red-first by fixture or omission, never by stubbing a guard open; no child processes; unique test basenames; each mutation run once, reverted byte for byte, recorded with red-then-green counts in `dev`'s hand-back. **Inside a chunk** `dev` runs only the targeted pest files named in the step plus `composer lint`, `composer stan`, `composer deptrac`. **The lead schedules the single full `composer check`** (once, at E4; a baseline only if T-032's DONE gate result is not reusable). Targeted runs from `modules/host`: `"/e/xampp/php/php.exe" artisan config:clear` then `"/e/xampp/php/php.exe" vendor/bin/pest <path> [<path> ...]`. Anything red that section 8 does not predict: stop and report to `architect`.

### Chunk A: base, schema, static instruments

- [x] **Step A0: baseline read** (`dev` reads only). *(No AC.)*
  - `git log -1` is `5a6b8a0` or a descendant with no change under `packages/finance/src`, `packages/organization/src/Numbering`, `packages/kernel/src/Schema/Support`, `modules/host/database` since; otherwise re-read the affected rows of section 0.2.
  - Record V0 = the catalogue top (81 expected) and `.env.example`'s value (81 expected).
  - Re-grep `table('number_sequences')` in every `tests/` directory; any absolute count or unscoped `value(`/`first(` not in section 8 is added before A2.
- [x] **Step A1: plumbing.** *(Groundwork; AC-33b.)*
  1. `packages/finance/composer.json`: `"brick/math": "^0.14"`; from `modules/host`: `"/e/xampp/php/php.exe" "/c/ProgramData/ComposerSetup/bin/composer.phar" update flexipos/finance`, then `validate --no-check-publish` in `packages/finance`.
  2. `Limits.php`: the six constants.
  3. `CheckConstraint.php`: `balanced()` (engine-only, literal verb, `assertIdentifier` on all three) and `balancedStatement()`.
  4. `CheckConstraintTest.php`: exact text ``ALTER TABLE `journal_entries` ADD CONSTRAINT `journal_entries_total_debit_total_credit_balanced` CHECK (`total_debit` = `total_credit`)``; `Total_Debit` refused; nothing issued on SQLite.
  5. `DatabaseDocumentNumbers.php`: `'journal_entry' => 'JE-'`.
  6. `DocumentNumbersTest.php`: new case "a missing `journal_entry` row is created as `JE-` / 6 / `never` and yields `JE-000001`" (deletes any `journal_entry` row first, so it holds before and after A2).
  - Targeted: `../../packages/kernel/tests/Schema/Support/CheckConstraintTest.php`, `../../packages/organization/tests/Numbering/DocumentNumbersTest.php`, `tests/Feature/QaToolchainRegistryCoverageTest.php` (expected green, no edit).
- [x] **Step A2: expand and seed migrations, schema and width tests, pinned lists.** *(Schema halves of AC-32, AC-39, AC-41 to AC-45, AC-75, AC-78 to AC-80.)*
  1. `003301_create_journal_entries_table.php`: section "Data / API changes"; docblock states the `'-'` sentinel and why AC-8 makes it safe (`docs/database.md:132`), and that keys are declared with the new table (D-24); `CheckConstraint::balanced` after `Schema::create`.
  2. `003302_create_journal_lines_table.php`: `CheckConstraint::oneSided('journal_lines', 'debit', 'credit')`.
  3. `003303_seed_journal_entry_number_sequence.php`: `@phase backfill`; one transaction; insert only when no row has `branch_id` null and `document_type = journal_entry`; `Str::ulid()` id, `RunnerClock` timestamps (the `003204` imports); no audit row; docblock "two homes" note pointing at the test that compares it with the allocator default.
  4. `"/e/xampp/php/php.exe" artisan schema:publish --write-fixture` (regenerates the catalogue JSON).
  5. `.env.example`: `APP_REQUIRED_SCHEMA_VERSION` = the regenerated top (V0 + 3).
  6. `packages/finance/tests/Support/JournalSeed.php` (new; `clear()` as in "Files").
  7. `packages/finance/tests/Schema/JournalTenantSchemaTest.php` (stand-in, `FinanceTenantSchemaTest` shape, whole tree):
     - exact sorted column lists with nullability; no `tenant_id`, `deleted_at`, currency or unit column (AC-42, AC-43, AC-79);
     - uniques by raw double insert: `number`; `(entry_id, line_no)`; the idempotency key (same type, id, purpose refused; **same type and id with null purpose twice refused**, AC-39; `revenue` and `cogs` coexist; two `manual` rows with null `source_id` coexist, AC-41);
     - the 13 own FKs exactly; `entry_id` cascade deletes lines (raw);
     - `003301`/`003302` call `balanced` and `oneSided` with these arguments (parsed), and the statements equal `balancedStatement`/`oneSidedStatement`; the `oneSided` text contains both `= 0 OR` and `>= 0` (AC-45 stand-in half, D-34);
     - seed: one row with the exact values; `003303` twice leaves one row; a pre-existing row is untouched (AC-32, AC-78); with the seed row deleted, `DocumentNumbers::next('journal_entry', null)` recreates a row whose `prefix`, `padding`, `reset_period` equal the seed's (AC-33b);
     - `SourceRules::inspect()` clean, no `down()`, every ULID column through `IdentifierColumn` (AC-80);
     - versions looked up by migration name, never literal (P-2).
     - **Mutation M8:** key on `source_purpose` instead of `source_purpose_key` → the null-purpose row goes red.
  8. `JournalWidthsTest.php`: every `string()`/`char()` in `003301`/`003302` equals its `Limits` constant. Red-first: a fixture `string('memo', 256)` is reported (AC-44, AC-80).
  9. Pinned host tests (section 8 rows marked A2): `tests/Helpers.php` `tenantTreeTables()`; `MigrationTreeDisciplineTest` (69 → 71); `TenantBaselineBuildsTest` (FK list +13, `branches` list +`journal_entries`, `journal_lines`, sequence count 3 → 4); `TenantDecimalPrecisionTest` (two map entries); `IdempotencyKeysSchemaTest` (top `003303`, backfill, measured version); `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest` (`$t033`).
  10. `number_sequences` reads (section 8 rows A2): scope each to the document types the test owns.
  - Targeted: `../../packages/finance/tests/Schema/JournalTenantSchemaTest.php`, `../../packages/finance/tests/Schema/JournalWidthsTest.php`, `../../packages/finance/tests/Schema/FinanceTenantSchemaTest.php`, `../../packages/finance/tests/Seed/SeedChartTest.php`, `tests/Feature/MigrationTreeDisciplineTest.php`, `tests/Feature/TenantBaselineBuildsTest.php`, `tests/Feature/TenantDecimalPrecisionTest.php`, `tests/Feature/IdempotencyKeysSchemaTest.php`, `tests/Feature/QaAccessCloseOutSweepsTest.php`, `tests/Feature/QaAccessSchemaShapeTest.php`, `tests/Feature/SchemaCataloguePublishTest.php`, `tests/Feature/MigrationHelperAndImportRulesTest.php`, `tests/Feature/OwedAccountColumnsMigrationsTest.php`, `../../packages/organization/tests/Numbering/DocumentNumbersTest.php`, `../../packages/sales/tests/Features/CompleteSale/CompleteSaleTest.php`, `../../packages/sales/tests/Features/OpenCashShift/OpenCashShiftTest.php`, `../../packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php`.
- [x] **Step A3: contract `003304`, with `JournalMigrationsTest`.** *(AC-75 to AC-78, AC-81.)*
  1. `003304_add_retained_earnings_entry_foreign_key.php`: `@phase contract`, the `003208` shape (Ruling 10).
  2. `schema:publish --write-fixture`; `.env.example` = the new top (V0 + 4); `IdempotencyKeysSchemaTest` top `003304`, contract, measured version; `TenantBaselineBuildsTest` FK list +1.
  3. `modules/host/tests/Feature/JournalMigrationsTest.php`:
     - red-first: a year with an orphan `retained_earnings_entry_id` → the run refuses naming `fiscal_years.retained_earnings_entry_id` and `1 row(s)`, no value; no key added (AC-76);
     - clean run: the key exists; re-run is a no-op; across the stand-in rebuild of `fiscal_years`, the `fiscal_years` **and `fiscal_periods`** row counts and every pre-existing key survive (CASCADE parent, risk 7);
     - AC-77: every T-033 catalogue row (by name) is `is_destructive = false`; none adds `NOT NULL` to an existing column; `003303` backfill, `003304` contract; the planner plans `003304` only once every tenant is at the version before it;
     - AC-78: on two fixture tenants the tables are empty and one sequence row was added; nothing else changed.
     - **Mutations:** S1 write the key as a `->change()` → AC-77 red; M17 skip the orphan count → the red-first case goes red.
  4. `FinanceTenantSchemaTest.php`: the F-14 case asserts the key **present**; "six documented foreign keys" becomes seven (AC-81).
  5. `QaFinanceSourceRulesTest.php`: the AC-60 case (:611-623) exempts `QA_FINANCE_OWNING_MIGRATIONS[3]` and `003304`; its "exact exemption" assertion becomes both basenames (F-24).
  6. `BranchForeignKeysContractRunTest.php`: add `bfkRunT033V()` (found by name `2026_11_16_003304_add_retained_earnings_entry_foreign_key`, `bfkRunT032V()`'s shape); the T-011-top case gains a stop at `bfkRunT033V() - 1` (five runs become six, title and comment updated); the fresh-tenant case's `$stops` gains `bfkRunT033V() - 1` before `null` (six runs become seven).
  - Targeted: `tests/Feature/JournalMigrationsTest.php`, `../../packages/finance/tests/Schema/FinanceTenantSchemaTest.php`, `tests/Feature/QaFinanceSourceRulesTest.php`, `tests/Feature/BranchForeignKeysContractRunTest.php`, `tests/Feature/IdempotencyKeysSchemaTest.php`, `tests/Feature/TenantBaselineBuildsTest.php`, `tests/Feature/SchemaCataloguePublishTest.php`, `../../packages/kernel/tests/Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php`; then `lint`, `stan`, `deptrac`. **Chunk A ends**; `dev` hands back counts; the lead decides whether to run a full check here.

### Chunk B: the pure core

- [x] **Step B1: contracts**, `tests/Contracts/JournalContractsTest.php`. *(AC-1, AC-4, AC-16, AC-16a.)* Exact enum lists (D-7's 26 in order; `PostingRefusal`'s 27); every code the spec names is present (AC-16a); `PostingRefused` carries a `PeriodRefusal` or `AccountRefusal` unchanged; `PostedEntry` has exactly the AC-4 properties (reflection); `fromArray` names **every** unknown key at both levels in one throw (`id`, `number`, `status`, `period_id`, `posted_at`, `posted_by`, `reversal_of_id`, `total_debit`, `is_auto`, `project_ref`, `tax_id`, `tax_base`); `exclude: ['source_type']` makes `source_type` unknown; omitted side → `'0'`, explicit `null` kept; no import outside contracts and PHP core.
- [x] **Step B2: `PostingInput` / `ReversalInput`** (pure datasets), `tests/Journal/PostingInputTest.php`, `ReversalInputTest.php`. *(AC-6 to AC-16, AC-6a, shape halves of AC-53, AC-56.)*
  - AC-6: `Sale`, `SALE`, `' sale'`, `''`, null, `'unknown'` refused.
  - **AC-6a:** `reversal` with any `source_id` (absent, a ULID, the id of a stored entry) → `invalid_source_type`, before `source_id` is looked at.
  - AC-7: missing, null, 25 or 27 characters; lower case accepted and folded; optional for `manual`/`opening`.
  - AC-8: `''`, `'-'`, `'1abc'`, `'Revenue'`, 31 characters, `"rev\n"`, a purpose without `source_id`.
  - AC-9: `2026-02-30`, `2026-1-1`, a date-time, `' 2026-01-01'`, null.
  - AC-10/AC-14: 500 multi-byte characters pass, 501 refused; control characters and invalid UTF-8 refused; blank or whitespace description and memo → null; `" x "` → `"x"`.
  - AC-11/AC-13/AC-15: malformed ids name `branch_id`, `lines.{i}.account_id`, `lines.{i}.{dimension}`.
  - AC-12: 1 and 201 lines; `lines` as a map or a string (`invalid_lines`).
  - Order: two wrong fields → the first in request order, lines ascending; a shape error and an amount error → the shape error; an amount error and an imbalance → the amount error.
  - Reversal: malformed id → `entry_not_found`; missing date → `invalid_date`; blank reason → `reason_required`; 501 characters → `invalid_reason`.
  - **M1:** `^…$` for `\A…\z` → the `"rev\n"` row goes red. **M20:** drop the `reversal` refusal → the AC-6a row goes red.
- [x] **Step B3: `Amounts` and balance** (pure), `tests/Journal/AmountsTest.php`. *(AC-17 to AC-22 logic.)* As rev 2: AC-17 `1`, `1.0`, `true`, null; AC-18 every listed string including `'٣'` and `"5\0"`; AC-19; AC-20 `0.1 + 0.2` vs `0.3`, `0.0001` off with both totals; AC-21 200 lines at the maximum → `total_out_of_range`, the exact maximum sum passes; AC-22 `"12.5"` → `"12.5000"`; `fromStored` for string, int and stand-in float. **M2** sum through a float; **M3** round instead of refuse.
- [x] **Step B4: `Fingerprint`** (pure), `tests/Journal/FingerprintTest.php`. *(AC-35, AC-36 logic.)* As rev 2, including line order and the reversal `[date, reason]` form. **M7** sort lines before hashing.
  - Chunk B ends: the B files plus `lint`, `stan`, `deptrac`, and `tests/Feature/QaFinanceSourceRulesTest.php` (contracts purity, FP-435 regex rule over the new files).

### Chunk C: posting and reversal on the database

- [x] **Step C1: `JournalWriter`, `JournalRows`, `DatabasePostingService::post` happy path, numbering, audit**, `tests/Journal/PostingServicePostTest.php` (stand-in through `AccessTenantFixture`). Provider binds `PostingService`; `FinanceAuditActions` gains the two actions; `QaFinanceSourceRulesTest`'s `QA_FINANCE_UNLOCKED_CALLERS` gains `Journal/DatabasePostingService.php` with exactly the `*Unlocked` methods it calls (and `Journal/JournalReferences.php` if it names any) (Ruling 15). *(AC-2, AC-3, AC-22, AC-24, AC-32, AC-33, AC-42, AC-43, AC-46, AC-65, AC-66, AC-73a, AC-74.)*
  - AC-2: outside a transaction → `LogicException`, a query listener sees **zero** statements, for `post` and `reverse`.
  - AC-3: a scratch document row (`settings`), then post, then throw → no document, entry, line or audit row; sequence unchanged; a refused post in the same shape rolls the document back too.
  - AC-22/42/43: stored row compared whole; `posted_at` from the fixed `RunnerClock`; `is_auto` false with an actor, true with null (AC-74); lines 1..N in request order.
  - AC-24, AC-32 (`JE-000001`, `JE-000002` from the company row even with `branch_id` set), AC-33 (numbers 1..N; one refusal per family, a replay and a rolled-back caller leave `next_number` unchanged).
  - AC-46: exactly **one** `insert into journal_lines`; a forced line-insert failure leaves neither row and the sequence unchanged.
  - AC-65/66: one audit row compared whole; none for a refusal.
  - **AC-73a and its position:** a malformed actor and a soft-deleted employee → `invalid_actor`, and no statement after the actor read; a request with a malformed amount **and** a malformed actor → `invalid_amount` with **no** statement at all; an unbalanced request with an invalid actor → `entry_unbalanced`; an invalid actor with a key that has a stored entry → `invalid_actor`, and no read of `journal_entries` (D-38).
  - **M16** one insert per line; **M18** drop the guard; **M21** actor check after the replay lookup → the last AC-73a row goes red.
  - Targeted also: `../../packages/access/tests/Support/AuditCatalogueTest.php` (expected green, no edit), `tests/Feature/QaFinanceSourceRulesTest.php`.
- [x] **Step C2: idempotency**, `tests/Journal/PostingServiceIdempotencyTest.php`. *(AC-34 to AC-41 except `[ENGINE]`.)* As rev 2: AC-34 (no write, no gate call, no `postable` call via spy bindings, no audit, sequence unchanged); AC-35 each difference → `idempotency_conflict`; `"12.5"`/`"12.5000"` and `"x"`/`" x "` replay; AC-36; AC-37 (close the period raw; replay succeeds, conflict stays, a fresh key → `period_closed`); AC-38; AC-39; AC-40 stand-in seam (a spy `PeriodGate` raw-inserts a competing row for the same key; replay or conflict, no `QueryException`, the loser leaves `next_number` unchanged); AC-41. **M4**, **M5**, **M6**, **M15**.
- [x] **Step C3: gate and accounts**, `tests/Journal/PostingServiceGateAndAccountsTest.php`. *(AC-23, AC-27 to AC-29, AC-31; construction halves of AC-25, AC-26, AC-30.)* As rev 2: AC-27; AC-28 {open, soft_closed, closed} × {plain, `finance.periods.post_adjustment`, null}; AC-29 first day, last day, day after (and the day after the last period of the last year → `no_fiscal_period`, the single-row read, fact 9); AC-31; AC-23 (two bad accounts → the lowest id); AC-25 spy order ascending, one call per distinct id. **M9**.
- [x] **Step C4: references (AC-15a)**, `tests/Journal/PostingServiceReferencesTest.php`. As rev 2: unknown or soft-deleted branch (entry and line), location, employee, customer, product → `unknown_reference` with path and line index, after the account check, before the number; no `QueryException`; `department_id`, `supplier_id` unchecked. **M19**.
- [x] **Step C5: `reverse`**, `tests/Journal/PostingServiceReverseTest.php`. *(AC-49 to AC-58, AC-67 except `[ENGINE]`.)*
  - AC-49, AC-50, AC-51 (also after the reversal period closed; never `idempotency_conflict`), AC-52, AC-53, AC-54 as rev 2.
  - **AC-6a interplay:** an entry's id used as `source_id` in a refused `post(source_type: reversal)` leaves the key free: the later `reverse` of that entry succeeds (D-32).
  - AC-55: a deactivated account on the original → the account refusal, nothing written. **D-35:** a soft-deleted customer, product, location, employee on a line, and a soft-deleted branch on the entry, each leave the reversal accepted with the dimension copied; a query listener shows **no** read of `branches`, `locations`, `employees`, `customers` or `products` during `reverse`.
  - Order: an invalid actor on a reverse of an unknown id → `invalid_actor`; a malformed id with an invalid actor → `entry_not_found` (shape first).
  - AC-56/67; AC-57 stand-in seam (the original flips to `reversed` between the plain read and `entryForUpdate` → `already_reversed`; the compare-and-set asserts 1 row); AC-58.
  - **M10** no swap; **M11** allow reversing a reversal; **M22** run `JournalReferences::check` in `reverse` → the D-35 rows go red.
  - Chunk C ends: the C files plus `PostingInputTest`, `AmountsTest`, `QaFinanceSourceRulesTest`, then `lint`, `stan`, `deptrac`.

### Chunk D: ports and handlers

- [x] **Step D1: the three ports, rebound**, `tests/Journal/JournalPortsTest.php`; provider lines 48 to 50 replaced; `FinanceAuthorizeTest`'s binding case (:141-144) asserts the journal classes. *(AC-59 to AC-64, AC-79.)*
  - AC-59 to AC-61 as rev 2 (any status for the first two; drafts only for the blockers).
  - **AC-62**, real bindings, one posted entry on a **non-system** postable account (a created account or a seeded one with control type `none`): recode → `code_locked` (`AccountRules::recode`); delete → `has_postings`; to header → `has_postings` (`toHeader`); retype → `type_change_refused` with `condition = has_postings` (`retype`); **subtype change → `type_change_refused`, field `subtype`, `condition = has_postings` (`resubtype`, T-032 D-32)**; **control-type change → `control_type_locked` (`recontrol`)**; delete the year → `fiscal_year_in_use`; a raw draft blocks a period close → `close_blocked`. The `FakeUsagePorts` tests stay green.
  - AC-63 (listener: only journal tables), AC-64 (stand-in `EXPLAIN QUERY PLAN` names the `(account_id, entry_id)` index, no `SCAN journal_lines`), AC-79 (two tenants).
  - **M12**, **M13**.
  - Targeted also: `tests/Features/{UpdateAccount, DeleteAccount, DeleteFiscalYear, TransitionFiscalPeriod, DeactivateAccount}/*`, `tests/Qa/QaFiscalLifecycleTest.php`, `tests/Support/FinanceAuthorizeTest.php`.
- [x] **Step D2: permissions and the four slices.** *(AC-68 to AC-73.)* Files: the four slice directories; `FinancePermissions` (+3, appended to `KEYS`); `FinanceRefusal::EntryNotFound`; `tests/Features/<Slice>/<Slice>Test.php` × 4; `tests/Features/JournalAuthorizationMatrixTest.php`; edits: `FinanceAuthorizeTest` key list (:48-59, seven → ten), `QaFinanceSourceRulesTest` slice lists and unlocked-caller entries for the two read handlers (Ruling 15).
  - AC-72: the three keys found by the registry through the booted provider; no role seed.
  - Matrix (AC-68 to AC-71, AC-73): without the key → `not_permitted` and **no** statement on any journal, account or period table; null actor and soft-deleted employee refused; `view` grants neither `reverse` nor `post_manual`, and neither grants `view`. The matrix catches `FinanceRefused` and `PostingRefused`.
  - `GetJournalEntry`: by id and by source key (null purpose through the key column); lines by `line_no`; four-decimal strings; unknown → `FinanceRefused(entry_not_found)`, and the string equals `PostingRefusal::EntryNotFound->value` (D-37).
  - `ListJournalEntries`: sizes 0, 201 and `"50"` refused, default 50; keyset over 5 entries at size 2; partial cursor → `cursor_invalid`; each filter valid plus a case variant refused; `account_id` through lines; the order (`JE-000010` after `JE-000009` on one date, D-36); no `lines` key.
  - `PostManualJournalEntry`: a body naming `source_type` → `unknown_field`; `is_auto = false`; `posted_by` = the employee.
  - `ReverseJournalEntry`: own transaction; actor = the employee.
  - **M14** `Authorize` after the transaction opens.
  - Targeted also: `tests/Feature/VerticalSliceLayoutTest.php`, `tests/Feature/QaAccessHttpSurfaceTest.php`, `tests/Feature/QaFinanceSourceRulesTest.php`, `../../packages/access/tests/Support/AuditCatalogueTest.php`, `../../packages/finance/tests/Periods/DatabasePeriodGateTest.php` and `../../packages/finance/tests/Features/FinanceAuthorizationMatrixTest.php` (both use `KEYS`; expected green, no edit). Chunk D ends with `lint`, `stan`, `deptrac`.

### Chunk E: scans, engine, documents, close-out

- [x] **Step E1: `QaJournalSourceRulesTest` (host)**, code only through `SourceRules::codeOnly`; named directories `packages/*/src`, `modules/*/src`, `modules/host/app`, `modules/host/database`. *(AC-5, AC-47, AC-48, AC-63; construction halves of AC-25, AC-26.)*
  - AC-5: any write to `journal_entries`/`journal_lines` (`table('journal_…')` chained to `insert`, `update`, `upsert`, `delete`, `truncate`, `increment`, `decrement`, or raw `insert into`/`update`/`delete from`) outside `Finance/Journal/JournalWriter.php` and the T-033 migrations is reported.
  - AC-48: the only `update` in `JournalWriter` sets exactly `status`, `reversed_by_id`, `updated_at`.
  - AC-47: no `delete` on either table anywhere; no route, command or console command in Finance (T-032's scan at `QaFinanceSourceRulesTest:687` already covers routes and jobs).
  - AC-63: no file in `packages/finance/src` outside `Journal/` and the two read slices names a journal table.
  - Locks: every port read chains `sharedLock()`; `postable` is the only account read in `Journal/`; `JournalRows` methods not named `*Unlocked` chain a lock (the `qaFinanceUnlockedTerminalOffenders` construction applied to `JournalRows`); `reverse` has no call into `JournalReferences` (D-35).
  - No float in `Journal/`. Anti-vacuity: the scan **finds** `JournalWriter`'s own inserts.
  - Red-first controls: a scratch `table('journal_lines')->update(` under `packages/organization/src`; a scratch update key `total_debit` in `JournalWriter`; both deleted after.
- [x] **Step E2: `JournalSchemaEngineTest`** (database `flexipos_t8test_t033_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep). Under ENGINE_HOLD only the "refuses to reach the engine" case runs; every other case is written, tagged `[ENGINE]`, skipped with "ENGINE_HOLD (G-1)" and reported NOT VERIFIED (AC-82). Cases: AC-45 (a both-sided row, a negative row and an unbalanced entry each rejected on MySQL 8.4 and MariaDB 10.4; `information_schema.CHECK_CONSTRAINTS` lists `journal_lines_debit_credit_one_sided` and `journal_entries_total_debit_total_credit_balanced`); AC-39; AC-40; AC-33a; AC-25; AC-26; AC-30; AC-57; risk 3 deadlock and retry; AC-64 `EXPLAIN` and the 10-line transaction time; AC-80 `CHAR(26)`/`ascii_bin`/DECIMAL(18,4); migrating twice is a no-op.
- [x] **Step E3: documents.** Describe; never quote retired vocabulary (a quoted old name in the working tree turns the gate red). `docs/database.md` §6.10 "As built (T-033)": the purpose sentinel and why it is safe; the fingerprint; the CHECKs (one combined line check, one balance check) and their engine-only status; omitted columns; owed keys (`department_id`, `supplier_id`); numbering through the shared allocator; the lock order and the caller rule (post last, several posts in ascending date order); `number` ordering is string order while widths stay equal (D-36); reversal copies dimensions without re-checking (D-35); `post` refuses the reversal type (D-32). In the T-032 block, the sentences that say the journal does not exist and the ports answer "none" are turned into past tense with a pointer to the T-033 block. §3.3: the ledger's double-post guard now carries the rule (the count of "not yet carrying it" drops by one). §12.2: the late-period rule is the caller's (D-13, D-31). `docs/architecture.md` §3: Finance owns the journal.
- [ ] **Step E4: close-out.** The lead schedules the single full `composer check` from `modules/host` (counts and `test` seconds compared with T-032's DONE gate); `composer test:fast` once if the lead wants the inner-loop figure. Every `[ENGINE]` clause reported NOT VERIFIED. *(AC-82.)*

## Test strategy

- **Unit (pure):** `JournalContractsTest`, `PostingInputTest`, `ReversalInputTest`, `AmountsTest`, `FingerprintTest`, `CheckConstraintTest`.
- **Integration (stand-in through `AccessTenantFixture`):** `PostingServicePostTest`, `PostingServiceIdempotencyTest`, `PostingServiceGateAndAccountsTest`, `PostingServiceReferencesTest`, `PostingServiceReverseTest`, `JournalPortsTest`, one file per slice, `JournalAuthorizationMatrixTest`, `JournalTenantSchemaTest`, `JournalWidthsTest`, `JournalMigrationsTest`. Every refusal asserts no entry, no line, no audit row and an unchanged `next_number`.
- **Static (host):** `QaJournalSourceRulesTest`, `QaFinanceSourceRulesTest`, `TenantDecimalPrecisionTest`, catalogue `is_destructive`, deptrac.
- **Engine:** `JournalSchemaEngineTest`, skipped under the hold, NOT VERIFIED.
- **Mutations:** M1 to M22 and S1, each in its step.

| ACs | Proved by |
|---|---|
| 1, 4, 16a | B1 |
| 2, 3 | C1 |
| 5, 47, 48 | E1 |
| 6 to 16, 6a | B1, B2 (AC-16 at the service in C1; AC-6a interplay in C5) |
| 15a | C4 |
| 17 to 22 | B3, C1 |
| 23, 24, 27 to 29, 31 | C1, C3 |
| 25, 26, 30 | C3, E1; `[ENGINE]` E2 |
| 32, 33, 33b | A1, A2, C1 |
| 33a | A2 (unique `number`); `[ENGINE]` E2 |
| 34 to 41 | B4, C2 (AC-39 also A2); AC-40 `[ENGINE]` E2 |
| 42 to 46 | A2, C1; AC-45 enforcement `[ENGINE]` E2 |
| 49 to 58, 67 | C5; AC-57 `[ENGINE]` E2 |
| 59 to 64, 79 | D1 (AC-64 engine plan E2) |
| 65, 66 | C1, C2 |
| 68 to 73, 73a, 74 | C1, D2 |
| 75 to 78, 81 | A2, A3, D1, D2 (section 8) |
| 80 | A2; `[ENGINE]` E2 |
| 82 | E2, E4 |

## Risks & rollback

1. **AC-81 wording** (F-22): if the lead reads the parenthetical as exhaustive, section 8's wider edits need a spec revision first. Each is an addition to an exact list, never a loosening.
2. **Engine deadlock** for a caller that posts twice in one transaction against a concurrent account admin (Ruling 9); one side retries; exercised in E2; FP-262 follow-up.
3. **Stand-in divergence.** SQLite ignores `lockForUpdate`/`sharedLock`, enforces no CHECK, and returns DECIMAL as REAL (15 significant digits). Lock order is proved by construction (spies, scans); CHECKs by statement text; no stand-in test reads back an amount above 15 significant digits; a fresh `PostedEntry` is never re-read; every real proof is `[ENGINE]`, NOT VERIFIED.
4. **The hottest lock.** The company-wide `journal_entry` row serialises every posting until the caller commits. Acceptable at 1 000 sales a day (spec NFR), unmeasured under the hold. Per-branch numbering is additive (owner Q1).
5. **Year rollover halts posting** (T-032 D-28): the gate fails closed with no next year; FP-262 must surface it.
6. **SQLite rebuild of `fiscal_years`** (a CASCADE parent) in `003304`; A3 asserts the periods survive.
7. **Cross-task edits** to DONE code: `DEFAULT_PREFIXES` (approved), and test edits in organization, sales, host and finance (section 8).
8. **The seeded row in every fixture tenant** is the first `number_sequences` row; any unscoped read added after `5a6b8a0` would go red; A0 re-greps.
9. **`number` ordering** is lexical (D-36).

**Rollback:** every file is new except section 8's edits. The migrations are forward-only and have run on no tenant outside this branch; rollback is to abandon the branch. The port rebinding (D1) can be reverted alone, which restores the `No*` defaults.

## 8. Edits to existing files (complete; re-grepped at `5a6b8a0`)

Every edit adds T-033's rows to an exact list or scopes a read to the rows the test owns; none removes, skips or loosens an assertion. Anything red that is not in this table: stop and report to `architect`.

| # | File | Pinned today | Edit | Step |
|---|---|---|---|---|
| 1 | `packages/finance/composer.json`, `modules/host/composer.lock` | no brick/math in finance | require; the lock's path-package entry | A1 |
| 2 | `packages/kernel/src/Schema/Support/CheckConstraint.php` | `between`, `oneSided` | `balanced`, `balancedStatement` | A1 |
| 3 | `packages/kernel/tests/Schema/Support/CheckConstraintTest.php` | `between`, `oneSided` text | `balanced` text and refusals | A1 |
| 4 | `packages/organization/src/Numbering/DatabaseDocumentNumbers.php:64-68` | three prefixes | `journal_entry` => `JE-` | A1 |
| 5 | `packages/organization/tests/Numbering/DocumentNumbersTest.php` | `dnRowCount()` counts every row (:90-92; asserted :139, :171, :211, :264) | new `journal_entry` case; `dnRowCount()` excludes `document_type = journal_entry` | A1, A2 |
| 6 | `packages/finance/src/Support/Limits.php` | 9 constants | +6 | A1 |
| 7 | `modules/host/database/catalogue/tenant_schema_versions.json` | top 81 | regenerated by `schema:publish --write-fixture` only | A2, A3 |
| 8 | `modules/host/.env.example:73` | `81` | the regenerated top (V0 + 3, then V0 + 4) | A2, A3 |
| 9 | `modules/host/tests/Helpers.php` `tenantTreeTables()` (:521-606) | T-032 block | `journal_entries`, `journal_lines` under a T-033 comment | A2 |
| 10 | `modules/host/tests/Feature/MigrationTreeDisciplineTest.php:152` | `toHaveCount(69)` | 71, with a T-033 comment | A2 |
| 11 | `modules/host/tests/Feature/IdempotencyKeysSchemaTest.php:124-126` | 81 / `003208` / contract | measured / `003303` / backfill, then measured / `003304` / contract, with a T-033 comment | A2, A3 |
| 12 | `modules/host/tests/Feature/TenantBaselineBuildsTest.php` FK list (:300-490) | T-032 rows | +13 (A2), +1 `fiscal_years.retained_earnings_entry_id -> journal_entries.id` (A3), sorted in, with a T-033 comment | A2, A3 |
| 13 | `TenantBaselineBuildsTest.php` `branches`-referencing list (:512-521) | 12 tables | + `journal_entries`, `journal_lines` | A2 |
| 14 | `TenantBaselineBuildsTest.php:268` | `number_sequences` count 3 | 4, with a T-033 note | A2 |
| 15 | `modules/host/tests/Feature/TenantDecimalPrecisionTest.php` map (:153) | T-032 entry | `003301` `total_debit`, `total_credit`; `003302` `debit`, `credit`; all (18,4) | A2 |
| 16 | `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php:151-153`, `QaAccessSchemaShapeTest.php:233-235` | `$t032` | `$t033 = ['journal_entries', 'journal_lines']` | A2 |
| 17 | `packages/sales/tests/Features/CompleteSale/CompleteSaleTest.php:375` and `OpenCashShift/OpenCashShiftTest.php:259` | `number_sequences` count 0 | count scoped to the test's own document types | A2 |
| 18 | `CompleteSaleTest.php:293, :562` and `OpenCashShiftTest.php:140, :339` | unscoped `value('next_number')` (would read the seeded `journal_entry` row first) | `where('document_type', …)` for the type the test consumes | A2 |
| 19 | `packages/finance/tests/Schema/FinanceTenantSchemaTest.php:262, :286` | six FKs; key absent (F-14) | seven; key present | A3 |
| 20 | `modules/host/tests/Feature/QaFinanceSourceRulesTest.php:611-623` | AC-60 exemption exactly the seed | seed and `003304` (F-24) | A3 |
| 21 | `modules/host/tests/Feature/BranchForeignKeysContractRunTest.php` | stops up to `bfkRunT032V()`; "five runs", "six runs" | `bfkRunT033V()`; one more stop in each case; titles and comments | A3 |
| 22 | `packages/finance/src/FinanceServiceProvider.php:46-50` | five binds | + `PostingService` (C1); lines 48 to 50 to the journal ports (D1); docblock | C1, D1 |
| 23 | `packages/finance/src/Registry/FinanceAuditActions.php:41-55` | 15 actions | +2 | C1 |
| 24 | `QaFinanceSourceRulesTest.php:62-73` `QA_FINANCE_UNLOCKED_CALLERS` | 10 files | + the journal files and the two read handlers, method by method (Ruling 15) | C1, D2 |
| 25 | `packages/finance/tests/Support/FinanceAuthorizeTest.php:141-144` | default bindings | journal classes | D1 |
| 26 | `FinanceAuthorizeTest.php:48-59` | "exactly the seven keys" | ten, title updated | D2 |
| 27 | `packages/finance/src/Registry/FinancePermissions.php` | seven keys | +3, appended to `KEYS` | D2 |
| 28 | `packages/finance/src/Exceptions/FinanceRefusal.php` | 46 cases | + `EntryNotFound` | D2 |
| 29 | `QaFinanceSourceRulesTest.php:40-49` slice lists | 13 write, 6 read | + `PostManualJournalEntry`, `ReverseJournalEntry`; + `GetJournalEntry`, `ListJournalEntries` | D2 |
| 30 | `docs/database.md` §6.10, §3.3, §12.2; `docs/architecture.md` §3 | as built T-032 | step E3 | E3 |

Expected green with **no** edit (run targeted): `QaToolchainRegistryCoverageTest`, `AuditCatalogueTest`, `QaAccessHttpSurfaceTest`, `VerticalSliceLayoutTest`, `SchemaCataloguePublishTest`, `ShippedTenantTreeTest`, `OwedAccountColumnsMigrationsTest`, `LoyaltySaleIdMigrationsTest`, `MigrationHelperAndImportRulesTest`, `FinanceAuthorizationMatrixTest`, `DatabasePeriodGateTest`, `FinanceWidthsTest`, `FinanceSeed` (unchanged; `JournalSeed` wraps it), `CloseCashShiftTest`, `VoidSaleTest`, every `FakeUsagePorts` test.

## 9. Follow-ups for `lead` (backlog rows or notes, not built here)

- **New row:** an actor-type parameter on `AuditTrail::record` so system and queued writers record `system` (D-27).
- **New row:** the nightly journal integrity re-check (D-20; owner question 4).
- **FP-262:** call `post` last, after its own number; several posts in one transaction in ascending date order; consider one combined call for revenue and cogs (risk 2); own the late-period rule (D-13, D-31); add `journal_entry_id` on documents; surface the year-rollover refusal.
- **Owed keys:** `journal_lines.department_id` (HR task) and `journal_lines.supplier_id` (T-034/FP-265), each as a contract-phase key plus an AC-15a existence check (F-13).
- **FP-268:** `account_balances` when a measured trial-balance query needs it (D-11).
- **Roles task:** grant the three journal keys. **FP-269 / API:** routes with `RequireTenantPermission` for the four slices.
- **`ba`:** F-22 (AC-81 parenthetical), F-23 (stale §7 sentence in the spec).
- Withdrawn from rev 2: the §7 permission-name doc fix (already done by T-032).

## Questions for the human

None. The merge precondition is met; F-22 to F-24 are for the lead.
