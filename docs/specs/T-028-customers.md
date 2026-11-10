# T-028: Customers — groups, credit ledger and loyalty

Backlog: FP-232, "Customers, groups, credit ledger and loyalty" (`docs/backlog.md`), matching
`docs/architecture.md` §3's one-line module description for **Customers**: "Customers, groups, credit
ledger, loyalty." Decisions D-1 to D-17 are in `docs/decisions/T-028-customers.md`. **No BLOCKING
questions** — this is autonomous/team-mode work; every open design question was decided and logged
there rather than left for the human.

**Scoping call, made before writing a single AC (D-1):** this spec is **one task**, covering all eight
tables `docs/database.md` §6.9 "Customers and CRM" groups together: `customer_groups`, `customers`,
`customer_addresses`, `customer_ledger`, `loyalty_programs`, `loyalty_transactions`, `customer_notes`,
`customer_communications`. Unlike T-027/FP-230's split of `barcode_rules` into its own task (FP-423) —
a genuinely separate mechanism (a dual-language parser with its own parity-testing discipline) — credit
ledger and loyalty here are the **same shape applied twice**: an append-only ledger plus a maintained
balance column, the identical "snapshot, don't recompute" discipline T-006/T-027 already established
for quantity. Nothing in this task's eight tables is a second kind of work; see D-1/D-7 for the full
reasoning.

## Problem / goal

FlexiPOS has no customer data at all today: no `customers`, `customer_groups`, `customer_ledger`,
`loyalty_programs`, `loyalty_transactions`, `customer_addresses`, `customer_notes` or
`customer_communications` table, and nothing a till, an admin screen, or a future credit sale could
read or write against. `docs/architecture.md` §3 and `docs/database.md` §6.9 already fully design the
schema — groups with their own discount/credit/loyalty-multiplier defaults, customers with a maintained
credit balance and loyalty balance, an append-only `customer_ledger` that is the authority behind the
maintained balance (the exact transaction shape is spelled out at `docs/database.md`'s line documenting
`SELECT customers FOR UPDATE, check credit_limit, INSERT ar_documents, customer_ledger; UPDATE
customers.credit_balance`), and a parallel append-only `loyalty_transactions` ledger behind a maintained
points balance and tier. This task is the first to build any of it: the schema, the CRUD features for
all eight tables, and the two balance-arithmetic calculators (credit, loyalty) that every later task
touching customer money or points must call rather than reimplement.

This is the second task in the new business-domain feature phase T-027 (Catalog) opened. Every task
before T-027 built platform/infrastructure; T-027 and this task are the first two to build something
the till and the admin app will actually read from.

## Users affected

- **A newly provisioned tenant's owner/admin staff**, who today have no way to record who their
  customers are, extend credit, or run a loyalty program. This task gives them customer records,
  groups, a credit ledger, and loyalty accrual/redemption to set up before a sale can be billed to an
  account or earn points.
