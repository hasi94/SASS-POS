# Plan: T-032 Chart of accounts and fiscal periods, with period closing

Spec: `docs/specs/T-032-chart-of-accounts.md` (AC-1 to AC-66, sections A to J). Decisions: `docs/decisions/T-032-chart-of-accounts.md` (D-1 to D-19).
Design authority: `docs/database.md` §6.10 (`accounts`, `fiscal_years`, `fiscal_periods`), §6.3 and §6.9 (the owed columns), §3.1/§3.2; `docs/architecture.md` §3 (as-built placement rule) and §10; `docs/patterns.md` §3 to §6. Carried-over precedents: T-027 plan Rulings 1, 11 to 14; T-028 plan Rulings 1, 6, 10 to 13; T-014 D-31 (one contract file); T-028 review R-1 and W-1 to W-6; T-029 plan (concurrent, `ai/T-029-taxes-pricing` at `0a6cbb5`).
Backlog: FP-260. Branch: `ai/T-032-chart-of-accounts`. Gate directory: `modules/host`.

Everything about shipped code below was measured read-only in `.claude/worktrees/build-56f213` (branch `ai/T-028-customers`, tip `20ad316`, T-028 DONE), because this branch's own tree does not contain it (F-1).

**Revision 2.** `ba` folded F-1 to F-19 into spec revision 2 and decisions D-20 to D-29 in `e5c99bb`. This revision was reconciled against that commit's diff, not a summary of it. Section 0.2 below is now the record of what was routed. Its resolutions are the spec's own text, and every "suggested" or "may prefer" alternative in it is **void**.

| Finding | Now in the spec as | Decision |
|---|---|---|
| F-1 | "Current behaviour", Assumption 1, section I preamble | D-20 |
| F-2, F-3 | **AC-58**, **AC-60** (three permitted kinds of test edit) | D-29 |
| F-4, F-5 | Merge order, section I preamble, AC-52, forward dependencies | D-22 |
| F-6 | **AC-52** (three expand files), **AC-53** (one contract file), **AC-54** (eight migrations) | D-21 |
| F-7, F-8, F-12 | Assumptions 2 and 3, **AC-8** (`not_updatable_here`), **AC-18** (enforced through whichever operation applies), **AC-64** (**seven** keys: `finance.periods.view` added) | D-24 |
| F-9, F-11 | **AC-10** (`parent_inactive` and the full check order, which also applies to re-parent), **AC-25** (cites AC-37 and AC-41; reason codes `has_postings` / `code_locked` / `control_type_locked`) | D-25 (all 14 proposed codes adopted as-is) |
| F-10 | **AC-26** (subtypes) | D-23 |
| F-13, F-14, F-17, F-18, F-19 | AC-47, AC-65, forward dependencies | D-28 |
| F-15 | **AC-30**, AC-65 | D-27 |
| F-16 | **AC-4** | D-26 |

**What changed in this plan between revisions.** Only one design change: the **seventh permission key** (D-24) moves the year, period and date-lookup reads from `finance.accounts.view` to `finance.periods.view`. Rulings 5 and 9, step 8, sections 4 and 10, and the AC-64 matrix are updated for it. Everything else changes only in its references:
- step 18 and section 9 now carry AC-60's third kind of test edit and AC-58's merged-customer clause;
- FP-428 is cited as the backlog row step 18 partly closes;
- three new findings, N-1 to N-3, are listed in section 0.2a.

The step count is unchanged at 23 (steps 0 to 22, six chunks).

---

## 0. Verification, findings and rulings

### 0.1 What the branch actually contains (measured)

