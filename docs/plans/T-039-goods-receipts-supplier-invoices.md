# Plan: T-039 Goods receipts and purchase returns

Spec: `docs/specs/T-039-goods-receipts-supplier-invoices.md` (AC-1 to AC-62). Decisions: `docs/decisions/T-039-goods-receipts-supplier-invoices.md` (D-1 to D-17). Backlog FP-255 (split, D-1). Branch `ai/T-039-goods-receipts-supplier-invoices`, worktree `.claude/worktrees/goods-receipts`, planned at `72a3953` (contains T-029, T-030, T-032; **not** T-031, T-033 or T-034).

**Planned ahead of two unbuilt dependencies.** Facts about T-034 come from its spec revision 2, decisions D-1 to D-53 and **plan revision 2** (`purchase-orders` worktree, commit `c437232` + plan at 22:19 on 2026-11-16, re-read last). Facts about T-031 come from its spec revision 2 and plan revision 2 (`stock-ledger` worktree, `3148cd2`). Facts about T-033 come from its spec and decision D-7. None of that code exists in this tree; every such fact is listed in section P.3 for `dev` to re-verify at step 0. Facts about built code (unit code, numbering, pricing scan, shared tests) were read in source at `72a3953`.

**Delivery rule (`ENGINE_HOLD`).** `architect` ran no test and no gate. During a chunk `dev` runs only the targeted Pest files the step names plus `composer lint`, `composer stan`, `composer deptrac`. The full `composer check` is scheduled and run by `lead`. Real-engine tests (`modules/host/tests/Engine/*`) are written and skipped and are reported **NOT VERIFIED**. Nothing in this plan claims a green gate.

---

## P. PRECONDITIONS

### P.1 What must be merged before each chunk

