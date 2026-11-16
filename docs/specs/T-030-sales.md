# T-030: Sales — cart, tenders, hold and recall, refunds, voids, cash shifts

**Revision 3.** Revision 2: `architect6` reviewed revision 1 against the real shipped T-027/T-028
code and T-029's committed plan (not this spec's assumptions about either) and found 21
inconsistencies — 4 serious enough to change behaviour (F-2 to F-5), 8 more that change behaviour
in a smaller way (F-1, F-6, F-7, F-10, F-12, F-13, F-15 to F-17), and 9 small corrections (F-8,
F-9, F-11, F-14, F-18 to F-21). Every one was resolved and logged in `docs/decisions/
T-030-sales.md` D-29 to D-50; two were offered as product questions (bill-level discounts, refund
pricing) and the lead ruled on both directly rather than escalating further (D-35, D-38).
Revision 3: `architect6` reconciled the committed plan revision 2 against spec revision 2 and
found 4 more, small, non-blocking drift points (F-22 to F-25 — an implicit recall on tendering a
held-from-`pending_payment` sale; splitting `approval_already_used` from `approval_not_pending`/
`approval_not_approved` by cause; naming the real employee-directory contract; an off-by-one on
the discount usage-limit boundary) — logged as D-51 to D-54 and folded in here. Nothing in either
revision changes the task's outer scope boundary from revision 1 — the same things stay out
(D-1, D-6, D-7, D-10).

Revision 4: `architect` reconciled plan revision 3 against the shipped T-028 and T-029 code at
`74fceba` (findings R-1 to R-41, questions Q-1 to Q-11) and the lead ruled on each. The amendments
are logged as D-55 to D-70 in `docs/decisions/T-030-sales.md`; D-1 to D-54 are kept intact, and a
correction is a new entry naming the one it corrects. Where an existing AC changes, a line marked
"Revision 4" sits under it; the new ACs are AC-47 to AC-58 (section I). Two owner-level questions
(Q-1, Q-2) are recorded as OPEN with a built default (see Open questions); neither blocks Chunk A.
Each claim below was checked against the code on this branch: `PricingRefusal` has 17 cases,
`CreditRefusal` has 16 (including `replay_conflict` and `reserved_reference_type`), the engine
refuses a soft-deleted product, loads a discount `whereNull('deleted_at')`, and `DiscountCalculator`
refuses on the current `is_active` and on `usedCount >= usageLimit`, and the ledger's
`RESERVED_REFERENCE_TYPES` is an exact `in_array` over `customer`, `customer_merge_out`,
`customer_merge_in`. (FP-440's `NOT NULL DEFAULT 0` on `products.selling_price` is taken from the
backlog and the plan; the migration text was not located by search.)

Spec written **spec-ahead** of two unfinished dependencies: T-029/FP-231 (Taxes, price lists,
discounts — `packages/pricing`) and T-028/FP-232 (Customers — `packages/customers`), both still
being implemented on their own branches. No code is built from this spec until both are DONE
and this branch is rebased onto their tip. Every place this spec names either package's contract,
it is naming intent, not a literal signature, and `dev` reconciles against the real shipped
interface before writing code (see decisions D-4/D-5, and the plan's own step 0 reconciliation
table).

Revision 5: the lead's four rulings recorded in the backlog row are written here as AC-12A, AC-14A,
AC-24A and AC-47A (decisions D-71 to D-74). Numbering is unchanged. Owner questions Q-1 and Q-2 are
unchanged and still open with their built defaults (see Open questions).

Revision 6: three lead rulings found while building Chunks C and D, written as suffix ACs so no number
moves: AC-47B (a referenced refund line also skips the sellability check), AC-12B (a refund gives no
change) and AC-27A (a refund of an overridden line pays back the price actually charged). Decisions D-75
to D-77; D-38 is amended by D-77, not deleted. Owner questions Q-1 and Q-2 are unchanged.

Revision 7: lead rulings from code review round 1, written as suffix ACs so no number moves: AC-27B
(refunds against one original line never exceed its line_total), AC-13A (a pending_payment sale's
paid_total and balance_due stay current as tenders come and go) and AC-55A (a reused approval keeps
naming the approver of that line). Decisions D-78 to D-81; D-81 lists the follow-ups deferred from the
same review. Owner questions Q-1 and Q-2 are unchanged.

Revision 8: lead rulings from review round 2 and QA round 2, no AC number moves. AC-27B is reworded
(a last-quantity refund with a 0.0000 remainder is accepted, not refused) and gains the accepted
consequences of its remainder rule; AC-57 gets a carve-out. Decisions D-82 to D-85 (review W-9, W-7,
W-8, W-10).

Backlog: FP-233, `docs/backlog.md`. Decisions: `docs/decisions/T-030-sales.md` (D-1 to D-85).
Design inputs read directly: `docs/architecture.md` v0.5 §1, §3, §8, §11, §12, §15, §21-22;
`docs/database.md` v0.5 §3, §6.4 to §6.9 (Catalog, Pricing/tax, Inventory's unit-inheritance
rule, Sales/POS, Payments, Customers, §12.1's credit-sale transaction shape); T-027's decisions/
plan (`UnitResolver`, `SnapshotCalculator`, the published `Contracts\Catalog\UnitResolver`);
T-029's decisions/plan (`PricingEngine`, the six-step price ladder, discount calculator, fixture
discipline); T-028's decisions/plan (the customer credit ledger's real unique key and contract,
and the `loyalty_transactions.sale_id` deferral it left for this task); `docs/plans/T-030-sales.md`
section 0 (`architect6`'s findings against all three).

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
domain, `exchange`-type sales, or bill-level discount splitting (deferred by the lead's own
ruling, D-35). See Out of scope and `docs/decisions/T-030-sales.md` D-1, D-6, D-7, D-10, D-35 for
why each is a clean boundary rather than an oversight.

## Users affected

- **Cashiers**, who build and complete a sale, hold and recall a parked sale, open and close their
  own cash shift, and request a supervisor's approval when a rule requires one.
- **Supervisors/managers**, who decide (approve or reject) a pending approval request by PIN plus
  a checked permission (D-30), and who reconcile a closed shift.
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
  item is handled as its own document without editing the original receipt, and so that the
  customer gets back what they actually paid.
- As a cashier, I want a supervisor's PIN **and** the right permission to unlock an action the
  rules restrict (voiding a completed sale, an over-threshold discount, a price override), so that
  sensitive corrections are never a unilateral cashier action and never just "whoever knows a
  second PIN."
- As a cashier, I want to open my cash drawer at the start of a shift and close it at the end with
  a counted-cash figure, so that any shortage or overage is visible the same day.
- As a supervisor, I want to mark a closed shift reconciled, so that the shift's own lifecycle has
  a clear endpoint even before a real bank-reconciliation step exists.

## Acceptance criteria

Decimal/date conventions throughout: every money and quantity column this task adds is
`DECIMAL(18,4)` **except** the three columns that reuse this schema's other already-allowed
pairs — `sale_items.unit_factor` at `(18,6)`, `sale_items.discount_percent`/`payment_methods.
surcharge_percent` at `(9,4)`, `sales.exchange_rate` at `(18,8)` — and no fifth pair is introduced
(D-45/F-19). Every refusal is a named code, writing nothing, with the existing field(s) and reason
stated. Every list feature supports offset-or-cursor pagination from day one (D-26). Every write
is attributed to an actor id and audited, one row per write, matching the T-027/T-028/T-029 slice
shape.

### A — Sale header and cart lines

- AC-1: `CreateSale` opens a new `sales` row with `status = draft`, `branch_id`/`location_id`
  taken from the terminal (never client-supplied), `employee_id` (the cashier/actor),
  `business_date` from the branch's own timezone (server-resolved — D-23's scope; no
  client-submitted date is accepted by this task), `business_date_source = server`, `currency`
  from the branch, and `shift_id` set to the terminal's currently open shift. It refuses
  `no_open_shift` when the terminal has no `cash_shifts` row with `status = open` (D-22), and
  `not_found` when an optional supplied `customer_id` is unknown, soft-deleted or already merged
  into another customer.
