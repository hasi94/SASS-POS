# Plan: T-030 Sales: cart, tenders, hold and recall, refunds, voids, cash shifts

Spec: `docs/specs/T-030-sales.md` revision 3 (AC-1 to AC-46, plus AC-23a; AC-2, AC-11, AC-24 and AC-41 amended by D-51 to D-54). Decisions: `docs/decisions/T-030-sales.md` (D-1 to D-54). Branch `ai/T-030-sales`. Revision 1 was planned at `a2e6631` and committed as `4614645`; revision 2 at `be48613`; revision 3 at `74fceba`, the merged tree.

**Revision 4 (this revision), planned at `e4ed355`, after chunks A and B were committed (`86e8a09`, `f9181cd`).** Chunks C to E now build from **spec revision 5**: AC-1 to AC-58 plus AC-23a, and the four suffix ACs AC-12A, AC-14A, AC-24A and AC-47A; decisions D-1 to D-74. Steps 0 to 12, their ticks and the chunk B "As built" note are unchanged. Every claim about built code below was read in `packages/sales` at `e4ed355`. What changed:
- **AC-12A (D-71):** `refund_not_allowed` is gone. A refund tender onto a method with `allows_refund = false` is `unsupported_method_type`. Step 16 edits the built `TenderRules::decide()` to add that cause, removes the built `SalesRefusal::RefundNotAllowed` case, and edits `SalesRegistryTest`, which pins all 43 codes (42 after the edit).
- **AC-24A (D-72):** the branch is a column of the binding table. The built `ApprovalGate::consume()` already compares it, and `ApprovalGateTest` already has a branch mismatch row; steps 15, 19 and 20 add one slice-level row each.
- **AC-47A (D-73):** `RefundSale` never runs Sales' sellability check. A soft-deleted product is still refused `unknown_product` by the engine until Q-1 is ruled (steps 14, 20).
- **AC-14A (D-74):** the zero-total completion takes the ordinary path. Step 17 lists what that path writes and what it does not.
- **Deadlock retry:** `CompleteSale` and `RefundSale` both call `DocumentNumbers::next()`, so both run as `transaction(..., 2)`, the way the built `OpenCashShift` does, with the same three deadlock-fake rows. The fake helpers move out of `OpenCashShiftTest` into `tests/Support`, because Pest file-level functions cannot be declared twice in one run (step 17).
- **`AddTender`'s input matches the built `TenderRules` shape:** one `offered` figure, never a caller `amount`/`tendered` pair (step 16).
- **Drift against the chunk B "As built" note, fixed in steps 13 to 21:** `BusinessClock`, `SaleType`, `LineWriter`, `SaleLocks` and `CreditPosting` are not built yet, and each step that first needs one now creates it. `SalesPermissions` still declares one key, so step 21 adds `sales.shifts.reconcile` and edits `SalesRegistryTest`. Q-3 to Q-11 were ruled as D-57 to D-65, every one at the plan's default, so their "if accepted" wording is removed. The rev-4 and rev-5 ACs are added to each step's AC list and to the proof table.

