# T-032: Chart of accounts and fiscal periods, with period closing

Backlog row FP-260 (Phase 3, Finance). Written autonomously (team mode): every question that would have blocked is resolved in `docs/decisions/T-032-chart-of-accounts.md` as D-n and cited here. Sources read: `docs/architecture.md` and `docs/database.md` §6.10 (as in `build-56f213`), `Refference/ERP.md` §11, the FP-260 to FP-269 backlog rows, T-008 D-10/D-5, T-027 D-3/D-4, T-028 D-6/D-19/D-21/D-25 and the T-028 round-1 review.

## Problem / goal

The ledger has nowhere to post. FP-261 (journal), FP-262 (posting rules), FP-263 to FP-268 all need three things this task provides: a chart of accounts, fiscal years divided into periods, and a way to close a period so that posting into it is refused. This task also pays two debts the catalogue and customer tasks left on purpose: the account columns on `products`, `categories` and `customers`, which could not exist until `accounts` did.

Goal: a tenant has a validated, hierarchical chart of accounts seeded at provisioning; it has fiscal years of twelve monthly periods; a period moves `open -> soft_closed -> closed` under rules that cannot be bypassed or replayed into a wrong state; and a published gate lets the posting service (FP-261) ask "may I post on this date, as this actor?" with an answer that cannot race with a close.

## Users affected

- Accountant / finance role: maintains the chart, creates fiscal years, closes and reopens periods.
- Shop owner / admin: receives the seeded chart, assigns accounts on products, categories and customers.
- Catalogue and customer users: gain optional account fields on products, categories and customers.
- Developers of FP-261 to FP-268: consume the contracts named under "Forward dependencies".
- Not affected yet: cashiers. Nothing in this task changes a sale.

## Current behaviour

Measured in `build-56f213` (the branch for this task is based on a stale `main`, see Assumption 1):
- No `accounts`, `fiscal_years` or `fiscal_periods` table exists. No Finance package exists.
- `products` and `categories` ship without `income_account_id`, `cogs_account_id`, `inventory_account_id` (T-027 D-3). `customers` ships without `receivable_account_id` (T-028 D-6/D-19).
- `company_profile` is not built (T-028 D-18 measured this), so `fiscal_year_start_month` has no home. `currencies` and `exchange_rates` are not built.
- `docs/database.md` §6.10 documents the three target tables and says the posting service refuses posting into a closed period; nothing implements it.

## User stories

- As an accountant, I want a validated chart of accounts with parents, types and control accounts, so that postings land on accounts that mean what their type says.
- As an accountant, I want fiscal years split into monthly periods, so that reports have defined boundaries.
- As an accountant, I want to soft-close and close a period, and reopen it with a recorded reason, so that finished months stop changing but a mistake is still fixable under control.
- As a finance user, I want to post an adjustment into a soft-closed period while ordinary users cannot, so that late corrections are possible without reopening.
- As a shop owner, I want a sensible chart ready on day one, so that I do not build one before my first sale.
- As a catalogue or customer manager, I want to choose the income, cost and inventory account of a product and the receivable account of a customer, so that FP-262 can post them correctly.
- As the FP-261 developer, I want one gate to ask whether a date may be posted into, so that I never re-implement period rules.

## Acceptance criteria

Conventions for every criterion below. Refusals are whole-request: nothing is written, the response names the offending field and carries a stable snake_case reason code. "Live" means `deleted_at IS NULL`. Money does not appear in this task (D-9), so no float rule is needed; dates are strict `Y-m-d`. Criteria marked `[ENGINE]` need a real MySQL/MariaDB engine to prove and are recorded as NOT VERIFIED until one runs them (T-028 D-15 precedent); everything else is provable on the SQLite stand-in.

### A. Account create and field validation