- AC-2: `AddSaleLine` adds a `sale_items` row carrying the full `[UOM]` quad — `unit_id` (any unit
  convertible to the product's stock unit), the resolved `unit_factor` and `base_quantity` from
  `Contracts\Catalog\UnitResolver::snapshot()` (never hand-computed) — plus `product_id`, and the
  snapshot columns `product_name`, `sku`, `unit_code`, `category_id`, `brand_id` taken from the
  product at the moment the line is added (so a later rename/recategorisation does not change an
  existing line, matching `docs/database.md` §6.7's own stated reason for those columns). An
  optional line-level `employee_id` (salesperson, for category-based commission) is validated
  against `Contracts\Access\EmployeeDirectory` (D-53/F-24 — employees are an Access/Identity
  concept in this codebase's module split, not Organization's).
- AC-3: `AddSaleLine` refuses `unit_unresolvable` when `UnitResolver::snapshot()` throws (the line
  unit does not convert to the product's stock unit at all), naming the requested unit and the
  product's stock unit.
- AC-4: Every `AddSaleLine`/`UpdateSaleLine` call (other than an approved price override, AC-7)
  prices the line by calling `Contracts\Pricing\PricingEngine::quoteLine()` with the line's
  `UnitSnapshot`, the sale's `customer_id`, `customer_id`'s own `group_id` (read as a fact, not
  re-derived), `branch_id`, the sale's `business_date` as the engine's `documentDate`, the current
  UTC instant as `at`, and the sale's `currency`, and stores the returned `listPrice`, `unitPrice`,
  `subtotal`, `discountAmount`/`discountId`, `taxGroupId`, `taxAmount`/`taxBreakdown` and
  `lineTotal` verbatim onto `sale_items`' matching columns (including the new `subtotal` column,
  D-44/F-18). This task performs no tax, discount or factor arithmetic of its own anywhere outside
  the one override multiplication AC-7/D-40 names.
- AC-5: `AddSaleLine`/`UpdateSaleLine` refuses `unresolvable_price` when `quoteLine()` throws
  `no_price_resolved`, and surfaces every other `PricingRefused` reason the engine raises
  (`unknown_product`, `unknown_tax_group`, `no_effective_rate`, `not_applicable`, etc.) under its
  own name, naming nothing this task did not receive from the engine.
- AC-6: `UpdateSaleLine` changes quantity, unit or (via AC-7) a price override, and re-prices
  through AC-4; `RemoveSaleLine` removes a line entirely. Both are refused `sale_not_editable`
  once the sale's `status` is `pending_payment`, **and also refused `sale_not_editable` for a sale
  `held` with a recorded `from_status` of `pending_payment`** (D-36/F-10) — only `draft`, and
  `held` recorded `from_status = draft`, allow a line edit. A `pending_payment` sale can still have
  tenders removed (AC-13) but never have its lines edited, because a line change after money is on
  the table would silently invalidate an already-recorded tender amount, and recalling it first
  does not change that: recall only restores the sale's prior status, it does not unlock its lines.
- AC-7: A price override (a cashier typing a different `unitPrice` than the engine resolved) is
  refused `price_override_not_allowed` when the product's `allow_price_override` is `false`, and
  refused `discount_with_override` when the line also carries an item `discount_id` — an
  overridden line carries no item discount. Accepted only when accompanied by a decided
  `approval_requests` row of `type = price_override` whose payload matches `{product_id, unit_id,
  unit_price}` exactly (D-47), refused `approval_required` otherwise. On success: `quoteLine()` is
  still called once, to obtain `listPrice` and `taxGroupId` only; `subtotal = round(unitPrice ×
  quantity, 4)` (the one multiplication this task's own code performs, confined to `Support/
  Money.php`, D-40); tax is computed on that `subtotal` via the engine's `computeTax()`;
  `lineTotal = tax.net + tax.total` (holds for an inclusive or exclusive tax group alike, so this
  task never branches on inclusivity). The line's `price_overridden_by` is set to the **approving**
  employee, never the requesting cashier (D-15's segregation rule, enforced by the same
  approval-decide feature, AC-23).
- AC-8: `sales.total`/`subtotal`/`discount_total`/`tax_total` are recomputed as a plain rollup
  (sum) over current `sale_items`' own stored columns — including the new `sale_items.subtotal`
  column (D-44/F-18), so the rollup never has to re-derive or branch on tax inclusivity — on every
  add/update/remove, and are never independently editable. `sales.rounding` and `service_charge`
  are always written `0.0000` (D-42/F-11 — no rounding policy or service-charge rule is documented
  anywhere this task can read). **Bill-level discounts are not built in this task** (D-35/F-1):
  `sales.bill_discount_id` and `bill_discount_value` exist as documented columns but are never
  written by any feature here, and `CreateSale` refuses either as an unknown field if supplied.
- AC-9: `sale_items.price_from_label` and `modifiers` are written only with their schema default
  (`false`/`null`) by every path this task ships (D-8) — no feature in this task sets either to a
  caller-supplied value. `sale_items.kitchen_station_id`, `batch_id`, `serial_id`, and every
  Finance/gateway-adjacent column named in D-6/D-7/D-33 are **not built at all** on any table this
  task migrates — not present with a default, simply absent, which AC-39's schema test proves.
- AC-10: `sale_items.unit_cost`, `line_cost` and `sales.cost_total` stay `NULL` on every row this
  task writes (D-1, D-2); `sales.stock_applied` stays `false`. `sale_items.discount_percent` is
  written `null` on every row (D-46/F-20 — `PricingEngine`'s `PricedLine` carries no item-level
  discount-percent fact; its `listDiscountPercent` is the price list's own markdown rate, a
  different number).

### B — Tenders

- AC-11: `AddTender` records a `sale_tenders` row (`payment_method_id`, `method_code`/
  `method_type` snapshotted from `payment_methods`, `amount`, `tendered`, `change_given`, a
  caller-supplied ULID as the row's own id — `docs/database.md` §6.8's own stated precedent,
  doubling as the idempotency key, AC-37) against a sale whose `status` is `draft`, `pending_
  payment`, or `held` **only when its recorded `from_status = pending_payment`** (a sale held from
  `draft` must be recalled first — Ruling 4's state machine, D-49); adding the first tender to a
  `draft` sale moves it to `pending_payment`. **Tendering a sale currently `held` with `from_
  status = pending_payment` performs an implicit recall** (D-51/F-22): in the same transaction as
  recording the tender, `AddTender` runs exactly `RecallSale`'s own status/terminal/shift
  reassignment (AC-18) before the tender is recorded — a separate explicit `RecallSale` call first
  is not required for this one case, though still legal and a no-op status-wise if the recalling
  terminal is the one already tendering. A `credit` (store-credit) tender requires the sale to
  carry a `customer_id`, refused
  `customer_required` otherwise — it posts **no ledger entry at this step** (D-29/F-3; posting
  happens at completion, AC-14).
- AC-12: A sale accepts more than one tender (split payment) in any combination of active
  `payment_methods` rows whose `type` is not `loyalty` or `voucher` (refused `unsupported_
  method_type` — D-41/F-17, since neither has a backing deduction mechanism this task can call).
- AC-13: `RemoveTender` reverses a mistakenly entered tender while the sale is still `pending_
  payment` (not yet completed) by deleting the row outright — including a `credit`-type tender,
  which is now safe to delete plainly because no ledger entry was ever posted for it (D-29/F-3
  corrects revision 1's claim here) — never allowed once the sale is `completed` (AC-21/AC-25's
  void/refund paths are the only corrections after that point).
- AC-14: `CompleteSale` is refused `balance_due` (naming the remaining amount) unless the sum of
  `sale_tenders.amount` equals `sales.total` exactly (D-42 — no rounding tolerance is configured);
  on success, inside one transaction, it: posts one `credit_sale` entry per **credit tender**
  present on the sale through the Customers credit-ledger contract, keyed `reference_type =
  'sale_tender'`, `reference_id = <that tender's own id>` (D-29/F-2/F-3 — never keyed on the sale
  itself, so a sale with two credit tenders posts two independently-replayable entries), refused
  `credit_refused` (carrying the ledger's own refusal reason) with nothing written on failure;
  calls `Contracts\Pricing\DiscountUsage::consume()` once per **distinct** `discount_id` actually
  used on the sale (D-37/F-12), refused `usage_limit_reached` with nothing written; allocates
  `sales.number` through the new `Contracts\Organization\DocumentNumbers::next('sale', $branchId)`
  contract (D-31/F-6 — never a second numbering scheme); and sets `status = completed`,
  `completed_at`, `paid_total`, `change_due`.
  **Revision 7 (amended by AC-13A, D-79):** `paid_total` and `balance_due` are no longer written first
  at completion; they are already current from the tenders (AC-13A). Completion confirms and finalises
  them (and sets `change_due`), it does not introduce them.
- AC-15: `CompleteSale` performs no stock check, no stock write, and no GL/journal posting (D-1,
  D-6) — every write it makes is to this task's own tables plus the two named cross-package calls
  in AC-14.
- AC-16: `payment_methods` (code, name, type, `is_active`, `opens_drawer`, `requires_reference`,
  `allows_change`, `allows_refund`, `allows_partial`, `rounding_rule`, `surcharge_percent`) is
  built as ordinary CRUD, soft-deletable, with a reference guard refusing deactivation/deletion
  while any `draft`/`held`/`pending_payment` sale holds a tender on the method (`in_use`, naming
  the count) — accepted again once every such sale completes, voids, or is otherwise resolved.
  `financial_account_id`/`gateway`/`settings` are refused as unknown fields, not silently dropped
  (D-6/D-33) — they are not built columns at all.

### C — Hold and recall

- AC-17: `HoldSale` moves a `draft` or `pending_payment` sale to `status = held`, recording the
  status it held from (a new `sale_holds` row, D-44/F-18, `action = hold`, `from_status`,
  `to_status = held`, the acting terminal and employee) — this `from_status` is exactly what
  AC-6/D-36 and AC-11 each read to decide whether the sale's lines are editable or a tender may be
  added once it is later recalled.
- AC-18: `RecallSale` moves a `held` sale back to exactly the status recorded at AC-17 (D-25),
  reassigning `sales.terminal_id` to the recalling terminal and `sales.shift_id` to that
  terminal's own currently open shift (refused `no_open_shift` if it has none, D-39/F-15) — it may
  be called from a different terminal/employee session than the one that created or held it;
  `sales.employee_id` (the original cashier) is never changed by this feature (D-24). A second
  `sale_holds` row is appended (`action = recall`).
- AC-19: Every hold and every recall is appended to the `sale_holds` audit trail naming the acting
  employee, the terminal, and the timestamp, independent of the sale's own `updated_at` — so "who
  parked this and who picked it up" is answerable without diffing audit-log rows against unrelated
  edits.
- AC-20: A list of currently held sales (for a "recall" screen) is scoped to the branch, not the
  terminal that created them — any terminal in the branch can see and recall any held sale in that
  branch, matching the user story's "possibly a different terminal," and is paginated (D-26).

### D — Voids

- AC-21: `VoidSale` on a `draft`, `held`, or `pending_payment` sale requires no approval (D-14),
  sets `status = voided`, `voided_by`, `void_reason`, `voided_at`, and reverses every recorded
  tender with a mirror, opposite-signed `sale_tenders` row rather than deleting or editing the
  original (D-16) — a credit tender has no ledger entry to compensate at this stage, because none
  was ever posted before completion (AC-11/D-29).
- AC-22: `VoidSale` on a `completed` sale requires: a decided, `approved` `approval_requests` row
  of `type = void` whose binding matches `{reference_type: 'sale', reference_id: <the sale>}`
  (D-47), refused `approval_required` otherwise; the decider must not be the sale's own
  `employee_id` nor the request's own `requested_by` (D-15, `self_approval_not_allowed`); and the
  sale's own `shift_id` must still be `open` (D-39/F-15, refused `shift_closed` otherwise —
  directing the cashier to a refund instead, since a mirror tender on an already-closed, already-
  counted shift would never be reflected in any drawer count). On success it behaves exactly as
  AC-21 for the tender-reversal step, **and additionally compensates any `credit_sale` ledger
  entry AC-14 posted for this sale with a `credit_note`-type entry** (D-29/F-4 — never `payment`,
  which the ledger's own enum documents as a customer-initiated payment, not a credit correction),
  keyed `('sale_tender', <the mirror tender's own id>, credit_note)`. It writes no stock or GL
  effect (D-16 — there is none to reverse under this task's own scope), and does not release any
  `DiscountUsage::consume()` already recorded (D-37 — usage is never released on void, a
  conservative, reversible default).
- AC-23: `RequestApproval`/`DecideApproval` build `approval_requests` for `type` in `{discount,
  void, refund, price_override}` only (D-13); every other `type` value is refused `validation`.
  `DecideApproval` runs in a fixed order (D-30/F-5, Ruling 5): first, **outside any transaction**,
  it calls the new `Contracts\Access\PinVerifier::verify()` — a null result (wrong PIN, unknown
  code, or a locked employee, indistinguishable to this caller) is refused `pin_invalid` with
  nothing in the sales tables written, while Access's own lockout counter still increments exactly
  as it would for a direct PIN attempt; then, inside one transaction, it checks the decider is not
  `requested_by` nor (for a `sale`-referenced request) the sale's own `employee_id`
  (`self_approval_not_allowed`, D-15), checks the decider holds the new `sales.approvals.decide`
  permission at the request's own branch via `PermissionResolver::decide()` (`not_authorised`,
  D-30), and only then sets `status`, `decided_by`, `decided_at`, `decision_method = pin_local`,
  `reason`. `decision_method = remote`, and `type` in `{credit_limit, negative_stock, custom}`, are
  not reachable through any feature this task ships (D-12, D-13) — the enum values exist for
  forward compatibility only.
- AC-24: Three distinct causes, three distinct refusal codes, split by cause rather than collapsed
  (D-52/F-23): `DecideApproval` against a row whose `status` is no longer `pending` (already
  decided, either way) is refused `approval_not_pending`; a **consuming** write (`VoidSale`/
  `RefundSale`/the price-override or discount-approval check) against a row whose `consumed_at` is
  already set is refused `approval_already_used`, enforced by the new `approval_requests.
  consumed_at` column (D-44/F-18), which the consuming write sets in the same transaction as its
  own change; a consuming write against a row whose `status` is not `approved` at all (still
  `pending`, or `rejected`) is refused `approval_not_approved`; and a type/`reference_type`/
  `reference_id`/payload mismatch against the fixed binding table (D-47, AC-7/AC-22/AC-23's own
  references) is refused `approval_mismatch` — naming nothing the approval itself did not already
  carry.
- AC-23a: A discount requiring approval — `discounts.requires_approval = true` on the discount a
  line's `discount_id` names, read directly as a fact (D-37/F-12) — refuses that line's add/update
  with `approval_required` unless a consumed `discount`-type approval exists whose payload
  `{discount_id}` matches. `approval_threshold`/`workflow_definition_id` are not evaluated by
  anything in this task (a forward note, D-37).

### E — Refunds

- AC-25: `RefundSale` creates a new `sales` row, `type = refund`, its own `number` allocated
  through `DocumentNumbers::next('refund', $branchId)` (D-31/F-6 — a distinct prefix/sequence from
  `sale`), and `reference_sale_id` pointing at the original sale when one is supplied, or `null`
  for a goodwill refund with no original (D-19).
- AC-26: Each refund line sets `refund_of_item_id` to the original line's id (when a `reference_
  sale_id` is given) and resolves its `UnitSnapshot` through `UnitResolver::snapshotInherited
  (productId, referencedUnitId, referencedUnitFactor, unitId, quantity)` (D-32/F-7) — reusing the
  original line's `unit_factor` when the refund's own requested unit equals the referenced line's
  unit, using a factor of exactly `1.000000` when the requested unit is the product's own stock
  unit (read by the resolver itself, never trusted from the caller), and refusing
  `inherited_unit_mismatch` (naming both units) for any third unit — never re-walking `UnitLadder`
  for any case. A goodwill refund line (no `refund_of_item_id`, D-19) resolves its `UnitSnapshot`
  through the ordinary `snapshot()` instead, since there is nothing to inherit from.
- AC-27: A referenced refund line (one with `refund_of_item_id` set) is re-quoted through
  `PricingEngine::quoteLine()` using the **original sale's own `completed_at` as `at`, the
  original sale's own `business_date` as `documentDate`, and the original line's own `discount_id`**
  (**Revision 7: the amount this re-quote yields is capped by AC-27B.**)
  (D-38/F-13 — refunds pay back what was actually charged, the standard retail convention, not
  today's price) — never copied verbatim from the original line's stored price, because a partial
  refund's smaller quantity can legitimately land on a different price-list step than the
  original full-quantity line did; it is the engine's input *instant*, not the pricing *method*,
  that is anchored to the original sale. A goodwill refund line is quoted at the refund's own
  current instant, having no original sale to anchor to.
  **Revision 6: amended by AC-27A (D-77).** This re-quote applies to a referenced line only when the
  original sale line carried no price override; an overridden line is refunded at the price charged.
- AC-28: `RefundSale` refuses `over_refund` (naming the line's remaining refundable quantity) when
  the requested refunded quantity on any line, added to that line's existing `refunded_quantity`,
  would exceed the original line's `base_quantity`; it refuses `fully_refunded` when every line of
  the named original sale is already fully refunded. On success it updates the original sale's
  lines' `refunded_quantity` and the original sale's own `status` to `refunded` (every line fully
  refunded) or `partially_refunded` (some, not all).
- AC-29: `RefundSale` requires a decided, `approved` `approval_requests` row of `type = refund`
  (D-14), bound per D-47: `{reference_type: 'sale', reference_id: <the original sale>}` when one
  is named, or `{reference_type: 'goodwill_refund', reference_id: null}` for a goodwill refund —
  with the same self-approval restriction as AC-22 where the original sale's `employee_id` is
  known (a goodwill refund has no sale to compare the decider against, so only the `requested_by`
  check applies).
- AC-30: `RefundSale`'s own `sale_items` carry unsigned (positive) quantities and money — the
  document's own `type = refund` carries the direction, not the numbers inside it (D-43/F-14) —
  while its `sale_tenders` are negative-signed (money paid back to the customer), following AC-12's
  method rules exactly, including a `credit` refund method posting a compensating `refund`-type
  entry through the Customers contract, keyed on that refund tender's own id. A refund completes
  when `Σ sale_tenders.amount = −sales.total`. **Revision 6:** "AC-12's method rules" does not
  include giving change: see AC-12B (D-76).
- AC-31: `RefundSale` writes no stock movement and no GL posting (D-1, D-6, D-17) — identical
  scope boundary to Void — and does not release any `DiscountUsage::consume()` the original sale
  recorded (D-37).

### F — Cash shifts

- AC-32: `OpenCashShift` creates a `cash_shifts` row (`status = open`, `branch_id`, `terminal_id`,
  `employee_id`, `opening_cash`, `number` allocated through `DocumentNumbers::next('cash_shift',
  $branchId)`, D-31/F-6) and refuses `shift_already_open` when the terminal already has one open
  (D-22, backed by a generated unique column so the race is caught at the database, not only in
  application logic).
- AC-33: `RecordCashMovement` appends a `cash_movements` row (`type` in `{cash_in, cash_out,
  float_in, float_out, drop, petty_cash, bank_deposit}`, `amount` (always stored positive — the
  sign is a property of `type`, D-39/F-15: `cash_in`/`float_in` add to expected cash, the other
  four subtract), `reason`, `employee_id`, a caller-supplied id doubling as the idempotency key)
  against an `open` shift only, refused `shift_not_open` otherwise; `financial_account_id` is not
  a built column at all (D-6/D-33).
- AC-34: `CloseCashShift` is refused `shift_has_open_sales` while any `draft` or `pending_payment`
  sale still names the shift (D-39/F-15 — a `held` sale does not block the close, since it carries
  no open tender to reconcile against). It otherwise requires a caller-supplied `counted_cash`,
  computes `expected_cash` (opening cash, plus every cash-method tender and every `cash_in`/
  `float_in` movement, minus every `cash_out`/`float_out`/`drop`/`petty_cash`/`bank_deposit`
  movement and every cash-method refund tender recorded against the shift) and `variance =
  counted_cash − expected_cash`, sets `status = closed`, `closed_at`, `closed_by`, and refuses
  `shift_not_open` on a shift that is not currently `open`.
- AC-35: `CloseCashShift` also computes and stores, each precisely defined (D-39/F-15):
  `sales_count` (every `type = sale` row with `status` in `{completed, refunded, partially_
  refunded}` on this shift); `refunds_count` (every `type = refund` row on this shift);
  `gross`/`discounts`/`tax` (the sum of `subtotal`/`discount_total`/`tax_total` over the same sale
  rows); `net` (`Σ sale.total − Σ refund.total`); `totals_by_method` (a JSON map of payment-method
  code to the summed, signed `sale_tenders.amount` for the shift — never `tendered`, which would
  double-count change already paid back out of the drawer as a separate method entry). None of
  this posts to a GL (D-6).
- AC-36: `ReconcileCashShift` moves a `closed` shift to `status = reconciled`, recording
  `reconciled_by`, and performs no comparison against any external record (D-21) — it is a status
  marker only, callable by any employee holding the new `sales.shifts.reconcile` permission
  (D-30) at the shift's own branch, regardless of whether they closed it, refused `not_authorised`
  otherwise.
- AC-37: `RecordCashMovement` is idempotent by its own caller-supplied id (AC-33); a replay with an
  identical payload is a no-op returning the original row, a replay with a different payload under
  the same id is refused `replay_conflict`. `CloseCashShift`/`ReconcileCashShift` are each
  idempotent using **the shift's own id** as the natural key (D-50) — a replayed close against an
  already-`closed`/`reconciled` shift with the same `counted_cash` returns the stored result
  unchanged; a different `counted_cash` is `replay_conflict`; a replayed reconcile against an
  already-`reconciled` shift is a plain no-op. `AddTender` is likewise idempotent by its own
  caller-supplied id (AC-11), on the same rule.

### G — Owed cross-package contracts (built by this task)

- AC-38: `Contracts\Catalog\UnitResolver` gains `snapshotInherited()` exactly as D-32/F-7
  specifies, plus `FactorSource::Inherited` and `UnitRefusal::InheritedUnitMismatch`, implemented
  in `packages/catalog` (the backlog's own "owes, per T-027" line, discharged here) and proven
  against the catalog unit-fixture discipline T-029/D-21 already established — at minimum one
  same-unit-inherit case, one stock-unit case, and one refused third-unit case.
- AC-39: `Contracts\Access\PinVerifier` is added, implemented in `packages/access` by delegating to
  the existing `VerifyPinHandler`, proven to return an indistinguishable `null` for a wrong PIN, an
  unknown employee code, and a locked employee, and proven that calling it **inside** a
  transaction that later rolls back would lose Access's own lockout-counter increment (the reason
  D-30 requires it be called outside one).
- AC-40: `Contracts\Organization\DocumentNumbers` is added, implemented in `packages/organization`,
  proven for: first use creating a company-wide default row and returning the correctly-prefixed
  first number; a branch-scoped row winning over the company-wide row when both exist; `sale`/
  `refund`/`cash_shift` allocating independently; a `reset_period` other than `never` refused
  `unsupported_reset_period`; a call inside a rolled-back transaction leaving the counter
  unchanged; and a concurrent first-use race retried once rather than failing.
- AC-41: `Contracts\Pricing\DiscountUsage` is added, implemented in `packages/pricing`, proven for:
  `consume()` incrementing `used_count` by exactly one; **exactly `usage_limit` uses succeed — the
  call made when `used_count` already equals `usage_limit` is the one refused `usage_limit_
  reached`, with nothing written** (D-54/F-25 — `usage_limit` is the count of uses allowed, not
  one fewer); and refusing `unknown_discount` for an unknown or soft-deleted discount id.

### H — Static and schema instruments

- AC-42: A source-rules test asserts no file under `packages/sales` computes tax, discount or
  unit-factor arithmetic itself — every such computation is a call into `Contracts\Pricing\
  PricingEngine`/`DiscountUsage` or `Contracts\Catalog\UnitResolver`, and the package's **only**
  self-performed arithmetic (sums, differences, and the single override-price multiplication,
  AC-7/D-40) lives in exactly one file, `Support/Money.php` — the scan reports any multiplication,
  division, or `multipliedBy`/`dividedBy` call anywhere else in the package.
- AC-43: A schema test asserts every table this task migrates has exactly the column set this
  spec and `docs/database.md`'s eventual "As built (T-030)" note document — including the
  **absence** of `journal_entry_id` on `sales`/`cash_shifts`, of `ar_document_id`/`sales_order_id`/
  `order_id` on `sales`, of `financial_account_id` on `cash_movements`/`payment_methods`, of
  `gateway_transaction_id`/`payment_id` on `sale_tenders`, of `gateway`/`settings` on `payment_
  methods`, and of `batch_id`/`serial_id`/`kitchen_station_id` on `sale_items` (D-6, D-7, D-33) —
  the same "prove the absence" shape T-027/T-029 already use for their own deferred columns.
- AC-44: `TenantDecimalPrecisionTest`'s column map is extended with every money/quantity column
  this task adds, at the correct one of this schema's four already-allowed pairs (D-45/F-19 —
  `(18,4)` for money/quantity, `(18,6)` for `unit_factor`, `(9,4)` for the two percent columns,
  `(18,8)` for `exchange_rate`); no fifth pair is introduced.
- AC-45: `loyalty_transactions.sale_id` (nullable) plus `unique(sale_id, type)` is added by this
  task as an expand migration, and its foreign key onto `sales` as a separate contract migration
  (D-34/F-9 — discharging T-028's own named debt to this task). `RecordLoyaltyTransaction` itself
  is not modified to accept it automatically; wiring a real `sale_id` through is left to whichever
  future caller wants automatic earning (D-5, unchanged).
- AC-46: `sale_holds` (D-44/F-18: `id`, `sale_id` cascade, `action`, `from_status`, `to_status`,
  `terminal_id`, `employee_id`, `created_at`), `approval_requests.consumed_at` (D-44/F-18), and
  `sale_items.subtotal` (D-44/F-18) are built exactly as decided, each proven present by AC-43's
  schema test and exercised by the AC that reads it (AC-6/AC-11/AC-17/AC-18/AC-19 for `sale_
  holds`; AC-24 for `consumed_at`; AC-8 for `subtotal`).

### I — Revision 4 amendments (D-55 to D-70)

Amendments to earlier ACs, stated here so no AC is renumbered:

- AC-5 (amended): the refusal a caller sees carries **both** a Sales reason and, where one exists,
  the foreign refusal as its cause (D-67). `no_price_resolved` is surfaced as `unresolvable_price`;
  every other pricing reason keeps its own name.
- AC-13 (amended): removing the last tender of a `pending_payment` sale returns it to `draft`.
- AC-14 (amended): a replayed ledger posting is refused (AC-48); a credit tender is refused at
  completion if the customer's currency is missing or differs from the sale's (AC-49); a draft with
  total `0.0000` and at least one line completes with no tender (the sum of tenders, zero, equals
  the total). `DocumentNumbers` may refuse (AC-50).
- AC-26/AC-27 (amended): a referenced refund line's re-quote is subject to the engine's live-state
  checks until the owner rules on Q-1 (AC-53).

New ACs:

- AC-47: Given a product that is inactive (`is_active = false`), not sellable (`is_sellable =
  false`) or of type `variant_parent`, When `AddSaleLine` or `UpdateSaleLine` names it, Then it is
  refused `product_not_sellable`, naming which fact failed, and nothing is written (D-57). A goodwill
  refund line is **not** subject to this check on the product's sellability (a returned item may no
  longer be sold), but is still subject to the engine's own refusals (AC-53).
- AC-48: Given `CompleteSale`, `VoidSale` or `RefundSale` posts to the customer credit ledger and
  the ledger reports the posting as replayed (a row for that `(reference_type, reference_id, type)`
  already existed, for example a manual ledger entry pre-seeded with a tender's id), Then the
  operation is refused `credit_refused` with cause `replay_conflict` and the whole transaction rolls
  back, leaving the sale, tenders, approval consumption and every other ledger row unchanged (D-66).
- AC-49: Given a credit tender on a sale whose customer's currency is missing or differs from the
  sale's currency, When `CompleteSale` runs, Then it is refused `credit_refused` with the ledger's
  own cause (`currency_required` or `currency_mismatch`) and nothing is written. `AddTender` does
  not make this check; it checks only that the sale has a customer (D-68).
- AC-50: Given a `DocumentNumbers` refusal, When `CompleteSale`, `RefundSale` or `OpenCashShift`
  allocates a number, Then the operation is refused `numbering_refused` carrying the cause
  (`unsupported_reset_period`, or `number_too_long` when prefix plus digits plus suffix would exceed
  30 characters, the width of `sales.number` and `cash_shifts.number`), and nothing is written.
- AC-51: Given `HoldSale`, `RecallSale` or `CompleteSale` called on a sale in a status the state
  machine does not allow, Then it is refused `invalid_transition`, naming the current status. Given
  any rollup or amount that would not fit `DECIMAL(18,4)`, Then it is refused `out_of_range`.
- AC-52: Given a quantity of zero or less on a sale line or a refund line, Then it is refused
  `validation` before any unit or price resolution runs.
- AC-53 (Chunk D acceptance depends on owner question Q-1): Given a referenced refund line whose
  discount has since reached its usage limit, been deactivated or soft-deleted, or whose product has
  `allow_discount` turned off, or a refund line (referenced or goodwill) whose product is
  soft-deleted, When `RefundSale` re-quotes it, Then the refund is refused with the engine's own
  reason (`usage_limit_reached`, `inactive`, `unknown_discount`, `discount_not_allowed`,
  `not_applicable`, `unknown_product`) and nothing is written. This is the built default, not a
  confirmed rule.
- AC-54: Given a referenced refund, Then the refund sale copies the original's `customer_id` and
  snapshots; a goodwill refund takes an optional customer, live-checked as in AC-1 (D-58). Given a
  refund requested at a branch other than the original sale's, Then it is refused `not_refundable`.
- AC-55: Given an approval already consumed by this sale for a price override or discount, When
  `UpdateSaleLine` re-prices the same line with the same discount and the same override price, Then
  no new approval is required. A different discount or a different override price needs a new one
  (D-59).
- AC-56: Given a tender removed by `RemoveTender`, When a late retry of that tender's `AddTender`
  arrives with the same id and payload, Then the tender is added again (D-60). No tombstone exists.
- AC-57: A refund is quoted at the original sale's `completed_at` (D-38 kept, amended by AC-27A: an
  overridden line is refunded at the stored price and carries no such drift) and with the
  customer's current group (D-63, D-64). Given a promotion that ended, or a customer who changed
  group, between the sale and the refund, the refund can differ from what was charged; this is
  accepted and named. Carve-out (Revision 8, D-85): the refund of one original line can never exceed
  that line's charge (AC-27B), so where the re-quote has drifted above the remaining charge the
  refund is capped or, for a partial quantity, refused `over_refund`; the "differs from the charge"
  outcome therefore only holds when the re-quote is lower.
- AC-58: `DiscountUsage::consume()` checks existence and the usage limit only. Given a discount
  deactivated or past its window between quote and completion, Then completion still consumes it
  (D-65).

Revision 5 — four lead rulings (D-71 to D-74), written as suffix ACs so no number moves:

- AC-12A (extends AC-12 and AC-30; D-71): Given a refund whose tender names a `payment_methods` row
  with `allows_refund = false`, When the tender is recorded (`RefundSale`), Then it is refused
  `unsupported_method_type` and nothing is written. Given the same method on an ordinary sale
  tender, Then `allows_refund` is not consulted (a sale tender is accepted or refused only by
  AC-12's own rules). Given a refund tender onto a method with `allows_refund = true` and an
  otherwise acceptable type, Then it is accepted. No separate `refund_not_allowed` code exists.
- AC-24A (extends AC-24 and D-47; D-72): Given an approval request granted at branch A, When a
  consuming write (`VoidSale`, `RefundSale`, the price-override or discount-approval check) at
  branch B (B is not A) names it, Then it is refused `approval_mismatch` and the approval's
  `consumed_at` stays unset. Given the same write at branch A, Then the approval is consumed
  normally. The branch is part of the binding in D-47's table alongside `type`, `reference_type`,
  `reference_id` and (where stated) the payload; a goodwill refund approval (`reference_type =
  goodwill_refund`, `reference_id = null`) is bound to its branch like any other.
- AC-47A (extends AC-47; D-73): Given a goodwill refund line (no `refund_of_item_id`) for a product
  that is now inactive (`is_active = false`) or soft-deleted, When `RefundSale` runs, Then
  `product_not_sellable` is **not** raised for it (the AC-47 sellability check, which covers
  `is_active`, `is_sellable` and `variant_parent`, is skipped for goodwill refund lines). The
  engine's own refusals still apply (AC-53): a soft-deleted product is still refused
  `unknown_product` until owner question Q-1 is ruled; an inactive product that still exists is
  quoted normally. The sellability check still applies to every `AddSaleLine`/`UpdateSaleLine`.
- AC-14A (extends AC-14 and the Revision 4 zero-total clause; D-74): Given a `draft` sale with at
  least one line and `sales.total = 0.0000` (for example, fully discounted), When `CompleteSale`
  runs, Then it takes the same completion path as any other sale, in one transaction: it allocates
  `sales.number` through `DocumentNumbers::next('sale', $branchId)` (same sequence and prefix),
  consumes `DiscountUsage` once per distinct `discount_id` used, posts any credit-ledger entries the
  sale's tenders require (none, when there are no tender rows), writes the one `completed` audit
  row, sets `status = completed`, `completed_at`, `paid_total = 0.0000`, `change_due = 0.0000`, and
  performs no stock write, no GL posting and leaves `stock_applied = false` (D-1, AC-15, as for
  every sale). No `sale_tenders` row is required, and the "sum of tenders equals total" rule of
  AC-14 applies unchanged (an empty sum of `0.0000` equals the total). Given a zero-total draft
  with no lines, Then it is not completable (AC-14 as amended requires at least one line).

Revision 6 — three lead rulings (D-75 to D-77), again as suffix ACs:

- AC-47B (extends AC-47 and AC-47A; D-75): Given a referenced refund line (one with `refund_of_item_id`)
  for a product that is now inactive (`is_active = false`), not sellable (`is_sellable = false`) or of
  type `variant_parent`, When `RefundSale` runs, Then `product_not_sellable` is not raised for it. The
  AC-47 sellability check therefore applies only to `AddSaleLine` and `UpdateSaleLine`; no refund line,
  referenced or goodwill, is subject to it. The engine's own refusals (AC-53, including `unknown_product`
  for a soft-deleted product) still apply until owner question Q-1 is ruled.
- AC-12B (extends AC-12 and AC-30; D-76): Given a refund tender whose offered payout exceeds what is
  still owed back, When `RefundSale` records it, Then it is refused `exceeds_balance` and nothing is
  written, even when the payment method has `allows_change = true`. A refund tender stores `tendered =
  null` and `change_given = 0.0000`. Given a refund tender when nothing is owed back, Then it is refused
  `exceeds_balance`. Ordinary sale tenders are unchanged: `allows_change` still decides whether an
  over-tender is change or `exceeds_balance` (AC-11, AC-12).
- AC-27A (amends AC-27 and D-38; D-77): Given a referenced refund line whose original sale line carried
  a price override (the original line's `price_overridden_by` is set), When `RefundSale` prices it, Then
  the refund line's `unit_price` is the original line's stored `unit_price` exactly (the figure the
  customer was charged, which an approval bound under AC-7/D-47), and it is never the engine's list or
  ladder price. The figures are built as the override path of AC-7 builds them, with the refund line's
  own unit snapshot: `list_price` and `tax_group_id` come from one `quoteLine()` call made with the
  same anchors as D-38 (the original sale's `completed_at` as `at`, its `business_date` as
  `documentDate`, its customer and group, its branch and currency); that quote's price is not used;
  `subtotal = round(stored unit_price × refund quantity, 4)`, the one multiplication of AC-7/D-40,
  confined to `Support/Money.php`; tax is the engine's `computeTax()` on that `subtotal` with the same
  tax context the sale's override path uses; `discount_id = null`, `discount_amount = 0.0000`,
  `discount_percent = null`; `line_total = tax.net + tax.total`. Then:
  - the refund line needs no price-override approval and does not read the product's current
    `allow_price_override`; the refund's own approval (AC-29) is the only approval consumed, and the
    refund line's `price_overridden_by` is `null`;
  - a partial refund is the same stored unit price times the smaller quantity, so the refund of an
    overridden line is never more than was paid for that quantity;
  - the refund line's unit must be the original line's own unit: a refund of an overridden line in any
    other unit (including the product's stock unit) is refused `inherited_unit_mismatch`, naming both
    units, because converting a stored line-unit price to another unit would be division this task does
    not perform (AC-42; see Assumptions);
  - the engine's live-state refusals (AC-53) still apply to the one `quoteLine()` call;
  - every referenced line whose original carried no override keeps D-38 and AC-27 unchanged, and a
    goodwill line is unaffected.
  Given a line sold at an approved override of 8.00 against a list price of 10.00, When it is refunded
  in full, Then the refund line's `unit_price` is 8.00 and its `subtotal` is 8.00 × quantity. Given the
  same line refunded in part, Then `unit_price` is still 8.00. Given a line sold without an override,
  Then it is quoted by the engine as in AC-27.

Revision 7 — lead rulings from code review round 1 (D-78 to D-80), again as suffix ACs:

- AC-27B (extends AC-27 and AC-27A; D-78, review W-1): Given a referenced refund line (one with
  `refund_of_item_id`), When `RefundSale` is about to write it, Then it sums `line_total` over every
  existing refund line whose `refund_of_item_id` is that same original line, adds the new line's
  `line_total`, and refuses `over_refund` with nothing written if the sum would exceed the original
  line's `line_total`. This holds whether the line was re-quoted (AC-27) or built at the stored override
  price (AC-27A). Given a refund that takes the last remaining quantity of the original line (the
  original `refunded_quantity` plus this quantity equals its `base_quantity`), Then the refund line's
  `line_total` is the original `line_total` minus the sum already refunded (a subtraction, not the
  engine's figure), so a full set of partial refunds always sums exactly to the original charge. Given
  a last-quantity refund where the original line has nothing left to refund in money (earlier partial
  refunds already took the whole charge, so the remainder is exactly 0.0000), Then the refund is
  ACCEPTED (D-82, review W-9): it writes a refund line with `line_total` 0.0000, a refund document
  with a zero total and no tender, and the original line becomes fully refunded (`refunded_quantity`
  reaches `base_quantity`); it is not refused, because otherwise that line could never be fully
  refunded. AC-28's `over_refund`/`fully_refunded` still applies when the original line's quantity is
  already fully refunded. Consequences of the linear remainder rule, accepted (D-83, D-84): on a
  last-quantity refund line `unit_price` and `list_price` are informational (the re-quote) and
  receipts and totals use `line_total`; on a fixed-amount line discount the remainder may store a
  negative `discount_amount` (and in tiered cases a negative `subtotal`) while `line_total` stays
  correct, net plus tax equals `line_total`, and every sum is exact; the refund header
  `discount_total` may therefore be negative. Given a re-quote that has drifted above the remaining
  charge, Then a partial refund can be refused `over_refund` while only the whole remaining line (or a
  goodwill refund) goes through (D-85, W-10); this is this AC working as designed. Given a goodwill refund line
  (no `refund_of_item_id`), Then this cap does not apply. AC-27's tier and `min_amount` behaviour
  otherwise stands. The `over_refund` code is shared with AC-28; its message names whether the
  quantity or the money limit was hit. The cap is checked under the same locks as AC-28's quantity
  check, so two concurrent refunds cannot together pass it.
- AC-13A (amends AC-11, AC-13 and AC-14; D-79, review W-3): Given a sale in `pending_payment`, When
  `AddTender` records a tender, Then in the same transaction and under the sale lock `sales.paid_total`
  is set to the exact sum of that sale's tender amounts and `sales.balance_due` to `sales.total −
  paid_total`. Given `RemoveTender` on a `pending_payment` sale, Then the same two columns are recomputed
  the same way (and removing the last tender still returns the sale to `draft`, AC-13 amended, with
  `paid_total = 0.0000` and `balance_due = total`). Given a `GetSale` or `ListSales` read of a
  `pending_payment` sale, Then it shows the true amount owing from those columns. Given an `AddTender`
  that a refusal rejects, Then neither column changes. `change_due` is still written only at completion.
  A refund sale's tenders are negative-signed (AC-30); its `paid_total`/`balance_due` are not defined by
  this AC and stay as built.
- AC-55A (extends AC-55 and AC-7; D-80, review W-4): Given an approval reused for a same-line re-price
  (AC-55), When `UpdateSaleLine` re-prices the line, Then `price_overridden_by` still names the approver
  who approved that line's override, never the requester and never an approver of another line's request.
  Given more than one consumed approval on the sale that matches the line's `{product_id, unit_id,
  unit_price}`, Then the matching prefers the row whose decider equals the line's current
  `price_overridden_by`; only if none does may it fall back to another matching row, and then the line's
  `price_overridden_by` becomes that row's decider. Given a re-price that needs a new approval (a
  different price), Then AC-7 applies as before.

## Out of scope

- Approval hardening named in review W-2 (requester binding, expiry, a goodwill refund amount ceiling),
  branch-scope and permission checks inside each write slice (W-5), a shared customer lock in
  `CreateSale` (W-6) and returning the stored result on a `CompleteSale` retry (S-1): deferred, see
  D-81 for each reason.
- Real-time stock validation and decrement on sale completion (FP-234, its own backlog row — D-1).
- Costing/COGS (`unit_cost`, `line_cost`, `cost_total`) and any GL/journal posting (Finance,
  FP-260/FP-263 — D-2, D-6).
- The full payment-gateway/settlement/AR-allocation apparatus of `docs/database.md` §6.8 beyond
  `payment_methods` and `sale_tenders` — `payments`, `payment_allocations`, `gateway_configs`,
  `gateway_transactions`, `settlements` (D-7).
- **Bill-level discounts** (`sales.bill_discount_id`/`bill_discount_value`) — deferred by the
  lead's own ruling (D-35/F-1): the engine's single returned amount needs a cross-line splitting
  and re-taxing rule this package is forbidden from inventing (AC-42); filed as a named follow-up
  for whichever task next owns it.
- `discounts.approval_threshold`/`workflow_definition_id` evaluation, and `min_selling_price`/
  `price_mode = open_price` enforcement (D-37, D-40 — each needs arithmetic or a rule this task
  does not have a documented basis for).
- `payment_methods.rounding_rule`/`surcharge_percent` application, and any rounding tolerance on
  `CompleteSale`'s balance check (D-42/F-11 — stored, not applied; the balance check is exact).
- `loyalty`/`voucher` tenders (D-41/F-17) and any live-gateway tender (D-7).
- Barcode-rule parsing and scale-label-derived quantities (FP-423 — D-8).
- Kitchen-station routing, batch/serial tracking on a sale line (FP-239/Inventory — D-9/D-33).
- Quotations, sales orders, the restaurant domain (floor areas, tables, orders, KOTs), and
  `type = exchange` sales (D-10) — each filed as its own forward note, not built here.
- Automatic loyalty-point earning on sale completion (no documented trigger rule exists — D-5); a
  caller may invoke Customers' own loyalty feature directly once both exist, now with a real
  `sale_id` to pass it (AC-45).
- Reserving `sale_tender` as a customers ledger reference type, with case-folding of the reserved
  check (FP-435): a follow-up row for the lead, not built here (D-62).
- A refund path that skips the engine's live-state checks (Q-1 option b), and tombstones for
  removed tenders before offline replay (D-60).
- A forced header-level price-list override via `sales.price_list_id` (D-11) — the column is
  stored but not yet read as a forcing parameter, because the pricing contract does not expose one.
- `approval_requests.decision_method = remote`, and `type` in `{credit_limit, negative_stock,
  custom}` (D-12, D-13); a refund-approval amount ceiling (D-47).
- Maintaining `customers.first_sale_at`/`last_sale_at`/`lifetime_value` and reassigning `sales.
  customer_id` on a customer merge, and a credit-limit override/approval-and-retry path (D-34/F-9)
  — each re-derivable later, no documented urgency.
- Real offline sync of any kind (FP-237) — `[SYNC]` columns are built present but inert (D-28);
  per-terminal reserved number prefixes for the offline case (§12) also stay with FP-237.
- An outbox `sale.completed` event — a follow-up once FP-234 or Finance needs one.
- The Till frontend itself (FP-236), its routes/permission wiring, and the Angular/offline client
  (FP-237) — this is a backend-only spec with no HTTP routes or console commands of its own.

## Non-functional requirements

- Every list feature ships with offset-or-cursor pagination from its first version (D-26).
- Every money/quantity column uses one of this schema's four already-allowed precision pairs
  (D-45/F-19, AC-44); no new pair is introduced.
- Every financial write (tender, void-reversal, refund, shift close/reconcile) is attributed to an
  actor and audited, one row per write, writing nothing on refusal — the same discipline every
  prior task in this backlog applies.
- `AddTender`/`RecordCashMovement`/`CloseCashShift`/`ReconcileCashShift` are each idempotent by a
  caller-supplied identifier or (for the shift lifecycle) the shift's own id (AC-37, D-50),
  matching the precedent this codebase already sets for financial replay safety.
- `Contracts\Access\PinVerifier` is always called outside any transaction this task opens, so a
  failed verification's lockout effect is never rolled back by an unrelated later refusal in the
  same request (D-30, AC-39).
- `sales.approvals.decide` and `sales.shifts.reconcile` are the **first real branch-scoped
  permission keys** in the codebase (Access and Organization declare only company-wide ones). The
  resolver's branch path has so far been proven only with fixture keys, so the tests cover both a
  branch-scoped grant and a company-wide (null-scope) grant, and a branch-scoped key asked with no
  branch is refused (D-69).
- Locks are taken in one global order (D-61) and every write's first statement is a locking read.
- A customer ledger posting writes its own audit row, so the audit invariant is one Sales audit row
  per write plus one Customers audit row per ledger posting.
- No new `(precision, scale)` pair is added to `TenantDecimalPrecisionTest`'s allow-list.

## Open questions

No BLOCKING question for Chunk A. Two **owner-level** questions are OPEN: the lead directed they be
recorded with their built default and not decided here (D-55, D-56). Every other judgement call is
resolved and logged in `docs/decisions/T-030-sales.md` (D-1 to D-74).

**Owner questions (OPEN, a default is built until ruled):**

1. **Q-1 [OWNER; must be ruled before Chunk D (refunds) is accepted] Refunding a line whose
   discount or product has changed.** A refund line is re-quoted through the pricing engine, which
   checks the discount's and the product's state **today**. So a line cannot be refunded once its
   discount has filled its usage limit (the N-th sale's own use makes all N sales unrefundable on
   that line), been deactivated or deleted, or `allow_discount` was turned off; and a soft-deleted
   product cannot be refunded at all, goodwill included. **Default built (AC-53):** the refund is
   refused with the engine's reason and nothing is written. Options and cost:
   - (a) Refuse and tell the cashier (default). Costs nothing to build and keeps AC-42 and D-38
     intact; costs a customer who cannot get a legitimate return accepted, with no in-system
     remedy, for any discontinued product.
   - (b) Refund the original sale line's recorded amounts without re-quoting. Always refundable
     and exact for a full-quantity refund; but a partial refund then needs a proportional split of
     the recorded amounts, which is arithmetic AC-42 forbids Sales from doing, so it needs either a
     rule exempting that one calculation or an additive pricing quote mode that skips live-state
     checks (a T-029 engine change, its fixtures and FP-432's TypeScript twin).
2. **Q-2 [OWNER; must be ruled before Chunk C (the cart) is accepted] Zero-priced products.**
   `products.selling_price` is NOT NULL DEFAULT 0 (backlog FP-440), so an unpriced product quotes
   `0.0000` through the real engine and `no_price_resolved` is unreachable. With the rule that a
   draft with total `0.0000` and at least one line completes with no tender, an unpriced product can
   leave the shop free. **Default built:** accept a `0.0000` line. Safer alternative: refuse a line
   priced `0.0000` from the product's own price (ladder step 6) unless explicitly marked free
   (for example through an approved price override); cost is a new refusal and an approval for every
   genuine giveaway.

**Not blocking, unconfirmed until built:**

1. The literal signatures on `Contracts\Pricing` and Customers' credit ledger were reconciled in
   revision 4 against the shipped code (see header); only the not-yet-built contracts
   (`snapshotInherited`, `PinVerifier`, `DocumentNumbers`, `DiscountUsage`) are still intent.
2. Identity's exact `PermissionResolver`/`TerminalAuthority` symbol shapes (D-30, Ruling 5 of the
   plan) are read directly from the shipped `packages/access`/`packages/organization` source at
   build time; both owning tasks (T-010, T-014) are DONE, so this is a lookup, not an open
   dependency, but the literal signature is not restated here to avoid this spec going stale the
   moment either package's own internals are refactored.

## Assumptions

- `packages/pricing` (T-029) and `packages/customers` (T-028) will each ship the published
  contracts their own decisions/plans describe (`PricingEngine`, `DiscountUsage`; a customer
  credit-ledger writer keyed per `customer_ledger`'s real unique index) before this task's own
  code is written — this spec is written to consume both, not to redesign either, and carries no
  fallback if either ships a materially different shape (that would be a further plan-stage
  finding for this task's own `architect` to route back to `ba`, exactly as happened on
  T-027/T-028/T-029 themselves, and as `architect6` has already done once for this task).
- `Contracts\Access\PinVerifier` and `Contracts\Organization\DocumentNumbers` (both new,
  D-30/D-31) can be added to their respective packages as purely additive contracts without a
  structural change to either package — if either cannot be, that is a further plan-stage finding,
  not a blocking question here.
- No tenant has a payment gateway configured at launch (`docs/architecture.md` §23, open question
  3's stated default), which is what makes D-7's narrower scope ("tenders only, no gateway
  apparatus") a safe, reversible cut rather than a feature gap anyone depends on today.
- AC-27A's same-unit rule for an overridden line is the BA's reading, not a ruling: the lead's ruling
  names the stored unit price, and a stored line-unit price cannot be restated in another unit without
  a division this package may not perform. It reuses the existing `inherited_unit_mismatch` code rather
  than adding one. Reversible by the lead (D-77). The tax context for the override tax is whatever the
  sale's own override path passes (`LineWriter`); the spec does not name a separate refund context.
- Price-list rows are not versioned anywhere in this schema (confirmed against `docs/database.md`
  §6.4), which is why D-38's "refund at the original price" still has one residual, named gap (a
  list row edited rather than replaced after the sale) rather than a fully closed guarantee — closing
  that gap needs arithmetic this task's own AC-42 forbids it from performing.