| Claim | Verdict | Evidence |
|---|---|---|
| Spec Assumption 1 / D-19: "the base stops at T-013, so `products`, `categories`, `customers` are missing; sections A to H and J are independent" | **False, and much worse (F-1)** | `main` = `4ece505` holds only T-001 to T-008 **docs/early code** plus T-013. This worktree has no `modules/host/database/migrations-tenant/` directory at all, no `packages/kernel/src/Schema`, and `packages/` holds only `auth`, `contracts`, `eventbus`, `http-client`, `kernel`, `plugin-sdk`, `testing`. T-009 to T-028 (employees, permissions, `audit_logs` writer, the per-tenant runner, provisioning, catalog, customers) all live only on the `ai/T-028-customers` lineage (`git log ai/T-028-customers` names T-009 … T-028; `git log main` names T-001 … T-008, T-013) |
| `ai/T-028-customers` is the right base | **Holds** | Tip `20ad316` "T-028: DONE". Its merge-base with this branch is `e1c4602`. This branch adds exactly one commit, `262a2bf` (spec, decisions, one backlog row). T-013 (`4ece505`) touches `print-agent/`, `README.md` and one backlog line only |
| `products`, `categories`, `customers` ship without the account columns | **Holds** | `…002702_create_categories_table.php` and `…002704_create_products_table.php` docblocks defer them to FP-260; `…002802_create_customers_table.php` likewise |
| T-027/T-028 suites "still pass unchanged" after the columns land (AC-60) | **False (F-2)** | `packages/catalog/tests/Schema/CatalogTenantSchemaTest.php:163-165` and `packages/customers/tests/Schema/CustomersTenantSchemaTest.php:143` assert the columns' **absence**; both apply the whole tenant tree. `CreateProductTest`, `CreateCustomerTest` (and the seeds) compare whole rows |
| AC-58: "a soft-deleted product, category or customer … is refused as it already is for every other field" | **False for products and categories (F-3)** | `UpdateProductHandler.php:65` and `UpdateCategoryHandler.php:88` read the target `lockForUpdate()` **without** `whereNull('deleted_at')`. Only `UpdateCustomerHandler.php:62` filters it (T-028 W-5 fix) |
| Permissions are enforced per route | **Only as a mechanism (F-8)** | `RequireTenantPermission` exists, but `routes/api.php` registers only `openapi.json`; no package ships tenant routes. No handler anywhere calls `PermissionResolver` (grep over `packages/*/src`, `modules/*/src`, `modules/host/app`) |
| Key spelling convention | **Two segments everywhere so far** | 15 shipped keys (`employees.view`, `branches.manage`, `audit.read`, …). `PermissionDeclaration::KEY_PATTERN` = `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`, max 80, so three segments are legal |
| Audit | **Holds** | `Contracts\Audit\AuditTrail::record(module, ?actorId, action, subjectType, subjectId, ?before, ?after, ?branchId)`, writes in the caller's transaction. `AuditCatalogueTest:48` hard-codes `['access', 'catalog', 'customers', 'organization']` and `:92` the same plus `fixture` |
| Tenant migrations may import package code | **No** | `SourceRules::MIGRATION_IMPORT_NAMESPACES` = `Illuminate\`, `Flexipos\Kernel\Schema\Support\` only |
| A shipped tenant migration already writes data | **No** | No file under `migrations-tenant/` inserts rows; the only non-expand files are the two `@phase contract` key files. T-029 *plans* the first backfill (its migration 07), not shipped |
| Planner and contract steps | **Holds** | `PlanMigrationRunHandler` AC-58 guard: a contract step is planned only once every non-provisioning tenant is at the version before it. `2026_10_22_000506_add_branch_foreign_keys.php` is the shape: every data-clean count first, message names table, column and count only, skip a key already present, no `down()` |
| Gate baseline | **Not re-measured here** | T-028 close (`20ad316`): `composer check` 13 failed (pre-existing) / 3483 passed / 38 skipped; `test` step about 1638 s. Step 0 measures it on the merged branch |

### 0.2 Findings routed to `ba` (numbered; S = serious): all resolved in `e5c99bb`

**These needed a spec or decision amendment** (the resolutions are now D-20 to D-29; see the mapping table at the top):

- **F-1 (S, blocking, corrects Assumption 1 and D-19).** No section of this spec can be built on the current base: A to H and J need the tenant migration tree, the runner, `employees`, `permissions`, `PermissionResolver`, `AuditTrail` and provisioning, none of which exist here. **Resolution:** Step 0 brings `ai/T-028-customers` @ `20ad316` into this branch before any code. Preferred: `git merge 20ad316` (no history rewrite, keeps T-013; the only expected conflict is `docs/backlog.md`). Alternative: `git rebase --onto 20ad316 4ece505` (moves `262a2bf` alone; T-013 then returns when the chain reaches `main`). Git merge/checkout needs the human's permission on this machine, so `lead` must ask **at task start**. Once merged, T-027 and T-028 are present, so section I is buildable in the same pass. It still sits in a late chunk (E), as asked.
- **F-2 (S, AC-60).** "Still pass unchanged" cannot hold. **Resolution:** AC-60 should read "still pass; the only edits are the assertions that pinned the five columns' absence, which now pin their presence, and the whole-row expectations that gain the five keys". Step 17/18 list each edited test in section 9.
- **F-3 (S, AC-58).** Products and categories accept edits to soft-deleted rows today, for every field. **Resolution (safer reading):** Step 18 adds `whereNull('deleted_at')` to both update reads and refuses `notFound` (catalog's existing reason), for **every** field, not only the account fields. That is a T-027 behaviour change; it closes the T-028 W-5 class in catalog. AC-58 should drop "as it already is".
- **F-4 (S, cross-task conflict with T-029).** T-029's plan step 4, AC-29.3, adds a static test that "no tenant migration names `accounts` in a key, and none declares `payable_account_id`, `receivable_account_id` or `sales_discount_account_id`". T-032's AC-52/AC-53 add exactly `customers.receivable_account_id` and five keys onto `accounts`. Whichever task merges second turns the other red. **Resolution:** AC-29.3 is narrowed to T-029's own files and tables (`tax_authorities`, `discounts`). If T-029 merges first, step 17 makes that narrowing (a test edit, never a migration edit); if T-032 merges first, T-029 must. `lead` routes this to both owners.
- **F-5 (S, scope gap).** T-029's F-17/section 10 routes three more owed columns to FP-260: `tax_authorities.payable_account_id` (expand, backfill, contract **NOT NULL**), `tax_authorities.receivable_account_id`, `discounts.sales_discount_account_id`. D-1/D-2 do not mention them. **Resolution:** this task builds the five only. The three tables do not exist on this branch unless T-029 merges first, and the `NOT NULL` tighten is destructive under today's classifier (T-029 F-3), which D-10 blocks. `lead` needs a backlog row (or a T-032 follow-up after T-029 lands). AC-52's "fails on a sixth" counter is scoped to `products`, `categories` and `customers`, so it does not go red when those three arrive.
- **F-6 (S, operational, D-2/AC-53/AC-54).** "An expand and a separate contract migration per pair" means five contract files. Under the AC-58 planner guard, five consecutive contract files need five fleet runs (T-014 D-31). **Resolution:** three expand files (one per table) and **one** contract file holding all five keys, ordered after all three. AC-53's "separate migration, ordered after its expand migration" holds. AC-54's "the ten migrations" becomes "every T-032 migration (eight)".
- **F-7 (Assumption 2, placement).** "Finance is a new module package booted from `MODULES`" contradicts `docs/architecture.md` §3's as-built rule, and it would be unsafe: catalog and customers resolve `ChartOfAccounts` inside their own update handlers (section I), so with `MODULES` lacking `finance` every product update would fail on an unbound interface. **Ruling 1** places it in `packages/finance`, registered in `bootstrap/providers.php`.
- **F-8 (AC-64, mechanism and spelling).** No route layer exists to hang the keys on. **Resolution:** authorization is checked inside every Finance handler, first, through the published `PermissionResolver`, before any transaction opens and before any read of the target (Ruling 5). This is the codebase's first handler-level check. A handler refusal writes no `access_log` row (that is the HTTP layer's record; the route task adds it). **Spelling:** the six keys are kept **verbatim** from AC-64: `finance.accounts.view`, `finance.accounts.manage`, `finance.periods.manage`, `finance.periods.close`, `finance.periods.reopen`, `finance.periods.post_adjustment`. They satisfy `KEY_PATTERN` and the 80-character limit. The `finance.` namespace is deliberate: FP-261 to FP-268 add journals, AR, AP and bank keys under one module, and a bare `accounts.*` would sit next to FP-263's `financial_accounts`. The keys are the first three-segment keys. **Resolved by D-24: `ba` added a seventh key, `finance.periods.view`, for the year, period and date-lookup reads.** The plan now declares seven.
- **F-9 (AC-25 text).** AC-25 lists AC-44 among the rules a bound fake must make refuse. AC-44 is the lock order. The port-driven refusals are AC-37 (`FiscalYearUsage`) and AC-41 (`PeriodCloseBlockers`). The plan tests AC-14, 15, 19, 22, 23, 37 and 41 with fakes.
- **F-10 (D-6 underspecified).** D-6 fixes parents, types and control types but not the subtype of the non-control rows. The plan's seed table (section 4) picks: headers null; 2200 `long_term_liability`; 4100 `sales`; 5000 `cogs`; 6100 and 6300 `operating_expense`; 6200 `payroll_expense`. 2400 and 2500 are `current_liability` and 1100 to 1400, 2100, 2300 and 3100 take the subtype AC-16 requires. The product owner should review before FP-261 (D-6 already says system codes freeze once data posts).
- **F-11 (AC-10 against AC-24).** AC-10 lets an active child be created under an inactive header, while AC-24 refuses to reactivate a child under one. **Resolution (safer):** create and re-parent also refuse an inactive parent, `parent_inactive`. Plan-added reason codes, for `ba` to adopt or rename: `parent_inactive`, `already_inactive`, `already_active`, `has_postings` (AC-14/15/19 "has postings" where AC-22 says `code_locked`), `control_type_locked` (AC-19), `not_updatable_here` (F-12), `period_not_found`, `fiscal_year_not_found`, `fiscal_year_not_open`, `later_year_not_open`, `name_in_use`, `not_permitted`, `page_size_invalid`, `cursor_invalid`.
- **F-12 (AC-18 wording).** `UpdateAccount` does not accept `parent_id` or `is_active`; those go through `ReparentAccount`, `DeactivateAccount` and `ReactivateAccount`, so each rule has one door. An update body naming either is refused `not_updatable_here`. AC-18's system immutability of the parent and of activation is proven through those slices (`system_account_immutable`). This needs no change to AC-18's intent, only its phrase "when anyone updates it".

**Informational (the plan applies these; `ba` may confirm):**

- **F-13 (AC-61).** The codebase clamps page sizes (`Kernel\Audit\PageSize::clamp`, max 100, T-028 Ruling 11). AC-61 refuses out-of-range sizes (1 to 200). The plan follows the spec for Finance lists.
- **F-14.** `fiscal_years.retained_earnings_entry_id` is built `CHAR(26) NULL` with **no** key, because `journal_entries` does not exist. FP-261 owes its contract key, along with `journal_entries.period_id`.
- **F-15 (seed mechanism).** Provisioning's `migrate_remainder` runs every tenant migration, so **one** `@phase backfill` migration seeds new tenants (at provisioning) and existing tenants (at their next run). That is one code path for AC-26 and AC-30, with no new provisioning step. Its consequences:
  - migrations cannot import Finance, so the seed rows live as a `public const` in the migration file (frozen with its checksum), and AC-29's test reads that constant;
  - the seed writes no audit rows, because a migration cannot reach `AuditTrail`;
  - "the current calendar year" is the runner's UTC clock year at migration time.
- **F-16.** Names (account, year, period) are trimmed before the length and blank checks (codebase convention). That is also what makes year-name uniqueness engine-independent, given pad-space collations. Codes are **never** trimmed (AC-2).
- **F-17.** The gate does not check "posting rights" (AC-47). It answers only the period question; FP-261 owns who may post at all.
- **F-18.** §6.10 gives `fiscal_periods` no `[TS]`, so a period rename records no `updated_at`; the audit row is the record.
- **F-19 (forward risk for FP-261).** Posting is asynchronous through the outbox (`architecture.md` §10). A document dated in a period that closes before its posting runs will be refused by the gate. FP-261 must decide what happens then (park and alert, or post to the next open period by rule). That is not this task's to build, but it is the gate's direct consequence.

### 0.2a New findings against revision 2 (N-1 to N-3)

- **N-1 (wording conflict, AC-15 against AC-25; not blocking).** AC-15 refuses a type change `type_change_refused` "naming the failing condition". Revised AC-25 says the postings refusals "use reason `has_postings`, except AC-22 `code_locked` and AC-19 `control_type_locked`", and AC-15 is in AC-25's list. Read literally, a retype of an account with postings must carry two different reason codes.
  - **Plan (the safer reading, because it keeps AC-15's explicit code):** the reason is `type_change_refused`, and its `condition` field is `has_postings` (or `is_system`, `has_children`, `in_use`, `parent_type_mismatch`).
  - AC-14 and AC-23 use `has_postings` as the reason itself.
  - `ba` should add "AC-15 `type_change_refused`" to AC-25's exception list.
- **N-2 (scope note, FP-428).** Backlog FP-428 (T-027 review round 1) covers **every** catalog update handler that skips `whereNull('deleted_at')`, not only products and categories. AC-58/D-29 fix products and categories only. Step 18 does exactly that, and FP-428 stays open for the remaining catalog update handlers (brands, units, barcodes and the rest). `lead` should annotate FP-428 rather than close it.
- **N-3 (informational, AC-58 merged customers).** Revised AC-58 adds "or, for a customer, merged away". `MergeCustomersHandler` soft-deletes the source (T-028 Ruling 9 step 7), so `UpdateCustomerHandler`'s existing `whereNull('deleted_at')` already refuses a merged customer. No code change is needed. Step 18 adds one regression case: an account-field update on a merged-away source is refused.

### 0.3 The T-028 review classes, ruled out by construction

| Class (T-028 review) | How this plan makes it impossible, and which test proves it |
|---|---|
| **R-1 replayed-write collision** | There are no free-text replay keys anywhere in Finance: ids are server ULIDs (Assumption 7). Each replay has its own outcome. A replayed transition carries `expected_status` (AC-39) and gets `status_changed` or `invalid_transition`. A replayed create gets `code_in_use` or `fiscal_year_not_contiguous`. A replayed delete gets `account_not_live` and writes no second audit row. The seed is idempotent per code. No handler ignores a "replayed" outcome, because none exists. Steps 9, 11, 13, 14 and 16 |
| **W-4 unlocked read-then-write** | Every row a decision rests on is read under a lock inside the write transaction (Ruling 6's lock table): the target `FOR UPDATE`; parents and assigned accounts with a shared lock; children counts and referrer counts as **locking** reads; periods and years `FOR UPDATE` in one global order. The **construction** is that write handlers never call a terminal read (`first`, `get`, `exists`, `count`, `value`, `pluck`) on a builder themselves. They call `Accounts\AccountRows` / `Periods\PeriodRows` methods whose names state the lock mode. `QaFinanceSourceRulesTest` (step 19) enforces both halves |
| **W-1 stale-snapshot re-read** | Under REPEATABLE READ, only plain reads see the snapshot, and locking reads see the latest committed row. The construction is: (a) authorization (the only plain reads a write path makes, inside `PermissionResolver`) runs **before** the transaction opens; (b) every read inside the transaction is a locking read (above), including the re-read after a `UniqueConstraintViolationException`; (c) the one deliberate unlocked read (a period's immutable coordinates, Ruling 7) is of columns no request can change (AC-36). Step 20 holds the two-connection proofs |
| **W-5 dead-record edits** | Every Finance write reads its target through `AccountRows::liveForUpdate()` (`deleted_at IS NULL`, AC-20). Years and periods have no soft delete. Catalog's two dead-record holes are closed in step 18 (F-3) |
| **W-6 / FP-436 unvalidated widths and rollover dates** | No decimal input exists (D-9 refuses `opening_balance`). Dates must match `\A\d{4}-\d{2}-\d{2}\z` **and** pass `checkdate()`, so `2026-02-30` is refused, and they must fall in 2000 to 2100 (AC-32). Integers are `is_int` and range-checked; strings are `mb_strlen`-checked against named constants in `Support\Limits`. `FinanceWidthsTest` (step 3) asserts every Finance `VARCHAR` width equals its `Limits` constant, so a validator and its column cannot drift |
| **FP-435 case-insensitive collation bypass of reserved values** | Enum values are compared `in_array($v, $values, true)` on strings only, and are never lower-cased or trimmed (AC-5). Codes are upper-cased on write and refuse every whitespace and non-ASCII byte, so the stored form is canonical and the unique index is a true backstop on every collation (AC-3). Regexes use `\A…\z`, never `^…$`, because `$` accepts a trailing newline. Single-holder control types have a generated-column backstop whose `IN` list compares case-insensitively on the engine, which is the conservative direction. Year names are compared `lower(trim())` with a unique index behind them. `is_system` and the `closing` status cannot be reached by any input. Steps 5, 9, 13 and 20 |

### 0.4 Rulings

**Ruling 1: placement.** A new path package `packages/finance` (`flexipos/finance`, namespace `Flexipos\Finance\`), registered in `bootstrap/providers.php` after `CustomersServiceProvider`. Its contracts go in `packages/contracts/src/Finance/`. Catalog and customers reach it only through those contracts (AC-51, AC-60). Its `Support/` helpers are another copy of the per-package set (FP-433), because deptrac forbids importing another package's. `Fields` is **not** copied, because the shipped copies trim and have the FP-436 rollover defect. Finance writes its own strict parser (`Support\Input`).

**Ruling 2: the published contract** (`packages/contracts/src/Finance/`, plain PHP, no vendor imports):

| Symbol | Shape |
|---|---|
| `AccountType`, `AccountSubtype`, `NormalBalance`, `ControlType`, `PeriodStatus`, `FiscalYearStatus`, `AccountKind` | backed enums. `AccountType::normalBalance()`, `AccountType::subtypes()`, `ControlType::isSingle()` (D-3) and `ControlType::requires()` (AC-16) live here, so the rule has one home. `AccountKind` = `income`, `cogs`, `inventory`, `receivable` with `accepts(AccountSnapshot)` (AC-56). `FiscalYearStatus` includes `closing` for the column, and no code path enters it |
| `AccountSnapshot` | readonly: `id`, `code`, `name`, `AccountType $type`, `?AccountSubtype $subtype`, `NormalBalance $normalBalance`, `bool $isPostable`, `bool $isActive`, `ControlType $controlType` (AC-63) |
| `ChartOfAccounts` | `byId(string $id): AccountSnapshot`; `byCode(string $code): AccountSnapshot`; `byControlType(ControlType $t): list<AccountSnapshot>` (live only); `postable(string $id): AccountSnapshot` (refuses non-live, inactive, non-postable; shared lock when a transaction is open); `assignable(string $field, string $id, AccountKind $kind): AccountSnapshot` (section I; shared lock, **must** be called inside the caller's transaction, else `LogicException`). Throws `AccountRefused` |
| `AccountRefused` / `AccountRefusal` | `RuntimeException` with `public readonly AccountRefusal $reason`, `?string $field`. Reasons: `account_not_found`, `account_not_live`, `account_inactive`, `account_not_postable`, `account_kind_mismatch`, `invalid_code` |
| `PeriodGate` | `clear(mixed $date, ?string $actorId): PeriodClearance`. Must run inside the caller's open transaction (`LogicException` otherwise). Takes a shared lock on the period row. Throws `PeriodGateRefused` |
| `PeriodClearance` | readonly `periodId`, `PeriodStatus $status` (AC-51: no model class) |
| `PeriodGateRefused` / `PeriodRefusal` | `invalid_date`, `no_fiscal_period`, `period_closed`, `period_soft_closed` |
| `AccountUsage` | `hasPostings(string $accountId): bool`. Default `NoAccountUsage` (false); FP-261 rebinds it |
| `FiscalYearUsage` | `hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool`. Default false |
| `PeriodCloseBlockers` / `PeriodBlocker` | `blockersFor(string $periodId, string $startDate, string $endDate): list<PeriodBlocker>`; `PeriodBlocker` readonly `kind`, `count`. Default `[]` |
| `AccountReferrers` / `AccountReferrer` / `DeclaresAccountReferrers` | `referrersOf(string $accountId): list<AccountReferrer>` where `AccountReferrer` is readonly `kind` (e.g. `products.income_account_id`), `live`, `deleted` (ints). The docblock says implementations **must** count with a locking read (`sharedLock()`). `DeclaresAccountReferrers` is a provider interface returning `list<class-string<AccountReferrers>>`. Finance's `Accounts\ReferrerCensus` sweeps `$app->getProviders(DeclaresAccountReferrers::class)`, the `AuditCatalogue`/`PermissionRegistry` precedent |

That is 22 files. Nothing else is published.

**Ruling 3: input parsing** (`Support\Input`, pure; AC-2, 4, 5, 8, 9, 32, 34, 42).
- `code(mixed)`: must be a string. Any byte outside printable ASCII, or any whitespace anywhere, is refused **before** case folding. Then `strtoupper` (ASCII-only on PHP 8.2), then `/\A[0-9A-Z][0-9A-Z.\-]{0,19}\z/`.
- `text(mixed, int $max, bool $required)`: must be a string and valid UTF-8 (`preg_match('//u')`). Refused if it contains `\p{Cc}`. Trimmed (F-16); blank refuses a required field. `mb_strlen` must be ≤ max; longer is refused, never truncated.
- `enum(mixed, class-string<BackedEnum>)`: must be a string, matched with `tryFrom` (case-sensitive). No trim, no lower-casing.
- `flag(mixed)`: must be `is_bool`.
- `smallUnsigned(mixed)`: must be `is_int`, 0 to 65535.
- `date(mixed)`: must be a string matching `/\A\d{4}-\d{2}-\d{2}\z/`, then `checkdate`. The year range check is the caller's (AC-32).
- `id(mixed, string $field)`: must be a string of exactly 26 characters that is a ULID, upper-cased.
- `Fields::only(array, allowed, forbidden)`: names **every** unknown key and **every** forbidden key in one refusal (AC-8, D-10). The forbidden set is `id`, `is_system`, `path`, `depth`, `deleted_at`, `created_by`, `updated_by`, `opening_balance`, `opening_balance_date`, plus `currency` when non-null.

**Ruling 4: the account rules** (`Accounts\AccountRules`, pure, operating on snapshots; AC-6, 7, 10, 11, 13 to 19).
- `derive(type, ?normalBalance)` gives the derived balance, or `normal_balance_mismatch`.
- `subtype(type, ?subtype)` gives `subtype_mismatch`.
- `control(controlType, type, subtype, isPostable)`: AC-16's table from `ControlType::requires()`; a control account must be postable.
- `parent(?parentSnapshot, childType)`, in this fixed order: `parent_not_found`, `parent_not_live`, `parent_inactive` (F-11), `parent_not_header`, `parent_type_mismatch`, `depth_exceeded` (`parent.depth ≥ 9`).
- `path(parentPath, id)`, depth; a guard asserts length ≤ 500 (the depth limit makes it at most 269).
- `move(account, newParent, deepestDescendantDepth)`: `cycle` when the new parent's path equals the account's path or starts with `account.path + '/'`; the depth limit is checked against the deepest descendant (AC-13).
- `retype`, `recode`, `recontrol`, `toPostable`, `toHeader`: decision functions over `{isSystem, hasChildren, hasPostings, hasReferrers, parentType}`. Each returns the **first** failing condition by name, in a fixed order, so a test can pin it (AC-14, 15, 18, 19, 22).

`AccountInput::forCreate(array)` and `forUpdate(array)` compose Rulings 3 and 4's field-level checks into one validated draft. The seed test (AC-29) uses exactly these.

**Ruling 5: authorization** (AC-64). `Support\Authorize::require(?string $actorId, string $key)` is the **first statement** of every Finance handler. A null actor is `not_permitted`. Otherwise it calls `PermissionResolver::decide($actorId, $key, null)` and refuses `not_permitted` naming the key, but never the resolver's finer cause (the T-010 D-26 precedent, not this task's D-26). It runs before any transaction opens (Ruling 6). The **seven** keys (AC-64, D-24) live as constants in `Registry\FinancePermissions` (module `finance`, groups `accounts` and `periods`, all `isBranchScoped = false`):
- `finance.accounts.view`
- `finance.accounts.manage`
- `finance.periods.view`
- `finance.periods.manage`
- `finance.periods.close`
- `finance.periods.reopen`
- `finance.periods.post_adjustment` The provider implements `DeclaresPermissions`, so `seed_core`'s `seedPermissions()` registers them at provisioning. Whether the owner role holds new keys automatically is verified at step 0. If it does not, nobody can use Finance after provisioning, and that is a follow-up row for the roles task (Assumption 3), not built here.

**Ruling 6: transactions and the lock table.** Every write handler runs `Authorize`, then pure parsing (rejections before locks), then `$connection->transaction(fn, attempts: 2)`. The second attempt exists only for a deadlock victim, which re-runs and then gets a named refusal.

| Operation | Locks, in this order | Then |
|---|---|---|
| Create account | parent `sharedLock` (if any) | `control_account_exists` pre-check as a locking read; insert; unique and generated-marker backstops caught and mapped to `code_in_use` / `control_account_exists`, the holder re-read **with a lock** |
| Update account | target `liveForUpdate`; children count `sharedLock`; referrers (ports, locking) | rules; update; audit |
| Re-parent | **every** `accounts` row `FOR UPDATE` in id order (whole-chart lock, the T-027 `product_components` precedent; re-parent is rare and the chart is at most a few thousand rows) | cycle and depth on the locked values; rewrite the account and every descendant's `path`/`depth` in PHP; update each |
| Delete / deactivate / reactivate | target `liveForUpdate`; parent `sharedLock` (reactivate); children `sharedLock`; referrers | rules; soft delete or flag; audit |
| Create fiscal year | latest year `FOR UPDATE` (if any) | contiguity; insert year and 12 periods; **post-insert** locking read of the whole year chain, which must be contiguous, else `fiscal_year_not_contiguous`; `unique(fiscal_years.start_date)` and `unique(fiscal_periods.start_date)` backstops. Two overlapping years always share a period start date, because every period starts on the first of a month, so the period index is a complete overlap backstop |
| Period transition | period immutable coordinates (unlocked, Ruling 7); its year `FOR UPDATE`; periods in `[prev.start, next.start]` `FOR UPDATE`, ascending `start_date` | AC-38, 40, 41, 46 on the locked rows |
| Year close / reopen / delete | the year (and, for reopen, every later year) `FOR UPDATE`, ascending; then its periods `FOR UPDATE`, ascending | AC-37, 45, 46 |
| Gate | period `sharedLock` only | AC-47 |
| `assignable()` (section I) | the account `sharedLock`, inside the catalog/customers transaction, **only** when the field's value changes to a non-null id | AC-55 to 57 |

**Global lock order:** `fiscal_years` before `fiscal_periods`, each ascending by `start_date`. Every Finance transaction acquires in that order, so Finance transitions cannot deadlock each other. The gate takes one shared lock at the tail. Account locks never interleave with period locks. The known engine deadlocks are re-parent against a concurrent child create, and account delete against a product update that already references the account. MySQL rolls one back (1213): data stays correct and the retry names the refusal. They are recorded in risk 3 and exercised in step 20.

**Ruling 7: the period state machine** (`Periods\PeriodTransitions`, pure; AC-38 to 43).
- `transition(PeriodStatus $from, PeriodStatus $to)`: only the four adjacent moves are allowed; anything else, including same to same, is `invalid_transition`.
- `checkExpected($stored, $expected)`: a mismatch is `status_changed`, naming the stored status.
- `checkOrder(?prev, $target, ?next, $newStatus)`: ranks open 0, soft_closed 1, closed 2; it requires `rank(prev) ≥ new ≥ rank(next)`, else `period_order_violation` naming the neighbour. Checking only the immediate neighbours suffices because the invariant held before the move.
- A reopen needs a reason (1 to 500 characters, not blank, else `reason_required`).
- `closedAt`/`closedBy` are set on a move into soft_closed or closed and cleared on a move to open (AC-43).
- The handler's sequence is fixed: AC-46 year check (`fiscal_year_closed`), then `expected_status`, then transition, then order, then blockers (close and soft-close only).

`FiscalCalendar` (pure; AC-31 to AC-33): `periodsFor(startDate)` returns 12 `[sequence, name, start, end]`. It requires a first-of-month start in 2000 to 2100, and computes `end = start + 12 months − 1 day`. `contiguous(?latestEnd, start)` gives `fiscal_year_not_contiguous`.

**Ruling 8: the seed** (AC-26 to 30, F-15). There is one backfill migration. It is retry-safe per row and runs in one transaction:
- for each row in parent-first order: if any row (live or soft-deleted) already has the code, skip it and remember it as the parent only if it is a live header of the same type; otherwise skip its subtree;
- if a single control type already has a live holder, skip the row;
- insert with `Str::ulid()` upper-cased, path and depth computed, `is_system = (control_type ≠ none)`, and `created_by` null;
- then, if `fiscal_years` is empty, create the current UTC year (`FY<yyyy>`, periods `FY<yyyy> P01` … `P12`).

The row list is a `public const ROWS` on the migration's class. A test re-derives the periods through `FiscalCalendar` and asserts equality, so the migration's inline calendar cannot drift from the API's.

**Ruling 9: the slices** (each `Features/<X>/{X.php, XHandler.php}` plus `XResult.php` where it returns an id, mirrored in `tests/Features/<X>/`; every command carries `?string $actorId`).

| Area | Slices | Key |
|---|---|---|
| Accounts | `CreateAccount`, `UpdateAccount`, `ReparentAccount`, `DeactivateAccount`, `ReactivateAccount`, `DeleteAccount` | `finance.accounts.manage` |
| Account reads | `ListAccounts`, `GetAccount`, `GetAccountTree` | `finance.accounts.view` |
| Years | `CreateFiscalYear`, `RenameFiscalYear`, `DeleteFiscalYear`, `RenameFiscalPeriod` | `finance.periods.manage` |
| Transitions | `TransitionFiscalPeriod` (target `soft_closed`/`closed`/`open`), `CloseFiscalYear`, `ReopenFiscalYear` | close moves: `finance.periods.close`; reopen moves (closed→soft_closed, soft_closed→open, year reopen): `finance.periods.reopen` |
| Year reads | `ListFiscalYears`, `ListFiscalPeriods`, `FindPeriodByDate` | `finance.periods.view` (D-24) |

`TransitionFiscalPeriod` decides the key from the requested move, after the pure `transition()` check, so an `invalid_transition` request is refused before authorization can tell the caller anything. The contract implementations (`Accounts\DatabaseChartOfAccounts`, `Periods\DatabasePeriodGate`) are not slices and need no permission. They are called by trusted module code, and the gate checks `post_adjustment` itself.

**Ruling 10: reads.**
- `ListAccounts`: page size 1 to 200, default 50, out of range is `page_size_invalid` (F-13). The cursor is the keyset `(after_code, after_id)`, both or neither. Filters per AC-61 are each validated as in Ruling 3. `q` is 1 to 100 characters, matched with `LIKE` over `code` and `name` with `\`, `%` and `_` escaped. Soft-deleted rows are excluded; the order is `code, id`.
- `GetAccountTree`: one query over live rows, `ORDER BY depth, code, id`, which puts parents before children, with no per-node query.
- `ListFiscalYears`: keyset on `start_date`. `ListFiscalPeriods` by year, ordered by `sequence`.
- `FindPeriodByDate`: the same single indexed read the gate uses, with no lock and no permission decision.
- Read slices use `AccountRows::*Unlocked` methods, the only ones the step 19 scan allows outside write paths.

**Ruling 11: audit** (AC-65). There is one `AuditTrail::record('finance', …)` per successful write, inside the transaction. `before`/`after` are the target row, and a transition's `after` includes `reason`. A re-parent's descendant path rewrites are derived and are not audited row by row. Actions are constants on each handler, collected in `Registry\FinanceAuditActions` (the catalog/customers precedent): `account_created`, `account_updated`, `account_reparented`, `account_deactivated`, `account_reactivated`, `account_deleted`, `fiscal_year_created`, `fiscal_year_renamed`, `fiscal_year_deleted`, `fiscal_period_renamed`, `fiscal_period_soft_closed`, `fiscal_period_closed`, `fiscal_period_reopened`, `fiscal_year_closed`, `fiscal_year_reopened`. A refusal writes none.

**Ruling 12: test cost** (T-027 Ruling 13 / T-028 Ruling 12, unchanged).
- Pure rules carry most cases, as datasets.
- Each slice gets one fixture-backed file in which every refusal writes nothing (no row and no audit row) and a success writes one audit row, compared whole.
- The package's tests run in each step, and `composer check` runs at each chunk boundary.
- If a chunk adds more than 10 % to the `test` step, stop and report to `lead`. The estimate is about 260 cases, about 70 % of them pure.

**Ruling 13: the vocabulary sweep** (T-028 Ruling 13). Before step 4, `dev` greps `QaVocabularyRenameSweepTest`'s retired spellings against every enum value and reason code in this task. Values are referenced as `Enum::Case`, never as dataset keys.

---

## 1. Approach summary

1. **Rebase first** (F-1): bring the T-028 tip into this branch, then measure the gate.
2. A new `packages/finance`, reached only through `Contracts\Finance\*`: `ChartOfAccounts`, `PeriodGate`, and the usage ports that FP-261 and catalog/customers implement.
3. Three tenant tables per §6.10. The single-holder control types are backstopped by a generated marker column with a unique index, and fiscal-year overlap by `unique(fiscal_periods.start_date)`.
4. All rules are pure classes (`Input`, `AccountRules`, `PeriodTransitions`, `FiscalCalendar`). Handlers authorize first, parse, then lock in one global order and evaluate the rules on locked rows only.
5. Period moves are adjacent-only, compare-and-set on `expected_status`, and monotonic by date. The gate takes a shared lock inside the posting transaction and fails closed.
6. One backfill migration seeds the D-6 chart and the current year for new and existing tenants alike.
7. Late chunk (section I): three expand files plus one contract file for the five owed columns. Catalog and customers validate through `ChartOfAccounts::assignable` and report referrers through a port. Catalog's dead-record edit hole is closed.
8. A source scan proves the locked-read construction, and an engine test (skipped under `ENGINE_HOLD`) holds every `[ENGINE]` proof.

## 2. Alternatives rejected

- **Finance as a `modules/finance` leaf booted from `MODULES`:** F-7.
- **Building HTTP routes now, to enforce AC-64 through `RequireTenantPermission`:** a whole API surface with no consumer (FP-269 owns screens). Handler checks give AC-64's guarantee today, and a future route adds the middleware on top.
- **Five contract files:** F-6.
- **A provisioning step plus a separate data migration for the seed:** two code paths for one data set. The backfill migration already runs at provisioning (F-15).
- **Optimistic `version` on accounts** (the T-027/T-028 shape): not in §6.10 and not asked for; row locks give AC-21.
- **Recursive SQL for descendants:** `path LIKE 'p/%'` under the whole-chart lock is engine-portable. ULIDs contain no `%` or `_`.
- **Gap-lock reliance for the first-year race:** replaced by the post-insert locking verification plus the period-start unique index (Ruling 6).
- **Mapping Finance's assignment refusal into `CatalogRefusal`:** that would edit T-029's concurrently-edited enum. The contract's `AccountRefused` propagates unchanged, carrying the field name.

## 3. Files to change / create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Finance/` (new)
The 22 files of Ruling 2.

