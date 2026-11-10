# T-030: Sales — cart, tenders, hold and recall, refunds, voids, cash shifts

Spec written **spec-ahead** of two unfinished dependencies: T-029/FP-231 (Taxes, price lists,
discounts — `packages/pricing`) and T-028/FP-232 (Customers — `packages/customers`), both still
being implemented on their own branches. No code is built from this spec until both are DONE
and this branch is rebased onto their tip. Every place this spec names either package's contract,
it is naming intent, not a literal signature, and `dev` reconciles against the real shipped
interface before writing code (see decisions D-4/D-5).

Backlog: FP-233, `docs/backlog.md`. Decisions: `docs/decisions/T-030-sales.md` (D-1 to D-28).
Design inputs read directly: `docs/architecture.md` v0.5 §1, §3, §8, §11, §12, §15, §21-22;
`docs/database.md` v0.5 §3, §6.4 to §6.9 (Catalog, Pricing/tax, Inventory's unit-inheritance
rule, Sales/POS, Payments, Customers); T-027's decisions/plan (`UnitResolver`, `SnapshotCalculator`,
the published `Contracts\Catalog\UnitResolver`); T-029's decisions/plan (`PricingEngine`, the
six-step price ladder, discount calculator, fixture discipline); T-028's decisions (the customer
credit ledger, the `loyalty_transactions.sale_id` deferral it left for this task).

## Problem / goal

A cashier needs to build a sale (a cart of priced lines), take one or more payments against it
(cash, card-as-reference, store credit), park and resume a sale without losing it, and correct a
mistake after the fact (void before anything is final, or refund after it is) — all while a
supervisor can account for the cash in the drawer across a shift. None of this exists yet: the
schema is fully designed (`docs/database.md` §6.7/§6.8) but no code reads or writes any of it.
This task builds that domain logic as backend slices the till (FP-236, a separate frontend task)
and the offline sync layer (FP-237, separate) will call once they exist.

This task deliberately does **not** build: real-time stock validation/decrement (FP-234, its own
backlog row), COGS/GL posting (Finance, FP-260/FP-263, not yet built), the full payment-gateway/
settlement apparatus (§6.8, no live gateway at launch), quotations/sales orders, the restaurant
domain, or `exchange`-type sales. See Out of scope and `docs/decisions/T-030-sales.md` D-1, D-6,
D-7, D-10 for why each is a clean boundary rather than an oversight.

## Users affected

- **Cashiers**, who build and complete a sale, hold and recall a parked sale, open and close their
  own cash shift, and request a supervisor's approval when a rule requires one.
- **Supervisors/managers**, who decide (approve or reject) a pending approval request by PIN, and
  who reconcile a closed shift.
- **Customers**, whose purchase history, store-credit balance and (where a later caller chooses to
  record it) loyalty points are affected by a completed sale, a void, or a refund.
- **Downstream modules not yet built**: Inventory (FP-250)/stock-validation (FP-234) read this
  task's completed sales to know what to deplete once they exist; Finance (FP-260) reads them to
  post a ledger; Commission (FP-255) reads `sale_items.employee_id` for category-based commission.

## Current behaviour

None. No migration, model, handler or route for any table in `docs/database.md` §6.7 ("Sales and
POS", limited to the tables named in Decisions D-10) or the subset of §6.8 ("Payments") named in
D-7 exists in the shipped tree. This is new functionality, not a change to existing behaviour.

## User stories

- As a cashier, I want to build a cart of priced lines in any unit the product allows, so that I
  can ring up a sale the way the customer asked for it (a case, not twenty-four singles).
- As a cashier, I want to take more than one form of payment on one sale, so that a customer who
  pays part cash and part card is not forced into two separate transactions.
- As a cashier, I want to park an in-progress sale and come back to it later — possibly from a
  different till — so that a customer who steps away does not lose their cart, and a queue behind
  them is not blocked.
- As a cashier, I want to void a sale I have not yet completed, so that an abandoned or mis-rung
  cart leaves no financial trace.
- As a cashier or manager, I want to refund a completed sale, fully or in part, so that a returned
  item is handled as its own document without editing the original receipt.
- As a cashier, I want a supervisor's PIN to unlock an action the rules restrict (voiding a
  completed sale, an over-threshold discount, a price override), so that sensitive corrections are
  never a unilateral cashier action.
