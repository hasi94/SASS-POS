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

**Revision note.** This revision folds in `architect`'s plan-stage review findings F-1 to F-13 against
real source (`docs/plans/T-028-customers.md` §0.1/§0.2, committed `ab78fa3`), each traceable below by
its `F-n` tag: F-1 (`custom_field_definitions` doesn't exist — same error T-027's own F-1 caught), F-2
(the deferred-column mechanism and count, plus `delivery_id`'s BIGINT type mismatch), F-3 (the merge
must not rewrite append-only ledger rows — compensating entries instead), F-4 (a replay with a
different payload is refused, not silently dropped), F-5 (`tier_rules` needs a validated minimal
shape), F-6 (`loyalty_programs` has no soft-delete — use `is_active`, gating both `earn` and `redeem`),
F-7 (loyalty points are `DECIMAL(18,4)`, not `(18,2)` — and AC-40's precision map was incomplete), F-8
(currency must match between a ledger entry and its customer), F-9 (hard debit/credit sides and signs,
not "normally"), F-10 (`credit_allowed` inherits from the group too; `credit_limit` is never null),
F-11 (`opening_balance` must actually post an opening ledger entry), F-12 (`merged_into_id` is written
only by the merge operation), F-13 (ledger/loyalty writes require a live, unmerged customer). Five
further informational findings (F-14 to F-19, not requiring a decision but recorded) are incorporated
where straightforward: F-14 (`[SYNC]` columns built inert), F-15 (an optional caller-supplied id gives
loyalty a real replay key today), F-16 (corrects the `version` precedent cited in Assumptions —
`restaurant_tables`/`payment_methods` do not exist; the real precedent is `products.version`, T-027),
F-17 (confirms `customer_groups.code` uniqueness is this spec's own addition, accepted), F-19
(soft-deleting the default group clears `is_default` in the same transaction). F-18 needs no edit —
noted for the reader in AC-32.

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
  sale_id` → `sales` (FP-233, Sales, TODO); `customer_communications.delivery_id` → `deliveries`
  (§6.16, TODO). None of the four are built by this task (D-6, corrected by F-2): each is deferred as an
  **expand migration (the column) followed by a separate contract migration (the foreign key)**, not a
  single combined step — a foreign key onto an already-existing table is a contract-phase change under
  this codebase's migration source rules, the identical correction T-027's own F-2 made. `delivery_id`
  carries a further wrinkle worth recording now so the owning task does not have to rediscover it:
  `docs/database.md` §6.16 makes `deliveries.id` a **BIGINT AUTO** primary key, not a ULID, so
  `delivery_id` will be `BIGINT UNSIGNED` — the one column among this task's deferred set that will
  **not** match the ULID shape every other identifier column in this schema carries.
- **`custom_field_definitions` does not exist** (corrected per F-1 — the earlier draft of this spec
  wrongly assumed it did, repeating the exact error T-027's own F-1 caught there): T-008 D-4
  deliberately left it out of the tenant baseline and nothing since has built it. `customers.
  custom_fields` is **not** built by this task; a `custom_fields` field on a create/update command is
  refused as unknown, the same shape T-027 AC-27a already uses for `products.custom_fields`.
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
  T-027's `product_barcodes.is_primary` precedent — never leaving two defaults. **Soft-deleting the
  current default group clears its `is_default` in the same transaction (F-19)** — the earlier draft
  left this unstated, which would let a soft-deleted row sit as "the default" indefinitely, defeating
  AC-3's own guard the moment a reader forgets to filter deleted rows out first.
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
  `blocked`, `inactive`). `currency`, when supplied, is a 3-letter code stored as given, with no
  validation against a currency table (none exists). **`[SYNC]` columns (`origin`, `terminal_id`,
  `device_id`, `client_seq`, `client_created_at`, `sync_status`, `synced_at`) are built present but
  inert (F-14)**: `origin` defaults to `online`, `sync_status` defaults to `synced`, every other `[SYNC]`
  field defaults to null, none carries a foreign key (§3.1's tag states none), and no handler in this
  task reads or branches on any of them — they exist so the column shape matches §6.9 exactly and the
  Sync module (not built, not named in FP-232) has a column to write into later rather than a migration
  it would have to add first.
- **AC-6a** (F-8): Changing `customers.currency` through `UpdateCustomer` is accepted while no
  `customer_ledger` row exists yet for that customer, and refused once at least one does —
  reinterpreting an existing balance under a different currency after the fact would silently change
  what every prior ledger entry meant.
- **AC-7** (corrected per F-10): When `group_id` is supplied and `credit_limit`/`payment_terms_days`/
  `credit_allowed` are **not** supplied on the create request, each is inherited from the group's
  `default_credit_limit`/`default_payment_terms_days`/`credit_allowed` at the moment of creation (a
  one-time copy, not a live link — changing the group's defaults later never retroactively changes an
  existing customer's own values). The earlier draft of this spec copied only the limit and terms,
  which would leave a customer created in a credit-enabled group refused credit until someone set the
  flag by hand — `credit_allowed` is copied the same way. `credit_allowed` defaults to `false` when
  there is no `group_id`. **`customers.credit_limit` is `NOT NULL DEFAULT 0`** — "no limit" is not a
  representable value in this schema; a customer who should have no practical ceiling is given a large
  literal limit, not a null one. A customer marked `credit_allowed = true` with `credit_limit = 0` is
  refused every credit sale (AC-24) — the safe direction when no limit was ever actually set.
- **AC-7a** (new, F-11): A non-zero `opening_balance` supplied on `CreateCustomer` **posts an opening
  entry through the credit ledger writer** (section D), reference `customer`/the new customer's id, in
  the same transaction as the customer row itself — not a free-standing column nothing reads, which
  would be exactly the two-sources-of-truth problem D-2 exists to prevent. A non-zero `opening_balance`
  with no `currency` set is refused (the opening entry needs one, per F-8/AC-20). `opening_balance`
  itself is immutable after creation: an update request that supplies it is refused in full, the same
  shape as AC-8.
- **AC-8** (corrected per F-12): `credit_balance`, `loyalty_points`, `loyalty_tier` and
  `merged_into_id` are **maintained-only** columns (D-2/D-3/D-8/D-12-F12): a create or update request
  that supplies any of the four is refused **in full** (the whole request, not a partial apply), naming
  the offending field(s) — mirroring T-027's AC-22 (`stock_unit_id` immutability) shape. On update,
  `opening_balance` is refused the same way (AC-7a). Only the ledger-writing features in sections D and
  E may change `credit_balance`/`loyalty_points`/`loyalty_tier`, and only inside the same transaction as
  the ledger/loyalty row that produces the new value; only `MergeCustomers` (AC-13) may set
  `merged_into_id`.
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
- **AC-12** (corrected per F-12): `possible_duplicate_of` is a plain nullable self-referencing field,
  settable manually on create/update; it must reference another existing customer, never the row
  itself (refused as a self-reference). `merged_into_id` is **not** settable through the ordinary
  customer create/update handler at all (AC-8) — setting it by hand would mark a merge that moved
  nothing. Only `MergeCustomers` (AC-13) ever writes it. No automatic duplicate-detection algorithm is
  built (D-10) — nothing documents a matching rule.
- **AC-13** (the merge feature, rewritten per F-3 — **the merge never rewrites an existing ledger
  row**): `MergeCustomers(source_id, target_id)` is refused when `source_id = target_id` (`self_merge`),
  when either id does not exist, or when either the source or the target is not live or already merged
  (`merged_into_id` already set on either — a dead record cannot absorb another, and a merge cannot
  chain onto one). The earlier draft of this criterion reassigned `customer_ledger.customer_id` and
  `loyalty_transactions.customer_id` directly, which would **edit an append-only financial row** —
  contradicting this spec's own AC-19/AC-27 (append-only) and corrupting every moved row's
  `balance_after`, which describes the *source's* running balance, not the target's. **The merge moves
  the balance, not the rows, through the same writers every other ledger/loyalty entry already uses**:
  - if the source's `credit_balance` is non-zero, the currencies of the two customers must match
    (refused otherwise, `currency_mismatch` — F-8) and the credit ledger writer posts a compensating
    **`adjustment`** pair: one entry on the source that brings its balance to exactly zero (reference
    `customer_merge_out` / the source's id), and a mirroring entry on the target for the same amount
    (reference `customer_merge_in` / the source's id). Both are `adjustment` entries, so neither is
    ever refused by the credit-limit rule (AC-24);
  - if the source's `loyalty_points` is non-zero, the loyalty ledger writer posts a compensating
    **`transfer`** pair (a negative entry zeroing the source, a positive entry crediting the target)
    under the loyalty program of the source's most recent loyalty transaction — the enum value exists
    for exactly this case, and `transfer` is never subject to the redeem-only insufficient-balance
    refusal (AC-31). Both customers' `loyalty_tier` is recomputed afterward (AC-32);
  - `customer_addresses`, `customer_notes` and `customer_communications` rows carry no append-only
    constraint, so these three **do** move by a direct `customer_id` reassignment. Before an address
    moves, the source's `is_default` is cleared for any `type` the target already has a default address
    for, so the unique default-per-type rule (AC-17) is never violated by the move;
  - the source's `customer_ledger` and `loyalty_transactions` rows **stay on the source** — they are
    now part of the source's permanently-closed history (ending at the `customer_merge_out`/`transfer`-
    out row), not moved;
  - the source's `merged_into_id` is set to `target_id` and the source is soft-deleted. Its balances are
    zero by construction at that point (the compensating entries guarantee it), so AC-10's balance guard
    is **satisfied**, not bypassed.
- **AC-14** (out-of-scope boundary, stated for the reader): Reassigning a reference from a table this
  task does not build — `sales.customer_id`, `ar_documents.customer_id`, `orders.customer_id`,
  `quotations.customer_id`, `sales_orders.customer_id`, `payments.customer_id`, `journal_lines.
  customer_id` — during a merge is **not** built here, since none of those tables exist yet. Flagged as
  a note for whichever task ships each one (D-10). Reading a merged customer's full ledger/loyalty
  history (the target's rows, plus the source's rows up to and including its `merge_out` row) as one
  statement is left to a future reporting/admin-UI task (FP-240 or similar), not built here.
- **AC-15** (out-of-scope boundary, D-6, mechanism corrected per F-2): `customers.receivable_account_id`
  is **not** built by this task — `accounts` (Finance, chart of accounts) does not exist. Added by the
  task that ships `accounts`, as an expand migration (the column) followed by a **separate** contract
  migration (the foreign key, with a data-clean pre-check) — not a single combined step, since a
  foreign key onto an already-existing table is a contract-phase change under this codebase's migration
  source rules, following T-027/D-3's precedent exactly.

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
- **AC-20**: Creating a `customer_ledger` row requires an existing, **live and unmerged** customer
  (`customer_not_live` otherwise — F-13), `type` (one of `opening`, `credit_sale`, `payment`, `refund`,
  `credit_note`, `debit_note`, `adjustment`, `write_off`), `reference_type`, `reference_id`, and exactly
  one of `debit`/`credit` non-zero and positive — the other is zero (mirrors `journal_lines`' `CHECK
  (debit = 0 OR credit = 0) AND (debit >= 0 AND credit >= 0)` shape, D-15). `branch_id`/`employee_id`,
  when supplied, must each reference an existing row. **Currency (F-8):** the entry's currency defaults
  to the customer's own `currency` when not supplied; a different value is refused (`currency_mismatch`,
  naming both); a customer whose own `currency` is null cannot take any ledger entry at all
  (`currency_required`). **Debit/credit side is a hard rule per type, not inferred (F-9)** — not
  "normally," as the earlier draft of this criterion stated:
  - **debit only:** `credit_sale`, `debit_note` — supplying `credit` for either is refused
    (`wrong_side`);
  - **credit only:** `payment`, `refund`, `credit_note`, `write_off` — supplying `debit` for any of
    these is refused (`wrong_side`);
  - **either side:** `opening` (a customer may legitimately start in credit) and `adjustment` (a
    correction can run either way) — no side restriction for these two types.
  `credit_sale`/`debit_note` additionally require the customer's `status = active`
  (`customer_not_active` otherwise — F-13); every other type is allowed regardless of `status` (a
  blocked customer can still have a payment or refund recorded against them).
- **AC-21** (idempotent replay, the documented unique key, corrected per F-4): A second write attempt
  with the same `(reference_type, reference_id, type)` as an existing row, where `customer_id`, `debit`,
  `credit` and `currency` **all match** the stored row, is a **no-op that returns the original row
  unchanged** (`replayed = true`), never a second insert and never an error — the offline-replay
  idempotency principle `docs/architecture.md` §12 states generally ("duplicates by identifier"),
  applied here to the ledger's own documented unique key. **When any of those four fields differs
  (F-4)**, the earlier draft's "always return the original, never an error" rule would silently drop a
  second payment recorded with a different amount — this is refused instead, as `replay_conflict`,
  naming the existing row's id, following `T-021`'s idempotency precedent (`RequireIdempotencyKey`:
  a reused key whose fingerprint differs is a refusal, not a silent success). A concurrent double-write
  that both miss the pre-check and both attempt the insert is resolved by the unique index itself: the
  losing insert's constraint violation is caught, the row re-read, and the same comparison applied.
- **AC-22**: Writing a `customer_ledger` row locks the owning customer row before computing
  `balance_after`, matching the transaction shape `docs/database.md` documents (`SELECT customers FOR
  UPDATE, check credit_limit, INSERT ... customer_ledger; UPDATE customers.credit_balance`). `[ENGINE]`
  (D-15): the lock itself is provable only against a real engine — the SQLite stand-in cannot exercise
  row locking under concurrency — so "the lock is taken and holds under a concurrent writer" stays NOT
  VERIFIED under `ENGINE_HOLD`, the same fencing `FP-300` already names for a different domain's lock.
  The arithmetic and the refusal rules below (AC-23/AC-24) are provable today as ordinary handler logic.
- **AC-23**: `balance_after` is computed as the customer's current `credit_balance` plus `debit` minus
  `credit`, using the hard per-type side rule AC-20 states, not an inferred "normal" side (corrected per
  F-9): `credit_sale`/`debit_note` always carry `debit` (increasing the balance owed); `payment`/
  `refund`/`credit_note`/`write_off` always carry `credit` (decreasing it); `opening`/`adjustment` may
  carry either. `customers.credit_balance` is updated to equal the new `balance_after` in the same
  transaction as the insert — never left to drift, never recomputed by a separate later read.
- **AC-24** (the credit-limit refusal, D-12): A `credit_sale` or `debit_note` entry is refused,
  unconditionally, when the owning customer's `credit_allowed = false` — regardless of amount. When
  `credit_allowed = true`, it is refused when the resulting `balance_after` would exceed `credit_limit`
  (a resulting balance **equal to** the limit is accepted — the check is `>`, not `>=`; this is checked
  against the resulting balance, never the amount alone, so a customer at 80 with a limit of 100 is
  refused a sale of 30, not only a sale of 21+). `opening`, `payment`, `refund`, `credit_note`,
  `adjustment` and `write_off` entries are never refused by this rule. No override/approval path is
  built in this task (D-12) — a caller needing one records its own approval elsewhere and either does
  not attempt the entry, or posts a correcting `adjustment` afterward.
- **AC-25** (out-of-scope boundary, D-6, mechanism corrected per F-2): `customer_ledger.ar_document_id`
  is **not** built by this task — `ar_documents` (Finance) does not exist. Added by the task that ships
  `ar_documents`, as an expand migration (the column) followed by a separate contract migration (the
  foreign key), following T-027/D-3's precedent.

### E. Loyalty

- **AC-26** (corrected per F-5 — `tier_rules` needs a structure, since AC-32 has to evaluate it):
  Creating a `loyalty_programs` row requires `name`; `earn_rule` and `redeem_rule` (each JSON) are
  stored and returned as supplied with no server-side schema validation beyond "valid JSON" — this task
  does not invent a controlled structure for either, mirroring T-027/AC-21's treatment of
  `variant_options`, because nothing in this task evaluates either (the caller supplies `points`
  directly, AC-28). **`tier_rules` is validated at write time against one minimal shape**: either
  `null`, or a non-empty list of `{"tier": <non-blank string, ≤ 50 characters>, "min_points": <a
  non-negative decimal string, ≤ 4 decimal places>}` objects, where every `tier` name is unique within
  the list and every `min_points` value is unique within the list — any other shape (a missing key, a
  duplicate name, a duplicate threshold, a negative threshold, a non-list, an empty list) is refused
  before the row is written. `expiry_months` is optional; `is_active` defaults to `true`.
- **AC-27**: `loyalty_transactions` is append-only (D-3), the same shape as AC-19: no update or delete
  handler is exposed, every write is an insert through one `RecordLoyaltyTransaction` feature.
- **AC-28** (corrected per F-6/F-13): Creating a `loyalty_transactions` row requires an existing,
  **live and unmerged** customer (`customer_not_live` otherwise — F-13) and an existing `program_id`.
  `loyalty_programs` carries **no `[SOFT]` tag** in `docs/database.md` §6.9 — the earlier draft of this
  criterion wrongly required a "non-soft-deleted" program, which has no column to read. A program is
  instead retired by setting `is_active = false`; **an inactive program refuses both `earn` and
  `redeem`** (`program_inactive`) — the earlier draft's omission of `redeem` from this gate is corrected
  here, since a retired program should not pay out any further either. `adjust`, `expire` and
  `transfer` remain allowed against an inactive program, so corrections and a customer merge (AC-13)
  still work even after a program is retired. `type` is one of `earn`, `redeem`, `adjust`, `expire`,
  `transfer`, and a signed `points` value (non-zero). `employee_id`, when supplied, must reference an
  existing employee.
- **AC-29**: Writing a `loyalty_transactions` row locks the owning customer row before computing
  `balance_after`, the identical shape as AC-22. `[ENGINE]`: the lock itself stays NOT VERIFIED under
  `ENGINE_HOLD` for the same reason as AC-22.
- **AC-30** (corrected per F-9): `balance_after` is computed as the customer's current `loyalty_points`
  plus `points`. The sign is a hard rule per type, not inferred — the earlier draft's "normally" is
  corrected: `earn` must be positive; `redeem` must be negative; `expire` must be negative; `adjust` and
  `transfer` may carry either sign. A value with the wrong sign for its type is refused (`wrong_sign`).
  `customers.loyalty_points` is updated to equal the new `balance_after` in the same transaction as the
  insert.
- **AC-31** (the insufficient-balance refusal, D-13): A `redeem` entry is refused when the resulting
  `balance_after` would be negative (redeeming down to exactly zero is accepted). `earn`, `adjust`,
  `expire` and `transfer` entries are never refused by this rule, and may legitimately take the balance
  below zero (`expire`/`adjust` correcting an over-award, `transfer` as part of AC-13's merge).
- **AC-32** (tier recomputation, D-11, now possible per F-5's validated shape): After every successful
  `loyalty_transactions` write, `customers.loyalty_tier` is recomputed by evaluating the **validated**
  `tier_rules` of the `loyalty_programs` row named by that write's own `program_id` (F-18: not some
  notion of "the customer's one program" — a customer can accrue under more than one program, and this
  task invents no "one active program" rule) against the customer's **new** `loyalty_points` balance
  (the only balance this schema carries a column for): the tier is the entry with the greatest
  `min_points` at or below the new balance; no qualifying entry, or `null` rules, gives tier `null`.
  `customers.loyalty_tier` is updated in the same transaction if it changed.
- **AC-33** (out-of-scope boundary, D-6, mechanism corrected per F-2, narrowed per F-15):
  `loyalty_transactions.sale_id` and its documented `unique (sale_id, type) where sale_id not null`
  idempotency key are **not** built by this task — `sales` (FP-233) does not exist. Added by FP-233 as
  an expand migration (the column and the unique index — `docs/database.md`'s own partial-index wording
  needs no partial-index feature on either engine, since NULLs are already distinct under a plain
  `unique(sale_id, type)`) followed by a separate contract migration for the FK, following T-027/D-3's
  precedent. **Until then, `RecordLoyaltyTransaction` accepts an optional caller-supplied `id` (a
  ULID) as its own replay key** (F-15): the same id with the same payload is a replay (`replayed =
  true`, no second row); the same id with a different payload is `replay_conflict`, the identical shape
  AC-21/F-4 uses for the credit ledger. This gives loyalty a real, if narrower, idempotency guarantee
  today rather than none at all — the earlier draft's "a known, documented limitation" is smaller than
  originally stated, though still real: a caller that omits `id` still has nothing to replay against.

### F. Customer notes and communications

- **AC-34**: Creating a `customer_notes` row requires an existing `customer_id` and a non-blank `note`;
  `pinned` (bool) defaults to `false`; `follow_up_date` is optional.
- **AC-35**: Creating a `customer_communications` row requires an existing `customer_id`, `channel`
  (one of `call`, `email`, `sms`, `whatsapp`, `visit`, `campaign`), and `direction` (one of `in`, `out`);
  `subject`, `body`, `campaign_ref`, `occurred_at` are optional (`occurred_at` defaults to the time of
  write when not supplied). `employee_id`, when supplied, must reference an existing employee.
- **AC-36** (out-of-scope boundary, D-6, mechanism corrected per F-2): `customer_communications.
  delivery_id` is **not** built by this task — `deliveries` (§6.16, Notification/Printing) does not
  exist. Added by that module's own task, as an expand migration followed by a separate contract
  migration, following T-027/D-3's precedent — and that task's own column will be `BIGINT UNSIGNED`,
  not a ULID, since `docs/database.md` §6.16 makes `deliveries.id` a `BIGINT AUTO` key (F-2).
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
- **AC-40** (corrected per F-7 — the earlier draft's list was both wrong on points' scale and
  incomplete): A static test asserts every `DECIMAL` column this task adds matches its documented
  precision (D-14, corrected), against exactly the four precision/scale pairs `TenantDecimalPrecisionTest`
  already allows (§3.2) — no fifth pair is introduced:
  - **`DECIMAL(18,4)`**: `customer_groups.default_credit_limit`; `customers.credit_limit`,
    `credit_balance`, `opening_balance`, `lifetime_value`, `loyalty_points`; `customer_ledger.debit`,
    `credit`, `balance_after`; `loyalty_transactions.points`, `value`, `balance_after`. (`points` is
    `DECIMAL(18,4)`, not `(18,2)` as an earlier draft stated — `(18,2)` is not one of the four allowed
    pairs and would turn `TenantDecimalPrecisionTest` red; D-14's own reasoning for a fractional-capable
    column is satisfied just as well at scale 4, consistent with every other quantity column in this
    schema.)
  - **`DECIMAL(9,4)`** (the rate/percentage pair): `customer_groups.discount_percent`,
    `loyalty_multiplier`.
  - Mirrors T-027/AC-44's static precision-assertion idiom.
- **AC-41**: Two pure, DB-free classes exist and are unit-tested independently: one applying a credit
  ledger entry's arithmetic (previous balance, type, debit/credit → new balance, refusal rules from
  AC-23/AC-24), one applying a loyalty transaction's arithmetic (previous balance, type, signed points →
  new balance, refusal rule from AC-31) — mirroring T-027's `SnapshotCalculator`/`UnitLadder` precedent
  for safety-critical arithmetic that must be provable with no database.

## Out of scope

- The pricing ladder reading `customer_groups.discount_percent`/`loyalty_multiplier` or
  `price_list_assignments.scope_type = customer_group` — FP-231 (D-4).
- `customers.receivable_account_id` — `accounts` does not exist (Finance, chart of accounts); deferred
  as an expand migration (the column) plus a separate contract migration (the key) — D-6, F-2.
- `customer_ledger.ar_document_id` — `ar_documents` does not exist (Finance); same deferred shape —
  D-6, F-2.
- `loyalty_transactions.sale_id` and its `unique (sale_id, type)` idempotency key — `sales` does not
  exist (FP-233, Sales); same deferred shape — D-6, F-2. A caller-supplied `id` stands in as a replay
  key in the meantime (F-15, AC-33).
- `customer_communications.delivery_id` — `deliveries` (§6.16) does not exist; same deferred shape,
  and the owning task's column will be `BIGINT UNSIGNED`, not a ULID, once `deliveries` ships (D-6,
  F-2).
- `customers.custom_fields` — `custom_field_definitions` does not exist (T-008 D-4 left it out of the
  baseline); deferred to its own task, following T-027/AC-27a's precedent (F-1).
- Reassigning a future reference (`sales.customer_id`, `ar_documents.customer_id`, `orders.customer_id`,
  `quotations.customer_id`, `sales_orders.customer_id`, `payments.customer_id`, `journal_lines.
  customer_id`) during a customer merge — none of those tables exist yet (AC-14).
- Automatic duplicate-customer detection — no matching algorithm is documented (D-10).
- A manager-override/approval path for a credit entry that would exceed the limit — `approval_requests`
  belongs to Sales (FP-233/FP-234), not this task (D-12).
- The offline replica/sync mechanics for `customer`/`customer_group` entity types `docs/architecture.md`
  §12 names — the Sync module's concern, not named in FP-232. The `[SYNC]` columns this task builds
  (AC-6) are present but inert (F-14) for exactly this reason.
- Any HTTP/admin-UI surface for customer management — this task ships the domain layer (migrations,
  models, handlers/contracts) a future admin-UI task and API layer read; no routes are added here,
  matching T-027/T-026/T-020's precedent for non-HTTP-first tasks.
- Maintaining `customers.first_sale_at`/`last_sale_at`/`lifetime_value` from real sales activity —
  nothing that completes a sale exists yet (FP-233) — AC-11.
- Evaluating `loyalty_programs.earn_rule`/`redeem_rule` to compute a points amount from a currency
  amount — the caller supplies `points` directly (AC-28); a future earn engine (FP-233 or FP-231) that
  converts money to points rounds to 4 decimals before calling the loyalty ledger, since this task's
  calculator never rounds (`architect`'s plan, Ruling 3).
- Reading a merged customer's full ledger/loyalty history as one statement spanning the source's and
  the target's rows — a future reporting/admin-UI task's concern (AC-14).

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
- **Single-currency arithmetic** (F-8): a `customer_ledger` entry's currency must match its customer's
  own `currency` — nothing in this task adds two different currencies together into one balance. A
  customer with no `currency` set cannot take any ledger entry at all, and `UpdateCustomer` refuses a
  currency change once any ledger row already exists for that customer, so a balance is never
  reinterpreted under a different currency after the fact.
- **No inferred sign or side** (F-9): every ledger entry's debit/credit side, and every loyalty entry's
  point sign, is a hard rule keyed on `type`, enforced by the pure calculator (AC-41) before any row is
  written — never left to the caller's intent or a comment saying "normally."

## Assumptions

- `credit_limit`/`payment_terms_days` are inherited from the customer's group at creation time as a
  one-time copy, not a live link (AC-7) — a future admin UI may expose "reset to group default" as its
  own explicit action without this task's schema changing.
- `loyalty_tier` is derived from `tier_rules` evaluated against the current `loyalty_points` balance, not
  a separate lifetime-earned total (D-11) — reversible if the owner later states tiering should be based
  on lifetime spend or lifetime points earned.
- `version` columns are plain optimistic-concurrency tokens, the standard interpretation this codebase
  already uses elsewhere for a bare `version` column — corrected per F-16: the earlier draft cited
  `restaurant_tables.version` and `payment_methods.version` as codebase precedents, but neither table
  exists; the real precedent is `products.version` (T-027).
- No manager-override path exists yet for an over-limit credit entry (D-12); a future caller handles
  that by not attempting the entry, or by posting a correcting `adjustment` row, until Sales'
  `approval_requests` mechanism can drive one.
- `customers.credit_limit`/`loyalty_points` writes never apply `customers.version`'s own optimistic lock
  — a ledger/loyalty write updates `credit_balance`/`loyalty_points`/`loyalty_tier` without touching
  `version`, since `version` guards an *admin's* edit against another concurrent admin edit (AC-4), and
  those edits can never touch the maintained columns in the first place (AC-8); incrementing it on every
  sale would make an unrelated admin save fail as stale under ordinary sales traffic.
- `customer_groups.code` is made unique by this task even though `docs/database.md` §6.9 does not mark
  it so explicitly (only `customers.code` is marked unique there) — confirmed per F-17 as this spec's
  own reasonable addition, the identical shape T-027/F-12 already used for `brands`.

## Open questions

None — every design question this task raised was decided and logged in
`docs/decisions/T-028-customers.md` (`ba`'s own calls), per the team's autonomous-mode protocol.
