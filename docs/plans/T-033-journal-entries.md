# Plan: T-033 Journal entries and lines, posted through one service

Spec: `docs/specs/T-033-journal-entries.md` (AC-1 to AC-82, AC-33a, AC-33b). Decisions: `docs/decisions/T-033-journal-entries.md` (D-1 to D-23).
Design authority: `docs/database.md` §3.3 (sentinel rule), §6.10 (`journal_entries`, `journal_lines`), §7, §11, §12; `docs/patterns.md` §4 rule 5 and §5; `docs/architecture.md` §3. Shape model: `docs/plans/T-032-chart-of-accounts.md`. Carried-over precedents: T-028 `CreditLedgerWriter` (replay, unique-key fallback with a locking re-read, W-1), T-030 `DatabaseDocumentNumbers` (the one allocator), T-032 Rulings 1 to 6.
Backlog: FP-261. Branch: `ai/T-033-journal-entries`. Gate directory: `modules/host`.

Everything below about shipped code was measured read-only in this worktree at `f336093` (base `4c969fc`, T-032 chunks A and B only) and, for T-030, on branch `ai/T-030-sales` at `c354a0e` (T-030 DONE, contains T-029). Where T-033 needs a T-032 chunk that is not built yet, the plan cites T-032's plan text and marks it **ASSUMED (T-032 step N), re-verify in step 0** (section 0.4).

---

## 0. Verification, findings and rulings