- AC-1: Given a valid code, name, type and no parent, When an account is created, Then a live row exists with a ULID id, `is_active = true`, `is_postable = true` (default), `is_system = false`, `control_type = none`, `depth = 0`, `path` equal to its own id, `created_by` the actor, and it is returned by the list and the tree reads.
- AC-2: Given a code, When it is submitted, Then leading or trailing whitespace is a refusal (never trimmed), lower-case letters are upper-cased for storage, and the stored value must match `^[0-9A-Z][0-9A-Z.-]{0,19}$`. Refused: empty, 21 characters, an internal space, a leading `.` or `-`, a non-ASCII digit or letter, a null byte, a tab or newline.
- AC-3: Given an account with code `AB1` exists (live or soft-deleted), When another is created or recoded to `ab1`, `Ab1` or `AB1`, Then it is refused `code_in_use` naming the existing account's id. Proven both on the stand-in and `[ENGINE]` on the real collation, with the pairs `ab1`/`AB1`, and a code identical to a soft-deleted account's code. A soft-deleted account keeps its code reserved.
- AC-4: Given `name`, When it is submitted, Then it is required, 1 to 150 characters counted as characters (not bytes, so a 150-character multi-byte name passes and a 151-character one is refused), not whitespace-only, and contains no control characters. `description` is optional, null or 1 to 500 characters. Over-length input is refused, never truncated.
- AC-5: Given `type`, `subtype`, `normal_balance` or `control_type`, When the value is not exactly one of the lower-case documented enum values, Then it is refused. `Asset`, `ASSET`, ` asset`, `asset ` and unknown values are all refused and never coerced (the engine's enum comparison is case-insensitive and would otherwise accept them).
- AC-6: Given `type`, When `normal_balance` is omitted, Then it is derived (asset and expense: debit; liability, equity and revenue: credit). When supplied and different from the derived value, Then it is refused `normal_balance_mismatch`.
- AC-7: Given a `subtype`, When it does not belong to the account's `type`, Then it is refused `subtype_mismatch`. Allowed sets: asset: current_asset, fixed_asset, inventory, receivable, cash, bank; liability: current_liability, payable, tax, long_term_liability; equity: capital, retained_earnings; revenue: sales, other_income; expense: cogs, operating_expense, payroll_expense, other_expense. `subtype` may be null on a non-control account.
- AC-8: Given a create or update body containing any of `id`, `is_system`, `path`, `depth`, `deleted_at`, `created_by`, `opening_balance`, `opening_balance_date`, `currency` (non-null), or any unknown key, When it is submitted, Then the whole request is refused naming every offending key (D-9, D-10).
- AC-9: Given `is_postable`, `is_active` or `sort_order`, When `is_postable`/`is_active` is anything but a JSON boolean (`"true"`, `1`, `"yes"`, null) or `sort_order` is not an integer from 0 to 65535, Then it is refused. Omitted `sort_order` stores 0.

### B. Hierarchy

- AC-10: Given a `parent_id`, When it is supplied, Then the parent must exist, be live, be a header (`is_postable = false`) and have the same `type`; each failure is refused with a distinct reason (`parent_not_found`, `parent_not_live`, `parent_not_header`, `parent_type_mismatch`).
- AC-11: Given a valid parent, When a child is created, Then `depth = parent.depth + 1` and `path = parent.path + '/' + id`. A parent at depth 9 refuses a child (`depth_exceeded`, maximum 10 levels, D-12). `path` never exceeds 500 characters.
- AC-12: Given create, update or re-parent against a parent, When the handler runs, Then it reads the parent under a row lock and re-validates AC-10 on the locked values, so a parent soft-deleted, deactivated or turned postable by a concurrent request cannot gain a child. `[ENGINE]` for the two-connection proof.
- AC-13: Given an account, When it is re-parented, Then the new parent is refused if it is the account itself or any of its descendants (`cycle`), and on success the account and every descendant get a rewritten `depth` and `path` in the same transaction; the depth limit is checked against the deepest descendant.
- AC-14: Given a header with live children, When it is changed to `is_postable = true`, Then it is refused `has_children`. Given a postable account with no children, When changed to a header, Then it is allowed only if it has no postings and no referrers (AC-25 port checks).
- AC-15: Given an account, When its `type` changes, Then it is refused unless it is not system, has no children, has no postings, has no referrers, and the new type matches its parent's type (`type_change_refused`, naming the failing condition).

### C. Control accounts and system accounts

- AC-16: Given `control_type` other than `none`, When set, Then the account must be postable and its type/subtype must fit: ar: asset/receivable; ap: liability/payable; inventory: asset/inventory; cash: asset/cash; bank: asset/bank; tax_payable: liability/tax; tax_receivable: asset (subtype current_asset); grni: liability (subtype current_liability); payroll_payable: liability (subtype current_liability); retained_earnings: equity/retained_earnings. A mismatch is refused `control_type_mismatch`.
- AC-17: Given `control_type` in {ar, ap, grni, payroll_payable, retained_earnings}, When a second live account is given the same value, Then it is refused `control_account_exists` naming the holder. A soft-deleted holder does not block. `none`, cash, bank, inventory, tax_payable and tax_receivable may repeat (D-3). `[ENGINE]` for the concurrent-create race: exactly one of two simultaneous creates wins and the other is refused, never two rows.
- AC-18: Given an account with `is_system = true`, When anyone updates it, Then `code`, `type`, `subtype`, `normal_balance`, `control_type`, `parent_id` and `is_postable` are immutable (refused `system_account_immutable`), and delete and deactivate are refused; `name`, `description` and `sort_order` remain editable. `is_system` can never be set through any request.
- AC-19: Given a non-system account, When its `control_type` changes, Then it is refused if the account has postings or referrers (usage ports, AC-25), and otherwise follows AC-16 and AC-17.

### D. Update, delete, deactivate: lifecycle and dead records

- AC-20: Given an account that is soft-deleted or does not exist, When update, delete, deactivate, reactivate or re-parent is attempted, Then it is refused `account_not_live` and nothing is written. The read inside each handler filters `deleted_at IS NULL`.
- AC-21: Given an update, When it runs, Then the row is read with a row lock and every rule is evaluated on the locked values (no read-then-write on an unlocked read). Unsupplied fields are unchanged. `[ENGINE]` for the two-connection proof.
- AC-22: Given a non-system account, When its `code` changes, Then it follows AC-2 and AC-3 and is refused if the account has postings (`code_locked`).
- AC-23: Given an account, When it is deleted, Then it is refused if it is system, has live children (`has_children`), has postings, or is referenced by any live or soft-deleted record in any module (`in_use`, naming the referrer kinds and counts). On success it is soft-deleted, its code stays reserved, and it disappears from default lists. A second delete is refused `account_not_live` and writes no second audit row.
- AC-24: Given an account, When it is deactivated, Then it is refused if system, if it has an active child, or if a live product, category or customer references it. Reactivation is refused while its parent is inactive or deleted. Deactivating twice is refused (no-op with a reason), not silently accepted.
- AC-25: Given the "usage" questions (does the account have postings? who references it? are there period-close blockers?), When this task answers them, Then it asks three published ports (`AccountUsage`, `AccountReferrers`, `PeriodCloseBlockers`) that other modules implement (D-7). Until FP-261 binds the postings and draft-entries implementations, the defaults report "none"; this is a forward dependency, listed below, not a guarantee. A test proves a bound fake that reports usage makes AC-14, AC-15, AC-19, AC-22, AC-23 and AC-44 refuse.

### E. Seed

- AC-26: Given tenant provisioning (and an idempotent data migration for tenants that already exist), When the seed runs, Then the chart of D-6 exists: 1000 Assets, 1100 Cash, 1200 Bank, 1300 Inventory, 1400 Accounts Receivable, 2000 Liabilities, 2100 Supplier Payables, 2200 Loans, 2300 Tax Payable, 2400 Goods Received Not Invoiced, 2500 Payroll Payable, 3000 Equity, 3100 Retained Earnings, 4000 Revenue, 4100 Sales, 5000 Cost of Goods Sold, 6000 Expenses, 6100 Rent, 6200 Salary, 6300 Electricity, with parents, types, subtypes and control types exactly as D-6 states.
- AC-27: Given the seeded rows, When inspected, Then 1000, 2000, 3000, 4000 and 6000 are headers, every other row is postable, every row with a control type other than `none` is `is_system = true`, and exactly one live account holds each of ar, ap, grni, payroll_payable and retained_earnings.
- AC-28: Given the seed is run twice, or resumed after a partial failure, When it completes, Then no row is duplicated, a seeded account the tenant renamed keeps its new name, and a tenant that already holds an account with a seed code is left unchanged and not failed.
- AC-29: Given every seeded row, When passed through the same validator the API uses (AC-2 to AC-19), Then all pass. A test fails the build if the seed data violates a rule the API enforces.
- AC-30: Given provisioning or the existing-tenant migration, When it finishes, Then one fiscal year for the application's current calendar year (1 January to 31 December) exists with twelve open periods (D-8), unless a fiscal year already exists, in which case none is added.

### F. Fiscal years and periods

- AC-31: Given a name and `start_date`, When a fiscal year is created, Then `end_date = start_date + 12 months - 1 day`, twelve periods with sequence 1 to 12 are created, each exactly one calendar month and together tiling the year with no gap or overlap, named `<year name> P01` to `P12`, all `open`, and the year is `open`. The year and its twelve periods are written in one transaction: no year exists without its twelve periods.
- AC-32: Given `start_date`, When it is not a real calendar date in strict `Y-m-d` (`2026-02-30`, `2026-13-01`, `26-1-1`, `2026-1-1`, a date-time, an empty string, null), or is not the first day of a month, or its year is outside 2000 to 2100, Then it is refused.
- AC-33: Given existing fiscal years, When a new one is created, Then its `start_date` must be exactly the day after the latest existing year's `end_date` (`fiscal_year_not_contiguous`); an overlapping, earlier or gapped start is refused. The first year in a tenant may start on any first-of-month (D-8).
- AC-34: Given `name`, When submitted for a year, Then it is 1 to 50 characters, not whitespace-only, and unique across years case-insensitively. Period names are 1 to 50 characters.
- AC-35: Given two simultaneous creates for the same `start_date` or for overlapping years, When both run, Then exactly one succeeds and the other is refused `fiscal_year_not_contiguous`; no partial year remains. `[ENGINE]`.
- AC-36: Given a year or period, When it is updated, Then only `name` may change; `start_date`, `end_date`, `sequence`, `status`, `closed_at`, `closed_by` and `retained_earnings_entry_id` supplied in the body refuse the whole request (status moves only through AC-38 to AC-46).
- AC-37: Given a fiscal year, When it is deleted, Then it is allowed only for the latest year, only if every period is `open`, and only if `FiscalYearUsage` reports no journal entries in it; otherwise refused (`fiscal_year_in_use`, `not_latest_year`). Deleting removes its periods with it.

### G. Period status transitions and year close

- AC-38: Given a period, When a status change is requested, Then only the adjacent moves are accepted: open to soft_closed, soft_closed to closed (close), closed to soft_closed (reopen), soft_closed to open (reopen). Any other pair, including open to closed and any move to the same status, is refused `invalid_transition` and writes nothing (D-4).
- AC-39: Given a transition request, When it is submitted, Then it must carry `expected_status`; if the stored status differs the request is refused `status_changed` naming the current status. A replayed close therefore never applies twice and never silently succeeds against a changed row.
- AC-40: Given all periods ordered by `start_date`, When any transition would leave an earlier period at a lower status rank than a later one (open < soft_closed < closed), Then it is refused `period_order_violation` naming the offending period. So an earlier period must be closed before a later one, and a later one reopened before an earlier.
- AC-41: Given a transition to soft_closed or closed, When `PeriodCloseBlockers` reports a blocker (for example a draft journal entry dated in the period), Then it is refused `close_blocked` listing the blockers. A reopen is never blocked this way.
- AC-42: Given a reopen (closed to soft_closed, soft_closed to open), When it is requested, Then `reason` is required, 1 to 500 characters, not whitespace-only, and is stored in the audit record; it is refused without it. Closes and soft-closes accept an optional reason.
- AC-43: Given a period transition, When it succeeds, Then `closed_at` and `closed_by` are set from the server clock and the authenticated employee to the moment of the move into soft_closed or closed, and are cleared when the period returns to open. A client-supplied `closed_at` or `closed_by` is refused (AC-36). `closed_by` references a live employee.
- AC-44: Given a transition, When it runs, Then it locks the affected period rows in ascending `start_date` order and evaluates AC-38, AC-40 and AC-41 on the locked values. Two simultaneous transitions of the same period leave exactly one winner and the other gets `status_changed`. `[ENGINE]`.
- AC-45: Given a fiscal year, When it is closed, Then every one of its periods must already be `closed` (`periods_not_closed`) and the year must be `open`; on success the year becomes `closed` with `closed_at` and `closed_by`, and `retained_earnings_entry_id` stays NULL. The `closing` status is never entered by this task. The retained-earnings entry is not generated here (D-5).
- AC-46: Given a closed year, When it is reopened, Then it requires a reason (AC-42) and the `finance.periods.reopen` permission, and is refused if any later year is not `open`. While the year is `closed`, reopening one of its periods is refused (`fiscal_year_closed`); a period move needs the year reopened first.

### H. The period gate (published contract for FP-261)

- AC-47: Given a posting date and the posting actor, When the gate is asked, Then it returns the id of the one period whose `start_date <= date <= end_date`, or refuses: `no_fiscal_period` when no period covers the date, `period_closed` when the period is closed (for every actor, including finance), `period_soft_closed` when it is soft_closed and the actor lacks `finance.periods.post_adjustment`. An open period is allowed for any actor with posting rights. A soft_closed period is allowed to an actor with that permission.
- AC-48: Given a date, When it is null, empty, not a real strict `Y-m-d`, or a date-time, Then the gate refuses `invalid_date`; it never defaults to "allowed" or "today". A tenant with no fiscal year refuses every date (`no_fiscal_period`), so the gate fails closed.
- AC-49: Given the first and last day of a period and the day after, When the gate is asked about each, Then the first two resolve to that period and the third to the next period (or `no_fiscal_period` if none).
- AC-50: Given the gate runs inside the posting transaction, When it reads the period, Then it takes a shared row lock on it, and every status transition takes an exclusive lock (AC-44). A close therefore waits for an in-flight posting to commit, and a posting started after the close commits sees the new status and is refused. `[ENGINE]` two-connection proof.
- AC-51: Given the gate's contract, When read by another module, Then it exposes only the period id, status and the refusal reason, never the model class (module boundary; deptrac clean).

### I. Owed account columns (T-027 D-3/D-4, T-028 D-6/D-19)

These criteria apply once the T-027 and T-028 tables exist on this branch (Assumption 1).

- AC-52: Given the expand step, When the migrations run on every tenant, Then `products` gains nullable `income_account_id`, `cogs_account_id`, `inventory_account_id`; `categories` gains nullable `income_account_id`; `customers` gains nullable `receivable_account_id`. Five columns, each `CHAR(26)` with the identifier charset and collation helper, no default, no foreign key, no `NOT NULL`, no backfill. A mechanical test counts exactly five and fails on a sixth. Columns the documents do not list (for example `categories.cogs_account_id`) are not added.
- AC-53: Given the contract step, When it runs, Then a separate migration, ordered after its expand migration, adds the five foreign keys onto `accounts` with `ON DELETE RESTRICT`, after a pre-check that every non-NULL value is an existing account id; if any is not, it refuses and names the table, column and count. A fixture with one orphan value proves the refusal and that no foreign key was added.
- AC-54: Given T-008 D-10, When the migration planner classifies the ten migrations, Then none is `is_destructive`, so the planner does not refuse them when backups are unavailable and new-tenant provisioning is not broken. No `NOT NULL` tightening is introduced on any of the five columns (D-11).
- AC-55: Given a create or update of a product, category or customer, When one of the account fields is supplied, Then it must be a well-formed 26-character ULID of a live, active, postable account of the required kind (AC-56), else refused (`account_not_found`, `account_not_live`, `account_inactive`, `account_not_postable`, `account_kind_mismatch`). Null clears the field; an omitted field is unchanged.
- AC-56: Given the kind table, When validating, Then: `income_account_id` requires type revenue; `cogs_account_id` requires type expense and subtype cogs; `inventory_account_id` requires type asset and subtype inventory; `receivable_account_id` requires type asset and subtype receivable.
- AC-57: Given assignment, When the handler reads the account, Then it reads it under a shared row lock inside the same transaction as the write, so a concurrent delete or deactivation of that account cannot leave a live referrer pointing at a dead account (AC-23, AC-24 count referrers under the same lock). `[ENGINE]`.
- AC-58: Given a product, category or customer that is soft-deleted, When an account field update is attempted, Then it is refused as it already is for every other field (dead records are not edited).
- AC-59: Given reads of a product, category or customer, When returned, Then the new fields appear with the account id or null. No resolution by inheritance (product to category to branch to company) is built; that is FP-262.
- AC-60: Given the T-027 and T-028 AC suites already shipped, When this task lands, Then they still pass unchanged, and the products and customers packages depend on the Finance package only through its published contract (boundary check clean).

### J. Reads, contracts, permissions, audit, limits

- AC-61: Given the account list, When called, Then it supports offset or cursor pagination from its first version (page size 1 to 200, default 50; out of range refused), filters by `type`, `subtype`, `parent_id`, `is_active`, `is_postable`, `control_type` and a `q` search over code and name, validates every filter value as in AC-5 (case-sensitive, invalid refused), excludes soft-deleted by default, and orders by `code` then `id`. A tree read returns the whole chart with parents before children.
- AC-62: Given fiscal years and periods, When listed, Then years are ordered by `start_date` with paging as AC-61; a lookup by date returns the period covering it or `no_fiscal_period`.
- AC-63: Given the published read contract `ChartOfAccounts`, When another module asks by id, by code or by control type, Then it returns an immutable snapshot (id, code, name, type, subtype, normal_balance, is_postable, is_active, control_type) or a refusal; a method that refuses non-live, inactive or non-postable accounts exists for posting callers.
- AC-64: Given permissions, When a caller lacks the permission, Then the request is refused before any read of the target: `finance.accounts.view` for reads; `finance.accounts.manage` for account writes; `finance.periods.manage` for creating, renaming and deleting years; `finance.periods.close` for soft-close, close and year close; `finance.periods.reopen` for reopen (separate from close); `finance.periods.post_adjustment` for the gate's soft-closed allowance.
- AC-65: Given any successful write (account create, update, delete, deactivate, reactivate, re-parent; year create, rename, delete; any period or year transition), When it commits, Then one audit row is written in the same transaction naming actor, action, target id, and the before and after values of the changed fields; a refused request writes none; a transition's reason is included.
- AC-66: Given the three new tables (accounts, fiscal_years, fiscal_periods) and the five new columns, When the schema catalogue and migration set are checked, Then every migration is forward-only (no `down()`), is run by the per-tenant runner against every tenant database, and the catalogue test, the identifier-collation rule (ULID columns `ascii_bin` through the shared helper, nothing else naming a collation) and the decimal-precision test stay green. `accounts.opening_balance` is not built as a money column that this task writes (D-9).

## Out of scope

- Journal entries and lines, the posting service, balancing, idempotent posting, number sequence for entries: FP-261.
- Posting rules, `account_mappings`, and the product to category to branch to company account resolver: FP-262.
- `financial_accounts` (cash, bank, petty cash accounts) and bank transactions: FP-263. `ar_documents`/`customer_ledger.ar_document_id`: FP-264. AP: FP-265. Expense accounts mapping: FP-266. Tax configuration: FP-267. Trial balance, P&L, balance sheet, `account_balances`: FP-268. Finance screens (Angular): FP-269.
- Generating the year-end retained-earnings entry and the `closing` year state (D-5). No backlog row currently owns this; see Open questions.
- Multi-currency accounts, `currencies`, `exchange_rates`, `company_profile.fiscal_year_start_month`, 13-period or short years, non-monthly periods, budgeting, cost centres.
- Writing `accounts.opening_balance` / `opening_balance_date` (D-9); account import or export; chart templates other than the one seed; branch-level `cost_centre_account_id` on `branches` (needs its own expand/contract pair, owner FP-260 not named in the backlog row).
- Frontend. Offline sync of finance records.

## Forward dependencies (named, deferred)

| Row | Must deliver for this task's contracts |
|---|---|
| FP-261 journal | bind `AccountUsage` (postings count per account), `FiscalYearUsage` (entries per year) and `PeriodCloseBlockers` (draft entries per period) replacing the "none" defaults; call `PeriodGate` inside its posting transaction; add `journal_entries.period_id` FK to `fiscal_periods`. Until bound, AC-14/15/19/22/23/37/41 refuse nothing in production. |
| FP-262 posting rules | the account resolver and `account_mappings`; consume the five owed columns. |
| FP-264 AR | `customers.receivable_account_id` consumer; `ar_documents` FK. |
| unassigned | year-end closing entry (revenue/expense to retained earnings) and the `closing` year state. |
| T-029 (concurrent) | see Merge order. |

## Merge order and concurrent work

T-029 (taxes and pricing) is adding `tax_group_id` and `purchase_tax_group_id` to `products` in another worktree. The account-id columns are different columns, but both tasks edit the `products` migration set, the product create/update handlers and validators, and the schema catalogue (the migration list the catalogue test compares against the directory). Textual conflicts and an ordering clash in the catalogue and in migration timestamps are expected. Rule: whichever task merges second rebases onto the first, re-sorts its migration filenames after the first's, regenerates the catalogue entry list rather than hand-merging it, and re-runs the full gate. This task's expand and contract migrations for `products` must sort after T-029's (or vice versa) deterministically; neither task may edit the other's migration files. The lead decides the order; this spec does not assume one.

## Non-functional requirements

- Security: every write behind a named permission (AC-64); no account is created with `is_system` through any request; refusals do not leak whether a soft-deleted account exists beyond the code reservation.
- Integrity: every financial-adjacent write is one transaction with locks as stated; failure modes from the T-028 review (replayed writes, unlocked read-then-write, dead-record edits, unvalidated widths and dates, collation bypass) are each covered by a named criterion: AC-39, AC-12/21/57, AC-20/58, AC-4/32, AC-3/5.
- Performance: tree read of 2,000 accounts returns in one query set without per-node queries (no N+1); the gate resolves a date with one indexed read.
- Migration: forward-only, runnable per tenant, expand before contract, never destructive.
- Accessibility: not applicable (no UI).

## Open questions

1. The year-end closing entry and the `closing` year state have no owning backlog row (AC-45, D-5). Needs a row, for example under FP-261 or FP-268. Not blocking this task.
2. Does the owner want the seeded fiscal year to follow a configurable start month once `company_profile` ships (D-8)? Not blocking.

## Assumptions

1. The branch `ai/T-032-chart-of-accounts` is based on a `main` that stops at T-013, so the `products`, `categories` and `customers` tables do not exist on it. Section I (AC-52 to AC-60) cannot be built or tested until the T-027 and T-028 work is on the branch. Sections A to H and J are independent of it. The lead must rebase or merge those tasks first, or schedule section I as a second pass.
2. Finance is a new module package booted from `MODULES`, named by `architect`; the contracts in this spec (`PeriodGate`, `ChartOfAccounts`, the three usage ports) are published by it and consumed across modules only through those contracts.
3. The permission keys in AC-64 follow the existing catalogue convention; `architect` aligns exact spelling. Roles holding them are seeded by the roles task, not here.
4. An audit mechanism exists in the tenant baseline (`audit_logs`) and writes inside the caller's transaction.
5. `employees` exists (T-010) for `closed_by` and `created_by` references.
6. The seed list is the minimum from `Refference/ERP.md` §11 plus the control accounts the documented posting rules need (D-6); the owner may extend it.
7. Account ids are server-generated ULIDs; clients cannot supply ids or replay keys (finance writes are online-only, out of offline sync).