- **Every later task that reads or writes customer data**: Sales (FP-233, billing a sale to a customer
  and posting `sale_tenders`/loyalty on completion), Finance (chart of accounts/`ar_documents`, which
  this task's `customer_ledger` already anticipates per its documented transaction shape), the pricing
  engine (FP-231, which reads `customer_group_id` for its own price-list scope but is not built here —
  D-4), and offline sync (`docs/architecture.md` §12 names `customer`/`customer_group` as replica entity
  types the till holds offline, not built here).
- **`CustomerCreditLedger`'s and `LoyaltyLedger`'s future callers** (Sales, Finance): this task is the
  one and only place credit-balance and loyalty-balance arithmetic is implemented. No later module may
  re-derive a customer's balance by summing `customer_ledger`/`loyalty_transactions` itself outside the
  published calculators this task ships.
- Not served by this task: anyone needing the pricing ladder that reads `customer_group_id` (FP-231),
  accounts receivable documents (`ar_documents`, Finance — chart of accounts does not exist), sales that
  bill to a customer (FP-233), or the offline replica/sync mechanics for customer data
  (`docs/architecture.md` §12, Sync module) — each depends on a table or module this task does not
  build, see Out of scope.

## Current behaviour

Re-derived from the files, not assumed:

- No customer table exists anywhere in the shipped tree. Measured: no migration under
  `modules/host/database/migrations-tenant/` (or any package's tenant migrations) creates `customers`,
  `customer_groups`, `customer_addresses`, `customer_ledger`, `loyalty_programs`,
  `loyalty_transactions`, `customer_notes`, or `customer_communications`; no `packages/customers`
  directory exists.
- **Tables this task's own columns reference already exist**, shipped by prior tasks: `branches`
  (T-014, for `customer_ledger.branch_id`), `employees` (T-010/T-014, for every `[BY]`/`created_by` and
  the explicit `employee_id` columns this task's tables carry).
- **Four documented columns reference tables that do not exist**, measured directly (no migration
  creates any of them): `customers.receivable_account_id` → `accounts` (Finance, chart of accounts,
  TODO); `customer_ledger.ar_document_id` → `ar_documents` (Finance, TODO); `loyalty_transactions.
  sale_id` → `sales` (FP-233, Sales, TODO); `customer_communications.delivery_id` → a
  notification-delivery table (Notification module, TODO). None of the four are built by this task
  (D-6).
- `custom_field_definitions` exists (T-008/T-011), so the `[CF]` tag on `customers` has somewhere to
  validate against, unchanged by this task.
- No module in this codebase declares a published "Customers" contract today (measured: no
  `packages/contracts/src/Customers/` directory exists). This task adds it.
- T-027 (Catalog, DONE) is the only precedent for this task's shape: a new `packages/customers` package
  following the identical vertical-slice convention (`Features/<Verb><Noun>/<Verb><Noun>`,
  `<Verb><Noun>Handler`, `<Verb><Noun>Result`), `IdentifierColumn`-style ULIDs, `[SOFT]`/`[BY]`/`[TS]`
  tagging, and per-tenant migrations under the package's own `database/migrations-tenant/` directory.

## User stories

- As a shop admin, I want to organise customers into groups with their own discount, credit and loyalty
  defaults, so that I can set a policy once (a wholesale group, a staff group) instead of configuring
  every customer individually.
- As a shop admin, I want to record a customer's name, contact details and billing/delivery addresses,
  so that staff can find and bill the right person and a delivery goes to the right place.
- As a shop admin extending credit to a trusted customer, I want a running ledger of every credit sale,
  payment, refund, credit note and adjustment against their account, with a balance that is always
  correct and never silently out of sync with the ledger that produced it, so that I can trust the
  number on the statement.
- As a shop admin, I want the system to refuse a credit sale that would push a customer past their
  credit limit, or that is attempted against a customer not approved for credit at all, so that a
  careless entry does not silently extend credit nobody authorised.
- As a shop admin running a loyalty programme, I want customers to earn and redeem points against rules
  I configure (points per currency unit, a minimum/maximum redemption share, tiers), with a points
  balance that is always correct, so that a cashier never has to do the math and a customer is never
  shorted or overpaid.
- As a shop admin, I want to flag and merge two customer records that turned out to be the same person,
  without losing their credit or loyalty history, so that duplicate sign-ups do not fragment a
  customer's history across two accounts.
- As a developer of the next task that touches customer money or points (Sales, Finance), I want one
  place that applies a ledger entry and recomputes the balance correctly, so that I never have to decide
  for myself how to sum signed debits and credits.

## Acceptance criteria

### A. Customer groups

- **AC-1**: Creating a `customer_groups` row requires `code` (unique, non-blank) and `name`;
  `discount_percent`, `default_credit_limit`, `default_payment_terms_days`, `loyalty_multiplier` are
  optional numeric fields, each validated as non-negative when supplied; `credit_allowed` (bool)
  defaults to `false`; `is_default` (bool) defaults to `false`. A blank or duplicate `code` is refused
  with a validation message naming the field, before any row is written.
- **AC-2**: At most one `customer_groups` row has `is_default = true`. Setting a second row's
  `is_default` to `true` automatically clears the previous default in the same transaction, mirroring
  T-027's `product_barcodes.is_primary` precedent — never leaving two defaults.
- **AC-3**: Soft-deleting a `customer_groups` row that is referenced by any active (non-soft-deleted)
  customer's `group_id` is refused, naming the blocking reference; a group with none soft-deletes
  normally.
- **AC-4**: `version` is a plain optimistic-concurrency token: an update must supply the row's current
  `version`; a stale value is refused rather than silently overwriting a concurrent change, and a
  successful update increments it.
- **AC-5** (out-of-scope boundary, D-4): `customer_groups.discount_percent` and `loyalty_multiplier` are
  stored and returned as plain data. This task does not read `price_list_assignments` or implement any
  part of the pricing ladder (`docs/database.md` §6.4) that would apply either value to a sale — that is
  FP-231's concern.

### B. Customers

- **AC-6**: Creating a `customers` row requires `name` (non-blank); `code` is optional but unique when
  supplied (nullable unique, duplicate values refused); `group_id`, when supplied, must reference an
  existing, non-soft-deleted `customer_groups` row. `status` defaults to `active` (one of `active`,
  `blocked`, `inactive`).
- **AC-7**: When `group_id` is supplied and `credit_limit`/`payment_terms_days` are **not** supplied on
  the create request, they are inherited from the group's `default_credit_limit`/
  `default_payment_terms_days` at the moment of creation (a one-time copy, not a live link — changing
  the group's defaults later never retroactively changes an existing customer's own values).
- **AC-8**: `credit_balance`, `loyalty_points` and `loyalty_tier` are **maintained-only** columns
  (D-2/D-3/D-8): a create or update request that supplies any of the three is refused **in full** (the
  whole request, not a partial apply), naming the offending field(s) — mirroring T-027's AC-22
  (`stock_unit_id` immutability) shape. Only the ledger-writing features in sections D and E may change
  these columns, and only inside the same transaction as the ledger/loyalty row that produces the new
  value.
- **AC-9**: `status = 'blocked'` requires a non-blank `blocked_reason`; every other status requires
  `blocked_reason` to be null — refused otherwise in either direction, naming the inconsistency.
- **AC-10**: Soft-deleting a customer is refused while `credit_balance != 0` (D-9), naming the
  outstanding balance; a customer with a zero balance soft-deletes normally regardless of how much
  ledger/loyalty/address/note/communication history exists against their id — that history remains
  valid against the row's id either way.
- **AC-11**: `first_sale_at`, `last_sale_at` and `lifetime_value` are built as nullable/zero-default
  columns with no maintenance logic in this task (out-of-scope boundary, stated for the reader): nothing
  that completes a sale exists yet (FP-233), so nothing could populate them correctly. Whichever task
  ships `sales` owns updating these fields.
- **AC-12**: `possible_duplicate_of` and `merged_into_id` are plain nullable self-referencing fields,
  settable manually; no automatic duplicate-detection algorithm is built (D-10) — nothing documents a
  matching rule.
- **AC-13** (the merge feature, D-10): `MergeCustomers(source_id, target_id)` is refused when
  `source_id = target_id`, when either id does not exist, or when the source is already merged
  (`merged_into_id` already set). On success, in one transaction: every `customer_addresses`,
  `customer_ledger`, `loyalty_transactions`, `customer_notes` and `customer_communications` row with
  `customer_id = source_id` is reassigned to `target_id`; the target's `credit_balance` and
  `loyalty_points` are **recomputed** from the full, now-reassigned set of ledger/loyalty rows (a fresh
  sum, not an addition of the two customers' prior balances, which could double-count or drift); the
  source's `merged_into_id` is set to `target_id` and the source is soft-deleted (bypassing AC-10's
  balance guard, since the source's balance is now zero by construction once its rows are reassigned).
- **AC-14** (out-of-scope boundary, stated for the reader): Reassigning a reference from a table this
  task does not build — `sales.customer_id`, `ar_documents.customer_id`, `orders.customer_id`,
  `quotations.customer_id`, `sales_orders.customer_id`, `payments.customer_id`, `journal_lines.
  customer_id` — during a merge is **not** built here, since none of those tables exist yet. Flagged as
  a note for whichever task ships each one (D-10).
- **AC-15** (out-of-scope boundary, D-6): `customers.receivable_account_id` is **not** built by this
  task — `accounts` (Finance, chart of accounts) does not exist. Added by the task that ships `accounts`
  as an expand migration (the column) plus a separate contract migration (the foreign key), following
  T-027/D-3's precedent exactly.

### C. Customer addresses

- **AC-16**: Creating a `customer_addresses` row requires an existing, non-soft-deleted `customer_id`
  and `type` (one of `billing`, `delivery`, `other`); `label`, `address` (JSON), `is_default` (bool),
  `geo` (JSON) are optional.
- **AC-17**: At most one `customer_addresses` row per `(customer_id, type)` pair has `is_default =
  true` — a customer may have one default billing address and one default delivery address
  simultaneously, but never two defaults of the same `type`. Setting a second row's `is_default` to
  `true` for the same `(customer_id, type)` automatically clears the previous default in the same
  transaction.
- **AC-18** (D-16): `customer_addresses` has no `[SOFT]` tag in `docs/database.md` §6.9 — removing a row
  is a real `DELETE`, not a soft delete. Deleting an address that does not exist is refused (not a
  silent no-op), naming the missing id.

### D. Credit ledger

- **AC-19**: `customer_ledger` is append-only (D-2): no update or delete handler is exposed for an
  existing row. Every write is an insert through the one `RecordCreditLedgerEntry` feature this task
  publishes.
- **AC-20**: Creating a `customer_ledger` row requires an existing `customer_id`, `type` (one of
  `opening`, `credit_sale`, `payment`, `refund`, `credit_note`, `debit_note`, `adjustment`, `write_off`),
  `reference_type`, `reference_id`, and exactly one of `debit`/`credit` non-zero and positive — the
  other is zero (mirrors `journal_lines`' `CHECK (debit = 0 OR credit = 0) AND (debit >= 0 AND credit >=
  0)` shape, D-15). `branch_id`, when supplied, must reference an existing branch.
- **AC-21** (idempotent replay, the documented unique key): A second write attempt with the same
  `(reference_type, reference_id, type)` as an existing row is a **no-op that returns the original row
  unchanged**, never a second insert and never an error — the offline-replay idempotency principle
  `docs/architecture.md` §12 states generally ("duplicates by identifier"), applied here to the ledger's
  own documented unique key.
- **AC-22**: Writing a `customer_ledger` row locks the owning customer row before computing
  `balance_after`, matching the transaction shape `docs/database.md` documents (`SELECT customers FOR
  UPDATE, check credit_limit, INSERT ... customer_ledger; UPDATE customers.credit_balance`). `[ENGINE]`
  (D-15): the lock itself is provable only against a real engine — the SQLite stand-in cannot exercise
  row locking under concurrency — so "the lock is taken and holds under a concurrent writer" stays NOT
  VERIFIED under `ENGINE_HOLD`, the same fencing `FP-300` already names for a different domain's lock.
  The arithmetic and the refusal rules below (AC-23/AC-24) are provable today as ordinary handler logic.
- **AC-23**: `balance_after` is computed as the customer's current `credit_balance` plus `debit` minus
  `credit` (an `opening`/`credit_sale`/`debit_note` entry normally carries a `debit`, increasing the
  balance owed; `payment`/`refund`/`credit_note`/`write_off` normally carry a `credit`, decreasing it;
  `adjustment` may carry either). `customers.credit_balance` is updated to equal the new
  `balance_after` in the same transaction as the insert — never left to drift, never recomputed by a
  separate later read.
- **AC-24** (the credit-limit refusal, D-12): A `credit_sale` or `debit_note` entry is refused,
  unconditionally, when the owning customer's `credit_allowed = false` — regardless of amount. When
  `credit_allowed = true`, it is refused when the resulting `balance_after` would exceed `credit_limit`.
  `opening`, `payment`, `refund`, `credit_note`, `adjustment` and `write_off` entries are never refused
  by this rule. No override/approval path is built in this task (D-12) — a caller needing one records
  its own approval elsewhere and either does not attempt the entry, or posts a correcting `adjustment`
  afterward.
- **AC-25** (out-of-scope boundary, D-6): `customer_ledger.ar_document_id` is **not** built by this
  task — `ar_documents` (Finance) does not exist. Added by the task that ships `ar_documents`, following
  T-027/D-3's precedent.

### E. Loyalty

- **AC-26**: Creating a `loyalty_programs` row requires `name`; `earn_rule`, `redeem_rule`, `tier_rules`
  (each JSON) are stored and returned as supplied with no server-side schema validation beyond "valid
  JSON" — this task does not invent a controlled structure for any of the three, mirroring T-027/AC-21's
  treatment of `variant_options`. `expiry_months` is optional; `is_active` defaults to `true`.
- **AC-27**: `loyalty_transactions` is append-only (D-3), the same shape as AC-19: no update or delete
  handler is exposed, every write is an insert through one `RecordLoyaltyTransaction` feature.
- **AC-28**: Creating a `loyalty_transactions` row requires an existing `customer_id` and `program_id`
  (existing, non-soft-deleted `loyalty_programs` row), `type` (one of `earn`, `redeem`, `adjust`,
  `expire`, `transfer`), and a signed `points` value (non-zero). `employee_id`, when supplied, must
  reference an existing employee.
- **AC-29**: Writing a `loyalty_transactions` row locks the owning customer row before computing
  `balance_after`, the identical shape as AC-22. `[ENGINE]`: the lock itself stays NOT VERIFIED under
  `ENGINE_HOLD` for the same reason as AC-22.
- **AC-30**: `balance_after` is computed as the customer's current `loyalty_points` plus `points` (signed
  — `earn`/`transfer`-in is positive, `redeem`/`expire`/`transfer`-out is negative, `adjust` may be
  either). `customers.loyalty_points` is updated to equal the new `balance_after` in the same
  transaction as the insert.
- **AC-31** (the insufficient-balance refusal, D-13): A `redeem` entry is refused when the resulting
  `balance_after` would be negative. `earn`, `adjust`, `expire` and `transfer` entries are never refused
  by this rule.
- **AC-32** (tier recomputation, D-11): After every successful `loyalty_transactions` write,
  `customers.loyalty_tier` is recomputed by evaluating the owning `loyalty_programs.tier_rules` JSON
  against the customer's **new** `loyalty_points` balance (the only balance this schema carries a column
  for), and updated in the same transaction if it changed.
- **AC-33** (out-of-scope boundary, D-6): `loyalty_transactions.sale_id` and its documented `unique
  (sale_id, type) where sale_id not null` idempotency key are **not** built by this task — `sales`
  (FP-233) does not exist. Added by FP-233 as an expand migration (the column and the unique index)
  together with the FK, following T-027/D-3's precedent. Until then, no idempotency key exists for a
  `loyalty_transactions` row beyond its own id — a known, documented limitation, not a defect (D-6).

### F. Customer notes and communications

- **AC-34**: Creating a `customer_notes` row requires an existing `customer_id` and a non-blank `note`;
  `pinned` (bool) defaults to `false`; `follow_up_date` is optional.
- **AC-35**: Creating a `customer_communications` row requires an existing `customer_id`, `channel`
  (one of `call`, `email`, `sms`, `whatsapp`, `visit`, `campaign`), and `direction` (one of `in`, `out`);
  `subject`, `body`, `campaign_ref`, `occurred_at` are optional (`occurred_at` defaults to the time of
  write when not supplied). `employee_id`, when supplied, must reference an existing employee.
- **AC-36** (out-of-scope boundary, D-6): `customer_communications.delivery_id` is **not** built by this
  task — no notification-delivery table exists (Notification module). Added by that module's own task,
  following T-027/D-3's precedent.
- **AC-37** (D-17): Listing `customer_notes` or `customer_communications` for a customer supports
  offset-or-cursor pagination from this task's first version (see Non-functional requirements) — not
  deferred the way T-010's employee list was, per `FP-308`.

### G. Framework integrity and the gate

- **AC-38**: `composer lint`, `composer stan` and `composer deptrac` pass with no new suppressions. The
  two balance calculators (credit, loyalty — exact class names `architect`'s/`dev`'s to choose) are
  published from a new `packages/contracts/src/Customers/` directory (or the equivalent they choose) and
  reached from outside the owning module only through that published contract, never the concrete
  implementation's internals — the same boundary rule T-027 follows.
- **AC-39**: `composer test:fast` and `composer test` both pass, proving every criterion above **except**
  the two `[ENGINE]` items named in AC-22/AC-29 (row-lock-under-concurrency, NOT VERIFIED until `G-1`)
  and the `customer_ledger` `CHECK` constraint named in AC-20/D-15 (NOT VERIFIED until `G-1`, the SQLite
  stand-in cannot express or exercise a `CHECK`) — matching how T-027/AC-42 fenced its own one `[ENGINE]`
  exception.
- **AC-40**: A static test asserts every `DECIMAL` column this task adds matches its documented
  precision (D-14): `DECIMAL(18,4)` for every money column (`credit_limit`, `credit_balance`, `debit`,
  `credit`, `balance_after` on `customer_ledger`, `opening_balance`, `lifetime_value`, `value` on
  `loyalty_transactions`), `DECIMAL(18,2)` for `loyalty_transactions.points` and `balance_after` on
  `loyalty_transactions` — mirroring T-027/AC-44's static precision-assertion idiom.
- **AC-41**: Two pure, DB-free classes exist and are unit-tested independently: one applying a credit
  ledger entry's arithmetic (previous balance, type, debit/credit → new balance, refusal rules from
  AC-23/AC-24), one applying a loyalty transaction's arithmetic (previous balance, type, signed points →
  new balance, refusal rule from AC-31) — mirroring T-027's `SnapshotCalculator`/`UnitLadder` precedent
  for safety-critical arithmetic that must be provable with no database.

## Out of scope

- The pricing ladder reading `customer_groups.discount_percent`/`loyalty_multiplier` or
  `price_list_assignments.scope_type = customer_group` — FP-231 (D-4).
- `customers.receivable_account_id` — `accounts` does not exist (Finance, chart of accounts) — D-6.
- `customer_ledger.ar_document_id` — `ar_documents` does not exist (Finance) — D-6.
- `loyalty_transactions.sale_id` and its `unique (sale_id, type)` idempotency key — `sales` does not
  exist (FP-233, Sales) — D-6.
- `customer_communications.delivery_id` — no notification-delivery table exists (Notification module)
  — D-6.
- Reassigning a future reference (`sales.customer_id`, `ar_documents.customer_id`, `orders.customer_id`,
  `quotations.customer_id`, `sales_orders.customer_id`, `payments.customer_id`, `journal_lines.
  customer_id`) during a customer merge — none of those tables exist yet (AC-14).
- Automatic duplicate-customer detection — no matching algorithm is documented (D-10).
- A manager-override/approval path for a credit entry that would exceed the limit — `approval_requests`
  belongs to Sales (FP-233/FP-234), not this task (D-12).
- The offline replica/sync mechanics for `customer`/`customer_group` entity types `docs/architecture.md`
  §12 names — the Sync module's concern, not named in FP-232.
- Any HTTP/admin-UI surface for customer management — this task ships the domain layer (migrations,
  models, handlers/contracts) a future admin-UI task and API layer read; no routes are added here,
  matching T-027/T-026/T-020's precedent for non-HTTP-first tasks.
- Maintaining `customers.first_sale_at`/`last_sale_at`/`lifetime_value` from real sales activity —
  nothing that completes a sale exists yet (FP-233) — AC-11.

## Non-functional requirements

- **Correctness over convenience in balance arithmetic**: neither the credit ledger nor the loyalty
  ledger balance is ever recomputed by two different code paths — one pure calculator class per domain
  (AC-41), the same discipline `docs/architecture.md` §21 and T-006/T-027 already established for
  quantity ("one ladder, one caller").
- **Append-only integrity**: no update or delete route exists for a `customer_ledger` or
  `loyalty_transactions` row (AC-19/AC-27); corrections are new rows (`adjustment`/`write_off`,
  `adjust`/`expire`), never edits to an existing one.
- **Isolation from other tenants**: nothing this task adds reads or writes across tenant databases —
  every table and handler operates on the single tenant connection, mirroring T-022's permanent
  cross-tenant sweep (no new route, queued job, cache key, or plugin hook is introduced that would need
  a new sweep entry).
- **Pagination from day one** (D-17/AC-37): every list feature this task ships supports offset-or-cursor
  pagination in its first version, so FP-308's gap (an employee list reachable only on its first page)
  is not repeated here.
- **Forward compatibility of deferred columns**: every column deferred by D-6 is deferred entirely — no
  key-less placeholder column is added now that would still need its own later contract migration to add
  the foreign key, matching T-027's own corrected reasoning (D-13 there): building the column today would
  not save the later step either way.

## Assumptions

- `credit_limit`/`payment_terms_days` are inherited from the customer's group at creation time as a
  one-time copy, not a live link (AC-7) — a future admin UI may expose "reset to group default" as its
  own explicit action without this task's schema changing.
- `loyalty_tier` is derived from `tier_rules` evaluated against the current `loyalty_points` balance, not
  a separate lifetime-earned total (D-11) — reversible if the owner later states tiering should be based
  on lifetime spend or lifetime points earned.
- `version` columns are plain optimistic-concurrency tokens, the standard interpretation this codebase
  already uses elsewhere for a bare `version` column (e.g. `restaurant_tables.version`,
  `payment_methods.version`).
- No manager-override path exists yet for an over-limit credit entry (D-12); a future caller handles
  that by not attempting the entry, or by posting a correcting `adjustment` row, until Sales'
  `approval_requests` mechanism can drive one.

## Open questions

None — every design question this task raised was decided and logged in
`docs/decisions/T-028-customers.md` (`ba`'s own calls), per the team's autonomous-mode protocol.
