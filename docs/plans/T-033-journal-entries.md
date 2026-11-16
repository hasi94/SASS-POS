# Plan: T-033 Journal entries and lines, posted through one service

**Revision 2.** Synced to spec `docs/specs/T-033-journal-entries.md` **revision 2** (AC-1 to AC-82 plus AC-15a, AC-16a, AC-33a, AC-33b, AC-73a) and decisions `docs/decisions/T-033-journal-entries.md` D-1 to D-31. Revision 1's findings F-1 to F-14 are resolved by D-24 to D-31 and are no longer open (section 0.5 maps each one). New findings for `ba` are F-15 to F-21 (section 0.3).

Backlog FP-261. Branch `ai/T-033-journal-entries` (worktree `.claude/worktrees/journal-entries`, tip `b932380`). Gate directory `modules/host`.

**Measured against real code, not against T-032's plan.** Every T-032 fact below was read in the sibling worktree `.claude/worktrees/chart-of-accounts` (branch `ai/T-032-chart-of-accounts`, committed tip `81a9169`, which is the merge of T-030 `c354a0e`, plus the developer's uncommitted chunk D: the fiscal-year slices, `Periods/DatabasePeriodGate.php`, `Periods/PeriodRows.php` and the seed migration `003204`). Uncommitted work can still change, so step A0 re-reads every row of section 0.2 after the merge. T-032 chunk E (steps 17 to 22: owed account columns `003205` to `003208`, `QaFinanceSourceRulesTest`, `FinanceSchemaEngineTest`, documents) is **not built** yet.

---

## 0. Preconditions, verification, findings

### 0.1 Preconditions (before any code)

- **P-1 (blocking): T-032 is DONE and its tip is merged into `ai/T-033-journal-entries`.** T-032 already carries T-030 at `c354a0e` (which contains T-029) through its merge commit `81a9169`, so revision 1's separate precondition P-2 (merge T-030) is **subsumed**: one merge brings `DocumentNumbers`, the finance package and all three predecessors. Merging needs the human's permission on this machine (it is denied by default); the lead raises it.
- **P-2: no hard-coded catalogue version anywhere in T-033's own files.** Migration numbers renumber at merge (T-032 is versions 74 to 76 for its tables, 77 for the seed, and chunk E adds four more). T-033's migrations carry no version, and T-033's own tests look a version up **by migration name** through the published catalogue. Only the house literals that already pin a number (`IdempotencyKeysSchemaTest`, `.env.example`, `MigrationTreeDisciplineTest`'s table count) receive the measured value, after `schema:publish --write-fixture`. Projected, not to be typed in: T-032's final top is 81, so T-033 would be 82 to 85.
- **P-3: file prefix.** `2026_11_16_0033xx` sorts after every T-029, T-030 and T-032 file (`2026_11_10_*`, `2026_11_15_0032xx`), including T-032 chunk E's `003205` to `003208`. No rename is needed in either merge order.
- **Already approved by the lead (not questions any more):** `'journal_entry' => 'JE-'` in `DatabaseDocumentNumbers::DEFAULT_PREFIXES` (D-25); the additive kernel helper `CheckConstraint::balanced()` (D-24); the idempotency key `unique(source_type, source_id, source_purpose_key)` with `source_purpose_key` a stored generated `COALESCE(source_purpose, '-')`.

### 0.2 Facts measured against the real T-032 code