### `packages/finance` (new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/finance` 0.1.0, requires `flexipos/contracts`. No `brick/math` (no arithmetic) |
| `src/FinanceServiceProvider.php` | binds `ChartOfAccounts`, `PeriodGate`, and the three single ports to their defaults; implements `DeclaresAuditActions` and `DeclaresPermissions`. No routes, no commands |
| `src/Accounts/{AccountRules,AccountInput,AccountDraft,AccountRows,ReferrerCensus,DatabaseChartOfAccounts,NoAccountUsage}.php` | Rulings 2, 4 and 6 |
| `src/Periods/{PeriodTransitions,FiscalCalendar,PeriodRows,DatabasePeriodGate,NoFiscalYearUsage,NoPeriodCloseBlockers}.php` | Rulings 2, 6 and 7 |
| `src/Support/{TenantConnection,Input,Limits,Authorize,Page}.php` | Rulings 1, 3, 5 and 10 |
| `src/Exceptions/{FinanceRefused,FinanceRefusal}.php` | `reason` (every snake code in the spec plus F-11's), `?field`, and context (for example the holder id) |
| `src/Registry/{FinanceAuditActions,FinancePermissions}.php` | Rulings 5 and 11 |
| `src/Features/<19 slices>/…` | Ruling 9 |
| `tests/Support/{FinanceSeed.php,finance-fixture.php,FakeUsagePorts.php}` | raw-insert seeds; an employee per permission profile through the access fixture; bound fakes for AC-25 |
| `tests/Accounts/*`, `tests/Periods/*`, `tests/Contracts/*`, `tests/Schema/FinanceTenantSchemaTest.php`, `tests/Seed/SeedChartTest.php`, `tests/Features/<X>/<X>Test.php` | section 7 |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_15_003201_create_accounts_table.php` | expand |
| `…003202_create_fiscal_years_table.php`, `…003203_create_fiscal_periods_table.php` | expand |
| `…003204_seed_chart_of_accounts_and_first_fiscal_year.php` | `@phase backfill` (Ruling 8) |
| `…003205_add_account_columns_to_products_table.php`, `…003206_add_income_account_column_to_categories_table.php`, `…003207_add_receivable_account_column_to_customers_table.php` | expand, nullable, no key (chunk E) |
| `…003208_add_owed_account_foreign_keys.php` | `@phase contract`, five keys, the `000506` shape (chunk E) |
| `config/finance.php` | `page_size` 50, `max_page_size` 200 |
| `tests/Feature/QaFinanceSourceRulesTest.php`, `tests/Feature/OwedAccountColumnsMigrationsTest.php`, `tests/Engine/FinanceSchemaEngineTest.php` | steps 17, 19 and 20 |

The `2026_11_15_0032xx` prefix sorts after T-029's `2026_11_10_0029xx` whichever task merges first. That makes no rename on this side necessary (section 5, merge order).

### `packages/catalog`, `packages/customers` (chunk E only)
- **Catalog:** `CreateProduct`/`UpdateProduct` gain `income_account_id`, `cogs_account_id` and `inventory_account_id`; `CreateCategory`/`UpdateCategory` gain `income_account_id`. Validation goes through `ChartOfAccounts::assignable`.
- **F-3:** `UpdateProductHandler` and `UpdateCategoryHandler` add `whereNull('deleted_at')`.
- **Customers:** `CreateCustomer` and `UpdateCustomer` gain `receivable_account_id`.
- New `src/Finance/CatalogAccountReferrers.php` and `src/Finance/CustomerAccountReferrers.php`. Both providers implement `DeclaresAccountReferrers`.

## 4. Data / API changes

**Schema** (tenant only; no master change):

| Table | Columns as built | Keys and indexes |
|---|---|---|
| `accounts` | `id`, `created_at`, `updated_at`, `deleted_at` (DATETIME(3)), `created_by`, `updated_by` (ULID NULL), `code` VARCHAR(20), `name` VARCHAR(150), `description` VARCHAR(500) NULL, `type` VARCHAR(30), `subtype` VARCHAR(30) NULL, `parent_id` ULID NULL, `path` VARCHAR(500), `depth` TINYINT UNSIGNED, `normal_balance` VARCHAR(30), `is_postable` default true, `is_system` default false, `control_type` VARCHAR(30) default `none`, `control_marker` VARCHAR(30) NULL **stored** `CASE WHEN deleted_at IS NULL AND control_type IN ('ar','ap','grni','payroll_payable','retained_earnings') THEN control_type END`, `currency` CHAR(3) NULL, `is_active` default true, `sort_order` SMALLINT UNSIGNED default 0, `opening_balance` DECIMAL(18,4) NULL, `opening_balance_date` DATE NULL | unique `code`; unique `control_marker`; index `parent_id`, `path`, `type`, `control_type`; FKs `parent_id` → accounts, `created_by`/`updated_by` → employees |
| `fiscal_years` | `id`, `created_at`, `updated_at`, `name` VARCHAR(50), `start_date` DATE, `end_date` DATE, `status` VARCHAR(30) default `open`, `closed_at` DATETIME(3) NULL, `closed_by` ULID NULL, `retained_earnings_entry_id` CHAR(26) NULL **no FK** (F-14) | unique `name`; unique `start_date`; FK `closed_by` → employees |
| `fiscal_periods` | `id`, `fiscal_year_id`, `sequence` TINYINT UNSIGNED, `name` VARCHAR(50), `start_date` DATE, `end_date` DATE, `status` VARCHAR(30) default `open`, `closed_at` DATETIME(3) NULL, `closed_by` ULID NULL (no `[TS]`, F-18) | unique (`fiscal_year_id`, `sequence`); unique `start_date`; FK `fiscal_year_id` → fiscal_years **CASCADE**, `closed_by` → employees |
| `products` (+3), `categories` (+1), `customers` (+1) | `income_account_id`, `cogs_account_id`, `inventory_account_id` / `income_account_id` / `receivable_account_id`: `IdentifierColumn::nullable`, no default | five FKs → `accounts.id`, RESTRICT (engine default), only in `003208` |

- **Foreign keys:** 6 on new tables plus 5 in the contract file. `TenantBaselineBuildsTest`'s inventory gains 6 (step 2) and then 5 (step 17).
- **Precision:** `accounts.opening_balance` (18,4) is the only decimal, never written (D-9, AC-66).
- **The single-control list has two homes**, `ControlType::isSingle()` and the generated expression. `FinanceTenantSchemaTest` parses the migration and asserts they are equal.
- **Version:** `APP_REQUIRED_SCHEMA_VERSION` goes 51 → 55 at chunk D and 55 → 59 at chunk E, each relative to the merged base. If T-029 is already in, the base is 63, so the targets are 67 and then 71.
- **Catalogue:** republished each time with `php artisan schema:publish --write-fixture`, never hand-merged.

**Seed (D-6, F-10)**, as `code type/subtype/control [header|leaf] parent`:
- `1000 asset/–/none` header root
- `1100 asset/cash/cash` leaf under 1000
- `1200 asset/bank/bank` leaf under 1000
- `1300 asset/inventory/inventory` leaf under 1000
- `1400 asset/receivable/ar` leaf under 1000
- `2000 liability/–/none` header root
- `2100 liability/payable/ap` leaf under 2000
- `2200 liability/long_term_liability/none` leaf under 2000
- `2300 liability/tax/tax_payable` leaf under 2000
- `2400 liability/current_liability/grni` leaf under 2000
- `2500 liability/current_liability/payroll_payable` leaf under 2000
- `3000 equity/–/none` header root
- `3100 equity/retained_earnings/retained_earnings` leaf under 3000
- `4000 revenue/–/none` header root
- `4100 revenue/sales/none` leaf under 4000
- `5000 expense/cogs/none` leaf root
- `6000 expense/–/none` header root
- `6100 expense/operating_expense/none` leaf under 6000
- `6200 expense/payroll_expense/none` leaf under 6000
- `6300 expense/operating_expense/none` leaf under 6000

`is_system` = control type ≠ none (nine rows).

**Contracts:** Ruling 2. **Permissions:** seven keys (Ruling 5, D-24). **HTTP, console, jobs, cache, hooks, outbox:** none. **Config:** `finance.php`. **Dependencies:** only the `flexipos/finance` path package; the `composer.lock` diff must add only that entry.

## 5. Boundary impact and merge order

- **`deptrac.yaml`** (the T-028 shape, no exemption):
  - `Package_Finance` = `^Flexipos\\Finance\\(?!Tests\\).*`, with ruleset `[Package_Kernel, Package_Contracts]`.
  - `Package_Finance_Tests` gets those plus `Package_Finance` and `Package_Access` (fixture).
  - `HostApp` and `HostTests` gain both layers.
  - **`Module_Demo` is not granted `Package_Finance`** (AC-51's instrument).
  - `Package_Catalog` and `Package_Customers` gain **no** edge: they use `Contracts\Finance`, which is inside `Package_Contracts` (AC-60).
- **PHPStan:** both paths.
- **Composer (host):** `require` gains `flexipos/finance: *`, and `autoload-dev` gains the tests namespace. Run `composer update flexipos/finance` (never `--lock`) and `composer validate --no-check-publish`.
- **`providers.php`:** `FinanceServiceProvider` goes after `CustomersServiceProvider`. Catalog and customers resolve `ChartOfAccounts` lazily inside handlers, so the order is not load-bearing; step 18 proves it.

**Merge order against T-029 (D-18; resolves `ba8`'s open assumption 3).** `architect` recommends that **T-029 merge first**. It is further along (plan revision 2 against this task's spec-only state), and its `0029xx` files already sort before `0032xx`, so neither side renames anything. The rule either way:
1. The second to merge brings the first into its branch and **regenerates** the catalogue, `.env.example`'s version, `tenantTreeTables()` and `MigrationTreeDisciplineTest`'s literal. It never hand-merges them.
2. **If T-029 is first:** T-032 narrows T-029's AC-29.3 test to T-029's own files (F-4). It resolves the textual conflicts in `CreateProduct`/`UpdateProduct` (both add fields to `FIELDS`), `CatalogTenantSchemaTest`'s `products` list (+2 there, +3 here), `TenantDecimalPrecisionTest`, `deptrac.yaml`, `phpstan.neon.dist`, `providers.php`, host `composer.json`/`.lock`, `QaToolchainRegistryCoverageTest`, `AuditCatalogueTest` and `TenantBaselineBuildsTest`. Our contract file then follows T-029's contract file, so a lagging fleet needs one run to the version before each, which is operational, not a defect.
3. **If T-032 is first:** T-029 must rename its twelve files to sort after `2026_11_15_003208` and narrow its own AC-29.3.
4. Neither task edits the other's migration files. The full `composer check` is re-run after the merge.

## 6. Implementation steps

Rules carried from T-027/T-028 §6, unchanged:
- red-first, by fixture or omission, never by stubbing a guard open;
- the **red-list rule** after steps 1, 2, 16, 17 and 18 (record the red list, compare it with section 9, and stop on any file section 9 does not name);
- no child processes; unique test basenames;
- list foreign `php.exe` processes before each gate run;
- mutations run once, reverted, and recorded with red-then-green counts in `dev`'s hand-back.

### Chunk A: base, plumbing, schema, static instruments

- [x] **Step 0: merge the base, measure it, verify every named symbol. STOP on any difference.**
  - **Spec:** build from spec revision 2 and D-20 to D-29 (`e5c99bb`), not from this plan's paraphrase and not from the first draft (`262a2bf`).
  - **Merge:** `lead` obtains the human's permission, then merges `ai/T-028-customers` @ `20ad316` into this branch (F-1, D-20); resolve `docs/backlog.md` only. If any other file conflicts, stop.
  - **Baseline:** `composer check`: record passed, failed and skipped counts, the `test` seconds and the process list. Expected: 13 failed (pre-existing) / about 3483 passed / about 38 skipped. A different failing set means stop.
  - **Verify, by reading source; every row must hold, else stop and report to `architect`:**
    - `AuditTrail::record` has the signature in 0.1, and `DeclaresAuditActions` / `DeclaresPermissions` / `PermissionDeclaration` / `PermissionResolver::decide(employeeId, key, ?branchId): AccessDecision` (with `->allowed`) are as quoted;
    - `$app->getProviders(Interface::class)` is the sweep `AuditCatalogue` and `PermissionRegistry` use;
    - `IdentifierColumn::{primary,required,nullable}` exist, `RunnerClock` is in `Kernel\Schema\Support`, and how a migration obtains the clock (container instance or `Illuminate\Support\Carbon`) is recorded;
    - `SourceRules` has no rule that refuses row inserts, `Str::ulid()` or a `public const` in a `@phase backfill` migration (read `inspect()`'s checks and `importsOutsideAllowedNamespaces`);
    - `SourceRules::isDestructive` flags only drop/rename/change/truncate;
    - the AC-58 contract guard in `PlanMigrationRunHandler` is as described;
    - `AuditCatalogueTest:48/:92` literals, `QaToolchainRegistryCoverageTest:446` literal, `MigrationTreeDisciplineTest:150` = 49, `TenantDecimalPrecisionTest:68` allow-list `[[18,4],[18,6],[9,4],[18,8]]`, `APP_REQUIRED_SCHEMA_VERSION=51`;
    - `CatalogTenantSchemaTest:163-165`, `CustomersTenantSchemaTest:143`, `UpdateProductHandler:65`, `UpdateCategoryHandler:88`, `UpdateCustomerHandler:62` read as in 0.1;
    - whether the `owner` system role acquires newly declared keys automatically (Ruling 5); record the answer;
    - whether T-029 has already merged into the base (`git log --oneline | grep T-029`). If it has, apply section 5's T-029-first branch now and re-baseline.
  - *(No AC.)*
- [x] **Step 1: package plumbing.**
  - `composer.json`, an empty provider (declares no actions and no keys yet), `config/finance.php`, and the section 5 edits.
  - Add `'finance'` to `QaToolchainRegistryCoverageTest`'s literal.
  - **`FinanceProviderTest`:** the provider is loaded.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Finance\Accounts\AccountRules` fails deptrac, and the same class importing `Flexipos\Contracts\Finance\ChartOfAccounts` passes. Delete both.
  - *(AC-51 instrument.)*
- [x] **Step 2: the three table migrations, with `FinanceTenantSchemaTest`** (own SQLite stand-in, the T-028 shape).
  - Exact sorted column lists with nullability for all three tables (section 4).
  - Unique keys by raw double insert:
    - `code`;
    - `control_marker`: a second live `ar` is refused; a soft-deleted `ar` plus a live one coexist; two `cash` coexist; two `none` coexist;
    - `fiscal_years.start_date`, `name`;
    - `fiscal_periods.start_date`, (`fiscal_year_id`, `sequence`).
  - The FK set equals the 6, and the cascade deletes a year's periods.
  - The single-control list is parsed from `003201` and equals `ControlType::isSingle()`. This needs the contracts enum, so step 2 adds `ControlType` from step 4 early.
  - `SourceRules::inspect()` reports nothing, and no file declares `down`.
  - Republish the catalogue. Update `tenantTreeTables()`, `MigrationTreeDisciplineTest` (49 → 52), `TenantBaselineBuildsTest`, `QaAccessCloseOutSweepsTest` / `QaAccessSchemaShapeTest` (`$t032` lists) and `.env.example` (51 → 54).
  - **Predicted red:** section 9's step-2 rows only.
  - *(The schema halves of AC-3, AC-17, AC-31, AC-35, AC-66.)*
- [x] **Step 3: static precision and widths.**
  - `TenantDecimalPrecisionTest` gains `accounts.opening_balance` (18,4) under a T-032 comment.
  - **`FinanceWidthsTest`:** every `VARCHAR` and `CHAR` width in `003201` to `003203` equals its `Support\Limits` constant (code 20, name 150, description 500, year and period name 50, enum 30, currency 3).
  - **Red-first:** a fixture declaring `string('code', 21)` is reported.
  - **Gate: `composer check` (end of chunk A).**
  - *(AC-4 and AC-34 width halves, AC-66.)*

### Chunk B: the pure core

- [x] **Step 4: contracts** (Ruling 2's 22 files), after Ruling 13's sweep.
  - **`FinanceEnumsTest`:**
    - each enum's exact value list;
    - `AccountType::normalBalance()` for all five types;
    - `subtypes()` equals AC-7's sets;
    - `ControlType::requires()` equals AC-16's table;
    - `isSingle()` equals D-3's five;
    - `AccountKind::accepts()` per AC-56;
    - every refusal enum equals Ruling 2's lists.
  - **`FinanceShapesTest`:** `AccountSnapshot`, `PeriodClearance`, `AccountReferrer` and `PeriodBlocker` accept well-formed values and refuse a malformed ULID or a negative count by name.
  - *(Groundwork for AC-5 to AC-7, AC-16, AC-17, AC-47, AC-51, AC-56, AC-63.)*
- [x] **Step 5: `Support\Input`, `Fields::only`, `AccountInput`** (pure datasets).
  - **AC-2:**
    - `ab1` is stored as `AB1`;
    - each of these is refused: `''`, 21 characters, `'A B'`, `' AB1'`, `'AB1 '`, `"AB1\n"` (the `$` trap), `"AB1\t"`, `"A\0B"`, `'.AB'`, `'-AB'`, `'Ａ1'` (full-width), `'١٢'` (Arabic-Indic digits), and non-strings.
  - **AC-4:**
    - 150 multi-byte characters pass and 151 are refused;
    - blank is refused, and `"a\x07b"` is refused;
    - invalid UTF-8 is refused;
    - a description of 501 characters is refused; `null` is accepted.
  - **AC-5:** `Asset`, `ASSET`, `' asset'`, `'asset '` and `'unknown'` are each refused for each of the four enum fields.
  - **AC-6 and AC-7:** the full derivation table; each wrong pairing gives `normal_balance_mismatch` / `subtype_mismatch`.
  - **AC-8:** each forbidden key and an unknown key are refused **in one message naming all of them**; `currency: null` is accepted; `currency: 'LKR'` is refused.
  - **AC-9:** `"true"`, `1`, `"yes"`, `null` for both flags; `sort_order` −1, 65536, `"5"`, `1.0`; an omitted `sort_order` gives 0.
  - **AC-32 and AC-48 date parser:** `2026-02-30`, `2026-13-01`, `26-1-1`, `2026-1-1`, `2026-01-01 00:00:00`, `''`, `null`, `"2026-01-01\n"`; the leap day `2028-02-29` passes and `2027-02-29` is refused.
  - **Mutations:**
    - **M1:** `^…$` instead of `\A…\z` makes the newline row go red;
    - **M2:** trim the code makes the `' AB1'` row go red;
    - **M3:** `strcasecmp` for the enum makes the `Asset` row go red;
    - **M4:** `DateTime::createFromFormat` alone, without `checkdate`, makes `2026-02-30` go red (FP-436 proven closed).
  - *(AC-2, AC-4 to AC-9, AC-32 and AC-48 parsing.)*
- [x] **Step 6: `AccountRules`** (pure).
  - **AC-10 order:** each of the six parent failures by itself, and two at once reports the first in Ruling 4's order.
  - **AC-11:** depth and path from a parent at depth 0 and at depth 8; a parent at depth 9 gives `depth_exceeded`.
  - **AC-13:**
    - moving under itself, under a child or under a grandchild gives `cycle`;
    - a sibling whose id string-prefixes the account's id (an `ABC` against `ABCD` path-segment trap) is **not** a cycle;
    - a subtree with deepest descendant depth 7 moved under depth 2 gives `depth_exceeded`.
  - **AC-14 to AC-19:**
    - the decision tables, one row per condition, each naming its condition;
    - AC-16's full table and its mismatch rows;
    - AC-18's seven immutable fields on a system snapshot each give `system_account_immutable`, while `name`, `description` and `sort_order` pass.
  - **Mutations:**
    - **M5:** the cycle check uses `str_starts_with(path, account.path)` without the `/`, which makes the prefix-trap row go red;
    - **M6:** check the depth on the moved node only, which makes the subtree row go red;
    - **M7:** drop `is_postable` from AC-16, which makes a header-control row go red.
  - *(AC-6, AC-7, AC-10, AC-11, AC-13 to AC-19 logic.)*
- [x] **Step 7: `FiscalCalendar` and `PeriodTransitions`** (pure).
  - **AC-31:**
    - `2026-01-01` gives 12 periods tiling to `2026-12-31`;
    - `2026-03-01` gives `2027-02-28`, and `2027-03-01` gives `2028-02-29` (leap);
    - every period starts the day after the previous one ends;
    - names are `P01` to `P12`.
  - **AC-32:** a non-first-of-month start, 1999 and 2101 are refused.
  - **AC-33:** the next start must be exactly `end + 1`; the day before, the day after, and a gap of a month each give `fiscal_year_not_contiguous`; a first year is free.
  - **AC-38:** all 9 (from, to) pairs; only 4 pass.
  - **AC-39:** an expected-status mismatch gives `status_changed` naming the stored status.
  - **AC-40:** close P2 while P1 is open, and reopen P1 while P2 is closed, are both refused naming the neighbour; boundary cases with no neighbour.
  - **AC-42:** a reopen without a reason, with a whitespace reason, or with 501 characters is refused; a close with no reason is accepted.
  - **AC-43:** `closedAt`/`closedBy` are set on each move up and cleared on soft_closed→open.
  - **Mutations:**
    - **M8:** allow open→closed, which makes the AC-38 row go red;
    - **M9:** check only the previous neighbour, which makes the reopen-order row go red;
    - **M10:** `+12 months` without `−1 day`, which makes the tiling go red.
  - **Gate: `composer check` (end of chunk B).**
  - *(AC-31 to AC-33, AC-38 to AC-40, AC-42, AC-43 logic.)*

### Chunk C: accounts on the database

- [x] **Step 8: permissions, authorization, ports.**
  - `FinancePermissions`, which the provider declares.
  - `Authorize`; `ReferrerCensus`; the three `No*` defaults bound.
  - `FinanceSeed` gives one employee per key (seven), plus one with none.
  - **AC-64 matrix (one dataset over every slice):**
    - a caller without the key is refused `not_permitted`, and a query listener proves **no** `accounts`/`fiscal_*` statement ran;
    - a null actor is refused;
    - a soft-deleted employee is refused;
    - `close` does not grant `reopen`, and `reopen` does not grant `close`;
    - **D-24 separation:** `finance.accounts.view` does not grant the year, period or date-lookup reads, and `finance.periods.view` does not grant the account reads.
  - The registry sweep finds the seven keys through the booted provider.
  - **Mutation M11:** move `Authorize` inside the transaction after the target read, which makes the "no statement ran" assertion go red.
  - *(AC-64; AC-25 wiring.)*
- [x] **Step 9: `CreateAccount`.**
  - **AC-1:** every default, and the row appears in list and tree (after step 12; the assertion is added there).
  - **AC-3 on the stand-in:**
    - `ab1` against an existing `AB1`, live and soft-deleted, gives `code_in_use` naming the id;
    - the backstop: seed a row by raw insert past the pre-check seam, and the catch maps to `code_in_use` and re-reads with a lock.
  - **AC-10 to AC-12** through the handler. AC-12's lock call is observed on the builder seam; holding it is `[ENGINE]`, step 20.
  - **AC-16 and AC-17:** a second `ar` gives `control_account_exists` naming the holder; after the holder is soft-deleted, the create is accepted; two `bank` are accepted.
  - **AC-8 end to end:** nothing written, no audit row.
  - **AC-65:** one audit row, compared whole.
  - **Mutation M12:** remove the `control_account_exists` pre-check; the generated-marker backstop still refuses and the test stays green on the reason. That proves the backstop. Then remove the catch's mapping, and the reason goes red.
  - *(AC-1 to AC-12, AC-16, AC-17, AC-65.)*
- [x] **Step 10: `UpdateAccount`.**
  - **AC-20:** unknown or soft-deleted gives `account_not_live`, nothing written.
  - **AC-21:** unsupplied fields are unchanged.
  - **AC-14:** a header with a live child changed to postable gives `has_children`. A postable leaf changed to header: accepted with no usage; refused with a bound `AccountUsage` fake (`has_postings`) or a fake referrer (`in_use`).
  - **AC-15:** each condition by name: reason `type_change_refused`, with `condition` set to the failing one, including `has_postings` (N-1).
  - **AC-18:** the system immutables.
  - **AC-19 and AC-22:** with fakes. The reasons are `control_type_locked` and `code_locked` (AC-25, D-25).
  - **F-12:** `parent_id` and `is_active` give `not_updatable_here`.
  - *(AC-14, AC-15, AC-18 to AC-22, AC-25 in part.)*
- [x] **Step 11: `ReparentAccount`, `DeactivateAccount`, `ReactivateAccount`, `DeleteAccount`.**
  - **AC-13:** a three-level subtree moved; every descendant's `path` and `depth` are rewritten in one transaction (assert all rows); cycle; depth.
  - **AC-18:** a system account cannot be re-parented, deactivated or deleted.
  - **AC-23:**
    - live child gives `has_children`; postings fake; referrer fake (live **or** deleted count) gives `in_use` naming kinds and counts;
    - success leaves the row soft-deleted, the code still reserved (a create of the same code is refused), and the row hidden from the list;
    - a second delete gives `account_not_live` with exactly one audit row in total.
  - **AC-24:**
    - an active child refuses deactivation;
    - a live referrer refuses deactivation (a deleted-only referrer does not);
    - deactivating twice gives `already_inactive`;
    - reactivation under an inactive or deleted parent is refused;
    - reactivating an active account gives `already_active`.
  - **AC-25:** one `FakeUsagePorts` file proves each listed rule refuses (F-9).
  - **Mutations:**
    - **M13:** skip the descendant rewrite, which makes the AC-13 assertion go red;
    - **M14:** count only live referrers on delete, which makes the deleted-referrer row go red.
  - *(AC-13, AC-18, AC-20, AC-23 to AC-25.)*
- [x] **Step 12: reads and the `ChartOfAccounts` implementation.**
  - **AC-61:**
    - page size 0, 201 and `"50"` give `page_size_invalid`; the default is 50;
    - 3 rows at size 2 page through `(after_code, after_id)`; a half cursor is refused;
    - each filter with a valid value and with a case variant (refused);
    - `q='%'` matches only literal `%`;
    - soft-deleted rows are excluded;
    - the order is `code, id`.
  - **Tree:** parents before children for a 4-level chart; the query count is constant across 10 and 200 accounts (no N+1, NFR).
  - **AC-63:**
    - `byId`/`byCode` (case-folded) / `byControlType` return snapshots;
    - `postable()` refuses deleted, inactive and header accounts with the contract reasons;
    - the snapshot is immutable (readonly);
    - resolving the contract from the container gives the implementation.
  - **Gate: `composer check` (end of chunk C).**
  - *(AC-1's read half, AC-61, AC-63.)*

### Chunk D: fiscal years, transitions, the gate, the seed

- [ ] **Step 13: years and periods CRUD** (`CreateFiscalYear`, `RenameFiscalYear`, `RenameFiscalPeriod`, `DeleteFiscalYear`, the three reads).
  - **AC-31:** one year plus 12 periods. Atomicity: a fault injected at period 7 (test seam) leaves **no** year.
  - **AC-33:** contiguity through the handler.
  - **AC-34:** `fy26` against `FY26`, and `'FY26 '` against `FY26`, give `name_in_use`.
  - **AC-35 on the stand-in:**
    - the backstops: a raw-inserted overlapping period start makes the create refuse with `fiscal_year_not_contiguous`, and nothing remains;
    - the post-insert verification is exercised by a seam that inserts a gapped year between the pre-check and the verify.
  - **AC-36:** each forbidden key in a rename body refuses the whole request.
  - **AC-37:**
    - not the latest gives `not_latest_year`;
    - a soft-closed period gives `fiscal_year_in_use`;
    - a `FiscalYearUsage` fake gives `fiscal_year_in_use`;
    - success removes the year and its periods.
  - **AC-62:** list order and paging; lookup by date, and `no_fiscal_period`.
  - **Mutation M15:** drop the post-insert verification, which makes the gapped-year seam test go red.
  - *(AC-31 to AC-37, AC-62.)*
- [ ] **Step 14: `TransitionFiscalPeriod`, `CloseFiscalYear`, `ReopenFiscalYear`.**
  - **AC-38 to AC-43** end to end.
  - **AC-39:** a replayed close with the old `expected_status` gives `status_changed`, nothing written, and no second audit row.
  - **AC-40:** across a year boundary (P12 of 2026 against P01 of 2027).
  - **AC-41:** a blockers fake gives `close_blocked` listing kinds and counts; a reopen is never asked.
  - **AC-43:** `closed_by` is the actor, and a client-supplied `closed_at` is refused.
  - **AC-44:** the lock calls are observed in the documented order on the builder seam; contention is `[ENGINE]`.
  - **AC-45:**
    - one non-closed period gives `periods_not_closed`;
    - success: `closed`, with `retained_earnings_entry_id` still NULL, and no row ever holds `closing` (asserted over the table).
  - **AC-46:**
    - reopening a period of a closed year gives `fiscal_year_closed`;
    - a year reopen without a reason is refused; without the reopen key it is refused;
    - with a later year not open it gives `later_year_not_open`.
  - **Mutations:**
    - **M16:** skip `expected_status`, which makes the replay row go red;
    - **M17:** skip the year check, which makes the AC-46 row go red.
  - *(AC-38 to AC-46 except the `[ENGINE]` clauses.)*
- [ ] **Step 15: `DatabasePeriodGate`.**
  - **AC-47:** a dataset of {open, soft_closed, closed} × {plain actor, `post_adjustment` actor, null actor}.
  - **AC-48:** every invalid date; a tenant with no year gives `no_fiscal_period` for today, so it never defaults.
  - **AC-49:** the first day, the last day and the day after.
  - **AC-50:** called outside a transaction gives `LogicException`; inside, the `sharedLock` call is observed. The two-connection proof is `[ENGINE]`.
  - **AC-51:** the contract's return type and exceptions are all under `Contracts\Finance` (reflection test).
  - **Mutations:**
    - **M18:** let finance actors through `closed`, which makes that row go red;
    - **M19:** return "allowed" on a parse failure, which makes AC-48 go red.
  - *(AC-47 to AC-51 except the `[ENGINE]` clause.)*
- [ ] **Step 16: the seed migration, with `SeedChartTest`.**
  - **AC-26 and AC-27:** the exact 20 rows (section 4); the headers; `is_system` iff control; exactly one live holder each for ar, ap, grni, payroll_payable and retained_earnings.
  - **AC-28:**
    - run twice gives no duplicates;
    - rename 4100 then re-run keeps the new name;
    - a pre-seeded `1100` that is a header of the wrong type is left untouched, with its subtree skipped and no failure;
    - a partial run (rows 1 to 7 raw-inserted) resumes to the full set.
  - **AC-29:** every `ROWS` entry passes `AccountInput::forCreate` plus `AccountRules` against its seeded parent.
  - **AC-30:** the clock is fixed at 2026-06-15, giving `FY2026`, 12 open periods, and parity with `FiscalCalendar`; with an existing year, none is added.
  - **Red-first:** a fixture copy of `ROWS` with `2100` as `asset` is reported by the AC-29 test.
  - The catalogue row is `backfill` and `is_destructive = false` (AC-54 in part). `.env.example` goes 54 → 55.
  - **Gate: `composer check` (end of chunk D).**
  - *(AC-26 to AC-30.)*

### Chunk E: the owed account columns (section I)

- [ ] **Step 17: the three expand files and the contract file, with `OwedAccountColumnsMigrationsTest`.**
  - **Pre-check:** T-027 and T-028 tables exist (guaranteed by step 0, re-asserted). If T-029 landed since step 0, apply section 5's T-029-first branch first.
  - **AC-52:**
    - the five columns are `CHAR(26)` through `IdentifierColumn::nullable`, nullable, with no default and no key in the expand files;
    - a counter over `products`/`categories`/`customers` finds exactly five `*_account_id` columns (F-5 scoping);
    - `categories.cogs_account_id` is absent.
  - **AC-53:**
    - **red-first:** seed a product with an `income_account_id` naming no account; the contract file refuses, naming `products.income_account_id` and `1 row(s)` and no value, and no key is added;
    - when clean, five keys exist;
    - a re-run is a no-op;
    - SQLite rebuild: row counts, the `sku`/`plu_code` uniques and every pre-existing key survive on all three tables.
  - **AC-54:** every T-032 catalogue row is `is_destructive = false`, and none adds `NOT NULL`.
  - **AC-60 / F-2:**
    - `CatalogTenantSchemaTest` and `CustomersTenantSchemaTest` change only their absence assertions and column lists;
    - narrow T-029's AC-29.3 if present (F-4);
    - `TenantBaselineBuildsTest` +5 keys; catalogue republished; `.env.example` 55 → 59.
  - **Mutation S1:** write the contract as `->change()`, and the AC-54 assertion goes red.
  - *(AC-52 to AC-54, AC-66.)*
- [ ] **Step 18: assignment and referrers in catalog and customers.**
  - **`DatabaseChartOfAccounts::assignable`.**
  - The field and validation edits (section 3). The account is read only when the value changes to a non-null id; null clears it; omitted leaves it unchanged.
  - **F-3:** add `whereNull('deleted_at')` to `UpdateProductHandler` and `UpdateCategoryHandler`.
  - **`CatalogAccountReferrers`, `CustomerAccountReferrers`:** `sharedLock()` counts, live and deleted separately.
  - **AC-55 and AC-56:** per field, each of the five reasons, plus a malformed id (catalog's or customers' own validation naming the field).
  - **AC-57:** `assignable` outside a transaction throws; the `sharedLock` call is observed; the race is `[ENGINE]`.
  - **AC-58 (D-29):**
    - a soft-deleted product, category or customer refuses an account update **and** a name update as not found, with nothing written (F-3 regression);
    - a merged-away customer source refuses an account update (N-3);
    - **AC-60(c):** any shipped T-027 test that expected a soft-deleted product or category to update successfully is edited to expect the refusal. `dev` lists each such file in the hand-back; section 9 names the candidates. If there are none, record "none found".
  - **AC-59:** reads carry the ids or null.
  - **AC-23 and AC-24 end to end:**
    - delete an account referenced by a soft-deleted product gives `in_use` (`products.income_account_id: 0 live, 1 deleted`);
    - deactivation is refused only by a live referrer.
  - **AC-60:** the full T-027 and T-028 package suites pass, with only section 9's expectation edits; deptrac is clean; the handler resolves with only the contract bound (provider order is not load-bearing).
  - **Mutations:**
    - **M20:** skip `sharedLock` in `assignable`; no stand-in test can see it, so the scan in step 19 must catch it (proves the scan);
    - **M21:** accept a header account, which makes `account_not_postable` go red.
  - **Gate: `composer check` (end of chunk E).**
  - *(AC-23, AC-24, AC-55 to AC-60.)*

### Chunk F: scans, engine, documents, close-out

- [ ] **Step 19: `QaFinanceSourceRulesTest` (host).** Code only, through `SourceRules::codeOnly`.
  - **Locked-read construction (0.3):**
    - no file under `packages/finance/src/Features/*` whose slice is a write (Ruling 9's list, held as a literal in the test) calls `->first(`, `->get(`, `->exists(`, `->count(`, `->value(` or `->pluck(`;
    - every terminal read in `AccountRows`/`PeriodRows` outside methods named `*Unlocked` has `lockForUpdate()` or `sharedLock()` in the same chain;
    - `*Unlocked` methods are called only from read slices and from `PeriodRows::immutableCoordinates`.
  - **Assignment and referrers:** `DatabaseChartOfAccounts::assignable` and every `AccountReferrers` implementation (catalog, customers) chain `sharedLock()`.
  - **One writer:** outside `packages/finance/src` and the T-032 migrations, no code names `table('accounts')`, `table('fiscal_years')` or `table('fiscal_periods')`. Catalog and customers reach accounts only through the contract.
  - **Unreachable values:** no `'closing'` literal and no `is_system` assignment outside the seed migration.
  - **No surface:** no `routes/` directory, `Route::`, `Cache::`, `dispatch(`, `ShouldQueue` or `HookGateway`.
  - **Regex discipline:** no `preg_match` pattern in `packages/finance/src` ends in `$/` (they must use `\z`).
  - **Contracts purity:** `packages/contracts/src/Finance` imports nothing outside contracts and PHP core.
  - **Red-first:**
    - a scratch `->first()` in a write handler is reported;
    - M20's missing `sharedLock` is reported;
    - a scratch `table('accounts')` in `packages/organization/src` is reported;
    - delete all three.
  - *(AC-12, AC-21, AC-44, AC-50, AC-51, AC-57 construction halves, AC-60.)*
- [ ] **Step 20: `FinanceSchemaEngineTest`** (database `flexipos_t8test_t032_tenant`, created and dropped by **exact name** through `EngineDatabase`, never a `LIKE` sweep).
  - Under the hold, only the "refuses to reach the engine" case runs.
  - Written for G-1, with rows built with explicit ids (never `insertGetId`, T-028 W-2), and with connection B calling the **real** handler or gate:
    - **AC-3:** `ab1` raw-inserted against `AB1` refused by the unique index on the real collation;
    - **AC-17:** two connections create `ar` at once, exactly one row;
    - **AC-12/21:** A holds the parent `FOR UPDATE` while B's create waits; after A turns it postable and commits, B gets `parent_not_header`;
    - **AC-35:** two first-year creates (overlapping, then gapped) give exactly one year, and the other gets `fiscal_year_not_contiguous` (deadlock retry path);
    - **AC-44:** two closes of one period give one winner and one `status_changed`;
    - **AC-50:** A runs the gate and holds its shared lock; B's close waits; A commits; B closes; C's gate after that gets `period_closed`;
    - **AC-57:** A assigns an account to a product and holds; B's delete waits, then gets `in_use`;
    - the known deadlock (risk 3) yields a 1213 rollback and a clean retry;
    - `information_schema` decimals; generated-column uniqueness; migrating twice is a no-op.
  - *(Every `[ENGINE]` clause; they stay NOT VERIFIED under `ENGINE_HOLD`.)*
- [ ] **Step 21: documents.** Describe, never quote retired vocabulary.
  - **`docs/database.md` §6.10, "As built (T-032)":**
    - the generated control marker;
    - the period-start overlap backstop;
    - the lock order;
    - the adjacent transition model with compare-and-set;
    - the gate's contract and its fail-closed rule;
    - the seed and its subtypes (F-10);
    - the unbuilt `closing` state and `retained_earnings_entry_id` key (F-14).
  - **§6.3 and §6.9:** the owed columns delivered.
  - **`docs/architecture.md` §3:** Finance joins the as-built `packages/` list.
  - **Backlog notes** (section 10).
- [ ] **Step 22: final gate.** `composer check` from `modules/host`, with counts and `test` seconds compared with step 0, then `composer test:fast` once.
  - *(AC-66; every criterion except the `[ENGINE]` clauses.)*

## 7. Test strategy

- **Unit (pure):** `InputTest`, `AccountInputTest`, `AccountRulesTest`, `FiscalCalendarTest`, `PeriodTransitionsTest`, `FinanceEnumsTest`, `FinanceShapesTest`. These carry most cases as datasets.
- **Integration (SQLite stand-in through `AccessTenantFixture`):**
  - one file per slice, plus `DatabaseChartOfAccountsTest` and `DatabasePeriodGateTest`;
  - `FinanceTenantSchemaTest`, `SeedChartTest`, `OwedAccountColumnsMigrationsTest`;
  - the edited catalog and customers feature tests.
- **Static (host):** `QaFinanceSourceRulesTest`, `FinanceWidthsTest`, `TenantDecimalPrecisionTest`, deptrac with the demo negative control, and the catalogue `is_destructive` assertions.
- **Engine:** `FinanceSchemaEngineTest`, skipped under the hold.
- **Mutations:** M1 to M21 and S1, covering:
  - the parser traps (newline, trim, case, rollover);
  - cycle and depth;
  - control fit and uniqueness backstop;
  - descendant rewrite;
  - referrer scope;
  - year atomicity and verification;
  - transition adjacency, order and replay;
  - year gating;
  - the gate's closed and parse rules;
  - assignment lock and postability;
  - the contract file's non-destructiveness.

| ACs | Proved by |
|---|---|
| 1–9 | steps 5 and 9 (AC-1 read half in step 12) |
| 10, 11, 13 | steps 6, 9 and 11 |
| 12, 21 | steps 9, 10 and 19; `[ENGINE]` step 20 |
| 14–20, 22 | steps 6, 10 and 11 |
| 23, 24 | steps 11 and 18 |
| 25 | steps 8, 10, 11, 13 and 14 (F-9) |
| 26–30 | step 16 |
| 31–34, 36, 37 | steps 7 and 13 |
| 35 | step 13; `[ENGINE]` step 20 |
| 38–43, 45, 46 | steps 7 and 14 |
| 44 | steps 14 and 19; `[ENGINE]` step 20 |
| 47–49, 51 | step 15 (AC-51 also steps 1 and 19) |
| 50 | steps 15 and 19; `[ENGINE]` step 20 |
| 52–54 | step 17 |
| 55, 56, 58, 59 | step 18 |
| 57 | steps 18 and 19; `[ENGINE]` step 20 |
| 60 | steps 17, 18, 19 and 22 |
| 61–63 | steps 12 and 13 |
| 64 | step 8 |
| 65 | every write slice's test (steps 9 to 14) |
| 66 | steps 2, 3, 17 and 22 |

## 8. Risks & rollback

1. **The base merge (highest, blocking).** Nothing can start without the human's permission to merge (F-1). The mitigation is that `lead` raises it at task start; the merge is conflict-light (backlog only), as measured from the two histories.
2. **Gate time.** The `test` step was about 1638 s at T-028's close, and this task adds about 260 cases, mostly pure. Measure at each chunk boundary; above 10 %, stop and report.
3. **Stand-in and engine divergence.** On SQLite, `lockForUpdate`/`sharedLock` compile to nothing. No stand-in test can catch a missing lock or the stale-snapshot class, which is why 0.3 makes them a **source construction** checked by step 19, and step 20 holds the real proofs for G-1. Known engine deadlocks: re-parent (whole-chart lock) against a concurrent child create, and account delete against an update of a product already referencing it. InnoDB rolls one back, and data stays correct (`attempts: 2` for Finance; catalog surfaces a raw error, the FP-430 class). Step 20 exercises one.
4. **T-029 collision** (F-4, F-5, section 5). Mitigation: the merge-order rule, regenerated catalogue, and narrowing AC-29.3 rather than deleting it.
5. **Year rollover halts posting.** The gate fails closed, so on 1 January a tenant with no next year refuses every FP-261 posting. Not in this task's scope; filed as a follow-up (auto-create or warn), and FP-261 must handle it (F-19).
6. **Guards inert until bound.** AC-14/15/19/22/23/37/41 refuse nothing in production until FP-261 binds the ports (spec, forward dependencies). This is accepted and stated.
7. **F-3 changes T-027 behaviour** (dead products and categories become uneditable). It is the correct direction; regression tests cover it.
8. **`composer.lock`:** only the path package entry may change.

**Rollback:** every file is new except section 9's edits. The migrations are forward-only, and no tenant outside this branch has run them, so rollback is to abandon the branch. Section I can be reverted alone (chunk E's commit), because nothing in A to D depends on it.

## 9. Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `docs/backlog.md` | merge conflict resolution only | 0 |
| `modules/host/composer.json`, `composer.lock` | require and autoload-dev; the path package entry | 1 |
| `modules/host/deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | section 5 | 1 |
| `modules/host/config/finance.php` *(new)* | Ruling 10 | 1 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` | `'finance'` | 1 |
| `packages/access/tests/Support/AuditCatalogueTest.php` | `'finance'` in both module literals, when the first action lands | 8 or 9 |
| access permission-count tests, if any count the total registered keys | +7 (record the exact files at step 8) | 8 |
| `database/catalogue/tenant_schema_versions.json` | republished | 2, 16, 17 |
| `.env.example` | version 51 → 54 → 55 → 59 (base-relative) | 2, 16, 17 |
| `tests/Helpers.php` `tenantTreeTables()` | three names under a T-032 comment | 2 |
| `tests/Feature/MigrationTreeDisciplineTest.php` | 49 → 52 | 2 |
| `tests/Feature/TenantBaselineBuildsTest.php` | +6 keys, then +5 | 2, 17 |
| `tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | `$t032` list | 2 |
| `SchemaCataloguePublishTest` | fixed by the republish | 2, 16, 17 |
| `tests/Feature/IdempotencyKeysSchemaTest.php` | hard-coded catalogue top entry (version and migration name); **missed by the plan, found by the chunk A gate** (the T-028 chunk A gap again). Steps 16 and 17 edit it again | 2, 16, 17 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | `accounts.opening_balance` | 3 |
| `packages/catalog/tests/Schema/CatalogTenantSchemaTest.php`, `packages/customers/tests/Schema/CustomersTenantSchemaTest.php` | absence to presence, column lists +3/+1/+1 (F-2) | 17 |
| `tests/Engine/CatalogSchemaEngineTest.php`, `CustomersSchemaEngineTest.php` | column lists, if they list them | 17 |
| T-029's `ProductTaxGroupMigrationsTest` (only if T-029 merged first) | AC-29.3 narrowed to T-029's files (F-4) | 17 |
| `packages/catalog/src/Features/{CreateProduct,UpdateProduct,CreateCategory,UpdateCategory}/*Handler.php`, `CatalogServiceProvider.php` | section 3, F-3 | 18 |
| `packages/customers/src/Features/{CreateCustomer,UpdateCustomer}/*Handler.php`, `CustomersServiceProvider.php` | section 3 | 18 |
| `packages/catalog/tests/Features/{CreateProduct,UpdateProduct,CreateCategory,UpdateCategory}/*Test.php`, `packages/catalog/tests/Support/CatalogSeed.php` | AC-60's three permitted kinds only: (a) is in step 17's row; here (b) whole-row expectations gain the account keys, and (c) any test that updated a soft-deleted product or category now expects the refusal; plus new F-3 regression cases | 18 |
| `packages/customers/tests/Features/{CreateCustomer,UpdateCustomer}/*Test.php`, `packages/customers/tests/Support/CustomersSeed.php` | the same | 18 |
| `docs/database.md`, `docs/architecture.md` | step 21 | 21 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **FP-261:**
  - bind `AccountUsage`, `FiscalYearUsage` and `PeriodCloseBlockers`;
  - call `PeriodGate::clear` inside the posting transaction;
  - `journal_entries.period_id` FK, and the contract key on `fiscal_years.retained_earnings_entry_id` (F-14);
  - decide the async-posting-into-a-closed-period behaviour (F-19).
- **New row:** the year-end closing entry and the `closing` state (spec open question 1, D-5).
- **New row:** create next year automatically, or warn before year end (risk 5).
- **New row (or a T-032 follow-up after T-029 lands):** `tax_authorities.payable_account_id`/`receivable_account_id`, `discounts.sales_discount_account_id` (F-5).
- **FP-262:** consume the five columns and the resolver.
- **FP-264:** `customers.receivable_account_id`.
- **Roles task:** grant the seven finance keys to a finance role, and to the owner if step 0 finds the owner does not get new keys automatically.
- **FP-269 / the API task:** routes with `RequireTenantPermission` declaring the same seven keys, which brings `access_log` rows (F-8, D-24).
- **FP-428:** step 18 closes it for products and categories only. It stays open for the other catalog update handlers (N-2).
- **`ba`:** add AC-15's `type_change_refused` to AC-25's exception list (N-1).
- **FP-433:** another copy of the support helpers. Finance's strict `Input` is a candidate for the shared version, because it has the FP-436 fix.
- **FP-430 class:** catalog's raw 1213 on the account-delete deadlock (risk 3).
- **Product owner:** review the seed's subtypes and codes (F-10, D-6) before FP-261 ships.

## Questions for the human

1. **Permission to merge `ai/T-028-customers` (`20ad316`) into `ai/T-032-chart-of-accounts` before any code (F-1).** Without it, no step past step 0 can run.
2. **Merge order against T-029:** `architect` recommends T-029 first (section 5). `lead` decides.

F-1 to F-19 are resolved in `e5c99bb` (D-20 to D-29). The open items are N-1 (a one-line AC-25 wording fix for `ba`, not blocking) and N-2 (a FP-428 annotation for `lead`).