| Chunk | Needs merged into this branch | Why | If missing |
|---|---|---|---|
| A (plumbing, converter, prefixes, schema, statics) | **T-034 complete** (all of chunks A to G of its plan) | the five tables have foreign keys onto `suppliers`, `purchase_orders`, `purchase_order_items`; every file lives in `packages/purchasing`; the refusal enum, registry, support copies and `DEFAULT_PREFIXES` entries this task extends are T-034's | do not start. Nothing in this task can be built in another package as a stand-in |
| B (building blocks), C (receipt drafts), D (return drafts) | T-034 (as A) | `ProductFacts`, `TaxGroupChain`, `Places`, `PurchaseLinePricing`, `BusinessClock`, `Authorise`, `Pager`, `Money` | as A |
| E (posting, against a **test double**) | T-034, **plus T-031's published contract only**: `packages/contracts/src/Inventory/*` (T-031 plan step 2) | the post handlers depend on `Contracts\Inventory\StockLedger` and its DTOs. T-031 step 2 is **not gated by FP-179**, so it can be merged while `stock_movements` does not exist | if even the contract is not merged, chunk E waits; `dev` must **not** author `Contracts\Inventory` (T-031's file) |
| F (posting against the **real ledger**) | T-034, plus T-031 through its chunk C (steps 4, 9, 10, 11: `stock_movements`, the writer, replay, batches). Step 4 is behind **gate G-179 (FP-179 owner ruling)** | end-to-end valuation (AC-25 worked example), real `insufficient_stock` (AC-45), real replay detection (AC-26) | report `BLOCKED on FP-179` for the chunk F rows; A to E and G (minus F's rows) still ship. ACs left unproven are listed in the hand-back |
| G (scans, engine, cross-tenant, docs, final) | as F for the rows that read the ledger; the rest as E | | split as stated in each step |

T-033 (journal) is **not** a precondition: this task only emits events (D-15, AC-31). T-032 is already in this tree.

### P.2 What can be built before the stock ledger exists

Chunks A to D need no ledger at all. Chunk E builds both post slices in full against `FakeStockLedger` (a test double implementing the published `StockLedger` contract, in `packages/purchasing/tests/Support`), which records commands, returns scripted `StockPosting`s (including `replayed = true` and an outbound `value`), or throws a scripted `StockLedgerRefused`. That proves every purchasing-side rule of AC-19 to AC-33 and AC-43 to AC-47 (command shape, money, refusal mapping, rollback, idempotency by outcome, `ledger_inconsistent`). Chunk F re-runs the money and stock rows through the real writer.

### P.3 Assumptions to re-verify at step 0 (stop and report any difference)

From T-034 (plan revision 2):
1. `packages/purchasing` exists, namespace `Flexipos\Purchasing\`, slices flat under `src/Features/`, `Features/<X>/{X, XHandler, XResult}` with `array $fields, ?string $actorId`.
2. `PurchasingRefusal` has the 32 codes of T-034's Data section, including `unit_unresolvable`, `inherited_unit_mismatch`, `supplier_mismatch`, `currency_required`, `order_not_open`, `unknown_branch`; `PurchasingRefused` has `because`, `fromUnit`, `fromPricing`, `fromNumbers`, `fromAccess`, a `?BackedEnum $cause`, `?string $detail`, `previous`, `code()`.
3. `PurchasingAuditActions` (28 names) and `PurchasingPermissions` (8 keys) exist; the provider implements `DeclaresAuditActions`, `DeclaresPermissions`; `AuditCatalogueTest` already lists `purchasing`.
4. `Support/` holds `TenantConnection`, `Fields`, `Identifier`, `Lookup`, `Pager` (by id, `before_id`), `Page`, `Decimal`, `Money` (`add`, `subtract`, `sum`, `compare`, `isZero`, `fits`, `product`), `Uniqueness`, `BusinessClock::businessDate(string $timezone)`, `Authorise::require($resolver, $actorId, $key, $branchId)`.
5. `Documents/` holds `Places`, `ProductFacts` (D-39, one `whereIn` per batch), `TaxGroupChain` (explicit, `purchase_tax_group_id`, `defaultGroupId()`, then `state()`), `LineQuoter`, `DocumentLocks`. **Check whether `LineQuoter` takes the document date and discount as parameters** (plan revision 2 step 8 hard-wires `documentDate = order_date`); if not, this task adds `ReceiptLineQuoter` beside it rather than editing it (see R-34e).
6. `ApplyReceivedQuantity` slice: fields `order_item_id`, `application_id`, `received_base_delta`, `received_quantity_delta`; no permission check (AC-96); nested `transaction()` (savepoint); locks the order header first; accepts a zero `received_quantity_delta` with a non-zero base delta (AC-77 rev 2, D-47); accepts `received` orders (D-44); writes one `purchasing_order_receipt_applied` audit row and one `purchase_order.received_applied` event per real application (AC-80, AC-97). **Its result must expose `replayed`** (R-34a). Record the actor parameter used for `created_by`.
7. `purchase_order_items` carries `unit_id`, `unit_factor`, `base_quantity`, `subtotal`, `discount_amount`, `received_base_quantity`; `purchase_orders` carries `supplier_id`, `location_id`, `branch_id`, `order_date`, `currency`, `status`.
8. `Contracts\Pricing\PurchaseLinePricing::quotePurchaseLine(PurchaseLineQuote)` exists and **`PurchaseLineQuote` accepts `quantity` and `baseQuantity` of `0.0000`** (R-34b). If it refuses zero, use the fallback in Ruling 4.
9. `DatabaseDocumentNumbers::DEFAULT_PREFIXES` holds `PR` and `PO`.
10. `QaPurchasingSourceRulesTest` exists with token list (a) including `grns` and `stock_levels`, `stock_movements`, and rule (b) restricting `*`, `/`, `multipliedBy(`, `dividedBy(` to `Support/Money.php` with a `Money::product(` call-site allow-list. This task edits it (Shared test edits).
11. `modules/host/config/purchasing.php` keys `page_size` 25, `max_page_size` 100, `max_lines` 200.

From T-031 (plan revision 2, Ruling 2, 4, 6):
12. `Flexipos\Contracts\Inventory\StockLedger::postBatch(list<StockMovementCommand>): list<StockPosting>` in input order, joins the caller's open transaction (savepoint), never commits; throws `StockLedgerRefused` (`StockRefusal $reason`, `?string $field`, `?int $commandIndex`, `?string $onHand`, `?string $existingMovementId`).
13. `StockMovementCommand::fromArray()` accepts exactly the 13 keys; `StockPosting` carries `value`, `unitCost`, `replayed`, and **its constructor and `StockLedgerRefused`'s are public** so a test double can build them (R-31a).
14. Type table: `grn` pairs with reference type `grn`, quoted-price shape (`unit_cost`, `cost_quantity`), magnitude inbound; `purchase_return` pairs with `purchase_return`, no cost accepted; `grn_return` refused `type_has_no_document`. `cost_quantity` on a quoted price is unsigned, at most 4 decimals, **zero allowed**; `money_in = ROUND(unit_cost x cost_quantity, 4)`.
15. References must be canonical upper-case ULIDs (`\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z`), never folded by the ledger.
16. FP-179 status in `docs/backlog.md` (record only).

From the tree (re-read after the merges):
17. `UnitResolver` unchanged: `snapshotInherited()` picks the stock unit first (factor `1.000000`), then the referenced unit with the referenced factor, else `inherited_unit_mismatch`; `SnapshotCalculator` rounds to the line unit's precision, then base, then Q-11 (`fractional_base_quantity`) and range. Cost: `snapshot()` 5 queries, `snapshotInherited()` 2.
18. `UpdateLocation` still refuses a change of `branch_id` (`packages/organization/src/Features/UpdateLocation/UpdateLocationHandler.php`, docblock line 21). Ruling 7 depends on it.
19. The last tenant migration filename and the catalogue top version after the merges; the tenant table count; the FK inventory; the `$t0xx` lists in `QaAccessSchemaShapeTest` and `QaAccessCloseOutSweepsTest`.
20. `QaPricingSourceRulesTest` AC-25(b) patterns (`modules/host/tests/Feature/QaPricingSourceRulesTest.php` line 212 onward) and `QaCatalogSourceRulesTest`'s factor patterns (line 206 onward) are unchanged.
21. T-033 D-7 still lists `grn` and `purchase_return` as `source_type` values (informational; FP-262 posts idempotently by `(source_type, source_id)`).

---

## 0. Resolutions of the points `ba` handed over

| # | Point | Resolution | Evidence |
|---|---|---|---|
| H-1 | A legal quoted-price shape that passes **exact line money** into the ledger | Each `grn` command carries `unit_cost` = the line's **ledger money** (inventory money x `exchange_rate`, rounded half-up once at 4, company currency) and `cost_quantity` = `"1"`. The ledger computes `ROUND(m x 1, 4) = m`: exact, no re-multiplied per-unit cost (AC-25, T-031 AC-26). A free-only line sends `unit_cost "0.0000"`, `cost_quantity "1"`. The replay fingerprint compares the resulting `value` only (T-031 AC-42), so the shape is replay-stable. The stored movement `unit_cost` is the ledger's own `ROUND(value / quantity, 4)` per stock unit (T-031 AC-75) | T-031 spec AC-21, AC-26, AC-42, AC-75; plan Ruling 4, Ruling 5. Note: T-031's plan follow-up text suggests `cost_quantity` = paid line quantity for FP-254; that shape re-multiplies the rounded `landed_cost` and is **not** used (R-31b) |
| H-2 | `postBatch` | One `postBatch` call per post, all lines in `line_no` order, inside the post transaction (the ledger joins it as a savepoint). Results come back in input order; `commandIndex` on a refusal maps to the line. A 50-line receipt is one call (NFR) | T-031 plan Ruling 2, Ruling 6 |
| H-3 | `ApplyReceivedQuantity` as an in-package call accepting a zero delta | Called **directly** (its handler, not a contract) once per receipt line with `order_item_id` and paid `base_quantity > 0`, inside the post transaction, after this task has itself locked the order header. Application id = the receipt line id. `received_quantity_delta` may be `0.0000` (T-034 AC-77 rev 2, D-47). A `replayed = true` result while the receipt was `draft` is refused `order_application_refused` (detail `replayed_for_draft`), the order-side twin of `ledger_inconsistent` | T-034 AC-69, AC-76, AC-77, AC-96; plan Ruling 5, 7 |
| H-4 | Do `PurchaseLinePricing` and `snapshotInherited()` serve receipt lines? | **`PurchaseLinePricing`: yes**, for receipt and return lines, discount `"0"`, `quantity` = paid quantity, `baseQuantity` = paid base, `documentDate` = `received_date` / `return_date`. **`snapshotInherited()`: yes, three uses**: (1) a receipt line in its order line's unit, referenced = the order line (AC-8b); (2) `base_free`, referenced = the receipt line itself (H-6); (3) a return line with `grn_item_id`, referenced = the receipt line (AC-40). A receipt line in any other unit, or with no order line, uses `snapshot()` (AC-8a) | `UnitResolver` docblock, `DatabaseUnitResolver`, `SnapshotCalculator` read at `72a3953` |
| H-5 | A unit-code operation for "base amount expressed in the order line's unit"? | **None exists** (`UnitResolver` has `resolve`, `snapshot`, `snapshotInherited`, all unit-to-base). This task adds one, in the unit code: `Contracts\Catalog\BaseQuantityConverter::toUnit(string $baseQuantity, string $unitFactor): string` = `ROUND(base / factor, 4)` half-up, signed allowed, factor canonicalised as `snapshotInherited()` does, refusals as `UnitResolutionRefused`. Implemented by pure `packages/catalog/src/Units/BaseInUnit.php`, bound in `CatalogServiceProvider`. A new interface, not a new `UnitResolver` method, because two test doubles implement `UnitResolver` (`AddPriceListItemTest`, `AddSaleLineTest`) (T-034's D-53a reasoning). Purchasing never divides or multiplies by a factor. Proof that it round-trips: for a receipt line in the order's own unit, `toUnit(ROUND(q x f, 4), f) = q` for every 4-place `q` and `f >= 1` (the error before rounding is below half a unit in the last place); a dataset row pins it | `packages/contracts/src/Catalog/UnitResolver.php`; grep for `implements UnitResolver` |
| H-6 | Base equivalent of `free_quantity` | **Existing operation**: `snapshotInherited(product, line.unit_id, line.unit_factor, line.unit_id, free_quantity)`. It reuses the line's frozen factor (or `1.000000` if the line is in the stock unit, which is the same factor), rounds `free_quantity` to the unit's precision, computes base by the resolver, and applies Q-11 (`fractional_base_quantity` -> `unit_unresolvable`, AC-9). Skipped (base free `0.0000`) when `free_quantity` is zero. Costs 2 queries per line with free units | as H-4 |
| H-7 | `base_free` stored or derived | **Stored**: `grn_items.base_free DECIMAL(18,4) NOT NULL DEFAULT 0`, written from H-6's snapshot. Deriving it on read would need a factor multiplication in purchasing (forbidden, AC-59) or a resolver call per read; the event, the ledger quantity and nightly invariant 3 all want the stored figure. "Reconstructible from the stored row" (AC-9) is proven by a test that recomputes it through `snapshotInherited()` from the stored `free_quantity` and `unit_factor`. `docs/database.md` §6.5's (†) expression stays the definition; step 21 records "stored, not generated" in As built | `docs/database.md` lines 981 to 985, 1207 |
| H-8 | Branch derived from location | **Derived, not stored.** `grns` and `purchase_returns` have no `branch_id` (spec assumption, `docs/database.md` §6.6, AC-57's FK list). A document's branch is `locations.branch_id` of its stored `location_id`, which T-014 makes immutable (P.3 item 18) and which must be non-null (AC-2). Every slice after create reads it through one helper (`DocumentBranch::of($locationId)`), never from the caller. List scoping filters `location_id IN (locations of allowed branches)` | `UpdateLocationHandler` docblock line 21 |
| H-9 | `GRN` / `PRN` prefixes | `DEFAULT_PREFIXES` gains `'grn' => 'GRN'` and `'purchase_return' => 'PRN'` (gives `GRN000001`, `PRN000001`, 9 characters, under the 30-character cap). Sequence types are the strings `grn` and `purchase_return` | `packages/organization/src/Numbering/DatabaseDocumentNumbers.php` line 64 |

## 0.1 Rulings

**Ruling 1: placement.** Everything goes into `packages/purchasing` (`docs/architecture.md` module table puts goods receipts and returns in Purchasing). No new package, no new deptrac layer: `Package_Purchasing` still reaches `Package_Kernel` and `Package_Contracts` only; `Contracts\Inventory` and the new `Contracts\Catalog\BaseQuantityConverter` are contracts. Two edits outside the package: the converter in catalog (H-5) and two prefixes in organization (H-9).

**Ruling 2: slices.** 18, flat under `src/Features/`, each with its mirrored test folder (`VerticalSliceLayoutTest`):

| Area | Slices | ACs |
|---|---|---|
| Receipts | `CreateGoodsReceipt`, `UpdateGoodsReceipt`, `AddGoodsReceiptLine`, `UpdateGoodsReceiptLine`, `RemoveGoodsReceiptLine`, `PostGoodsReceipt`, `CancelGoodsReceipt`, `GetGoodsReceipt`, `ListGoodsReceipts` | 1 to 36 |
| Returns | `CreatePurchaseReturn`, `UpdatePurchaseReturn`, `AddPurchaseReturnLine`, `UpdatePurchaseReturnLine`, `RemovePurchaseReturnLine`, `PostPurchaseReturn`, `CancelPurchaseReturn`, `GetPurchaseReturn`, `ListPurchaseReturns` | 37 to 49 |

Every slice checks its own key through `Authorise::require` at the document's branch (H-8; at create, the location's branch) and writes one audit row per real write, subject type `grn` or `purchase_return`, line writes recorded against the header (T-034 D-23).

**Ruling 3: the line unit rule** (`Documents/ReceiptUnits`, AC-8, AC-9, AC-40).
- Receipt line, `order_item_id` given and `unit_id` = the order line's `unit_id`: `snapshotInherited(product, orderLine.unit_id, orderLine.unit_factor, unit_id, quantity)`.
- Receipt line otherwise (no order line, or another unit, including the stock unit): `snapshot(product, unit_id, quantity)`.
- Free: H-6, always against the line's own resolved `unit_id` and `unit_factor`.
- Return line with `grn_item_id`: `snapshotInherited(product, grnLine.unit_id, grnLine.unit_factor, unit_id, quantity)` (own unit or stock unit; else `inherited_unit_mismatch`). Without: `snapshot()`.
- Refusals: `UnitRefusal::InheritedUnitMismatch` -> `inherited_unit_mismatch`, any other `UnitResolutionRefused` -> `unit_unresolvable`, cause carried.
- After rounding, `base_quantity + base_free = 0` is `validation` at entry as well as at post (AC-6, AC-22).
- `received_quantity_delta` for the order: `BaseQuantityConverter::toUnit(line.base_quantity, orderLine.unit_factor)` (H-5). Pure, no query, so calling it under the post's locks takes nothing (AC-55's "before any lock" concerns reads).

**Ruling 4: money** (`Documents/ReceiptMoney`, pure; AC-12, AC-13, AC-23, AC-24, AC-25, AC-29, AC-32).
- Line: `PurchaseLinePricing::quotePurchaseLine(quantity = paid quantity, unitCost, discountPercent "0", taxGroupId, baseQuantity = paid base, documentDate)`; store `tax_amount = tax.total`, `line_total`. Ex-tax = `line_total - tax_amount`. **Fallback** if step 0 finds the DTO refuses a zero quantity: a line with paid quantity zero is not quoted and stores `tax_amount 0.0000`, `line_total 0.0000` (no arithmetic happens), recorded as a deviation.
- Header: `subtotal = sum(ex-tax)`, `tax_total = sum(tax_amount)`, `total = sum(line_total) + other_charges`; an overflow refuses the line that caused it (`out_of_range`).
- Apportionment at post: `share_i = Money::share(other_charges, exTax_i, sum exTax)` = `ROUND(other_charges x exTax_i / sum, 4)` half-up; the remainder `other_charges - sum(share)` goes to the line with the largest ex-tax (lowest `line_no` on a tie). `other_charges > 0` and `sum exTax = 0` is `charges_unapportionable`.
- Inventory money `= exTax + share`. `landed_cost = Money::quotient(inventoryMoney, quantity)` (`ROUND(./., 4)`), or `0.0000` when paid quantity is zero.
- Ledger money `= Money::convert(inventoryMoney, exchange_rate)` = `ROUND(m x r, 4)` half-up once.
- Price history `unit_cost = Money::quotient(exTax, base_quantity)`; written only when paid base > 0.
- Variance per base `= quotient(exTax, base_quantity) - quotient(orderLine.subtotal - orderLine.discount_amount, orderLine.base_quantity)`; null when either base is zero **or the receipt's currency differs from the order's** (Q-3 to `ba`).
- All five helpers live in `Support/Money.php` (T-034's rule (b): no `*`, `/`, `multipliedBy(`, `dividedBy(` elsewhere). **Pricing-scan note for the reviewer:** `QaPricingSourceRulesTest` AC-25(b) matches any multiplication beside an identifier ending in `rate` or `percent`. Converting document money at the exchange rate is a currency conversion, not tax or discount arithmetic, which is what that rule protects; so it is not exempted, and `Money::convert(string $amount, string $multiplier)` names its parameter accordingly. Rule (b) of `QaPurchasingSourceRulesTest` is extended to pin `Money::convert(` to exactly one caller (`ReceiptMoney`), so the name cannot become a general escape hatch.

Hand-checked worked examples (each becomes a dataset row): AC-12 10 x 4800.0000, 0 % -> `48000.0000`/`0.0000`. AC-13 `48110.5000`/`10.0000`/`48320.5000`. AC-24 shares `480.0000`/`120.0000`, inventory `48480.0000`/`12120.0000`, `landed_cost` 48480/10 = `4848.0000`. AC-25 money 48000 x 1 -> command `unit_cost 48000.0000`, `cost_quantity 1`, quantity 240 + 48 = `288.0000`; the ledger gives value `48000.0000`, `avg_cost 166.6667`. AC-29 48000/240 = `200.0000`. **AC-32's example is arithmetically inconsistent** (Q-1 to `ba`): it states "10 cases at 4800 (base 240, 20.0000 per base)", but 10 x 4800 / 240 = **200.0000** per base, and 240 singles at 20.0000 (4800 / 240 = 20.0000) would then give **-180.0000**, not `0.0000`. The tests use corrected figures: order 10 cases at **480.0000** (4800 / 240 = 20.0000 per base), received 240 singles at 20.0000 -> `0.0000`, at 21.0000 -> `1.0000`.

**Ruling 5: posting a receipt** (`PostGoodsReceipt`, AC-19 to AC-33, AC-55). Outermost `transaction(..., 2)` (deadlock retry is safe: every downstream key replays), nested when a caller already opened one (no retry then). Before the transaction: permission (at the derived branch), input validation. Inside, in this order:
1. `grns` header `FOR UPDATE` (first statement). `posted` -> return it, `replayed = true`, nothing else (AC-20); `cancelled` -> `invalid_transition`.
2. Lines by `line_no`; none -> `no_lines`.
3. Re-check (AC-21), plain reads: location eligible (H-8 rule, `unknown_location`), each product purchasable and `tracking_mode = quantity`. Unit quads are **not** re-resolved.
4. Pure: AC-22 per line, AC-23, apportionment, landed cost, ledger money.
5. If `order_id`: `purchase_orders` header `FOR UPDATE`; status in `approved`, `partially_received`, `received` else `order_not_open`. Then, per line with `order_item_id` and paid base > 0, in `line_no` order: read the order line's current `received_base_quantity` (this is `order_received_base_before`, stored on the line); call `ApplyReceivedQuantity` (H-3). A refusal -> `order_application_refused` (cause = the inner `PurchasingRefusal`, previous = the exception).
6. `StockLedger::postBatch` with one `grn` command per line: `location_id`, `product_id`, `type grn`, `quantity = Money::add(base_quantity, base_free)`, `unit_cost` = ledger money, `cost_quantity "1"`, `reference_type grn`, `reference_id` = receipt id, `reference_line_id` = line id, `employee_id` = actor. No other key; never `already_happened` (AC-62). `StockLedgerRefused` -> `ledger_refused` (cause `StockRefusal`, detail names the line from `commandIndex`, plus `onHand` when present). Any posting with `replayed = true` -> `ledger_inconsistent` naming the line (AC-26).
7. Update each line: `landed_cost`, `order_received_base_before`. Insert price-history rows (AC-29).
8. Header: `status posted`, `posted_at` (server time, `RunnerClock`), `posted_by`, `updated_by`.
9. One audit row `purchasing_receipt_posted`; one outbox event `goods_receipt.posted` (`NewEvent(type, 'grn', id, payload, branchId: derived branch)`), payload exactly AC-30, `ledger_value` = each posting's `value`, `price_variance_per_base` per Ruling 4.

**Ruling 6: posting a return** (`PostPurchaseReturn`, AC-43 to AC-47).
1. `purchase_returns` header `FOR UPDATE`; `posted` -> replay; `cancelled` -> `invalid_transition`. 2. Lines; none -> `no_lines`. 3. Re-check location and products.
4. Referenced `grn_items` rows `FOR UPDATE`, ascending id. For each referenced receipt line: `remaining = base_quantity + base_free - sum(base_quantity of posted return lines naming it)`; the sum over **this** return's lines naming it must be `<= remaining`, else `over_return` naming the line and `remaining` (AC-44). Draft returns reserve nothing. Direct lines are not capped.
5. `postBatch` with one `purchase_return` command per line: quantity = `base_quantity`, **no cost key**, references, `employee_id`. Refusal -> `ledger_refused` (AC-45 carries `insufficient_stock` and `onHand`); `replayed = true` -> `ledger_inconsistent`.
6. Header posted; one audit row; one `purchase_return.posted` event (AC-46), `ledger_value` = each posting's signed `value`. **No order call** (AC-47); a static row proves `PostPurchaseReturn` never references `ApplyReceivedQuantity`.

**Ruling 7: lock order** (AC-55; extends T-034 Ruling 7; one global order, every writer takes the subset it needs, never in reverse):

| # | Row | Taken by | How |
|---|---|---|---|
| 1 | own document header (`grns` / `purchase_returns`) | every receipt or return write | `FOR UPDATE`, first statement |
| 2 | referenced receipt: `grns` header (return create and header edit naming a receipt); `grn_items` (return post) | return slices | `sharedLock()` on the header; `FOR UPDATE` on items, ascending id |
| 3 | `purchase_orders` header | receipt create / header edit naming an order: `sharedLock()`; receipt post: `FOR UPDATE`, then `ApplyReceivedQuantity` re-locks the same row | T-034 Ruling 7 row 1 |
| 4 | `suppliers` | create and header edits naming a supplier | `sharedLock()` (T-034 D-22) |
| 5 | stock ledger: `products`, `locations`, `employees` shared, then `stock_levels` `FOR UPDATE` ascending | `StockLedger::postBatch` inside the post | T-031 Ruling 6 |
| 6 | `number_sequences` | `DocumentNumbers::next()` at create only | **last** |

Then price history, header update, audit, outbox. Why levels after the order header: `stock_levels` rows are the hottest rows in the tenant (every sale locks them), so they are taken last and held for the shortest tail; no writer locks levels and then a purchasing header (sales lock levels then `customers`, §12.1), so no cycle. Unit resolution, pricing, permission and tax-group reads all happen before row 1. **[ENGINE; NOT VERIFIED]** for the run-time half; the statement order is pinned on the stand-in with `LockMarkingGrammar` (pricing test support, as T-034).

**Ruling 8: idempotency** (AC-52, D-5). Creates and line adds replay by the caller's ULID (folded upper case): identical normalised payload returns the stored row with `replayed = true` (no number, audit, event); otherwise `replay_conflict` naming the id. Posts are idempotent by outcome (Ruling 5.1, 6.1). Cancel of `cancelled` is a no-op success; cancel of `posted` is `receipt_already_posted` (both documents, AC-18, AC-42).

**Ruling 9: the order read figures** (AC-11, AC-32). `GetGoodsReceipt` and every receipt result expose per linked line `ordered_base_quantity` (order line `base_quantity`, immutable once received), `received_base_quantity_before` (posted: the stored `order_received_base_before`; draft: the order line's current `received_base_quantity` plus earlier lines of this draft naming the same order line), `over_received` (`before + base_quantity > ordered`, compared on 4-place strings) and `price_variance_per_base`. Storing `order_received_base_before` at post is an **undocumented addition** (AC-36: a posted read must not move when later receipts arrive); recorded in As built.

**Ruling 10: currency defaults.** Receipt (AC-1): explicit, else the order's, else the supplier's, else the branch's; `currency_required` only if the branch has none. Return (AC-38): explicit, else the receipt's (rate too), else the supplier's, else the branch's (Q-4 to `ba`).

**Ruling 11: dates** (AC-4, D-9). `received_date`: strict `Y-m-d`, a real date, `<= BusinessClock::businessDate(branch.timezone)`, and with an order `>= order.order_date`. `return_date`: the same, and with a receipt `>= grn.received_date` (Q-2 to `ba`). A date change in a draft re-quotes every line (pricing is dated).

---

## Approach summary

1. Eighteen vertical slices in `packages/purchasing` build goods receipts and purchase returns: draft, edit, post, cancel, read. Five new tenant tables, expand-only.
2. Every unit quad and every base figure comes from the unit code (`snapshot()`, `snapshotInherited()`, and one new pure operation `BaseQuantityConverter::toUnit()` for the order's display delta). Every line amount comes from `PurchaseLinePricing`. Every number from `DocumentNumbers` (`GRN`, `PRN`).
3. Posting a receipt is one transaction: receipt header lock, order header lock and `ApplyReceivedQuantity` per linked line, one `StockLedger::postBatch` carrying exact line money (`cost_quantity 1`), price history, status, one audit row, one `goods_receipt.posted` event carrying the values the ledger returned.
4. Posting a return locks the receipt lines it draws on, enforces the over-return cap, posts `purchase_return` movements at the ledger's average cost, and never touches the order.
5. Purchasing's own arithmetic is sums, apportionment, per-unit quotients and one currency conversion, all in `Support/Money.php`.
6. Built in an order that lets chunks A to E proceed before the stock ledger table exists; chunk F waits for FP-179.

## Alternatives rejected

- **A new method on `UnitResolver`** for the base-to-unit delta: breaks two anonymous test doubles; a separate interface costs nothing.
- **Computing `received_quantity_delta` as `quantity` when the units match, and only dividing otherwise:** two code paths for one figure; the converter is exact in the matching case anyway (H-5).
- **Deriving `base_free` on read:** a factor multiplication in purchasing or a resolver call per read.
- **Storing `branch_id` on `grns`:** contradicts the spec's FK list and the documented table; the location's branch is immutable.
- **`cost_quantity` = paid quantity with `unit_cost` = `landed_cost`:** re-multiplies a rounded per-unit figure (the 99.9999 case, T-031 AC-26).
- **Ledger before the order rollup:** holds hot `stock_levels` locks longer.
- **Building `Contracts\Inventory` here when T-031 is late:** it is T-031's file; a second author of a published contract is a merge hazard.
- **A price-history read slice:** not asked; AC-58's price-history row is proven at table level (Q-6).

## Files to change / create

`dev` writes every file; `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Catalog/` (new file)
- `BaseQuantityConverter.php`: interface, `toUnit(string $baseQuantity, string $unitFactor): string`, `@throws UnitResolutionRefused`; docblock: inverse of `snapshot()`'s step 3 on a frozen factor, never walks the ladder, half-up at 4, display figures only (the base figure stays the authority).

### `packages/catalog`
- `src/Units/BaseInUnit.php` (new, pure, `brick/math`): implements the interface; canonicalises the factor with the same rules `InheritedFactor` applies to a referenced factor (reuse its helper if it is a static method; otherwise a private copy within `Units/`); range check as `SnapshotCalculator::checkRange`.
- `src/CatalogServiceProvider.php`: `singleton(BaseQuantityConverter::class, BaseInUnit::class)`.
- `tests/Units/BaseInUnitTest.php` (new).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php`: two prefixes. `tests/Numbering/DocumentNumbersTest.php`: one row (`GRN000001`, `PRN000001`, independent sequences).

### `packages/purchasing`

| Path | What |
|---|---|
| `src/Documents/{ReceiptStatus,ReceiptUnits,ReceiptMoney,ReceiptTotals,ReturnCap,DocumentBranch,ReceiptLineQuoter,ReceiptView,ReturnView,ReceiptEvents,ReceiptLocks}.php` | Rulings 3 to 9; `ReceiptLineQuoter` only if T-034's `LineQuoter` cannot take a date (P.3 item 5) |
| `src/Support/Money.php` | add `share`, `quotient`, `convert` (Ruling 4) |
| `src/Exceptions/PurchasingRefusal.php` | 10 new codes (Data section) |
| `src/Registry/{PurchasingAuditActions,PurchasingPermissions}.php` | 14 actions, 6 keys |
| `src/Features/<18 slices>/…` | Ruling 2 |
| `tests/Support/{FakeStockLedger,ReceiptSeed,receipt-fixture}.php` | the ledger double (P.2); seeds on top of T-034's `PurchasingSeed` (an approved order with lines) |
| `tests/Schema/ReceiptsTenantSchemaTest.php` | step 3 |
| `tests/Documents/…Test.php` | pure helpers |
| `tests/Features/<X>/<X>Test.php` | one folder per slice |
| `tests/Invariants/ReceiptsInvariantTest.php` | step 17 |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/<D>_003901_create_grns_table.php` … `<D>_003905_create_purchase_price_history_table.php` | `<D>` is chosen at step 0 so the five sort after every tenant migration in the merged tree (provisionally `2026_11_17`). `@phase expand`, new tables with keys inline, `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) statuses, full decimal arguments, no `down()`, docblock naming every deferred column. **No migration, docblock or test names its catalogue version** |
| `tests/Feature/QaPurchasingSourceRulesTest.php` | edited (step 18) |
| `tests/Engine/ReceiptsEngineTest.php` | step 19, skipped |
| shared files | section "Shared test edits" |

## Data / API changes

**Five new tenant tables; no change to any existing table; no master change.** Money and quantity `DECIMAL(18,4)` unless stated; ids `CHAR(26)` ascii binary; statuses VARCHAR(30) with application enums (`draft`, `posted`, `cancelled`).

| Table | Columns | Keys | Deferred (absent) |
|---|---|---|---|
| `grns` | `id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `number` VARCHAR(30), `supplier_id`, `order_id` NULL, `location_id`, `received_date` DATE, `status`, `supplier_dn_no` VARCHAR(60) NULL, `currency` CHAR(3), `exchange_rate` (18,8) default 1, `subtotal`, `tax_total`, `other_charges`, `total` (default 0), `posted_at` DATETIME(3) NULL, `posted_by` NULL, `notes` TEXT NULL | unique `number`; index (`supplier_id`, `status`), `order_id`, (`location_id`, `received_date`), `status`; FKs `supplier_id`, `order_id`, `location_id`, `created_by`, `updated_by`, `posted_by` (6) | `[GL]` journal link, `branch_id` (H-8) |
| `grn_items` | `id`, `grn_id` (**cascade**), `line_no` SMALLINT UNSIGNED, `order_item_id` NULL, `product_id`, `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `free_quantity` NOT NULL default 0, `base_free` NOT NULL default 0, `unit_cost`, `landed_cost` NULL, `tax_group_id`, `tax_amount`, `line_total`, `order_received_base_before` NULL, `notes` VARCHAR(500) NULL | unique (`grn_id`, `line_no`); index `product_id`, `order_item_id`; FKs `grn_id`, `order_item_id`, `product_id`, `unit_id`, `tax_group_id` (5) | `batch_no`, `expiry_date`, `mfg_date`, `serial_numbers` (FP-252) |
| `purchase_returns` | `id`, TS, `created_by`, `updated_by`, `number` VARCHAR(30), `supplier_id`, `grn_id` NULL, `location_id`, `return_date` DATE, `reason` VARCHAR(500), `status`, `debit_note_no` VARCHAR(60) NULL, `currency` CHAR(3), `exchange_rate` (18,8) default 1, `subtotal`, `tax_total`, `total`, `posted_at` NULL, `posted_by` NULL, `notes` TEXT NULL | unique `number`; index (`supplier_id`, `status`), `grn_id`, (`location_id`, `return_date`), `status`; FKs `supplier_id`, `grn_id`, `location_id`, `created_by`, `updated_by`, `posted_by` (6) | `[GL]`, AP debit-note link (FP-265) |
| `purchase_return_items` | `id`, `return_id` (**cascade**), `line_no`, `grn_item_id` NULL, `product_id`, `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `unit_cost`, `tax_group_id`, `tax_amount`, `line_total` | unique (`return_id`, `line_no`); index `product_id`, `grn_item_id`; FKs `return_id`, `grn_item_id`, `product_id`, `unit_id`, `tax_group_id` (5) | `batch_id` (FP-252) |
| `purchase_price_history` | `id` CHAR(26) (server ULID, AC-57; the documented `BIGINT AUTO` is superseded by AC-57), `product_id`, `supplier_id`, `grn_item_id` NULL, `unit_cost`, `currency` CHAR(3), `quantity`, `effective_date` DATE, `source` VARCHAR(20), `created_at` DATETIME(3) | **unique `grn_item_id`** (backstop: one row per receipt line; several NULLs allowed for later sources); index (`product_id`, `effective_date`); FKs `product_id`, `supplier_id`, `grn_item_id` (3) | any unit column |

- **25 foreign keys**, all `RESTRICT` except the two line cascades. Migration order 01 to 05 is forced by the keys.
- `line_no` and `order_received_base_before` are additions to `docs/database.md` (T-034's `line_no` precedent; Ruling 9). `base_free` is stored (H-7).
- **Precision map:** (18,4): `grns.subtotal`, `tax_total`, `other_charges`, `total`; `grn_items.quantity`, `base_quantity`, `free_quantity`, `base_free`, `unit_cost`, `landed_cost`, `tax_amount`, `line_total`, `order_received_base_before`; `purchase_returns.subtotal`, `tax_total`, `total`; `purchase_return_items.quantity`, `base_quantity`, `unit_cost`, `tax_amount`, `line_total`; `purchase_price_history.unit_cost`, `quantity`. (18,6): both `unit_factor`. (18,8): both `exchange_rate`.
- **Versions:** five consecutive expand steps on top of whatever the merged tree's top is (call it N): top becomes N+5, tenant tables T+5. The catalogue fixture is regenerated with `php artisan schema:publish --write-fixture` only (never the master-writing form under the hold), never hand-merged.

**Refusal codes added to `PurchasingRefusal` (32 -> 42):** `order_item_mismatch`, `product_mismatch`, `receipt_not_posted`, `over_return`, `tracking_not_supported`, `charges_unapportionable`, `ledger_refused` (cause `StockRefusal`), `ledger_inconsistent`, `receipt_already_posted`, `order_application_refused` (cause the inner `PurchasingRefusal`). `supplier_mismatch` and the rest of the spec's list already exist.

**Audit actions (28 -> 42):** the 14 of the spec preamble. **Permissions (8 -> 14):** the six of AC-50, all branch-scoped. **Outbox:** `goods_receipt.posted`, `purchase_return.posted` (aggregate types `grn`, `purchase_return`), plus T-034's `purchase_order.received_applied` per application, written by `ApplyReceivedQuantity`.

**New contract:** `Contracts\Catalog\BaseQuantityConverter` (H-5). **HTTP, console, jobs, hooks:** none. **Dependencies:** none new.

## Implementation steps

House rules carried from T-034: red-first; the red-list rule after each schema step (record red, compare with "Shared test edits", stop on anything else); unique test basenames (prefix `Receipt…`/`Return…`, Pest helpers prefixed `gr`, constants `GR_`); every test file namespaced `Flexipos\Purchasing\Tests\…`; never write the bare non-stock product type string in an array (the vocabulary sweep); mutations run once and reverted byte for byte, counts in the hand-back. Every refusal row compares purchasing's tables, `audit_logs`, `outbox_events`, `number_sequences`, `purchase_order_items`, `purchase_order_receipt_applications` (and, in chunk F, `stock_levels`, `stock_movements`) whole before and after.

### Chunk A: verification, converter, prefixes, schema, statics (needs T-034 merged)

- [ ] **Step 0: verify.** Confirm T-034 is merged (and T-031's contract or more, recording which). Re-verify P.3 items 1 to 21; record differences only. Choose `<D>`. Record the merged tree's top version N, table count T, FK count. Raise R-34a, R-34b, R-34e findings to `lead` immediately if they fail.
- [ ] **Step 1: `BaseQuantityConverter`.** *(AC-27's unit half; H-5.)* Contract, `BaseInUnit`, binding. `BaseInUnitTest` rows: 120.0000 / 24.000000 -> `5.0000`; 1 / 1000.000000 -> `0.0010`; 0.0001 / 24 -> `0.0000` (the D-47 zero case); 100 / 3 -> `33.3333`; 0.0002 / 3 rounds half-up; negative base `-120` -> `-5.0000`; factor `"24"` (driver form) accepted; factor `0.5`, `abc`, 13 integer digits refused with the `UnitRefusal` cases; base with 5 decimals or a float refused; round-trip dataset `toUnit(ROUND(q x f, 4), f) = q` over a grid of `q` (4-place) and `f` (1, 3, 7, 12, 24, 1000, 1.5); no Illuminate import. `QaCatalogSourceRulesTest` stays green (the file is inside `Units/`; contracts purity holds). **Targeted:** the new test, `QaCatalogSourceRulesTest`.
- [ ] **Step 2: prefixes.** *(H-9, AC-1, AC-37 numbering.)* `DocumentNumbersTest` row. **Targeted:** that file.
- [ ] **Step 3: migrations 01 to 05 with `ReceiptsTenantSchemaTest`.** *(AC-57, schema half of AC-58.)* Sorted column lists equal the Data section with nullability (proves every deferred absence); `free_quantity`, `base_free` NOT NULL default 0; unique keys by raw double insert (`number` x2, (`header`, `line_no`) x2, `purchase_price_history.grn_item_id`); FK set equals the 25; both cascades delete lines; deleting a referenced supplier, order, order line, receipt or receipt line is refused; `SourceRules::inspect()` reports nothing; no `down()`; the five catalogue rows are consecutive, `expand`, not destructive, versions derived through `MigrationSet::baseline(...)->versionOf()`, never literals. Then the shared edits of this step (below). **Predicted red until edited:** `Helpers.php`, `MigrationTreeDisciplineTest`, `TenantBaselineBuildsTest`, `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`, `IdempotencyKeysSchemaTest`, `SchemaCataloguePublishTest` (via `.env.example`). Expected green with no edit: `BranchForeignKeysContractRunTest`, `LoyaltySaleIdMigrationsTest` (relaxed by T-032/T-034; re-check), every T-034 schema test. **Targeted:** those files plus the new schema test.
- [ ] **Step 4: statics.** *(AC-57.)* `TenantDecimalPrecisionTest` gains the T-039 map; red-first with a fixture `decimal('total', 18, 2)`. `t039DeferredColumns()`: no T-039 migration declares `journal_entry_id`, `batch_no`, `expiry_date`, `mfg_date`, `serial_numbers`, `batch_id`, `ap_document_id`, `branch_id`, `tenant_id`, and `purchase_price_history` declares no `unit_id`, `unit_factor`, `base_quantity`; red-first with a scratch migration. `UomColumnSetsTest`: add `'free_quantity' => 'base_free'` to the `grn_items` row (now non-vacuous for `grn_items` and `purchase_return_items`). **Gate: `lead`.**

### Chunk B: building blocks (needs T-034)

- [ ] **Step 5: refusals, registry, money helpers.** 10 codes, 14 actions, 6 keys; `PurchasingRegistryTest` (T-034's) updated to 42 / 42 / 14 with the new names pinned; `QaAccessHttpSurfaceTest` key list. `Money::share`, `quotient`, `convert` in `PurchasingMoneyTest`: half-up, never `-0.0000`, overflow detection, no float. **Targeted:** the registry, money and HTTP-surface tests; `AuditCatalogueTest` (expected green).
- [ ] **Step 6: pure helpers.** `ReceiptStatus` (draft -> posted | cancelled; every other pair `invalid_transition`; posted cancel `receipt_already_posted`); `ReceiptMoney` (AC-12, AC-13, AC-24 worked examples; remainder rule with a three-way split of 100.0000 over 1/1/1 giving `33.3334/33.3333/33.3333`, tie to the earliest line; `charges_unapportionable`; free-only lines; landed cost zero quantity; AC-25 ledger money at rate 1 and at `0.00512345` rounded once; AC-29; AC-32 with the corrected figures of Ruling 4, null on zero base and on currency mismatch); `ReceiptTotals` (overflow refuses the causing line); `ReturnCap` (exact limit accepted, one over refused naming the remainder, two lines of one return naming one receipt line summed, free units count towards the cap).
  - **Mutations:** M1 remainder to the last line (tie row red); M2 apportion by `line_total` instead of ex-tax (inclusive-group row red); M3 ledger money from `landed_cost x quantity` (AC-26-style 1/3 row red: `99.9999`); M4 cap ignores `base_free` (free-units row red).
- [ ] **Step 7: line resolution and quoting.** `ReceiptUnits` (Ruling 3) and the quoter, against the real resolver and pricing engine on `PricingSeed`: same unit as the order line after the ladder moved keeps the order factor (AC-8b); cartons against cases resolves fresh (AC-8a); stock unit against cases is fresh with factor 1; free in cases at factor 24 gives `base_free 48.0000`; a free quantity whose base is fractional on a precision-0 stock unit is `unit_unresolvable` (AC-9); return line in the receipt unit inherits, in the stock unit is factor 1, in a third unit `inherited_unit_mismatch` (AC-40); tax chain rows (AC-14: explicit, product purchase group, default, `no_default_tax_group`, unknown, inactive; the order line's group is **not** inherited); the return line takes the receipt line's group (AC-41). Query count for one receipt line with and without free units recorded (AC-61). **Gate: `lead`.**

### Chunk C: receipt drafts (needs T-034)

- [ ] **Step 8: `CreateGoodsReceipt`, `UpdateGoodsReceipt`.** *(AC-1 to AC-5, AC-17, AC-50 to AC-53.)* Outermost `transaction(..., 2)`, locks per Ruling 7 (order shared, supplier shared, number last). Defaults (currency chain, rate `1.00000000`, charges `0.0000`, totals zero, `posted_*` null, audit carries the derived branch). AC-2 location dataset (missing, deleted, inactive, not purchasable-to, branch-less) and supplier rows (unknown, deleted, inactive without order refused, inactive **with** order accepted). AC-3 order dataset over every T-034 status (`draft`, `pending_approval`, `sent`, `closed`, `cancelled` refused `order_not_open`; `approved`, `partially_received`, `received` accepted), `supplier_mismatch`, location not the order's. AC-4 date rows (missing, `2026-02-30`, tomorrow in the branch zone at a UTC instant where UTC is still today, before `order_date`). AC-5 boundary rows. AC-17 `order_id`/`supplier_id`/`location_id` edit with lines `locked` naming the field, without lines revalidated. Every refusal consumes no number (`number_sequences` compared whole). Replay identical / different (AC-52). `DeadlockingNumbers` rows (T-034 test support).
- [ ] **Step 9: `AddGoodsReceiptLine`, `UpdateGoodsReceiptLine`, `RemoveGoodsReceiptLine`.** *(AC-6 to AC-10, AC-12 to AC-16.)* AC-7 product dataset (unknown, inactive, deleted, not purchasable per D-39, batch and serial tracking `tracking_not_supported`), `batch_no`/`expiry_date`/`mfg_date`/`serial_numbers` each `validation` naming the field; both quantities zero `validation`; AC-10 `order_item_mismatch` (unknown line, another order's line, no order on the receipt), `product_mismatch`; two lines naming one order line accepted; totals re-quoted after each add/edit/remove; `landed_cost` null on a draft (AC-15); a `received_date` edit re-quotes every line; 201st line `too_many_lines`; removal before-image in the audit row; JSON float in any money or quantity field `validation`; line replay.
- [ ] **Step 10: `CancelGoodsReceipt`, `GetGoodsReceipt`, `ListGoodsReceipts`.** *(AC-11 draft half, AC-18, AC-32 draft half, AC-34, AC-35, AC-50.)* Cancel: one audit row, second cancel no-op, no order, ledger or event effect. Get: every field, money as 4-place strings, AC-11 and AC-32 figures on a draft. List: keyset `before_id`, default 25, clamp 100, filters status, supplier, order, location, `received_date` range (inverted range `validation`), branch scoping per T-034 AC-35 (branch-A grant sees only A; company-wide sees all; inactive and deleted branches decided). AC-50: one refusal row per receipt slice (including reads) without the key, branch-A grant at B, company-wide accepted, no-branch ask `BranchRequired`; AC-51 suspended / inactive / deleted employee rows. **Gate: `lead`.**

### Chunk D: return drafts (needs T-034; receipts are seeded posted by raw insert until chunk E)

- [ ] **Step 11: `CreatePurchaseReturn`, `UpdatePurchaseReturn`.** *(AC-37, AC-38, AC-42.)* `PRN` number; `reason` 1 to 500; `debit_note_no` 60; inactive supplier accepted, deleted `unknown_supplier`; with G: unknown `not_found`, draft or cancelled `receipt_not_posted`, other supplier `supplier_mismatch`, other location `unknown_location`; currency and rate default from G; direct return allowed; date rules (Ruling 11); no number consumed on refusal; replay.
- [ ] **Step 12: return lines.** *(AC-39 to AC-41, AC-42.)* Positive quantity only; `grn_item_id` on a return without G `validation`; a receipt line of another receipt `not_found`; other product `product_mismatch`; unit rows of step 7 end to end; tax from the receipt line; totals (no `other_charges`); 201st line; `locked` when not draft.
- [ ] **Step 13: `CancelPurchaseReturn`, `GetPurchaseReturn`, `ListPurchaseReturns`.** *(AC-42, AC-49, AC-50.)* As step 10 with the return key and filters (status, supplier, `grn_id`, location, `return_date` range). **Gate: `lead`.**

### Chunk E: posting against the published contract (needs T-034 and `Contracts\Inventory`)

- [ ] **Step 14: `PostGoodsReceipt` with `FakeStockLedger`.** *(AC-19 to AC-24, AC-26 to AC-31, AC-33, AC-62; the stand-in half of AC-55.)*
  - The command list equals exactly the expected arrays (keys, order, `cost_quantity "1"`, `unit_cost` = ledger money, quantity = base + free, references upper case, `employee_id`, no `already_happened`, no unit field).
  - AC-20 `no_lines`, replay of a posted receipt (`replayed = true`, the fake records zero calls, no audit, event or application), cancelled `invalid_transition`.
  - AC-21 re-check rows: product made non-purchasable, location deactivated, order closed between draft and post; a unit ladder edited between draft and post does not change the posted quad.
  - AC-22, AC-23 rows. AC-24 landed cost stored. AC-29 price history rows (none for a free-only line).
  - AC-27/AC-28 through the real `ApplyReceivedQuantity`: 5 cartons of 12 against 10 cases of 24 leaves `partially_received`, a second receipt of 120 singles makes it `received`; a free-only line applies nothing; the order delta for the cartons line is `5.0000`, for a 1-single line against a tonne-sized line `0.0000` accepted; applications carry the receipt line ids; `order_received_base_before` stored.
  - AC-26 `ledger_inconsistent` when the fake returns `replayed = true` for a draft; `order_application_refused` when an application row with the line id already exists (`replayed_for_draft`) and when T-034 refuses.
  - `ledger_refused` carrying each scripted `StockRefusal` (dataset over the 24 reasons), detail naming the line from `commandIndex`.
  - Rollback: a failure injected at each of steps 5 to 9 of Ruling 5 leaves receipt `draft`, order lines, applications, price history, audit and outbox unchanged.
  - AC-30 payload exactly (keys, 4-place strings, `ledger_value` from the posting, no name or address); AC-31 static row: the handler names no journal, `ap_`, `batches`, `serials` or `quantity_incoming` token. AC-33 a receipt at rate `0.00512345` sends converted money and stores document-currency figures.
  - Lock order pinned as SQL text with `LockMarkingGrammar`: first statement `grns … for update`, then `purchase_orders … for update`, then (in chunk F) the ledger's statements.
  - **Mutations:** P1 skip the posted-replay check (replay row red: a second application); P2 accept a replayed ledger posting (AC-26 row red); P3 apply free units to the order (AC-28 row red: the order closes early).
- [ ] **Step 15: `PostPurchaseReturn` with `FakeStockLedger`.** *(AC-43 to AC-48.)* Commands carry no cost key; quantity = `base_quantity`; AC-44 cap at the limit accepted, one ten-thousandth over refused naming the remainder, a second return after a first posted one, draft returns reserve nothing, direct lines uncapped; AC-45 `ledger_refused` with `insufficient_stock` and `onHand`, `ledger_inconsistent`, posted replay; AC-46 payload with the signed `ledger_value`; AC-47 order tables and applications unchanged; cancelled `invalid_transition`; rollback rows. **Mutation R1:** sum draft returns into the cap (draft-reserves-nothing row red). **Gate: `lead`.**

### Chunk F: the real ledger (needs T-031 through its chunk C, i.e. FP-179 passed)

- [ ] **Step 16: end-to-end posting through `StockLedger`.** *(AC-25, AC-26, AC-45, AC-61; the real-writer half of AC-19, AC-43.)* AC-25 worked example on an empty level: movement quantity `288.0000`, value `48000.0000`, `avg_cost 166.6667`, the movement's stored `unit_cost 166.6667`, event `ledger_value 48000.0000`; the AC-26 1/3 case (1 case of 3 at 100 landed) gives `total_value 100.0000`; AC-26 two lines of one product in two units give two movements and the summed level; a movement planted through the contract with a receipt line's key before posting makes the post `ledger_inconsistent` and rolls back everything; AC-45 at a location forbidding negative stock; a return's `ledger_value` equals `-ROUND(q x avg_cost, 4)`; a 50-line receipt is one `postBatch` and its query count is recorded with the one-line add count (AC-61).
- [ ] **Step 17: `ReceiptsInvariantTest`.** A scripted week through the real slices: an order received in three units over three receipts (one over-receipt), a direct receipt with other charges in a foreign currency, a free-goods-only receipt, a cancelled draft, a return against a receipt in the stock unit and a direct return. Assert: numbers gapless per type; one `purchasing_*` audit row per write plus one `purchasing_order_receipt_applied` per application, none per replay; every header total equals its lines; every order status equals T-034's `ReceiptRollup` over stored lines; `SUM(stock_movements.value)` for each receipt equals the sum of its event's `ledger_value`; every `base_free` equals `snapshotInherited()` recomputed from the stored row (AC-9); posted rows are byte-identical after a later ladder, tax-rate and supplier change (AC-36). **Gate: `lead`.**

### Chunk G: scans, engine, isolation, documents (rows that read the ledger need chunk F)

- [ ] **Step 18: `QaPurchasingSourceRulesTest` (edit).** *(AC-36, AC-48, AC-59.)* (a) remove `grns` from the forbidden-token list (now this package's table) keep every other token (`unit_conversions`, `stock_levels`, `stock_movements`, `journal_`, `quantity_incoming`, …), and add `ap_documents`, `batches`, `serials` (AC-31, AC-59); (b) extend the `Money` allow-list: `share`, `quotient`, `convert` callable only from `ReceiptMoney`, `Money::convert(` from exactly one call site; (c) **AC-36:** the files that name `grns`, `grn_items`, `purchase_returns` or `purchase_return_items` together with a write verb (`update(`, `delete(`, `insert(`, `upsert(`, `updateOrInsert(`, raw `UPDATE`/`DELETE`) are exactly the draft slices plus the two post handlers, and each of those files calls `ReceiptLocks::requireDraft(` before its first write; a planted file writing `grn_items` without the guard is red; (d) **AC-48:** no `grn_return` token anywhere in `packages/purchasing/src`; planted control; (e) `PostPurchaseReturn` names no `ApplyReceivedQuantity` (AC-47); (f) stock is reached only through `Contracts\Inventory\StockLedger` (no `Flexipos\Inventory\` import; deptrac proves the same). Red-first planted files, then deleted.
- [ ] **Step 19: `ReceiptsEngineTest`** (database `flexipos_t8test_t039_tenant`, created and dropped by **exact name** through `EngineDatabase`, never `LIKE`). Written and skipped under the hold except the hold-refusal case: two connections posting one receipt (one effect, the loser replays); two receipts for one order line (no lost update, final status matches sums); two return posts on one receipt line (cap holds); a receipt post and a sale on the same level (no deadlock, Ruling 7); first-use `GRN` numbering race (`transaction(..., 2)` retry); `information_schema` decimals equal the map; unique keys on the engine. *(AC-55, AC-56: **NOT VERIFIED**.)*
- [ ] **Step 20: cross-tenant rows on the stand-in.** *(AC-58.)* Receipt and return created in tenant A: every get and one write slice each in tenant B is `not_found`, nothing written in either; a price-history row written in A is absent from B's table (Q-6).
- [ ] **Step 21: documents.** Describe, never quote, old wording. `docs/database.md` §6.6 "As built (T-039)": the five tables, `line_no`, `order_received_base_before`, stored `base_free`, derived branch, the price-history id, the deferred columns, the state machine, the lock order (Ruling 7), the ledger shape (H-1) and why it differs from §6.5's table text; §6.1 the `GRN`/`PRN` prefixes; §6.3 `BaseQuantityConverter`. `docs/architecture.md` module table: what Purchasing now builds. Follow-ups to `lead`.
- [ ] **Step 22: final gate.** `lead` runs `composer check` and compares counts and `test` seconds with its own baseline (FP-328, more than 10 % on `test` is a stop). The hand-back lists AC-55, AC-56 and the engine rows as **NOT VERIFIED**, and any chunk F row as **BLOCKED on FP-179** if that gate is still closed.

## Shared test edits (hard-coded tests a new migration or module must keep in sync)

| File | Edit | Step |
|---|---|---|
| `packages/organization/tests/Numbering/DocumentNumbersTest.php` | `GRN`/`PRN` row | 2 |
| `modules/host/tests/Helpers.php` `tenantTreeTables()` | append the five tables with a "T-039 step 3" comment | 3 |
| `modules/host/tests/Feature/MigrationTreeDisciplineTest.php` | table count T -> T+5 with a T-039 comment | 3 |
| `modules/host/tests/Feature/IdempotencyKeysSchemaTest.php` (the one pinned top) | top N -> N+5, file `<D>_003905_create_purchase_price_history_table`, `expand` | 3 |
| `modules/host/tests/Feature/TenantBaselineBuildsTest.php` | the 25 FKs sorted into the inventory; branch-key list **unchanged** (no `branch_id`) | 3 |
| `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | `$t039 = ['grn_items', 'grns', 'purchase_price_history', 'purchase_return_items', 'purchase_returns'];` appended to the `array_diff` | 3 |
| `modules/host/database/catalogue/tenant_schema_versions.json`, `modules/host/.env.example` | regenerated with `schema:publish --write-fixture`; `APP_REQUIRED_SCHEMA_VERSION` N -> N+5 (never `.env`) | 3 |
| `modules/host/tests/Feature/TenantDecimalPrecisionTest.php` | T-039 map and `t039DeferredColumns()` | 4 |
| `modules/host/tests/Feature/UomColumnSetsTest.php` | `grn_items` pairs gain `'free_quantity' => 'base_free'` | 4 |
| `packages/purchasing/tests/Registry/PurchasingRegistryTest.php` | 42 codes, 42 actions, 14 keys | 5 |
| `modules/host/tests/Feature/QaAccessHttpSurfaceTest.php` `accessBranchScopedKeys()` | six keys sorted in: `purchasing.receipts.{manage,post,view}` after `purchasing.orders.view`, `purchasing.returns.{manage,post,view}` after `purchasing.requests.view` | 5 |
| `modules/host/tests/Feature/QaPurchasingSourceRulesTest.php` | step 18 | 18 |
| `SchemaCataloguePublishTest`, `LoyaltySaleIdMigrationsTest`, `BranchForeignKeysContractRunTest`, `AuditCatalogueTest`, `QaToolchainRegistryCoverageTest`, `VerticalSliceLayoutTest`, `QaPricingSourceRulesTest`, `QaCatalogSourceRulesTest`, T-031's `QaInventorySourceRulesTest`, the cross-tenant sweeps, every T-034 test | **expected green with no edit**; red on any is a stop | 1 to 18 |

Anything red outside this table: stop and report to `architect`.

## Test strategy

- **Unit (pure):** `BaseInUnit`, `ReceiptMoney`, `ReceiptTotals`, `ReturnCap`, `ReceiptStatus`, `Money` additions.
- **Integration (SQLite stand-in, real implementations):** one folder per slice with the real `UnitResolver`, `BaseQuantityConverter`, `PurchaseLinePricing`, `TaxGroupDirectory`, `DocumentNumbers`, `PermissionResolver`, `AuditTrail`, `Outbox`, `ApplyReceivedQuantity`; `FakeStockLedger` in chunk E, the real `StockLedger` in chunk F.
- **Static:** schema test, decimal map, deferred columns, `UomColumnSetsTest`, `QaPurchasingSourceRulesTest`, deptrac.
- **Engine:** `ReceiptsEngineTest`, skipped, NOT VERIFIED.

| ACs | Proved by step |
|---|---|
| 1 to 5, 17 | 8 |
| 6 to 10, 12 to 16 | 6, 7, 9 |
| 11, 32 | 6, 10 (draft), 14 (posted), 17 |
| 18 | 6, 10 |
| 19 to 24, 27 to 31, 33 | 6, 14, 16 |
| 25, 26 | 6, 14 (fake), 16 (real) |
| 34, 35 | 10 |
| 36 | 17, 18 |
| 37, 38 | 11 |
| 39 to 41 | 7, 12 |
| 42 | 6, 12, 13 |
| 43 to 47 | 6, 15, 16 |
| 48 | 18 |
| 49 | 13 |
| 50, 51 | 10, 13, every slice folder |
| 52, 53 | 8, 9, 11, 12 |
| 54 | every slice folder, 17 |
| 55, 56 | 14 (statement order), 19 (**NOT VERIFIED**) |
| 57 | 3, 4 |
| 58 | 3, 20 |
| 59 | 18, deptrac |
| 60 | every slice folder (malformed datasets) |
| 61 | 7, 16 (recorded counts) |
| 62 | 14 |

## Risks & rollback

1. **FP-179 (highest, scheduling).** Chunk F cannot run until the owner rules; A to E ship and F's ACs are reported blocked, not passed. If the ruling changes `stock_movements`' keys, only step 16's expectations move; the contract shape this task uses does not.
2. **T-034 and T-031 shapes are read from plans, not code.** Step 0 is a hard stop on any difference in P.3; R-34a, R-34b, R-34e are the likeliest gaps.
3. **Exchange-rate conversion beside the pricing scan** (Ruling 4). Not an exemption; a reviewer may still prefer a contract owner for currency conversion. Contained to one call site, pinned by step 18.
4. **Spec worked example AC-32 is arithmetically wrong** (Q-1); tests use corrected figures, so an unrevised spec and the tests disagree in text until `ba` amends it.
5. **Audit-row count** (Q-5): a post writes one receipt row plus T-034's one row per application. If `ba` insists on one row total, T-034's `ApplyReceivedQuantity` must change.
6. **NOT VERIFIED under `ENGINE_HOLD`:** lock order, deadlock freedom, concurrent posts, the cap under race, unique keys on the engine.
7. **Query cost:** 5 resolver queries per fresh line, 2 per free quantity, plus pricing; a 200-line receipt is about 1400 resolver queries at entry (FP-426). Measured, not capped.
8. **Large events:** a 200-line `goods_receipt.posted` payload is roughly 80 KB of JSON; acceptable for the outbox's JSON column, noted for FP-262.
9. **Merge renumbering:** all versions derive from the merged tree; only `IdempotencyKeysSchemaTest`, the table count and `.env.example` move, and they are recomputed, never hand-merged.

**Rollback:** everything is new except the converter, two prefixes and the shared test edits. Migrations are forward-only and run in no tenant outside this branch; rollback is abandoning the branch.

## Requirements this task places on T-031 and T-034 that their plans do not yet state

- **R-34a** `ApplyReceivedQuantity`'s result must expose `replayed` (implied by T-034 Ruling 5, not stated in its result shape).
- **R-34b** `PurchaseLineQuote` must accept `quantity` and `baseQuantity` of zero (T-034 never quotes a zero line; free-only receipt lines do). Fallback in Ruling 4 if not.
- **R-34c** `QaPurchasingSourceRulesTest` forbids the token `grns` in `packages/purchasing/src`; this task removes it (step 18). Its `Money::product(` allow-list must be extendable per helper.
- **R-34d** T-034's follow-up line says FP-255 "maintains `received_value`". This spec does not ask for it and forbids order writes outside `ApplyReceivedQuantity` (AC-59); not built here (Q-7).
- **R-34e** `LineQuoter` hard-wires `documentDate = order_date`; receipts and returns need their own date (a parameter, or this task's `ReceiptLineQuoter`).
- **R-34f** `ApplyReceivedQuantity` writes its own audit row and event per application; T-039's AC-54 wording assumes one audit row per write (Q-5).
- **R-31a** `StockPosting` and `StockLedgerRefused` must be publicly constructible (or T-031 publishes a fake) so purchasing can test against the contract before the ledger table exists.
- **R-31b** T-031's plan follow-up suggests FP-254's `grn` call use `cost_quantity` = paid line quantity; this task uses `cost_quantity "1"` with the exact line money (both legal; T-031's docblock should not prescribe the former).
- **R-31c** The `StockLedger` docblock states only "levels before `customers`"; T-039 adds "purchasing document headers before levels" to `docs/database.md` §12 (step 21). No T-031 change is required.

## Questions for `ba` (none blocking; the recommended answer is what is built)

- **Q-1 (AC-32 worked example).** 10 cases at 4800 is 48000, which is **200.0000** per base, not 20.0000; 240 singles at 20.0000 against it give **-180.0000**. Recommended amendment: "an order of 10 cases at 480.0000 (base 240, 20.0000 per base) received as 240 singles at 20.0000 gives `0.0000`; at 21.0000 gives `1.0000`." Built with those figures.
- **Q-2 (return date).** Unstated. Recommended: strict date, not after the branch's business today, and with a receipt not before its `received_date`.
- **Q-3 (variance across currencies).** A receipt may name a currency different from its order's; `price_variance_per_base` would subtract two currencies. Recommended: the figure is **null** when the currencies differ (never blocks, consistent with D-14). Alternative: refuse such a receipt `validation`.
- **Q-4 (return currency without a receipt).** Recommended: the supplier's, else the branch's (AC-1's chain).
- **Q-5 (AC-19, AC-54 audit count).** A post writes one `purchasing_receipt_posted` row and, through T-034's `ApplyReceivedQuantity`, one `purchasing_order_receipt_applied` row per linked line (plus one `purchase_order.received_applied` event each). Recommended: amend AC-54 to "exactly one row per write per subject; the order's own application rows are T-034's (AC-80)".
- **Q-6 (AC-58 "price history by product").** No slice reads price history. Recommended: proven at table level (a row in tenant A is absent from B); a read slice is a later row.
- **Q-7 (`received_value`).** T-034 expected this task to maintain it; the spec does not ask, and it would need a new T-034 operation plus a currency rule. Recommended: not built, follow-up row.
- **Q-8 (receipt at an inactive branch).** Recommended, as T-034 AC-41: the location's branch must be active (`unknown_branch`), checked at create and post.
- **Q-9 (line notes length).** Recommended: 500 characters.
- **Q-10 (an order application replayed during a draft post).** Not named. Recommended: `order_application_refused` with detail `replayed_for_draft`, the order-side twin of `ledger_inconsistent`.

## Follow-ups for `lead` (not built here)

- FP-NEW-A and FP-NEW-B (spec, D-1); docs correction of `grn_return` (D-2).
- `received_value` maintenance (Q-7, R-34d).
- A price-history read or report slice (Q-6).
- FP-426 batch unit resolution (risk 7); FP-331 support-copy debt unchanged.
- FP-262 consumes `goods_receipt.posted` / `purchase_return.posted` idempotently by `(source_type, source_id)` (T-033 D-7 lists both types).
- Engine evidence for step 19 before the hold lifts.

## Questions for the human

None. Owner-visible questions stand as the spec lists them (Q-1 to Q-6 there), each with its built default.