- As a cashier, I want to open my cash drawer at the start of a shift and close it at the end with
  a counted-cash figure, so that any shortage or overage is visible the same day.
- As a supervisor, I want to mark a closed shift reconciled, so that the shift's own lifecycle has
  a clear endpoint even before a real bank-reconciliation step exists.

## Acceptance criteria

Decimal/date conventions throughout: every money and quantity value is `DECIMAL(18,4)` (D-27);
every refusal is a named code, writing nothing, with the existing field(s) and reason stated; every
list feature supports offset-or-cursor pagination from day one (D-26); every write is attributed to
an actor id and audited, one row per write, matching the T-027/T-028/T-029 slice shape.

### A — Sale header and cart lines

- AC-1: `CreateSale` opens a new `sales` row with `status = draft`, `branch_id`, `location_id`,
  `terminal_id`, `employee_id` (the cashier), `business_date` (server date — D-23's scope; no
  client-submitted date is accepted by this task), and an optional `customer_id`. It refuses
  `no_open_shift` when the terminal has no `cash_shifts` row with `status = open` (D-22).
- AC-2: `AddSaleLine` adds a `sale_items` row carrying the full `[UOM]` quad — `unit_id` (any unit
  convertible to the product's stock unit), the resolved `unit_factor` and `base_quantity` from
  `Contracts\Catalog\UnitResolver::snapshot()` (never hand-computed) — plus `product_id`, and the
  snapshot columns `product_name`, `sku`, `unit_code`, `category_id`, `brand_id` taken from the
  product at the moment the line is added (so a later rename/recategorisation does not change an
  existing line, matching `docs/database.md` §6.7's own stated reason for those columns).
- AC-3: `AddSaleLine` refuses `unit_unresolvable` when `UnitResolver::snapshot()` throws (the line
  unit does not convert to the product's stock unit at all), naming the requested unit and the
  product's stock unit.
- AC-4: Every `AddSaleLine`/`UpdateSaleLine` call prices the line by calling `Contracts\Pricing\
  PricingEngine::quoteLine()` (D-4) with the line's `UnitSnapshot`, the sale's `customer_id`/
  `branch_id` and the sale's `business_date`, and stores the returned `listPrice`, `unitPrice`,
  `discountAmount`/`discountId`, `taxGroupId`, `taxAmount`/`taxBreakdown` and `lineTotal` verbatim
  onto `sale_items`' matching columns. This task performs no tax or discount arithmetic of its own
  anywhere.
- AC-5: `AddSaleLine`/`UpdateSaleLine` refuses `unresolvable_price` when `quoteLine()` throws
  `no_price_resolved`, and surfaces every other `PricingRefused` reason the engine raises
  (`unknown_product`, `unknown_tax_group`, `no_effective_rate`, etc.) under its own name, naming
  nothing this task did not receive from the engine.
- AC-6: `UpdateSaleLine` changes quantity, unit or an explicit price override and re-prices through
  AC-4; `RemoveSaleLine` removes a line entirely. Both are refused (`sale_not_editable`) once the
  sale's `status` is anything other than `draft` or `held` — a `pending_payment` sale (one or more
  tenders already recorded) can still have tenders removed (AC-13) but not have its lines edited,
  because a line change after money is on the table would silently invalidate an already-recorded
  tender amount.
- AC-7: A price override (a cashier typing a different `unitPrice` than the engine resolved) is
  accepted only when accompanied by a decided `approval_requests` row of `type = price_override`
  (D-13/D-14), refused `approval_required` otherwise, and the line's `price_overridden_by` is set
  to the approving employee (never the requesting cashier — D-15's segregation rule applies here
  too, enforced by the same approval-decide feature, AC-23).
- AC-8: `sales.total`/`subtotal`/`discount_total`/`tax_total`/`rounding` are recomputed as a
  rollup over current `sale_items` on every add/update/remove — never independently editable —
  and a bill-level discount (`sales.bill_discount_id`/`bill_discount_value`) is applied through
  `PricingEngine::billDiscount()` (D-4) against the sum of each line's `discountedSubtotal`
  (matching T-029/D-19's own pre-tax base rule, read, not re-derived, by this task).
- AC-9: `sale_items.price_from_label`, `kitchen_station_id` and `modifiers` are written only with
  their schema default (`false`/`null`/`null`) by every path this task ships (D-8, D-9) — no
  feature in this task sets any of the three to a caller-supplied value.
- AC-10: `sale_items.unit_cost`, `line_cost` and `sales.cost_total` stay `NULL` on every row this
  task writes (D-1, D-2); `sales.stock_applied` stays `false`.

### B — Tenders

- AC-11: `AddTender` records a `sale_tenders` row (`payment_method_id`, `method_code`/`method_type`
  snapshotted from `payment_methods`, `amount`, `tendered`, `change_given`, an optional caller
  identifier used as an idempotency key — AC-27) against a sale whose `status` is `draft`, `held`
  or `pending_payment`; adding the first tender to a `draft`/`held` sale moves it to `pending_
  payment`.
- AC-12: A sale accepts more than one tender (split payment) in any combination of active
  `payment_methods` rows; `sale_tenders.amount` for a `credit` (store-credit) method additionally
  calls the Customers credit-ledger contract (D-5) to post a `credit_sale` entry for that amount,
  refused (`credit_refused`, naming the contract's own reason) when Customers refuses it (no
  credit allowed, over limit, inactive/merged customer).
- AC-13: `RemoveTender` reverses a mistakenly entered tender while the sale is still `pending_
  payment` (not yet completed) by deleting the row outright (no money has moved outside this
  task's own ledger-free bookkeeping at this stage) — never allowed once the sale is `completed`
  (AC-16's void/refund paths are the only corrections after that point).
- AC-14: `CompleteSale` is refused (`balance_due`, naming the remaining amount) unless the sum of
  `sale_tenders.amount` equals `sales.total` within the sale's configured rounding tolerance; on
  success it sets `status = completed`, `completed_at`, `paid_total`, `change_due`, and allocates
  `sales.number` through Organization's existing `number_sequences` mechanism (D-20) — never a
  second numbering scheme.
- AC-15: `CompleteSale` performs no stock check, no stock write, and no GL/journal posting (D-1,
  D-6) — it is a pure status/number/paid-total transition over this task's own tables.
- AC-16: `payment_methods` (code, name, type, `is_active`, `opens_drawer`, `requires_reference`,
  `allows_change`, `allows_refund`, `allows_partial`, `rounding_rule`, `surcharge_percent`) is
  built as ordinary CRUD, soft-deletable, with a reference guard refusing deactivation while any
  `pending_payment`/`draft` sale references it as a tender candidate — `gateway`/`settings`/
  `financial_account_id` stay unbuilt or unused (D-6, D-7).

### C — Hold and recall

- AC-17: `HoldSale` moves a `draft` or `pending_payment` sale to `status = held`, recording the
  status it held from.
- AC-18: `RecallSale` moves a `held` sale back to exactly the status recorded at AC-17 (D-25), and
  may be called from a different `terminal_id`/employee session than the one that created or held
  it — `sales.terminal_id` is updated to the recalling terminal; `sales.employee_id` (the original
  cashier) is never changed by this feature (D-24).
- AC-19: Every hold and every recall is appended to an audit trail naming the acting employee, the
  terminal, and the timestamp, independent of the sale's own `updated_at` (which a status column
  change already touches) — so "who parked this and who picked it up" is answerable without
  diffing audit-log rows against unrelated edits.
- AC-20: A list of currently held sales (for a "recall" screen) is scoped to the branch, not the
  terminal that created them — any terminal in the branch can see and recall any held sale in that
  branch, matching the user story's "possibly a different terminal."

### D — Voids

- AC-21: `VoidSale` on a `draft`, `held` or `pending_payment` sale requires no approval (D-14),
  sets `status = voided`, `voided_by`, `void_reason`, `voided_at`, and reverses every recorded
  tender with a mirror, opposite-signed `sale_tenders` row rather than deleting or editing the
  original (D-16) — including reversing any `credit_sale` ledger entry AC-12 posted, through the
  same Customers contract (D-5), as a compensating entry.
- AC-22: `VoidSale` on a `completed` sale requires a decided, `approved` `approval_requests` row of
  `type = void` (D-13/D-14), refused `approval_required` otherwise, and the decider must not be the
  sale's own `employee_id` (D-15, `self_approval_not_allowed`). On success it behaves exactly as
  AC-21 for the tender-reversal and ledger-compensation steps, and additionally does **not** write
  any stock or GL effect (D-16 — there is none to reverse under this task's own scope).
- AC-23: `RequestApproval`/`DecideApproval` build `approval_requests` for `type` in `{discount,
  void, refund, price_override}` only (D-13); `DecideApproval` verifies the deciding employee's PIN
  through Identity's existing PIN-verification mechanism (D-12), sets `decision_method = pin_local`,
  and refuses `pin_invalid` on a failed verification without revealing which part failed. `type =
  credit_limit`, `negative_stock` and `custom`, and `decision_method = remote`, are not reachable
  through any feature this task ships (D-12, D-13) — the enum values exist in the schema for
  forward compatibility only.
- AC-24: A `pending`/`expired` `approval_requests` row cannot be reused for a second, unrelated
  write once decided — `DecideApproval` is a one-time transition (`pending` → `approved`/
  `rejected`), and the write that consumed an `approved` row marks it consumed so a second write
  cannot replay the same approval (`approval_already_used`).

### E — Refunds

- AC-25: `RefundSale` creates a new `sales` row, `type = refund`, its own `number` (D-20, a
  distinct sequence key from `sale`), and `reference_sale_id` pointing at the original sale when
  one is supplied, or `null` for a goodwill refund with no original (D-19).
- AC-26: Each refund line sets `refund_of_item_id` to the original line's id (when a
  `reference_sale_id` is given) and resolves its `UnitSnapshot` through `UnitResolver::
  snapshotInherited()` (D-3) — reusing the original line's `unit_factor` when the refund is taken
  in the same unit, converting base-to-base otherwise, and never re-walking the price/unit ladder
  for the unit factor. A goodwill refund line (AC-19/D-19, no `refund_of_item_id`) resolves its
  `UnitSnapshot` through the ordinary `snapshot()` instead.
- AC-27: Every refund line is re-priced through `PricingEngine::quoteLine()` for the refunded
  quantity (D-17) — never copied verbatim from the original line's stored price — because a
  partial refund's quantity can legitimately re-resolve a different price-list step than the
  original full-quantity line did.
- AC-28: `RefundSale` refuses `over_refund` (naming the line's remaining refundable quantity) when
  the requested refunded quantity on any line, added to that line's existing `refunded_quantity`,
  would exceed the original line's `base_quantity`; it refuses `fully_refunded` when every line of
  the named original sale is already fully refunded. On success it updates the original sale's
  lines' `refunded_quantity` and the original sale's own `status` to `refunded` (every line fully
  refunded) or `partially_refunded` (some, not all).
- AC-29: `RefundSale` requires a decided, `approved` `approval_requests` row of `type = refund`
  (D-14), with the same self-approval restriction as AC-22 where the original sale's `employee_id`
  is known (a goodwill refund with no original sale has no `employee_id` to compare against, and
  is refused `approval_required` on the same `type = refund` gate with no self-approval check to
  apply).
- AC-30: `RefundSale`'s own tenders (`sale_tenders`, negative-signed — money paid back to the
  customer) follow AC-12's rule set exactly, including a `credit` refund method posting a
  compensating `refund` entry through the Customers contract (D-5).
- AC-31: `RefundSale` writes no stock movement and no GL posting (D-1, D-6, D-17) — identical
  scope boundary to Void.

### F — Cash shifts

- AC-32: `OpenCashShift` creates a `cash_shifts` row (`status = open`, `branch_id`, `terminal_id`,
  `employee_id`, `opening_cash`) and refuses `shift_already_open` when the terminal already has one
  open (D-22).
- AC-33: `RecordCashMovement` appends a `cash_movements` row (`type` in `{cash_in, cash_out,
  float_in, float_out, drop, petty_cash, bank_deposit}`, `amount`, `reason`, `employee_id`) against
  an `open` shift only, refused `shift_not_open` otherwise; `financial_account_id` stays unset
  (D-6).
- AC-34: `CloseCashShift` requires a caller-supplied `counted_cash`, computes `expected_cash`
  (opening cash plus every cash-method tender and cash-type movement recorded against the shift,
  minus any cash refund tenders) and `variance = counted_cash − expected_cash`, sets `status =
  closed`, `closed_at`, `closed_by`, and refuses `shift_not_open` on a shift that is not currently
  `open`.
- AC-35: `CloseCashShift` also computes and stores `totals_by_method` (a JSON map of payment-method
  code to summed tendered amount for the shift), `sales_count`, `refunds_count`, `gross`,
  `discounts`, `tax`, `net` — all as a rollup over this task's own `sales`/`sale_tenders` rows for
  the shift, with no GL posting (D-6).
- AC-36: `ReconcileCashShift` moves a `closed` shift to `status = reconciled`, recording
  `reconciled_by`, and performs no comparison against any external record (D-21) — it is a status
  marker only, callable by any authorised supervisor regardless of whether they closed the shift.
- AC-37: `RecordCashMovement`/`CloseCashShift`/`ReconcileCashShift` are each independently
  idempotent by a caller-supplied identifier (the same replay-safety discipline every other
  financial write in this codebase already carries) — a replay with an identical payload is a
  no-op returning the original result; a replay with a different payload under the same identifier
  is refused `replay_conflict`.

### G — Static and engine instruments

- AC-38: A source-rules test asserts no file under this task's package computes tax, discount or
  unit-factor arithmetic itself — every such computation is a call into `Contracts\Pricing\
  PricingEngine` or `Contracts\Catalog\UnitResolver`, mirroring T-029's own AC-25 instrument and
  T-027's factor-multiplication scan, which this task's package must pass with zero exemptions
  needed (it performs no arithmetic of its own to exempt).
- AC-39: A schema test asserts every table this task migrates has exactly the column set this spec
  and `docs/database.md` document — including the **absence** of `journal_entry_id` on `sales`
  (D-6) and of the three deferred `accounts`/`financial_accounts` FK columns (D-6, D-7) — the same
  "prove the absence" shape T-027/T-029 already use for their own deferred columns.
- AC-40: `TenantDecimalPrecisionTest`'s column map is extended with every money/quantity column
  this task adds, all at `(18,4)` (D-27); no fifth precision pair is introduced.
- AC-41: `UnitResolver::snapshotInherited()` (D-3) is proven against the same catalog unit-fixture
  discipline T-029/D-21 already established for `SnapshotCalculator` — at minimum one case that
  reuses the inherited factor (refund in the same unit) and one that converts base-to-base (refund
  in a different, but still resolvable, unit), checked in beside `packages/catalog/fixtures/
  units/`.

## Out of scope

- Real-time stock validation and decrement on sale completion (FP-234, its own backlog row — D-1).
- Costing/COGS (`unit_cost`, `line_cost`, `cost_total`) and any GL/journal posting (Finance,
  FP-260/FP-263 — D-2, D-6).
- The full payment-gateway/settlement/AR-allocation apparatus of `docs/database.md` §6.8 beyond
  `payment_methods` and `sale_tenders` — `payments`, `payment_allocations`, `gateway_configs`,
  `gateway_transactions`, `settlements` (D-7).
- Barcode-rule parsing and scale-label-derived quantities (FP-423 — D-8).
- Kitchen-station routing on a sale line (FP-239 — D-9).
- Quotations, sales orders, the restaurant domain (floor areas, tables, orders, KOTs), and
  `type = exchange` sales (D-10) — each filed as its own forward note, not built here.
- Automatic loyalty-point earning on sale completion (no documented trigger rule exists — D-5); a
  caller may invoke Customers' own loyalty feature directly once both exist.
- A forced header-level price-list override via `sales.price_list_id` (D-11) — the column is
  stored but not yet read as a forcing parameter, because the pricing contract does not expose one.
- `approval_requests.decision_method = remote`, and `type` in `{credit_limit, negative_stock,
  custom}` (D-12, D-13).
- Real offline sync of any kind (FP-237) — `[SYNC]` columns are built present but inert (D-28).
- The Till frontend itself (FP-236) and the Angular/offline client (FP-237) — this is a backend-
  only spec.

## Non-functional requirements

- Every list feature ships with offset-or-cursor pagination from its first version (D-26).
- Every money/quantity column is `DECIMAL(18,4)`, matching this schema's existing allow-list; no
  new precision pair is introduced (D-27, AC-40).
- Every financial write (tender, void-reversal, refund, shift close/reconcile) is attributed to an
  actor and audited, one row per write, writing nothing on refusal — the same discipline every
  prior task in this backlog applies.
- `CloseCashShift`/`RecordCashMovement`/`ReconcileCashShift` are idempotent by caller-supplied
  identifier (AC-37), matching the precedent this codebase already sets for financial replay safety
  (T-021's `RequireIdempotencyKey`, T-028's ledger-write replay rule).
- No new `(precision, scale)` pair is added to `TenantDecimalPrecisionTest`'s allow-list.

## Open questions

None left blocking — every judgement call this spec needed is resolved and logged in
`docs/decisions/T-030-sales.md` (D-1 to D-28), per the team protocol. Two items are **not**
blocking but stay genuinely unconfirmed until their owning dependency lands, and are called out
rather than silently assumed correct:

1. The exact method/DTO names on `Contracts\Pricing\PricingEngine` (D-4) and the exact published
   shape of Customers' credit-ledger contract (D-5) are still moving on T-029's and T-028's own
   branches respectively. This spec names the *call shape and intent* (quote a line/bill discount
   from a `UnitSnapshot`; post/compensate a `credit_sale`/`refund`/`payment`-type ledger entry
   keyed on this task's own `sales.id`), and `dev` reconciles the literal interface against each
   package's real, committed contract before writing any Sales code — the same reconciliation step
   the task brief itself calls for ("read it directly... once committed").
2. Identity's exact PIN-verification contract name (D-12/AC-23) is read directly from the shipped
   `packages/access`/Identity source at build time; T-010 (DONE) is the owning task, so this is a
   lookup, not an open dependency, but the literal symbol is not restated here to avoid this spec
   going stale the moment that package's own internals are refactored.

## Assumptions

- `packages/pricing` (T-029) and `packages/customers` (T-028) will each ship the published
  contracts their own decisions/plans describe (`PricingEngine`, `TaxGroupDirectory`-adjacent
  machinery; a customer credit-ledger writer) before this task's own code is written — this spec
  is written to consume both, not to redesign either, and carries no fallback if either ships a
  materially different shape (that would be a plan-stage finding for this task's own `architect`
  to route back to `ba`, exactly as happened on T-027/T-028/T-029 themselves).
- Organization's `number_sequences` mechanism (T-010/T-014, DONE) can be extended with `'refund'`
  as a second document-type key alongside `'sale'` without a structural change to that package —
  if it cannot, that is a plan-stage finding, not a blocking question here.
- Identity's PIN-verification mechanism (T-010, DONE) is callable from another package through a
  published contract, following this codebase's consistent "contract, not direct read" rule for
  every other cross-package dependency named in this spec.
- No tenant has a payment gateway configured at launch (`docs/architecture.md` §23, open question
  3's stated default), which is what makes D-7's narrower scope ("tenders only, no gateway
  apparatus") a safe, reversible cut rather than a feature gap anyone depends on today.