### 0.1 The ten facts `ba` asked for, measured

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Where does Finance live? | **`packages/finance`** (`Flexipos\Finance\`), a path package registered in `bootstrap/providers.php`, not a `MODULES` module. The journal goes in `packages/finance/src/Journal/`. That confirms spec Assumption 2. | `packages/finance/src/FinanceServiceProvider.php` docblock (T-032 Ruling 1). `deptrac.yaml:175` has layer `Package_Finance`. The host `composer.json:26` requires `flexipos/finance` |
| 2 | Is there a number-sequence allocator? | **Not in this base.** The base has only the `number_sequences` table (`0001_01_01_000004`, unique `(branch_id_key, document_type)`, sentinel `COALESCE(branch_id,'-')`). **T-030 built one:** `Contracts\Organization\DocumentNumbers::next(string $documentType, ?string $branchId): string`, implemented by `Organization\Numbering\DatabaseDocumentNumbers`. It uses portable lock-and-increment (`lockForUpdate` + `UPDATE`), runs inside the caller's transaction, and **creates a missing company-wide row itself** (prefix from `DEFAULT_PREFIXES`, padding 6, `reset_period=never`). Ruling 6 reuses it | `git show ai/T-030-sales:packages/organization/src/Numbering/DatabaseDocumentNumbers.php` |
| 3 | How are the three "none" port defaults bound? | **Not bound in this base.** `FinanceServiceProvider` is an empty class, and `NoAccountUsage`, `NoFiscalYearUsage` and `NoPeriodCloseBlockers` do not exist yet. T-032 plan step 8 binds them in the provider | **ASSUMED (T-032 step 8)** |
| 4 | Which FK target tables exist? | Present: `fiscal_periods`, `fiscal_years`, `accounts`, `employees`, `branches`, `locations`, `customers`, `products`. **Absent:** `departments` and `suppliers`. `suppliers` is T-034, which is only registered so far | `Schema::create` sweep over `migrations-tenant/` (54 files) |
| 5 | A unique idempotency key with a nullable `source_purpose`, on MySQL 8.4, MariaDB 10.4 and SQLite | **Use a stored generated column.** `source_purpose_key VARCHAR(30) AS (COALESCE(source_purpose, '-')) STORED`, with `unique(source_type, source_id, source_purpose_key)`. `'-'` cannot be a purpose, because AC-8 requires a leading `[a-z]`. That is the §3.3 rule for a non-ULID column, stated in the migration (`IdentifierColumn::sentinel` is ULID-only on purpose, so this column is written out by hand). `source_id` stays a plain nullable column in the key, so `manual` and `opening` entries without a `source_id` escape it by design (AC-41). Stored generated columns with a unique index already work on the stand-in (`number_sequences`, `TenantBaselineBuildsTest` "refuses a second company-wide sequence") and on both engines (§14) | `IdentifierColumn.php:97`, `0001_01_01_000004`, `003201` `control_marker` |
| 6 | Shapes of `AuditTrail`, `DeclaresAuditActions` and `PermissionResolver` | `AuditTrail::record(string $module, ?string $actorId, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after, ?string $branchId = null): void`, written in the caller's transaction. **It has no actor-type parameter:** `AuditWriter::ACTOR_TYPE` is always `employee`, including for a null actor (F-4). `DeclaresAuditActions::auditActions(): array<string, list<string>>`. `PermissionResolver::decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision` (`->allowed`). `DeclaresPermissions::permissionDeclarations()` returns `PermissionDeclaration(key, module, group, description, isBranchScoped)`, and `KEY_PATTERN` allows three segments. `EmployeeDirectory::isLive(string): bool` exists | `packages/contracts/src/{Audit,Access}/*`; `packages/access/src/Support/AuditWriter.php:67` |
| 7 | bcmath or brick/math? | **brick/math.** `brick/math` 0.14.8 is in the host lock. `catalog` and `customers` require `^0.14`, and `customers\Support\Decimal` is the house parse/canonical/`fitsEighteenFour` helper. The repo avoids bcmath on purpose: `workflow\Conditions\DecimalComparator` has "no bcmath" in its docblock. bcmath is installed (CI `ci.yml`, the Dockerfile and XAMPP) but no code uses it. Finance adds `brick/math: ^0.14` to its `composer.json`. That is the only new dependency, and it is already locked | `packages/customers/composer.json`, `modules/host/composer.lock:10` |
| 8 | CHECK constraints on SQLite and MariaDB 10.4 | **Engine-only by design.** `Kernel\Schema\Support\CheckConstraint::oneSided($table,'debit','credit')` already exists for this exact `journal_lines` shape (`(debit=0 OR credit=0) AND debit>=0 AND credit>=0`; T-028 uses it on `customer_ledger`). Like `between()`, it issues **nothing on SQLite**, because neither Laravel nor SQLite can `ALTER TABLE … ADD CONSTRAINT … CHECK`. MariaDB 10.4 enforces CHECK (10.2.1+, §14) and MySQL 8.4 does too. There is no helper for `total_debit = total_credit`, so Ruling 9 adds one. **The stand-in cannot enforce AC-45** (F-2) | `packages/kernel/src/Schema/Support/CheckConstraint.php` |
| 9 | How does the planner classify expand, backfill and contract? | `MigrationPhase::fromSource` reads `@phase expand\|backfill\|contract` from a comment (default expand). `SourceRules::inspect` rules: AC-33 (an expand step does no drop, rename or change on an existing table) and AC-34 (an FK on an **existing** table only in a contract step; **FKs declared inside `Schema::create` of a new table are allowed in expand**, which is the shipped precedent in `accounts`, `fiscal_years` and `fiscal_periods`). `isDestructive` = AC-33 patterns plus `TRUNCATE`, evaluated phase-blind. An added FK is not destructive. `PlanMigrationRunHandler::assertContractStepsUnblocked` plans a contract step only once every tenant is at the version before it. A `@phase backfill` file may insert rows (T-029 `002907` and T-032's planned `003204` seed). Migrations may import only `Illuminate\` and `Kernel\Schema\Support\` | `SourceRules.php:111-126, 497-506, 550-693`; `MigrationPhase.php` |
| 10 | Which permission key? | `finance.periods.post_adjustment` is the key T-032 declares (T-032 spec AC-64, plan Ruling 5). **It is not built in this base yet** (no `FinancePermissions` class exists): **ASSUMED (T-032 step 8)**. `docs/database.md` §7 (line 1588) still carries the older name, and step 17 rewrites that sentence (describe, don't quote). D-10 holds | `docs/database.md:1588`; T-032 plan Ruling 5 |

Other measurements the plan rests on:
- **Precedent for a caller-transaction ledger writer.** `customers\Ledger\CreditLedgerWriter` does a replay pre-check, then the work, then an insert that catches `UniqueConstraintViolationException`, re-reads **with `lockForUpdate`** (W-1), and compares. T-033 keeps that shape and closes its one gap for numbered documents (Ruling 7, savepoint).
- **Audit action naming.** Every shipped action is snake_case with no dots (`customer_created`, `catalog_product_updated`), and T-032 plans `account_created`. The spec's `finance.journal.posted` is permission-key style (F-5). `audit_logs.action` is VARCHAR(80), so either spelling fits.
- **SQLite stores `DECIMAL` with NUMERIC affinity**, and text-to-REAL keeps 15 significant digits. A stand-in read-back of an 18-digit amount is lossy, so every 18-digit round trip is `[ENGINE]` (risk 4).
- **The stand-in rebuilds a table to add an FK.** Laravel's SQLite `compileAlter` turns `foreign_keys` off for the rebuild. `fiscal_years` is the parent of `fiscal_periods` (CASCADE), so step 3 asserts the periods survive the contract run.
- **Version base.** `.env.example` here is 54 (51 + T-032's three expands). T-030's tip is 73. The merged base is measured at step 0. T-033 adds **4**.
- **Migration prefix.** `2026_11_16_0033xx` sorts after T-029/T-030 (`2026_11_10_*`) and T-032 (`2026_11_15_0032xx`), so no rename is needed whichever way the predecessors merged.

### 0.2 Findings for `ba` (routed through `lead`; S = serious)

- **F-1 (S, AC-75: where the FKs go).** AC-75 puts every FK "onto an existing table", including the ones from the two **new** tables onto `fiscal_periods`, `accounts`, `employees` and the rest, into the contract file. The codebase rule (SourceRules AC-34) and every shipped precedent (`accounts` → `employees`, `fiscal_periods` → `fiscal_years`) declare a new table's keys inside its own `Schema::create`, in expand: the table has no rows, so there is nothing to validate. Moving them to contract buys nothing, and on the stand-in it means rebuilding two fresh tables.
  - **Plan:** the expand files create both tables **with** all their keys. The contract file holds only the key on the one pre-existing, populated table: `fiscal_years.retained_earnings_entry_id` → `journal_entries` (RESTRICT), with AC-76's orphan pre-check. It is still one contract file and one fleet run, as AC-75 says.
  - **Ask:** amend AC-75 to "the keys of the two new tables are declared with them; the contract migration adds the one key onto `fiscal_years`".
  - **Fallback if `ba` keeps AC-75 literally:** the same keys move into `003304` in the `000506` shape. That is a mechanical change to step 2/3 and touches no other step.
- **F-2 (S, AC-45 "proved on the stand-in").** This cannot hold. `CheckConstraint` is engine-only by construction (0.1 fact 8), and SQLite cannot add a CHECK after `CREATE TABLE`.
  - **Plan, stand-in half:**
    - the migration calls the helpers, and the exact SQL text is asserted through the pure `*Statement()` functions;
    - the service refuses every violating shape before any write (AC-17 to AC-20).
  - **Engine half:** a raw violating insert is rejected on MySQL 8.4 and MariaDB 10.4 (`[ENGINE]`).
  - **Ask:** reword AC-45's parenthesis to "(the statement text on the stand-in; enforcement `[ENGINE]`)".
- **F-3 (S, AC-33b against the one allocator).** T-030's `DocumentNumbers` **creates** a missing company-wide row. AC-33b wants a fail-closed refusal. Building a second allocator to get fail-closed would give the codebase two writers of `number_sequences`.
  - **Plan:** reuse `DocumentNumbers` (Ruling 6) and add `'journal_entry' => 'JE-'` to its `DEFAULT_PREFIXES` (padding 6 and `never` are already its defaults). A missing row is then re-created **identical to the seed**, and numbering is still gap-free, because the insert commits or rolls back with the entry. The seed stays (AC-32), because it also removes T-030's unproven first-use gap-lock race.
  - **Ask:** amend AC-33b to "the service never creates the sequence row itself; the shared allocator's first-use row carries the same `JE-`/6/never defaults as the seed, so a missing row never changes the number format".
  - **Fallback if fail-closed is required:** an additive `DocumentNumbers::nextExisting()` that throws a new `DocumentNumbersRefusal::SequenceMissing`. This is an edit to a DONE task's contract, so it needs `lead`'s approval.
- **F-4 (AC-65, actor `system`).** `AuditTrail` has no actor-type parameter, and `AuditWriter` stamps `actor_type = employee` on every row, even with a null actor.
  - **Plan:** a null actor writes `actor_id = null`, and `after` carries `is_auto: true`.
  - **Ask:** amend AC-65 to say exactly that.
  - **Follow-up row:** an optional `ActorType` on `AuditTrail::record`. Every queued or system writer needs it, not only the journal, and it touches `access` and one engine-test fake.
- **F-5 (AC-65, action spelling).** House style is snake_case per module, for example T-032's `fiscal_period_closed`. Recommend `journal_entry_posted` and `journal_entry_reversed`. **Default if unanswered:** the spec's literal `finance.journal.posted` / `finance.journal.reversed`, as two constants. Either way it is a one-line change.
- **F-6 (closed vocabulary gaps).** The spec uses several refusals it never names. The plan adds:
  - **`unknown_field`** (AC-15, AC-16, AC-71: unknown keys, naming every one in `fields`);
  - **`invalid_actor`** (a service caller passing a malformed or non-live actor id; this makes AC-73's "`posted_by` always references a live employee or is null" true for module calls as well, through `EmployeeDirectory::isLive`).
  - List filters reuse T-032's existing `validation` (naming the field), `page_size_invalid` and `cursor_invalid`.
  - An unknown (but well-formed) `branch_id` or dimension id is **not** given a code: its FK refuses it as a database error, which rolls back the caller's whole transaction (no number consumed, no row).
  - **Ask:** adopt the two codes, and confirm the FK-error behaviour or name a code.
- **F-7 (AC-51 against D-23).** D-23 says a different date is a "conflict"; AC-51 says `already_reversed`. The plan builds **AC-51**: `reverse` never returns `idempotency_conflict`. D-23 needs one word changed.
- **F-8 (AC-34 against a race; informational).** "Does not call the gate or the account check" holds for a sequential replay. The loser of a concurrent same-key race (AC-40) has already run both before it meets the winner's row. Its outcome is still exactly AC-40's.
- **F-9 (AC-7/AC-41; confirm).** `opening` without a `source_id` also has no idempotency, exactly like `manual` (no key to match). Its callers own double-submit control.
- **F-10 (memo and trimming; confirm).** AC-14 allows a 0-length memo. Finance's `Input::text` (T-032 F-16) trims and refuses a blank. **Plan:**
  - an empty or whitespace-only memo is stored as null;
  - memo and description are stored trimmed;
  - the fingerprint is over the stored (normalised) form, so `"x"` and `" x "` are one payload;
  - amounts are fingerprinted at four places, so `"12.5"` and `"12.5000"` are one payload (AC-35's "any difference in debit" is read as a numeric difference).
- **F-11 (AC-63 wording).** "Finance never reads journal tables directly" cannot be literal, because the journal *is* in Finance. Read as: `Finance\Accounts\*` and `Finance\Periods\*` never name a journal table, and only `Finance\Journal\Ports\*` answer the three ports. The source scan in step 15 enforces it.
- **F-12 (AC-64 and NFR "measured"; informational).** Under ENGINE_HOLD nothing can be measured on an engine. The stand-in asserts `EXPLAIN QUERY PLAN` uses the `(account_id, entry_id)` covering index. The engine `EXPLAIN` and the 10-line transaction time are written into the engine test and reported NOT VERIFIED.
- **F-13 (AC-15/D-16; informational).** `department_id` (no HR table) and `supplier_id` (T-034 not built) stay unguarded. They are owed to those tasks as contract-phase keys. If T-034 merges before step 0, step 0 moves `supplier_id` to guarded.
- **F-14 (cross-document, for FP-262).** `docs/database.md` §12.2 says a late offline sale "posts to the earliest open period with a note". D-13 makes this service refuse and leaves re-dating to the caller. That is not a conflict for this task, but FP-262 inherits it, and step 17 records it beside §12.2.

### 0.3 Questions for `lead`

1. **Merge preconditions (blocking; needs the human's permission, which is denied by default on this machine: raise it now).** Two lines must be in this branch before step 1:
   - **P-1:** T-032 DONE.
   - **P-2:** T-030 at `c354a0e` or later, which carries T-029 (for `DocumentNumbers`, Ruling 6).
   - **Recommended topology:** T-032 absorbs T-030 before it closes, because T-032 plan §5 already owns the "T-029 first" conflict resolution (AC-29.3 narrowing, regenerated catalogue and literals). T-033 step 0 then merges only T-032's tip.
   - **Otherwise:** step 0 merges T-032, then T-030, and regenerates (never hand-merges) the catalogue, `.env.example`, `tenantTreeTables()` and the migration-count literal.
2. **One-line edit to a DONE task's code:** `'journal_entry' => 'JE-'` in `DatabaseDocumentNumbers::DEFAULT_PREFIXES` (F-3). Approve, or choose F-3's fallback.
3. **Kernel helper addition:** `CheckConstraint::balanced($table, $a, $b)` (Ruling 9). It is additive, with its own `*Statement()` test.
4. File the follow-up rows in section 10.

### 0.4 What T-033 takes from T-032's unbuilt chunks (each **ASSUMED: re-verify in step 0; stop on any difference**)

| Needed | ASSUMED from T-032 plan |
|---|---|
| `FinanceServiceProvider` implements `DeclaresAuditActions` and `DeclaresPermissions`, and binds `ChartOfAccounts`, `PeriodGate` and the three `No*` defaults | step 8 (Ruling 2, files table) |
| `Registry\FinancePermissions` (seven keys incl. `finance.periods.post_adjustment`) and `Registry\FinanceAuditActions` | step 8 (Rulings 5, 11) |
| `Support\Authorize::require(?string $actorId, string $key)`: first statement of every handler, before any transaction; null or non-live actor gives `not_permitted` | step 8 (Ruling 5) |
| `Support\TenantConnection`, `Support\Page` | steps 8 and 12 (files table) |
| `Accounts\{NoAccountUsage}`, `Periods\{NoFiscalYearUsage, NoPeriodCloseBlockers}` classes | step 8 |
| `Accounts\DatabaseChartOfAccounts::postable()`: refuses non-live, inactive or header with `AccountRefused`, and takes `sharedLock()` when a transaction is open | step 12 (Ruling 2, AC-63) |
| `Periods\DatabasePeriodGate::clear()`: `LogicException` outside a transaction, `sharedLock` on the period, `post_adjustment` checked through `PermissionResolver`, null actor refused in soft-closed | step 15 (AC-47 to AC-51) |
| `UpdateAccount` gives `code_locked`; retype gives `type_change_refused` (condition `has_postings`); `DeleteAccount` gives `has_postings`, each through `AccountUsage` under the target's `liveForUpdate` | steps 10, 11 (N-1) |
| `DeleteFiscalYear` gives `fiscal_year_in_use` through `FiscalYearUsage`; `TransitionFiscalPeriod` gives `close_blocked` through `PeriodCloseBlockers`, each under year and period `FOR UPDATE` | steps 13, 14 |
| Seed migration `003204` (chart plus the current UTC year) and owed-columns contract `003208` | steps 16, 17 |
| `tests/Support/{FinanceSeed.php, finance-fixture.php, FakeUsagePorts.php}`: an employee per permission profile through `AccessTenantFixture` | step 8 |
| `QaFinanceSourceRulesTest` "one writer" rule naming `table('fiscal_years')` | step 19 |
| `FinanceSchemaEngineTest` / `EngineDatabase` exact-name pattern | step 20 |
| `FinanceTenantSchemaTest` "no FK on `retained_earnings_entry_id` (F-14)" and "exactly the six documented foreign keys" | step 2 (**built**: lines 254 and 278 here) |

### 0.5 Rulings

**Ruling 1: placement.** All journal code lives in `packages/finance/src/Journal/`:
- `DatabasePostingService`
- `JournalWriter`
- `PostingInput`
- `ReversalInput`
- `Amounts`
- `Fingerprint`
- `JournalRows`
- `Ports/*`
- `Features/<4 slices>`

The contracts go in `packages/contracts/src/Finance/`. No new package and no deptrac edge: Finance already reaches `Contracts\Organization\DocumentNumbers`, `Contracts\Access\EmployeeDirectory` and `Contracts\Audit\AuditTrail` through `Package_Contracts`. brick/math is vendor and outside deptrac's layers.

**Ruling 2: the published contract** (AC-1, AC-4; plain PHP, no vendor imports):

| Symbol | Shape |
|---|---|
| `PostingService` | `post(PostingRequest $r): PostedEntry`; `reverse(ReversalRequest $r): PostedEntry`. Both have the docblock rule "must run inside the caller's open transaction (`LogicException` otherwise); call it **after every other lock your transaction takes, including your own document number**; it never opens, retries or commits a transaction" |
| `PostingLine` | readonly `mixed $accountId, mixed $debit = '0', mixed $credit = '0', mixed $memo = null, array $dimensions = []`. Never throws: every check is `post()`'s, so D-21's order holds |
| `PostingRequest` | readonly `mixed $sourceType, mixed $sourceId, mixed $sourcePurpose, mixed $entryDate, mixed $description, mixed $branchId, mixed $lines, ?string $actorId`. Also `static fromArray(array $body, ?string $actorId): self`, which refuses `unknown_field` naming **every** unknown key at entry and line level in one throw. It distinguishes an omitted side (it becomes `'0'`) from an explicit `null` (kept, then refused `invalid_amount`) |
| `ReversalRequest` | readonly `mixed $entryId, mixed $entryDate, mixed $reason, ?string $actorId`, with `fromArray` |
| `PostedEntry` | readonly `id, number, periodId, entryDate, totalDebit, totalCredit, status (JournalEntryStatus), replayed (bool)`. Nothing else (AC-4) |
| `JournalEntryStatus` | `draft`, `posted`, `reversed` |
| `JournalSourceType` | D-7's 26 values, in the document's order |
| `PostingRefusal` | the spec's codes plus F-6's `unknown_field` and `invalid_actor` |
| `PostingRefused` | `RuntimeException`, private constructor with named constructors. Carries `public readonly PostingRefusal\|PeriodRefusal\|AccountRefusal $reason` (so gate and account codes pass through **unchanged**: AC-23, AC-28), `?string $field` (a path such as `lines.3.debit`), `list<string> $fields` (`unknown_field`), and `array $context` (both totals for `entry_unbalanced`, the stored id for `idempotency_conflict` / `already_reversed`). `getPrevious()` is the gate or account exception |

That is 10 files.

**Ruling 3: validation order (D-21), all pure, in `PostingInput`/`ReversalInput`, before any read.** Each step stops at the first failing field (lines ascending).
- (0) `LogicException` when `transactionLevel() === 0`: the first statement, before any parse or read (AC-2).
- (1) Unknown keys (`fromArray`; dimension keys checked again in `post`).
- (2) Shape, in request order:
  - `source_type`: exact `tryFrom`;
  - `source_id`: required unless `manual`/`opening`, a ULID folded to upper case;
  - `source_purpose`: `\A[a-z][a-z0-9_]{0,29}\z`, never without `source_id`;
  - `entry_date`: `Input::date`;
  - `description`: `Input::text` 500, optional;
  - `branch_id`;
  - actor (`invalid_actor`: not a ULID);
  - `lines`: a list, 2 to 200;
  - per line: `account_id`, `memo` (255, blank becomes null), dimensions (7 keys; `project_ref`/`tax_id`/`tax_base` are unknown).
- (3) Amounts per line: `is_string` and `\A(0|[1-9][0-9]{0,13})(\.[0-9]{1,4})?\z` (never `^…$`), then exactly one side positive (`line_both_sides` / `line_zero`).
- (4) Balance: exact `BigDecimal` sums compared at scale 4 (`entry_unbalanced`), then `total_out_of_range` (> `99999999999999.9999`).
- (5) Actor liveness through `EmployeeDirectory::isLive` (one indexed read; `invalid_actor`).

Every refusal maps to `PostingRefused`, never `FinanceRefused`.

**Ruling 4: amounts** (`Journal\Amounts`, pure, brick/math). It reuses the semantics of `customers\Support\Decimal` (parse, `canonical` with `RoundingMode::Unnecessary`, the `MAX` constant) as Finance's own copy (FP-433 convention: deptrac forbids importing another package's support). It is **stricter**: no int input, no leading zeros, and the pattern is anchored with `\A…\z`. Output is always the four-decimal string. No `float` anywhere. Step 15's scan refuses `(float)`, `floatval`, `round(` and `number_format(` in `Journal/`.

**Ruling 5: the idempotency key and the fingerprint** (AC-34 to AC-41, D-6, D-23).
- **Key:** `unique(source_type, source_id, source_purpose_key)`, where `source_purpose_key` is stored generated `COALESCE(source_purpose, '-')` (0.1 fact 5).
- **Fingerprint:** `payload_fingerprint CHAR(64)` = lower-case hex SHA-256 of canonical JSON (`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`) of a **positional** list:
  - `[entry_date, branch_id, description, [[account_id, debit4, credit4, memo, branch_id, location_id, department_id, employee_id, customer_id, supplier_id, product_id], …]]`;
  - normalised values (F-10); line order is significant; actor and clock are excluded.
- **Reversal:** key `(reversal, original_id, '-')`; fingerprint over `[entry_date, reason]`. The key is also the database backstop for "exactly one reversal per entry" (AC-57).

**Ruling 6: numbering reuses T-030's `DocumentNumbers`** (D-8, AC-32 to AC-33b; precondition P-2).
- `next('journal_entry', null)` always uses the company-wide row, even when the entry has a branch. It is called inside the savepoint of Ruling 7, after every validation, the replay check, the gate and the accounts.
- A backfill migration seeds the row (prefix `JE-`, padding 6, `never`, next 1; insert-if-absent by `branch_id_key='-'` and `document_type='journal_entry'`).
- `DEFAULT_PREFIXES` gains `journal_entry` (F-3).
- `DocumentNumbersRefused` (`unsupported_reset_period`, `number_too_long`) is a deployment fault. It propagates as-is and is never mapped to a `PostingRefusal`.

**Alternative rejected:** a Finance-owned allocator on `number_sequences` would be a second writer of a table Organization owns, and two rules about the same row. A merge precondition is cheaper than that permanent cost.

**Ruling 7: the posting algorithm and the savepoint.** `post()`:
1. Rulings 3 to 5 run, pure.
2. **Replay lookup**, only when `source_id` is non-null: a **plain** read by key (no gap lock, so two first posts cannot deadlock on it). If found: same fingerprint returns `PostedEntry` from the stored row with `replayed=true`; a different fingerprint is `idempotency_conflict`. Either way, no gate, accounts, number or audit.
3. `PeriodGate::clear(entry_date, actor)`. A `PeriodGateRefused` becomes `PostingRefused(reason: PeriodRefusal, field: entry_date)`.
4. Distinct account ids sorted ascending (`strcmp` on the upper-case ULIDs = `ascii_bin` order), then `ChartOfAccounts::postable($id)` each. An `AccountRefused` becomes `PostingRefused(reason: AccountRefusal, field: lines.{first index}.account_id)`.
5. **Savepoint** (`$connection->transaction(fn)` nested, one attempt), inside which:
   - `DocumentNumbers::next`;
   - insert the entry;
   - insert **all lines in one statement**;
   - write `AuditTrail::record`.
6. **Catch `UniqueConstraintViolationException`** from the savepoint. The savepoint is already rolled back, so **the sequence increment is undone** (the gap `CreditLedgerWriter` would leave if it numbered). Then re-read the key **with `lockForUpdate`** (W-1): a row means replay or `idempotency_conflict`; no row means rethrow (a `number` duplicate is an invariant failure, never a refusal).
7. Any other exception propagates. `post` never catches a deadlock (1213) and never retries: the caller's outer `transaction(fn, attempts: 2)` owns retry.

`PostedEntry` for a fresh post is built from the computed strings and never re-read (risk 4).

**Ruling 8: lock order, and composing inside a caller's transaction.**

| Order | `post` | `reverse` |
|---|---|---|
| L0 | the caller's own locks, including **its own document number** (for example Sales' `sale`/`refund` sequence row) | the caller's own locks (for example a void's sale row) |
| L1 | period row, shared (gate) | period of the **reversal date**, shared (gate) |
| L2 | accounts, shared, ascending id | accounts of the original lines, shared, ascending id |
| L2b | (none) | **original entry `FOR UPDATE`**, then the status check (race path of AC-51/57) |
| L3 | `journal_entry` sequence row `FOR UPDATE` (the hottest lock; held to the caller's commit) | same |
| L4 | insert entry and lines (plus the locking re-read on a duplicate) | insert the reversal and its lines; `UPDATE` the original `SET status='reversed', reversed_by_id, updated_at WHERE id=? AND status='posted'`, asserting 1 row |

How the other lock takers fit around it:
- **Against T-032 (all ASSUMED, T-032 Ruling 6):**
  - A period close holds year and periods `X`, then counts drafts. A poster holding the period `S` makes the close wait, and a poster arriving after waits at L1 (AC-30).
  - An account delete, recode or retype holds the account `X`, then asks `hasPostings`. Every poster for that account is held at L2 (AC-26).
  - A year delete holds years and periods `X`, so posters wait at L1.
- **Why `reverse` takes L1/L2 before L2b:** close(P) holds P `X` and then scans `journal_entries` in P. If `reverse` locked the original (in P) first and then asked for P `S`, the two would wait on each other. With the period first, `reverse` waits at L1 before it touches the original.
- **Two posters** order on L1 `S` (compatible), then L2 `S` (compatible), then L3 `X` (a queue). They cannot deadlock.
- **The three ports read with `sharedLock()`** (Ruling 10), the T-032 `AccountReferrers` rule. Under the account's own `X` that read always sees every committed line.
- **One known engine deadlock:** a caller that posts **twice** in one transaction (FP-262's revenue and cogs) takes new account `S` locks *after* it holds L3. If an account admin then holds account `X` and its next-key usage scan waits on that caller's uncommitted lines, InnoDB rolls one back (1213). Data stays correct. T-032 handlers retry (`attempts: 2`) and Sales' `CompleteSale` retries once. Recorded as risk 3 and exercised in the engine test. FP-262 may remove it with one combined call (follow-up).
- **The caller rule** (in the contract docblock, and a note for FP-262): take your own number before calling `post`/`reverse`. After it returns, write only rows you already hold or created in this transaction (for example `sales.journal_entry_id`).

**Ruling 9: schema.**
- **Two expand files.** Each creates its table with its indexes, uniques and keys (F-1), and its CHECKs via `CheckConstraint::oneSided('journal_lines','debit','credit')` and the new `CheckConstraint::balanced('journal_entries','total_debit','total_credit')`. `balanced` follows the existing shape exactly: engine-only, a literal verb, `assertIdentifier`, and a pure `balancedStatement()` with an exact-text test.
- **One backfill file** for the sequence seed.
- **One contract file** for `fiscal_years.retained_earnings_entry_id` in the `000506` shape:
  - count the orphans first, and refuse naming table, column and count, never a value;
  - skip a key already present;
  - no `down()`.

Section 4 has the column lists.

**Ruling 10: the three ports** (`Journal\Ports\*`, AC-59 to AC-64, D-12). Each read is `sharedLock()` and touches journal tables only:
- `JournalAccountUsage::hasPostings`: `journal_lines WHERE account_id=? LIMIT 1`, using the `(account_id, entry_id)` index, any status. An unknown or malformed id gives false.
- `JournalFiscalYearUsage::hasEntries`: `journal_entries WHERE entry_date BETWEEN ? AND ? LIMIT 1`, any status.
- `JournalPeriodCloseBlockers::blockersFor`: `COUNT(*) … entry_date BETWEEN … AND status='draft'`, which gives `[PeriodBlocker('draft_journal_entries', n)]` or `[]`.

`FinanceServiceProvider` **replaces** the three `No*` bindings (edit in place, so binding order does not matter). The `No*` classes remain for tests that bind them.

**Ruling 11: handlers and permissions** (AC-68 to AC-74, D-10). Four slices in `Journal/Features/`, each with `Authorize::require` as its first statement (ASSUMED T-032 step 8), refusing `not_permitted` before any transaction or read:

| Slice | Key | Notes |
|---|---|---|
| `GetJournalEntry` | `finance.journal.view` | by `id`, or by `(source_type, source_id, source_purpose)` through `source_purpose_key`; lines ordered by `line_no`; `entry_not_found` |
| `ListJournalEntries` | `finance.journal.view` | page 1 to 200, default 50 (refused out of range); keyset cursor `(after_entry_date, after_number, after_id)`, all or none; filters `from`, `to`, `status`, `source_type`, `period_id`, `account_id` (`EXISTS` on lines), `branch_id`; order `entry_date DESC, number DESC, id DESC`; summary rows only. `from > to` gives an empty page |
| `PostManualJournalEntry` | `finance.journal.post_manual` | `fromArray` with `source_type` **not** allowed (`unknown_field`), fixed to `manual`; `transaction(fn, attempts: 2)`; actor = the employee |
| `ReverseJournalEntry` | `finance.journal.reverse` | `transaction(fn, attempts: 2)` around `PostingService::reverse` |

`FinancePermissions` gains the three keys (group `journal`, `isBranchScoped=false`). The service itself checks no permission (AC-58, AC-74).

**Ruling 12: audit** (AC-65 to AC-67, D-19). There is one `AuditTrail::record('finance', $actor, ACTION, 'journal_entry', $id, null, $after)` inside the savepoint.
- **`after`** holds `number`, `source_type`, `source_id`, `source_purpose`, `entry_date`, `period_id`, `total_debit`, `total_credit`, `line_count`, `is_auto`, plus `reason` and `original_id` for a reversal.
- **Action names:** F-5. They are constants on `DatabasePostingService`, collected into `FinanceAuditActions`.
- **Not audited:** a replay, a refusal, a read, and the original's status flip in a reversal.

**Ruling 13: test cost.** As T-032 Ruling 12: pure datasets carry most cases. There is one fixture-backed file per slice and per service operation, and every refusal asserts no entry, no line, no audit row and an unchanged sequence. The estimate is about 230 cases, about 60 % pure. If a chunk adds more than 10 % to the `test` step, stop and report to `lead`.

---

## 1. Approach summary

1. Merge T-032 (DONE) and T-030 (for `DocumentNumbers`) into this branch, then measure the gate (P-1, P-2).
2. Add two tenant tables with their keys, uniques and engine CHECKs, a seeded `journal_entry` sequence, and one contract key on `fiscal_years`.
3. Publish a `PostingService` contract with typed `mixed` value objects, so every refusal comes out of one deterministic, pure validation pass (D-21) as one `PostingRefused` that carries period and account codes unchanged.
4. Do exact brick/math string arithmetic, with no float anywhere.
5. Make idempotency a stored-sentinel unique key plus a SHA-256 payload fingerprint. The replay lookup runs before the gate, and a unique-key collision is resolved inside a savepoint, so no number is ever consumed by a non-commit.
6. Run inside the caller's transaction with one fixed lock order, period then accounts then sequence (`reverse` adds the original row after the accounts). The service never retries.
7. Reversal is the only update, a compare-and-set on `status`.
8. Rebind the three T-032 ports to `sharedLock` journal reads. Add four permission-checked handlers. A source scan proves the one-writer rule, and an engine test (held) carries every `[ENGINE]` clause.

## 2. Alternatives rejected

- **A Finance-owned allocator:** a second writer of `number_sequences` (Ruling 6).
- **A nullable `source_purpose` directly in the unique key:** NULLs are distinct on both engines (AC-39).
- **A fingerprint-free replay** (compare only the key): a retry with different content would silently return the old entry (AC-35).
- **Re-checking the key under the sequence lock instead of a savepoint:** this needs `DocumentNumbers` split into lock and increment, a contract change, for the same result.
- **A shared-lock replay pre-check:** two first posts of one key take gap locks and deadlock on insert.
- **All FKs in a contract file:** F-1.
- **`account_balances` now:** D-11.
- **Value objects that validate in their constructors:** refusals would escape D-21's order and fire before `post()` could guarantee "nothing written".
- **A trigger for the balance rule:** D-20.
- **A `modules/journal` leaf:** the ports must rebind inside Finance (spec Assumption 2).

## 3. Files to change / create

`dev` writes every file; `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Finance/` (new, 10)
`PostingService`, `PostingRequest`, `PostingLine`, `ReversalRequest`, `PostedEntry`, `PostingRefusal`, `PostingRefused`, `JournalEntryStatus`, `JournalSourceType`, `JournalDimension` (the seven keys, in fixed order; used by input and fingerprint).

### `packages/finance` (new files unless stated)

| Path | What |
|---|---|
| `composer.json` (edit) | add `brick/math: ^0.14`; description mentions the journal |
| `src/FinanceServiceProvider.php` (edit) | bind `PostingService`; **replace** the three `No*` bindings; declare 2 audit actions and 3 keys through the registries |
| `src/Registry/FinancePermissions.php`, `FinanceAuditActions.php` (edit) | Rulings 11, 12 |
| `src/Support/Limits.php` (edit) | `MEMO = 255`, `REASON = 500`, `NUMBER = 30`, `FINGERPRINT = 64`, `MAX_LINES = 200` |
| `src/Journal/{DatabasePostingService, JournalWriter, PostingInput, ReversalInput, ParsedPosting, Amounts, Fingerprint, JournalRows}.php` | Rulings 3 to 8. `JournalWriter` is the **only** class that inserts or updates journal rows. `JournalRows` holds every read, named by lock mode (`byKeyUnlocked`, `byKeyForUpdate`, `entryForUpdate`, `linesOf`) |
| `src/Journal/Ports/{JournalAccountUsage, JournalFiscalYearUsage, JournalPeriodCloseBlockers}.php` | Ruling 10 |
| `src/Journal/Features/{GetJournalEntry, ListJournalEntries, PostManualJournalEntry, ReverseJournalEntry}/…` | Ruling 11 (command, handler, result) |
| `tests/Journal/*`, `tests/Journal/Features/*`, `tests/Contracts/JournalContractsTest.php`, `tests/Schema/JournalTenantSchemaTest.php`, `tests/Support/JournalSeed.php` | section 7. `JournalSeed` reuses `FinanceSeed` (a year, accounts, employees per key) and adds a raw-insert `draft` entry helper for AC-61 (the only draft path; tests only) |

### `packages/kernel`
- `src/Schema/Support/CheckConstraint.php` (edit): add `balanced()` and `balancedStatement()`.
- `tests/Schema/Support/CheckConstraintTest.php` (edit): exact text, identifier refusal, no statement on SQLite.

### `packages/organization` (after P-2)
- `src/Numbering/DatabaseDocumentNumbers.php` (edit): one line in `DEFAULT_PREFIXES` (F-3, lead Q2).

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_16_003301_create_journal_entries_table.php` | expand |
| `…003302_create_journal_lines_table.php` | expand |
| `…003303_seed_journal_entry_number_sequence.php` | `@phase backfill` |
| `…003304_add_retained_earnings_entry_foreign_key.php` | `@phase contract` |
| `tests/Feature/JournalMigrationsTest.php` | steps 2, 3 |
| `tests/Feature/QaJournalSourceRulesTest.php` | step 15 |
| `tests/Engine/JournalSchemaEngineTest.php` | step 16 |

## 4. Data / API changes

**Schema** (tenant only; no master change):

| Table | Columns | Keys, indexes, checks |
|---|---|---|
| `journal_entries` | `id` (`IdentifierColumn::primary`), `number` VARCHAR(30), `entry_date` DATE, `period_id` ULID, `branch_id` ULID NULL, `source_type` VARCHAR(30), `source_id` ULID NULL, `source_purpose` VARCHAR(30) NULL, `source_purpose_key` VARCHAR(30) **stored** `COALESCE(source_purpose, '-')`, `status` VARCHAR(30), `total_debit` DECIMAL(18,4), `total_credit` DECIMAL(18,4), `description` VARCHAR(500) NULL, `reversal_of_id` ULID NULL, `reversed_by_id` ULID NULL, `payload_fingerprint` CHAR(64), `posted_at` DATETIME(3), `posted_by` ULID NULL, `is_auto` BOOL default false, `created_by` ULID NULL, `created_at` DATETIME(3), `updated_at` DATETIME(3). No `deleted_at`, tenant, currency or `plugin_name` (D-4, D-7) | unique `number`; unique `(source_type, source_id, source_purpose_key)`; index `(entry_date, number)`; index `(period_id, status)`. FKs: `period_id` → `fiscal_periods`, `branch_id` → `branches`, `posted_by` and `created_by` → `employees`, `reversal_of_id` and `reversed_by_id` → `journal_entries` (all RESTRICT, the engine default). `CHECK (total_debit = total_credit)` `[ENGINE]` |
| `journal_lines` | `id`, `entry_id`, `line_no` SMALLINT UNSIGNED, `account_id`, `debit` DECIMAL(18,4) default 0, `credit` DECIMAL(18,4) default 0, `memo` VARCHAR(255) NULL, `branch_id`, `location_id`, `department_id`, `employee_id`, `customer_id`, `supplier_id`, `product_id` (ULID NULL), `created_at` DATETIME(3). No unit, quantity, currency, `project_ref`, `tax_id` or `tax_base` | unique `(entry_id, line_no)`; index `(account_id, entry_id)`; index `customer_id`, `supplier_id`, `employee_id`. FKs: `entry_id` → `journal_entries` **CASCADE** (pure child, §3); `account_id` → `accounts`; `branch_id` → `branches`; `location_id` → `locations`; `employee_id` → `employees`; `customer_id` → `customers`; `product_id` → `products`. **`department_id` and `supplier_id` are unguarded and owed** (F-13). `oneSided` CHECK `[ENGINE]` |
| `number_sequences` | one row: `branch_id` null, `document_type` `journal_entry`, `prefix` `JE-`, `padding` 6, `reset_period` `never`, `next_number` 1 | none (insert-if-absent) |
| `fiscal_years` | none | FK `retained_earnings_entry_id` → `journal_entries` RESTRICT (contract) |

The FK inventory grows by **14**: 6 on entries, 7 on lines, 1 on `fiscal_years`.

Four new decimal columns are all (18,4). `APP_REQUIRED_SCHEMA_VERSION` = merged base + 4. The catalogue is republished with `php artisan schema:publish --write-fixture`, never hand-merged.

**Contracts:** Ruling 2. **Permissions:** `finance.journal.view`, `finance.journal.post_manual`, `finance.journal.reverse`. **Audit actions:** 2 (F-5). **HTTP, console, jobs, outbox, cache:** none. **Dependencies:** brick/math in Finance's `composer.json`; it is already locked, so the `composer.lock` diff is the path package's `require` entry only.

## 5. Merge order

Section 0.3 Q1. After the merge, step 0 re-verifies section 0.4 and the two T-030 facts (the `DocumentNumbers` signature and `DEFAULT_PREFIXES`). If T-034 is already in, `supplier_id` becomes guarded (F-13).

## 6. Implementation steps

Rules carried from T-032 §6:
- red-first by fixture or omission, never by stubbing a guard open;
- the red-list rule after steps 0, 2, 3, 13 and 14 (compare with section 9; stop on any file it does not name);
- no child processes;
- unique test basenames;
- list foreign `php.exe` processes before each gate run;
- each mutation is run once, reverted byte for byte, and recorded with red-then-green counts in `dev`'s hand-back.

### Chunk A: base, schema, static instruments

- [ ] **Step 0: merge, measure, verify. STOP on any difference.**
  - **Merge:** `lead` obtains permission and merges per 0.3 Q1.
  - **Baseline:** run `composer check` and record passed, failed and skipped counts, the `test` seconds and the process list.
  - **Read source to confirm every 0.4 row and the 0.1 facts:**
    - `DocumentNumbers::next` signature and `DEFAULT_PREFIXES`;
    - the `postable` lock; the gate's `LogicException`;
    - the `No*` binding site; `Authorize`;
    - the `QaFinanceSourceRulesTest` allow-list form;
    - whether `suppliers` or `departments` exist.
  - **Grep** for tests that assert `number_sequences` is empty, or count all its rows, after a whole-tree build. Add each to section 9 before step 2.
  - *(No AC.)*
- [ ] **Step 1: plumbing.**
  - brick/math in Finance's `composer.json` (`composer update flexipos/finance`, `composer validate --no-check-publish`).
  - `Limits` constants.
  - `CheckConstraint::balanced` + `balancedStatement` with `CheckConstraintTest` cases: exact text; `Total_Debit` refused as an identifier; no statement on SQLite.
  - The `DEFAULT_PREFIXES` line (after lead Q2), plus T-030's `DocumentNumbersTest` case that a fresh `journal_entry` row gets `JE-000001`.
  - *(Groundwork; AC-33b per F-3.)*
- [ ] **Step 2: migrations `003301` to `003303`, with `JournalTenantSchemaTest`** (own stand-in, the `FinanceTenantSchemaTest` shape, whole tenant tree).
  - Exact sorted column lists with nullability, and no `tenant_id`, `deleted_at`, currency or unit column (AC-42, AC-43, AC-79).
  - **Uniques by raw double insert:**
    - `number`;
    - `(entry_id, line_no)`;
    - the idempotency key: same type, id and purpose refused; **same type and id with null purpose twice refused** (AC-39 stand-in); `revenue` and `cogs` coexist; two `manual` rows with null `source_id` coexist (AC-41).
  - The FK set equals the 13. `entry_id` cascade deletes lines (raw).
  - The migration source calls `oneSided` and `balanced` with the right arguments (parsed), so AC-45's stand-in half holds (F-2).
  - **Seed:** one row with the exact values; running `003303` twice leaves one row; with a pre-existing row, nothing changes (AC-32, AC-78).
  - `SourceRules::inspect()` is clean, no `down()` exists, and every ULID column goes through `IdentifierColumn` (AC-80).
  - **Housekeeping:** republish the catalogue; update `tenantTreeTables()`, `MigrationTreeDisciplineTest` (+3), `TenantBaselineBuildsTest` (+13 keys), `IdempotencyKeysSchemaTest`'s top entry, the `Qa*Access*` lists and `.env.example` (+3).
  - **Mutation M8:** key on `source_purpose` instead of `source_purpose_key`, and the null-purpose row goes red.
  - *(Schema halves of AC-32, AC-39, AC-41 to AC-45, AC-75, AC-78 to AC-80.)*
- [ ] **Step 3: contract `003304`, with `JournalMigrationsTest`.**
  - **Red-first:** one fiscal year with an orphan `retained_earnings_entry_id`. The run refuses naming `fiscal_years.retained_earnings_entry_id` and `1 row(s)` and no value, and no key is added (AC-76).
  - **Clean run:** the key exists; a re-run is a no-op; on the stand-in rebuild, `fiscal_years` **and `fiscal_periods`** row counts and every pre-existing key survive.
  - **AC-77:** every T-033 catalogue row is `is_destructive = false`, and none adds `NOT NULL` to an existing column. The contract row is `contract`, and the seed row is `backfill`.
  - **AC-81:**
    - `FinanceTenantSchemaTest`'s F-14 assertion flips to presence;
    - the "six keys" count reads seven if it covers `fiscal_years`;
    - add the T-033 contract file to `QaFinanceSourceRulesTest`'s writer allow-list;
    - no other T-032 test is edited.
  - `.env.example` +1; republish the catalogue.
  - **Mutation S1:** write the key as a `->change()` and the AC-77 assertion goes red. **M17:** skip the orphan count and the red-first row goes red.
  - *(AC-75 to AC-78, AC-81.)*
- [ ] **Step 4: statics.**
  - `TenantDecimalPrecisionTest` gains the four (18,4) columns under a T-033 comment.
  - `FinanceWidthsTest` extends to `003301`/`003302`: `number` 30, `source_type`/`source_purpose`/`source_purpose_key`/`status` 30 (`ENUM`), `description` 500, `memo` 255, `payload_fingerprint` 64.
  - **Red-first:** a fixture `string('memo', 256)` is reported.
  - **Gate: `composer check` (end of chunk A).**
  - *(AC-44, AC-80.)*

### Chunk B: the pure core

- [ ] **Step 5: contracts** (Ruling 2), after T-032 Ruling 13's vocabulary sweep. **`JournalContractsTest`:**
  - every enum's exact value list (D-7's 26, in order);
  - `PostingRefused` is not constructible without a reason, and carries a `PeriodRefusal` or `AccountRefusal` unchanged;
  - `PostedEntry` has exactly the AC-4 properties (reflection);
  - `fromArray` names **every** unknown key at both levels in one throw (`id`, `number`, `status`, `period_id`, `posted_at`, `posted_by`, `reversal_of_id`, `total_debit`, `is_auto`, `project_ref`, `tax_id`, `tax_base`);
  - an omitted side becomes `'0'`, and an explicit `null` is kept;
  - no symbol imports anything outside contracts and PHP core.
  - *(AC-1, AC-4, AC-16 contract half.)*
- [ ] **Step 6: `PostingInput` / `ReversalInput`** (pure datasets).
  - **AC-6:** `Sale`, `SALE`, `' sale'`, `''`, `null`, `'unknown'`.
  - **AC-7:** missing, null, 25 or 27 characters, lower-case (accepted, folded); optional for `manual`/`opening`.
  - **AC-8:** `''`, `'-'`, `'1abc'`, `'Revenue'`, 31 characters, `"rev\n"`, a purpose without `source_id`.
  - **AC-9:** `2026-02-30`, `2026-1-1`, a date-time, `' 2026-01-01'`, `null`.
  - **AC-10 / AC-14:**
    - 500 multi-byte characters pass, 501 are refused;
    - control characters and invalid UTF-8 are refused;
    - a blank description is refused;
    - an empty or whitespace memo becomes null (F-10).
  - **AC-11 / AC-13 / AC-15:** malformed ids name `lines.{i}.{dimension}`.
  - **AC-12:** 1 and 201 lines; `lines` given as a map or a string.
  - **D-21 determinism:** two wrong fields give the first in request order; lines ascending.
  - **Reversal input:** missing date, `reason` blank (`reason_required`), 501 characters (`invalid_reason`), malformed id (`entry_not_found`).
  - **F-6:** a malformed actor gives `invalid_actor`.
  - **Mutation M1:** `^…$` instead of `\A…\z` makes the `"rev\n"` row red.
  - *(AC-6 to AC-16, AC-53 and AC-56 shape halves.)*
- [ ] **Step 7: `Amounts` and the balance rule** (pure).
  - **AC-17:** `1`, `1.0`, `true`, `null` are refused.
  - **AC-18:** every listed string, including `'٣'` and `"5\0"`.
  - **AC-19:** both sides, zero spellings `0`, `0.0`, `0.0000`.
  - **AC-20:**
    - `0.1 + 0.2` against `0.3` balances;
    - `0.0001` off is refused with both totals as strings;
    - the totals are exact.
  - **AC-21:** 200 lines at `99999999999999.9999` gives `total_out_of_range`; the exact maximum sum passes.
  - **AC-22:** `"12.5"` becomes `"12.5000"`.
  - **Mutations:**
    - **M2:** sum as `(float)`, and the `0.1+0.2` row goes red;
    - **M3:** round to scale 4 instead of refusing, and the `1.00001` row goes red.
  - *(AC-17 to AC-22 logic.)*
- [ ] **Step 8: `Fingerprint`** (pure).
  - The same payload with a different actor or clock gives an equal hash (AC-36).
  - Each AC-35 difference gives a different hash: date, branch, description, line count, **line order**, account, debit, credit, memo, each dimension.
  - `"12.5"` and `"12.5000"` hash equal; the reversal pair `[date, reason]` hashes as its own value.
  - **Mutation M7:** sort lines before hashing, and the line-order row goes red.
  - **Gate: `composer check` (end of chunk B).**
  - *(AC-35, AC-36 logic; D-23.)*

### Chunk C: posting and reversal on the database

- [ ] **Step 9: `JournalWriter` and `DatabasePostingService::post`, happy path, numbering, audit** (stand-in through `AccessTenantFixture`).
  - **AC-2:** `post`/`reverse` outside a transaction throws `LogicException`, and a query listener shows **zero** statements.
  - **AC-3:**
    - a test transaction inserts a scratch document row (`settings`), posts, then throws, and afterwards there is no document, entry, line or audit row and the sequence is unchanged;
    - a refused post inside the same shape rolls the document back too.
  - **AC-22, AC-42, AC-43:** the stored row, compared whole:
    - `posted_at` from the fixed `RunnerClock`;
    - `is_auto` false with an actor and true with null (AC-74);
    - lines 1..N in request order.
  - **AC-24:** the same account on two lines, debit and credit.
  - **AC-32:** the first numbers are `JE-000001` and `JE-000002`, from the company row even with `branch_id` set.
  - **AC-33:** after N posts, numbers 1..N with no gaps; a refused post (one per refusal family), a replay and a rolled-back caller each leave `next_number` unchanged.
  - **AC-46:**
    - a query listener sees exactly **one** `insert into journal_lines`;
    - rename `journal_lines` outside the transaction, then post: neither row exists and the sequence is unchanged.
  - **AC-65:** one audit row compared whole. **AC-66:** none for a refusal.
  - **AC-73 (service half):** a soft-deleted actor gives `invalid_actor`.
  - **Mutations:**
    - **M16:** insert lines one per statement, and the one-statement assertion goes red;
    - **M18:** drop the transaction guard, and AC-2 goes red.
  - *(AC-2, AC-3, AC-22, AC-24, AC-32, AC-33, AC-42, AC-43, AC-46, AC-65, AC-66, AC-73, AC-74.)*
- [ ] **Step 10: idempotency.**
  - **AC-34:** a replay returns an equal `PostedEntry` with `replayed=true`; a listener shows no write, no gate call and no `postable` call (spy bindings); no audit row; sequence unchanged.
  - **AC-35:** each difference gives `idempotency_conflict` naming the stored id, with the stored row untouched.
  - **AC-36:** a different actor or a later clock still replays.
  - **AC-37:** close the period (raw status update), then a replay succeeds, a conflict is still a conflict, and a fresh key is refused `period_closed`.
  - **AC-38:** after a reversal, a replay of the original returns it as `reversed` with `replayed=true`.
  - **AC-39:** two null-purpose posts give a replay or a conflict; `revenue` and `cogs` give two entries.
  - **AC-40 on the stand-in, simulated through a seam:** a spy `PeriodGate` that, on the second call, raw-inserts a competing committed-equivalent row for the same key. The service hits the unique key inside its savepoint, returns the winner as a replay (same payload) or `idempotency_conflict`, surfaces **no** `QueryException`, and **`next_number` is unchanged by the loser**.
  - **AC-41:** two `manual` posts without `source_id` give two entries.
  - **Mutations:**
    - **M4:** move the replay lookup after the gate, and AC-37 goes red;
    - **M5:** allocate the number before the replay lookup, and AC-33's replay row goes red;
    - **M6:** catch the duplicate outside the savepoint, and the AC-40 sequence assertion goes red;
    - **M15:** audit the replay, and AC-34 goes red.
  - *(AC-34 to AC-41 except `[ENGINE]`.)*
- [ ] **Step 11: the period gate and the accounts, through the service.**
  - **AC-27:** the stored `period_id` is the gate's; `entry_date` lies in that period's range (asserted against `fiscal_periods`).
  - **AC-28:** a dataset of {open, soft_closed, closed} × {plain, `post_adjustment`, null actor}, with codes unchanged.
  - **AC-29:** first day, last day, day after; a tenant with no year gives `no_fiscal_period`.
  - **AC-31:** a closed-period refusal never substitutes a date (no row).
  - **AC-23:**
    - header, inactive, soft-deleted and nonexistent accounts are each refused with the AccountRefusal code and `lines.{i}.account_id`;
    - two bad accounts report the lowest id.
  - **AC-25 (construction):** a spy `ChartOfAccounts` records call order, ascending regardless of line order, one call per **distinct** id (the NFR "one lock per distinct account").
  - **AC-26 (construction):** `postable` is the only account read (scan, step 15).
  - **Mutation M9:** iterate accounts in line order, and the spy order row goes red.
  - *(AC-23, AC-27 to AC-29, AC-31; construction halves of AC-25, AC-26, AC-30.)*
- [ ] **Step 12: `reverse`.**
  - **AC-49:** swapped sides, same accounts, dimensions, memo and `line_no`; equal totals; own number; `source_type=reversal`, `source_id` and `reversal_of_id` set to the original.
  - **AC-50:** the original is `reversed` with `reversed_by_id` set; amounts, lines, number, date and period unchanged (compared whole before and after).
  - **AC-51:** a second reversal with a different date gives `already_reversed`; an identical request replays the same reversal.
  - **AC-52:** reversing a reversal gives `not_reversible`; a raw draft gives `not_reversible`; unknown and malformed ids give `entry_not_found`.
  - **AC-53:** a date before the original gives `reversal_before_original`; the same day is accepted.
  - **AC-54:** a reversal date in a closed period is refused and the original stays `posted`; an original in a closed period is irrelevant.
  - **AC-55:** a deactivated account on the original refuses with nothing written.
  - **AC-56:** the reason is in the audit row and the description.
  - **AC-57 (stand-in):** a seam flips the original to `reversed` between the plain read and `entryForUpdate`, giving `already_reversed`. The compare-and-set `UPDATE` asserts 1 row.
  - **AC-58:** the service needs no permission.
  - **AC-67:** exactly one audit row, `after.original_id`.
  - **Mutations:**
    - **M10:** don't swap, and AC-49 goes red;
    - **M11:** allow reversing a reversal, and AC-52 goes red.
  - **Gate: `composer check` (end of chunk C).**
  - *(AC-49 to AC-58 and AC-67 except `[ENGINE]`.)*

### Chunk D: ports and handlers

- [ ] **Step 13: the three ports, rebound.**
  - **AC-59:** a line in a posted, reversed or raw-draft entry gives true; none gives false; unknown and malformed ids give false.
  - **AC-60:** the first and last day count; the day outside does not; any status counts.
  - **AC-61:** two raw drafts give `[PeriodBlocker('draft_journal_entries', 2)]`; posted and reversed give `[]`.
  - **AC-62:**
    - the container resolves the journal classes;
    - post one entry, then with real bindings: recode gives `code_locked`, delete gives `has_postings`, retype gives `type_change_refused` (condition `has_postings`), and deleting the year gives `fiscal_year_in_use`;
    - T-032's `FakeUsagePorts` tests stay green.
  - **AC-63:** the ports read only journal tables (listener).
  - **AC-64:** stand-in `EXPLAIN QUERY PLAN` for `hasPostings` names the `(account_id, entry_id)` index, with no `SCAN journal_lines`.
  - **AC-79:** post in `tenant-a`, then enter `tenant-b`: empty journal, `next_number` 1, ports false.
  - **Mutations:**
    - **M12:** `hasPostings` filtered to `posted`, and the reversed and draft rows go red;
    - **M13:** blockers count `posted`, and AC-61 goes red.
  - **Red-list rule.**
  - *(AC-59 to AC-64, AC-79.)*
- [ ] **Step 14: permissions and the four handlers.**
  - **AC-72:** the three keys are found by the registry sweep through the booted provider; there is no role seed.
  - **AC-68 to AC-71 matrix (one dataset over the four slices):**
    - without the key, `not_permitted`, and a listener shows **no** statement against any journal, account or period table;
    - a null actor and a soft-deleted employee are refused (AC-73);
    - `view` does not grant `reverse` or `post_manual`, and neither of those grants `view`.
  - **`GetJournalEntry`:**
    - by id and by source key (null purpose through the key column);
    - lines ordered by `line_no`; four-decimal strings;
    - `entry_not_found`.
  - **`ListJournalEntries`:**
    - sizes 0, 201 and `"50"` refused; default 50;
    - keyset paging over 5 entries at size 2, and a partial cursor refused;
    - each filter valid, plus a case variant refused;
    - the `account_id` filter through lines;
    - order `entry_date DESC, number DESC, id DESC`; no `lines` key.
  - **`PostManualJournalEntry`:** a body naming `source_type` gives `unknown_field`; `is_auto=false`; `posted_by` is the employee.
  - **`ReverseJournalEntry`:** the handler's own transaction; actor = employee.
  - **Mutation M14:** `Authorize` after the transaction opens, and the no-statement assertion goes red.
  - **Red-list rule.**
  - **Gate: `composer check` (end of chunk D).**
  - *(AC-68 to AC-73.)*

### Chunk E: scans, engine, documents, close-out

- [ ] **Step 15: `QaJournalSourceRulesTest` (host)**, code-only through `SourceRules::codeOnly`. **Named directories:** `packages/*/src`, `modules/*/src`, `modules/host/app`, `modules/host/database`.
  - **AC-5:** any write to `journal_entries` or `journal_lines` (`table('journal_…')` chained to `insert`, `update`, `upsert`, `delete`, `truncate`, `increment` or `decrement`, or raw `insert into` / `update` / `delete from`) outside `Finance/Journal/JournalWriter.php` and the T-033 migrations is reported.
  - **AC-48:** the only `update` in `JournalWriter` carries exactly the keys `status`, `reversed_by_id`, `updated_at`.
  - **AC-47:** no `delete` on either table anywhere; no route, command or console command in Finance.
  - **AC-63 / F-11:** `Finance/Accounts` and `Finance/Periods` name no journal table.
  - **Locks:** every port read chains `sharedLock()`. `postable` is the only account read in `Journal/` (AC-26). `JournalRows` methods not named `*Unlocked` chain a lock.
  - **No float:** `(float)`, `floatval`, `round(`, `number_format(` in `Journal/`.
  - **Regexes:** no pattern ends in `$/`.
  - **Contracts purity.**
  - **Anti-vacuity:** the scan must **find** `JournalWriter`'s own insert (it reads real source).
  - **Red-first controls:**
    - a scratch `table('journal_lines')->update(` under `packages/organization/src` is reported;
    - a scratch update key `total_debit` in `JournalWriter` is reported;
    - delete both.
  - *(AC-5, AC-47, AC-48, AC-63; construction halves of AC-25, AC-26.)*
- [ ] **Step 16: `JournalSchemaEngineTest`** (database `flexipos_t8test_t033_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep).
  - Under the hold only the "refuses to reach the engine" case runs. Every other case is written, tagged `[ENGINE]`, skipped with the reason "ENGINE_HOLD (G-1)" and reported NOT VERIFIED (AC-82).
  - Rows use explicit ids, and connection B calls the **real** service.
  - **The cases:**
    - **AC-45:** raw inserts violating each CHECK are rejected on MySQL 8.4 and MariaDB 10.4 (`information_schema.CHECK_CONSTRAINTS`).
    - **AC-39:** a null-purpose double insert is refused by the generated key.
    - **AC-40:** A posts and holds; B's same-key post waits on L3; A commits; B returns a replay; `next_number` advanced by one.
    - **AC-33a:** two different-key posts get consecutive numbers, with no duplicate error.
    - **AC-25:** opposite line orders do not deadlock.
    - **AC-26:** A holds `postable`'s `S`; B's deactivate waits; then B commits first in the reverse case and A is refused `account_inactive`.
    - **AC-30:** A posts and holds; B's period close waits; C's post after the close is refused `period_closed`.
    - **AC-57:** two reversals give one winner and one `already_reversed` or replay.
    - **Risk 3's deadlock:** a two-post caller against account delete yields a 1213 on one side and a clean retry.
    - **AC-64 / NFR:** `EXPLAIN` on `hasPostings` (type `ref`, the index) and the 10-line post transaction time.
    - **AC-80:** `information_schema` `CHAR(26)` and `ascii_bin` on every ULID column; DECIMAL(18,4).
    - Migrating twice is a no-op.
  - *(Every `[ENGINE]` clause; NOT VERIFIED under the hold.)*
- [ ] **Step 17: documents.** Describe; never quote retired vocabulary.
  - `docs/database.md` §6.10 "As built (T-033)":
    - the purpose sentinel and why `'-'` is safe here (§3.3's own requirement);
    - the fingerprint;
    - the CHECKs and their engine-only status;
    - the omitted columns (currency, `plugin_name`, `project_ref`, `tax_id`, `tax_base`) and owed keys (`department_id`, `supplier_id`);
    - the numbering through the shared allocator;
    - the lock order and the caller rule.
  - **§7:** the adjustment-permission sentence now names T-032's period key (0.1 fact 10).
  - **§12.2:** a note that the late-period rule is the caller's (F-14).
  - **§3.3:** move the journal key out of the "not yet carrying it" list.
  - `docs/architecture.md` §3: Finance now owns the journal.
  - *(Documentation of AC-75 and D-8, D-10, D-13.)*
- [ ] **Step 18: final gate.**
  - `composer check` from `modules/host`, with counts and `test` seconds compared with step 0; then `composer test:fast` once.
  - Report every `[ENGINE]` clause NOT VERIFIED.
  - *(AC-82; every criterion except the `[ENGINE]` clauses.)*

## 7. Test strategy

- **Unit (pure):** `JournalContractsTest`, `PostingInputTest`, `ReversalInputTest`, `AmountsTest`, `FingerprintTest`, `CheckConstraintTest` (kernel). They carry most cases as datasets.
- **Integration (SQLite stand-in through `AccessTenantFixture`):**
  - `PostingServicePostTest`, `PostingServiceIdempotencyTest`, `PostingServiceGateAndAccountsTest`, `PostingServiceReverseTest`, `JournalPortsTest`;
  - one file per handler;
  - `JournalTenantSchemaTest`, `JournalMigrationsTest`.
  - Every refusal asserts no entry, no line, no audit row and an unchanged `next_number`.
- **Static (host):** `QaJournalSourceRulesTest`, `FinanceWidthsTest`, `TenantDecimalPrecisionTest`, catalogue `is_destructive`, deptrac.
- **Engine:** `JournalSchemaEngineTest`, skipped under the hold.

**Mutations:** M1 to M18 and S1 (each in its step), covering:
- the anchor and newline trap;
- float and rounding;
- replay ordering;
- number-before-replay;
- the savepoint;
- fingerprint order;
- the nullable-key trap;
- the account lock order;
- the reversal swap and reversal-of-reversal;
- port scope;
- authorization order;
- replay audit;
- the one-statement insert;
- the orphan check;
- the transaction guard;
- non-destructiveness.

| ACs | Proved by |
|---|---|
| 1, 4 | step 5 |
| 2, 3 | step 9 |
| 5, 47, 48 | step 15 |
| 6 to 16 | steps 5, 6 (AC-16 at the service in step 9) |
| 17 to 22 | steps 7, 9 |
| 23, 24, 27 to 29, 31 | steps 9, 11 |
| 25, 26, 30 | steps 11, 15; `[ENGINE]` step 16 |
| 32, 33, 33b | steps 1, 2, 9 |
| 33a | step 2 (unique `number`); `[ENGINE]` step 16 |
| 34 to 41 | steps 8, 10 (AC-39 also step 2); AC-40 `[ENGINE]` step 16 |
| 42 to 46 | steps 2, 4, 9; AC-45 enforcement `[ENGINE]` (F-2) |
| 49 to 58, 67 | step 12; AC-57 `[ENGINE]` |
| 59 to 64, 79 | step 13 (AC-64 engine plan in step 16) |
| 65, 66 | steps 9, 10 |
| 68 to 74 | steps 9, 14 |
| 75 to 78, 81 | steps 2, 3 |
| 80 | steps 2, 4; `[ENGINE]` step 16 |
| 82 | steps 16, 18 |

## 8. Risks & rollback

1. **Merge preconditions (highest, blocking).** P-1 and P-2 need the human's permission to merge. Raise it now (0.3 Q1). Without T-030, Ruling 6's fallback is a second allocator, which this plan rejects.
2. **Unbuilt T-032 surface.** Section 0.4 is assumed from T-032's plan. Step 0 verifies each row and stops on a difference. T-032's guards stay inert in production until step 13 lands (accepted, as T-032 risk 6).
3. **Engine deadlock** for a caller that posts twice in one transaction against a concurrent account admin (Ruling 8). Data stays correct, and one side retries. It is exercised in the engine test, with a follow-up for a combined call in FP-262.
4. **Stand-in divergence.**
   - SQLite ignores `lockForUpdate`/`sharedLock`, enforces no CHECK, and stores DECIMAL as REAL (15 significant digits).
   - **Mitigations:**
     - lock order is proved by construction (spies and the source scan);
     - CHECKs are proved by statement text;
     - no stand-in test reads back an amount above 15 significant digits;
     - a fresh `PostedEntry` is never re-read;
     - every real proof is `[ENGINE]`, NOT VERIFIED.
5. **The hottest lock.** The company-wide `journal_entry` row serialises every posting until the caller commits. At 1 000 sales a day this is acceptable (spec NFR), but it is unmeasured under the hold. A per-branch sequence is additive later (D-8).
6. **Year rollover halts posting** (T-032 risk 5). The gate fails closed with no next year. FP-262 must surface it.
7. **SQLite rebuild of `fiscal_years`** (a CASCADE parent) in the contract file. Laravel turns `foreign_keys` off for the rebuild, and step 3 asserts the period rows survive.
8. **Cross-task edit** to T-030's `DEFAULT_PREFIXES` (lead Q2).

**Rollback:** every file is new except section 9's edits. The migrations are forward-only and have run on no tenant outside this branch, so rollback is to abandon the branch. The ports rebinding (step 13) can be reverted alone, which restores the `No*` defaults.

## 9. Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `docs/backlog.md` | merge resolution only | 0 |
| `packages/finance/composer.json`, `modules/host/composer.lock` | brick/math require; the path package entry only | 1 |
| `packages/finance/src/Support/Limits.php` | new constants | 1 |
| `packages/kernel/src/Schema/Support/CheckConstraint.php`, `packages/kernel/tests/Schema/Support/CheckConstraintTest.php` | `balanced()` | 1 |
| `packages/organization/src/Numbering/DatabaseDocumentNumbers.php`, `packages/organization/tests/Numbering/DocumentNumbersTest.php` | `journal_entry` prefix (and any assertion that pins the prefix map) | 1 |
| `database/catalogue/tenant_schema_versions.json`; `SchemaCataloguePublishTest` | republished | 2, 3 |
| `.env.example` | +3, then +1 | 2, 3 |
| `tests/Helpers.php` `tenantTreeTables()` | two names under a T-033 comment | 2 |
| `tests/Feature/MigrationTreeDisciplineTest.php` | +4 | 2, 3 |
| `tests/Feature/TenantBaselineBuildsTest.php` | FK inventory +13, then +1 | 2, 3 |
| `tests/Feature/IdempotencyKeysSchemaTest.php` | hard-coded catalogue top entry (the T-032 chunk-A miss) | 2, 3 |
| `tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | `$t033` lists | 2 |
| any test asserting an empty or fully counted `number_sequences` after a whole-tree build | +1 row (found at step 0) | 2 |
| `packages/finance/tests/Schema/FinanceTenantSchemaTest.php` | F-14 assertion to presence; key count (AC-81) | 3 |
| `modules/host/tests/Feature/QaFinanceSourceRulesTest.php` | allow-list gains `003304` (it names `fiscal_years`) | 3 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | four columns | 4 |
| `packages/finance/tests/Schema/FinanceWidthsTest.php` | extended to `003301`/`003302` | 4 |
| `packages/finance/src/FinanceServiceProvider.php`, `Registry/{FinancePermissions,FinanceAuditActions}.php` | bindings, keys, actions | 9, 13, 14 |
| `packages/access/tests/Support/AuditCatalogueTest.php`, access permission-count tests | +2 actions, +3 keys, if they count | 9, 14 |
| `docs/database.md`, `docs/architecture.md` | step 17 | 17 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **New row:** an `ActorType` parameter on `AuditTrail::record`, so system and queued writers are recorded as `system` (F-4).
- **New row:** the nightly journal integrity re-check (D-20; spec owner question 4).
- **FP-262:**
  - call `post` last, after its own number (Ruling 8);
  - consider one combined call for revenue and cogs (risk 3);
  - own the late-period rule (D-13, F-14);
  - add `journal_entry_id` on documents;
  - surface the year-rollover refusal.
- **Owed keys:** `journal_lines.department_id` (HR task) and `journal_lines.supplier_id` (T-034/FP-265), each as a contract-phase key (F-13).
- **FP-268:** `account_balances` when a measured trial-balance query needs it (D-11).
- **Roles task:** grant the three journal keys.
- **FP-269 / API:** routes with `RequireTenantPermission` for the four handlers.
- **Product owner:** review the seeded chart before the first real posting (spec owner question 5). Per-branch or per-year numbering (owner question 1) is additive.
- **`ba`:** F-1, F-2, F-3, F-4, F-5, F-6 and F-7 need a spec or decision edit. F-9 and F-10 need a confirmation.

## Questions for the human

1. **Permission to merge** T-032 (when DONE) and T-030 at `c354a0e` into `ai/T-033-journal-entries` before any code (0.3 Q1). No step past step 0 can run without it.