**Revision 3: reconciled against the shipped code.** Both dependencies are DONE and merged into this branch: T-028 `customers` at `db5e772`, T-029 `pricing` at `74fceba` (the T-027 catalog came in underneath them). Revision 2 was reconciled against T-029's *plan*; this revision does the plan's own step 0 on paper, against the *code*. **Section 0.5 is the record**: 41 numbered findings, R-1 to R-41, of which R-1 to R-8 are marked serious. Where a finding changes a step, the step below is edited in place and names the finding (R-n). What changed, in short:
- **`DiscountUsage` is confirmed unshipped** and still owed. Every other contract this plan calls exists with the shape revision 2 assumed, apart from small differences (no `taxAmount` on `PricedLine`, a `TaxQuote` that needs a base quantity and a context, and `Shape`'s split between refusals and caller-bug exceptions). Each is now written into the step that uses it.
- **The lock order is rewritten** (R-5). Revision 2 locked the `sales` row first. That leaves `CreateSale`, `RecallSale`, `VoidSale`, `RefundSale` and `RecordCashMovement` free to race `CloseCashShift`, so a draft sale, a mirror tender or a movement could land on a shift that is already closed.
- **The predicted-red list grows by four files, and a fifth one's nature is now stated** (R-6). One of them is a pricing test with an anonymous `UnitResolver` double, which is a fatal error the moment step 2 adds a method to the interface.
- **One spec-level gap is serious and goes to `ba`** (R-1): a referenced refund line is re-quoted through the engine, and the engine checks the *current* state of the discount and the product. A discount that has reached its usage limit (this very sale can be the use that reached it), a discount deactivated or deleted since the sale, or a product deleted since the sale, makes that line impossible to refund.
- Step 0 no longer rebases. The merges are done. It now verifies them and measures the baseline (section 0.5, R-41).

**Revision 2.** `ba` folded F-1 to F-21 (section 0.2) into the spec in `be48613`, as spec revision 2 with D-29 to D-50. I read the full diff, not the summary. Every resolution matches this plan's proposal, including the lead's two rulings: bill discounts deferred (D-35) and refunds priced at the original sale's instant (D-38). So **no step's design changed**, and the "Contingent" markers are gone. What did change:
- **The ACs were renumbered.** Every AC reference in this plan now uses the revision-2 numbers. The moves that matter:

  | Revision 1 | Revision 2 | What |
  |---|---|---|
  | AC-38 | **AC-42** | the source-rules scan |
  | AC-39 | **AC-43** | schema absences |
  | AC-40 | **AC-44** | the precision map |
  | AC-41 | **AC-38** | `snapshotInherited()` |
  | (new) | **AC-39** | `PinVerifier` |
  | (new) | **AC-40** | `DocumentNumbers` |
  | (new) | **AC-41** | `DiscountUsage` |
  | (new) | **AC-45** | `loyalty_transactions.sale_id` |
  | (new) | **AC-46** | the three schema additions |
  | (new) | **AC-23a** | the discount approval |
- **Four new drift points**, F-22 to F-25 (section 0.2, the end of the list). F-22 changes one step (16). The other three are wording, and the plan applies a reading for each.

| Finding | Now lives in |
|---|---|
| F-1 (bill discount deferred) | D-35, AC-8 |
| F-2, F-3, F-4 (ledger key, timing, `credit_note`) | D-29, AC-11, AC-13, AC-14, AC-21, AC-22 |
| F-5 (`PinVerifier`, permission keys) | D-30, AC-23, AC-36, AC-39 |
| F-6 (`DocumentNumbers`) | D-31, AC-14, AC-25, AC-32, AC-40 |
| F-7 (`snapshotInherited` rule) | D-32, AC-26, AC-38 |
| F-8 (not built) | D-33, AC-9, AC-16, AC-33, AC-43 |
| F-9 (loyalty `sale_id`) | D-34, AC-45 |
| F-10 (held-from-pending locked) | D-36, AC-6 |
| F-11 (zero tolerance) | D-42, AC-8, AC-14 |
| F-12 (discount approval, usage) | D-37, AC-23a, AC-14, AC-41 |
| F-13 (refund instant) | D-38, AC-27 |
| F-14 (refund signs) | D-43, AC-30 |
| F-15 (shift edges) | D-39, AC-18, AC-22, AC-33 to AC-35 |
| F-16 (override rules) | D-40, AC-7 |
| F-17 (loyalty/voucher refused) | D-41, AC-12 |
| F-18 (three additions) | D-44, AC-46 |
| F-19 (cross-references, precision wording) | D-45, AC-44 |
| F-20 (`discount_percent` null) | D-46, AC-10 |
| F-21 (approval binding) | D-47, AC-24 |

**Branch baseline (revision 2's record; superseded by section 0.5).** This branch was cut from `main` at `4ece505`. That tree had **no** `packages/access`, `catalog`, `organization`, `customers` or `pricing`, and its `docs/` predated v0.5. The two merges at `db5e772` and `74fceba` brought all of them in. For revision 2, every contract below was read on the sibling worktrees:
- `build-56f213` (T-028 at `180544b`, chunks A to D committed): `packages/contracts/src/{Catalog,Customers,Access,Organization}`, `packages/catalog/src/Units`, `packages/access/src/Features/VerifyPin`, and the tenant migrations;
- `taxes-pricing` (T-029 at `0a6cbb5`): **plan only, no code yet**. `Contracts\Pricing` is known only from T-029 plan Ruling 2.

The merge brought in the tenant migrations up to `2026_11_10_002912` (version 63). Section 0.5 recomputes every number in this plan that depends on them.

**Routing.** Revision 1's findings went to `ba` through `lead` (I had no messaging tool). F-22 to F-25 go the same way. None of them blocks: the plan applies a reading for each.

---

## 0. Verification and findings

### 0.1 The spec's premises, re-read against source

*Sections 0.1 and 0.2 (F-1 to F-21) are revision 1's record and cite revision-1 AC numbers: AC-38 there is today's AC-42, AC-39 is AC-43, AC-40 is AC-44, and AC-41 is AC-38. F-22 onward, and everything from section 0.3 on, use revision-2 numbers.*

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

F-1 to F-21 below are the record of what revision 1 routed. All of them are **resolved in `be48613`**, and the mapping table at the head of this plan gives where each now lives. The spec's text governs, not this paraphrase. Every "proposal" or "alternative" clause in F-1 to F-21 is now void: the proposal is required by the spec, and F-1's allocator alternative is a follow-up row (D-35).

**Changed behaviour (resolved in `be48613`):**

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

**Small inconsistencies (resolved in `be48613`):**

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

**New in revision 2, found reading `be48613`. None blocks; the plan applies the reading given.**

- **F-22 (AC-11 vs D-49): tendering a held sale.**
  - AC-11 accepts `AddTender` directly on a sale `held` from `pending_payment`. D-49 says that transition happens "after recall and re-tender".
  - Taken literally, AC-11 would unpark the sale with no `recall` row in `sale_holds` (breaking AC-19's "who picked it up") and without moving `terminal_id`/`shift_id` (breaking AC-18 and D-39). The cash would then be counted in a shift the sale no longer belongs to.
  - **Plan reading:** `AddTender` on a sale held from `pending_payment` performs AC-18's recall first, in the same transaction: a `recall` row, `terminal_id` and `shift_id` moved to the tendering terminal, and `no_open_shift` if it has none. Then it records the tender. That satisfies both texts.
  - A sale held from `draft` is still refused `sale_not_editable` (recall it first).
  - `ba` may instead rule "refuse; recall first", which removes one branch in step 16.
- **F-23 (AC-24): its first two clauses contradict each other.** One sends a second decide to `approval_not_pending`; the next sends "deciding an already-decided row a second time" to `approval_already_used`. **Plan reading:** deciding a row that is not `pending` is `approval_not_pending`, and `approval_already_used` is reserved for consuming a row whose `consumed_at` is already set. That is the only split under which each code names one condition.
- **F-24 (AC-2): wrong owner named.** AC-2 says the line salesperson is validated "against Organization's employee directory". The contract is `Contracts\Access\EmployeeDirectory` (implemented by `packages/access`). Nothing else changes.
- **F-25 (AC-41 vs D-37): the usage-limit boundary.** AC-41 says `usage_limit_reached` fires "once `used_count` would reach `usage_limit`", which would allow only `usage_limit − 1` uses. D-37 says "would exceed", and T-029's engine refuses at `used_count = usage_limit`. **Plan reading:** `consume()` refuses when the stored `used_count ≥ usage_limit`, so exactly `usage_limit` uses succeed. Step 5 has a boundary row for it.

### 0.3 Rulings

**Ruling 1: placement.** A new path package, `packages/sales` (`flexipos/sales`, namespace `Flexipos\Sales\`). It publishes **no** contract yet, because nothing outside it calls Sales today. FP-234 adds one when it needs it.
- **Direct reads**, limited to existence checks and read-only facts (T-029 Ruling 1's precedent, the `Lookup` helper):
  - `products` (name, sku, category, brand, `allow_price_override`, soft delete; and `is_active`, `is_sellable`, `type` for Q-3's default, R-29);
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

None of it is tax, discount or factor arithmetic, so AC-42 needs no exemption (D-35 deferred the only case that would have needed one).

**Ruling 4: the sale state machine.**

| From | Command | To |
|---|---|---|
| — | `CreateSale` | `draft` |
| `draft` | first `AddTender` | `pending_payment` |
| `held` | first `AddTender` | `pending_payment`, only when held from `pending_payment`, through an implicit recall (F-22); a sale held from `draft` must be recalled first |
| `pending_payment` | last `RemoveTender` | `draft` |
| `draft`, `pending_payment` | `HoldSale` | `held` (records `from_status`) |
| `held` | `RecallSale` | the recorded `from_status` |
| `pending_payment`, or `draft` with total `0.0000` and at least one line | `CompleteSale` | `completed` |
| `draft`, `held`, `pending_payment` | `VoidSale` (no approval) | `voided` |
| `completed`, shift still open | `VoidSale` (approval) | `voided` |
| `completed`, `partially_refunded` | `RefundSale` against it | `partially_refunded` or `refunded` |

- AC-11 accepts `AddTender` on a sale held from `pending_payment`. Under F-22's reading, that call runs AC-18's recall first (a `sale_holds` recall row, `terminal_id` and `shift_id` moved), so the hold/recall trail and the shift attribution stay honest. A sale held from `draft` is refused `sale_not_editable`.
- `failed` and `exchange` are unreachable.
- Every write that changes a sale locks the `sales` row `FOR UPDATE` before it checks anything, so concurrent tenders cannot both pass the balance check. A write that also attributes the sale to a shift takes the `cash_shifts` row first and the sale second (Ruling 7 as amended by R-5).

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

**Ruling 7: transaction order** (§12.1: resolve before you lock). *Amended in revision 3 (R-5, R-27).*
1. `UnitResolver` and `PricingEngine` run **before** the transaction opens, on autocommit reads. They read only facts that cannot change under the write: a sale's customer, branch, business date and currency are fixed at `CreateSale`, and a sale line's unit, factor and discount are fixed once written.
2. **The first database statement inside every write transaction is a locking read** (T-029 review W-1, T-028 review W-1). Under InnoDB REPEATABLE READ the first plain read fixes the snapshot, and a lock taken after it does not refresh what the later checks read.
3. **One global lock order.** Every writer that takes more than one of these locks takes them in this order, and skips the ones it does not need:

   | # | Row | Taken by | How |
   |---|---|---|---|
   | 1 | `cash_shifts` (by id) | `CreateSale`, `RecallSale`, `AddTender` with an implicit recall, `VoidSale` of a completed sale, `RefundSale`, `RecordCashMovement`: `sharedLock()`. `CloseCashShift`, `ReconcileCashShift`: `lockForUpdate()` | The shift id is read before the transaction (the terminal's open shift, or the completed sale's own `shift_id`, which nothing changes after completion). It is then locked **by primary key**, never by a `(terminal_id, status)` range: a range lock takes gap locks, which is T-029 review round 2 W-2's deadlock class. After the lock, the status (`open`) and the terminal are re-checked |
   | 2 | `sales` | every sale write, `lockForUpdate()`. A refund locks the **original** sale here; the refund's own row is new | When a write locks two sales, it takes the lower id first. None does today |
   | 3 | `approval_requests` | `ApprovalGate::consume()`, `DecideApproval` (which takes it first, since it locks nothing before it) | `lockForUpdate()` |
   | 4 | `payment_methods` | `AddTender`, `RefundSale` (`sharedLock()`); `UpdatePaymentMethod`, `DeletePaymentMethod` (`lockForUpdate()`, their first statement) | The shared lock is what stops a tender landing on a method that a concurrent delete has just found unused (T-028 review W-4's class) |
   | 5 | `discounts` | `DiscountUsage::consume()`, once per distinct discount, **in ascending id order** | Two completions consuming {A, B} and {B, A} would otherwise deadlock |
   | — | `stock_levels` | nothing yet. **FP-234 takes this slot** | T-028's debt to FP-233: stock before customers |
   | 6 | `customers` | `CustomerCreditLedger` (inside the writer) | `lockForUpdate()`, by the writer itself |
   | 7 | `number_sequences` | `DocumentNumbers::next()` | **Always the last existing row a transaction locks.** The company-wide row is the hottest lock in the shop and is held until commit, so it is taken as late as possible. Inserting new rows after it is fine |

4. Then the audit row. Nothing in this order is ever taken in reverse by another writer. `CloseCashShift` reads sales, tenders and movements **after** its exclusive shift lock and does not lock them, so there is no cycle with a writer that holds a sale and waits on the shift.
5. There is no stock lock (D-1), so T-028's "stock before customers" order is trivially kept. The unnumbered slot between rows 5 and 6 is where FP-234 adds it.

**D-50 says "every write locks the `sales` row first".** Row 1 departs from that for the writes that attribute a sale to a shift. D-50 is informational and consolidates this ruling, so the amendment is this plan's to make. It is routed to `ba` as question Q-7, so that D-50 is reworded to match.

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
| Approvals | `RequestApproval`, `DecideApproval` | 23, 23a, 24 |
| Cart | `CreateSale`, `AddSaleLine`, `UpdateSaleLine`, `RemoveSaleLine` | 1–10 |
| Tenders | `AddTender`, `RemoveTender`, `CompleteSale` | 11–15 |
| Hold | `HoldSale`, `RecallSale` | 17–20 |
| Corrections | `VoidSale`, `RefundSale` | 21, 22, 25–31 |
| Reads (D-26) | `ListSales` (branch required; status filter covers AC-20), `GetSale` (header plus tenders), `ListSaleLines`, `ListApprovalRequests`, `ListCashShifts`, `ListCashMovements` | 20, D-26 |

**Ruling 8 amendment (revision 3, R-40): the refusal shape.** `SalesRefused` carries a `SalesRefusal $reason` and a `?\BackedEnum $cause`, which is the foreign refusal when there is one (`PricingRefusal`, `UnitRefusal`, `CreditRefusal` or `DocumentNumbersRefusal`). The cause's enum **type** is always carried, because several values exist in more than one enum (`unknown_product`, `invalid_amount`, `out_of_range`, `replay_conflict`). The code a caller sees:

| Source | Code |
|---|---|
| a `PricingRefused` from `quoteLine()` or `computeTax()` | the cause's own value (AC-5), except `no_price_resolved`, which is `unresolvable_price` |
| a `PricingRefused` from `DiscountUsage::consume()` | the cause's own value (`usage_limit_reached`, `unknown_discount`, `out_of_range`; AC-14, AC-41) |
| a `UnitResolutionRefused` | `unit_unresolvable` (AC-3), or `inherited_unit_mismatch` (AC-26), with the cause attached |
| a `CreditLedgerRefused`, or a replayed posting (R-4) | `credit_refused`, with the cause attached (AC-14) |
| a `DocumentNumbersRefused` | `numbering_refused`, with the cause attached |

### 0.5 Reconciliation with the shipped code (revision 3)

Read at `74fceba`. Every symbol, signature, enum case, table, column, permission key and migration this plan names was checked in source, never in a plan or a summary. **Holds** means the plan was right. **Drift** means the plan was wrong and is fixed in place. **Gap** means a defect class the plan did not rule out, and now does, in the step named.

**Serious** marks a finding that would otherwise have produced wrong money, a corrupted or silently skipped ledger entry, a refund that can never be made, or a stop under the red-list rule. R-1 to R-8 are the serious ones. R-1 is the only one that needs a ruling, and Chunk A does not depend on it.

#### Serious

**R-1 [Serious; spec gap; to `ba` as Q-1, owner-level]. A referenced refund line is re-quoted through the engine's *current* discount and product state.**
- **Evidence.**
  - `DatabasePricingEngine::quoteLine()` refuses `unknown_product` for a soft-deleted product.
  - `discounts()` loads the discount `whereNull('deleted_at')`, so a deleted discount surfaces as `unknown_discount`.
  - `DiscountCalculator` refuses `inactive` on the **current** `is_active`, and `usage_limit_reached` on the **current** `used_count >= usage_limit` (line 108).
  - Only the window checks (`not_yet_started`, `expired`) run against the quote's `at`.
- **Consequence under D-38** (the original instant and the original `discount_id`). A line cannot be refunded when any of these holds:
  - (a) its discount has since reached its usage limit. With `usage_limit = N`, the N-th sale's own `DiscountUsage::consume()` makes **all N of those sales** unrefundable on that line;
  - (b) the discount has been deactivated or soft-deleted;
  - (c) the product's `allow_discount` has been turned off (`discount_not_allowed`), or the discount's `applies_to` has been narrowed (`not_applicable`);
  - (d) the product has been soft-deleted (`unknown_product`). This refuses a **goodwill** refund of that product too, because a goodwill line is also quoted. A discontinued product can then never be refunded at all.
- **Whose defect.** This is not a Sales bug. It is a gap between D-38 and the engine as shipped. Fixing it needs either an additive pricing contract change (a quote mode that skips the live-state checks but keeps the window and the arithmetic), or a refund rule that does not go through the engine, which AC-42 and D-38 forbid.
- **Plan default (step 20).** Pass the engine's refusal through under its own name and write nothing. Dataset rows pin (a) to (d), so the behaviour is visible rather than discovered. A follow-up row goes to `lead`. **Chunk A does not depend on it. Chunk D should not be accepted until it is ruled.**

**R-2 [Serious; red-list stop in step 2]. A test double implements `UnitResolver`.**
- `packages/pricing/tests/Features/AddPriceListItem/AddPriceListItemTest.php:116` holds `new class implements UnitResolver`.
- Adding `snapshotInherited()` to the interface turns that class into a fatal error, so the file errors at collection, before any test in it runs. Revision 2's predicted-red list did not name it, so step 2 would have stopped.
- The only implementers are `DatabaseUnitResolver` and this double (searched for `implements UnitResolver`).
- **Fix:** step 2 adds the method to the double. It throws, because nothing calls it. The file is now on the list.

**R-3 [Serious; a planned test cannot be written]. FP-440 makes `no_price_resolved` unreachable through the real adapter.**
- `products.selling_price` is `NOT NULL DEFAULT 0`, so ladder step 6 always resolves. Step 14's row "no price gives `unresolvable_price`", written against the real engine, can never go red-then-green honestly.
- **Fix:** that row moves into the `FakePricingEngine` dataset, which raises `PricingRefused::noPriceResolved()`. Against the real engine, step 14 instead pins that an unpriced product quotes `0.0000`, with source step 6.
- **Behaviour.** Sales accepts a zero-priced line, as the spec says. Ruling 4 also lets a draft with a total of `0.0000` and at least one line complete with no tender. Together, an unpriced product can be rung up and completed with no money taken. That is owner question Q-2. Read it with FP-443: an unavailable `product_branches` row still prices.

**R-4 [Serious; T-028 review R-1's class]. A ledger post that comes back "replayed" is silently accepted.**
- `CreditLedgerWriter::post()` returns `replayed = true`, and writes nothing, when a row with the same `(reference_type, reference_id, type)` already exists.
- `sale_tender` is not reserved: `RESERVED_REFERENCE_TYPES` holds `customer`, `customer_merge_out` and `customer_merge_in`.
- The reservation check is an exact, case-sensitive `in_array` (FP-435), while on MySQL the unique key uses a case-insensitive collation.
- So a manual `RecordCreditLedgerEntry` with reference type `sale_tender` (in any case, on MySQL) and a tender's id pre-seeds the key, and a tender id is supplied by the caller. `CompleteSale` would then see a "replay" and complete with no debit of its own.
- **Ruled out by construction.** Completion, void and refund each make the **first and only** post for their key. The sale's status lock allows one completion, and a rolled-back attempt rolls back the writer's savepoint with it. So **any `replayed = true` from these posts is refused as `credit_refused`, with cause `replay_conflict`, and the whole transaction rolls back** (steps 17, 19 and 20; mutation M7).
- Reserving `sale_tender` against the public customers slice would be a change to customers: question Q-8. It is not built here.

**R-5 [Serious; unlocked read-then-write, and a stale snapshot]. Revision 2's lock order let writes race `CloseCashShift`.**
- Revision 2 locked the `sales` row first and read the shift with a plain read. Under that order:
  - `CreateSale` and `RecallSale` can attach a draft to a shift that `CloseCashShift` has just checked and is closing;
  - `VoidSale` of a completed sale can pass its "shift still open" check (D-39) and then write mirror tenders onto a shift that closes before it commits;
  - `RecordCashMovement` can add a movement that the close's `expected_cash` never sees.
- Two more races of the same class:
  - `AddTender` reads the payment method without a lock, so a concurrent `DeletePaymentMethod` can find the method unused and delete it while the tender lands on it (T-028 review W-4's class);
  - two completions consuming the same two discounts in opposite orders deadlock.
- **Fix:** Ruling 7, as amended above: a global order, the shift locked by primary key and re-checked, the payment method under a shared lock, discounts in ascending id order, `number_sequences` last, and a locking read as the first statement of every write transaction.

**R-6 [Serious; red-list stop]. The predicted-red list was missing five files.** Each is red the moment the step that names it lands, and the red-list rule would have stopped `dev`:
- `packages/access/tests/Support/AuditCatalogueTest.php`, lines 48–49 and 92: the hard-coded module list `access, catalog, customers, organization, pricing`, and the same list with `fixture`, both gain `sales` (step 1, once the provider declares audit actions);
- `modules/host/tests/Feature/BranchForeignKeysContractRunTest.php`: its "to the top" cases count one run per contract step. A new contract step on top adds one run to each: three becomes four, and four becomes five. A `bfkRunT030V()` helper finds file 10 by name, the `bfkRunT029V()` shape (step 7);
- `modules/host/tests/Feature/IdempotencyKeysSchemaTest.php:117-119`: it pins the catalogue top at version 63, `2026_11_10_002912_…`, phase `contract`. That becomes 73, file 10's name, `contract` (step 7);
- `AddPriceListItemTest.php` (R-2, step 2);
- `packages/customers/tests/Schema/CustomersTenantSchemaTest.php:174-182`: this one was on the list, but its nature was not stated. Its test is **T-028's own AC-33 absence assertion** ("no `sale_id`"), and step 7 inverts it, so `qa` reviews it as an AC-33 change, not as an incidental column edit. Its FK inventory (line 356) also gains `loyalty_transactions.sale_id -> sales.id`.

**R-7 [Serious; gate stop at chunk C]. The deptrac grant for Sales' tests was wrong.**
- Revision 2 granted `Package_Sales_Tests` the **source** layers `Package_Catalog`, `Package_Pricing`, `Package_Customers` and `Package_Organization`. Tests reach real implementations through the container, by contract, so they do not need those.
- What they do need, and revision 2 did not grant: step 14 seeds "through T-029's seeds". `Flexipos\Pricing\Tests\Support\PricingSeed` (units, products, customers, taxes, price lists, discounts) is in `Package_Pricing_Tests`.
- `Package_Access` collects `^Flexipos\\Access\\.*`, tests included, so it covers `AccessTenantFixture` and `AccessSeed`.
- **Fixed grant:** `Package_Sales_Tests: [Package_Sales, Package_Contracts, Package_Kernel, Package_Access, Package_Pricing_Tests]`. A test that names an implementation class directly (none is planned) would have to justify adding that one source layer.

**R-8 [Serious; raw exceptions and false conflicts]. Decimals come back from the database as numbers, not canonical strings.**
- On the SQLite stand-in a `DECIMAL` column reads back as an `int` or a `float`. On MySQL it reads back as a string such as `"7.5000"`. A caller then sends `"7.5"`.
- `ResolvedFactor`, `UnitSnapshot` and every pricing DTO accept **only** canonical strings. `ResolvedFactor` requires exactly `\d+\.\d{6}`, and it throws `InvalidArgumentException`, not a named refusal.
- So feeding a stored `unit_factor` straight into `snapshotInherited()` is a raw exception on the stand-in. And comparing a replayed tender's `amount`, or an approval payload's `unit_price`, as strings reports a false `replay_conflict` or `approval_mismatch`.
- **Fix:**
  - every decimal read from a row is canonicalised first (the adapter's `decimal()` precedent: `number_format` for a float, then the canonical scale);
  - every decimal equality test is `BigDecimal::compareTo() === 0`, which is pricing's `Fields::sameNumber`;
  - a dataset row in steps 16 and 20 pins each case.

#### Contracts and signatures

**R-9 (Holds). `PricingEngine` has three methods**, exactly the ones the plan calls:
- `quoteLine(LineQuote): PricedLine`;
- `computeTax(TaxQuote): TaxBreakdown`;
- `billDiscount(BillQuote): BillDiscount`, which Sales never calls (D-35).

**R-10 (Drift). `LineQuote` and `Shape`.**
- The constructor order is `(productId, UnitSnapshot unit, at, documentDate, currency, ?customerId, ?customerGroupId, ?branchId, ?discountId)`.
- `Shape` splits failures:
  - a malformed **amount** is `PricingRefused(invalid_amount)`;
  - a malformed **id, date, instant or currency** is an `InvalidArgumentException`, treated as a caller bug.
- So Sales folds and validates every id it receives (`Identifier::fold`, giving `validation`) **before** it builds a DTO. A malformed client id must never become a 500.
- `at` must be `YYYY-MM-DDTHH:MM:SS.mmmZ`. `BusinessClock` produces it for an ordinary line. A refund converts the original sale's stored `completed_at` (a `DATETIME(3)` string) the way the adapter's `instant()` does.
- The other arguments:
  - `documentDate` is the sale's `business_date`;
  - `currency` is the sale's `currency`, which Organization's write path stores upper-case;
  - `customerGroupId` is `customers.group_id`, read as a fact;
  - `branchId` is the sale's branch.

**R-11 (Drift). `PricedLine` has no `taxAmount`.**
- Its fields are `listPrice`, `listDiscountPercent`, `unitPrice`, `source`, `subtotal`, `discountAmount`, `discountId`, `discountedSubtotal`, `taxGroupId`, `tax` (a `TaxBreakdown`) and `lineTotal`.
- So `tax_amount = $line->tax->total`, and `tax_breakdown = $line->tax->toArray()`.
- `PricedLine::toArray()` is **not** the column map. It carries `source`, `list_discount_percent`, `discounted_subtotal` and a nested `tax`, so `LineWriter` maps field by field.
- `subtotal` is before the discount. It is the gross for an inclusive group and the net for an exclusive one. `lineTotal` is the discounted amount (inclusive) or the discounted amount plus tax (exclusive).
- Hence:
  - `total = Σ line_total` holds for every sale;
  - `subtotal − discount_total + tax_total = total` holds only for an all-exclusive sale. Step 9's "inclusive: `tax_total` is informational" is right;
  - `discountedSubtotal` has no column and the rollup does not need it.

**R-12 (Drift). `TaxQuote` has two arguments revision 2 did not name.** It is `(taxGroupId, amount, baseQuantity, documentDate, TaxContext $context)`. The override path (F-16) passes:
- `amount` = the override subtotal;
- `baseQuantity` = `UnitSnapshot::$baseQuantity`, which feeds any `fixed` tax;
- `documentDate` = the business date;
- `TaxContext::Sale`.

`amount` is the net for an exclusive group and the gross for an inclusive one, and `net + total` is the gross in both. That is exactly what `lineTotal = tax.net + tax.total` needs.

**R-13 (Holds). `PricingRefusal` is a closed list of 17 cases:** `unknown_product`, `unknown_tax_group`, `no_effective_rate`, `inclusive_amount_too_small`, `no_price_resolved`, `unknown_discount`, `discount_scope_mismatch`, `inactive`, `not_yet_started`, `expired`, `usage_limit_reached`, `discount_not_allowed`, `not_applicable`, `below_min_amount`, `unsupported_discount_type`, `invalid_amount`, `out_of_range`. Every named constructor exists, including the three `DiscountUsage` needs (`usageLimitReached`, `unknownDiscount`, `outOfRange`).

**R-14 (Holds, plus a Gap). Signs and zero quantities.**
- `Shape::money` refuses a sign, so a negative quantity is `invalid_amount` (T-029 D-26). Refund lines are unsigned (D-43), which is consistent.
- But `Shape` accepts `0.0000`. `UnitResolver` accepts zero **and** negative quantities: `UnitSnapshot`'s pattern allows `-`, and `SnapshotCalculator` reads `^-?\d+`.
- **Gap fixed in step 14:** Sales refuses a quantity `≤ 0` as `validation` before it calls the resolver, on sale lines and refund lines alike.

**R-15 (Holds: still owed). `DiscountUsage` was not shipped.**
- There is no contract and no writer. The only writes of `used_count` are `CreateDiscount`'s literal `0`, and `DiscountFields` refuses it as an input field.
- The engine reads `used_count` without a lock at quote time and refuses at `>=`, which matches D-54.
- **Drift:** revision 2's claim that `QaPricingSourceRulesTest` "already allows `used_count` inside `packages/pricing/src`" is false. No scan names `used_count`. `QaSalesSourceRulesTest` (step 23) is the first.
- `DatabaseDiscountUsage` sits in pricing's **read path** (all of `src/` outside `Features/`), so the no-ordering, no-limit and no-sort scans apply to it. It does exactly this:
  - its first statement is one keyed `lockForUpdate()->first()`;
  - `whereNull('deleted_at')`, so a deleted discount is `unknown_discount`;
  - `out_of_range` when `used_count` is already at `UNSIGNED INT`'s maximum, 4294967295;
  - an `update`, never an `increment()` on a stale value.
- `is_active` and the window are **not** re-checked: they are facts checked at quote time (Q-11).

**R-16 (Holds, by absence). `TaxGroupDirectory`'s `bool $lock`** (T-029 review W-2) is never called by Sales. The engine prices an inactive tax group normally (K-12), so Sales has nothing to check.

**R-17 (Holds, plus a Gap). Ordering.** The adapter never orders (D-29). The pricing ordering scan covers pricing's read path only, so Sales' lists may order by their own keys. **Gap:** cash movement ids are supplied by the caller, so `ListCashMovements` orders by `(created_at, id)`, never by `id` alone (T-028 review's ordering suggestion).

**R-18 (Holds). `UnitResolver` and `SnapshotCalculator`.**
- The interface has `resolve(productId, fromUnitId): ResolvedFactor` and `snapshot(productId, unitId, int|string quantity): UnitSnapshot`. `snapshotInherited()` is absent, so it is still owed.
- `FactorSource` has 4 cases. `UnitRefusal` has 10, with no `InheritedUnitMismatch`.
- `SnapshotCalculator::compute(ResolvedFactor, lineUnitId, lineUnitCode, int lineUnitPrecision, int stockUnitPrecision, int|string quantity)` is pure. The plan's use of it holds.
- Two facts revision 2 did not state:
  - `resolve()` does not check `products.deleted_at`; the engine does;
  - `products.stock_unit_id` is **immutable** (`UpdateProduct`, T-027 AC-22). So "the requested unit is the stock unit, factor `1.000000`" cannot drift between a sale and its refund.

**R-19 (Drift). The catalog unit-fixture format.**
- Fixtures live at `packages/catalog/fixtures/units/unit-*.json`. The lint requires `kind: unit` and `name` equal to the file name, with every decimal a string. The replay runs `SnapshotCalculator` with `input.factor_source`.
- Revision 2's "`inherited-*.json` with an `inherited` kind" would fail that lint.
- **Fix (step 2):**
  - the same-unit and stock-unit cases are ordinary `kind: unit` fixtures, named `unit-inherited-*.json`, with `factor_source` set to `inherited` or `stock_unit`. The replay needs no change once `FactorSource::Inherited` exists;
  - the third-unit refusal needs the selection step. The lint widens to `kind ∈ {unit, inherited}`, and a `kind: inherited` replay runs `InheritedFactor`, then `SnapshotCalculator`;
  - there is at least one `inherited` fixture per branch of the rule (same unit, stock unit, third unit).

**R-20 (Drift). The customers credit ledger.**
- `CustomerCreditLedger::record(CreditLedgerEntry): CreditLedgerPosting` throws `CreditLedgerRefused`.
- `CreditRefusal` has **16** cases. Revision 2 said 15; `reserved_reference_type` came with T-028's fix round.
- `CreditLedgerEntry` is `(customerId, CreditEntryType, referenceType (1 to 50 characters), referenceId (ULID), int|string debit, int|string credit, ?currency, ?branchId, ?employeeId, ?notes, ?actorId)`.
- The writer runs in this order: the replay pre-check (a plain read); `customers FOR UPDATE`; the live check (deleted or merged gives `customer_not_live`); the currency (`currency_required`, `currency_mismatch`); the branch and employee existence checks; the calculator (`credit_not_allowed`, `over_limit`, `customer_not_active`); the insert; the balance update; and **its own audit row**, `customers_credit_ledger_entry_recorded`. For Sales this means:
  - **amounts are positive canonical strings:** `credit_sale` debit = the tender amount; `credit_note` credit = |the mirror's amount|; `refund` credit = |the refund tender's amount|. The sign flip is a `Money` call (Ruling 3);
  - **`currency` is the sale's currency**, so a customer held in another currency is refused at completion (`credit_refused`, `currency_mismatch`), never earlier, because AC-11 checks only that a customer is present;
  - **the audit invariant** (step 22, test strategy) is "one sales audit row per write, plus one customers audit row per ledger posting", not "one audit row per write".

**R-21 (Holds). Reserved reference types and the replay key.** The reserved types are `customer`, `customer_merge_out` and `customer_merge_in`, and the key is `(reference_type, reference_id, type)`. `sale_tender` collides with none of them. The case-sensitivity gap is R-4's.

**R-22 (Holds). Customers before stock: FP-233's note.** T-030 writes no stock (D-1), and Ruling 7 reserves the slot. `MergeCustomers` locks both customer rows in ascending id order and touches no sales table, so there is no cycle with Sales.

**R-23 (Not applicable). Loyalty points rounded to 4 decimals, half up, before `LoyaltyLedger`** (T-028's follow-up). Sales never calls `LoyaltyLedger` (D-5, AC-45). The rule is added to the follow-up row for whichever task wires earning.

**R-24 (Holds; one test row added). Access and the PIN.**
- There is no `PinVerifier` yet; it is owed.
- `VerifyPinHandler::handle(VerifyPin(code, pin, ?ip)): VerifyPinResult` returns a result with `?employeeId`. It runs its own transaction, whose first locking statement is `employees FOR UPDATE`, and it writes the cause to `access_log` only. `HandlerPinVerifier` returns `$result->employeeId`.
- `QaVerifyPinReachabilityTest` follows interface bindings, so a future route to a slice that depends on `PinVerifier` will be reported. No change is needed.
- **Row added to step 10, from `QaPinAttemptSinkTest`'s rule:** a sentinel PIN given to `DecideApproval` appears in no table, no audit `after`, no log line and no result.

**R-25 (Holds). Employees and permissions.**
- `EmployeeDirectory::isLive(id): bool` means the row exists and is not soft-deleted; it does not consult status (F-24).
- `PermissionResolver::decide(employeeId, key, ?branchId): AccessDecision` returns `{allowed, ?reason, ?detail}`:
  - a branch-scoped key asked with no branch is `branch_required`;
  - an unregistered key is `unknown_permission`, so every Sales fixture syncs the registry;
  - a null-scope contribution covers every branch.
- `PermissionDeclaration`'s pattern accepts `sales.approvals.decide` and `sales.shifts.reconcile`.
- These will be the **first real branch-scoped keys**: Access and Organization declare only company-wide ones (T-010 D-13, T-014 D-12). The resolver's branch arm has so far been proven only with fixture keys, so step 10 tests both a branch-scoped grant and a null-scope grant.
- `QaAccessHttpSurfaceTest` refuses a branch-scoped key on a route. Sales has no routes.

**R-26 (Holds). Organization's terminals and branches.**
- `TerminalAuthority::activeBranchOf(terminalId): ?string` returns null for a terminal that is missing, paused or revoked, or whose credential is revoked. It returns only the branch, so `location_id` is read from `terminals` directly (`Lookup`).
- `branches.timezone` is an IANA zone and `branches.currency` is `CHAR(3)` upper-case, both guaranteed by Organization's `Fields`.

**R-27 (Drift; Gap). `number_sequences` as built.**
- The columns:
  - `id`;
  - `branch_id` `CHAR(26)` NULL, keyed onto `branches` by T-014's contract file;
  - `branch_id_key`, a stored `COALESCE` sentinel;
  - `document_type` `VARCHAR(30)`;
  - `prefix` `VARCHAR(20)` NULL;
  - `next_number` `BIGINT`, default 1;
  - `padding` `TINYINT UNSIGNED`, default **0**;
  - `suffix` `VARCHAR(20)` NULL;
  - `reset_period` `VARCHAR(30)`, **NOT NULL, with no default**;
  - `last_reset_at`, `created_at`, `updated_at`.
- The key is unique `(branch_id_key, document_type)`. Nothing reads or writes the table yet.
- What `DatabaseDocumentNumbers` must do:
  - set `reset_period = never` and `padding = 6` explicitly on the default row it inserts;
  - make the duplicate-insert retry a **`lockForUpdate()`** read. A plain re-read after the failed insert sees the old snapshot on MySQL (T-028 review W-1);
  - refuse `number_too_long` when prefix plus digits plus suffix would exceed 30 characters (`sales.number` and `cash_shifts.number` are `VARCHAR(30)`). That is a second `DocumentNumbersRefusal` case beside `unsupported_reset_period`.
- The migration's docblock describes MySQL's `LAST_INSERT_ID` allocation. The portable `lockForUpdate()` plus increment is right for a suite that runs on SQLite. Step 25 records the difference in §6.1.

**R-28 (Holds). Platform.** The plan names no `packages/platform` symbol, and Sales needs none.

**R-29 (Holds; Gap to `ba`). Product and customer facts.**
- `products` has every column the plan reads: `sku` (50), `name` (150), `category_id` NOT NULL, `brand_id`, `allow_price_override` (default false), `allow_discount`, `min_selling_price`, `price_mode`, `is_active`, `is_sellable`, and `type`, which includes `variant_parent`.
- **Neither the engine nor revision 2 checks `is_active`, `is_sellable` or `variant_parent`:** question Q-3.
- `customers` has `group_id`, `name` (150), `phone` (30), `tax_no` (50), `currency`, `status`, `deleted_at` and `merged_into_id`. The sale's snapshot widths match.

**R-30 (Holds). Discount approval columns.** `discounts.requires_approval` (boolean, default false), `approval_threshold` and `workflow_definition_id` exist. The last two are not evaluated (D-37).

#### Schema and migrations

**R-31 (Drift). Numbers on the merged tree.**
- The top is `2026_11_10_002912_add_product_tax_group_foreign_keys`: version 63, phase `contract`. `.env.example` holds `APP_REQUIRED_SCHEMA_VERSION=63`. There are 63 tenant migration files and 58 tenant tables.
- T-030's ten files, `2026_11_10_003001` to `003010`, sort after it and take versions **64 to 73**. `APP_REQUIRED_SCHEMA_VERSION` becomes **73**, and the table count becomes **66**.
- File 10 is a contract step on top of the tree. The planner therefore needs a fleet at 72 before a run through 73. Because 63 is also a contract step, a fresh tenant needs one more stop (R-6, `BranchForeignKeysContractRunTest`).

**R-32 (Holds). The three D-44 additions** are built inside new-table migrations: `approval_requests.consumed_at` in 04, `sale_items.subtotal` in 06, and `sale_holds` in 08. None needs its own expand file.

**R-33 (Holds). `loyalty_transactions.sale_id`.**
- File 09 (`@phase expand`): `IdentifierColumn::nullable('sale_id')` plus `unique(sale_id, type)`. An index on an existing table is allowed in expand, because `SourceRules`' AC-34 restricts foreign keys only.
- File 10 (`@phase contract`): the key alone, in the `002912` shape:
  - the data-clean count runs first, and its message names the table, the column and the count, never an id;
  - a key that is already present is skipped;
  - restrict only, and no `down()`.
- T-014 D-31's "group contract keys into one file": file 10 is T-030's **only** contract file, and it holds exactly one key.
- Nothing is destructive (T-008 D-10). Both catalogue rows have `is_destructive: false`.

**R-34 (Holds). Expand and contract elsewhere.** The eight new tables are created with their keys in expand, which is allowed for new tables. No column of an existing table changes, and nothing is dropped.

#### Shared files and the toolchain

**R-35 (Drift). The exact shared-file edits** are the table under "Edits to existing files", now complete:
- `providers.php`;
- `deptrac.yaml`: two layers, `HostApp`, `HostTests`, and the R-7 grant;
- `phpstan.neon.dist`: `packages/sales/src` and `packages/sales/tests`;
- host `composer.json` (`require` and `autoload-dev`) and `composer.lock`;
- `AuditCatalogueTest`, both lists;
- the `QaToolchainRegistryCoverageTest` literal, line 446;
- the schema catalogue, republished and never hand-merged;
- `.env.example`, to 73;
- the schema-count and inventory tests, plus R-6's five files.

**R-36 (Holds). Test discovery.**
- `phpunit.xml` already globs `../../packages/*/tests`, and each package test file binds `Tests\TestCase` itself, so no `phpunit.xml` edit is needed.
- The new owner-side basenames are unique today: `SnapshotInheritedTest`, `HandlerPinVerifierTest`, `DocumentNumbersTest`, `DiscountUsageTest`.

**R-37 (Drift). Where the tenth `Support` copy comes from.**
- From pricing: `Fields`, because its `money()` and `rate()` cap the integer digits and `sameNumber()` compares with `BigDecimal`; `Uniqueness`, for the `lower(code)` pre-check; and `Lookup`, with `lock:`.
- From customers: `Decimal`, `Pager` and `Page`.
- Revision 2 named the customers set wholesale. Customers' `Fields::decimal` gained its digit cap only in a fix round, and its `datetime` accepts rollover dates (FP-436). Sales accepts no client date at all.

#### The defect classes from the T-028 and T-029 reviews

**R-38 (Gap; each now ruled out by construction).**

| Class | Where it would arise here | Ruled out by |
|---|---|---|
| A replayed write colliding with a crafted key | the ledger posts | R-4 |
| An unlocked read, then a write that depends on it | the shift, the payment method, the discounts, the approval, the customer's liveness at `CreateSale` | R-5. `CreateSale` reads the customer with `Lookup::live(…, lock: true)`, after the shift (Ruling 7, row 6) |
| A stale snapshot | a plain read before the lock; the `DocumentNumbers` retry | Ruling 7 item 2; R-27 |
| Editing a dead record | updating, removing or deleting a payment method, a line or a tender | Every one reads `whereNull('deleted_at')` where the table has it. A line or tender that is not on the named sale is `not_found`. A sale in `completed`, `voided`, `refunded` or `partially_refunded` is changed only by the named corrections |
| Decimal widths and dates not validated | every money and quantity input | R-39 |
| A case-insensitive collation bypassing a check | `payment_methods.code`; enum values; the approval binding | the `lower(code)` pre-check (pricing's `Uniqueness`); enum values compared exactly, lower-case, in PHP; ids folded to upper case before any comparison (`self_approval_not_allowed`, the binding); the binding compared in PHP after reading the row **by id**, never through a `WHERE` on a case-insensitive column |
| Lock order: parent first, and every writer in the same order | everywhere | Ruling 7's table |

**R-39 (Gap). Input validation.**
- **Decimals.** Every caller decimal (`opening_cash`, `counted_cash`, a movement's `amount`, a tender's `amount` and `tendered`, a line's `quantity`, the override `unit_price`) is a **string** matching `Fields::money`: at most 14 integer and 4 fraction digits, never negative. It is strictly positive where zero is meaningless: quantity, tender amount and movement amount.
- **Rollups.** Every rollup is checked to fit `(18,4)`, or refused `out_of_range`, a new `SalesRefusal`.
- **Text caps.** `reason`, `void_reason` and `discount_reason` are 255. `reference` is 100. `notes` is capped at 65,535 bytes.
- **No client date or instant is accepted.** `business_date`, `client_created_at` and the other `[SYNC]` fields are refused as unknown.
- **Caller ULIDs.** A caller-supplied `id` (a tender, a movement) is validated by `Identifier::fold`.

**R-40 (Drift). The refusal shape.** See the Ruling 8 amendment above. The new `SalesRefusal` cases are:
- `invalid_transition` (`HoldSale`, `RecallSale` or `CompleteSale` from a wrong status, naming the current status);
- `out_of_range`;
- `numbering_refused`;
- `product_not_sellable`, only if Q-3 is accepted.

#### The baseline

**R-41 (Drift). Step 0 measures the merged tree; it no longer rebases.**
- The expected failures, with **no `APP_KEY` in the environment**, are **13, by name**: `QaModuleRegistryContractTest` 6, `QaModuleNameValidationTest` 3, `QaHealthProbeResilienceTest` 2, `ModulesEnvParsingTest` 1, `QaVocabularyRenameSweepTest` 1.
- With an `APP_KEY` set, one `QaHealthProbeResilienceTest` case passes and the count reads 12. That is not a baseline, so the gate is run without one.
- Any failure outside that set stops `dev` at step 0, with a report.
- The passed and skipped counts are not predicted. T-029's final tree read 41 skipped and 4110 passed, but it lacked the later T-028 tip, so `dev` records what the merged tree reads.
- The **`test`-step seconds** measured at step 0 are the reference for the 10 % stop line at every chunk gate (FP-328).
- The engine hold stays on: `ENGINE_HOLD` is respected, and the `[ENGINE]` halves are written and skipped.

---

## Approach summary

1. A new `packages/sales` holds the till's documents and their state machine: payment methods, cash shifts and movements, PIN-gated approvals, sales with priced lines, tenders, hold and recall, void and refund. It does no stock, costing, GL or gateway work (D-1, D-6, D-7).
2. Every price, discount and tax figure comes verbatim from `Contracts\Pricing\PricingEngine`, and every unit quad from `Contracts\Catalog\UnitResolver`. Sales' own arithmetic is sums, differences and one override product, all in a single file (Ruling 3).
3. Four small cross-package contracts are added first: `snapshotInherited()` (owed by FP-233), `PinVerifier`, `DocumentNumbers` and `DiscountUsage`. T-028's `loyalty_transactions.sale_id` debt is discharged in schema.
4. Eight new tenant tables, plus two migrations on `loyalty_transactions`. Undocumented additions are named (F-18). Deferred columns are deferred by not building them (F-8).
5. Credit is posted to the customer ledger once per credit tender at completion, and compensated with `credit_note` on void or `refund` on refund (F-2 to F-4).
6. Static instruments cover the precision map (AC-44), schema absences (AC-43) and the source-rules scan (AC-42), plus one engine test.

## Alternatives rejected

- **Building bill discounts with an allocator now:** it invents an unruled business rule and needs an AC-42 exemption (F-1; the lead deferred it, D-35, and it is a follow-up row).
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
- `fixtures/units/unit-inherited-*.json` (new, at least 4; R-19): the same-unit and stock-unit cases are `kind: unit` with `factor_source` set to `inherited` or `stock_unit`, and the selection cases (including the refused third unit) are `kind: inherited`. All are replayed by T-029's `UnitFixtureReplayTest`, whose lint widens to `kind ∈ {unit, inherited}` and which gains an `inherited` replay (`InheritedFactor`, then `SnapshotCalculator`).
- `tests/Units/SnapshotInheritedTest.php` (new).
- `packages/pricing/tests/Features/AddPriceListItem/AddPriceListItemTest.php`: its anonymous `UnitResolver` double gains a throwing `snapshotInherited()` (R-2).

### `packages/access`
- `src/Pins/HandlerPinVerifier.php` (new). `src/AccessServiceProvider.php`: binds it. `tests/Pins/HandlerPinVerifierTest.php` (new).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php` (new). `src/OrganizationServiceProvider.php`: binds it. `tests/Numbering/DocumentNumbersTest.php` (new).

### `packages/pricing`
- `src/Discounts/DatabaseDiscountUsage.php` (new). `src/PricingServiceProvider.php`: binds it. `tests/Discounts/DiscountUsageTest.php` (new). No existing scan names `used_count` (R-15; revision 2's claim to the contrary was wrong). The file sits in pricing's read path, so `QaPricingSourceRulesTest`'s rules against ordering, limiting and sorting apply to it, and it satisfies them with one keyed locking read.

### `packages/sales` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/sales` 0.1.0: `php ^8.2`, `brick/math ^0.14`, `flexipos/contracts` (the `packages/customers` shape) |
| `src/SalesServiceProvider.php` | `implements DeclaresAuditActions, DeclaresPermissions` (two keys, F-5c). No routes, no commands |
| `src/Support/{TenantConnection,Fields,Identifier,Lookup,Uniqueness,Pager,Page,Decimal,Money,BusinessClock}.php` | the tenth copy: `Fields`, `Uniqueness` and `Lookup` from pricing, and `Decimal`, `Pager` and `Page` from customers (R-37). Plus `Money` (Ruling 3; also the sign flip for ledger amounts, R-20, and the canonicalising of stored decimals, R-8), and `BusinessClock` (the UTC instant in `Shape::instant` form, the branch-local `Y-m-d` from `branches.timezone`, and the `DATETIME(3)`-to-instant conversion, R-10) |
| `src/Sales/{SaleStatus,SaleType,SaleRollup,SaleLocks,LineWriter}.php` | Ruling 4. `SaleRollup` re-sums the header from `sale_items` (AC-8). `LineWriter` maps `PricedLine` onto columns (AC-4) |
| `src/Approvals/{ApprovalType,ApprovalGate}.php` | Ruling 5 |
| `src/Shifts/ShiftTotals.php` | AC-34/AC-35 rollups (F-15) |
| `src/Tenders/{TenderRules,CreditPosting}.php` | method rules (AC-11, F-17); ledger entry building (F-2 to F-4) |
| `src/Exceptions/{SalesRefused,SalesRefusal}.php` | section 4's refusal list |
| `src/Registry/{SalesAuditActions,SalesPermissions}.php` | |
| `src/Features/<26 slices>/…` | Ruling 8 |
| `tests/Support/{SalesSeed.php,sales-fixture.php,FakePricingEngine.php}` | raw-insert seeds for Sales' own tables, with branches, terminals, employees and the permission registry from `AccessSeed`/`AccessTenantFixture`, and products, units, customers, taxes and discounts from T-029's `PricingSeed` (R-7). The fake engine is **only** for the refusal-propagation rows, including `no_price_resolved` (R-3); every pricing-value assertion uses the real engine |
| `tests/Schema/{SalesTenantSchemaTest,LoyaltySaleIdMigrationsTest}.php` | steps 6, 7 |
| `tests/{Sales,Approvals,Shifts,Tenders}/…Test.php` | the pure helpers |
| `tests/Features/<X>/<X>Test.php` | one per slice |
| `tests/Support/SalesProviderTest.php` | step 1 |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_10_003001_create_payment_methods_table.php` … `003010_…` (versions 64 to 73; they sort after `2026_11_10_002912`, R-31) | 01 `payment_methods`, 02 `cash_shifts`, 03 `cash_movements`, 04 `approval_requests`, 05 `sales`, 06 `sale_items`, 07 `sale_tenders`, 08 `sale_holds`, 09 `add_sale_id_to_loyalty_transactions` (`@phase expand`), 10 `add_loyalty_sale_foreign_key` (`@phase contract`). Shape: `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) enums, full decimal arguments, no `down()`, and docblocks naming each F-8 deferral |
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

**What each approval is bound to (F-21, D-47, and AC-24A/D-72 for the branch):**

| Approval | `branch_id` | `reference_type` | `reference_id` | Payload |
|---|---|---|---|---|
| void | the sale's branch | `sale` | the sale | — |
| refund, against a sale | the refund's branch, which AC-54 requires to equal the original sale's | `sale` | the original sale | — |
| refund, goodwill | the refund's branch (the refunding terminal's) | `goodwill_refund` | null | — |
| price override | the sale's branch | `sale` | the sale | `{product_id, unit_id, unit_price}`, which must equal the write |
| discount | the sale's branch | `sale` | the sale | `{discount_id}` |

**As built (chunk B), checked in `packages/sales/src/Approvals/ApprovalGate.php`.** `consume(type, branchId, referenceType, referenceId, ?payloadMatch, approvalId)` reads the row by id `FOR UPDATE` and refuses, in this order: `approval_required`, `approval_not_approved`, `approval_already_used`, then `approval_mismatch` when the type, the upper-cased `branch_id`, `reference_type`, the upper-cased `reference_id` or the payload differs. It writes `consumed_at` only after every check passes, so a mismatch leaves `consumed_at` unset. So AC-24A's branch check **is already built**, and `ApprovalGateTest` has a `branch` dataset row. Nothing in the gate changes. What is still owed is that each consuming slice passes the branch shown above, never a caller-supplied one.
- `RequestApproval` already refuses a `sale`-bound request whose `branch_id` differs from the sale's (`validation`). Through the slices, the only approval that can reach a consuming write at another branch is a **goodwill refund** one.
- For the `sale`-bound types, the AC-24A rows in steps 15 and 19 seed the approval row raw at another branch (a defence-in-depth row, named as such).
- `ApprovalBinding`'s docblock table has no branch column. Step 19 adds it, because that is a comment-only edit in a file the step already reads.

**Precision map (AC-44).** No fifth pair.

| Pair | Columns |
|---|---|
| (18,4) | every money and quantity column above |
| (18,6) | `sale_items.unit_factor` |
| (9,4) | `sale_items.discount_percent`, `payment_methods.surcharge_percent` |
| (18,8) | `sales.exchange_rate` |

**FK count:** 36 on new tables (`payment_methods` 0, `cash_shifts` 5, `cash_movements` 3, `approval_requests` 4, `sales` 11 including its self-key, `sale_items` 8 including its self-key, `sale_tenders` 2, `sale_holds` 3), plus 1 in the contract file. `dev` recounts the exact list at step 6 and writes it into `TenantBaselineBuildsTest`.

**`APP_REQUIRED_SCHEMA_VERSION`:** 63 → **73** (measured on the merged tree, R-31). The catalogue is republished, never hand-merged. Migration 10 is a contract step, so a fleet below version 72 runs `--to=72` first (the T-014/T-029 planner consequence). A fresh tenant now needs one more stop than before, which is why `BranchForeignKeysContractRunTest` changes (R-6).

**Refusal vocabulary (`SalesRefusal`):**

| Area | Refusals |
|---|---|
| General | `validation`, `not_found`, `duplicate`, `in_use`, `out_of_range` (R-39), `invalid_transition` (R-40), `numbering_refused` (R-40) |
| Shifts | `no_open_shift`, `shift_already_open`, `shift_not_open`, `shift_has_open_sales`, `shift_closed` |
| Cart | `sale_not_editable`, `unit_unresolvable`, `unresolvable_price` (plus the engine's own reasons, AC-5, carried as the cause per the Ruling 8 amendment), `price_override_not_allowed`, `discount_with_override`, `empty_sale`, `product_not_sellable` (D-57, AC-47; `AddSaleLine` and `UpdateSaleLine` only, never a refund line, AC-47A) |
| Tenders | `customer_required`, `credit_refused`, `method_inactive`, `unsupported_method_type` (a `loyalty` or `voucher` method, AC-12; **or a refund tender onto a method with `allows_refund = false`**, AC-12A/D-71), `reference_required`, `exceeds_balance`, `partial_not_allowed`, `balance_due`, `tender_not_removable` |
| Approvals | `approval_required`, `approval_not_approved`, `approval_already_used`, `approval_mismatch`, `approval_not_pending`, `self_approval_not_allowed`, `pin_invalid`, `not_authorised` |
| Corrections and replay | `sale_not_voidable`, `not_refundable`, `over_refund`, `fully_refunded`, `inherited_unit_mismatch`, `replay_conflict` |

There is **no `refund_not_allowed`** (AC-12A: "No separate `refund_not_allowed` code exists"). Chunk A built it as `SalesRefusal::RefundNotAllowed`, and `SalesRegistryTest` pins it among 43 codes. Step 16 removes the case and edits that test to the 42 codes above. Nothing else names it: it is in no `src` call site.

**Contracts:** Ruling 2. **Config:** `sales.page_size` (default and maximum), the customers `Pager` shape. **HTTP, console, jobs, hooks, outbox:** none (an outbox `sale.completed` event is a follow-up). **Dependencies:** the `flexipos/sales` path package only. `brick/math` is already locked.

**Boundary (`deptrac.yaml`, the T-028/T-029 shape):**
- `Package_Sales` = `^Flexipos\\Sales\\(?!Tests\\).*`, ruleset `[Package_Kernel, Package_Contracts]`;
- `Package_Sales_Tests` = `[Package_Sales, Package_Contracts, Package_Kernel, Package_Access, Package_Pricing_Tests]` (R-7). Real implementations are reached through the container, by contract, so no other package's **source** layer is granted. `Package_Access` (which collects access's tests too) covers `AccessTenantFixture` and `AccessSeed`, and `Package_Pricing_Tests` covers `PricingSeed`;
- `HostApp` and `HostTests` gain the layers;
- **`Module_Demo` is not granted `Package_Sales`.**

## Implementation steps

These rules carry over from T-027 to T-029 unchanged:
- red-first, with a fixture or by leaving something out, and never by stubbing a guard to allow;
- the **red-list rule** after steps 2 to 7 and 9 (record the red list, compare it with section "Edits to existing files", and stop on anything that section does not name);
- no child processes;
- unique test basenames;
- list foreign `php.exe` processes before each gate run.

Mutations are run once, reverted, and recorded in `dev`'s hand-back with the red-then-green counts. Every step builds from the revision-2 ACs. Where a step applies one of the F-22 to F-25 readings, it says so, and `ba` ruling otherwise changes that step only.

### Chunk A: reconciliation, plumbing, owed contracts, schema, statics

- [x] **Step 0: verify the merged tree and measure the baseline.** *(No AC. Revision 3: no rebase, R-41.)*
  - Confirm that `db5e772` (T-028) and `74fceba` (T-029) are ancestors of `HEAD`, and that the tree is clean.
  - Then run `composer check` with **no `APP_KEY`** in the environment. Record passed, failed and skipped, the `test` seconds, and the process list. The failed set must be exactly R-41's 13 by name. **Any other failure is a stop**, reported before anything is written.
  - The `test` seconds recorded here are the reference for the 10 % stop line at every chunk gate.
  - **Reconciliation table** (in the hand-back). Revision 3 has done this on paper (section 0.5). `dev` re-confirms it in a minute and reports only differences from R-9 to R-30. For every symbol this plan names, compare the shipped signature with what is written here:
    - `PricingEngine`, `LineQuote`, `PricedLine`, `TaxQuote`, `TaxBreakdown`, `PricingRefused`/`PricingRefusal`;
    - `CustomerCreditLedger`, `CreditLedgerEntry`, `CreditEntryType`, `CreditRefusal`;
    - `UnitResolver`, `SnapshotCalculator`;
    - `VerifyPinHandler`, `PermissionResolver`, `TerminalAuthority`;
    - the `number_sequences` columns;
    - T-029's `QaPricingSourceRulesTest` token list and `UnitFixtureReplayTest` format.

    **Stop and report to `architect`** on any difference that changes a step. Renames are fixed in place.
  - Confirm the schema numbers: top version 63, 58 tenant tables, 63 tenant files (R-31). Record the FK inventory baseline.
  - Build from spec revision 3 (AC-1 to AC-46 plus AC-23a; D-1 to D-54, with D-51 to D-54 folding F-22 to F-25 in exactly as this plan reads them), never from the first draft (`a2e6631`, whose AC numbers differ) and never from this plan's paraphrase. Check whether `ba` has ruled on Q-1 to Q-11 (the "Questions for `ba`" section). Apply any ruling to the step it names; until then, the stated default holds.
- [x] **Step 1: package plumbing.** *(Groundwork.)*
  - `composer.json`, an empty provider, the deptrac/PHPStan/`providers.php` edits (with R-7's test-layer grant), host `composer.json` (`require`, `autoload-dev` `Flexipos\\Sales\\Tests\\`), `composer update flexipos/sales`, and `'sales'` added to `QaToolchainRegistryCoverageTest`'s literal (line 446).
  - The provider declares its audit actions from this step, so `AuditCatalogueTest`'s two module lists gain `sales` here (R-6). If the declarations land later, this edit moves with them.
  - **`SalesProviderTest`:** the provider is loaded and `BigDecimal` autoloads.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Sales\Support\Money` fails deptrac; one importing `Flexipos\Contracts\Pricing\PricingEngine` passes. Delete both.
- [x] **Step 2: `snapshotInherited()`.** *(AC-38, AC-26's unit half; D-32.)*
  - The contract method, `FactorSource::Inherited`, `UnitRefusal::InheritedUnitMismatch`, the pure `InheritedFactor`, and the `DatabaseUnitResolver` method (it calls `SnapshotCalculator::compute()` and never `UnitLadder`).
  - **`SnapshotInheritedTest`:**
    - (a) same unit, referenced factor `24.000000`, quantity 2: base `48.0000`, source `inherited`, **after** `unit_conversions` is edited to 12 (the §6.5 walk-through);
    - (b) the stock unit: factor `1.000000`, source `stock_unit`;
    - (c) a third unit: `inherited_unit_mismatch`;
    - (d) the line unit's precision is still applied first;
    - (e) Q-11's `fractional_base_quantity` still fires;
    - (f) an unknown product gives `unknown_product`.
  - **Input handling:** `$referencedUnitFactor` is canonicalised to six decimals before a `ResolvedFactor` is built from it, because a caller holding a stored factor may hold it as `"24"` (R-8). A row passes `"24"` and `"24.0"` and gets `24.000000`. A value that is not a plain decimal, or is below 1, is refused `factor_below_one`, or `factor_out_of_range` when it exceeds `DECIMAL(18,6)`; it never escapes as an `InvalidArgumentException`. Sales canonicalises as well, so neither side relies on the other.
  - **Fixtures:** at least 4 `unit-inherited-*.json` files (R-19; the T-029 F-15 format, decimals as strings), replayed by the extended `UnitFixtureReplayTest`.
  - **Mutation U1:** call `resolve()` in the same-unit branch. Row (a) goes red. Revert.
  - **Predicted red:**
    - catalog's `FactorSource`/`UnitRefusal` enum-list tests (`CatalogEnumsTest`);
    - `UnitFixtureReplayTest`'s lint (the new kind);
    - **`AddPriceListItemTest`**, whose anonymous `UnitResolver` double becomes a fatal error until it gains the method (R-2). It is fixed in this step.
- [x] **Step 3: `PinVerifier`.** *(AC-39, AC-23's PIN half; D-30.)*
  - Interface, `HandlerPinVerifier`, binding.
  - **Test:**
    - the right code and PIN return the id;
    - a wrong PIN, an unknown code and a locked employee each return null, and the three nulls are indistinguishable;
    - **the hazard, pinned:** a wrong PIN verified inside an outer transaction that is then rolled back loses its `pin_failed_attempts` increment. The test asserts that loss, which is why Ruling 5 calls the verifier outside any transaction, and the contract's docblock says so.
- [x] **Step 4: `DocumentNumbers`.** *(AC-40; the numbering for AC-14, AC-25 and AC-32; D-31.)*
  - **Test:**
    - first use creates the company-wide default and returns `S000001`, then `S000002`;
    - a branch row, when present, wins over the company row;
    - `refund` and `cash_shift` are independent;
    - a `yearly` row is refused `unsupported_reset_period`;
    - a call inside a rolled-back transaction leaves `next_number` unchanged;
    - the duplicate-insert race is simulated by pre-inserting between the read and the insert, and the retry succeeds. **The retry's read is pinned as `for update` in the SQL shape** (R-27): a plain re-read sees the old snapshot on MySQL;
    - the inserted default row has `reset_period = never` and `padding = 6`, both written explicitly. The column has no default, and the padding default is 0 (R-27);
    - a sequence whose prefix, padded digits and suffix would exceed 30 characters is refused `number_too_long`, and nothing changes.
  - **Mutation N1:** drop the lock. The SQL-shape assertion (`for update`) goes red.
  - **Mutation N2:** make the retry read plain. Its SQL-shape row goes red.
- [x] **Step 5: `DiscountUsage`.** *(AC-41; D-37; F-25's reading.)*
  - **Test:**
    - `consume()` increments `used_count` by one;
    - **boundary (F-25):** with `usage_limit = 2`, two calls succeed (`used_count` 0 → 1 → 2), and the third refuses `usage_limit_reached` and writes nothing;
    - a null `usage_limit` never refuses;
    - an unknown or soft-deleted discount gives `unknown_discount`;
    - `used_count` already at 4294967295 with no limit is refused `out_of_range`, and nothing is written (R-15);
    - an inactive discount, or one outside its window, is consumed normally. Those are quote-time facts (R-15, Q-11), and the row documents the choice;
    - the lock is pinned in the SQL shape, and it is the first statement (R-15).
  - **No ordering, no limit, no sort, and a keyed read:** the file is in pricing's read path, so `QaPricingSourceRulesTest` already scans it. `dev` confirms that the scan enumerates it, and that it reports nothing.
- [x] **Step 6: migrations 01 to 08, with `SalesTenantSchemaTest`.** *(The schema halves of AC-1 to AC-37; AC-43, AC-46; D-33, D-44.)*
  - Each table's sorted column list equals the Data section **exactly**, with nullability. That proves every F-8 absence, `journal_entry_id` included.
  - **Unique keys, by raw double insert:**
    - `open_terminal_key`: two `open` shifts on one terminal are refused; an `open` and a `closed` shift are accepted;
    - `sales.number`: two NULLs are accepted;
    - `payment_methods.code`;
    - `cash_shifts.number`;
    - (`sale_id`, `line_no`).
  - The FK set equals the recounted list, and the three cascades delete their children.
  - `SourceRules::inspect()` reports nothing, and no file declares `down`.
  - **Predicted red** (all in the "Edits to existing files" table): `Helpers.php`'s `tenantTreeTables()`, `MigrationTreeDisciplineTest` (58 becomes 66), `TenantBaselineBuildsTest` (FK inventory), `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest` (a `$t030` list of the eight tables), and the catalogue with `.env.example` (versions 64 to 71 at this step). `IdempotencyKeysSchemaTest` is red here too, because the top becomes version 71, `sale_holds`, phase `expand`. Edit it once, at step 7's final values; record it red at the end of step 6.
- [x] **Step 7: migrations 09 and 10, with `LoyaltySaleIdMigrationsTest`.** *(AC-45; D-34.)*
  - **Expand:**
    - a nullable column and the unique key;
    - existing loyalty rows survive with `sale_id` null;
    - two rows with the same `(sale_id, type)` are refused, and two NULLs are accepted.
  - **Contract:**
    - **red-first:** seed a `sale_id` naming no sale. The file refuses, the message names `loyalty_transactions.sale_id` and `1 row(s)` and no id (T-029 F-5), and nothing changes;
    - when clean, the key exists;
    - a re-run is a no-op.
  - The catalogue rows read: 09 `expand`, 10 `contract`, **both `is_destructive = false`**. The top is now version 73, so `APP_REQUIRED_SCHEMA_VERSION=73`.
  - **Predicted red** (R-6):
    - `CustomersTenantSchemaTest`: T-028's AC-33 absence assertion ("no `sale_id`") is **inverted**, and the FK inventory gains `loyalty_transactions.sale_id -> sales.id`. `qa` reviews it as an AC-33 change;
    - `CustomersSchemaEngineTest`, if it lists the columns;
    - `IdempotencyKeysSchemaTest`: the catalogue top is 73, file 10's name, `contract`;
    - `BranchForeignKeysContractRunTest`: a `bfkRunT030V()` helper finds file 10 by name, and each "to the top" case gains one run (three becomes four, four becomes five);
    - `TenantBaselineBuildsTest`: the one contract key.
- [x] **Step 8: AC-43 and AC-44 statics.** *(AC-43, AC-44.)*
  - Extend `TenantDecimalPrecisionTest`'s column map with the Data section's map, under a T-030 comment. The allow-list stays at four pairs. **Red-first:** a fixture `decimal('amount', 18, 2)` is reported.
  - AC-43, AC-46: the static half of AC-43's full absence list. No migration of this task's declares any of:
    - `journal_entry_id` (on `sales` or `cash_shifts`);
    - `ar_document_id`, `sales_order_id`, `order_id`, `custom_fields` (on `sales`);
    - `financial_account_id` (on `cash_movements` or `payment_methods`);
    - `gateway`, `settings` (on `payment_methods`);
    - `gateway_transaction_id`, `payment_id` (on `sale_tenders`);
    - `batch_id`, `serial_id`, `kitchen_station_id` (on `sale_items`).

    Step 6's column-list equality is the runtime half. **Red-first:** a scratch migration under a fixture directory declaring one of them is reported. Delete it.
  - **Gate: `composer check` (end of chunk A).**

### Chunk B: building blocks (bottom-up)

- [x] **Step 9: `Support`, `Money`, `SaleRollup`, `TenderRules`, `ShiftTotals`.** All pure, or reading only this package's tables.
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
- [x] **Step 10: approvals.** `RequestApproval`, `DecideApproval`, `ApprovalGate`, and `ListApprovalRequests`.
  - **AC-23:**
    - only the four types are accepted; `credit_limit`, `negative_stock` and `custom` are refused `validation`;
    - `decision_method` is always `pin_local`;
    - a wrong PIN is `pin_invalid` with **no** change to the approval row, while access's counter **did** increment (F-5b);
    - a decider without `sales.approvals.decide` at the branch is `not_authorised`. A decider holding it at another branch only is `not_authorised`; one holding it at the null scope (company-wide, the owner role's shape) is allowed. These are the first real branch-scoped keys (R-25);
    - ids are folded to upper case before the self-approval comparison: a lower-case `requested_by` spelling of the decider's own id is still `self_approval_not_allowed` (R-38);
    - **PIN sink (R-24):** after a decide with a sentinel PIN, the sentinel appears in no row of any tenant table, in no audit `before` or `after`, in no log line and in no result.
  - **D-15:**
    - the decider equal to `requested_by` is refused;
    - for a `sale` reference, the decider equal to the sale's `employee_id` is refused;
    - a goodwill refund has no sale, so only the `requested_by` check applies.
  - **AC-24:**
    - deciding a row that is no longer `pending` (approved, rejected or expired) is `approval_not_pending` (F-23's reading);
    - consuming twice is `approval_already_used`;
    - consuming a `rejected` row is `approval_not_approved`;
    - a type, branch or reference mismatch is `approval_mismatch`.
  - **Mutation M3:** call `PinVerifier` inside the transaction. The counter assertion goes red.
  - The list is paginated (D-26), and the cursor reaches row 51 of 51 at page size 50.
  - *(AC-23, AC-24; D-12 to D-15; F-5, F-21.)*
- [x] **Step 11: payment methods** (three slices).
  - Code blank or duplicate (also by case, through a `lower(code)` pre-check with the unique index as the caught backstop; pricing's `Uniqueness` precedent, R-38); bad `type` (exact lower-case values only); the boolean defaults; `surcharge_percent` above `99999.9999` (`DECIMAL(9,4)`), negative, or with a fifth decimal, is `validation`; `financial_account_id`, `gateway` and `settings` refused as **unknown** (F-8).
  - **Lock order:** `UpdatePaymentMethod` and `DeletePaymentMethod` take the method row `lockForUpdate()` as their first statement, then count tenders on open sales with a plain read (Ruling 7, row 4). An update or delete of a soft-deleted method is `not_found`.
  - **The guard:** deactivate or delete is refused `in_use`, naming the count, while a `draft`, `held` or `pending_payment` sale has a tender on the method. It is accepted once that sale completes.
  - The soft delete leaves the row in place.
  - *(AC-16.)*
- [x] **Step 12: `OpenCashShift`, `RecordCashMovement`, `ListCashShifts`, `ListCashMovements`.**
  - **AC-32:**
    - `shift_already_open`;
    - a paused or inactive terminal (`TerminalAuthority`) is `not_found`;
    - the number comes from `DocumentNumbers` (`SH000001`).
  - **AC-33:**
    - each of the seven types is accepted, and a bad type is refused;
    - a closed shift is `shift_not_open`;
    - `financial_account_id` is refused as unknown.
  - **AC-37:** a replayed movement id with the same payload is a no-op (`replayed = true`, no second audit row); a different payload is `replay_conflict`. The `amount` comparison is numeric: `"10"` replays `"10.0000"` (R-8).
  - **Lock order:** `RecordCashMovement` takes the shift `sharedLock()` by id as its first statement and re-checks `status = open` (Ruling 7, row 1), so it serialises with `CloseCashShift`. `OpenCashShift` allocates its number last (row 7). A unique-key violation on `open_terminal_key` is caught and refused `shift_already_open`.
  - **Inputs (R-39):** `opening_cash` is non-negative; a movement's `amount` is strictly positive; both have at most 14 integer and 4 fraction digits and are strings.
  - Both lists are paginated. `ListCashMovements` orders by `(created_at, id)`, because the ids are caller-supplied (R-17).
  - **Gate: `composer check` (end of chunk B).**
  - *(AC-32, AC-33, AC-37 (movement); D-22.)*
  - **As built (chunk B), the notes the next chunks need:**
    - **Deadlock handling for `DocumentNumbers` (the NOT VERIFIED item from chunk A).** `OpenCashShift` runs its whole transaction as `transaction(..., 2)`: the engine's deadlock error rolls the transaction back and runs it once more, so no number, shift or audit row of the first attempt survives; a second deadlock reaches the caller as the engine's `QueryException`. It retries only as the outermost transaction. Proved against a numbering fake that throws the deadlock `QueryException` (retry once, give up after two, no retry of a non-deadlock error). **Every later slice that calls `DocumentNumbers::next()` (`CompleteSale`, `RefundSale`) must do the same.** Still not measured on a real engine.
    - **`OpenCashShift` locks the terminal row first** (`terminals`, by primary key, `FOR UPDATE`). Ruling 7's table has no row for it, because the shift it creates is new; the terminal is the one row that serialises two openings for one terminal, and it keeps the "locking read first" rule. `number_sequences` is last.
    - **`DecideApproval` takes `(fields, ?ip)`, not `(fields, ?actorId)`.** The decider is whoever the PIN proves, so there is no actor to pass; the PIN field is sensitive in traces and out of `__debugInfo()`.
    - **`BusinessClock` is not built yet.** Nothing in steps 9 to 12 reads a business date or converts a `DATETIME(3)` to an instant; it arrives with `CreateSale` (step 13), its first caller.
    - **`SalesPermissions` declares only `sales.approvals.decide`.** `sales.shifts.reconcile` arrives with `ReconcileCashShift` (chunk D), the slice that checks it.
    - **Audit module `sales` is declared**, and `AuditCatalogueTest`'s two module lists now include it.
    - **`ApprovalGate::consume()` returns a `ConsumedApproval`** (`approvalId`, `decidedBy`, `requestedBy`), so a price override can set `price_overridden_by` to the approver (AC-7).
    - **`TenderRules::decide(method, offered, reference, balance)`** takes what was put on the counter (the tendered figure for a method that gives change, the amount for any other) and refuses in this order: `unsupported_method_type`, `method_inactive`, `reference_required`, nothing owing (`exceeds_balance`), over the balance (`exceeds_balance`, or change when the method gives it), `partial_not_allowed`.

### Chunk C: the cart, tenders, completion, hold

*Revision 4: chunks C to E build from spec revision 5 (AC-1 to AC-58, AC-23a, AC-12A, AC-14A, AC-24A, AC-47A; D-1 to D-74). Q-3 to Q-11 are ruled (D-57 to D-65), each at this plan's default, so the defaults below are now the rule. Q-1 (D-55) and Q-2 (D-56) are still open with their built defaults. Q-2 must be ruled before chunk C is accepted, and Q-1 before chunk D.*

- [x] **Step 13: `CreateSale`.**
  - **Creates `Support/BusinessClock.php` and `Sales/SaleType.php`.** Neither exists yet (chunk B "As built"). `BusinessClock` has three jobs:
    - the UTC instant in `Shape::instant` form;
    - the branch-local `Y-m-d` from `branches.timezone`;
    - the `DATETIME(3)`-to-instant conversion that step 20 uses (R-10).

    It gets a pure test file, `tests/Support/BusinessClockTest.php`. One row is a branch in a zone far from UTC, at an instant where the local date differs from the UTC date.
  - Writes `draft`, with branch and location from the terminal (never client-supplied), `employee_id` = the actor, `business_date` from `BusinessClock` in the branch timezone, `business_date_source = server`, `currency` from the branch, and `shift_id` = the terminal's open shift.
  - Refusals:
    - `no_open_shift`;
    - a customer unknown, soft-deleted or merged is `not_found`;
    - the snapshots are copied;
    - `business_date`, `bill_discount_id` and `bill_discount_value` (F-1), and every `[SYNC]` field, are refused as unknown fields (R-39);
    - a null actor is `validation`, because `sales.employee_id` is required.
  - **Lock order (R-5):**
    - the terminal's open shift id is read before the transaction;
    - the first statement locks that shift `sharedLock()` **by id** and re-checks `status = open` and the terminal. A shift that closed in between is `no_open_shift`;
    - the customer is read with `Lookup::live(…, lock: true)` (Ruling 7, row 6). As built, `Lookup::live()` takes `bool|string $lock`, where `Lookup::SHARED` is the shared lock and `true` is `FOR UPDATE`.
  - **Race row:** a close that commits between the pre-read and the lock leaves no draft on the closed shift.
  - *(AC-1; D-22, D-23.)*
- [x] **Step 14: `AddSaleLine`, `UpdateSaleLine`, `RemoveSaleLine`, `ListSaleLines`**, against the **real** `UnitResolver` and `PricingEngine`, seeded through T-029's seeds.
  - **Creates `Sales/LineWriter.php` and `Sales/SaleLocks.php`.** Neither exists yet. The header rollup uses the built `SaleRollup::of(connection, saleId): SaleTotals` and `SaleTotals::toColumns()`, which already refuse `out_of_range` (AC-51).
  - **AC-2:**
    - 1 case of 24 gives `unit_factor 24.000000`, base 24, `unit_code` copied;
    - renaming the product afterwards leaves the line unchanged.
  - **AC-3:** a cross-dimension unit is `unit_unresolvable`, naming both units.
  - **AC-4:**
    - each stored column equals the corresponding `PricedLine` field byte for byte (fixture `line/case-of-24` replayed end to end);
    - `tax_amount` equals `PricedLine::$tax->total`, and `tax_breakdown` equals `$tax->toArray()`. There is no `taxAmount` field, and `PricedLine::toArray()` is not the column map (R-11);
    - `LineQuote` receives `at` in `Shape::instant` form, `documentDate` = `business_date`, `currency`, `customerId`, `customerGroupId` (= `customers.group_id`, read as a fact) and `branchId` (R-10). A malformed product, unit or discount id from the caller is `validation`, never an `InvalidArgumentException` (R-10).
  - **AC-5:**
    - **no price gives `unresolvable_price` through `FakePricingEngine` only** (R-3). Against the real engine, an unpriced product quotes `0.0000` with source step 6 (FP-440), and the row pins that, pending Q-2;
    - an engine `not_applicable` discount surfaces as `not_applicable` (the fake engine covers the remaining reasons by dataset), with the `PricingRefusal` carried as the cause (Ruling 8 amendment).
  - **Quantity (R-14, AC-52):** `0`, `-1` and `"-0.5"` are `validation` before the resolver is called; the resolver itself would accept them.
  - **Product facts (AC-47, D-57; ruled, no longer a default):** a product that is `is_active = false`, `is_sellable = false` or of `type = variant_parent` is refused `product_not_sellable` on `AddSaleLine` and `UpdateSaleLine`. The refusal names the product and the fact, and nothing is written.
    - **Scope (AC-47A, D-73).** The check is one private step of these two slices, not a shared helper, and `RefundSale` never calls it. A goodwill refund line is exempt, and so is a referenced one, because AC-47 names only `AddSaleLine` and `UpdateSaleLine` (lead question 1). Step 20 has the rows that prove the exemption.
    - **The engine still decides on deletion.** A soft-deleted product is refused `unknown_product` by `quoteLine()` (AC-53). That holds for a goodwill refund too, until Q-1 is ruled. An inactive product that still exists is quoted normally.
    - **Test rows:** one per fact, on both `AddSaleLine` and `UpdateSaleLine`. Plus one row showing that the same inactive product **is** accepted as a goodwill refund line (step 20's row, referenced here so the two stay paired).
  - **AC-6 and F-10:**
    - `pending_payment` is `sale_not_editable`;
    - `held` from `draft` is accepted;
    - `held` from `pending_payment` is refused.
  - **AC-8:** the header equals `SaleRollup` after every add, update and remove.
  - **AC-9 and AC-10:**
    - `price_from_label = false` and `modifiers = null` on every row;
    - `unit_cost`, `line_cost` and `cost_total` null, and `stock_applied` false;
    - those fields are refused if supplied.
  - Optional `employee_id` (line salesperson) is validated via `Contracts\Access\EmployeeDirectory::isLive()` (F-24).
  - **Mutation M4:** compute `base_quantity` locally. The AC-2 row still passes, but step 23's scan goes red, which is the point of that scan.
  - *(AC-2 to AC-6, AC-8 to AC-10, AC-47, AC-51's rollup half, AC-52; D-57, D-70.)*
- [x] **Step 15: price override and the discount approval.** *(AC-7, AC-23a; D-37, D-40, D-47.)*
  - **AC-7:**
    - an override without an approval is `approval_required`;
    - with a matching approval, the stored `unit_price` is the override, `subtotal` = override × quantity, tax from `computeTax(new TaxQuote($taxGroupId, $subtotal, $snapshot->baseQuantity, $businessDate, TaxContext::Sale))` (R-12), `discount_amount = 0.0000`, `line_total = net + total` (one exclusive and one inclusive row, and one row on a group with a `fixed` member, so the base quantity is shown to matter);
    - the override `unit_price` is a string with at most 14 integer and 4 fraction digits, never negative. A product `round(unitPrice × quantity, 4)` that does not fit `(18,4)` is `out_of_range` (R-39);
    - the payload match is numeric and id-folded: a payload `unit_price` of `"7.5"` matches a write of `"7.5000"`, and a lower-case `product_id` in the payload matches (R-8, R-38);
    - `price_overridden_by` = the **decider**;
    - the approval is consumed, and reusing it is `approval_already_used`.
  - **F-16:**
    - `allow_price_override = false` is `price_override_not_allowed`;
    - an override plus a `discount_id` is `discount_with_override`;
    - a payload `unit_price` different from the write is `approval_mismatch`.
  - **F-12:** a line discount with `requires_approval = true` is `approval_required` without a matching consumed approval and accepted with one; a discount without the flag needs none.
  - **Re-pricing a line that already carries an approval (AC-55, D-59):** an `UpdateSaleLine` that keeps the same `discount_id`, or the same override `{product_id, unit_id, unit_price}`, is accepted when a matching approval was **already consumed by this sale**. A different discount or price needs a new approval. One row each way.
  - **AC-24A, the branch:** the gate is called with the **sale's** `branch_id`, never a caller-supplied one.
    - An override approval seeded raw at another branch (`RequestApproval` would refuse it, so this is a defence-in-depth row) is `approval_mismatch`, and its `consumed_at` stays null.
    - The same approval at the sale's branch is consumed.
  - *(AC-7, AC-23a, AC-24A, AC-55; D-13, D-15, D-59, D-72.)*
- [x] **Step 16: `AddTender`, `RemoveTender`.** *(AC-11 to AC-13, AC-12A, AC-37's tender half, AC-56; D-29, D-41, D-60, D-71; F-22's reading.)*
  - **16a. Edit the built `TenderRules` first (AC-12A, D-71).** This changes an existing file, `packages/sales/src/Tenders/TenderRules.php`, and its test, `tests/Tenders/TenderRulesTest.php`. It is done red-first.
    - **What is built** (read at `e4ed355`): `decide(object $method, string $offered, ?string $reference, string $balance): TenderOutcome`. It refuses in this order:
      1. `validation` when `offered` is not positive;
      2. `unsupported_method_type` when the type is unknown or is not `MethodType::isTenderable()`, which covers `loyalty` and `voucher`;
      3. `method_inactive`;
      4. `reference_required` when the reference is missing or blank;
      5. `exceeds_balance` when nothing is owing;
      6. `exceeds_balance` when `offered` is over the balance, unless `allows_change`, in which case the amount is the balance and the rest is change;
      7. `partial_not_allowed`.

      The method object's documented shape has **no `allows_refund`**, and nothing reads it.
    - **The edit:** add a trailing parameter, `bool $refund = false`, and an `allows_refund` key to the method shape, which is read only when `$refund` is true. The new cause goes into the **existing** check 2: a refund onto a method whose `allows_refund` is false is `unsupported_method_type`, with a message naming the method as not refundable. It never becomes a new code. Placing it at check 2 means a method that is both inactive and non-refundable is refused `unsupported_method_type` on a refund. The docblock's order list is updated to match.
    - **Refund semantics in the same edit.** With `$refund = true`, `offered` and `balance` are **magnitudes**: what the drawer pays out, and what is still owed back. Both are positive, and the caller negates the result (step 20). A payout gives no change, so on a refund check 6 is `exceeds_balance` whatever `allows_change` says, and `tendered` is null. That is this plan's reading, not a ruling (lead question 2).
    - **`TenderRulesTest` rows, red first:**
      - a refund onto `allows_refund = false` is `unsupported_method_type`;
      - the same method on an ordinary tender (`$refund = false`) is accepted. The test passes `allows_refund = 0`, so a check that ignores the flag goes red (AC-12A's second clause);
      - a refund onto `allows_refund = true` with an acceptable type is accepted;
      - a refund onto an inactive, non-refundable method is `unsupported_method_type`, which pins the order;
      - a cash refund with `offered` over the balance is `exceeds_balance`, not change.

      The existing `tenderMethod()` helper keeps its defaults. The refund rows pass `allows_refund` explicitly.
    - **Remove `SalesRefusal::RefundNotAllowed`** and edit `tests/Registry/SalesRegistryTest.php`: delete `refund_not_allowed` from the pinned list and change `toHaveCount(43)` to 42. Run it red first, by removing the case before the test edit.
    - **Mutation M8:** drop the `$refund &&` guard, so that `allows_refund` is read on every tender. The ordinary-tender row goes red.
  - **AddTender's input follows the built `offered` shape** (chunk B "As built"). The caller sends one money field, `offered`: the tendered figure for a method that gives change, and the amount for any other. `amount`, `tendered` and `change_given` are **computed** and are refused as unknown fields if supplied. The stored row is the `TenderOutcome`. `AddTender` calls `decide()` with `$refund = false` and the sale's balance (`total − Σ amount`) as a `Money` difference.
  - **F-22, implicit recall:** a tender on a sale held from `pending_payment` at terminal B, which has an open shift:
    - writes one `recall` row in `sale_holds`;
    - moves `terminal_id` and `shift_id` to B's;
    - leaves the sale `pending_payment` with the tender recorded.

    The same call at a terminal without an open shift is `no_open_shift`, and nothing is written. A sale held from `draft` is `sale_not_editable`.
  - **AC-11:**
    - the method snapshots are copied;
    - the first tender moves `draft` to `pending_payment`;
    - an inactive method is `method_inactive`.
  - **AC-12:**
    - split cash plus card is accepted;
    - a credit tender with no customer is `customer_required`;
    - **no `customer_ledger` row is written by `AddTender`** (F-3).
  - **Idempotency:**
    - a replayed tender id with the same payload is a no-op; a different payload is `replay_conflict`;
    - the replay check runs **right after the sale lock and before any state check**, so a replay that arrives after the sale has completed still returns the original row, and a replay of an implicit-recall tender writes no second `recall` row;
    - an id already used by a tender **of another sale** is `replay_conflict`;
    - amounts are compared numerically (R-8);
    - **what is compared is the request, not the outcome.** The outcome depends on the balance, which the first tender changed. The stored request is `payment_method_id`, `reference`, and `offered = tendered ?? amount`. That works because the built `TenderRules` records `tendered` for every change-giving tender and null for any other, and a non-change tender's amount always equals what was offered. One row: a cash tender of 50 against 45 (amount 45, change 5) replayed with `offered = "50"` is a no-op, and with `"45"` it is `replay_conflict`.
  - **Replay after removal (AC-56, D-60):** a replay of an `AddTender` whose tender was since removed by `RemoveTender` adds it again while the sale is still open. A row pins this as documented behaviour.
  - **Lock order (R-5):**
    - sale `lockForUpdate()` first, then the payment method `sharedLock()`. An inactive method is `method_inactive`; a soft-deleted one is `not_found`;
    - with an implicit recall: the tendering terminal's open shift first (`sharedLock()`, by id, re-checked), then the sale.
  - **Inputs (R-39):** `offered` is a string, strictly positive (`Fields::positiveMoney`), with at most 14 integer and 4 fraction digits. Change is given only when the method's `allows_change` is true, which is `TenderRules`' rule, not the slice's.
  - **AC-12A on `AddTender`:** one row. A method with `allows_refund = false` takes an ordinary sale tender normally.
  - **AC-13:**
    - removal deletes the row;
    - removing the last tender moves the sale back to `draft`;
    - on `completed` it is `tender_not_removable`.
  - **Concurrency shape:** the `sales` row is read `for update` before the balance check (SQL pinned).
  - *(AC-11 to AC-13.)*
- [x] **Step 17: `CompleteSale`.**
  - **Creates `Tenders/CreditPosting.php`**, which is not built yet. It builds the `CreditLedgerEntry` for each credit tender, mirror and refund tender (F-2 to F-4, R-20), and steps 19 and 20 reuse it.
  - **AC-14:**
    - a short balance is `balance_due`, naming the remainder;
    - an exact balance sets `completed`, `completed_at`, `paid_total`, `change_due`, `balance_due = 0` and `number = S000001`;
    - no lines is `empty_sale`. It is checked before the balance, so a draft with no lines is `empty_sale` and never `balance_due`;
    - a `draft` with a positive total and no tenders is `balance_due`, naming the total. The status is one Ruling 4 allows, so it is not `invalid_transition`, and the empty sum simply falls short.
  - **AC-14A, zero-total completion (D-74): the ordinary path, no branch of its own.** One dataset row is a `draft` with one line fully discounted by a discount with `usage_limit = 5`, so `total = 0.0000`, and no tenders. In one transaction it:
    - allocates `number` through `DocumentNumbers::next('sale', $branchId)`. That is the same sequence and prefix as a paid sale: a paid sale completed after it gets `S000002`;
    - consumes `DiscountUsage` once for that discount, so `used_count` goes from 0 to 1;
    - makes no ledger post and writes no `customer_ledger` row, because there are no tender rows;
    - writes exactly **one** sales audit row (`completed`) and no customers audit row;
    - sets `status = completed`, `completed_at`, `paid_total = 0.0000`, `change_due = 0.0000` and `balance_due = 0.0000`;
    - leaves `stock_applied = false` and writes no `sale_tenders` row.

    The row compares the whole tables before and after, so a special case that skips numbering or usage goes red.
    - **Second row:** the same zero-total sale reaching `pending_payment` is impossible, because the built `TenderRules` refuses any tender on a sale with nothing owing (`exceeds_balance`). The row pins that, so the only zero-total completion is from `draft`.
    - **Mutation M9:** short-circuit a zero total, returning `completed` before numbering and usage. The AC-14A row goes red on `number` and `used_count`.
  - **F-2 and F-3:**
    - two credit tenders write **two** `credit_sale` rows keyed `('sale_tender', <tender id>)`;
    - `credit_checked = true`;
    - an over-limit customer is `credit_refused` carrying `over_limit`, with no number consumed, no row changed and no ledger row (one transaction);
    - a customer with no currency, or in another currency, is `credit_refused` carrying `currency_required` or `currency_mismatch` (R-20);
    - the ledger rows carry positive canonical debits equal to each tender's amount, the sale's currency and branch, and the cashier as `employeeId` (R-20);
    - **R-4:** a `customer_ledger` row pre-seeded under `('sale_tender', <tender id>, credit_sale)` with the same customer and amount makes the writer report `replayed = true`. `CompleteSale` refuses `credit_refused` carrying `replay_conflict`, and nothing is written: no status change, no number, no usage. The pre-seeded row is still the only ledger row.
  - **F-12:** `DiscountUsage::consume()` runs once per distinct discount, **in ascending discount-id order** (R-5); `usage_limit_reached` refuses the whole completion.
  - **Lock order (R-5):** sale → discounts (ascending) → customers (inside the ledger) → `number_sequences` (`DocumentNumbers`, last) → the audit row. The SQL shape pins the order of the locking statements.
  - **Deadlock retry, exactly as the built `OpenCashShift` does it.** The whole write runs as `$connection->transaction(fn, self::ATTEMPTS)` with `private const ATTEMPTS = 2`.
    - A deadlock rolls everything back and runs the closure once more: the number, the usage increment, the ledger rows with their customers audit rows, the status change and the sales audit row. A second deadlock reaches the caller as the engine's `QueryException`.
    - It retries only as the outermost transaction (Laravel's own rule). The handler's docblock says so, in `OpenCashShiftHandler`'s wording.
    - The engine and resolver calls stay **before** the transaction (Ruling 7 item 1), so the retry re-runs only the locked body. Nothing the closure computes may be captured by reference across attempts.
    - **Shared helpers first.** `numberingWrapper()` and `deadlock()` are file-level functions in `tests/Features/OpenCashShift/OpenCashShiftTest.php`, and a second test file declaring them would be a fatal redeclaration in one Pest run. So this step moves them into `tests/Support/DeadlockingNumbers.php`, as a class with static `wrap(Closure): object` and `deadlock(): QueryException`, and edits `OpenCashShiftTest` to call it. That file's three deadlock rows must stay green with no other change, and that is the check that the move is behaviour-neutral.
    - **The three rows**, each marked NOT VERIFIED on a real engine as `OpenCashShiftTest`'s are:
      - (a) numbering deadlocks on call 1 and succeeds on call 2. The sale is `S000001`, `next_number` is 2, `used_count` was incremented **once**, there is one `credit_sale` row per credit tender, one customers audit row per posting and one sales audit row;
      - (b) numbering deadlocks on both calls. It surfaces `QueryException` ('Deadlock found'), the wrapper's call count is 2, and **nothing is written**: the status is still `pending_payment`, there is no number, `used_count` and `customer_ledger` are unchanged, and there is no audit row;
      - (c) a non-deadlock `QueryException` is not retried (call count 1).
    - **Mutation M10:** drop `ATTEMPTS`. Row (a) goes red.
  - **Audit (R-20):** one sales audit row, plus one customers audit row per credit tender (written by the ledger writer itself).
  - **The way out of a refused completion (risk 12):** a sale refused `usage_limit_reached` at completion is unstuck by removing its tenders (back to `draft`), removing or changing the line, tendering again and completing. One row walks the whole path.
  - **Mutation M7:** ignore `replayed`. The R-4 row goes red.
  - **AC-15:** no `stock_*` table is touched (asserted by query count on stock tables, which do not exist, plus a source scan in step 23); `stock_applied` stays false.
  - **Mutation M5:** post the ledger before the balance check, with the balance short. A ledger row survives, and the "nothing written" assertion goes red.
  - **AC-50:** a `yearly` `sale` sequence, or one whose prefix makes the number longer than 30 characters, is `numbering_refused`, carrying the cause, and nothing is written.
  - **AC-58:** a discount deactivated between the quote and the completion is still consumed, and the completion succeeds.
  - *(AC-14, AC-14A, AC-15, AC-48, AC-49, AC-50, AC-51, AC-58; D-29, D-31, D-37, D-42, D-65, D-66, D-68, D-74.)*
- [x] **Step 18: `HoldSale`, `RecallSale`, `ListSales`, `GetSale`.**
  - **AC-17:** `draft` and `pending_payment` can be held, and one `sale_holds` row records `from_status`; any other status is refused `invalid_transition`, naming it (R-40). `RecallSale` of a sale that is not `held`, and `CompleteSale` from any status Ruling 4 does not list, are refused the same way.
  - **Lock order (R-5):** `RecallSale` takes the recalling terminal's open shift (`sharedLock()`, by id, re-checked) first, then the sale.
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
  - *(AC-17 to AC-20, AC-51's transition half; D-24, D-25.)*

### Chunk D: corrections and shift close

- [x] **Step 19: `VoidSale`.** *(AC-21, AC-22; D-29, D-39, D-47.)*
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
    - no stock or GL effect;
    - each `credit_note` carries a positive credit equal to |the mirror's amount| (R-20). A `replayed = true` posting is refused `credit_refused` carrying `replay_conflict`, with nothing written (R-4);
    - a customer merged since the sale is `credit_refused` carrying `customer_not_live` (risk 7).
  - **Lock order (R-5):** the completed sale's own `shift_id` is read before the transaction; then the shift `sharedLock()` by id, then the sale `lockForUpdate()`, which re-checks that `shift_id` is unchanged and the shift is `open`; then the approval; then the customers. **Race row:** a `CloseCashShift` that commits between the pre-read and the lock makes the void `shift_closed`, and no mirror lands on the closed shift.
  - **AC-24A, the branch (D-72):** the gate is called with the **sale's** `branch_id`.
    - A `void` approval for this sale, seeded raw at another branch, is `approval_mismatch` (a defence-in-depth row, since `RequestApproval` refuses such a request). The sale stays `completed`, no mirror is written, and `consumed_at` stays null.
    - The same approval at the sale's branch is consumed.
    - In the same step, `ApprovalBinding`'s docblock table gains its `branch_id` column (a comment-only edit, matching the Data section's table).
  - **Ledger post:** built through step 17's `CreditPosting`. There is no number allocation, so **no** deadlock retry is needed here.
  - **Mutation M6:** edit the original tender to a zero amount instead of mirroring. The byte-identity assertion goes red.
  - *(AC-21, AC-22, AC-24A, AC-48; D-14 to D-16, D-66, D-72.)*
- [x] **Step 20: `RefundSale`.** *(AC-25 to AC-31, AC-12A, AC-24A, AC-47A, AC-48, AC-50, AC-53, AC-54, AC-57; D-32, D-38, D-43, D-47, D-55, D-58, D-63, D-64, D-71 to D-73.)*
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
  - **AC-24A, the branch (D-72):** the gate is called with the **refund's** branch, which is the refunding terminal's.
    - A goodwill `refund` approval requested and approved at branch A, consumed by a goodwill `RefundSale` at a terminal of branch B, is `approval_mismatch`: no refund row, no number consumed, and `consumed_at` null. This row goes through the real `RequestApproval` and `DecideApproval`, because a goodwill request has no sale whose branch `RequestApproval` could check.
    - The same refund at branch A consumes it.
    - A referenced refund at another branch than the original sale's is refused `not_refundable` (AC-54) before the gate is reached. One row shows that the approval is untouched.
  - **AC-30, F-14 and AC-12A, tender rules:**
    - tenders are negative and Σ = −total, otherwise `balance_due`;
    - each refund tender goes through the built `TenderRules::decide()` as edited in step 16a, with `$refund = true`, the method row read under `sharedLock()` (including `allows_refund`), `offered` = the payout magnitude, and `balance` = what is still owed back, a magnitude computed by `Money` from the refund total and the tenders decided so far. The stored `amount` is `Money::negate($outcome->amount)`, `tendered` is null and `change_given` is `0.0000`;
    - **a refund tender onto a method with `allows_refund = false` is `unsupported_method_type`, and nothing is written** (AC-12A). There is no refund row, no `refunded_quantity` change, no number, and the approval is unconsumed;
    - the same method with `allows_refund = true` is accepted;
    - `loyalty` and `voucher` are `unsupported_method_type` as on a sale (AC-12);
    - a zero-total refund (a fully discounted line refunded) takes no tender and completes with Σ = 0 = −0, as AC-14A does for a sale;
    - a credit refund method writes a `refund` ledger row (credit side) keyed on the refund tender.
  - **AC-47A, the goodwill sellability exemption (D-73):**
    - a goodwill refund line for a product that is `is_active = false`, or `is_sellable = false`, is **accepted**: `product_not_sellable` is never raised, and the engine quotes it normally;
    - a referenced refund line for a product deactivated since the sale is accepted too (lead question 1);
    - a goodwill line for a **soft-deleted** product is still refused `unknown_product` by the engine (AC-53, R-1(d)). **That is the built default until owner question Q-1 is ruled.** The row is written so that a Q-1 ruling for option (b) flips it, and it is not deleted;
    - **Mutation M11:** call step 14's sellability check on refund lines. The inactive-goodwill row goes red.
  - **AC-31:** no stock or GL effect.
  - **R-1, the engine's live-state checks (default pending Q-1):** one dataset row each. The refund is refused under the engine's own reason, and nothing is written:
    - the original line's discount has since reached its usage limit, including the case where **this sale's own consumption** reached it (`usage_limit = 1`): `usage_limit_reached`;
    - the discount has been deactivated (`inactive`) or soft-deleted (`unknown_discount`);
    - the product's `allow_discount` has been turned off: `discount_not_allowed`;
    - the product has been soft-deleted: `unknown_product`, for a referenced line **and** for a goodwill line.

    The rows are written so that Q-1's ruling flips them, rather than having to be discovered.
  - **R-8:** the original line's stored `unit_factor`, which the stand-in reads back as a number, is canonicalised before `snapshotInherited()`. The refund of a 24-factor line works on SQLite.
  - **R-10:** `at` is the original `completed_at`, converted from `DATETIME(3)` to `YYYY-MM-DDTHH:MM:SS.mmmZ`.
  - **The refund's customer and branch (AC-54, D-58; ruled):**
    - a referenced refund copies the original sale's `customer_id` and its snapshots;
    - a goodwill refund takes an optional `customer_id`, with AC-1's liveness check;
    - a `credit` refund tender with no customer is `customer_required`;
    - a refund at a terminal of another branch than the original sale's is `not_refundable`.
  - **Ledger:** a `credit` refund tender posts a `refund` entry with a positive credit equal to |the tender's amount|, keyed `('sale_tender', <refund tender id>, refund)`. A replayed posting is refused (R-4). Refund tender ids are generated by the server.
  - **Replay safety:** a `RefundSale` retried after success is refused `approval_already_used`, because the first call consumed the approval. That is the refund's idempotency guard, and a row pins it.
  - **Lock order (R-5):** the refunding terminal's open shift (`sharedLock()`, by id) → the original sale (`lockForUpdate()`; every refund of a sale takes it, so `refunded_quantity` is read fresh after it) → the approval → the payment methods (`sharedLock()`) → the customers → `number_sequences` last.
  - **Deadlock retry, as `OpenCashShift` and step 17:** the whole write runs as `transaction(fn, self::ATTEMPTS)` with `ATTEMPTS = 2`, retrying only as the outermost transaction.
    - The engine quotes and `snapshotInherited()`/`snapshot()` stay **before** the transaction. The retry re-runs only the locked body, which re-reads `refunded_quantity` under the sale lock on each attempt.
    - The server-generated ids (the refund sale, its lines and its tenders) are generated **inside** the closure, so nothing from a rolled-back attempt is reused.
    - **The three rows** use step 17's `tests/Support/DeadlockingNumbers`, wrapping the `refund` allocation, and are marked NOT VERIFIED on a real engine:
      - (a) a deadlock on call 1 succeeds on call 2: `R000001`, one refund row, `refunded_quantity` incremented **once**, the approval consumed once, one `refund` ledger row per credit refund tender, and one sales audit row;
      - (b) two deadlocks surface `QueryException`, and nothing is written: no refund row, `refunded_quantity` and the original status unchanged, `consumed_at` null, and no ledger or audit row;
      - (c) a non-deadlock error is not retried.
  - **AC-50:** a refused `refund` sequence is `numbering_refused`, carrying the cause, and nothing is written, the approval included.
  - **AC-57:** the Q-9 and Q-10 residual gaps (promotion ended, customer group changed) each have one dataset row showing that the refund differs from the charge, as accepted behaviour.
  - *(AC-25 to AC-31; D-17 to D-19.)*
- [x] **Step 21: `CloseCashShift`, `ReconcileCashShift`.**
  - **AC-34:**
    - `counted_cash` is required;
    - `expected_cash` = opening + cash tenders (sales and refunds, signed) ± movements;
    - `variance = counted − expected` (one short, one over, one exact);
    - `closed`, `closed_at`, `closed_by`;
    - not `open` is `shift_not_open`;
    - an open `pending_payment` sale on the shift is `shift_has_open_sales` (F-15).
  - **AC-35:** `totals_by_method`, `sales_count`, `refunds_count`, `gross`, `discounts`, `tax` and `net` over a seeded mixed shift (two sales, one voided, one refund), with exact strings.
  - **Permission key (chunk B "As built"):** `SalesPermissions` declares only `sales.approvals.decide` today. This step adds `sales.shifts.reconcile` (branch-scoped) and edits `tests/Registry/SalesRegistryTest.php`, whose `toHaveCount(1)` becomes 2, with a second key assertion and the sync row extended to both keys (D-69).
  - **AC-36:**
    - `closed` → `reconciled` with `reconciled_by`;
    - a different supervisor than the closer is accepted;
    - without `sales.shifts.reconcile`, `not_authorised`;
    - held at another branch only, `not_authorised`; held at the null scope (company-wide), allowed (D-69, the same pair as step 10's);
    - an `open` shift is `shift_not_open`.
  - **AC-37:** close and reconcile replayed with the same payload are no-ops; a close replayed with a different `counted_cash` is `replay_conflict`. `counted_cash` is compared numerically (R-8).
  - **Lock order (R-5):** both take the shift `lockForUpdate()` by id as their first statement; `CloseCashShift` then reads sales, tenders and movements with plain reads, which are fresh because nothing was read before the lock. **Race rows:** a `CreateSale`, a `RecordCashMovement` or a completed-sale `VoidSale` that commits between the pre-read and its own shift lock is either counted by the close or refused, never silently attached to a closed shift.
  - **Gate: `composer check` (end of chunk D).**
  - **As built (chunk D), the notes chunk E needs:**
    - **`VoidSale`** pre-reads the sale plainly (to decide whether a shift lock is needed), then in the transaction locks the sale's own shift shared (completed sales only), the sale `FOR UPDATE`, the approval, then the customers. `sale_not_voidable` is a refund document or `refunded`/`partially_refunded`; `voided` and `failed` are `invalid_transition`. The sale's rolled-up figures are left as they were; the originals are untouched and each gets one mirror (`tendered` null, `change_given` 0.0000).
    - **`RefundSale` input shape:** `terminal_id`, optional `reference_sale_id`, `customer_id` (goodwill only; refused on a referenced refund), `lines` (referenced: `refund_of_item_id`, `unit_id`, `quantity`; goodwill: `product_id`, `unit_id`, `quantity`; a line named twice is `validation`), `tenders` (`payment_method_id`, `offered`, `reference`), `approval_id`. The approval is consumed **before** `fully_refunded`/`over_refund` are read, so a retry after success is always `approval_already_used`; `not_refundable` and the status `refunded` (`fully_refunded`) come before the gate. The refund document's header money is unsigned (`paid_total = total`, `balance_due = 0`); its tenders are negative. It is `completed` in the one call. `LineWriter::quoteSnapshot()` is the one new shared method.
    - **A price-list tier's `min_qty` is in base units**, not line units (found while writing the AC-27 row).
    - **`CloseCashShift`** takes the shift `FOR UPDATE` as its first statement; its figures come from this shift's `completed`/`refunded`/`partially_refunded` documents. `totals_by_method` is keyed by `method_code`, sorted, `{}` when empty. **`ReconcileCashShift`** checks the permission (branch read from the shift row) before it looks at the status, so an unauthorised caller learns nothing about the shift. `SalesPermissions::SHIFTS_RECONCILE` is the second key.
  - *(AC-34 to AC-37; D-21.)*

### Chunk E: scans, engine, documents, close-out

- [ ] **Step 22: the cross-slice invariant sweep** (`SalesInvariantTest`). After a scripted day (open → three sales with split and credit tenders, **plus one fully discounted zero-total sale** (AC-14A) → hold, recall elsewhere → void one → refund one → close → reconcile), assert:
  - the zero-total sale is numbered in sequence with the others and has exactly one audit row;
  - every completed sale's Σ tender `amount` = `total` (−`total` for a refund);
  - every void has an exact mirror;
  - every credit tender has exactly one ledger row;
  - `refunded_quantity` ≤ `base_quantity` on every line;
  - every approval consumed at most once;
  - one **sales** audit row per sales write, plus exactly one **customers** audit row (`customers_credit_ledger_entry_recorded`) per ledger posting (R-20);
  - the shift's `expected_cash` equals a hand-checked figure.
  - *(The NFRs; AC-14, AC-21, AC-28.)*
- [ ] **Step 23: `QaSalesSourceRulesTest` (host),** code-only via `SourceRules::codeOnly`.
  - **AC-42 (a), D-48's token list:** no file under `packages/sales/src` contains `tax_rates`, `tax_group_taxes`, `price_list_items`, `price_list_assignments`, `is_inclusive`, `is_compound`, `unit_conversions`, `base_unit_id`, `customer_ledger`, `credit_balance`, `balance_after`, `number_sequences` or `used_count`.
  - **AC-42 (b):** no `*`, `/`, `multipliedBy(` or `dividedBy(` in `packages/sales/src` outside `Support/Money.php` (Ruling 3).
  - **AC-42 (c), anti-vacuity:** T-027's factor scan and T-029's AC-25 scan both enumerate at least one `packages/sales/src` file, and both report zero hits with **no** sales entry in either exemption list.
  - **AC-15/AC-31:** no `stock_` table name and no `journal_` token in the package.
  - The package has no `routes/`, `Route::`, `Cache::` or `dispatch(`.
  - **Red-first:** planted files for each rule, then deleted.
  - *(AC-42, AC-15, AC-31.)*
- [ ] **Step 24: `SalesSchemaEngineTest`** (database `flexipos_t8test_t030_tenant`, created and dropped by **exact name** through `EngineDatabase`, never with `LIKE`). Under `ENGINE_HOLD`, only the refusal case runs. Written for G-1:
  - the `information_schema` decimals equal the precision map;
  - `open_terminal_key` refuses on MySQL;
  - `JsonValidity`;
  - contract file 10 on a populated `loyalty_transactions`;
  - two connections completing the same sale: the second waits on the row lock and then sees `completed` (`innodb_lock_wait_timeout = 1`);
  - concurrent `DocumentNumbers` gives distinct numbers, and a concurrent **first** use (both connections finding no row) gives distinct numbers through the locking retry (R-27). This row calls `DocumentNumbers` directly, so it does not exercise any slice's retry. A sibling row runs two `CompleteSale` calls on two connections against a fresh `sale` sequence and asserts that both complete with distinct numbers. That is the only real-engine evidence for the `transaction(..., 2)` retry (chunk B's NOT VERIFIED item), and it is skipped under the hold like the rest;
  - `CloseCashShift` on connection A holding the shift lock makes `CreateSale` on connection B wait, then refuse `no_open_shift` (R-5);
  - two completions consuming discounts {A, B} on two connections both succeed, with no deadlock (ascending order, R-5);
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
  - §6.1: `DocumentNumbers` and the defaults (F-6), and the portable lock-and-increment allocation, which replaces the MySQL-only allocation the `number_sequences` migration's docblock describes (R-27). §6.3: `snapshotInherited`.
  - §6.7: Ruling 7's lock order, as the order every future writer of these tables must keep (FP-234 takes the stock slot). §6.9 "As built (T-028)": `loyalty_transactions.sale_id` is now built.
  - `docs/architecture.md` §3: the Sales row points at `packages/sales`.
  - The follow-ups below go to `lead`.
- [ ] **Step 26: final gate.** `composer check` from `modules/host`, with counts and `test` seconds compared against step 0, then `composer test:fast` once. If a chunk added more than 10 % to the `test` step, stop and report to `lead` (FP-328).

## Test strategy

- **Unit (pure):** `Money`, `SaleRollup`, `TenderRules`, `ShiftTotals`, `InheritedFactor` (plus fixtures), and the state-machine table (`SaleStatus` transitions as a dataset, including every refused edge).
- **Integration (SQLite stand-in, real implementations):**
  - one file per slice, against the real `UnitResolver`, `PricingEngine`, `CustomerCreditLedger`, `PinVerifier`, `DocumentNumbers` and `PermissionResolver`;
  - refusals write nothing (sales tables, ledger, `discounts.used_count` and `number_sequences` all compared whole);
  - a success writes one sales audit row, plus one customers audit row per ledger posting (R-20);
  - `FakePricingEngine` only for AC-5's reason-propagation dataset, including `no_price_resolved` (R-3);
  - every lock order is pinned as SQL text on the stand-in, which compiles no lock clause; the run-time proof is step 24's, under the engine hold (the T-029 `PricingLockOrderSourceRulesTest` honesty rule);
  - `SalesInvariantTest` for the scripted day.
- **Owned elsewhere:** `SnapshotInheritedTest` (catalog), `HandlerPinVerifierTest` (access), `DocumentNumbersTest` (organization), `DiscountUsageTest` (pricing).
- **Static:** `SalesTenantSchemaTest`, `LoyaltySaleIdMigrationsTest`, `TenantDecimalPrecisionTest`, `QaSalesSourceRulesTest`, the catalogue `is_destructive` assertions, and deptrac with the demo control.
- **Engine:** `SalesSchemaEngineTest`, skipped under the hold.
- **Mutations:** U1, N1, N2, M1 to M11. Revision 4 adds four:
  - M8, the `allows_refund` guard (step 16a);
  - M9, a zero-total short circuit (step 17);
  - M10, the dropped retry (step 17);
  - M11, the sellability check on refund lines (step 20).

| ACs | Proved by |
|---|---|
| 1 | 13 |
| 2–6, 8–10 | 14 (AC-6 also by D-36's rows) |
| 7 | 15 |
| 11–13 | 9, 16 |
| 14, 15 | 4, 5, 17, 23 |
| 16 | 11 |
| 17–20 | 18 (and 16 for F-22's implicit recall) |
| 21, 22 | 19 |
| 23, 24 | 3, 10, 15 |
| 23a | 15 |
| 25–31 | 2, 20, 23 |
| 32, 33 | 4, 12 |
| 34–36 | 9, 21 |
| 37 | 12, 16, 21 |
| 38 | 2 |
| 39 | 3 |
| 40 | 4, 24 |
| 41 | 5 |
| 42 | 23 |
| 43 | 6, 8 |
| 44 | 8, 24 |
| 45 | 7, 24 |
| 46 | 6, 10, 18 |
| 12A | 16a (`TenderRulesTest`, `SalesRegistryTest`), 16 (sale tender unaffected), 20 (refund refused and accepted) |
| 14A | 17 (zero-total row, M9), 22 |
| 24A | 10 (`ApprovalGateTest`'s branch row, built), 15, 19 (raw-seeded rows), 20 (goodwill, end to end) |
| 47 | 14 |
| 47A | 14 (scope), 20 (goodwill rows, M11; deleted product still `unknown_product` pending Q-1) |
| 48 | 17, 19, 20 |
| 49 | 17 |
| 50 | 12 (built), 17, 20 |
| 51 | 9 (built rollup), 14, 17, 18 |
| 52 | 14, 20 |
| 53 | 20 (default pending Q-1) |
| 54 | 20 |
| 55 | 15 |
| 56 | 16 |
| 57 | 20 |
| 58 | 5 (built), 17 |

## Risks & rollback

1. **Refunds through the engine's live state (highest, R-1).** Contract drift, the highest risk in revision 2, is retired: section 0.5 read the shipped code. What replaces it is a gap between D-38 and the engine. A discounted line whose discount has since filled its usage limit, or been deactivated or deleted, cannot be refunded, and nor can a deleted product, even as a goodwill refund. The default (refuse, naming the reason) is safe for money and bad for customers, so it needs Q-1's ruling before Chunk D is accepted.
2. **Spec drift.** Revision 3 of the spec is settled. The residual risk is that `dev` reads the first draft (`a2e6631`), which is still in history and numbers its ACs differently. There, D-5's sale-keyed ledger entry (a silent replay) and its tender-time posting (an orphaned debit) produce code that passes its own tests and corrupts a customer balance. Steps 16, 17 and 22 assert the corrected shapes specifically.
3. **Merge conflicts.** These shared files are edited by T-028, T-029 and this task:
   - `deptrac.yaml`, `phpstan.neon.dist`, `providers.php`, host `composer.json`/`.lock`;
   - `QaToolchainRegistryCoverageTest`, `TenantDecimalPrecisionTest`, `tests/Helpers.php`, `TenantBaselineBuildsTest`, `MigrationTreeDisciplineTest`;
   - the catalogue JSON, `.env.example`.

   Both have landed and are merged (`db5e772`, `74fceba`), so this risk is now limited to T-030's own merge back. Republish the catalogue; never hand-merge it.
4. **Four edits in packages this task does not own** (catalog, access, organization, pricing), plus one schema change on customers'. Each is additive and tested by its owner's suite. Rollback is per contract. `qa` should review them as their owners' code, not as Sales code.
5. **Approval scope.** A refund approval carries no amount ceiling (F-21), so one approval covers whatever refund it is consumed by, against its bound sale. Approvals are single-use and sale-bound, which caps the exposure at one refund. A ceiling is a follow-up if the owner wants it.
6. **Held sale with tenders, recalled at another terminal** (F-15). Its cash tender is counted in the completing drawer, not the one that took the cash. This is rare and visible as a variance pair. Fix options: refuse holding a sale with cash tenders, or move tenders with the shift. That is the owner's call. F-22's implicit recall gives the same attribution, never a different one.
7. **A customer merged after a credit sale** makes the void's `credit_note` refuse (`customer_not_live`), so `VoidSale` refuses `credit_refused`. The refund path has the same limit. T-028 follow-up: re-point to `merged_into_id`.
8. **Gate time.** This task adds roughly 300 cases. Measure at each chunk boundary (Ruling 11 of T-029).
9. **Query cost.** `UnitResolver::snapshot()` loads `units` twice (FP-426). A 50-line sale is about 250 queries plus the engine's. That is acceptable for the online till, and FP-426's batch API is the remedy.
10. **A free sale (R-3, Q-2).** An unpriced product quotes `0.0000` (FP-440), and a zero-total draft with lines completes with no tender. Together, a product nobody priced can leave the shop with no money taken and no refusal anywhere. It is visible after the fact (a `0.0000` line), not prevented.
11. **The company-wide number lock is the shop's serial point (R-27).** Every completion, refund and shift open takes the same `number_sequences` row and holds it until commit. Ruling 7 takes it last to keep the hold short. A shop with several tills completing at once queues there by design: gapless numbering costs exactly this.
12. **A stuck pending sale.** A completion refused at commit (`usage_limit_reached` after a concurrent sale took the last use, or `credit_refused`) leaves a `pending_payment` sale whose lines are locked (AC-6). The way out exists: remove the tenders (back to `draft`), edit the line, and tender again. Step 17 includes one row that walks it.
13. **A replayed tender after removal (Q-6).** With no tombstone, a late retry of an `AddTender` re-adds a tender the cashier removed. The online till rarely retries, and the exact balance check still holds; offline replay (FP-237) needs a tombstone.

**Rollback:** everything is new except the four contract additions and the edits listed below. The migrations are forward-only and no tenant outside this branch has run them, so rollback is to abandon the branch.

## Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock`, `deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | package wiring (deptrac with R-7's test-layer grant) | 1 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` | `'sales'` (line 446) | 1 |
| `packages/access/tests/Support/AuditCatalogueTest.php` | `sales` in both module lists, lines 48–49 and 92 (R-6) | 1, or whichever step first declares an audit action |
| `packages/contracts/src/Catalog/{UnitResolver,FactorSource,UnitRefusal,UnitResolutionRefused}.php`, `packages/catalog/src/Units/DatabaseUnitResolver.php`, `packages/catalog/tests/Contracts/CatalogEnumsTest.php`, `UnitFixtureReplayTest` (lint and replay, R-19) | step 2 | 2 |
| `packages/pricing/tests/Features/AddPriceListItem/AddPriceListItemTest.php` | the anonymous `UnitResolver` double gains `snapshotInherited()` (R-2) | 2 |
| `packages/access/src/AccessServiceProvider.php` | binding | 3 |
| `packages/organization/src/OrganizationServiceProvider.php` | binding | 4 |
| `packages/pricing/src/PricingServiceProvider.php` | binding | 5 |
| `database/catalogue/tenant_schema_versions.json`, `.env.example` | republished; versions 64 to 73, `APP_REQUIRED_SCHEMA_VERSION` 63 → 73 | 6, 7 |
| `tests/Helpers.php` `tenantTreeTables()`, `MigrationTreeDisciplineTest` (58 → 66), `TenantBaselineBuildsTest` (FK inventory, +36 and +1), `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest` (a `$t030` list) | schema | 6, 7 |
| `tests/Feature/SchemaCataloguePublishTest.php` | expected green with no edit: it derives the top from the tree. Listed so that a red here is a stop, not an edit | 6, 7 |
| `tests/Feature/IdempotencyKeysSchemaTest.php` | the catalogue top: 63 → 73, file 10's name, `contract` (R-6). Red from step 6, edited once at step 7 | 6, 7 |
| `tests/Feature/BranchForeignKeysContractRunTest.php` | `bfkRunT030V()`; one more run in each "to the top" case (R-6) | 7 |
| `packages/customers/tests/Schema/CustomersTenantSchemaTest.php` | `loyalty_transactions` +`sale_id`; **T-028's AC-33 absence assertion inverted**; FK inventory +1 (R-6) | 7 |
| `tests/Engine/CustomersSchemaEngineTest.php` | the same column, if it lists them | 7 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | the T-030 map | 8 |
| permission registry sweep tests (the `DeclaresPermissions` set) | expected green with no edit: every one reads the registry dynamically (`QaAccessHttpSurfaceTest`, `QaAccessGuardIsolationHttpTest`, `AccessBumpSweepTest`, `OwnerRoleSyncTest`). The two keys are branch-scoped, and `QaAccessHttpSurfaceTest` refuses a branch-scoped key only on a route; Sales has none (R-25). A red here is a stop | 10 |
| `packages/sales/src/Tenders/TenderRules.php`, `packages/sales/tests/Tenders/TenderRulesTest.php` | built in chunk B. `decide()` gains `bool $refund = false` and reads `allows_refund` on a refund (AC-12A); new rows (rev 4) | 16a |
| `packages/sales/src/Exceptions/SalesRefusal.php`, `packages/sales/tests/Registry/SalesRegistryTest.php` | `RefundNotAllowed` removed; the pinned list goes from 43 codes to 42 (rev 4) | 16a |
| `packages/sales/tests/Features/OpenCashShift/OpenCashShiftTest.php` | `numberingWrapper()` and `deadlock()` move to `tests/Support/DeadlockingNumbers.php`; its rows otherwise unchanged and still green (rev 4) | 17 |
| `packages/sales/src/Approvals/ApprovalBinding.php` | docblock table gains `branch_id` (comment only, rev 4) | 19 |
| `packages/sales/src/Registry/SalesPermissions.php`, `packages/sales/tests/Registry/SalesRegistryTest.php` | `sales.shifts.reconcile`; the permission count goes from 1 to 2 (rev 4) | 21 |
| `docs/database.md`, `docs/architecture.md` | step 25 | 25 |

Anything red that is not in this table means stop and report to `architect`.

## Follow-ups for `lead` (backlog rows or notes, not built here)

- **Bill-level discounts** (D-35): the allocation rule, re-tax, `bill_discount_amount`, and refund share.
- **Unversioned price-list rows** (D-38's residual gap) make an edited row change a later refund.
- **Refunds against the engine's live state (R-1, Q-1):** an additive pricing quote mode for a refund that skips the current-state checks (usage limit, `is_active`, deletion, `allow_discount`) and keeps the window and the arithmetic. It touches T-029's engine, its fixtures and FP-432's twin.
- **Reserving `sale_tender` in customers (R-4, Q-8)**, together with FP-435's case-folding of the reserved-type check.
- **Tombstones for removed tenders** before offline replay (R-38, Q-6, FP-237).
- **The quote instant and the customer group at sale time** are not stored on `sale_items` or `sales`, so a refund uses `completed_at` and the customer's current group (Q-9, Q-10).
- **T-028 debts still open (F-9):**
  - `RecordLoyaltyTransaction` accepting `sale_id`, with points rounded to 4 decimals half up before `LoyaltyLedger` (R-23);
  - `first_sale_at`/`last_sale_at`/`lifetime_value` (recomputable from `sales`);
  - `sales.customer_id` on merge;
  - the `credit_limit` approval and the offline recorded-anyway path;
  - void and refund compensation for merged customers (risk 7).
- **T-029 debts still open:**
  - `approval_threshold` and `workflow_definition_id` evaluation (F-12);
  - `min_selling_price` and `price_mode = open_price` (F-16);
  - `rounding_rule`, `surcharge_percent`, `service_charge` (F-11);
  - `sales.price_list_id` as a forcing parameter (D-11);
  - whether `customer_groups.discount_percent` feeds the engine;
  - FP-440 (an unset price indistinguishable from a free one) and FP-443 (an unavailable branch row still prices), which Sales now consumes (R-3, Q-2).
- **FP-234:** stock validation and decrement inside `CompleteSale`, `stock_applied`, `unit_cost`/`line_cost`/`cost_total`, and the `negative_stock` approval.
- **FP-260/FP-263:** `journal_entry_id`, `ar_document_id`, `payment_methods.financial_account_id` (documented `NOT NULL`: expand + backfill + contract), `cash_movements.financial_account_id`, the posting rules of §7.1, and a real reconcile step before `reconciled`.
- **FP-237:** per-terminal reserved number prefixes (§12), offline sale replay, the `[SYNC]` columns, and the `payment_method` replica entity.
- **FP-236 / API task:** routes and permission keys for every slice, and a `SetSaleCustomer` command (customer is set at create only here).
- **An outbox `sale.completed` event** (§12.1) once FP-234 or Finance needs one.
- **Loyalty/voucher tenders** (F-17), and **gateway** tenders (D-7).
- **Exchange sales, quotations, sales orders, restaurant** (D-10).
- **A refund-approval amount ceiling** (risk 5); **cash tenders on a held sale** (risk 6).
- **FP-331:** the tenth copy of the support helpers. **FP-426:** a batch resolve for multi-line documents.

## Questions for `ba` (revision 3; routed through `lead`)

**Revision 4 status.** Q-3 to Q-11 are **ruled**, as D-57 to D-65 (spec revision 4), each at the default below, so the table is kept as the record. Q-1 (D-55) and Q-2 (D-56) are still **open** and owner-level, and their defaults are what is built.

`architect` cannot message `ba`. Each question has a default, the plan builds that default, and the step it changes is named. None blocks Chunk A. Q-1 and Q-2 change customer-visible behaviour and are owner-level. Q-1 should be ruled before Chunk D is accepted, and Q-2 before Chunk C is.

| # | Question | Default the plan builds | Step |
|---|---|---|---|
| **Q-1** (owner-level, R-1) | A referenced refund line is re-quoted through the engine, which checks the **current** state of the discount (usage limit, active, deleted) and of the product (deleted, `allow_discount`). With D-38's original instant, a discount that has filled its usage limit (this sale's own use can be the one that filled it), or that has been deactivated or deleted, makes the line unrefundable. A deleted product cannot be refunded at all, even as a goodwill refund. Which rule? (a) refuse, as the engine does; (b) an additive pricing quote mode for refunds that skips the live-state checks (a T-029 engine change); (c) something else | (a): refuse under the engine's own reason, write nothing, a dataset row per case, and a follow-up row for (b) | 20 |
| **Q-2** (owner-level, R-3) | FP-440: an unpriced product quotes `0.0000`, and Ruling 4 lets a zero-total draft with lines complete with no tender. Should `AddSaleLine` refuse a line whose resolved `unit_price` is `0.0000` and came from ladder step 6 (the product's own `selling_price`)? A genuinely free item would then need an approved override | Accept, as the spec reads: the line is stored at `0.0000`, and the risk is listed (risk 10) | 14 |
| **Q-3** (R-29) | Neither the spec nor the engine checks `products.is_active`, `is_sellable` or `type = variant_parent`. May such a product be rung up? | Refuse `product_not_sellable` (a new `SalesRefusal`), naming the fact. It is reversible, and a variant parent is not a sellable item | 14 |
| **Q-4** (step 20) | The spec does not say whose customer a refund carries, or whether a refund may be made at another branch than the sale's | A referenced refund copies the original's `customer_id` and its snapshots. A goodwill refund takes an optional, live-checked `customer_id`. A refund at another branch is `not_refundable` (currency, shift and approval branch all follow the original) | 20 |
| **Q-5** (AC-7, AC-23a) | Once a price-override or discount approval has been consumed by `AddSaleLine`, does an `UpdateSaleLine` that re-prices the same line (for example, a new quantity) need a new approval? AC-23a's "a consumed approval exists" reads as no | No: a matching approval already consumed by **this sale** covers it. A different discount or a different price needs a new one | 15 |
| **Q-6** (R-38) | `RemoveTender` deletes the row (AC-13), so a late retry of that tender's `AddTender` re-adds it. Is that acceptable for the online till? | Accept and document it (risk 13). A tombstone is a follow-up before offline replay (FP-237) | 16 |
| **Q-7** (R-5) | D-50 says every write locks the `sales` row first. Ruling 7 now takes the `cash_shifts` row first, for the writes that attribute a sale to a shift, to close the race with `CloseCashShift`. Please reword D-50 to "every write takes the locks in Ruling 7's order" | The plan follows Ruling 7. D-50 is informational, so nothing else changes | 13, 16, 18–21 |
| **Q-8** (R-4) | `sale_tender` is not a reserved ledger reference type, so a manual customers ledger entry can pre-seed a tender's key. Sales now refuses any replayed posting, so no money is lost. Should customers also reserve `sale_tender` against its public slice (a customers change, with FP-435's case-folding)? | Not built here; a follow-up row | — |
| **Q-9** (D-38) | A line is quoted when it is added, but a refund is quoted at the sale's `completed_at`, because `sale_items` stores no quote instant. A promotion that ended between the line's add and the completion prices the refund differently from what was charged | Keep D-38 (`completed_at`), and name it as a residual gap alongside the unversioned price lists | 20 |
| **Q-10** (R-10) | A refund passes the customer's **current** `group_id` to the engine, because the sale does not store the group at sale time. A customer moved to another group since then can be refunded at a different group price | Use the current group, and name the residual gap | 20 |
| **Q-11** (R-15) | `DiscountUsage::consume()` re-checks only existence and the limit, not `is_active` or the window. A discount deactivated between quote and completion is still consumed and honoured | Accept: those are facts checked at quote time, and the line was priced while the discount was valid | 5, 17 |

## Questions for `lead` (revision 4)

Neither blocks: each has a default, which the plan builds.

1. **A referenced refund line and the sellability check.** AC-47A exempts a goodwill refund line. AC-47 names only `AddSaleLine`/`UpdateSaleLine`, so a referenced refund line of a product deactivated since the sale is not checked either. **Default:** `RefundSale` never runs the check, for either kind of line (steps 14 and 20). The reason is the same as D-73's: the shop's catalogue change should not block a return.
2. **Change on a refund tender.** AC-30 says refund tenders follow "AC-12's method rules exactly". But paying out more than is owed and taking change back is not a real flow. **Default:** with `$refund = true`, `TenderRules` gives no change. An over-payout is `exceeds_balance` whatever `allows_change` says, and `tendered` is null (step 16a). If the lead wants change honoured on refunds, the `$refund` branch is removed from check 6 only.

## Questions for the human

None of their own. Q-1 and Q-2 above are owner-level, and `lead` should put them to the product owner. Revision 1's two questions were ruled by `lead` and recorded as decisions:
- bill discounts are deferred (D-35);
- refunds are re-quoted at the original sale's instant (D-38).

F-22 to F-25 were folded in as D-51 to D-54. `lead` may still want to tell the product owner about D-38's named gap: a price-list row **edited** (rather than replaced) after a sale still changes that sale's refund amount, because price lists are not versioned. Q-1, Q-9 and Q-10 are three more gaps of the same family.