| # | Fact | Evidence (sibling worktree) | Effect on the plan |
|---|---|---|---|
| 1 | Finance is `packages/finance` (`Flexipos\Finance\`), deptrac layer `Package_Finance` → `Package_Kernel`, `Package_Contracts` only; tests layer may also reach `Package_Access` | `deptrac.yaml` lines 185-193, 523-533 | Journal code goes in `packages/finance/src/Journal/`; no deptrac edit |
| 2 | **Slices live in `packages/finance/src/Features/<Slice>/`** (`CreateAccount`, `DeleteFiscalYear`, ...), each with `<Slice>.php` + `<Slice>Handler.php` (+ optional `<Slice>Result.php`), mirrored by `tests/Features/<Slice>/<Slice>Test.php` | `src/Features/*`; `VerticalSliceLayoutTest` mirror rule | **Changed from rev 1** (which put slices under `src/Journal/Features/`). The four journal slices go in `src/Features/` |
| 3 | `FinanceServiceProvider::register()` binds `ChartOfAccounts`, `PeriodGate` (uncommitted chunk D), and `AccountUsage`/`FiscalYearUsage`/`PeriodCloseBlockers` to `NoAccountUsage`, `NoFiscalYearUsage`, `NoPeriodCloseBlockers` with `bind` | `src/FinanceServiceProvider.php` | Step D1 replaces the three `bind` lines in place |
| 4 | Port signatures: `AccountUsage::hasPostings(string $accountId): bool`; **`FiscalYearUsage::hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool`**; **`PeriodCloseBlockers::blockersFor(string $periodId, string $startDate, string $endDate): array`** (list of `PeriodBlocker(kind, count)`) | `packages/contracts/src/Finance/*` | The journal ports answer from the dates (inclusive) and ignore the ids |
| 5 | Callers of the ports: `DeleteAccountHandler` (`has_postings`), `UpdateAccountHandler` (`code_locked` via `AccountRules::recode`, `type_change_refused` with `context.condition = has_postings` via `retype`, `has_postings` via `toHeader`, `control_type_locked` via `recontrol`), `DeleteFiscalYearHandler` (`fiscal_year_in_use`), `TransitionFiscalPeriodHandler` (`close_blocked`) | `src/Accounts/AccountRules.php`, `src/Features/*` | AC-62's real-binding proofs use exactly these codes |
| 6 | `PeriodGate::clear(mixed $date, ?string $actorId): PeriodClearance` (`periodId`, `status`); `LogicException` when `transactionLevel() < 1`; `periodCoveringSharedLock`; `post_adjustment` asked through `PermissionResolver`; null actor refused in soft-closed; throws `PeriodGateRefused` carrying `PeriodRefusal` | `src/Periods/DatabasePeriodGate.php` (uncommitted) | Confirms rev 1 Ruling 7 step 3 |
| 7 | `ChartOfAccounts::postable(string $id): AccountSnapshot` takes `anySharedLock` **only when a transaction is open**, else an unlocked read; throws `AccountRefused` (`reason`, `field` = null for `postable`) | `src/Accounts/DatabaseChartOfAccounts.php:75` | The service always calls it inside the transaction and supplies the field path itself (`lines.{i}.account_id`) |
| 8 | `AccountReferrers` / `DeclaresAccountReferrers` exist (catalog, customers assignment columns) | contracts | Not implemented by the journal: postings are `AccountUsage`'s question, a referrer would double-count |
| 9 | Permission key for the soft-closed allowance is **`finance.periods.post_adjustment`** (`FinancePermissions::PERIODS_POST_ADJUSTMENT`); `FinancePermissions::KEYS` lists seven; all `isBranchScoped = false`, group strings `accounts` / `periods` | `src/Registry/FinancePermissions.php` | D-10 holds. `docs/database.md` §7 still names the older key: follow-up and step E3 |
| 10 | `Authorize::require(?string $actorId, string $key)` throws **`FinanceRefused(not_permitted)`** with `context = ['permission' => $key]`; null actor refused | `src/Support/Authorize.php` | Handler-level refusals are `FinanceRefused`; the service's are `PostingRefused` (F-20) |
| 11 | `FinanceAuditActions::ALL` is built from each handler's `ACTION` constant, module `finance`, 15 actions | `src/Registry/FinanceAuditActions.php` (uncommitted) | Add `DatabasePostingService::ACTION_POSTED`, `ACTION_REVERSED` |
| 12 | `Input::text` refuses a blank value; `Input::date` strict `Y-m-d` + `checkdate`; `Input::id` ULID folded upper; all throw `FinanceRefused` | `src/Support/Input.php` | Journal input normalises blank to null **before** `Input::text` (D-30) and maps `FinanceRefused` to `PostingRefused` codes |
| 13 | `Support\Page` is a two-part cursor (`nextAfterCode`, `nextAfterId`); paging config `finance.page_size` (50) / `finance.max_page_size` (200) | `src/Support/Page.php`, `FinanceProviderTest` | `ListJournalEntries` returns its own `ListJournalEntriesResult` with a three-part cursor; reuses the config keys |
| 14 | `DocumentNumbers::next(string $documentType, ?string $branchId): string`; implementation locks the row `FOR UPDATE`, creates a missing company-wide row with `DEFAULT_PREFIXES[$type] ?? null`, padding 6, `never`, catching its own `UniqueConstraintViolationException`; `WIDTH = 30`; `DEFAULT_PREFIXES` = sale `S`, refund `R`, cash_shift `SH` | `packages/organization/src/Numbering/DatabaseDocumentNumbers.php` | Confirms Ruling 6; the edit is one array line |
| 15 | `CheckConstraint::oneSided($table, $debit, $credit)` emits **one** constraint `{table}_{debit}_{credit}_one_sided` = `CHECK ((debit = 0 OR credit = 0) AND debit >= 0 AND credit >= 0)`; engine-only (`mysql`/`mariadb`), `oneSidedStatement()` pure | `packages/kernel/src/Schema/Support/CheckConstraint.php` | AC-45 lists two line checks; the helper is one combined equivalent (F-17) |
| 16 | `AuditTrail::record(module, ?actorId, action, subjectType, subjectId, ?before, ?after, ?branchId = null)`; an `ActorType` enum now exists in `Contracts\Audit` but **`record` has no actor-type parameter** | contracts `Audit/*` | D-27 holds as written |
| 17 | `EmployeeDirectory::isLive(string): bool`; `BranchDirectory::state(string): BranchState` | contracts `Access`, `Organization` | AC-73a uses `isLive` |
| 18 | Tenant tables present: `accounts`, `fiscal_years`, `fiscal_periods`, `employees`, `branches`, `locations`, `customers`, `products`, `number_sequences` (all targets have `deleted_at`). **Absent: `departments`, `suppliers`** | `Schema::create` sweep over `migrations-tenant/` | D-16 / F-13 holds: those two dimensions stay unguarded and unchecked |
| 19 | `fiscal_years.retained_earnings_entry_id` is `IdentifierColumn::nullable` with no key; `FinanceTenantSchemaTest` pins its key **absent** ("F-14" case) and "exactly the six documented foreign keys" over `accounts`, `fiscal_periods`, `fiscal_years` | `003202`, `FinanceTenantSchemaTest.php:262, 286` | AC-81 edits, step A3 |
| 20 | The seed `003204` (chart + current UTC year) runs in every fixture template; `FinanceSeed::clearSeed()` deletes periods, years, then accounts | `tests/Support/FinanceSeed.php:91` | With journal keys (RESTRICT) `clearSeed` must delete journal rows first and null `retained_earnings_entry_id` |
| 21 | brick/math 0.14.8 is locked; `catalog`, `customers`, `pricing`, `sales` require `^0.14`; **finance's `composer.json` does not** | `packages/*/composer.json`, `modules/host/composer.lock` | Finance adds `brick/math: ^0.14` (no new lock entry) |
| 22 | Reading another module's table through the shared tenant connection for a read is house practice (Sales reads `customers`, `products`) | `packages/sales/src/Sales/LineWriter.php:61` | AC-15a's existence check reads the target tables directly |
| 23 | Migrations may import `Illuminate\` and `Kernel\Schema\Support\` (`RunnerClock` in `003204`) | `003204` header | The sequence seed uses `RunnerClock` and `Str::ulid()` |
| 24 | `QaFinanceSourceRulesTest` (host) and `FinanceSchemaEngineTest` **do not exist yet** (T-032 steps 19, 20) | file search | T-033 edits them after the merge; if T-032 closes without them, step A0 stops |
| 25 | Whole-tree tests that count `number_sequences` absolutely: `TenantBaselineBuildsTest:268` (`3`), `DocumentNumbersTest` `dnRowCount()` (lines 139, 171, 211, 264), `CompleteSaleTest:375` (`0`), `OpenCashShiftTest:259` (`0`) | grep | The seeded `journal_entry` row adds one row to every tenant: each goes red (section 8) |

### 0.3 Findings for `ba` (spec questions; recommended answer in bold; the plan builds the recommendation unless told otherwise)

- **F-15 (S, AC-6/D-7: `post()` with `source_type = reversal`).** D-7's accepted list includes `reversal`, so a module could `post()` an entry typed `reversal` with `source_id` = some entry's id. That entry carries no `reversal_of_id`, flips nothing, and **occupies the key `(reversal, original_id, '-')`**, so the real `reverse()` of that entry would then find a "reversal" whose fingerprint differs and refuse `already_reversed` forever. **Recommend: `post()` refuses `source_type = reversal` with `invalid_source_type`; only `reverse()` writes it.** (`period_close` and `year_close` stay accepted: their owners do not exist yet and nothing keys on them.)
- **F-16 (S, AC-81 "the only edit allowed").** AC-62 and AC-72 themselves force edits to T-032 tests beyond the `retained_earnings_entry_id` assertion: `FinanceAuthorizeTest` pins the three default bindings (AC-62 flips them) and "exactly the seven keys" (AC-72 makes ten); `FinanceTenantSchemaTest`'s "six documented foreign keys" becomes seven; `FinanceAuthorizationMatrixTest` gains the four journal slices; `FinanceSeed::clearSeed` must delete journal rows before accounts and years; `QaFinanceSourceRulesTest` (once built) must allow T-033's contract file to name `fiscal_years`. **Recommend AC-81 reads: "T-032's tests still pass; the only edits are assertions that pinned what T-033 changes (the key's absence, the default bindings, the key list, the foreign-key count, the slice matrix, the seed clean-up and the source-rule allow-list), each listed in the plan's predicted-red table."**
- **F-17 (AC-45: two line checks or one).** The shipped kernel helper `oneSided` declares one combined constraint, logically identical to the two AC-45 lists. **Recommend: accept the one combined constraint (no second helper); the stand-in proves its exact text, the engine test rejects a both-sided row and a negative row separately.**
- **F-18 (AC-15a for a reversal).** `reverse()` copies the original's dimensions. If a customer, product, branch or location on the original has since been soft-deleted, AC-15a read literally would refuse the reversal `unknown_reference`. **Recommend: `reverse()` does not re-check dimension liveness** (it re-states history; the rows still exist so no foreign key can fail), unlike accounts, which D-14 re-checks on purpose.
- **F-19 (informational, AC-69 ordering by `number`).** `number` is a string; descending string order equals commit order only while the padding holds (999 999 entries) and the prefix never changes. **Recommend: accept for this task; the per-branch or per-year numbering owner question (Q1) revisits it.**
- **F-20 (confirm, read-handler vocabulary).** `Authorize` throws `FinanceRefused`, and AC-16a says list filters reuse T-032's `validation`, `page_size_invalid`, `cursor_invalid`. **Plan: `GetJournalEntry` and `ListJournalEntries` throw only `FinanceRefused`; `FinanceRefusal` gains `entry_not_found` for the unknown-id case (same string as `PostingRefusal::EntryNotFound`; precedent: `account_not_found` exists in both `FinanceRefusal` and `AccountRefusal`). `PostManualJournalEntry` and `ReverseJournalEntry` throw `FinanceRefused(not_permitted)` and otherwise let the service's `PostingRefused` through.** Confirm.
- **F-21 (confirm, where `invalid_actor` sits in D-21).** D-21 does not place the actor check. **Plan: after the balance check and before the replay lookup** (the first lookup, which AC-73a requires), for both `post` and `reverse`. A request with a malformed actor and a malformed amount therefore reports the amount.

### 0.4 Rev 1 assumptions that failed against the real T-032 code

1. Slice placement (`src/Journal/Features/` would fail `VerticalSliceLayoutTest`'s mirror rule against T-032's convention): now `src/Features/<Slice>/` (fact 2).
2. Port signatures carried ids rev 1 did not mention (fact 4); `hasEntries` and `blockersFor` take `(id, startDate, endDate)`.
3. `postable` locks only inside a transaction and takes no field (fact 7).
4. `Input::text` refuses blank, so it cannot implement D-30 directly (fact 12).
5. `Page` cannot carry a three-part cursor (fact 13).
6. Handler refusals are `FinanceRefused`, and `FinanceAuthorizationMatrixTest` catches only `FinanceRefused` (fact 10, F-20).
7. T-032 tests pin the default bindings and the key list, so AC-81's literal "only edit" cannot hold (F-16).
8. T-030 is already inside T-032 (precondition P-2 of rev 1 subsumed).
9. The seeded `journal_entry` row breaks four absolute `number_sequences` counts in organization, sales and host tests (fact 25); rev 1 had deferred this to a step-0 grep.
10. `QaFinanceSourceRulesTest` and `FinanceSchemaEngineTest` are still unbuilt (fact 24).
11. `FinanceWidthsTest` is scoped to T-032's three files; T-033 gets its own `JournalWidthsTest` instead of editing it (AC-81).
12. Rev 1's "a blank description is refused" contradicted rev 2's AC-14 (blank → null); corrected.

### 0.5 Revision 1 findings, now closed

| Finding | Closed by | What the plan now builds |
|---|---|---|
| F-1 FK placement | D-24, AC-75 | New tables declare all their own keys in the expand step; contract file holds only `fiscal_years.retained_earnings_entry_id` |
| F-2 CHECK on the stand-in | D-24, AC-45 | Exact SQL text on the stand-in; enforcement `[ENGINE]`, NOT VERIFIED |
| F-3 missing sequence row | D-25, AC-33b | Seed + shared allocator recreates with `JE-`/6/`never` |
| F-4, F-5 audit actor and names | D-27, AC-65 | `journal_entry_posted`, `journal_entry_reversed`; null actor id, `after.is_auto = true` |
| F-6 vocabulary | D-26, AC-15a, AC-16a, AC-73a | `unknown_field`, `invalid_actor`, `unknown_reference` |
| F-7 reverse never conflicts | D-28, AC-51 | `already_reversed` or replay |
| F-8 race loser | D-31, AC-34 | Stated; outcome is AC-40's |
| F-9 `opening` without key | D-29, AC-41 | No idempotency, like `manual` |
| F-10 normalisation | D-30, AC-14, AC-35 | Trim, blank → null, fingerprint over the stored form |
| F-11 AC-63 wording | D-31 | Source scan (step E1) |
| F-12 AC-64 measurement | D-31 | Stand-in query plan; engine plan and timing NOT VERIFIED |
| F-13 unguarded dimensions | D-31 | `department_id`, `supplier_id` unguarded and unchecked; owed |
| F-14 late offline sale | D-31 | Note beside `docs/database.md` §12.2 (step E3) |

### 0.6 Rulings

**Ruling 1: placement.**
- `packages/contracts/src/Finance/` (new, 10): `PostingService`, `PostingRequest`, `PostingLine`, `ReversalRequest`, `PostedEntry`, `PostingRefusal`, `PostingRefused`, `JournalEntryStatus`, `JournalSourceType`, `JournalDimension`.
- `packages/finance/src/Journal/`: `DatabasePostingService`, `PostingInput`, `ReversalInput`, `ParsedPosting`, `ParsedReversal`, `Amounts`, `Fingerprint`, `JournalRows` (every read, named by lock mode), `JournalReferences` (AC-15a), `JournalWriter` (the **only** writer of journal rows), `Ports/{JournalAccountUsage, JournalFiscalYearUsage, JournalPeriodCloseBlockers}`.
- `packages/finance/src/Features/{GetJournalEntry, ListJournalEntries, PostManualJournalEntry, ReverseJournalEntry}/` (fact 2).
- No new package, no deptrac edge, no route, command, job, outbox event or cache.

**Ruling 2: the published contract** (AC-1, AC-4, AC-16, AC-16a; plain PHP, no vendor import).

| Symbol | Shape |
|---|---|
| `PostingService` | `post(PostingRequest $r): PostedEntry`; `reverse(ReversalRequest $r): PostedEntry`. Docblock: must run inside the caller's open transaction (`LogicException` otherwise); call it **after every other lock the caller takes, including its own document number**; never opens, retries or commits a transaction; checks no permission |
| `PostingLine` | readonly `mixed $accountId, mixed $debit = '0', mixed $credit = '0', mixed $memo = null, array $dimensions = []`. Never throws (every check is `post()`'s, so D-21's order holds) |
| `PostingRequest` | readonly `mixed $sourceType, $sourceId, $sourcePurpose, $entryDate, $description, $branchId, $lines, ?string $actorId`; `static fromArray(array $body, ?string $actorId, list<string> $exclude = []): self` refuses `unknown_field` naming **every** unknown key at entry and line level in one throw (`$exclude` lets `PostManualJournalEntry` drop `source_type` from the allowed set, AC-71). An omitted side becomes `'0'`; an explicit `null` is kept and refused later (`invalid_amount`) |
| `ReversalRequest` | readonly `mixed $entryId, $entryDate, $reason, ?string $actorId`, with `fromArray` (keys `entry_id`, `entry_date`, `reason`) |
| `PostedEntry` | readonly `id, number, periodId, entryDate, totalDebit, totalCredit, status (JournalEntryStatus), replayed`. Nothing else (AC-4) |
| `JournalEntryStatus` | `draft`, `posted`, `reversed` |
| `JournalSourceType` | D-7's 26 values in the document's order. `post()` refuses `reversal` (F-15) |
| `JournalDimension` | `branch_id`, `location_id`, `department_id`, `employee_id`, `customer_id`, `supplier_id`, `product_id`, in this fixed order (used by input, fingerprint, references and the writer) |
| `PostingRefusal` | `unknown_field`, `invalid_source_type`, `invalid_source_id`, `invalid_source_purpose`, `invalid_date`, `invalid_description`, `invalid_branch`, `invalid_lines`, `too_few_lines`, `too_many_lines`, `invalid_account_id`, `invalid_memo`, `invalid_dimension`, `invalid_amount`, `line_both_sides`, `line_zero`, `entry_unbalanced`, `total_out_of_range`, `invalid_actor`, `idempotency_conflict`, `unknown_reference`, `entry_not_found`, `not_reversible`, `already_reversed`, `reversal_before_original`, `reason_required`, `invalid_reason`. `JournalContractsTest` asserts this exact list and that every code the spec names is in it (AC-16a) |
| `PostingRefused` | `RuntimeException`, private constructor, named constructors. `public readonly PostingRefusal\|PeriodRefusal\|AccountRefusal $reason` (gate and account codes pass through **unchanged**, AC-23, AC-28), `?string $field` (path such as `lines.3.debit`), `list<string> $fields` (`unknown_field`), `array $context` (both totals for `entry_unbalanced`; stored id for `idempotency_conflict`/`already_reversed`; field path and line index for `unknown_reference`). `getPrevious()` is the gate or account exception |

**Ruling 3: `post()` validation order (D-21 as amended, F-21).** Each pure step stops at the first failing field (request order, lines ascending, dimensions in `JournalDimension` order).
- (0) `LogicException` when `transactionLevel() < 1`: the first statement, before parse or read (AC-2).
- (1) Unknown keys (`fromArray`; dimension keys again in `post`, AC-15, AC-16).
- (2) Shape in request order: `source_type` exact `tryFrom`, not `reversal` (AC-6, F-15); `source_id` required unless `manual`/`opening`, ULID folded upper (AC-7); `source_purpose` `\A[a-z][a-z0-9_]{0,29}\z`, never without `source_id` (AC-8); `entry_date` via `Input::date` (AC-9); `description` trimmed, blank → null, 1 to 500 characters, UTF-8, no control character (AC-10, AC-14); `branch_id` (AC-11); `lines` a list, 2 to 200 (AC-12); per line `account_id` (AC-13), `memo` (AC-14, 255), each dimension (AC-15).
- (3) Amounts per line: `is_string` and `\A(0|[1-9][0-9]{0,13})(\.[0-9]{1,4})?\z`; then exactly one side positive (AC-17 to AC-19).
- (4) Balance: exact `BigDecimal` sums compared at scale 4 (`entry_unbalanced` with both totals), then `total_out_of_range` above `99999999999999.9999` (AC-20, AC-21).
- (5) Actor: null passes (system); otherwise ULID and `EmployeeDirectory::isLive`, else `invalid_actor` (AC-73a; first lookup).
- (6) Replay lookup (Ruling 7).
- (7) Gate, (8) accounts ascending, (9) references (AC-15a), (10) number, (11) write.
Every refusal is `PostingRefused`, never `FinanceRefused`.

**Ruling 4: amounts** (`Journal\Amounts`, pure, brick/math). Finance's own copy of the `customers\Support\Decimal` semantics (FP-433: no import of another package's support), stricter: no int input, no leading zeros, `\A…\z` anchors, `RoundingMode::Unnecessary`. Output is always the four-decimal string. `Amounts::fromStored(mixed)` reads a column back: a string or int is parsed exactly; a float (only the SQLite stand-in returns one) goes through `BigDecimal::of((string) $value)`. No cast **to** float, no `floatval`, `round(`, `number_format(` in `Journal/` (step E1 scan).

**Ruling 5: idempotency key and fingerprint** (AC-34 to AC-41, D-6, D-23, D-29, D-30).
- Key: `unique(source_type, source_id, source_purpose_key)`, `source_purpose_key VARCHAR(30)` stored generated `COALESCE(source_purpose, '-')` (lead-approved; `'-'` cannot be a purpose, AC-8). `source_id` stays a plain nullable column, so `manual`/`opening` without `source_id` escape the key by design (AC-41).
- Fingerprint: `payload_fingerprint CHAR(64)`, lower-case hex SHA-256 of canonical JSON (`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`) of the positional list `[entry_date, branch_id, description, [[account_id, debit4, credit4, memo, ...7 dimensions in JournalDimension order], ...]]` over the **stored** (normalised) values; line order significant; actor and clock excluded.
- Reversal: key `(reversal, original_id, '-')`, fingerprint over `[entry_date, reason]` (trimmed reason). The key is also the database backstop for one reversal per entry (AC-57).

**Ruling 6: numbering through `DocumentNumbers`** (D-8, D-25, AC-32 to AC-33b). `next('journal_entry', null)`, always company-wide even when the entry has a branch, inside the savepoint of Ruling 7, after every check. Backfill seed `003303` inserts the row if absent (`branch_id` null, `document_type = journal_entry`, prefix `JE-`, padding 6, `never`, next 1). `DocumentNumbersRefused` is a deployment fault and propagates unmapped.

**Ruling 7: the `post()` algorithm.**
1. Ruling 3 steps 0 to 5.
2. **Replay lookup**, only when `source_id` is non-null: `JournalRows::byKeyUnlocked` (a plain read: no gap lock, so two first posts of one key cannot deadlock on it). Found: same fingerprint → `PostedEntry` from the stored row, `replayed = true`; different → `idempotency_conflict` with the stored id. Either way: no gate, no accounts, no number, no audit (AC-34, AC-37, AC-38).
3. `PeriodGate::clear(entry_date, actor)`; `PeriodGateRefused` → `PostingRefused(reason: PeriodRefusal, field: entry_date)`.
4. Distinct account ids sorted ascending (`strcmp` on upper-case ULIDs), `ChartOfAccounts::postable($id)` once each; `AccountRefused` → `PostingRefused(reason: AccountRefusal, field: lines.{first index naming it}.account_id)`.
5. `JournalReferences::check` (AC-15a): one `whereIn(id)->whereNull('deleted_at')` read per target table (`branches` for the entry branch and line `branch_id`, `locations`, `employees`, `customers`, `products`); `department_id`, `supplier_id` skipped. Plain reads (`*Unlocked`): a foreign key already guarantees the row exists, a concurrent soft delete leaves a valid historical reference, and a shared lock on hot catalog and customer rows would add contention to every sale. First missing reference in request order → `unknown_reference` with the field path.
6. **Savepoint** (nested `$connection->transaction(fn)`, one attempt): `DocumentNumbers::next`; insert the entry; insert **all lines in one statement**; `AuditTrail::record`.
7. Catch `UniqueConstraintViolationException` from the savepoint: the savepoint is rolled back, so **the sequence increment is undone**. Re-read the key with `JournalRows::byKeyForUpdate` (W-1): a row → replay or `idempotency_conflict`; no row → rethrow (a `number` duplicate is an invariant failure, never a refusal) (AC-40, AC-33a).
8. Any other exception propagates. No retry, no deadlock catch: the caller's outer `transaction(fn, attempts: 2)` owns retry.
A fresh `PostedEntry` is built from the computed strings, never re-read (risk 4).

**Ruling 8: the `reverse()` algorithm** (AC-49 to AC-58, D-9, D-14, D-23, D-28).
1. Transaction guard; unknown keys; shape in request order: `entry_id` (malformed → `entry_not_found`), `entry_date` (`invalid_date`), `reason` (missing or blank → `reason_required`, over 500 or control character → `invalid_reason`).
2. Actor (`invalid_actor`, F-21).
3. Original by id, plain read: none → `entry_not_found`; `source_type = reversal` or status `draft` → `not_reversible`.
4. Replay lookup on `(reversal, original_id, '-')`, plain: found and same fingerprint → that reversal, `replayed = true`; found and different → `already_reversed` (never `idempotency_conflict`).
5. `entry_date < original.entry_date` → `reversal_before_original` (same day accepted).
6. Gate on the **reversal date**; then accounts of the original's lines through `postable`, ascending (D-14). Dimensions are **not** re-checked (F-18).
7. Original `FOR UPDATE` (`JournalRows::entryForUpdate`); status no longer `posted` → re-read the reversal key `FOR UPDATE` → replay or `already_reversed`.
8. Savepoint: number; insert the reversal entry (`source_type = reversal`, `source_id` and `reversal_of_id` = original, `description` = reason) and its lines (sides swapped, same account, memo, dimensions and `line_no`) in one statement; `UPDATE journal_entries SET status = 'reversed', reversed_by_id = ?, updated_at = ? WHERE id = ? AND status = 'posted'`, asserting exactly 1 row; one audit row `journal_entry_reversed` with `after.reason`, `after.original_id` (AC-65, AC-67).
9. Unique violation from the savepoint → step 7's re-read.

**Ruling 9: lock order** (unchanged from rev 1, re-verified against facts 6 and 7).

| Order | `post` | `reverse` |
|---|---|---|
| L0 | the caller's own locks, including its own document number | the caller's own locks |
| L1 | period row, shared (gate) | period of the reversal date, shared |
| L2 | accounts, shared, ascending id | accounts of the original lines, shared, ascending |
| L2b | (none) | original entry `FOR UPDATE` |
| L3 | `journal_entry` sequence row `FOR UPDATE` (hottest; held to the caller's commit) | same |
| L4 | insert entry and lines | insert reversal and lines; compare-and-set on the original |

Against T-032 (now verified in code): a period close holds periods `X` then counts drafts, so a poster holding the period `S` makes it wait and a later poster waits at L1 (AC-30); an account delete/recode/retype holds the account `X` then asks `hasPostings`, so posters on that account wait at L2 (AC-26). `reverse` takes L1 and L2 **before** L2b, because a close holds the period `X` and then scans entries in it. Two posters order on L1 `S`, L2 `S`, L3 `X` and cannot deadlock. The ports read with `sharedLock()` (the T-032 `AccountReferrers` rule). Known engine deadlock: a caller that posts twice in one transaction (FP-262 revenue plus cogs) takes new L2 locks after holding L3; against a concurrent account admin InnoDB rolls one back (1213), data stays correct, retries absorb it (risk 3).

**Ruling 10: schema** (section 4). Two expand files with all their own keys, uniques, indexes and CHECKs (`CheckConstraint::oneSided('journal_lines', 'debit', 'credit')`, new `CheckConstraint::balanced('journal_entries', 'total_debit', 'total_credit')`, constraint name `journal_entries_total_debit_total_credit_balanced`, same engine-only shape, literal verb, `assertIdentifier`, pure `balancedStatement()`); one backfill seed; one contract file for `fiscal_years.retained_earnings_entry_id` in the `000506` shape (orphan count first, refuse naming table, column and count but never a value; skip a key already present; no `down()`).

**Ruling 11: the three ports** (`Journal\Ports\*`, AC-59 to AC-64, D-12). Each read is `sharedLock()` and names journal tables only:
- `hasPostings`: `journal_lines WHERE account_id = ? LIMIT 1` on the `(account_id, entry_id)` index, any status; malformed or unknown id → false.
- `hasEntries($yearId, $start, $end)`: `journal_entries WHERE entry_date BETWEEN ? AND ? LIMIT 1`, any status.
- `blockersFor($periodId, $start, $end)`: `COUNT(*) WHERE status = 'draft' AND entry_date BETWEEN ? AND ?` → `[PeriodBlocker('draft_journal_entries', n)]` or `[]`.
`FinanceServiceProvider` replaces the three `bind` lines in place; the `No*` classes stay for tests.

**Ruling 12: handlers** (AC-68 to AC-74, D-10, F-20). `Authorize::require` is the first statement of each, before any transaction or read.

| Slice | Key | Notes |
|---|---|---|
| `GetJournalEntry` | `finance.journal.view` | by `id`, or by `(source_type, source_id, source_purpose)` through `source_purpose_key`; inputs validated case-sensitively (`validation`); lines by `line_no`; amounts through `Amounts::fromStored`; unknown → `FinanceRefused(entry_not_found)` |
| `ListJournalEntries` | `finance.journal.view` | page 1 to 200, default `finance.page_size`; out of range `page_size_invalid`; keyset cursor `(after_entry_date, after_number, after_id)` all or none, else `cursor_invalid`; filters `from`, `to` (strict `Y-m-d`), `status`, `source_type`, `period_id`, `account_id` (`EXISTS` on lines), `branch_id`; invalid → `validation`; order `entry_date DESC, number DESC, id DESC`; summary rows only; `from > to` gives an empty page. Returns `ListJournalEntriesResult` |
| `PostManualJournalEntry` | `finance.journal.post_manual` | `PostingRequest::fromArray($body, $actor, exclude: ['source_type'])`, type fixed to `manual`; `transaction(fn, attempts: 2)` |
| `ReverseJournalEntry` | `finance.journal.reverse` | `transaction(fn, attempts: 2)` around `PostingService::reverse`, actor = the employee |

`FinancePermissions` gains `JOURNAL_VIEW`, `JOURNAL_POST_MANUAL`, `JOURNAL_REVERSE` (module `finance`, group `journal`, not branch-scoped), appended to `KEYS`. `FinanceRefusal` gains `EntryNotFound = 'entry_not_found'`.

**Ruling 13: audit** (AC-65 to AC-67, D-19, D-27). One `AuditTrail::record('finance', $actor, ACTION, 'journal_entry', $id, null, $after)` inside the savepoint. `after`: `number`, `source_type`, `source_id`, `source_purpose`, `entry_date`, `period_id`, `total_debit`, `total_credit`, `line_count`, `is_auto`, plus `reason` and `original_id` for a reversal. Constants `DatabasePostingService::ACTION_POSTED = 'journal_entry_posted'`, `ACTION_REVERSED = 'journal_entry_reversed'`, collected into `FinanceAuditActions::ALL`. Not audited: replay, refusal, read, the original's status flip.

**Ruling 14: test cost.** Pure datasets carry most cases; one fixture-backed file per service operation and per slice; every refusal asserts no entry, no line, no audit row and an unchanged `next_number`. About 240 cases, about 60 % pure. If a chunk adds more than 10 % to the measured `test` step, stop and report to `lead`.

---

## Approach summary

1. After the human-approved merge of T-032 (which carries T-030 and T-029), re-verify section 0.2 and start chunk A.
2. Add `journal_entries` and `journal_lines` with every own key, unique and engine CHECK in the expand step, a seeded `journal_entry` sequence row, and one contract key on `fiscal_years`.
3. Publish `PostingService` with `mixed`-typed value objects, so every refusal comes out of one deterministic pass (D-21) as one `PostingRefused` that carries period and account codes unchanged.
4. Exact brick/math string arithmetic; no float anywhere.
5. Idempotency is a stored-sentinel unique key plus a SHA-256 fingerprint of the normalised payload; the replay lookup runs before the gate, and a unique-key collision is resolved inside a savepoint, so no number is consumed by anything that did not commit.
6. One fixed lock order inside the caller's transaction (period, accounts, [original], sequence); the service never retries.
7. Reversal is the only update: a compare-and-set on `status`.
8. Rebind T-032's three ports to `sharedLock` journal reads; four permission-checked slices; a source scan proves the one-writer rule; an engine test (held) carries every `[ENGINE]` clause.

## Alternatives rejected

- A Finance-owned allocator: a second writer of `number_sequences` (Ruling 6).
- A nullable `source_purpose` directly in the unique key: NULLs are distinct on both engines (AC-39).
- Key-only replay without a fingerprint: a retry with different content would silently return the old entry (AC-35).
- Re-checking the key under the sequence lock instead of a savepoint: needs `DocumentNumbers` split into lock and increment, a contract change, for the same result.
- A shared-lock replay pre-check: two first posts of one key take gap locks and deadlock on insert.
- Shared locks on dimension targets (AC-15a): contention on hot product and customer rows for no integrity gain (Ruling 7 step 5).
- Value objects that validate in their constructors: refusals would escape D-21's order.
- A trigger for the balance rule (D-20); `account_balances` now (D-11); a separate journal package (the ports must rebind inside Finance).
- Editing `FinanceWidthsTest` for the journal files: a T-032 test (AC-81); a separate `JournalWidthsTest` instead.

## Files to change / create

`dev` writes every file; `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Finance/` (new, 10)
`PostingService`, `PostingRequest`, `PostingLine`, `ReversalRequest`, `PostedEntry`, `PostingRefusal`, `PostingRefused`, `JournalEntryStatus`, `JournalSourceType`, `JournalDimension` (Ruling 2).

### `packages/finance`

| Path | What |
|---|---|
| `composer.json` (edit) | `brick/math: ^0.14`; description mentions the journal |
| `src/Support/Limits.php` (edit) | `MEMO = 255`, `REASON = 500`, `NUMBER = 30`, `FINGERPRINT = 64`, `MIN_LINES = 2`, `MAX_LINES = 200` (`DESCRIPTION = 500` and `ENUM = 30` exist) |
| `src/Exceptions/FinanceRefusal.php` (edit) | `EntryNotFound` (F-20) |
| `src/Journal/{DatabasePostingService, PostingInput, ReversalInput, ParsedPosting, ParsedReversal, Amounts, Fingerprint, JournalRows, JournalReferences, JournalWriter}.php` | Rulings 3 to 9 |
| `src/Journal/Ports/{JournalAccountUsage, JournalFiscalYearUsage, JournalPeriodCloseBlockers}.php` | Ruling 11 |
| `src/Features/GetJournalEntry/{GetJournalEntry, GetJournalEntryHandler, GetJournalEntryResult}.php` | Ruling 12 |
| `src/Features/ListJournalEntries/{ListJournalEntries, ListJournalEntriesHandler, ListJournalEntriesResult}.php` | Ruling 12 |
| `src/Features/PostManualJournalEntry/{PostManualJournalEntry, PostManualJournalEntryHandler}.php` | Ruling 12 |
| `src/Features/ReverseJournalEntry/{ReverseJournalEntry, ReverseJournalEntryHandler}.php` | Ruling 12 |
| `src/FinanceServiceProvider.php` (edit) | bind `PostingService`; replace the three port bindings; docblock |
| `src/Registry/{FinancePermissions, FinanceAuditActions}.php` (edit) | three keys; two actions |
| `tests/Contracts/JournalContractsTest.php` | step B1 |
| `tests/Journal/{PostingInputTest, ReversalInputTest, AmountsTest, FingerprintTest, PostingServicePostTest, PostingServiceIdempotencyTest, PostingServiceGateAndAccountsTest, PostingServiceReferencesTest, PostingServiceReverseTest, JournalPortsTest}.php` | chunks B to D |
| `tests/Features/{GetJournalEntry, ListJournalEntries, PostManualJournalEntry, ReverseJournalEntry}/<Slice>Test.php` | step D2 (slice mirror rule) |
| `tests/Schema/{JournalTenantSchemaTest, JournalWidthsTest}.php` | step A2 |
| `tests/Support/JournalSeed.php` | builds on `FinanceSeed` (year, accounts, actors per key); raw-insert `draft` helper for AC-61 (tests only) |

### `packages/kernel`
- `src/Schema/Support/CheckConstraint.php` (edit): `balanced()`, `balancedStatement()`.
- `tests/Schema/Support/CheckConstraintTest.php` (edit).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php` (edit): `'journal_entry' => 'JE-'`.
- `tests/Numbering/DocumentNumbersTest.php` (edit): a fresh `journal_entry` row gets `JE-000001`; `dnRowCount()` scoped (section 8).

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_16_003301_create_journal_entries_table.php` | expand |
| `database/migrations-tenant/2026_11_16_003302_create_journal_lines_table.php` | expand |
| `database/migrations-tenant/2026_11_16_003303_seed_journal_entry_number_sequence.php` | `@phase backfill` |
| `database/migrations-tenant/2026_11_16_003304_add_retained_earnings_entry_foreign_key.php` | `@phase contract` |
| `tests/Feature/JournalMigrationsTest.php` | step A3 |
| `tests/Feature/QaJournalSourceRulesTest.php` | step E1 |
| `tests/Engine/JournalSchemaEngineTest.php` | step E2 |
| pinned tests and fixtures | section 8 |

### Documents (step E3)
`docs/database.md` §6.10, §7, §12.2, §3.3; `docs/architecture.md` §3.

## Data / API changes

**Schema** (tenant only, no master change):

| Table | Columns | Keys, indexes, checks |
|---|---|---|
| `journal_entries` | `id` (`IdentifierColumn::primary`), `number` VARCHAR(30), `entry_date` DATE, `period_id` ULID, `branch_id` ULID NULL, `source_type` VARCHAR(30), `source_id` ULID NULL, `source_purpose` VARCHAR(30) NULL, `source_purpose_key` VARCHAR(30) stored `COALESCE(source_purpose, '-')`, `status` VARCHAR(30), `total_debit` DECIMAL(18,4), `total_credit` DECIMAL(18,4), `description` VARCHAR(500) NULL, `reversal_of_id` ULID NULL, `reversed_by_id` ULID NULL, `payload_fingerprint` CHAR(64), `posted_at` DATETIME(3), `posted_by` ULID NULL, `is_auto` BOOL default false, `created_by` ULID NULL, `created_at`, `updated_at` DATETIME(3). No `deleted_at`, tenant, currency or `plugin_name` (D-4, D-7) | unique `number`; unique `(source_type, source_id, source_purpose_key)`; index `(entry_date, number)`; index `(period_id, status)`. FKs (RESTRICT): `period_id` → `fiscal_periods`, `branch_id` → `branches`, `posted_by`, `created_by` → `employees`, `reversal_of_id`, `reversed_by_id` → `journal_entries`. `balanced` CHECK `[ENGINE]` |
| `journal_lines` | `id`, `entry_id`, `line_no` SMALLINT UNSIGNED, `account_id`, `debit`, `credit` DECIMAL(18,4) default 0, `memo` VARCHAR(255) NULL, `branch_id`, `location_id`, `department_id`, `employee_id`, `customer_id`, `supplier_id`, `product_id` (ULID NULL), `created_at` DATETIME(3). No unit, quantity, currency, `project_ref`, `tax_id`, `tax_base` | unique `(entry_id, line_no)`; index `(account_id, entry_id)`; indexes `customer_id`, `supplier_id`, `employee_id`. FKs: `entry_id` → `journal_entries` CASCADE; `account_id` → `accounts`, `branch_id` → `branches`, `location_id` → `locations`, `employee_id` → `employees`, `customer_id` → `customers`, `product_id` → `products` (RESTRICT). `department_id`, `supplier_id` unguarded and owed. `oneSided` CHECK `[ENGINE]` |
| `number_sequences` | one row: `branch_id` null, `document_type` `journal_entry`, `prefix` `JE-`, `padding` 6, `reset_period` `never`, `next_number` 1 | insert-if-absent |
| `fiscal_years` | none | FK `retained_earnings_entry_id` → `journal_entries` RESTRICT (contract) |

FK inventory +14 (6 entries, 7 lines, 1 `fiscal_years`). Decimal columns +4, all (18,4). `APP_REQUIRED_SCHEMA_VERSION` = merged top + 4, measured. Catalogue republished with `php artisan schema:publish --write-fixture`, never hand-merged.

**Contracts:** Ruling 2. **Permissions:** `finance.journal.view`, `finance.journal.post_manual`, `finance.journal.reverse`. **Audit actions:** `journal_entry_posted`, `journal_entry_reversed`. **Refusals:** `PostingRefusal` (new), `FinanceRefusal::EntryNotFound`. **HTTP, console, jobs, outbox, cache:** none. **Dependency:** brick/math in Finance's `composer.json` only (already locked at 0.14.8; the lock diff is the path package's `require`).

## Implementation steps

Working rules (carried from T-032 §6): red-first by fixture or omission, never by stubbing a guard open; no child processes; unique test basenames; each mutation run once, reverted byte for byte, and recorded with red-then-green counts in `dev`'s hand-back. **Inside a chunk** `dev` runs only the targeted pest files named in the step plus `composer lint`, `composer stan` and `composer deptrac`. **The lead schedules the single full `composer check`** (recommended once, at step E4; plus a baseline only if T-032's DONE gate result is not reusable). Targeted pest runs from `modules/host`: `"/e/xampp/php/php.exe" vendor/bin/pest <path> [<path> ...]` (run `php artisan config:clear` first, as the scripts do). Anything red that section 8 does not predict: stop and report to `architect`.

### Chunk A: base, schema, static instruments (the first developer chunk)

- [ ] **Step A0: verify after the merge** (`lead` merges; `dev` reads only). *(No AC.)*
  - Confirm `git log` shows T-032's DONE commit and `81a9169` (T-030) in this branch.
  - Re-read every row of section 0.2 in this worktree; specifically: the provider's five `bind` lines; `FinancePermissions::KEYS` (seven); `FinanceAuditActions::ALL`; `DatabasePeriodGate::clear` guard and refusals; `DatabaseChartOfAccounts::postable`; `DEFAULT_PREFIXES`; `Authorize`; `FinanceSeed::clearSeed`.
  - Confirm T-032 chunk E's files exist: `QaFinanceSourceRulesTest` (its one-writer allow-list form and its write-slice literal), `FinanceSchemaEngineTest`, migrations `003205` to `003208`. Missing → stop.
  - `Schema::create` sweep: `suppliers` or `departments` present → that dimension becomes guarded and checked (F-13); record it.
  - Read the catalogue top (`database/catalogue/tenant_schema_versions.json`) and `.env.example`'s `APP_REQUIRED_SCHEMA_VERSION`; record as V0.
  - Re-grep `number_sequences')->count()` and `->table('number_sequences')` in every `tests/` directory; add any new absolute count to section 8.
  - Re-read `MigrationTreeDisciplineTest`'s table count and `TenantBaselineBuildsTest`'s FK list for chunk E's additions.
- [ ] **Step A1: plumbing.** *(Groundwork; AC-33b.)* Files, in order:
  1. `packages/finance/composer.json`: add `"brick/math": "^0.14"`; then from `modules/host`: `"/e/xampp/php/php.exe" "/c/ProgramData/ComposerSetup/bin/composer.phar" update flexipos/finance` and `validate --no-check-publish` in `packages/finance`.
  2. `packages/finance/src/Support/Limits.php`: the six constants.
  3. `packages/kernel/src/Schema/Support/CheckConstraint.php`: `balanced()` (engine-only, literal verb, `assertIdentifier` on all three) and `balancedStatement()`.
  4. `packages/kernel/tests/Schema/Support/CheckConstraintTest.php`: exact text `ALTER TABLE \`journal_entries\` ADD CONSTRAINT \`journal_entries_total_debit_total_credit_balanced\` CHECK (\`total_debit\` = \`total_credit\`)`; `Total_Debit` refused as an identifier; nothing issued on SQLite.
  5. `packages/organization/src/Numbering/DatabaseDocumentNumbers.php`: `'journal_entry' => 'JE-'`.
  6. `packages/organization/tests/Numbering/DocumentNumbersTest.php`: case "a missing `journal_entry` row is created as `JE-` / 6 / `never` and yields `JE-000001`" (delete any `journal_entry` row first, so the case is meaningful before and after A2).
  - Targeted: `../../packages/kernel/tests/Schema/Support/CheckConstraintTest.php`, `../../packages/organization/tests/Numbering/DocumentNumbersTest.php`, `tests/Feature/QaToolchainRegistryCoverageTest.php` (reads package manifests; expected green, no edit).
- [ ] **Step A2: expand and seed migrations, schema and width tests, pinned housekeeping.** *(Schema halves of AC-32, AC-39, AC-41 to AC-45, AC-75, AC-78 to AC-80.)* Files, in order:
  1. `modules/host/database/migrations-tenant/2026_11_16_003301_create_journal_entries_table.php`: section "Data / API changes"; docblock states the `'-'` sentinel and why it is safe (§3.3), and that keys are declared with the new table (D-24); calls `CheckConstraint::balanced` after `Schema::create`.
  2. `…003302_create_journal_lines_table.php`: calls `CheckConstraint::oneSided('journal_lines', 'debit', 'credit')`.
  3. `…003303_seed_journal_entry_number_sequence.php`: `@phase backfill`; one transaction; insert only when no row has `branch_id` null and `document_type = journal_entry`; `Str::ulid()` id, `RunnerClock` timestamps; no audit row; docblock "two homes" note pointing at the A2 test that compares it with the allocator's default.
  4. From `modules/host`: `"/e/xampp/php/php.exe" artisan schema:publish --write-fixture` → `database/catalogue/tenant_schema_versions.json`.
  5. `modules/host/.env.example`: `APP_REQUIRED_SCHEMA_VERSION` = V0 + 3.
  6. `packages/finance/tests/Support/FinanceSeed.php`: `clearSeed()` first deletes `journal_lines`, `journal_entries`, and sets `fiscal_years.retained_earnings_entry_id` to null, before its existing deletes (F-16).
  7. `packages/finance/tests/Schema/JournalTenantSchemaTest.php` (own stand-in, `FinanceTenantSchemaTest` shape, whole tenant tree):
     - exact sorted column lists with nullability; no `tenant_id`, `deleted_at`, currency or unit column (AC-42, AC-43, AC-79);
     - uniques by raw double insert: `number`; `(entry_id, line_no)`; the idempotency key (same type, id, purpose refused; **same type and id with null purpose twice refused**, AC-39; `revenue` and `cogs` coexist; two `manual` rows with null `source_id` coexist, AC-41);
     - the 13 own FKs exactly; `entry_id` cascade deletes lines (raw);
     - `003301`/`003302` source calls `balanced` and `oneSided` with the right arguments (parsed), and the statements equal `balancedStatement`/`oneSidedStatement` (AC-45 stand-in half);
     - seed: one row with the exact values; `003303` twice leaves one row; with a pre-existing row nothing changes (AC-32, AC-78); with the seed row deleted, `DocumentNumbers::next('journal_entry', null)` recreates a row whose `prefix`, `padding`, `reset_period` equal the seed's (AC-33b, the "two homes" test);
     - `SourceRules::inspect()` clean, no `down()`, every ULID column through `IdentifierColumn` (AC-80);
     - versions read from the catalogue by migration name, never literal (P-2).
     - **Mutation M8:** key on `source_purpose` instead of `source_purpose_key` → the null-purpose row goes red.
  8. `packages/finance/tests/Schema/JournalWidthsTest.php`: every `string()`/`char()` in `003301`/`003302` equals its `Limits` constant (`number` NUMBER, `source_type`/`source_purpose`/`source_purpose_key`/`status` ENUM, `description` DESCRIPTION, `memo` MEMO, `payload_fingerprint` FINGERPRINT). Red-first: a fixture `string('memo', 256)` is reported (AC-44, AC-80).
  9. Pinned host tests (section 8 rows A2): `tests/Helpers.php` `tenantTreeTables()`; `MigrationTreeDisciplineTest`; `TenantBaselineBuildsTest` (FK list +13, sequence count +1); `TenantDecimalPrecisionTest` (two file entries); `IdempotencyKeysSchemaTest` (top = `003303`, phase `backfill`, version V0 + 3); `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest` (`$t033 = ['journal_entries', 'journal_lines']`).
  10. Absolute `number_sequences` counts (section 8 rows A2): scope each to the document types the test owns.
  - Targeted: `../../packages/finance/tests/Schema/JournalTenantSchemaTest.php`, `../../packages/finance/tests/Schema/JournalWidthsTest.php`, `../../packages/finance/tests/Schema/FinanceTenantSchemaTest.php`, `../../packages/finance/tests/Seed/SeedChartTest.php`, `tests/Feature/MigrationTreeDisciplineTest.php`, `tests/Feature/TenantBaselineBuildsTest.php`, `tests/Feature/TenantDecimalPrecisionTest.php`, `tests/Feature/IdempotencyKeysSchemaTest.php`, `tests/Feature/QaAccessCloseOutSweepsTest.php`, `tests/Feature/QaAccessSchemaShapeTest.php`, `tests/Feature/SchemaCataloguePublishTest.php`, `../../packages/organization/tests/Numbering/DocumentNumbersTest.php`, `../../packages/sales/tests/Features/CompleteSale/CompleteSaleTest.php`, `../../packages/sales/tests/Features/OpenCashShift/OpenCashShiftTest.php`.
- [ ] **Step A3: contract `003304`, with `JournalMigrationsTest`.** *(AC-75 to AC-78, AC-81.)* Files, in order:
  1. `…003304_add_retained_earnings_entry_foreign_key.php`: `@phase contract`, the `000506` shape.
  2. `schema:publish --write-fixture`; `.env.example` = V0 + 4; `IdempotencyKeysSchemaTest` top = `003304`, phase `contract`, V0 + 4; `TenantBaselineBuildsTest` FK list +1.
  3. `modules/host/tests/Feature/JournalMigrationsTest.php`:
     - red-first: a fiscal year with an orphan `retained_earnings_entry_id` → the run refuses naming `fiscal_years.retained_earnings_entry_id` and `1 row(s)`, no value, and no key is added (AC-76);
     - clean run: the key exists; re-run is a no-op; across the stand-in rebuild of `fiscal_years`, the `fiscal_years` **and `fiscal_periods`** row counts and every pre-existing key survive (CASCADE parent, risk 7);
     - AC-77: every T-033 catalogue row (looked up by name) is `is_destructive = false`, none adds `NOT NULL` to an existing column; `003303` is `backfill`, `003304` is `contract`; the planner plans `003304` only once every tenant is at the version before it;
     - AC-78: on two fixture tenants the tables are empty and one sequence row was added; nothing else changed.
     - **Mutations:** S1 write the key as a `->change()` → AC-77 red; M17 skip the orphan count → the red-first case goes red (the stand-in rebuild adds the key over the orphan silently, so no refusal is raised).
  4. `packages/finance/tests/Schema/FinanceTenantSchemaTest.php`: the F-14 case asserts the key **present**; "six documented foreign keys" becomes seven (AC-81, F-16).
  5. `modules/host/tests/Feature/QaFinanceSourceRulesTest.php`: one-writer allow-list gains `003304` (it names `fiscal_years`).
  - Targeted: `tests/Feature/JournalMigrationsTest.php`, `../../packages/finance/tests/Schema/FinanceTenantSchemaTest.php`, `tests/Feature/QaFinanceSourceRulesTest.php`, `tests/Feature/IdempotencyKeysSchemaTest.php`, `tests/Feature/TenantBaselineBuildsTest.php`, `tests/Feature/SchemaCataloguePublishTest.php`; then `composer lint`, `composer stan`, `composer deptrac`. **Chunk A ends**; `dev` hands back counts; the lead decides whether to schedule a full check here.

### Chunk B: the pure core

- [ ] **Step B1: contracts** (Ruling 2), `tests/Contracts/JournalContractsTest.php`. *(AC-1, AC-4, AC-16, AC-16a.)* Exact enum lists (D-7's 26 in order; `PostingRefusal`'s 27); every code the spec names is present (AC-16a); `PostingRefused` carries a `PeriodRefusal` or `AccountRefusal` unchanged; `PostedEntry` has exactly the AC-4 properties (reflection); `fromArray` names **every** unknown key at both levels in one throw (`id`, `number`, `status`, `period_id`, `posted_at`, `posted_by`, `reversal_of_id`, `total_debit`, `is_auto`, `project_ref`, `tax_id`, `tax_base`); `exclude: ['source_type']` makes `source_type` unknown; omitted side → `'0'`, explicit `null` kept; no import outside contracts and PHP core.
- [ ] **Step B2: `PostingInput` / `ReversalInput`** (pure datasets), `tests/Journal/PostingInputTest.php`, `ReversalInputTest.php`. *(AC-6 to AC-16, AC-53 and AC-56 shape halves, F-15.)*
  - AC-6: `Sale`, `SALE`, `' sale'`, `''`, null, `'unknown'`, and `reversal` (F-15) refused.
  - AC-7: missing, null, 25 or 27 characters; lower case accepted and folded; optional for `manual`/`opening`.
  - AC-8: `''`, `'-'`, `'1abc'`, `'Revenue'`, 31 characters, `"rev\n"`, a purpose without `source_id`.
  - AC-9: `2026-02-30`, `2026-1-1`, a date-time, `' 2026-01-01'`, null.
  - AC-10/AC-14: 500 multi-byte characters pass, 501 refused; control characters and invalid UTF-8 refused; **blank or whitespace description and memo stored as null**; `" x "` stored as `"x"`.
  - AC-11/AC-13/AC-15: malformed ids name `branch_id`, `lines.{i}.account_id`, `lines.{i}.{dimension}`.
  - AC-12: 1 and 201 lines; `lines` as a map or a string (`invalid_lines`).
  - D-21 determinism: two wrong fields → the first in request order; lines ascending.
  - Reversal: malformed id → `entry_not_found`; missing date → `invalid_date`; blank reason → `reason_required`; 501 characters → `invalid_reason`.
  - **Mutation M1:** `^…$` for `\A…\z` → the `"rev\n"` row goes red.
- [ ] **Step B3: `Amounts` and balance** (pure), `tests/Journal/AmountsTest.php`. *(AC-17 to AC-22 logic.)* AC-17 `1`, `1.0`, `true`, null; AC-18 every listed string including `'٣'` and `"5\0"`; AC-19 both sides and zero spellings; AC-20 `0.1 + 0.2` against `0.3` balances, `0.0001` off refused with both totals; AC-21 200 lines at the maximum → `total_out_of_range`, the exact maximum sum passes; AC-22 `"12.5"` → `"12.5000"`; `fromStored` for string, int and stand-in float. **M2** sum through a float → `0.1+0.2` red; **M3** round instead of refuse → `1.00001` red.
- [ ] **Step B4: `Fingerprint`** (pure), `tests/Journal/FingerprintTest.php`. *(AC-35, AC-36 logic; D-23, D-30.)* Same payload, other actor or clock → equal; each AC-35 difference → different (date, branch, description, line count, **line order**, account, debit, credit, memo, each dimension); `"12.5"`/`"12.5000"` and `"x"`/`" x "` equal; reversal `[date, reason]`. **M7** sort lines before hashing → line-order row red.
  - Chunk B ends: targeted files above plus `composer lint`, `stan`, `deptrac` (contracts purity is a deptrac and scan fact).

### Chunk C: posting and reversal on the database

- [ ] **Step C1: `JournalWriter`, `JournalRows`, `DatabasePostingService::post` happy path, numbering, audit**, `tests/Journal/PostingServicePostTest.php` (stand-in through `AccessTenantFixture`). Provider binds `PostingService`; `FinanceAuditActions` gains the two actions. *(AC-2, AC-3, AC-22, AC-24, AC-32, AC-33, AC-42, AC-43, AC-46, AC-65, AC-66, AC-73a, AC-74.)*
  - AC-2: outside a transaction → `LogicException` and a query listener sees **zero** statements, for `post` and `reverse`.
  - AC-3: a scratch document row (`settings`), then post, then throw → no document, entry, line or audit row; sequence unchanged; a refused post inside the same shape rolls the document back too.
  - AC-22/42/43: stored row compared whole; `posted_at` from the fixed `RunnerClock`; `is_auto` false with an actor, true with null (AC-74); lines 1..N in request order.
  - AC-24: one account on a debit line and a credit line.
  - AC-32: `JE-000001`, `JE-000002` from the company row even with `branch_id` set.
  - AC-33: after N posts the numbers are 1..N; one refusal per family, a replay and a rolled-back caller each leave `next_number` unchanged.
  - AC-46: exactly **one** `insert into journal_lines`; a forced line-insert failure (rename `journal_lines` outside the transaction) leaves neither row and the sequence unchanged.
  - AC-65/66: one audit row compared whole (`journal_entry_posted`, `is_auto`); none for a refusal.
  - AC-73a: malformed actor and soft-deleted employee → `invalid_actor`, no statement after the actor read.
  - **M16** one insert per line → one-statement assertion red; **M18** drop the guard → AC-2 red.
  - Targeted also: `../../packages/access/tests/Support/AuditCatalogueTest.php` (expected green, no edit: `finance` already in the module list).
- [ ] **Step C2: idempotency**, `tests/Journal/PostingServiceIdempotencyTest.php`. *(AC-34 to AC-41 except `[ENGINE]`.)*
  - AC-34: replay → equal `PostedEntry`, `replayed = true`; no write, no gate call, no `postable` call (spy bindings), no audit, sequence unchanged.
  - AC-35: each difference → `idempotency_conflict` naming the stored id; stored row untouched; `"12.5"` vs `"12.5000"` and `"x"` vs `" x "` replay.
  - AC-36: other actor, later clock → replay.
  - AC-37: close the period (raw), then replay succeeds, conflict stays a conflict, a fresh key → `period_closed`.
  - AC-38: after a reversal, the original's replay returns `reversed` with `replayed = true`.
  - AC-39: two null-purpose posts → replay or conflict; `revenue` and `cogs` → two entries.
  - AC-40 (stand-in seam): a spy `PeriodGate` raw-inserts a competing row for the same key on its call; the service hits the key inside its savepoint, returns a replay or `idempotency_conflict`, surfaces no `QueryException`, and **`next_number` is unchanged by the loser**.
  - AC-41: two `manual` (and two `opening`) posts without `source_id` → two entries.
  - **M4** replay after the gate → AC-37 red; **M5** number before replay → AC-33 replay row red; **M6** catch outside the savepoint → AC-40 sequence assertion red; **M15** audit the replay → AC-34 red.
- [ ] **Step C3: gate and accounts through the service**, `tests/Journal/PostingServiceGateAndAccountsTest.php`. *(AC-23, AC-27 to AC-29, AC-31; construction halves of AC-25, AC-26, AC-30.)*
  - AC-27: stored `period_id` is the gate's; `entry_date` inside that period's range.
  - AC-28: {open, soft_closed, closed} × {plain, `finance.periods.post_adjustment`, null actor}; codes unchanged; `field = entry_date`.
  - AC-29: first day, last day, day after; a tenant with no year → `no_fiscal_period`.
  - AC-31: closed-period refusal writes nothing and substitutes no date.
  - AC-23: header, inactive, soft-deleted, nonexistent → the `AccountRefusal` code with `lines.{i}.account_id`; two bad accounts → the lowest id.
  - AC-25: a spy `ChartOfAccounts` records ascending order regardless of line order, one call per distinct id.
  - **M9** accounts in line order → spy order row red.
- [ ] **Step C4: references (AC-15a)**, `tests/Journal/PostingServiceReferencesTest.php`. *(AC-15a.)* An unknown or soft-deleted branch (entry and line), location, employee, customer, product → `unknown_reference` with the field path and line index, after the account check (a bad account and a bad reference → the account code) and before the number (sequence unchanged); no `QueryException`; `department_id`, `supplier_id` with any well-formed ULID accepted (unchecked, unguarded). **M19** check references after the number → the sequence assertion red.
- [ ] **Step C5: `reverse`**, `tests/Journal/PostingServiceReverseTest.php`. *(AC-49 to AC-58, AC-67 except `[ENGINE]`.)*
  - AC-49: swapped sides, same accounts, dimensions, memo, `line_no`; equal totals; own number; `source_type = reversal`, `source_id` and `reversal_of_id` = original.
  - AC-50: original `reversed`, `reversed_by_id` set; amounts, lines, number, date, period compared whole before and after.
  - AC-51: different date or reason → `already_reversed`; identical → the same reversal, `replayed = true` (also after the reversal period closed); never `idempotency_conflict`.
  - AC-52: reversing a reversal, a raw draft → `not_reversible`; unknown or malformed id → `entry_not_found`.
  - AC-53: before the original → `reversal_before_original`; same day accepted.
  - AC-54: reversal date in a closed period → gate code, original stays `posted`; original in a closed period is irrelevant.
  - AC-55: a deactivated account on the original → account refusal, nothing written.
  - F-18: a soft-deleted customer on the original does not block the reversal.
  - AC-56/67: reason in the audit row and the description; exactly one audit row, `after.original_id`.
  - AC-57 (stand-in seam): the original flips to `reversed` between the plain read and `entryForUpdate` → `already_reversed`; the compare-and-set asserts 1 row.
  - AC-58: no permission needed by the service.
  - **M10** no swap → AC-49 red; **M11** allow reversing a reversal → AC-52 red.
  - Chunk C ends: the C files plus `PostingInputTest`, `AmountsTest`, then `lint`, `stan`, `deptrac`.

### Chunk D: ports and handlers

- [ ] **Step D1: the three ports, rebound**, `tests/Journal/JournalPortsTest.php`; provider edit; `FinanceAuthorizeTest`'s binding case flips to the journal classes (F-16). *(AC-59 to AC-64, AC-79.)*
  - AC-59: a line in a posted, reversed or raw-draft entry → true; none → false; unknown or malformed → false.
  - AC-60: first and last day count; outside does not; any status.
  - AC-61: two raw drafts → `[PeriodBlocker('draft_journal_entries', 2)]`; posted and reversed → `[]`.
  - AC-62: container resolves the journal classes; post one entry, then with real bindings: recode → `code_locked`; delete → `has_postings`; to header → `has_postings`; retype → `type_change_refused` with `condition = has_postings`; delete the year → `fiscal_year_in_use`; a raw draft blocks a period close → `close_blocked`. T-032's `FakeUsagePorts` tests stay green.
  - AC-63: the ports read only journal tables (listener).
  - AC-64: stand-in `EXPLAIN QUERY PLAN` for `hasPostings` names the `(account_id, entry_id)` index, no `SCAN journal_lines`.
  - AC-79: post in `tenant-a`; `tenant-b` has an empty journal, `next_number` 1, ports false.
  - **M12** `hasPostings` filtered to `posted` → reversed and draft rows red; **M13** blockers count `posted` → AC-61 red.
  - Targeted also: every T-032 test that uses a port: `tests/Features/{UpdateAccount, DeleteAccount, DeleteFiscalYear, TransitionFiscalPeriod}/*`, `tests/Support/FinanceAuthorizeTest.php`.
- [ ] **Step D2: permissions and the four slices.** *(AC-68 to AC-73.)* Files: the four slice directories; `FinancePermissions` (+3, appended to `KEYS`); `FinanceRefusal::EntryNotFound`; tests `tests/Features/<Slice>/<Slice>Test.php` × 4; edits to `FinanceAuthorizeTest` (ten keys) and `FinanceAuthorizationMatrixTest` (F-16: four journal rows in `financeSlices()`; `financeRunRecorded` also catches `PostingRefused`; `financeTouchesFinanceTables` adds `journal_entries|journal_lines`; fixture gains one posted entry for the reverse and get rows).
  - AC-72: the three keys found by the registry sweep through the booted provider; no role seed.
  - Matrix (AC-68 to AC-71, AC-73): without the key → `not_permitted` and **no** statement on any journal, account or period table; null actor and soft-deleted employee refused; `view` grants neither `reverse` nor `post_manual`, and neither grants `view`.
  - `GetJournalEntry`: by id and by source key (null purpose through the key column); lines by `line_no`; four-decimal strings; `entry_not_found`.
  - `ListJournalEntries`: sizes 0, 201 and `"50"` refused, default 50; keyset over 5 entries at size 2; partial cursor → `cursor_invalid`; each filter valid plus a case variant refused; `account_id` through lines; the order; no `lines` key.
  - `PostManualJournalEntry`: a body naming `source_type` → `unknown_field`; `is_auto = false`; `posted_by` = the employee.
  - `ReverseJournalEntry`: own transaction; actor = the employee.
  - **M14** `Authorize` after the transaction opens → no-statement assertion red.
  - Targeted also: `tests/Feature/VerticalSliceLayoutTest.php`, `tests/Feature/QaAccessHttpSurfaceTest.php` (both expected green with no edit; no route is added), `../../packages/access/tests/Support/AuditCatalogueTest.php`, `../../packages/finance/tests/Periods/DatabasePeriodGateTest.php` (uses `KEYS`). Chunk D ends with `lint`, `stan`, `deptrac`.

### Chunk E: scans, engine, documents, close-out

- [ ] **Step E1: `QaJournalSourceRulesTest` (host)**, code only through `SourceRules::codeOnly`. Named directories: `packages/*/src`, `modules/*/src`, `modules/host/app`, `modules/host/database` (tests are out of scope by name; `JournalSeed`'s raw draft insert is test code). *(AC-5, AC-47, AC-48, AC-63; construction halves of AC-25, AC-26.)*
  - AC-5: any write to `journal_entries`/`journal_lines` (`table('journal_…')` chained to `insert`, `update`, `upsert`, `delete`, `truncate`, `increment`, `decrement`, or raw `insert into`/`update`/`delete from`) outside `Finance/Journal/JournalWriter.php` and the T-033 migrations is reported.
  - AC-48: the only `update` in `JournalWriter` carries exactly `status`, `reversed_by_id`, `updated_at`.
  - AC-47: no `delete` on either table anywhere; no route, command or console command in Finance.
  - AC-63: no file in `packages/finance/src` outside `Journal/` and the two read slices (`GetJournalEntry`, `ListJournalEntries`) names a journal table.
  - Locks: every port read chains `sharedLock()`; `postable` is the only account read in `Journal/`; `JournalRows` methods not named `*Unlocked` chain a lock.
  - No float: `(float)`, `floatval`, `round(`, `number_format(` in `Journal/`. Regexes: no pattern ends in `$/`. Contracts purity.
  - Anti-vacuity: the scan **finds** `JournalWriter`'s own inserts.
  - Red-first controls: a scratch `table('journal_lines')->update(` under `packages/organization/src`; a scratch update key `total_debit` in `JournalWriter`; delete both.
  - Also add `PostManualJournalEntry` and `ReverseJournalEntry` to `QaFinanceSourceRulesTest`'s write-slice literal (they must make no terminal read themselves).
- [ ] **Step E2: `JournalSchemaEngineTest`** (database `flexipos_t8test_t033_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep). Under ENGINE_HOLD only the "refuses to reach the engine" case runs; every other case is written, tagged `[ENGINE]`, skipped with "ENGINE_HOLD (G-1)" and reported NOT VERIFIED (AC-82). Rows with explicit ids; connection B calls the real service. Cases: AC-45 (a both-sided row, a negative row and an unbalanced entry rejected on MySQL 8.4 and MariaDB 10.4; `information_schema.CHECK_CONSTRAINTS`); AC-39 null-purpose double insert; AC-40 same-key race (B waits on L3, then replays; `next_number` +1); AC-33a two keys get consecutive numbers; AC-25 opposite line orders; AC-26 deactivate vs post both ways; AC-30 close vs post; AC-57 reversal race; risk 3 deadlock and clean retry; AC-64 `EXPLAIN` (type `ref`, the index) and the 10-line transaction time; AC-80 `CHAR(26)`/`ascii_bin`/DECIMAL(18,4); migrating twice is a no-op.
- [ ] **Step E3: documents.** Describe; never quote retired vocabulary. `docs/database.md` §6.10 "As built (T-033)" (purpose sentinel and why `'-'` is safe; fingerprint; CHECKs and their engine-only status; omitted columns; owed keys; numbering through the shared allocator; lock order and caller rule); §7 the soft-closed sentence names T-032's period adjustment key; §12.2 a note that the late-period rule is the caller's (D-13, D-31); §3.3 the journal key leaves the "not yet carrying it" list; `docs/architecture.md` §3 Finance owns the journal.
- [ ] **Step E4: close-out.** The lead schedules the single full `composer check` from `modules/host` (counts and `test` seconds compared with T-032's DONE gate); `composer test:fast` once if the lead wants the inner-loop figure. Report every `[ENGINE]` clause NOT VERIFIED. *(AC-82.)*

## Test strategy

- **Unit (pure):** `JournalContractsTest`, `PostingInputTest`, `ReversalInputTest`, `AmountsTest`, `FingerprintTest`, `CheckConstraintTest` (kernel). Most cases are datasets.
- **Integration (SQLite stand-in through `AccessTenantFixture`):** `PostingServicePostTest`, `PostingServiceIdempotencyTest`, `PostingServiceGateAndAccountsTest`, `PostingServiceReferencesTest`, `PostingServiceReverseTest`, `JournalPortsTest`, one file per slice, `FinanceAuthorizationMatrixTest`, `JournalTenantSchemaTest`, `JournalWidthsTest`, `JournalMigrationsTest`. Every refusal asserts no entry, no line, no audit row and an unchanged `next_number`.
- **Static (host):** `QaJournalSourceRulesTest`, `QaFinanceSourceRulesTest`, `TenantDecimalPrecisionTest`, catalogue `is_destructive`, deptrac.
- **Engine:** `JournalSchemaEngineTest`, skipped under the hold, NOT VERIFIED.
- **Mutations:** M1 to M19 and S1, each in its step.

| ACs | Proved by |
|---|---|
| 1, 4, 16a | B1 |
| 2, 3 | C1 |
| 5, 47, 48 | E1 |
| 6 to 16 | B1, B2 (AC-16 at the service in C1) |
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
| 75 to 78, 81 | A2, A3, D1, D2 |
| 80 | A2; `[ENGINE]` E2 |
| 82 | E2, E4 |

## Risks & rollback

1. **Merge precondition (highest, blocking).** P-1 needs the human's permission to merge; T-032 must also finish chunk E (fact 24). Nothing past A0 can start before it.
2. **T-032's uncommitted chunk D can still move.** Section 0.2 rows 3, 6, 11, 20 were read from uncommitted files. A0 re-reads them and stops on any difference.
3. **Engine deadlock** for a caller that posts twice in one transaction against a concurrent account admin (Ruling 9); one side retries; exercised in E2; follow-up for FP-262.
4. **Stand-in divergence.** SQLite ignores `lockForUpdate`/`sharedLock`, enforces no CHECK, and returns DECIMAL as REAL (15 significant digits). Lock order is proved by construction (spies, scan); CHECKs by statement text; no stand-in test reads back an amount above 15 significant digits; a fresh `PostedEntry` is never re-read; every real proof is `[ENGINE]`, NOT VERIFIED.
5. **The hottest lock.** The company-wide `journal_entry` row serialises every posting until the caller commits. Acceptable at 1 000 sales a day (spec NFR) but unmeasured under the hold. Per-branch numbering is additive (owner Q1).
6. **Year rollover halts posting** (T-032 risk 5): the gate fails closed with no next year; FP-262 must surface it.
7. **SQLite rebuild of `fiscal_years`** (a CASCADE parent) in `003304`; A3 asserts the periods survive.
8. **Cross-task edits** to DONE code: `DEFAULT_PREFIXES` (approved), and test edits in organization and sales for the seeded row (section 8).
9. **The seeded row in every fixture tenant** may hit an absolute count added after this plan (chunk E or another merge); A0 re-greps.
10. **`number` ordering** is lexical (F-19).

**Rollback:** every file is new except section 8's edits. The migrations are forward-only and have run on no tenant outside this branch; rollback is to abandon the branch. The port rebinding (D1) can be reverted alone, which restores the `No*` defaults.

## 8. Edits to existing files (the predicted-red list, including every hard-coded test)

| File | Pinned today | Edit | Step |
|---|---|---|---|
| `packages/finance/composer.json`, `modules/host/composer.lock` | no brick/math | require + path package entry only | A1 |
| `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php` | package list incl. `finance` | **none expected** (no new package); run targeted | A1 |
| `packages/kernel/.../CheckConstraint.php`, `CheckConstraintTest.php` | `between`, `oneSided` | `balanced` | A1 |
| `packages/organization/.../DatabaseDocumentNumbers.php` | three prefixes | `journal_entry` | A1 |
| `packages/organization/tests/Numbering/DocumentNumbersTest.php` | `dnRowCount()` absolute (lines 139, 171, 211, 264) | new case; `dnRowCount()` counts rows other than `journal_entry` | A1, A2 |
| `database/catalogue/tenant_schema_versions.json` | top = T-032's last file | republished | A2, A3 |
| `modules/host/.env.example` | V0 | V0 + 3, then V0 + 4 | A2, A3 |
| `modules/host/tests/Helpers.php` `tenantTreeTables()` | T-032 block | `journal_entries`, `journal_lines` under a T-033 comment | A2 |
| `tests/Feature/MigrationTreeDisciplineTest.php` | `tenantTreeTables()->toHaveCount(69)` (re-read at A0) | +2 with a T-033 comment | A2 |
| `tests/Feature/IdempotencyKeysSchemaTest.php` | top `version`/`migration`/`phase` (77/`003204`/backfill today; chunk E moves it) | `003303`/backfill, then `003304`/contract, measured versions | A2, A3 |
| `tests/Feature/TenantBaselineBuildsTest.php` | whole-tree FK list; `number_sequences` count 3 (line 268) | +13 then +1 FK lines; count 4 with a T-033 note | A2, A3 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | per-file map | `003301` total_debit/total_credit, `003302` debit/credit, all (18,4) | A2 |
| `tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | `$t032` exclusion lists | `$t033` | A2 |
| `packages/sales/tests/Features/CompleteSale/CompleteSaleTest.php:375`, `OpenCashShift/OpenCashShiftTest.php:259` | `number_sequences` count 0 | scope the count to the test's own document types | A2 |
| `packages/finance/tests/Support/FinanceSeed.php` | `clearSeed` deletes periods, years, accounts | journal rows first; null `retained_earnings_entry_id` | A2 |
| `packages/finance/tests/Schema/FinanceTenantSchemaTest.php` | key absent (F-14); six FKs | present; seven | A3 |
| `modules/host/tests/Feature/QaFinanceSourceRulesTest.php` (T-032 chunk E) | one-writer allow-list; write-slice literal | `003304`; the two write slices | A3, E1 |
| `packages/finance/src/FinanceServiceProvider.php` | five binds | `PostingService`; three ports replaced | C1, D1 |
| `packages/finance/src/Registry/FinanceAuditActions.php` | 15 actions | +2 | C1 |
| `packages/access/tests/Support/AuditCatalogueTest.php` | module list (finance present) | **none expected**; run targeted | C1, D2 |
| `packages/finance/tests/Support/FinanceAuthorizeTest.php` | default bindings; "exactly the seven keys" | journal classes; ten keys | D1, D2 |
| `packages/finance/src/Registry/FinancePermissions.php`, `src/Exceptions/FinanceRefusal.php` | seven keys | +3 keys; `EntryNotFound` | D2 |
| `packages/finance/tests/Features/FinanceAuthorizationMatrixTest.php` | 20 slices; catches `FinanceRefused`; table regex | +4 slices; also `PostingRefused`; journal tables | D2 |
| `tests/Feature/VerticalSliceLayoutTest.php` | mirror rule | **none expected**; slices must have `<X>.php`, `<X>Handler.php`, `tests/Features/<X>/<X>Test.php` | D2 |
| `tests/Feature/QaAccessHttpSurfaceTest.php` | registry read dynamically; no route added | **none expected**; run targeted | D2 |
| `docs/database.md`, `docs/architecture.md` | §6.10, §7, §12.2, §3.3; §3 | step E3 | E3 |

Anything red that is not in this table: stop and report to `architect`.

## 9. Follow-ups for `lead` (backlog rows or notes, not built here)

- **Doc mismatch:** `docs/database.md` §7 still names the retired soft-closed permission; the built key is `finance.periods.post_adjustment` (step E3 fixes the sentence; record it against the T-032 doc step so the two edits do not collide at merge).
- **New row:** an actor-type parameter on `AuditTrail::record` so system and queued writers record `system` (D-27).
- **New row:** the nightly journal integrity re-check (D-20; owner question 4).
- **FP-262:** call `post` last, after its own number; consider one combined call for revenue and cogs (risk 3); own the late-period rule (D-13, D-31); add `journal_entry_id` on documents; surface the year-rollover refusal.
- **Owed keys:** `journal_lines.department_id` (HR task) and `journal_lines.supplier_id` (T-034/FP-265), each as a contract-phase key plus an AC-15a existence check (F-13).
- **FP-268:** `account_balances` when a measured trial-balance query needs it (D-11).
- **Roles task:** grant the three journal keys. **FP-269 / API:** routes with `RequireTenantPermission` for the four slices.
- **`ba`:** F-15 to F-21 (section 0.3).

## Questions for the human

1. **Permission to merge** T-032 (once DONE, including chunk E) into `ai/T-033-journal-entries` before any code (P-1). T-030 and T-029 come with it. No step past A0 can run without it.
