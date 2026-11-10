# Plan: T-030 Sales: cart, tenders, hold and recall, refunds, voids, cash shifts

Spec: `docs/specs/T-030-sales.md` (AC-1 to AC-41). Decisions: `docs/decisions/T-030-sales.md` (D-1 to D-28). Branch `ai/T-030-sales`, planned at `a2e6631`. This is revision 1, written **spec-ahead**: no code is written from it until T-028 and T-029 are DONE and this branch is rebased onto their tip.

**Branch baseline (read this first).** This branch was cut from `main` at `4ece505`. That tree has **no** `packages/access`, `catalog`, `organization`, `customers` or `pricing`, and its `docs/` predate v0.5. Every contract below was read on the sibling worktrees:
- `build-56f213` (T-028 at `180544b`, chunks A to D committed): `packages/contracts/src/{Catalog,Customers,Access,Organization}`, `packages/catalog/src/Units`, `packages/access/src/Features/VerifyPin`, and the tenant migrations;
- `taxes-pricing` (T-029 at `0a6cbb5`): **plan only, no code yet**. `Contracts\Pricing` is known only from T-029 plan Ruling 2.

The rebase is therefore not optional. It brings in about 60 tenant migrations, and step 0 recomputes every number in this plan that depends on them.

**`ba6` could not be messaged.** I have no messaging tool in this session. Every finding is in section 0.2 for `lead` to route. Where a finding is a small inconsistency, the plan applies the resolution and says so. Where it changes behaviour, the plan builds the **proposed** resolution and marks the step contingent, the same way T-028 and T-029 revision 1 did.

---

## 0. Verification and findings

### 0.1 The spec's premises, re-read against source

| Spec claim | Verdict | Evidence |
|---|---|---|
| D-3: `UnitResolver` is published and `snapshotInherited()` reuses `SnapshotCalculator` | **Holds. The signature does not work as written (F-7)** | `Contracts\Catalog\UnitResolver` has `resolve()` and `snapshot()`. `SnapshotCalculator::compute(ResolvedFactor, lineUnitId, lineUnitCode, lineUnitPrecision, stockUnitPrecision, quantity)` is pure and takes a `ResolvedFactor`, so an inherited factor can be fed in directly. D-3's proposed signature has no `productId` and no `quantity`, and it trusts a caller-supplied `stockUnitId`. `FactorSource` has no `inherited` case |
| D-4: `PricingEngine::quoteLine()` / `billDiscount()` | **Holds per T-029 Ruling 2. Not yet shipped** | `LineQuote` needs `productId`, `UnitSnapshot`, `at` (UTC instant), `documentDate`, `currency`, `?customerId`, `?customerGroupId`, `?branchId`, `?discountId`. AC-4 names only customer, branch and business date. Sales must also supply `at`, `currency` (from `branches.currency`, a required `CHAR(3)`) and `customerGroupId` (from `customers.group_id`). `PricedLine` returns `subtotal` and `discountedSubtotal`, which `sale_items` has no column for (F-18) |
| AC-8: bill discount through `billDiscount()` | **Incomplete (F-1)** | T-029 D-19 and its section 10: `billDiscount()` returns one amount. Allocating it across lines and re-taxing each line is left to "the future Sales caller", with no allocation rule. AC-38 forbids Sales doing discount arithmetic |
| D-5: the credit ledger, keyed on `sales.id` | **The contract exists. The key does not work (F-2, F-3)** | `Contracts\Customers\CustomerCreditLedger::record(CreditLedgerEntry): CreditLedgerPosting`, throwing `CreditLedgerRefused` (15 `CreditRefusal` cases). `customer_ledger` is unique on `(reference_type, reference_id, type)`. With the key on `sales.id`, two credit tenders on one sale collide: same amount is a silent replay, a different amount is `replay_conflict`. The ledger may be called inside the caller's transaction (its docblock) |
| D-12 and Assumptions: PIN verification "callable through a published contract" | **False (F-5)** | No `Contracts\Access` symbol verifies a PIN. `Flexipos\Access\Features\VerifyPin\VerifyPinHandler` is internal: it takes an employee **code**, returns `VerifyPinResult` (one refusal, no cause), and writes `access_log` plus a lockout counter **in its own transaction**. Deptrac denies `Package_Access` internals to other packages |
| D-20: "Organization's existing `number_sequences` mechanism" | **Only the table exists (F-6)** | `0001_01_01_000004_create_number_sequences_table.php` creates it. No package reads or writes it, and no contract allocates from it. `sales.number` is unique across **both** `sale` and `refund`, so two sequences without prefixes would collide |
| D-6 / D-7: `ar_document_id`, `financial_account_id`, `gateway_transaction_id`, `payment_id` built nullable with no key | **Contradicts the settled precedent (F-8)** | T-029 F-17 reversed exactly this ("a nullable, unkeyed column gives Finance nothing"). T-028 D-6 and T-027 D-3 defer such columns by **not building them**. AC-39 already asks for a proof of absence, which is the same shape |
| T-028's debts to FP-233 | **Missing from the spec (F-9)** | T-028 plan section 10: `loyalty_transactions.sale_id` plus `unique(sale_id, type)`, as expand plus contract; `first_sale_at`/`last_sale_at`/`lifetime_value`; reassigning `sales.customer_id` on merge; the credit-limit override |
| T-029's debts to FP-233 | **Partly missing (F-12, F-16)** | T-029 plan section 10: increment `discounts.used_count` under a row lock; evaluate `requires_approval`/`approval_threshold`; `price_mode = open_price`, `allow_price_override`, `min_selling_price` |
| D-22: one open shift per terminal | **Holds** | `terminals` has `branch_id` and `location_id`, both required. `Contracts\Organization\TerminalAuthority::activeBranchOf()` gives the branch of an active terminal |
| D-27: every new money column `(18,4)` | **Holds for money. AC-40's "all at (18,4)" is too strong (F-19)** | §3.2 sets factor `(18,6)`, rate/percent `(9,4)` and exchange rate `(18,8)`. `unit_factor`, `discount_percent`, `surcharge_percent` and `exchange_rate` use those pairs. All four are already allowed, so no fifth pair is introduced |

### 0.2 Findings for `ba` (routed through `lead`)

**Changes behaviour. The plan builds the proposal and marks the step contingent.**

- **F-1 (AC-8, AC-38): bill-level discount.** `billDiscount()` returns a single amount. To be correct, it must be split across lines and each line re-taxed through `computeTax()`. Otherwise an exclusive tax is charged on the undiscounted base, and the stored tax is wrong. The split is discount arithmetic (a proportional share plus a residue rule), which AC-38 forbids in this package. T-029 D-19 ruled no allocation rule either. Refunding a line from a bill-discounted sale then needs that line's share, which is undefined too.
  - **Proposal (the plan default):** defer bill-level discounts to a follow-up row. `sales.bill_discount_id` and `bill_discount_value` are built, nullable and never written. `CreateSale` refuses both fields as unknown.
  - **Alternative, if `ba` keeps AC-8:**
    - one pure class, `Sales\Engine\BillDiscountAllocator`, splits in proportion to `discountedSubtotal`, rounds HALF_UP to 4 dp, and puts the residue on the largest line (ties go to the lowest `line_no`);
    - each line is re-taxed via `computeTax()`;
    - a new `sale_items.bill_discount_amount` column;
    - a refund line takes its share in proportion to the refunded base quantity;
    - AC-38 gains one named exemption.
  - That adds about two steps to chunk C and a rule the owner should confirm.
- **F-2 (D-5, AC-12): the ledger key is the tender, not the sale.** `reference_type = 'sale_tender'`, `reference_id = sale_tenders.id`. One ledger row per credit tender, and the unique key is a true replay guard.
- **F-3 (AC-12, AC-13, AC-21): post credit at `CompleteSale`, not at `AddTender`.**
  - As written, AC-12 posts a `credit_sale` debit when the tender is added. AC-13 then deletes that tender outright, leaving the debit orphaned. Its stated reason ("no money has moved outside this task's own bookkeeping") is false for a credit tender.
  - **Proposal:** `AddTender` checks only that the sale has a customer (`customer_required`). `CompleteSale` posts one `credit_sale` per credit tender, inside its own transaction, and refuses `credit_refused` (carrying the `CreditRefusal` value) with nothing written. This is §12.1's shape ("[credit sale] … INSERT customer_ledger" inside the completion transaction).
  - Consequences: a void of a not-yet-completed sale has no ledger entry to compensate; `RemoveTender` stays a plain delete.
- **F-4 (D-16): the void compensation is `credit_note`, not `payment`.** `payment` is "a customer-initiated payment" in T-028's enum. A void is "a documented credit correction reducing what is owed", which is `credit_note`'s own description. Both sit on the credit side, so either passes `wrong_side`. The key is `('sale_tender', <mirror tender id>, credit_note)`.
- **F-5 (D-12, AC-23): there is no PIN contract, and a PIN is not authority.**
  - **(a)** Add `Contracts\Access\PinVerifier` (`verify(string $employeeCode, string $pin, ?string $ip = null): ?string`, returning the employee id or null). `packages/access` implements it by delegating to `VerifyPinHandler`.
  - **(b)** `DecideApproval` must call it **before** opening its own transaction. If the call runs inside a transaction that then rolls back (for example `self_approval_not_allowed`), the savepoint rolls back the failure counter too, and the lockout never fires.
  - **(c)** A correct PIN proves who is deciding, not that they may decide. Proposal: two branch-scoped permission keys, `sales.approvals.decide` and `sales.shifts.reconcile`, declared by the sales provider and checked through `PermissionResolver::decide()`. A refusal is `not_authorised`. Without this, any cashier with a PIN approves any other cashier's void, and D-15's segregation is half a control.
- **F-6 (D-20): numbering needs an allocator.** Add `Contracts\Organization\DocumentNumbers` (`next(string $documentType, ?string $branchId): string`), implemented in `packages/organization` (§6.1's owner), callable inside the caller's transaction.
  - It locks the branch row, otherwise the company-wide row. When neither exists, it inserts a company-wide default.
  - **Proposed defaults:**

    | Type | Prefix | Padding | Example |
    |---|---|---|---|
    | `sale` | `S` | 6 | `S000001` |
    | `refund` | `R` | 6 | `R000001` |
    | `cash_shift` | `SH` | 6 | `SH000001` |

    `reset_period = never` throughout. The prefixes must differ because `sales.number` is unique across both sale types.
  - `cash_shift` is a new `document_type` value. The column is VARCHAR(30) with an application enum, so this is not a schema change.
  - `reset_period` other than `never` is refused (`unsupported_reset_period`). Nothing can create such a row today, because there is no numbering CRUD.
  - Portable `lockForUpdate()` plus increment, not MySQL's `LAST_INSERT_ID()` trick, because the suite runs on SQLite. Concurrent first use races on the unique key, so the loser retries once.
  - §12's "allocated per terminal from a reserved prefix" is the offline case (FP-237) and is not built here.
- **F-7 (D-3, AC-26, AC-41): what "base to base" means.**
  - §6.5: a referencing line "inherits the referenced line's `unit_factor` when it uses the same `unit_id`, and otherwise converts **base to base**. It never re-resolves through the ladder."
  - The only reading that never touches the ladder: if the requested unit is the referenced line's unit, take its factor; if the requested unit is the product's **stock** unit, the factor is `1.000000`; any other unit is refused `inherited_unit_mismatch`.
  - AC-41's "a different, but still resolvable, unit" becomes "the stock unit".
  - **Signature:** `snapshotInherited(string $productId, string $referencedUnitId, string $referencedUnitFactor, string $unitId, int|string $quantity): UnitSnapshot`. The resolver reads the stock unit itself and never trusts a caller's.
  - **New enum cases:** `FactorSource::Inherited` and `UnitRefusal::InheritedUnitMismatch`.
- **F-10 (AC-6, AC-17): a sale held from `pending_payment` must not be editable.** AC-6 allows line edits in `held`, but a sale held from `pending_payment` has tenders, and AC-6's own reason applies to it. Lines are editable when `status = draft`, or `held` with its recorded `from_status = draft`. Otherwise the edit is refused `sale_not_editable`.
- **F-12 (D-13; T-029 D-8 and section 10): discount approval and discount usage.**
  - No AC consumes a `type = discount` approval.
  - **Proposal:**
    - when a line's `discount_id` names a discount with `requires_approval = true`, the line needs a consumed `discount` approval whose `payload.discount_id` matches, or it is refused `approval_required`;
    - `discounts.requires_approval` is read directly, as a read-only fact (T-029 Ruling 1's `Lookup` precedent);
    - `approval_threshold` and `workflow_definition_id` are not evaluated (follow-up), because what a threshold compares against is undocumented.
  - **Usage:**
    - T-029 assigned the `used_count` increment to FP-233. Without it, `usage_limit` never fires.
    - Add `Contracts\Pricing\DiscountUsage::consume(string $discountId): void`, implemented in `packages/pricing` under `lockForUpdate()` and throwing `PricingRefused(usage_limit_reached)`.
    - `CompleteSale` calls it once per distinct discount on the sale. Usage is never released, on void or on refund (conservative; reversible).
- **F-13 (AC-27, D-17): at what instant a refund line is re-quoted.**
  - Re-quoting "now" pays back today's price, not what was paid.
  - **Proposal:** a referenced refund line is quoted with the original sale's `completed_at` as `at`, the original `business_date` as `documentDate`, and the original line's `discount_id`. A goodwill line is quoted at the refund's own instant.
  - Price-list rows edited since the sale still change the answer, because price lists are not versioned. That is in "Questions for the human".
- **F-15 (AC-34, AC-35; D-22): shift edges the spec leaves open.**
  - `CloseCashShift` refuses `shift_has_open_sales` while a `draft` or `pending_payment` sale names the shift. `held` sales do not block.
  - `RecallSale` moves `sales.shift_id` to the recalling terminal's open shift (refused `no_open_shift`).
  - Voiding a **completed** sale is allowed only while its shift is still `open` (`shift_closed`, so the cashier uses a refund instead). Otherwise the mirror tenders land on a closed shift, and the drawer actually paying out never sees them.
  - **Movement signs:** `cash_in` and `float_in` add; `cash_out`, `float_out`, `drop`, `petty_cash` and `bank_deposit` subtract. `amount` is always positive.
  - **AC-35's figures:**
    - `sales_count` counts `type = sale` rows with status `completed`, `refunded` or `partially_refunded`, and `refunds_count` counts `type = refund` rows;
    - `gross`, `discounts` and `tax` sum `subtotal`, `discount_total` and `tax_total` over those sales;
    - `net` is Σ sale `total` − Σ refund `total`;
    - `totals_by_method` sums the signed `amount` per `method_code` (AC-35 says "tendered", but `tendered` includes change that went back out of the drawer).
- **F-16 (AC-7; T-029 section 10): price-override rules.**
  - An override is refused `price_override_not_allowed` when `products.allow_price_override = false`.
  - An overridden line carries no item discount (`discount_with_override`).
  - It is priced by `quoteLine()` (for `listPrice` and `taxGroupId`), then `subtotal = round(override × quantity, 4)` and `computeTax()` on that subtotal. The line total is `tax.net + tax.total`, which holds for inclusive and exclusive groups alike, so Sales never branches on inclusivity.
  - `min_selling_price` is per **stock** unit, so enforcing it needs a factor multiplication, which AC-38 forbids. Follow-up.
  - `price_mode = open_price` is a follow-up too: such a product goes through an approved override today.
- **F-17 (AC-12): `loyalty` and `voucher` tenders are refused** (`unsupported_method_type`). Neither has a backing ledger in this task: a loyalty tender redeems no points and a voucher burns no voucher, so either would be free money.

**Small inconsistencies. The plan applies these, and `ba` folds them in.**

- **F-8:** `sales.ar_document_id`, `cash_movements.financial_account_id`, `sale_tenders.gateway_transaction_id`/`payment_id`, `payment_methods.financial_account_id`/`gateway`/`settings`, `sale_items.batch_id`/`serial_id`/`kitchen_station_id`, `sales.sales_order_id`/`order_id`/`journal_entry_id`, `cash_shifts.journal_entry_id` and every `[CF]` `custom_fields` are **not built**. AC-9's `kitchen_station_id` contradicts D-9; D-9 wins. AC-33's "stays unset" becomes "not built". AC-39's absence list grows to match.
- **F-9:** `loyalty_transactions.sale_id` plus `unique(sale_id, type)` (expand) and its FK onto `sales` (contract) are built here, discharging T-028's debt. Wiring `RecordLoyaltyTransaction` to accept a `sale_id` is left to its first caller (D-5 builds no earn). Maintaining `first_sale_at`/`last_sale_at`/`lifetime_value` and reassigning `sales.customer_id` on merge are follow-ups; all three can be recomputed from `sales` at any time, so nothing is lost by deferring them.
- **F-11 (AC-14):** no rounding tolerance is configured anywhere, so it is zero. `sales.rounding` and `service_charge` are written `0.0000`. `payment_methods.rounding_rule` and `surcharge_percent` are stored but not applied (follow-up).
- **F-14 (AC-25 to AC-30): refund signs.** A refund's line quantities and money columns are positive, because the engine's inputs are unsigned and the document `type` carries the direction. Its tenders are negative. It completes when Σ `amount` = −`total`.
- **F-18 (AC-17, AC-19, AC-24, AC-8): three undocumented schema additions the ACs need:**
  - `sale_holds` (the hold/recall trail and the recorded `from_status`);
  - `approval_requests.consumed_at` (AC-24's "marks it consumed");
  - `sale_items.subtotal` (so the header rollup is a plain sum and never branches on inclusivity).

  Each is documented in `docs/database.md` "As built (T-030)".
- **F-19: stale cross-references.**
  - AC-11's "AC-27" should be AC-37;
  - AC-13's "AC-16's void/refund paths" should be AC-21/AC-25;
  - AC-26's "AC-19/D-19" should be AC-25/D-19;
  - D-2's "(D-9)" should be D-18;
  - D-3's "the only edit outside …" is false (F-5, F-6, F-9, F-12 add four more);
  - D-13 says a refund approval is triggered by "refunding without the original sale present", while D-14 and AC-29 require one for every refund (the plan follows D-14);
  - AC-40's "all at (18,4)" should be "money and quantity at (18,4)" (see 0.1).
- **F-20:** `sale_items.discount_percent` is written `null`. `PricedLine` has no item-discount percent, and its `listDiscountPercent` is the price-list percent (T-029 F-11), which is a different thing. AC-4's column list does not include it either.
- **F-21 (AC-23, AC-24): what an approval is bound to.** An approval is consumed only when its `type`, `branch_id`, `reference_type`/`reference_id` and (where stated) payload all match the write. The binding is in section 4 under `approval_requests`. A refund approval carries no amount ceiling (risk 5).

### 0.3 Rulings

**Ruling 1: placement.** A new path package, `packages/sales` (`flexipos/sales`, namespace `Flexipos\Sales\`). It publishes **no** contract yet, because nothing outside it calls Sales today. FP-234 adds one when it needs it.
- **Direct reads**, limited to existence checks and read-only facts (T-029 Ruling 1's precedent, the `Lookup` helper):
  - `products` (name, sku, category, brand, `allow_price_override`, soft delete);
  - `customers` (name, phone, `tax_no`, `group_id`, soft delete, `merged_into_id`);
  - `terminals` (`location_id`, status);
  - `branches` (`timezone`, `currency`);
  - `discounts.requires_approval` (F-12).
- **Never written outside its own tables.** Sales never names `customer_ledger`, `credit_balance`, `balance_after`, `number_sequences`, `used_count`, `unit_conversions`, `base_unit_id` or any T-029 tax/price token. The step 23 scan enforces that.
- `Support/` (`TenantConnection`, `Fields`, `Identifier`, `Lookup`, `Pager`, `Page`, `Decimal`) is the **tenth** copy (FP-331).

**Ruling 2: four small contract additions in other packages, in chunk A.** Each is additive, and each owner's own tests cover it:

| Contract | Implemented in | Finding |
|---|---|---|
| `Contracts\Catalog\UnitResolver::snapshotInherited()`, plus `FactorSource::Inherited` and `UnitRefusal::InheritedUnitMismatch` | `packages/catalog/src/Units` (`DatabaseUnitResolver` and a pure `InheritedFactor`) | D-3, F-7 |
| `Contracts\Access\PinVerifier` | `packages/access` (`Pins\HandlerPinVerifier` delegating to `VerifyPinHandler`) | F-5 |
| `Contracts\Organization\DocumentNumbers`, plus `DocumentNumbersRefused` | `packages/organization` (`Numbering\DatabaseDocumentNumbers`) | F-6 |
| `Contracts\Pricing\DiscountUsage` | `packages/pricing` (`Discounts\DatabaseDiscountUsage`) | F-12 |

**Ruling 3: arithmetic.** `brick/math` `BigDecimal` throughout, never a float, with 4-dp canonical strings. All of Sales' own arithmetic lives in **one file**, `Support/Money.php`, and step 23 asserts that no other file in the package multiplies, divides or calls `multipliedBy`/`dividedBy`. That arithmetic is:
- additions and subtractions of stored 4-dp amounts (rollups, balance, change, expected cash, variance);
- the single price-override product `round(unitPrice × quantity, 4)`, HALF_UP (F-16).

None of it is tax, discount or factor arithmetic, so AC-38 needs no exemption, unless F-1's alternative is chosen.

**Ruling 4: the sale state machine.**

| From | Command | To |
|---|---|---|
| — | `CreateSale` | `draft` |
| `draft` | first `AddTender` | `pending_payment` |
| `held` | first `AddTender` | `pending_payment`, only when held from `pending_payment`; a sale held from `draft` must be recalled first |
| `pending_payment` | last `RemoveTender` | `draft` |
| `draft`, `pending_payment` | `HoldSale` | `held` (records `from_status`) |
| `held` | `RecallSale` | the recorded `from_status` |
| `pending_payment`, or `draft` with total `0.0000` and at least one line | `CompleteSale` | `completed` |
| `draft`, `held`, `pending_payment` | `VoidSale` (no approval) | `voided` |
| `completed`, shift still open | `VoidSale` (approval) | `voided` |
| `completed`, `partially_refunded` | `RefundSale` against it | `partially_refunded` or `refunded` |

- AC-11 lists `held` among the statuses `AddTender` accepts. The plan allows that only for a sale held from `pending_payment`. A sale held from `draft` must be recalled first, which keeps the hold/recall trail honest.
- `failed` and `exchange` are unreachable.
- Every write locks the `sales` row `FOR UPDATE` first, so concurrent tenders cannot both pass the balance check.

**Ruling 5: the approval gate** (`Approvals\ApprovalGate::consume(type, branchId, referenceType, referenceId, ?payloadMatch, approvalId)`), called inside the consuming write's transaction:
- it locks the row;
- it refuses each of these by name:

  | Condition | Refusal |
  |---|---|
  | no such approval | `approval_required` |
  | `status ≠ approved` | `approval_not_approved` |
  | `consumed_at` already set | `approval_already_used` |
  | type, branch, reference or payload differs | `approval_mismatch` |

- otherwise it sets `consumed_at`.

`DecideApproval` runs in this order:
1. `PinVerifier` **outside** any transaction (F-5b). A null result is `pin_invalid`, and nothing in the sales tables is written. Access writes its own `access_log` row and counter, which is the point.
2. Then, in one transaction:
   - the row is locked and must be `pending` (`approval_not_pending`);
   - the decider must not be `requested_by`, nor the referenced sale's `employee_id` (`self_approval_not_allowed`);
   - `PermissionResolver` must grant `sales.approvals.decide` at the request's branch (`not_authorised`);
   - it sets `status`, `decided_by`, `decided_at`, `decision_method = pin_local` and `reason`.

**Ruling 6: idempotency (AC-11, AC-37).**
- **Caller-supplied ids:**
  - `AddTender` and `RecordCashMovement` take a caller-supplied ULID `id` as the row id (§6.8: "the row `id` equals the POS tender ULID");
  - a replay with the same payload returns the original row with `replayed = true` and writes nothing;
  - a different payload under that id is refused `replay_conflict`, naming the row (T-028 D-21's shape).
- **The shift as its own key:** `CloseCashShift` and `ReconcileCashShift` use the shift id. A close replayed against a shift that is already `closed` or `reconciled` with the same `counted_cash` returns the stored result. A different `counted_cash` is refused `replay_conflict`. A reconcile replayed against a `reconciled` shift is a no-op. No new table is needed.

**Ruling 7: transaction order** (§12.1: resolve before you lock):
1. `UnitResolver` and `PricingEngine` run **before** the `sales` row lock.
2. Inside the transaction come the `sales` lock, then the original sale's lock (refunds), then `DocumentNumbers`, `DiscountUsage` and `CustomerCreditLedger` (the customers lock), then the audit row.
3. There is no stock lock (D-1), so T-028's "stock before customers" order is trivially kept.

**Ruling 8: the slice shape.** Unchanged from T-027 to T-029:
- `Features/<X>/{X, XHandler, XResult}`;
- `array $fields` plus `?string $actorId`, with `Fields::only`;
- one audit row per write through `AuditTrail`, and nothing written on refusal;
- `SalesRefused` carries a `SalesRefusal` enum, and an engine, ledger, unit or numbering refusal is carried through under its own value (AC-5).

There are **26 slices**, and none of them has an HTTP route: the till (FP-236) and the API task own routes.

| Area | Slices | ACs |
|---|---|---|
| Payment methods | `CreatePaymentMethod`, `UpdatePaymentMethod`, `DeletePaymentMethod` | 16 |
| Shifts | `OpenCashShift`, `RecordCashMovement`, `CloseCashShift`, `ReconcileCashShift` | 32–37 |
| Approvals | `RequestApproval`, `DecideApproval` | 23, 24 |
| Cart | `CreateSale`, `AddSaleLine`, `UpdateSaleLine`, `RemoveSaleLine` | 1–10 |
| Tenders | `AddTender`, `RemoveTender`, `CompleteSale` | 11–15 |
| Hold | `HoldSale`, `RecallSale` | 17–20 |
| Corrections | `VoidSale`, `RefundSale` | 21, 22, 25–31 |
| Reads (D-26) | `ListSales` (branch required; status filter covers AC-20), `GetSale` (header plus tenders), `ListSaleLines`, `ListApprovalRequests`, `ListCashShifts`, `ListCashMovements` | 20, D-26 |

---

## Approach summary

1. A new `packages/sales` holds the till's documents and their state machine: payment methods, cash shifts and movements, PIN-gated approvals, sales with priced lines, tenders, hold and recall, void and refund. It does no stock, costing, GL or gateway work (D-1, D-6, D-7).
2. Every price, discount and tax figure comes verbatim from `Contracts\Pricing\PricingEngine`, and every unit quad from `Contracts\Catalog\UnitResolver`. Sales' own arithmetic is sums, differences and one override product, all in a single file (Ruling 3).
3. Four small cross-package contracts are added first: `snapshotInherited()` (owed by FP-233), `PinVerifier`, `DocumentNumbers` and `DiscountUsage`. T-028's `loyalty_transactions.sale_id` debt is discharged in schema.
4. Eight new tenant tables, plus two migrations on `loyalty_transactions`. Undocumented additions are named (F-18). Deferred columns are deferred by not building them (F-8).
5. Credit is posted to the customer ledger once per credit tender at completion, and compensated with `credit_note` on void or `refund` on refund (F-2 to F-4).
6. Static instruments cover the precision map, schema absences and the source-rules scan (AC-38), plus one engine test.

## Alternatives rejected

- **Building bill discounts with an allocator now:** it invents an unruled business rule and needs an AC-38 exemption (F-1; kept as `ba`'s alternative).
- **Posting credit at `AddTender`:** it makes `RemoveTender` corrupt the ledger (F-3).
- **Calling `VerifyPinHandler` from Sales directly:** deptrac denies it, and done inside a transaction it would roll back the lockout counter (F-5).
- **A Sales-local number counter:** it would make a second numbering scheme, which D-20 forbids. Allocation belongs to `number_sequences`' owner.
- **A generic `idempotency_keys` row for close and reconcile:** the shift id is already a natural, unique key (Ruling 6).
- **Re-resolving the unit for a refund in a third unit:** §6.5 forbids re-walking the ladder (F-7).
- **A Catalog/Customers "facts" contract for snapshots:** there is no second consumer yet. Existence and fact reads follow the established `Lookup` precedent.

## Files to change / create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/` (new symbols)
- `Catalog/UnitResolver.php`: add `snapshotInherited()` (Ruling 2, F-7). `Catalog/FactorSource.php`: `Inherited`. `Catalog/UnitRefusal.php`, `UnitResolutionRefused.php`: `InheritedUnitMismatch` plus its named constructor.
- `Access/PinVerifier.php` (new).
- `Organization/DocumentNumbers.php`, `DocumentNumbersRefused.php`, `DocumentNumbersRefusal.php` (new).
- `Pricing/DiscountUsage.php` (new; added to T-029's contract set after the rebase).

### `packages/catalog`
- `src/Units/InheritedFactor.php` (new, pure): it chooses the `ResolvedFactor` (referenced unit, stock unit, or refusal).
- `src/Units/DatabaseUnitResolver.php`: `snapshotInherited()` = load the stock unit and precisions → `InheritedFactor` → `SnapshotCalculator::compute()`. There is no ladder call.
- `fixtures/units/inherited-*.json` (new, at least 4): replayed by T-029's `UnitFixtureReplayTest`, extended with an `inherited` kind.
- `tests/Units/SnapshotInheritedTest.php` (new).

### `packages/access`
- `src/Pins/HandlerPinVerifier.php` (new). `src/AccessServiceProvider.php`: binds it. `tests/Pins/HandlerPinVerifierTest.php` (new).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php` (new). `src/OrganizationServiceProvider.php`: binds it. `tests/Numbering/DocumentNumbersTest.php` (new).

### `packages/pricing`
- `src/Discounts/DatabaseDiscountUsage.php` (new). `src/PricingServiceProvider.php`: binds it. `tests/Discounts/DiscountUsageTest.php` (new). `QaPricingSourceRulesTest` already allows `used_count` inside `packages/pricing/src`.

### `packages/sales` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/sales` 0.1.0: `php ^8.2`, `brick/math ^0.14`, `flexipos/contracts` (the `packages/customers` shape) |
| `src/SalesServiceProvider.php` | `implements DeclaresAuditActions, DeclaresPermissions` (two keys, F-5c). No routes, no commands |
| `src/Support/{TenantConnection,Fields,Identifier,Lookup,Pager,Page,Decimal,Money,BusinessClock}.php` | the tenth copy, plus `Money` (Ruling 3) and `BusinessClock` (UTC now, plus the branch-local `Y-m-d` from `branches.timezone`) |
| `src/Sales/{SaleStatus,SaleType,SaleRollup,SaleLocks,LineWriter}.php` | Ruling 4. `SaleRollup` re-sums the header from `sale_items` (AC-8). `LineWriter` maps `PricedLine` onto columns (AC-4) |
| `src/Approvals/{ApprovalType,ApprovalGate}.php` | Ruling 5 |
| `src/Shifts/ShiftTotals.php` | AC-34/AC-35 rollups (F-15) |
| `src/Tenders/{TenderRules,CreditPosting}.php` | method rules (AC-11, F-17); ledger entry building (F-2 to F-4) |
| `src/Exceptions/{SalesRefused,SalesRefusal}.php` | section 4's refusal list |
| `src/Registry/{SalesAuditActions,SalesPermissions}.php` | |
| `src/Features/<26 slices>/…` | Ruling 8 |
| `tests/Support/{SalesSeed.php,sales-fixture.php,FakePricingEngine.php}` | raw-insert seeds. The fake engine is **only** for the refusal-propagation rows; every pricing-value assertion uses the real engine |
| `tests/Schema/{SalesTenantSchemaTest,LoyaltySaleIdMigrationsTest}.php` | steps 6, 7 |
| `tests/{Sales,Approvals,Shifts,Tenders}/…Test.php` | the pure helpers |
| `tests/Features/<X>/<X>Test.php` | one per slice |
| `tests/Support/SalesProviderTest.php` | step 1 |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_10_003001_create_payment_methods_table.php` … `003010_…` | 01 `payment_methods`, 02 `cash_shifts`, 03 `cash_movements`, 04 `approval_requests`, 05 `sales`, 06 `sale_items`, 07 `sale_tenders`, 08 `sale_holds`, 09 `add_sale_id_to_loyalty_transactions` (`@phase expand`), 10 `add_loyalty_sale_foreign_key` (`@phase contract`). Shape: `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) enums, full decimal arguments, no `down()`, and docblocks naming each F-8 deferral |
| `tests/Feature/QaSalesSourceRulesTest.php` | step 23 |
| `tests/Engine/SalesSchemaEngineTest.php` | step 24 |

## Data / API changes

**Schema: eight new tenant tables, plus one column and one key on `loyalty_transactions`.** There is no master change. Every money and quantity column is `(18,4)` unless stated. Column order in the migrations follows the doc.

| Table | Columns as built | Keys | Not built (F-8) |
|---|---|---|---|
| `payment_methods` | `id`, TS, `deleted_at`, `code` VARCHAR(50), `name` VARCHAR(150), `type` VARCHAR(30), `is_active` (default true), `opens_drawer`, `requires_reference`, `allows_change`, `allows_refund`, `allows_partial` (default false, except `allows_refund` and `allows_partial`, which default true), `rounding_rule` VARCHAR(30) NULL, `surcharge_percent` (9,4) NULL, `sort_order` INT default 0, `version` INT UNSIGNED default 1 | unique `code` | `financial_account_id`, `gateway`, `settings` |
| `cash_shifts` | `id`, TS, `[SYNC]` (7; `terminal_id` required), `branch_id`, `employee_id`, `number` VARCHAR(30), `status`, `opened_at`, `closed_at` NULL, `opening_cash`, `expected_cash`, `counted_cash`, `variance`, `gross`, `discounts`, `tax`, `net` (NULL until close), `totals_by_method` JSON NULL, `sales_count`, `refunds_count` INT UNSIGNED NULL, `closed_by` NULL, `reconciled_by` NULL, `notes` TEXT NULL, `open_terminal_key` CHAR(26) stored `CASE WHEN status='open' THEN terminal_id END` | unique `number`; **unique `open_terminal_key`** (D-22 backstop, T-028's generated-key shape); index (`branch_id`, `opened_at`), (`terminal_id`, `status`); FKs `branch_id`, `terminal_id`, `employee_id`, `closed_by`, `reconciled_by` | `journal_entry_id` |
| `cash_movements` | `id`, `[SYNC]`, `shift_id` (**cascade**, as §6.8 documents), `type`, `amount`, `reason` VARCHAR(255), `reference` VARCHAR(100) NULL, `employee_id`, `approved_by` NULL, `created_at` | FKs `shift_id`, `employee_id`, `approved_by` | `financial_account_id` |
| `approval_requests` | `id`, TS, `[SYNC]` (`terminal_id` NULL), `branch_id`, `type`, `reference_type` VARCHAR(50), `reference_id` CHAR(26) NULL, `requested_by`, `payload` JSON NULL, `status`, `decided_by` NULL, `decided_at` NULL, `decision_method` VARCHAR(30) NULL, `reason` VARCHAR(255) NULL, `plugin_name` VARCHAR(100) NULL, **`consumed_at` NULL (F-18)** | index (`reference_type`, `reference_id`), (`branch_id`, `status`); FKs `branch_id`, `terminal_id`, `requested_by`, `decided_by`; **no FK on `reference_id`** (polymorphic, §3.3) | — |
| `sales` | `id`, TS, `[SYNC]` (its `terminal_id` is the documented FK, NULL), `branch_id`, `location_id`, `shift_id` NULL, `employee_id`, `customer_id` NULL, `customer_name`/`customer_phone`/`customer_tax_no` NULL, `number` VARCHAR(30) NULL, `type`, `reference_sale_id` NULL, `status`, `business_date` DATE, `business_date_source`, `price_list_id` NULL, `currency` CHAR(3), `exchange_rate` (18,8) default 1, `subtotal`, `discount_total`, `tax_total`, `service_charge`, `rounding`, `total`, `paid_total`, `change_due`, `balance_due` (default 0), `bill_discount_id` NULL, `bill_discount_value` NULL, `discount_reason` VARCHAR(255) NULL, `approval_request_id` NULL, `stock_applied` default false, `cost_total` NULL, `credit_checked` default false, `notes` TEXT NULL, `voided_by` NULL, `void_reason` VARCHAR(255) NULL, `voided_at` NULL, `completed_at` NULL, `receipt_print_count` INT UNSIGNED default 0 | unique `number`; §6.7's indexes minus `journal_entry_id`; FKs `branch_id`, `location_id`, `terminal_id`, `shift_id`, `employee_id`, `customer_id`, `reference_sale_id` (self), `price_list_id`, `bill_discount_id`, `approval_request_id`, `voided_by` | `journal_entry_id`, `ar_document_id`, `sales_order_id`, `order_id`, `custom_fields` |
| `sale_items` | `id`, `sale_id` (**cascade**), `line_no` SMALLINT UNSIGNED, `product_id`, `product_name` VARCHAR(150), `sku` VARCHAR(50) NULL, `unit_code` VARCHAR(50), `category_id`/`brand_id` CHAR(26) NULL (**snapshots, no FK**), `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `list_price`, `unit_price`, **`subtotal` (F-18)**, `discount_id` NULL, `discount_percent` (9,4) NULL, `discount_amount`, `tax_group_id`, `tax_amount`, `tax_breakdown` JSON, `price_from_label` default false, `line_total`, `unit_cost`/`line_cost` NULL, `employee_id` NULL, `modifiers` JSON NULL, `notes` NULL, `refunded_quantity` default 0, `refund_of_item_id` NULL, `price_overridden_by` NULL, `sort_order` INT | unique (`sale_id`, `line_no`); index (`product_id`), (`employee_id`); FKs `sale_id`, `product_id`, `unit_id`, `discount_id`, `tax_group_id`, `employee_id`, `refund_of_item_id` (self), `price_overridden_by`; `JsonValidity` on `tax_breakdown` and `modifiers` | `batch_id`, `serial_id`, `kitchen_station_id` |
| `sale_tenders` | `id`, `sale_id` (**cascade**), `payment_method_id`, `method_code` VARCHAR(50), `method_type` VARCHAR(30), `amount` (signed), `tendered` NULL, `change_given` default 0, `reference` VARCHAR(100) NULL, `payment_status` VARCHAR(30) default `recorded`, `paid_at` | index (`sale_id`), (`method_type`, `paid_at`); FKs `sale_id`, `payment_method_id` | `gateway_transaction_id`, `payment_id` |
| `sale_holds` (**new, F-18**) | `id`, `sale_id` (**cascade**), `action` VARCHAR(30) (`hold`, `recall`), `from_status`, `to_status`, `terminal_id`, `employee_id`, `created_at` | index (`sale_id`, `created_at`); FKs `sale_id`, `terminal_id`, `employee_id` | — |
| `loyalty_transactions` (+1) | `sale_id` CHAR(26) NULL (migration 09) | `unique(sale_id, type)` in 09 (NULLs are distinct on both engines, per T-028 plan); FK onto `sales` in 10 (contract) | — |

The `[SYNC]` columns (`origin` default `online`, `device_id`, `client_seq`, `client_created_at`, `sync_status` default `synced`, `synced_at`) are present and inert, with no FKs (D-28, the T-028 shape).

**What each approval is bound to (F-21):**

| Approval | `reference_type` | `reference_id` | Payload |
|---|---|---|---|
| void | `sale` | the sale | — |
| refund, against a sale | `sale` | the original sale | — |
| refund, goodwill | `goodwill_refund` | null | — |
| price override | `sale` | the sale | `{product_id, unit_id, unit_price}`, which must equal the write |
| discount | `sale` | the sale | `{discount_id}` |

**Precision map (AC-40).** No fifth pair.

| Pair | Columns |
|---|---|
| (18,4) | every money and quantity column above |
| (18,6) | `sale_items.unit_factor` |
| (9,4) | `sale_items.discount_percent`, `payment_methods.surcharge_percent` |
| (18,8) | `sales.exchange_rate` |

**FK count:** 36 on new tables (`payment_methods` 0, `cash_shifts` 5, `cash_movements` 3, `approval_requests` 4, `sales` 11 including its self-key, `sale_items` 8 including its self-key, `sale_tenders` 2, `sale_holds` 3), plus 1 in the contract file. `dev` recounts the exact list at step 6 and writes it into `TenantBaselineBuildsTest`.

**`APP_REQUIRED_SCHEMA_VERSION`:** the rebased value + 10 (63 → 73 if T-029 lands at 63). The catalogue is republished, never hand-merged. Migration 10 is a contract step, so a fleet below version 72 runs `--to=72` first (the T-014/T-029 planner consequence).

**Refusal vocabulary (`SalesRefusal`):**

| Area | Refusals |
|---|---|
| General | `validation`, `not_found`, `duplicate`, `in_use` |
| Shifts | `no_open_shift`, `shift_already_open`, `shift_not_open`, `shift_has_open_sales`, `shift_closed` |
| Cart | `sale_not_editable`, `unit_unresolvable`, `unresolvable_price` (plus the engine's own reasons, AC-5), `price_override_not_allowed`, `discount_with_override`, `empty_sale` |
| Tenders | `customer_required`, `credit_refused`, `method_inactive`, `unsupported_method_type`, `reference_required`, `exceeds_balance`, `partial_not_allowed`, `refund_not_allowed`, `balance_due`, `tender_not_removable` |
| Approvals | `approval_required`, `approval_not_approved`, `approval_already_used`, `approval_mismatch`, `approval_not_pending`, `self_approval_not_allowed`, `pin_invalid`, `not_authorised` |
| Corrections and replay | `sale_not_voidable`, `not_refundable`, `over_refund`, `fully_refunded`, `inherited_unit_mismatch`, `replay_conflict` |

**Contracts:** Ruling 2. **Config:** `sales.page_size` (default and maximum), the customers `Pager` shape. **HTTP, console, jobs, hooks, outbox:** none (an outbox `sale.completed` event is a follow-up). **Dependencies:** the `flexipos/sales` path package only. `brick/math` is already locked.

**Boundary (`deptrac.yaml`, the T-028/T-029 shape):**
- `Package_Sales` = `^Flexipos\\Sales\\(?!Tests\\).*`, ruleset `[Package_Kernel, Package_Contracts]`;
- `Package_Sales_Tests` gets those plus `Package_Sales`, `Package_Access`, `Package_Catalog`, `Package_Pricing`, `Package_Customers` and `Package_Organization` (real implementations for fixtures);
- `HostApp` and `HostTests` gain the layers;
- **`Module_Demo` is not granted `Package_Sales`.**

## Implementation steps

These rules carry over from T-027 to T-029 unchanged:
- red-first, with a fixture or by leaving something out, and never by stubbing a guard to allow;
- the **red-list rule** after steps 2 to 7 and 9 (record the red list, compare it with section "Edits to existing files", and stop on anything that section does not name);
- no child processes;
- unique test basenames;
- list foreign `php.exe` processes before each gate run.

Mutations are run once, reverted, and recorded in `dev`'s hand-back with the red-then-green counts. **Contingent** marks a step whose design rests on a section 0.2 finding that `ba` has not yet folded in. If `ba` rules otherwise, that step changes and no other does.

### Chunk A: reconciliation, plumbing, owed contracts, schema, statics

- [ ] **Step 0: rebase and reconcile.** *(No AC.)*
  - Confirm that T-028 and T-029 are DONE, and rebase this branch onto the tip that contains both.
  - Then `composer check`: record passed, failed and skipped, the `test` seconds, and the process list.
  - **Reconciliation table** (in the hand-back): for every symbol this plan names, compare the shipped signature with what is written here:
    - `PricingEngine`, `LineQuote`, `PricedLine`, `TaxQuote`, `TaxBreakdown`, `PricingRefused`/`PricingRefusal`;
    - `CustomerCreditLedger`, `CreditLedgerEntry`, `CreditEntryType`, `CreditRefusal`;
    - `UnitResolver`, `SnapshotCalculator`;
    - `VerifyPinHandler`, `PermissionResolver`, `TerminalAuthority`;
    - the `number_sequences` columns;
    - T-029's `QaPricingSourceRulesTest` token list and `UnitFixtureReplayTest` format.

    **Stop and report to `architect`** on any difference that changes a step. Renames are fixed in place.
  - Recompute the schema version and the FK inventory baseline.
  - Read `ba`'s answers to F-1 to F-21 if they have landed. Build from the revised ACs, never from this plan's paraphrase.
- [ ] **Step 1: package plumbing.** *(Groundwork.)*
  - `composer.json`, an empty provider, the deptrac/PHPStan/`providers.php` edits, host `composer.json` (`require`, `autoload-dev`), `composer update flexipos/sales`, and `'sales'` added to `QaToolchainRegistryCoverageTest`'s literal.
  - **`SalesProviderTest`:** the provider is loaded and `BigDecimal` autoloads.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Sales\Support\Money` fails deptrac; one importing `Flexipos\Contracts\Pricing\PricingEngine` passes. Delete both.
- [ ] **Step 2: `snapshotInherited()`.** *(D-3, AC-26's unit half, AC-41; F-7.)*
  - The contract method, `FactorSource::Inherited`, `UnitRefusal::InheritedUnitMismatch`, the pure `InheritedFactor`, and the `DatabaseUnitResolver` method (it calls `SnapshotCalculator::compute()` and never `UnitLadder`).
  - **`SnapshotInheritedTest`:**
    - (a) same unit, referenced factor `24.000000`, quantity 2: base `48.0000`, source `inherited`, **after** `unit_conversions` is edited to 12 (the §6.5 walk-through);
    - (b) the stock unit: factor `1.000000`, source `stock_unit`;
    - (c) a third unit: `inherited_unit_mismatch`;
    - (d) the line unit's precision is still applied first;
    - (e) Q-11's `fractional_base_quantity` still fires;
    - (f) an unknown product gives `unknown_product`.
  - **Fixtures:** at least 4 `inherited-*.json` files (the T-029 F-15 format, decimals as strings), replayed by the extended `UnitFixtureReplayTest`.
  - **Mutation U1:** call `resolve()` in the same-unit branch. Row (a) goes red. Revert.
  - **Predicted red:** catalog's `FactorSource`/`UnitRefusal` enum-list tests (contract-enum tests, the T-027 shape).
- [ ] **Step 3: `PinVerifier`.** *(AC-23's PIN half; F-5.)*
  - Interface, `HandlerPinVerifier`, binding.
  - **Test:**
    - the right code and PIN return the id;
    - a wrong PIN, an unknown code and a locked employee each return null, and the three nulls are indistinguishable;
    - **the hazard, pinned:** a wrong PIN verified inside an outer transaction that is then rolled back loses its `pin_failed_attempts` increment. The test asserts that loss, which is why Ruling 5 calls the verifier outside any transaction, and the contract's docblock says so.
- [ ] **Step 4: `DocumentNumbers`.** *(AC-14's and AC-25's numbering; F-6.)*
  - **Test:**
    - first use creates the company-wide default and returns `S000001`, then `S000002`;
    - a branch row, when present, wins over the company row;
    - `refund` and `cash_shift` are independent;
    - a `yearly` row is refused `unsupported_reset_period`;
    - a call inside a rolled-back transaction leaves `next_number` unchanged;
    - the duplicate-insert race is simulated by pre-inserting between the read and the insert, and the retry succeeds.
  - **Mutation N1:** drop the lock. The SQL-shape assertion (`for update`) goes red.
- [ ] **Step 5: `DiscountUsage`.** *(Contingent on F-12.)*
  - **Test:**
    - `consume()` increments `used_count` by one;
    - at `used_count = usage_limit` it refuses `usage_limit_reached` and writes nothing;
    - an unknown or soft-deleted discount gives `unknown_discount`;
    - the lock is pinned in the SQL shape.
- [ ] **Step 6: migrations 01 to 08, with `SalesTenantSchemaTest`.** *(The schema halves of AC-1 to AC-36; AC-39; F-8, F-18.)*
  - Each table's sorted column list equals the Data section **exactly**, with nullability. That proves every F-8 absence, `journal_entry_id` included.
  - **Unique keys, by raw double insert:**
    - `open_terminal_key`: two `open` shifts on one terminal are refused; an `open` and a `closed` shift are accepted;
    - `sales.number`: two NULLs are accepted;
    - `payment_methods.code`;
    - `cash_shifts.number`;
    - (`sale_id`, `line_no`).
  - The FK set equals the recounted list, and the three cascades delete their children.
  - `SourceRules::inspect()` reports nothing, and no file declares `down`.
- [ ] **Step 7: migrations 09 and 10, with `LoyaltySaleIdMigrationsTest`.** *(F-9.)*
  - **Expand:**
    - a nullable column and the unique key;
    - existing loyalty rows survive with `sale_id` null;
    - two rows with the same `(sale_id, type)` are refused, and two NULLs are accepted.
  - **Contract:**
    - **red-first:** seed a `sale_id` naming no sale. The file refuses, the message names `loyalty_transactions.sale_id` and `1 row(s)` and no id (T-029 F-5), and nothing changes;
    - when clean, the key exists;
    - a re-run is a no-op.
  - The catalogue rows read: 09 `expand`, 10 `contract`, **both `is_destructive = false`**.
- [ ] **Step 8: AC-39 and AC-40 statics.** *(AC-39, AC-40.)*
  - Extend `TenantDecimalPrecisionTest`'s column map with the Data section's map, under a T-030 comment. The allow-list stays at four pairs. **Red-first:** a fixture `decimal('amount', 18, 2)` is reported.
  - AC-39: no tenant migration declares `journal_entry_id` on `sales`, or `financial_account_id`, `ar_document_id`, `gateway_transaction_id` or `payment_id` on this task's tables. **Red-first:** a scratch migration under a fixture directory declaring one of them is reported. Delete it.
  - **Gate: `composer check` (end of chunk A).**

### Chunk B: building blocks (bottom-up)

- [ ] **Step 9: `Support`, `Money`, `SaleRollup`, `TenderRules`, `ShiftTotals`.** All pure, or reading only this package's tables.
  - **`Money`:**
    - canonical 4-dp strings; no float; never `-0.0000`;
    - `round(7.5000 × 3.0000) = 22.5000`;
    - `.00005` rounds HALF_UP.
  - **`SaleRollup`** over seeded lines:
    - exclusive: subtotal 179, discount 17.9, tax 24.165, total 185.265;
    - inclusive: the total equals Σ `line_total` and `tax_total` is informational;
    - zero lines give all zeros.
  - **`TenderRules`:**
    - change: cash `tendered` 50 against balance 45 gives amount 45, change 5;
    - a card 50 against balance 45 is `exceeds_balance`;
    - `allows_partial = false` with a short amount is `partial_not_allowed`;
    - `requires_reference` without one is `reference_required`;
    - `loyalty` and `voucher` are `unsupported_method_type`.
  - **`ShiftTotals`:** expected cash with each movement type (sign table, F-15); voided and held sales excluded; refunds negative.
  - **Mutations:**
    - **M1:** add `drop` instead of subtracting it. The movement row goes red;
    - **M2:** count `held` sales' tenders. The held row goes red.
  - *(AC-8, AC-11, AC-34, AC-35 arithmetic; F-11, F-15, F-17.)*
- [ ] **Step 10: approvals.** `RequestApproval`, `DecideApproval`, `ApprovalGate`, and `ListApprovalRequests`.
  - **AC-23:**
    - only the four types are accepted; `credit_limit`, `negative_stock` and `custom` are refused `validation`;
    - `decision_method` is always `pin_local`;
    - a wrong PIN is `pin_invalid` with **no** change to the approval row, while access's counter **did** increment (F-5b);
    - a decider without `sales.approvals.decide` at the branch is `not_authorised`.
  - **D-15:**
    - the decider equal to `requested_by` is refused;
    - for a `sale` reference, the decider equal to the sale's `employee_id` is refused;
    - a goodwill refund has no sale, so only the `requested_by` check applies.
  - **AC-24:**
    - deciding twice is `approval_not_pending`;
    - consuming twice is `approval_already_used`;
    - consuming a `rejected` row is `approval_not_approved`;
    - a type, branch or reference mismatch is `approval_mismatch`.
  - **Mutation M3:** call `PinVerifier` inside the transaction. The counter assertion goes red.
  - The list is paginated (D-26), and the cursor reaches row 51 of 51 at page size 50.
  - *(AC-23, AC-24; D-12 to D-15; F-5, F-21.)*
- [ ] **Step 11: payment methods** (three slices).
  - Code blank or duplicate (also by case); bad `type`; the boolean defaults; `financial_account_id`, `gateway` and `settings` refused as **unknown** (F-8).
  - **The guard:** deactivate or delete is refused `in_use`, naming the count, while a `draft`, `held` or `pending_payment` sale has a tender on the method. It is accepted once that sale completes.
  - The soft delete leaves the row in place.
  - *(AC-16.)*
- [ ] **Step 12: `OpenCashShift`, `RecordCashMovement`, `ListCashShifts`, `ListCashMovements`.**
  - **AC-32:**
    - `shift_already_open`;
    - a paused or inactive terminal (`TerminalAuthority`) is `not_found`;
    - the number comes from `DocumentNumbers` (`SH000001`).
  - **AC-33:**
    - each of the seven types is accepted, and a bad type is refused;
    - a closed shift is `shift_not_open`;
    - `financial_account_id` is refused as unknown.
  - **AC-37:** a replayed movement id with the same payload is a no-op (`replayed = true`, no second audit row); a different payload is `replay_conflict`.
  - Both lists are paginated.
  - **Gate: `composer check` (end of chunk B).**
  - *(AC-32, AC-33, AC-37 (movement); D-22.)*

### Chunk C: the cart, tenders, completion, hold

- [ ] **Step 13: `CreateSale`.**
  - Writes `draft`, with branch and location from the terminal (never client-supplied), `employee_id` = the actor, `business_date` from `BusinessClock` in the branch timezone, `business_date_source = server`, `currency` from the branch, and `shift_id` = the terminal's open shift.
  - Refusals:
    - `no_open_shift`;
    - a customer unknown, soft-deleted or merged is `not_found`;
    - the snapshots are copied;
    - `business_date`, `bill_discount_id` and `bill_discount_value` (F-1) are refused as unknown fields.
  - *(AC-1; D-22, D-23.)*
- [ ] **Step 14: `AddSaleLine`, `UpdateSaleLine`, `RemoveSaleLine`, `ListSaleLines`**, against the **real** `UnitResolver` and `PricingEngine`, seeded through T-029's seeds.
  - **AC-2:**
    - 1 case of 24 gives `unit_factor 24.000000`, base 24, `unit_code` copied;
    - renaming the product afterwards leaves the line unchanged.
  - **AC-3:** a cross-dimension unit is `unit_unresolvable`, naming both units.
  - **AC-4:**
    - each stored column equals the corresponding `PricedLine` field byte for byte (fixture `line/case-of-24` replayed end to end);
    - `tax_breakdown` equals `TaxBreakdown::toArray()`.
  - **AC-5:**
    - no price gives `unresolvable_price`;
    - an engine `not_applicable` discount surfaces as `not_applicable` (the fake engine covers the remaining reasons by dataset).
  - **AC-6 and F-10:**
    - `pending_payment` is `sale_not_editable`;
    - `held` from `draft` is accepted;
    - `held` from `pending_payment` is refused.
  - **AC-8:** the header equals `SaleRollup` after every add, update and remove.
  - **AC-9 and AC-10:**
    - `price_from_label = false` and `modifiers = null` on every row;
    - `unit_cost`, `line_cost` and `cost_total` null, and `stock_applied` false;
    - those fields are refused if supplied.
  - Optional `employee_id` (line salesperson) is validated via `EmployeeDirectory`.
  - **Mutation M4:** compute `base_quantity` locally. The AC-2 row still passes, but step 23's scan goes red, which is the point of that scan.
  - *(AC-2 to AC-6, AC-8 to AC-10.)*
- [ ] **Step 15: price override and the discount approval.** *(Contingent on F-12, F-16.)*
  - **AC-7:**
    - an override without an approval is `approval_required`;
    - with a matching approval, the stored `unit_price` is the override, `subtotal` = override × quantity, tax from `computeTax()`, `line_total = net + total` (one exclusive and one inclusive row);
    - `price_overridden_by` = the **decider**;
    - the approval is consumed, and reusing it is `approval_already_used`.
  - **F-16:**
    - `allow_price_override = false` is `price_override_not_allowed`;
    - an override plus a `discount_id` is `discount_with_override`;
    - a payload `unit_price` different from the write is `approval_mismatch`.
  - **F-12:** a line discount with `requires_approval = true` is `approval_required` without a matching consumed approval and accepted with one; a discount without the flag needs none.
  - *(AC-7; D-13, D-15.)*
- [ ] **Step 16: `AddTender`, `RemoveTender`.** *(Contingent on F-3.)*
  - **AC-11:**
    - the method snapshots are copied;
    - the first tender moves `draft` to `pending_payment`;
    - an inactive method is `method_inactive`.
  - **AC-12:**
    - split cash plus card is accepted;
    - a credit tender with no customer is `customer_required`;
    - **no `customer_ledger` row is written by `AddTender`** (F-3).
  - **Idempotency:** a replayed tender id with the same payload is a no-op; a different payload is `replay_conflict`.
  - **AC-13:**
    - removal deletes the row;
    - removing the last tender moves the sale back to `draft`;
    - on `completed` it is `tender_not_removable`.
  - **Concurrency shape:** the `sales` row is read `for update` before the balance check (SQL pinned).
  - *(AC-11 to AC-13.)*
- [ ] **Step 17: `CompleteSale`.**
  - **AC-14:**
    - a short balance is `balance_due`, naming the remainder;
    - an exact balance sets `completed`, `completed_at`, `paid_total`, `change_due`, `balance_due = 0` and `number = S000001`;
    - a total of 0 with lines completes from `draft`;
    - no lines is `empty_sale`.
  - **F-2 and F-3:**
    - two credit tenders write **two** `credit_sale` rows keyed `('sale_tender', <tender id>)`;
    - `credit_checked = true`;
    - an over-limit customer is `credit_refused` carrying `over_limit`, with no number consumed, no row changed and no ledger row (one transaction).
  - **F-12:** `DiscountUsage::consume()` runs once per distinct discount; `usage_limit_reached` refuses the whole completion.
  - **AC-15:** no `stock_*` table is touched (asserted by query count on stock tables, which do not exist, plus a source scan in step 23); `stock_applied` stays false.
  - **Mutation M5:** post the ledger before the balance check, with the balance short. A ledger row survives, and the "nothing written" assertion goes red.
  - *(AC-14, AC-15; D-20; F-2, F-3, F-12.)*
- [ ] **Step 18: `HoldSale`, `RecallSale`, `ListSales`, `GetSale`.**
  - **AC-17:** `draft` and `pending_payment` can be held, and one `sale_holds` row records `from_status`; any other status is refused.
  - **AC-18:**
    - recall restores the recorded status (both cases);
    - recall from terminal B sets `terminal_id = B` and `shift_id` = B's open shift (F-15);
    - `employee_id` is unchanged;
    - B with no open shift is `no_open_shift`.
  - **AC-19:** two holds and one recall give three `sale_holds` rows, each with its actor, terminal and time, independent of `audit_logs`.
  - **AC-20:**
    - `ListSales(branch, status = held)` returns a sale held at terminal A when asked from terminal B;
    - another branch's held sale is absent;
    - pagination works.
  - **Gate: `composer check` (end of chunk C).**
  - *(AC-17 to AC-20; D-24, D-25.)*

### Chunk D: corrections and shift close

- [ ] **Step 19: `VoidSale`.** *(Contingent on F-4, F-15.)*
  - **AC-21:**
    - a `draft`, `held` or `pending_payment` sale voids without approval, sets `voided_by`, `void_reason` and `voided_at`, and gets one negative mirror per tender;
    - the original tender rows are byte-identical afterwards;
    - no ledger row is written (F-3: nothing was posted yet).
  - **AC-22:**
    - a `completed` sale without approval is `approval_required`;
    - with a self-decided approval it was already refused at decide time (step 10);
    - with a valid approval: mirrors, plus a `credit_note` per credit tender keyed on the mirror id (F-4), plus `sales.approval_request_id` set and the approval consumed;
    - its shift closed is `shift_closed`;
    - `partially_refunded` is `sale_not_voidable`;
    - no stock or GL effect.
  - **Mutation M6:** edit the original tender to a zero amount instead of mirroring. The byte-identity assertion goes red.
  - *(AC-21, AC-22; D-14 to D-16.)*
- [ ] **Step 20: `RefundSale`.** *(Contingent on F-13, F-14.)*
  - **AC-25:**
    - a new `type = refund` row, number `R000001`, `reference_sale_id` set;
    - a goodwill refund has a null reference;
    - the refund's own shift is the refunding terminal's open one.
  - **AC-26:**
    - each line sets `refund_of_item_id`;
    - the same unit inherits the factor after `unit_conversions` changes (§6.5's 10-cases walk-through, at sale scale);
    - the stock unit gives factor 1;
    - a third unit is `inherited_unit_mismatch`;
    - a goodwill line goes through `snapshot()`.
  - **AC-27:**
    - each line is quoted by `quoteLine()`;
    - a two-step quantity tier re-resolves (2 cases refunded out of 4 at a 48-base tier gives the 1-case price);
    - **F-13:** a price-list edit made after the sale with an `at` window ending before it does **not** change the refund; the quote uses the original `completed_at` (one dataset row each way).
  - **AC-28:**
    - `over_refund` names the remaining base quantity;
    - the second partial refund to exhaustion sets `refunded`, and a third is `fully_refunded`;
    - `refunded_quantity` accumulates in base across a case refund and a single refund;
    - only `type = sale` in `completed` or `partially_refunded` is refundable (`not_refundable`).
  - **AC-29:** without approval, `approval_required`; goodwill takes the same gate; an approval bound to another sale is `approval_mismatch`.
  - **AC-30 and F-14:**
    - tenders are negative and Σ = −total, otherwise `balance_due`;
    - `allows_refund = false` is `refund_not_allowed`;
    - a credit refund method writes a `refund` ledger row (credit side) keyed on the refund tender.
  - **AC-31:** no stock or GL effect.
  - *(AC-25 to AC-31; D-17 to D-19.)*
- [ ] **Step 21: `CloseCashShift`, `ReconcileCashShift`.**
  - **AC-34:**
    - `counted_cash` is required;
    - `expected_cash` = opening + cash tenders (sales and refunds, signed) ± movements;
    - `variance = counted − expected` (one short, one over, one exact);
    - `closed`, `closed_at`, `closed_by`;
    - not `open` is `shift_not_open`;
    - an open `pending_payment` sale on the shift is `shift_has_open_sales` (F-15).
  - **AC-35:** `totals_by_method`, `sales_count`, `refunds_count`, `gross`, `discounts`, `tax` and `net` over a seeded mixed shift (two sales, one voided, one refund), with exact strings.
  - **AC-36:**
    - `closed` → `reconciled` with `reconciled_by`;
    - a different supervisor than the closer is accepted;
    - without `sales.shifts.reconcile`, `not_authorised`;
    - an `open` shift is `shift_not_open`.
  - **AC-37:** close and reconcile replayed with the same payload are no-ops; a close replayed with a different `counted_cash` is `replay_conflict`.
  - **Gate: `composer check` (end of chunk D).**
  - *(AC-34 to AC-37; D-21.)*

### Chunk E: scans, engine, documents, close-out

- [ ] **Step 22: the cross-slice invariant sweep** (`SalesInvariantTest`). After a scripted day (open → three sales with split and credit tenders → hold, recall elsewhere → void one → refund one → close → reconcile), assert:
  - every completed sale's Σ tender `amount` = `total` (−`total` for a refund);
  - every void has an exact mirror;
  - every credit tender has exactly one ledger row;
  - `refunded_quantity` ≤ `base_quantity` on every line;
  - every approval consumed at most once;
  - one audit row per write;
  - the shift's `expected_cash` equals a hand-checked figure.
  - *(The NFRs; AC-14, AC-21, AC-28.)*
- [ ] **Step 23: `QaSalesSourceRulesTest` (host),** code-only via `SourceRules::codeOnly`.
  - **AC-38 (a):** no file under `packages/sales/src` contains `tax_rates`, `tax_group_taxes`, `price_list_items`, `price_list_assignments`, `is_inclusive`, `is_compound`, `unit_conversions`, `base_unit_id`, `customer_ledger`, `credit_balance`, `balance_after`, `number_sequences` or `used_count`.
  - **AC-38 (b):** no `*`, `/`, `multipliedBy(` or `dividedBy(` in `packages/sales/src` outside `Support/Money.php` (Ruling 3).
  - **AC-38 (c), anti-vacuity:** T-027's factor scan and T-029's AC-25 scan both enumerate at least one `packages/sales/src` file, and both report zero hits with **no** sales entry in either exemption list.
  - **AC-15/AC-31:** no `stock_` table name and no `journal_` token in the package.
  - The package has no `routes/`, `Route::`, `Cache::` or `dispatch(`.
  - **Red-first:** planted files for each rule, then deleted.
  - *(AC-38, AC-15, AC-31.)*
- [ ] **Step 24: `SalesSchemaEngineTest`** (database `flexipos_t8test_t030_tenant`, created and dropped by **exact name** through `EngineDatabase`, never with `LIKE`). Under `ENGINE_HOLD`, only the refusal case runs. Written for G-1:
  - the `information_schema` decimals equal the precision map;
  - `open_terminal_key` refuses on MySQL;
  - `JsonValidity`;
  - contract file 10 on a populated `loyalty_transactions`;
  - two connections completing the same sale: the second waits on the row lock and then sees `completed` (`innodb_lock_wait_timeout = 1`);
  - concurrent `DocumentNumbers` gives distinct numbers;
  - migrating twice is a no-op.
  - *(The `[ENGINE]` halves.)*
- [ ] **Step 25: documents.** Describe the old wording; never quote it (memory note).
  - `docs/database.md` §6.7/§6.8 "As built (T-030)":
    - F-8's unbuilt columns;
    - F-18's three additions;
    - the approval binding (F-21);
    - the state machine (Ruling 4);
    - the sign conventions (F-14, F-15);
    - the ledger keys (F-2 to F-4).
  - §6.1: `DocumentNumbers` and the defaults (F-6). §6.3: `snapshotInherited`. §6.9 "As built (T-028)": `loyalty_transactions.sale_id` is now built.
  - `docs/architecture.md` §3: the Sales row points at `packages/sales`.
  - The follow-ups below go to `lead`.
- [ ] **Step 26: final gate.** `composer check` from `modules/host`, with counts and `test` seconds compared against step 0, then `composer test:fast` once. If a chunk added more than 10 % to the `test` step, stop and report to `lead` (FP-328).

## Test strategy

- **Unit (pure):** `Money`, `SaleRollup`, `TenderRules`, `ShiftTotals`, `InheritedFactor` (plus fixtures), and the state-machine table (`SaleStatus` transitions as a dataset, including every refused edge).
- **Integration (SQLite stand-in, real implementations):**
  - one file per slice, against the real `UnitResolver`, `PricingEngine`, `CustomerCreditLedger`, `PinVerifier`, `DocumentNumbers` and `PermissionResolver`;
  - refusals write nothing (sales tables, ledger and `number_sequences` all compared whole);
  - a success writes one audit row;
  - `FakePricingEngine` only for AC-5's reason-propagation dataset;
  - `SalesInvariantTest` for the scripted day.
- **Owned elsewhere:** `SnapshotInheritedTest` (catalog), `HandlerPinVerifierTest` (access), `DocumentNumbersTest` (organization), `DiscountUsageTest` (pricing).
- **Static:** `SalesTenantSchemaTest`, `LoyaltySaleIdMigrationsTest`, `TenantDecimalPrecisionTest`, `QaSalesSourceRulesTest`, the catalogue `is_destructive` assertions, and deptrac with the demo control.
- **Engine:** `SalesSchemaEngineTest`, skipped under the hold.
- **Mutations:** U1, N1, M1 to M6.

| ACs | Proved by |
|---|---|
| 1 | 13 |
| 2–6, 8–10 | 14 (6 also by F-10's rows) |
| 7 | 15 |
| 11–13 | 9, 16 |
| 14, 15 | 4, 17, 23 |
| 16 | 11 |
| 17–20 | 18 |
| 21, 22 | 19 |
| 23, 24 | 3, 10, 15 |
| 25–31 | 2, 20, 23 |
| 32, 33 | 12 |
| 34–36 | 9, 21 |
| 37 | 12, 21 |
| 38 | 23 |
| 39 | 6, 8 |
| 40 | 8, 24 |
| 41 | 2 |

## Risks & rollback

1. **Contract drift (highest).** `PricingEngine` is known only from T-029's plan. Step 0's reconciliation table is the control, and a mismatch that changes a step is a stop.
2. **Spec drift.** Fifteen findings change or sharpen behaviour. If `dev` builds from `a2e6631`'s text instead of `ba`'s revision, F-2 (a silent ledger replay) and F-3 (an orphaned debit) produce code that passes its own tests and corrupts a customer balance. Steps 16, 17 and 22 assert the corrected shapes specifically.
3. **Merge conflicts.** These shared files are edited by T-028, T-029 and this task:
   - `deptrac.yaml`, `phpstan.neon.dist`, `providers.php`, host `composer.json`/`.lock`;
   - `QaToolchainRegistryCoverageTest`, `TenantDecimalPrecisionTest`, `tests/Helpers.php`, `TenantBaselineBuildsTest`, `MigrationTreeDisciplineTest`;
   - the catalogue JSON, `.env.example`.

   Rebase only after both have landed. Republish the catalogue; never hand-merge it.
4. **Four edits in packages this task does not own** (catalog, access, organization, pricing), plus one schema change on customers'. Each is additive and tested by its owner's suite. Rollback is per contract. `qa` should review them as their owners' code, not as Sales code.
5. **Approval scope.** A refund approval carries no amount ceiling (F-21), so one approval covers whatever refund it is consumed by, against its bound sale. Approvals are single-use and sale-bound, which caps the exposure at one refund. A ceiling is a follow-up if the owner wants it.
6. **Held sale with tenders, recalled at another terminal** (F-15). Its cash tender is counted in the completing drawer, not the one that took the cash. This is rare and visible as a variance pair. Fix options: refuse holding a sale with cash tenders, or move tenders with the shift. That is the owner's call.
7. **A customer merged after a credit sale** makes the void's `credit_note` refuse (`customer_not_live`), so `VoidSale` refuses `credit_refused`. The refund path has the same limit. T-028 follow-up: re-point to `merged_into_id`.
8. **Gate time.** This task adds roughly 300 cases. Measure at each chunk boundary (Ruling 11 of T-029).
9. **Query cost.** `UnitResolver::snapshot()` loads `units` twice (FP-426). A 50-line sale is about 250 queries plus the engine's. That is acceptable for the online till, and FP-426's batch API is the remedy.

**Rollback:** everything is new except the four contract additions and the edits listed below. The migrations are forward-only and no tenant outside this branch has run them, so rollback is to abandon the branch.

## Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock`, `deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | package wiring | 1 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` | `'sales'` | 1 |
| `packages/contracts/src/Catalog/{UnitResolver,FactorSource,UnitRefusal,UnitResolutionRefused}.php`, `packages/catalog/src/Units/DatabaseUnitResolver.php`, catalog enum tests, `UnitFixtureReplayTest` | step 2 | 2 |
| `packages/access/src/AccessServiceProvider.php` | binding | 3 |
| `packages/organization/src/OrganizationServiceProvider.php` | binding | 4 |
| `packages/pricing/src/PricingServiceProvider.php` | binding | 5 |
| `database/catalogue/tenant_schema_versions.json`, `.env.example` | republished; version + 10 | 6, 7 |
| `tests/Helpers.php` `tenantTreeTables()`, `MigrationTreeDisciplineTest` (count +8), `TenantBaselineBuildsTest` (FK inventory), `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest` (`$t030` list), `SchemaCataloguePublishTest` | schema | 6, 7 |
| `packages/customers/tests/Schema/CustomersTenantSchemaTest.php` | `loyalty_transactions` +`sale_id` | 7 |
| `tests/Engine/CustomersSchemaEngineTest.php` | the same column, if it lists them | 7 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | the T-030 map | 8 |
| permission registry sweep tests (the `DeclaresPermissions` set) | two keys | 10 |
| `docs/database.md`, `docs/architecture.md` | step 25 | 25 |

Anything red that is not in this table means stop and report to `architect`.

## Follow-ups for `lead` (backlog rows or notes, not built here)

- **Bill-level discounts** (F-1): the allocation rule, re-tax, `bill_discount_amount`, and refund share.
- **T-028 debts still open (F-9):**
  - `RecordLoyaltyTransaction` accepting `sale_id`;
  - `first_sale_at`/`last_sale_at`/`lifetime_value` (recomputable from `sales`);
  - `sales.customer_id` on merge;
  - the `credit_limit` approval and the offline recorded-anyway path;
  - void and refund compensation for merged customers (risk 7).
- **T-029 debts still open:**
  - `approval_threshold` and `workflow_definition_id` evaluation (F-12);
  - `min_selling_price` and `price_mode = open_price` (F-16);
  - `rounding_rule`, `surcharge_percent`, `service_charge` (F-11);
  - `sales.price_list_id` as a forcing parameter (D-11);
  - whether `customer_groups.discount_percent` feeds the engine.
- **FP-234:** stock validation and decrement inside `CompleteSale`, `stock_applied`, `unit_cost`/`line_cost`/`cost_total`, and the `negative_stock` approval.
- **FP-260/FP-263:** `journal_entry_id`, `ar_document_id`, `payment_methods.financial_account_id` (documented `NOT NULL`: expand + backfill + contract), `cash_movements.financial_account_id`, the posting rules of §7.1, and a real reconcile step before `reconciled`.
- **FP-237:** per-terminal reserved number prefixes (§12), offline sale replay, the `[SYNC]` columns, and the `payment_method` replica entity.
- **FP-236 / API task:** routes and permission keys for every slice, and a `SetSaleCustomer` command (customer is set at create only here).
- **An outbox `sale.completed` event** (§12.1) once FP-234 or Finance needs one.
- **Loyalty/voucher tenders** (F-17), and **gateway** tenders (D-7).
- **Exchange sales, quotations, sales orders, restaurant** (D-10).
- **A refund-approval amount ceiling** (risk 5); **cash tenders on a held sale** (risk 6).
- **FP-331:** the tenth copy of the support helpers. **FP-426:** a batch resolve for multi-line documents.

## Questions for the human

1. **Refund price (F-13).** Should a refund pay back what the customer was charged, or today's price? The plan re-quotes at the original sale's instant, which matches the original price unless a price-list row was edited since. Price lists are not versioned, so "exactly what was paid" would instead need a pro-rata of the stored line, which is arithmetic AC-38 forbids today. Which does the owner want?
2. **Bill-level discounts (F-1).** Ship the cart without them (the plan default) and add them once an allocation rule is agreed, or agree the rule now: proportional to each line's pre-tax amount, with the rounding remainder on the largest line?

Everything else in section 0.2 is a plan-stage correction for `ba` to fold into the spec, not a product question.
