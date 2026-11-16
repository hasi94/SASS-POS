# Plan: T-034 Suppliers, purchase requests and purchase orders

Spec: `docs/specs/T-034-suppliers-purchase-orders.md` (AC-1 to AC-98). Decisions: `docs/decisions/T-034-suppliers-purchase-orders.md` (D-1 to D-30). Backlog FP-254. Branch `ai/T-034-suppliers-purchase-orders`, cut from the T-030 tip `c354a0e`; spec committed at `1b8de31`. **Plan revision 1**, planned at `1b8de31`. Every claim about built code below was read in source at that commit, never in a plan or a summary. T-031 (stock ledger), T-032 (`accounts`) and T-033 are not in this base; schema versions are renumbered at merge time (known, accepted).

**Delivery rule.** `architect` ran no test, no composer and no gate (the machine's single gate is in use by another task). Every "Gate" line below is run by `dev` only when `lead` says the gate is free. Engine tests (`modules/host/tests/Engine/*`) are written and stay skipped under `ENGINE_HOLD`. Nothing in this plan claims a green gate.

---

## 0. Verification and findings

### 0.1 The ten facts `ba` asked for, verified in code

| # | Question | Answer, with evidence |
|---|---|---|
| 1 | How does the workflow engine tell the owning module an instance was decided? | **It does not.** `WorkflowEngine::decide()` and `cancel()` (`packages/workflow/src/Engine/WorkflowEngine.php`) return a `WorkflowInstanceState` to their own caller and write only `workflow_*` rows. There is no outbox write, no hook and no callback anywhere in `packages/workflow/src` (grep for `Outbox`, `dispatch`, `Hook`: nothing). So `SyncOrderApproval` / `SyncRequestApproval` are **pull** slices with no trigger in this task; see Ruling 6 and lead question L-1 |
| 4 | Does `WorkflowGate::instance()` return `on_reject`, the entity id and status? | **Yes.** `WorkflowInstanceState` carries `id`, `definitionKey`, `entityType` (string), `entityId`, `status` (`WorkflowInstanceStatus`: pending, approved, rejected, cancelled, expired), `currentStep`, `onReject` (`WorkflowOnReject`: stop, return_to_requester), `startedAt`, `completedAt`. `InstanceReader::read()` reads the row with a plain read; unknown id throws `WorkflowActionRefused::unknownInstance`. `evaluate()` also returns `onReject` on its `WorkflowDecision`. `expired` is never written by the engine today (T-020 D-9) |
| 2 | Does `DocumentNumbers` accept `purchase_request` / `purchase_order`? Deadlock handling? | **Accepted, with no prefix.** `DatabaseDocumentNumbers::next()` takes any string type; on first use it inserts a company-wide row with `prefix = DEFAULT_PREFIXES[$type] ?? null`, `padding = 6`, `reset_period = never`. `DEFAULT_PREFIXES` holds only `sale`, `refund`, `cash_shift`, so both purchase types would print `000001`. Ruling 3 adds `PR` and `PO`. First use is a `FOR UPDATE` read of an absent key, which can gap-lock deadlock on InnoDB (NOT VERIFIED, the class docblock says so); the T-030 remedy is `transaction(..., 2)` as the **outermost** transaction (`OpenCashShiftHandler`, `CompleteSaleHandler`, `RefundSaleHandler`). Every purchasing slice that calls `next()` does the same |
| 3 | Which Catalog contract exposes `purchase_tax_group_id`, active, purchasable, stock-only? Which contract answers "location L belongs to branch B and is active"? | **None, for either.** `Contracts\Catalog` holds only `UnitResolver` and its DTOs. `Contracts\Organization\BranchDirectory::state()` answers a branch's state only (`unknown`, `active`, `inactive`); nothing answers a location. The facts are columns: `products.is_active`, `deleted_at`, `is_purchasable`, `type` (`simple`, `variant_parent`, `variant`, the non-stock type, `bundle`, `composite`; `ProductType`), `track_stock`, `purchase_tax_group_id` (nullable, migration `002906`), `name`; `locations.branch_id` (**nullable**), `is_active`, `is_purchasable_to`, `deleted_at`; `branches.is_active`, `timezone`, `currency`, `deleted_at`. Ruling 1 reads them directly, the T-030 Ruling 1 `Lookup` precedent |
| 5 | Did T-028 ship `[CF]` custom-field storage on `customers`? | **No.** `docs/database.md` §6.9 "As built (T-028)": "`customers.custom_fields` is likewise deferred, following `products`' own precedent: no `custom_field_definitions` table exists." T-030 deferred every `[CF]` too. So `suppliers.custom_fields` and `purchase_orders.custom_fields` are **not built** (D-3 mirrored) |
| 6 | Tenant base currency; the business clock | **No tenant base currency exists in the tenant database.** The only currency is `branches.currency CHAR(3) NOT NULL`. The clock: `RunnerClock` (kernel, stoppable in tests) gives UTC; T-030's `Flexipos\Sales\Support\BusinessClock::businessDate($timezone)` converts it to the branch-local date from `branches.timezone`. Deptrac forbids purchasing → sales internals, and promoting to the kernel needs a third use (`docs/patterns.md` §3). Ruling 1: **copy** the one method purchasing needs into `Purchasing\Support\BusinessClock` (the eleventh support copy, FP-331) |
| 7 | Does `computeTax()` handle AC-43? Are the BA's numbers right? | **The numbers are right** (hand recomputation below), but **purchasing may not compute the discount itself**: `QaPricingSourceRulesTest` AC-25(b) scans every `packages/*/src` outside `packages/pricing/src/Engine` and reports any multiplication next to a token ending in `percent` or `rate`, including `multipliedBy(...percent)`. `subtotal x discount_percent / 100` in purchasing goes red. Ruling 2 adds one pricing contract that does the line arithmetic. Two engine facts the spec misses: `computeTax()` **computes an inactive group normally** (adapter docblock "an inactive group is computed normally (K-12)") and **skips a member whose `applies_to` excludes the context, never refusing** (`TaxCalculator` step 1). So AC-44's "inactive" and "not applicable to purchases" refusals cannot come from the engine (F-6) |
| 8 | Per-line query cost of `UnitResolver::snapshot()`; batch method? | **Five queries per call** (`DatabaseUnitResolver`): `products` (stock unit), `units` (whole table), `unit_conversions` product row, `unit_conversions` global row, `units` again. `snapshotInherited()` costs two (`products`, `units`). **No batch method exists** (FP-426 still open). A 200-line order is about 1000 resolver queries |
| 9 | Exact names for the header supplier snapshots and the applications table | **Neither exists in `docs/database.md` §6.6.** The `purchase_orders` row there has no supplier snapshot column, and there is no receipt-applications table. This plan names them (Data section): `purchase_orders.supplier_name VARCHAR(150)`, `supplier_tax_no VARCHAR(50) NULL`, `payment_terms_days SMALLINT UNSIGNED`; table `purchase_order_receipt_applications`. Both are undocumented additions, recorded in `docs/database.md` "As built (T-034)" at step 24, the T-030 F-18 shape |
| 10 | Events or a read contract for FP-250's `quantity_incoming`? | **Build the events (AC-97) now; recommend FP-250 compute `quantity_incoming` from a read contract it asks for.** §6.5 defines the figure as `SUM(base_quantity - received_base_quantity)` over open order lines per `(location, product)`, which is a join, and `docs/patterns.md` §5 says a maintained figure must be rebuildable from source. A figure maintained only from events has no rebuild and depends on outbox order (ULID order is not commit order, §5). So FP-250 needs a read contract regardless, at least for its rebuild and nightly check; it adds `Contracts\Purchasing\...` when it is built (T-030 Ruling 1: no contract until a consumer exists). The events remain for notifications (`purchase.approved`) and for an incremental cache if FP-250 wants one. Follow-up row |

**AC-43 recomputed by hand** (half-up at four decimals at each step, `TaxCalculator` rules: percentage member `round(base x rate / 100, 4)`, exclusive `net = amount`):
- subtotal = 12.0000 x 3.3333 = 39.9996 (exact);
- discount = 39.9996 x 10.00 / 100 = 3.99996, rounds to **4.0000**;
- net = 39.9996 - 4.0000 = **35.9996**;
- tax = 35.9996 x 10 / 100 = 3.59996, rounds to **3.6000**;
- line_total = 35.9996 + 3.6000 = **39.5996**.

All five of the BA's figures are right. AC-45: 39.5996 + 10.0000 + 2.5000 = **52.0996**, right. The inclusive twin the tests add (same line, 10 % **inclusive** group): gross 35.9996; net = round(35.9996 / 1.1, 4) = 32.7269; tax = round(32.7269 x 0.10, 4) = 3.2727; residue 35.9996 - 32.7269 - 3.2727 = 0.0000; so `tax_amount 3.2727`, `line_total 35.9996`.

### 0.2 Spec conventions checked against T-028 and T-030 (mismatches)

`ba` wrote the spec without opening `docs/specs/T-028-customers.md` or `docs/specs/T-030-sales.md`. Read against both specs and the shipped code:

| # | T-034 says | T-028 / T-030 (as built) | Plan's reading |
|---|---|---|---|
| C-1 | Audit actions `supplier.created`, `purchase_order.approved`, ... (dotted) | Snake case, the handler's `ACTION` constant: `customer_created` (customers), `sales_sale_refunded` (sales, with the module prefix) | `purchasing_<entity>_<verb>`, the T-030 shape (Data section). The outbox event types stay dotted, because `OutboxWriter::TYPE_PATTERN` requires dots |
| C-2 | List paging: "25 per page", "a page past the end", `page_size` above 100 is `validation` | Keyset paging: newest first by ULID `id`, `before_id` cursor; an oversize `page_size` is **clamped**, never refused (`Pager`, T-028 Ruling 11, D-17) | Keyset paging as built, default 25, maximum 100, **oversize refused `validation`** as the spec says (the one difference, flagged F-11). Suppliers are ordered by `code`, so their cursor is `after_code` |
| C-3 | Read slices check permission | T-030's read slices check nothing (FP-452 names them) | Every slice checks its own key (AC-84, the FP-452 lesson) |
| C-4 | Supplier `code` 1 to 30, folded upper case, immutable | `customers.code` VARCHAR(50), optional, stored as given, case-insensitive pre-check | Column VARCHAR(50) (§3.2 "codes VARCHAR(50)"), validation cap 30, folded upper case, as the spec says |
| C-5 | `email` at most 190, validated | `customers.email` / `branches.email` VARCHAR(191), no format check | Column VARCHAR(191); the spec's 190 cap and format check are applied (F-12 asks whether 191 was meant) |
| C-6 | `credit_limit` nullable, null = unspecified | `customers.credit_limit` NOT NULL DEFAULT 0, "no limit is not representable" | Nullable, as the spec says; data only (D-8), so nothing reads it. Flagged F-12 |
| C-7 | `exchange_rate` "positive `DECIMAL(18,6)`", default `1.000000` | §3.2: exchange rate `(18,8)`; `sales.exchange_rate (18,8)` | **`(18,8)`**, default `1.00000000`, input up to 8 decimals (F-8) |
| C-8 | Refusal names `unit_refused`, `numbering_refused`, ... | T-030: `unit_unresolvable`, `inherited_unit_mismatch`, `numbering_refused`, `pricing_refused` (whose `code()` reports the engine's value) | The spec's own working names are kept (less churn for `ba`); `code()` always reports the purchasing reason and the foreign refusal rides as `cause` |
| C-9 | "the cause and its enum type" for every foreign refusal (AC-52) | `PricingRefused`, `UnitResolutionRefused`, `DocumentNumbersRefused` and `AccessDecision` carry backed enums; **`WorkflowEvaluationFailed->cause` and `WorkflowActionRefused->reason` are plain strings** | Enum causes as T-030 does; a workflow refusal is carried as the `previous` exception plus its string in `detail` (F-7) |
| C-10 | Caller-supplied ULID on every create, required | T-028: server-generated customer ids; a caller `id` is an optional replay key on loyalty rows. T-030: caller ids on tenders and movements | Required on every create and every line add (D-21), as the spec says |
| C-11 | Engine-touching tests "migrations, tenant database, models, concurrency, the cross-tenant suite" written but not run (AC-98, D-30) | `ENGINE_HOLD` skips only `modules/host/tests/Engine/*` (real MySQL, `EngineConnection::onHold()`); SQLite stand-in schema, model and cross-tenant tests run in every gate (T-027 to T-030 ran hundreds) | Stand-in tests are written **and** run at each chunk gate when the gate is free; real-engine tests are written and skipped (F-13) |
| C-12 | AC-88 "every new table is added to the cross-tenant isolation suite (T-022)" | There is no per-table T-022 registry. The cross-tenant sweeps (`QaRouteCrossTenantSweepTest`, `QaPlatformDatabaseSeparationTest`, `QaSchemaSignatureInstrumentTest`) read `tenantTreeTables()` in `tests/Helpers.php` | Adding the six tables to `tenantTreeTables()` puts them under T-022's sweeps; plus one tenant-A/tenant-B `not_found` row per root entity |

### 0.3 Findings for `ba` (routed through `lead`)

None blocks; the plan builds the reading given and names the step it would change.

- **F-1 (AC-93) "no read of another package's tables" contradicts the shipped precedent.** No contract exposes product facts or location facts (fact 3). T-030 Ruling 1 reads `products`, `branches`, `terminals`, `customers` directly, read-only, through `Lookup`. **Reading:** purchasing reads `products`, `branches` and `locations` read-only for existence and facts (Ruling 1), and the scan forbids a named token list instead (step 21). Proposed wording: "no write to another package's table, and no read of `unit_conversions`, `units.factor`/`base_unit_id`, tax tables, `number_sequences`, `workflow_*` tables, customer ledgers or stock tables".
- **F-2 (D-29) what "purchasable" means.** The catalogue distinguishes it: **eligible iff** not soft-deleted, `is_active`, `is_purchasable`, `track_stock`, and `type` in {`simple`, `variant`, `composite`}. A variant parent, a bundle or the non-stock product type is `product_not_purchasable`. Composite (a product with a bill of materials) is accepted, because it is stocked; say if it should not be.
- **F-3 (AC-20, AC-41) the location rule.** `locations.branch_id` is nullable, so a company-level warehouse has no branch. **Reading:** L must be live, `is_active`, `is_purchasable_to`, and `branch_id = B`; a branch-less warehouse is `unknown_location`. Delivering a branch's order to a central warehouse is a plausible real case; say if it should be allowed. `is_purchasable_to` is a check the spec does not name.
- **F-4 (AC-40, D-20) `currency_required` is avoidable.** Every branch carries a required `currency`. **Built:** explicit, else the supplier's, else refused `currency_required` (spec). **Proposal:** fall back to the branch's currency, which removes the refusal. One-line change in step 13.
- **F-5 (AC-44) where the tax group comes from.** The chain is built as written. But see F-6.
- **F-6 (AC-44) the engine does not refuse an inactive group or one "not applicable to purchases".** `computeTax()` computes an inactive group normally and **skips** members whose `applies_to` excludes `purchase` (an all-sale group yields zero tax, no refusal). **Reading:** purchasing asks `TaxGroupDirectory::state()` first: unknown is `unknown_tax_group`, inactive is `inactive_tax_group` (two new refusals). "Not applicable to purchases" is not a refusal; such a group taxes at zero, which the engine defines. If `ba` wants it refused, the engine needs a new query (out of scope here).
- **F-7 (AC-52) workflow refusals carry no enum.** `WorkflowEvaluationFailed->cause` and `WorkflowActionRefused->reason` are strings. The purchasing refusal carries the workflow exception as `previous` and its string as `detail`. AC-52's "enum type" holds for unit, pricing, numbering and access only.
- **F-8 (AC-40, AC-41) exchange rate precision.** `(18,8)` per §3.2 and `sales.exchange_rate`; default `1.00000000`. Please amend "DECIMAL(18,6)" and "1.000000".
- **F-9 (AC-66 vs AC-75) a receipt on a `received` order.** AC-66 refuses "receipt application" on `received` with `order_closed`; AC-75 accepts a reversal or over-receipt there and refuses closed/cancelled with `order_not_open`. **Reading:** AC-75 governs receipts (`approved`, `partially_received`, `received` accept; `draft`, `pending_approval`, `closed`, `cancelled` are `order_not_open`); AC-66 governs edits, submit and reopen.
- **F-10 (AC-62) reopen after a reversed receipt.** A receipt reversed back to zero leaves `received_base_quantity = 0` but leaves application rows pointing at the lines (RESTRICT). Reopening, then removing a line, would hit the key. **Reading:** reopen is refused `already_received` when **any** application row exists for the order, not only when the sums are non-zero. Stricter, and it keeps the receipt trail intact.
- **F-11 (AC-16, AC-35, AC-82) paging shape.** Keyset, not page numbers (C-2). An oversize `page_size` is refused, while T-028 clamps; say if clamping was meant.
- **F-12 (AC-4, AC-6) small drifts from T-028.** Email 190 vs the house 191; `credit_limit` nullable vs T-028's NOT NULL DEFAULT 0. Built as the spec says; confirm.
- **F-13 (AC-98, D-30) what the hold covers** (C-11). Please reword: real-engine tests are written and skipped; stand-in tests run at each gate.
- **F-14 (AC-88) the isolation suite** (C-12). Please reword to "the six tables join `tenantTreeTables()`, and a tenant-B read of a tenant-A id is `not_found`".
- **F-15 (AC-77) a zero quantity delta.** Receiving one unit against a line in tonnes can round the line-unit delta to `0.0000` while the base delta is real. **Reading:** `received_base_delta` must be non-zero; `received_quantity_delta` may be zero, and the two may not have opposite signs.
- **F-16 (AC-49, AC-89) removing a converted line.** A draft order made by conversion has lines that request lines point at (`purchase_order_item_id`, RESTRICT). **Reading:** `RemovePurchaseOrderLine` clears that pointer on the request line in the same transaction, and the audit row records it. The key stays RESTRICT, so no row is orphaned silently.
- **F-17 (AC-97) event payloads.** `reopened`, `cancelled` and `closed` carry ids only, which forces a `quantity_incoming` consumer to read purchasing's tables to know what to subtract. **Reading:** all four transition events carry the same per-line `{line_id, product_id, location_id, base_quantity, received_base_quantity}` list as `approved`.
- **F-18 (AC-38, AC-84) which key a sync slice needs.** Unstated. **Reading:** the document's `.view` key at its branch; a sync only applies a decision the engine already made and confers nothing.
- **F-19 (D-24) `purchase.approved`.** The event is `purchase_order.approved`; a notification rule maps its `event_key` to it when notifications are built. No second event.

### 0.4 Rulings

**Ruling 1: placement and reads.** A new path package `packages/purchasing` (`flexipos/purchasing`, namespace `Flexipos\Purchasing\`, module name `purchasing`). It publishes **no** contract: `ApplyReceivedQuantity`'s caller (FP-255, goods receipts) lives in this same package per `docs/architecture.md`'s module table, so it calls the handler directly inside its own transaction (AC-96 confirmed: direct call, no contract). Source reaches `Package_Kernel` and `Package_Contracts` only.
- **Contracts used:** `UnitResolver`, `PricingEngine`, the new `PurchaseLinePricing` (Ruling 2), `TaxGroupDirectory`, `DocumentNumbers`, `BranchDirectory`, `PermissionResolver`, `EmployeeDirectory`, `AuditTrail`, `Outbox`, `WorkflowGate`.
- **Direct read-only fact reads (F-1):** `products` (`name`, `is_active`, `is_purchasable`, `track_stock`, `type`, `purchase_tax_group_id`, `deleted_at`), `locations` (`branch_id`, `is_active`, `is_purchasable_to`, `deleted_at`), `branches` (`timezone`, `currency`, `id` list for list scoping). Never written.
- **Support:** `TenantConnection`, `Fields`, `Identifier`, `Lookup`, `Pager`, `Page`, `Decimal`, `Money` copied from `packages/sales/src/Support` (the T-030 set, which already carries the T-028 and T-029 fixes), plus `BusinessClock` reduced to `businessDate(string $timezone)`. Eleventh copy (FP-331).

**Ruling 2: one pricing contract addition, `Contracts\Pricing\PurchaseLinePricing`.** Purchasing's line arithmetic involves a percentage, which AC-25(b) confines to `packages/pricing/src/Engine`, and the `PricingEngine` docblock calls the engine "the only place tax, price-list and discount arithmetic happens". So:
```php
interface PurchaseLinePricing
{
    /** @throws PricingRefused */
    public function quotePurchaseLine(PurchaseLineQuote $quote): PricedPurchaseLine;
}
// PurchaseLineQuote: quantity (18,4 string), unitCost (18,4), discountPercent (9,4, 0..100),
//   taxGroupId (ULID), baseQuantity (18,4), documentDate (Y-m-d). Context is always Purchase.
// PricedPurchaseLine: subtotal, discountAmount, taxableAmount, tax (TaxBreakdown), lineTotal.
```
- Pure `Engine/PurchaseLineCalculator` (no Illuminate import): `subtotal = round(quantity x unitCost, 4)`; `discount = Decimal::percentOf(subtotal, percent)`; `taxable = subtotal - discount`; the adapter then runs the existing `TaxCalculator::compute(members, taxable, baseQuantity, date, Purchase, isInclusive)`; `lineTotal = tax.net + tax.total` (equals `taxable` for an inclusive group, `taxable + tax` for an exclusive one, so nobody branches on inclusivity). Every step through `Decimal::round`/`checkRange`, so an overflow is `PricingRefused(out_of_range)`.
- `DatabasePricingEngine` implements it (it already owns `taxGroup()` and `members()`); `PricingServiceProvider` binds `PurchaseLinePricing` to the same singleton.
- **A new interface, not a new method on `PricingEngine`,** so no existing double breaks (`packages/sales/tests/Support/FakePricingEngine.php` and others implement `PricingEngine`).
- FP-432's TypeScript twin needs no counterpart: purchasing never runs on the till.

**Ruling 3: numbering.** `DatabaseDocumentNumbers::DEFAULT_PREFIXES` gains `'purchase_request' => 'PR'` and `'purchase_order' => 'PO'` (so `PR000001`, `PO000001`). The number is taken **last**, inside the creating transaction, which runs as `transaction(..., 2)` and is the outermost one (`CreatePurchaseRequest`, `CreatePurchaseOrder`, `ConvertRequestToOrder`). A `DocumentNumbersRefused` becomes `numbering_refused`, or `number_too_long` for that cause. A unique violation on `number` (two branch rows sharing a prefix, FP-458) becomes `numbering_refused` too, never an unhandled error.

**Ruling 4: the two state machines** (D-2; AC-37, AC-67; F-9).

Requests:

| From | Command | To |
|---|---|---|
| — | `CreatePurchaseRequest` | `draft` |
| `draft` | `SubmitPurchaseRequest`, `NotRequired` / `Pending` | `approved` / `pending_approval` |
| `pending_approval` | `SyncRequestApproval`: approved / rejected (either `on_reject`) / cancelled or expired / pending | `approved` / `rejected` / `draft` (instance cleared) / unchanged |
| `draft`, `pending_approval`, `approved` | `CancelPurchaseRequest` | `cancelled` |
| `approved` | `ConvertRequestToOrder` | `converted` |

Orders:

| From | Command | To |
|---|---|---|
| — | `CreatePurchaseOrder`, `ConvertRequestToOrder` | `draft` |
| `draft` | `SubmitPurchaseOrder`, `NotRequired` / `Pending` | `approved` / `pending_approval` |
| `pending_approval` | `SyncOrderApproval`: approved / rejected + `return_to_requester` / rejected + `stop` / cancelled or expired / pending | `approved` / `draft` (cleared) / `cancelled` / `draft` (cleared) / unchanged |
| `approved`, no application rows | `ReopenPurchaseOrder` | `draft` (cleared) |
| `draft`, `pending_approval`, `approved` with no application rows | `CancelPurchaseOrder` | `cancelled` |
| `approved`, `partially_received`, `received` | `ApplyReceivedQuantity` | recomputed from base sums: all zero `approved`, all lines complete `received`, else `partially_received` |
| `partially_received`, `received` | `ClosePurchaseOrder` | `closed` |

`sent` ships in the enum and nothing enters or leaves it. `received`, `closed`, `cancelled` refuse every edit, submit and reopen with `order_closed` (AC-66); `closed` and `cancelled` refuse receipts with `order_not_open` (F-9). A line is complete when `received_base_quantity >= base_quantity`, compared as `BigDecimal` on the stored strings (AC-72, AC-73).

**Ruling 5: idempotency** (D-21).
- Creates and line adds take the caller's ULID as the row id. A replay compares the normalised input with the stored row's columns: identical returns the stored row with `replayed = true`, no number, no audit, no event; any difference is `replay_conflict` naming the id. A document edited since its creation therefore conflicts on a create replay, which is the safe outcome (risk 6). A soft-deleted supplier is never returned by a replay (`replay_conflict`).
- State changes are idempotent by outcome: submit on `pending_approval` or `approved` returns the document (`replayed = true`, no second instance); cancel on `cancelled`, close on `closed`, deactivate on inactive, delete on deleted are no-op successes with no audit row; convert of a `converted` request with the same supplier returns its order.
- `ApplyReceivedQuantity` replays by application id (the applications table's primary key): same line and deltas returns the stored result; otherwise `replay_conflict`.

**Ruling 6: approval** (D-11; fact 1).
- `Submit*` calls `WorkflowGate::evaluate()` once, **inside** the submit transaction, after the document lock. The engine opens a savepoint; any exception from it is caught **only** to rethrow as `workflow_failed`, which rolls the whole transaction back (document stays `draft`, nothing audited). The catch never continues.
- The request amount (AC-26) is `Money::sum(Money::product(quantity, estimated_cost))` over the lines, each product rounded half-up at 4; the order amount is the stored `total` (AC-55). An amount that will not fit `DECIMAL(18,4)` is `out_of_range` before `evaluate()` (the `WorkflowContext` constructor would throw `InvalidArgumentException` otherwise).
- `Sync*` locks the document, then reads `WorkflowGate::instance()` (a plain read, after the lock, so fresh). It acts only on `pending_approval`; any other status is returned unchanged, `changed = false`. The instance's `entityType` and `entityId` must equal the document's, else `invalid_transition` (AC-59).
- `Cancel*` of a `pending_approval` document calls `WorkflowGate::cancel()`. If the engine answers `instanceClosed` (it was decided but not yet synced), the cancel **proceeds**: there is nothing left to withdraw, and since the document never reached `approved` here no cancel event is owed. The audit row records the instance's status.
- **No trigger exists** for `Sync*` (fact 1). Lead question L-1.

**Ruling 7: transaction and lock order** (D-22; the T-030 Ruling 7 rules).
1. Before the transaction, on autocommit reads: input validation, permission (`PermissionResolver`), product facts, `UnitResolver`, tax-group resolution and `PurchaseLinePricing`. These read facts the write cannot change: an order's `order_date` and currency are fixed for the quote, and a line's unit and factor are fixed once written.
2. **The first statement inside every write transaction is a locking read** (T-028 review W-1, T-029 review W-1).
3. One global order; every writer takes the subset it needs, in this order, and nothing is ever taken in reverse:

| # | Row | Taken by | How |
|---|---|---|---|
| 1 | the document header: `purchase_requests` or `purchase_orders`, by id | every request or order write (a convert locks the **request**; the order it creates is new). `ApplyReceivedQuantity` locks the **order** header, never the line first (AC-78, D-22) | `lockForUpdate()`; status and line count re-read after it |
| 2 | `suppliers`, by id | order create, convert, order submit, a header edit naming a supplier, a request line naming one: `sharedLock()`. `UpdateSupplier`, `ActivateSupplier`, `DeactivateSupplier`, `DeleteSupplier`: `lockForUpdate()`, as their first and only lock | A supplier writer then reads requests and orders with plain reads, so there is no cycle with a document writer that holds a header and waits on the supplier |
| 3 | `workflow_instances` | inside `WorkflowGate::evaluate()` / `cancel()` (the engine's own locks) | the engine never calls back into purchasing |
| 4 | `number_sequences` | `DocumentNumbers::next()` | **last**, always; inserting rows after it is fine |

4. Then the outbox row(s) and the audit row. Lines of the locked document are read and written freely after lock 1, because only a holder of lock 1 writes them.
5. `ApplyReceivedQuantity` runs inside its caller's transaction (FP-255), so it opens a nested `transaction()` (a savepoint) and never retries; its first statement is still the header lock.
6. A request create takes no supplier lock (it names none); a line naming a supplier takes it under the request lock (row 1 then row 2).

**Ruling 8: the slice shape** (T-027 to T-030). `Features/<X>/{X, XHandler, XResult}`; `array $fields` plus `?string $actorId`; `Fields::only`; each slice asserts its own permission through `Support/Authorise::require($resolver, $actorId, $key, $branchId)` (refusal `not_authorised`, cause the `RefusalReason`), always at the **stored** document branch (AC-85 d); one `AuditTrail::record()` per real write; nothing written on refusal. **31 slices, no HTTP route, no console command:**

| Area | Slices | ACs |
|---|---|---|
| Suppliers | `CreateSupplier`, `UpdateSupplier`, `ActivateSupplier`, `DeactivateSupplier`, `DeleteSupplier`, `GetSupplier`, `ListSuppliers` | 1–18 |
| Requests | `CreatePurchaseRequest`, `UpdatePurchaseRequest`, `AddPurchaseRequestLine`, `UpdatePurchaseRequestLine`, `RemovePurchaseRequestLine`, `SubmitPurchaseRequest`, `SyncRequestApproval`, `CancelPurchaseRequest`, `ConvertRequestToOrder`, `GetPurchaseRequest`, `ListPurchaseRequests` | 19–39 |
| Orders | `CreatePurchaseOrder`, `UpdatePurchaseOrder`, `AddPurchaseOrderLine`, `UpdatePurchaseOrderLine`, `RemovePurchaseOrderLine`, `SubmitPurchaseOrder`, `SyncOrderApproval`, `ReopenPurchaseOrder`, `CancelPurchaseOrder`, `ClosePurchaseOrder`, `ApplyReceivedQuantity`, `GetPurchaseOrder`, `ListPurchaseOrders` | 40–83 |

`UpdatePurchaseOrder` and `UpdatePurchaseOrderLine` carry the amendment rules (AC-60, AC-61, AC-63): in `draft` every field; in `approved` with nothing received and in `partially_received`, only `notes`, `terms`, header `expected_date`, and a line's `expected_date` (in `partially_received`, only for an incomplete line); anything else is `locked` naming the field and naming reopen.

**Ruling 9: permission keys** (D-26; AC-95). `PurchasingPermissions` declares eight keys, module `purchasing`: `purchasing.suppliers.view`, `purchasing.suppliers.manage` (`isBranchScoped = false`); `purchasing.requests.view`, `purchasing.requests.manage`, `purchasing.orders.view`, `purchasing.orders.manage`, `purchasing.orders.close`, `purchasing.orders.reopen` (`true`). `ConvertRequestToOrder` checks both `requests.manage` and `orders.manage` at the request's branch. `GetSupplier` accepts `view` or `manage`, and returns `bank_details` only to `manage`. **List scoping (AC-35, AC-82):** with a `branch_id` filter, one `decide()` at it; without, one `decide()` per `branches` row (soft-deleted and inactive included, because documents outlive their branch), and a `whereIn` over the allowed set; none allowed is an empty page. The cost is one decision per branch, named in risk 7.

**Ruling 10: `bank_details` redaction** (D-9, AC-17). Every audit image built by a supplier slice replaces a non-null `bank_details` with the marker `[redacted]`, and when the value changed the after image carries `[redacted, changed]`, so `AuditWriter`'s derived `changed_fields` still lists the field. No purchasing code logs. No refusal message names a `bank_details` value. Outbox payloads never carry a supplier field other than its id.

**Ruling 11: outbox events** (AC-97, F-17). Written inside the same transaction through `Outbox::record(new NewEvent(type, 'purchase_order', id, payload, branchId: order branch))`. Payload ids and base figures only:
- `purchase_order.approved`, `purchase_order.reopened`, `purchase_order.cancelled` (only when the order had been `approved`), `purchase_order.closed`: `{order_id, location_id, lines: [{line_id, product_id, base_quantity, received_base_quantity}]}`;
- `purchase_order.received_applied`: `{order_id, application_id, line_id, product_id, location_id, received_base_delta, received_quantity_delta, received_base_quantity, received_quantity, status}`.

A replay or refusal writes none.

---

## Approach summary

1. A new `packages/purchasing` owns suppliers, purchase requests, purchase orders and the received-quantity rollup: 31 vertical slices, no routes, six new tenant tables.
2. Every unit quad comes from `UnitResolver` (`snapshot()` for new or draft-edited lines, `snapshotInherited()` for conversion), every line amount from one new pricing contract (`PurchaseLinePricing`, Ruling 2), every number from `DocumentNumbers`. Purchasing's own arithmetic is sums, comparisons and the request estimate's `quantity x estimated_cost`, all in `Support/Money.php`.
3. Approval goes through `WorkflowGate`, fail closed, inside the submit transaction; outcomes are pulled by two sync slices (the engine has no callback).
4. The rollup compares base to base on the stored four-decimal strings, takes the order header lock first, and replays by a caller application id.
5. Two small edits outside the package: the pricing contract (Ruling 2) and two default number prefixes (Ruling 3).
6. Cross-module effects are outbox events; nothing touches stock, ledgers or accounts.

## Alternatives rejected

- **Discount arithmetic inside purchasing:** goes red on `QaPricingSourceRulesTest` AC-25(b), and contradicts the engine's "only place" docblock. An exemption in the scan erodes the boundary.
- **A new method on `PricingEngine`:** breaks every class that implements it (sales' `FakePricingEngine` among them). A separate interface on the same class costs nothing.
- **Catalog and Organization "facts" contracts now:** a second reader of `products` does exist (sales and purchasing), but a contract means migrating sales too; follow-up row instead (F-1).
- **Copying `BusinessClock` via a contract or the kernel:** second use only; the three-use rule says copy.
- **A console sweep or an engine outbox event to trigger sync:** both are real designs, both change scope (an engine change, or a scheduled job nobody specified). Lead question L-1.
- **A published `ApplyReceivedQuantity` contract:** its only caller (FP-255) is in this package.
- **Computing `quantity_incoming` here:** FP-250 owns `stock_levels` (AC-79).
- **Allocating the number at submit:** breaks `number unique NOT NULL` on drafts (D-10).

## Files to change / create

`dev` writes every file. `Qa*` test files are `qa`-accountable.

### `packages/contracts/src/Pricing/` (new)
- `PurchaseLinePricing.php`, `PurchaseLineQuote.php`, `PricedPurchaseLine.php` (Ruling 2). Shapes validated through `Shape::money`, `Shape::rate`, `Shape::ulid`, `Shape::date`; `discountPercent` additionally `<= 100`.

### `packages/pricing`
- `src/Engine/PurchaseLineCalculator.php` (new, pure).
- `src/Engine/DatabasePricingEngine.php`: `implements PricingEngine, PurchaseLinePricing`; `quotePurchaseLine()` reuses `taxGroup()` and `members()`; no new import.
- `src/PricingServiceProvider.php`: the binding.
- `tests/Engine/PurchaseLineCalculatorTest.php`, `tests/Engine/PurchaseLinePricingTest.php` (new).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php`: two prefixes. `tests/Numbering/DocumentNumbersTest.php`: one new row.

### `packages/purchasing` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/purchasing` 0.1.0: `php ^8.2`, `brick/math ^0.14`, `flexipos/contracts` (the sales shape) |
| `src/PurchasingServiceProvider.php` | `implements DeclaresAuditActions, DeclaresPermissions`; config `purchasing.page_size` 25, `purchasing.max_page_size` 100, `purchasing.max_lines` 200. No routes, no commands |
| `src/Support/{TenantConnection,Fields,Identifier,Lookup,Pager,Page,Decimal,Money,BusinessClock,Authorise,Redaction}.php` | Ruling 1, 8, 10 |
| `src/Suppliers/{SupplierCode,SupplierImage,SupplierUse}.php` | code rules (AC-2, AC-11); audit images (Ruling 10); the in-use query (AC-14) |
| `src/Documents/{RequestStatus,OrderStatus,Amendments,Places,ProductFacts,TaxGroupChain,LineQuoter,OrderRollup,ReceiptRollup,ApprovalOutcome,OrderEvents,DocumentLocks}.php` | Ruling 4; AC-61/63 field sets; branch and location checks (F-3); product eligibility (F-2); AC-44 chain (F-6); unit plus pricing per line (Ruling 2); header sums (AC-45); base-to-base status (AC-69 to AC-74); instance-to-status mapping (Ruling 6); event payloads (Ruling 11); lock helpers (Ruling 7) |
| `src/Exceptions/{PurchasingRefused,PurchasingRefusal}.php` | Data section's vocabulary; `?BackedEnum $cause`, `?string $detail`, `previous` |
| `src/Registry/{PurchasingAuditActions,PurchasingPermissions}.php` | Ruling 9; actions from each handler's `ACTION` |
| `src/Features/<31 slices>/…` | Ruling 8 |
| `tests/Support/{PurchasingSeed.php,purchasing-fixture.php,FakeWorkflowGate.php,DeadlockingNumbers.php,PurchasingQueryLog.php}` | raw seeds for purchasing's own tables plus `AccessSeed`/`AccessTenantFixture` and `PricingSeed` (units, products, taxes); `PurchasingSeed::workflowDefinition(entity, minAmount, onReject)` raw-inserts a one-step `employee` definition so real-engine rows can approve through `WorkflowApprovals::decide()`; `FakeWorkflowGate` (records calls; returns or throws on demand) for the refusal datasets; `DeadlockingNumbers` copied from sales' test support |
| `tests/Schema/PurchasingTenantSchemaTest.php` | step 4 |
| `tests/{Support,Documents,Suppliers,Registry}/…Test.php` | pure helpers |
| `tests/Features/<X>/<X>Test.php` | one per slice |
| `tests/Invariants/PurchasingInvariantTest.php` | step 20 |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_16_003401_create_suppliers_table.php` … `003406_…` | 01 `suppliers`, 02 `purchase_requests`, 03 `purchase_orders`, 04 `purchase_order_items`, 05 `purchase_request_items`, 06 `purchase_order_receipt_applications`. All `@phase expand`, new tables with their keys inline. Shape: `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) statuses, full decimal arguments, `JsonValidity` on the JSON columns, no `down()`, docblocks naming every deferral. They sort after `2026_11_10_003010` and take versions 74 to 79 |
| `tests/Feature/QaPurchasingSourceRulesTest.php` | step 21 |
| `tests/Engine/PurchasingSchemaEngineTest.php` | step 22 |
| shared files | the predicted-red table |

## Data / API changes

**Six new tenant tables, no change to an existing table, no master change.** Money and quantity `(18,4)` unless stated. `[BY]` columns FK `employees`. Statuses VARCHAR(30) with application enums.

| Table | Columns | Keys | Not built (D-3) |
|---|---|---|---|
| `suppliers` | `id`, `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by`, `code` VARCHAR(50), `name` VARCHAR(150), `legal_name` VARCHAR(150) NULL, `contact_name` VARCHAR(100) NULL, `phone` VARCHAR(30) NULL, `email` VARCHAR(191) NULL, `address` JSON NULL, `tax_no` VARCHAR(50) NULL, `currency` CHAR(3) NULL, `payment_terms_days` SMALLINT UNSIGNED default 0, `credit_limit` NULL, `bank_details` JSON NULL, `is_active` default true, `notes` TEXT NULL | unique `code` (over every row, deleted included); index `name`; FKs `created_by`, `updated_by` (2) | `custom_fields`, `opening_balance`, `payable_account_id`, `default_expense_account_id` |
| `purchase_requests` | `id`, TS, `created_by`, `updated_by`, `number` VARCHAR(30), `branch_id`, `location_id`, `requested_by`, `required_date` DATE NULL, `status`, `workflow_instance_id` NULL, `notes` TEXT NULL | unique `number`; index (`branch_id`, `status`), (`status`); FKs `branch_id`, `location_id`, `requested_by`, `workflow_instance_id`, `created_by`, `updated_by` (6) | — |
| `purchase_orders` | `id`, TS, `created_by`, `updated_by`, `number` VARCHAR(30), `supplier_id`, **`supplier_name` VARCHAR(150), `supplier_tax_no` VARCHAR(50) NULL, `payment_terms_days` SMALLINT UNSIGNED** (fact 9), `branch_id`, `location_id`, `request_id` NULL, `order_date` DATE, `expected_date` DATE NULL, `status`, `workflow_instance_id` NULL, `currency` CHAR(3), `exchange_rate` (18,8) default 1, `subtotal`, `discount_total`, `tax_total`, `shipping`, `total`, `received_value` (all default 0), `terms` VARCHAR(1000) NULL, `notes` TEXT NULL | unique `number`; index (`supplier_id`, `status`), (`branch_id`, `order_date`), (`status`), (`request_id`); FKs `supplier_id`, `branch_id`, `location_id`, `request_id`, `workflow_instance_id`, `created_by`, `updated_by` (7) | `custom_fields` |
| `purchase_order_items` | `id`, `order_id` (**cascade**), `line_no` SMALLINT UNSIGNED, `product_id`, `description` VARCHAR(255), `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `unit_cost`, **`subtotal`, `discount_amount`** (the T-030 `sale_items.subtotal` precedent, so the header is a plain sum), `discount_percent` (9,4) default 0, `tax_group_id`, `tax_amount`, `line_total`, `received_quantity` default 0, `received_base_quantity` default 0, `expected_date` DATE NULL | unique (`order_id`, `line_no`); index `product_id`; FKs `order_id`, `product_id`, `unit_id`, `tax_group_id` (4) | `unit_code` (D-27), `tax_breakdown` (not asked; follow-up) |
| `purchase_request_items` | `id`, `request_id` (**cascade**), `line_no` SMALLINT UNSIGNED, `product_id`, `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `estimated_cost` NULL, `supplier_id` NULL, `purchase_order_item_id` NULL | unique (`request_id`, `line_no`); index `product_id`, `supplier_id`; FKs `request_id`, `product_id`, `unit_id`, `supplier_id`, `purchase_order_item_id` (5) | — |
| `purchase_order_receipt_applications` (**new, D-18**) | `id` (the caller's application ULID), `order_id`, `order_item_id`, `received_base_delta` (signed), `received_quantity_delta` (signed), `created_by` NULL, `created_at` | index `order_item_id`, `order_id`; FKs `order_id`, `order_item_id`, `created_by` (3), all RESTRICT | — |

- `line_no` is an addition: caller ULIDs do not sort by arrival, and a document prints in entry order. Next `line_no` = max + 1 under the header lock; a removed line leaves a gap.
- **FK count: 27**, all RESTRICT except the two line cascades. Migration order 01 to 06 is forced by the keys (`purchase_request_items` references `purchase_order_items`).
- `[UOM]`: both item tables carry the full quad; `purchase_order_items` also the `received_quantity`/`received_base_quantity` pair, exactly what `UomColumnSetsTest`'s pre-registered rows for these two tables expect.

**Precision map.**

| Pair | Columns |
|---|---|
| (18,4) | `suppliers.credit_limit`; `purchase_request_items.quantity`, `base_quantity`, `estimated_cost`; `purchase_orders.subtotal`, `discount_total`, `tax_total`, `shipping`, `total`, `received_value`; `purchase_order_items.quantity`, `base_quantity`, `unit_cost`, `subtotal`, `discount_amount`, `tax_amount`, `line_total`, `received_quantity`, `received_base_quantity`; both application deltas |
| (18,6) | both `unit_factor` |
| (9,4) | `purchase_order_items.discount_percent` |
| (18,8) | `purchase_orders.exchange_rate` |

**`APP_REQUIRED_SCHEMA_VERSION`:** 73 → **79**. Tenant tables 66 → **72**. The catalogue is republished (`schema:publish`), never hand-merged. All six are expand steps above contract step 73, so no fleet needs an extra stop.

**Refusal vocabulary (`PurchasingRefusal`, 31 codes):**

| Area | Codes |
|---|---|
| General | `validation`, `not_found`, `out_of_range`, `replay_conflict`, `invalid_transition`, `locked`, `not_authorised`, `too_many_lines`, `no_lines` |
| Suppliers | `duplicate_code`, `code_immutable`, `supplier_in_use`, `supplier_inactive`, `unknown_supplier`, `supplier_mismatch`, `currency_required` |
| Places and products | `unknown_branch`, `unknown_location`, `unknown_product`, `product_not_purchasable` |
| Units and pricing | `unit_refused` (cause `UnitRefusal`), `pricing_refused` (cause `PricingRefusal`), `no_default_tax_group`, `unknown_tax_group`, `inactive_tax_group` (F-6) |
| Numbering and workflow | `numbering_refused`, `number_too_long` (cause `DocumentNumbersRefusal`), `workflow_failed` (detail = the engine's string, previous = the exception) |
| Orders | `already_received`, `order_closed`, `order_not_open` |

**Audit actions (`PurchasingAuditActions`, module `purchasing`, 30):** `purchasing_supplier_{created,updated,activated,deactivated,deleted}`; `purchasing_request_{created,updated,line_added,line_updated,line_removed,submitted,approved,rejected,returned_to_draft,cancelled,converted}`; `purchasing_order_{created,updated,line_added,line_updated,line_removed,submitted,approved,returned_to_draft,rejected,reopened,cancelled,closed,receipt_applied}`, plus `purchasing_order_amended` for a post-approval `notes`/`terms`/date edit. Subject types `supplier`, `purchase_request`, `purchase_order`; line writes record against the header (D-23).

**Boundary (`deptrac.yaml`):** `Package_Purchasing` = `^Flexipos\\Purchasing\\(?!Tests\\).*` → `[Package_Kernel, Package_Contracts]`; `Package_Purchasing_Tests` → `[Package_Purchasing, Package_Contracts, Package_Kernel, Package_Access, Package_Pricing_Tests]`; `HostApp`/`HostTests` gain the layers; **`Module_Demo` is not granted it.**

**HTTP, console, jobs, hooks:** none. **Dependencies:** the path package only; `brick/math` is already locked.

## Implementation steps

Rules carried from T-027 to T-030: red-first (a fixture, or leaving something out; never stubbing a guard); the **red-list rule** after each schema step (record the red list, compare with the predicted-red table, stop on anything else); no child processes; unique test basenames (Pest file-level helper names prefixed `pu*`, never reused); list foreign `php.exe` processes before each gate run; never the bare string of the non-stock product type in an array tail or key position (`QaVocabularyRenameSweepTest` reports it), so eligibility is written as the positive list of F-2; no `svc` token anywhere. Mutations are run once, reverted byte for byte, and recorded in the hand-back with red/green counts. **Every gate below waits for `lead`'s "gate free".**

### Chunk A: baseline, plumbing, contract edits, schema, statics

- [ ] **Step 0: baseline and reconciliation.** Confirm `c354a0e` is an ancestor and the tree is clean. Run `composer check` with **no `APP_KEY`**; the failed set must be exactly the 13 by name of T-030's final gate (`QaModuleRegistryContractTest` 6, `QaModuleNameValidationTest` 3, `QaHealthProbeResilienceTest` 2, `ModulesEnvParsingTest` 1, `QaVocabularyRenameSweepTest` 1); anything else is a stop. Record passed/skipped and the `test` seconds (the 10 % reference, FP-328). Re-confirm every symbol in section 0.1 in a minute; report only differences.
- [ ] **Step 1: package plumbing.** `composer.json`, an empty provider, `providers.php`, `phpstan.neon.dist`, `deptrac.yaml`, host `composer.json` (`require`, `autoload-dev` `Flexipos\\Purchasing\\Tests\\`), `composer update flexipos/purchasing`, `'purchasing'` in `QaToolchainRegistryCoverageTest`'s literal (between `pricing` and `sales`). **`PurchasingProviderTest`:** the provider loads, `BigDecimal` autoloads. **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Purchasing\Support\Money` fails deptrac; one importing `Flexipos\Contracts\Pricing\PurchaseLinePricing` passes. Delete both.
- [ ] **Step 2: `PurchaseLinePricing`.** *(AC-43, AC-44's arithmetic half; Ruling 2.)* Contract, DTOs, `PurchaseLineCalculator`, adapter method, binding.
  - **Tests:** (a) AC-43's exclusive example gives `39.9996 / 4.0000 / 35.9996 / 3.6000 / 39.5996`; (b) the inclusive twin gives `tax 3.2727`, `lineTotal 35.9996`; (c) discount `0` and `100.00` (line total `0.0000`); (d) zero unit cost (D-28) gives all zeros; (e) a sale-only group taxes zero with no refusal (F-6); (f) an overflow is `out_of_range`; (g) `discountPercent` `100.0001` and a negative are refused by the DTO; (h) an inactive group computes normally (documents why purchasing checks the directory itself); (i) context is `Purchase` (a purchase-only member applies, a sale-only one does not).
  - **Mutations:** P1 round the discount half-down (row a: discount `3.9999` … red); P2 tax on `subtotal` instead of `taxable` (a red); P3 `lineTotal = taxable + tax` for inclusive (b red).
  - `QaPricingSourceRulesTest` stays green: the new pure class imports no Illuminate, the adapter's import list is unchanged.
- [ ] **Step 3: number prefixes.** *(D-10; Ruling 3.)* `DocumentNumbersTest` row: first use of each type gives `PR000001` / `PO000001`, independent sequences.
- [ ] **Step 4: migrations 01 to 06 with `PurchasingTenantSchemaTest`.** *(AC-68, AC-88, AC-89; schema halves of AC-1, AC-19, AC-40.)*
  - Sorted column lists equal the Data section exactly, with nullability (proves every D-3 absence).
  - Unique keys by raw double insert: `suppliers.code` (a deleted row still blocks), both `number`s, both (`header`, `line_no`).
  - The FK set equals the 27; both cascades delete their lines; deleting a supplier, product or order item referenced by an application is refused.
  - `SourceRules::inspect()` reports nothing; no `down()`; catalogue rows 74 to 79 are `expand`, `is_destructive = false`.
  - **Predicted red:** `Helpers.php`, `MigrationTreeDisciplineTest` (66 → 72), `TenantBaselineBuildsTest` (tables + 27 FKs), `QaAccessCloseOutSweepsTest` and `QaAccessSchemaShapeTest` (a `$t034` list), `IdempotencyKeysSchemaTest` (top 79, file 06, `expand`), the catalogue and `.env.example`. `UomColumnSetsTest` and `BranchForeignKeysContractRunTest` are expected **green** (the first already registers both item tables; the second derives the top and the new steps are expand); red on either is a stop.
- [ ] **Step 5: statics.** *(AC-89, AC-93's schema half.)* `TenantDecimalPrecisionTest` gains the T-034 map; red-first with a fixture `decimal('total', 18, 2)`. An absence scan: no T-034 migration declares `custom_fields`, `opening_balance`, `payable_account_id`, `default_expense_account_id`, `unit_code`, `tenant_id`; red-first with a scratch fixture migration.
  - **Gate: `composer check` (end of chunk A).**

### Chunk B: building blocks

- [ ] **Step 6: support, refusals, registry.** The copies (Ruling 1); `PurchasingRefused` (`because`, `fromUnit`, `fromPricing`, `fromNumbers`, `fromWorkflow`, `fromAccess`, `code()`); `PurchasingPermissions` (8, Ruling 9); `PurchasingAuditActions` (declared as slices land; the provider declares from this step, so `AuditCatalogueTest`'s two module lists gain `purchasing` here); `Authorise`; `Redaction`.
  - **Tests:** `PurchasingRegistryTest` pins the 31 codes, the 30 actions and the 8 keys with their scope; `Authorise` rows for AC-85 (a) to (c) and AC-86 (a suspended, an inactive, a deleted employee each refused with the resolver's reason); `BusinessClockTest` (midnight in `Asia/Colombo` vs UTC); `MoneyTest` (`product` half-up, `sum`, no float, never `-0.0000`).
  - **Predicted red:** `AuditCatalogueTest` lines 49 and 92; `QaAccessHttpSurfaceTest` line 684 (`accessBranchScopedKeys()` gains the six branch-scoped purchasing keys, sorted between `fixture.at_branch` and `sales.approvals.decide`). The other registry sweeps read the registry dynamically and stay green.
- [ ] **Step 7: document helpers.** `RequestStatus`/`OrderStatus` (Ruling 4 as a dataset: every allowed edge passes, **every** other pair is `invalid_transition` naming the status); `ApprovalOutcome` (each instance status x `on_reject` x document type); `ReceiptRollup` (pure: AC-70's 10 cases at 24 against two 120.0000 deliveries, AC-71, AC-72's `239.9999` vs `240.0000`, AC-73 over-receipt, AC-74 reversal to zero); `OrderRollup` (AC-45's `52.0996`; zero lines gives shipping only; overflow `out_of_range`); `Amendments` (AC-61, AC-63 field sets).
  - **Mutations:** R1 compare `received_quantity` instead of base (AC-70 row red); R2 `>` instead of `>=` (AC-72 row red); R3 status from the last line only (AC-71 red).
- [ ] **Step 8: lookups and the line quoter.** `Places` (branch via `BranchDirectory`, location per F-3), `ProductFacts` (F-2, one `whereIn` for a batch), `TaxGroupChain` (explicit → `purchase_tax_group_id` → `defaultGroupId()` → `no_default_tax_group`; then `state()`: `unknown_tax_group`, `inactive_tax_group`), `LineQuoter` (`snapshot()` or `snapshotInherited()`, then `quotePurchaseLine()` with `documentDate = order_date`).
  - **Tests (real resolver, real engine, `PricingSeed`):** each refusal by name with its cause; a product's purchase group wins over the default; an explicit group wins over both; the query count for one line is recorded through `PurchasingQueryLog` (AC-94).
  - **Gate: `composer check` (end of chunk B).**

### Chunk C: suppliers

- [ ] **Step 9: `CreateSupplier`, `UpdateSupplier`.** *(AC-1 to AC-12, AC-17.)* Datasets for every AC-2/AC-4/AC-5/AC-6 boundary (`30` vs `31` chars, `-1`/`3651`, `"2500"` → `"2500.0000"`, a JSON float, 5 decimals, 15 integer digits); AC-3 against an active, an inactive and a deleted holder (refusal names its id; the unique key is the backstop on a race); AC-9 replay identical and different; AC-10 subset update, no-change update writes no audit row; AC-11 `code_immutable`; AC-17 marker in the audit row on create, on a `bank_details` change (`changed_fields` lists it) and on an unrelated change (the marker is present, unchanged); AC-12 is in step 13.
- [ ] **Step 10: `ActivateSupplier`, `DeactivateSupplier`, `DeleteSupplier`.** *(AC-13, AC-14.)* Idempotent repeats write nothing; delete refused per blocking document status (a dataset over each non-terminal request status via a line's `supplier_id`, and each non-terminal order status) and accepted when all are terminal; deleted is retained, code still reserved, `get` is `not_found`.
- [ ] **Step 11: `GetSupplier`, `ListSuppliers`.** *(AC-15, AC-16, AC-18.)* `bank_details` absent for view-only, present for manage; keyset by `code` with `after_code`; filters combine; `page_size` 101 is `validation`; deleted never listed. **AC-18:** one row per supplier slice without the key (`not_authorised`, cause `NotPermitted`, nothing written), and one row asserting the key is asked with a **null** branch and is not refused `BranchRequired`.
  - **Mutation S1:** drop the deleted-row filter from the AC-3 pre-check; the deleted-holder row goes red (the unique key then refuses with an unhandled error, which the row also asserts is not what happens).
  - **Gate: `composer check` (end of chunk C).**

### Chunk D: purchase requests

- [ ] **Step 12: create, header edit, lines.** `CreatePurchaseRequest` (`transaction(..., 2)`, number last), `UpdatePurchaseRequest`, `Add/Update/RemovePurchaseRequestLine`. *(AC-19 to AC-25, AC-36, AC-39.)* Rows: `requested_by` is the actor even when a caller field names someone else (refused as unknown field); no number consumed on any refusal (`number_sequences` compared whole); the unit quad equals `snapshot()`'s; AC-24 ladder edit after the line changes nothing on read; the 201st line is `too_many_lines`; `locked` outside `draft`; replay identical/different; the three deadlock-fake rows from `DeadlockingNumbers` (a first-attempt deadlock retries once and leaves one number, one audit row; two deadlocks surface the engine error; nested in an outer transaction it does not retry).
- [ ] **Step 13: submit, sync, cancel.** `SubmitPurchaseRequest`, `SyncRequestApproval`, `CancelPurchaseRequest`. *(AC-26 to AC-29, AC-37.)*
  - With `FakeWorkflowGate`: the exact `WorkflowContext` (entity type, id, requester, branch, amount `"39.9996"` for 12 x 3.3333 plus an unestimated line, fields `location_id`, `line_count`); `no_lines` never calls the gate; `workflow_failed` leaves `draft`, no instance, no audit row (fail closed); second submit returns it and calls the gate zero more times.
  - With the **real engine** (`PurchasingSeed::workflowDefinition`, approver decides through `WorkflowApprovals::decide()`): pending → approve → sync gives `approved`; reject with each `on_reject` gives `rejected`; engine `cancel()` gives `draft` with the instance cleared; re-sync is a no-op with no audit row; `expired` by a raw instance update.
  - Cancel from each allowed status (a pending one cancels the instance; the `instanceClosed` race proceeds, Ruling 6); a second cancel is a no-op; rejected/converted are `invalid_transition`.
  - **Mutations:** W1 catch `WorkflowEvaluationFailed` and approve (fail-closed row red); W2 skip the entity-id match in sync (the mismatched-instance row red).
- [ ] **Step 14: `GetPurchaseRequest`, `ListPurchaseRequests`, permission sweep.** *(AC-21, AC-34, AC-35, AC-38.)* Branch-A grant refused at B, company-wide accepted, list without a branch filter returns only allowed branches; one refusal row per request slice (AC-38), each asserting nothing written.
  - **Gate: `composer check` (end of chunk D).**

### Chunk E: draft orders and conversion

- [ ] **Step 15: `CreatePurchaseOrder`, `UpdatePurchaseOrder` (draft half).** *(AC-40, AC-41, AC-50, AC-12, AC-53.)* Defaults (`order_date` = branch-local today, `exchange_rate 1.00000000`, totals `0.0000`, snapshots copied); `currency_required` (F-4 default); every AC-41 refusal consumes no number; supplier change re-copies three snapshots and keeps currency; AC-12: a supplier edit leaves the order's snapshots unchanged; replay; deadlock rows.
- [ ] **Step 16: order lines.** `Add/Update/RemovePurchaseOrderLine`. *(AC-42 to AC-49, AC-51.)* AC-43 and its inclusive twin stored as strings and returned as strings (a JSON float in any money/quantity/percent/rate field is `validation`, AC-46); AC-45 totals after each add/edit/remove; removing the last line leaves zeros plus shipping; AC-47 a line whose `line_total` fits but whose header `total` overflows refuses that line; AC-48 rate, group, price, factor and supplier changed after the line: read unchanged; AC-49 an edit that changes unit or quantity re-snapshots, an edit of cost only does not call the resolver; `description` defaults to the product name at that moment; line `expected_date` before `order_date` is `validation`; 201st line; line replay (AC-53).
- [ ] **Step 17: `ConvertRequestToOrder`.** *(AC-30 to AC-33, AC-94.)* One transaction: order, lines, `purchase_order_item_id` on each request line, request `converted`, two audit rows, `PO` number; AC-31 a request line whose ladder moved since keeps its stored factor (`snapshotInherited`); unestimated cost becomes `0.0000`; each refusal in AC-32 writes nothing and consumes no number (dataset, `number_sequences` compared whole); AC-33 same-supplier replay and different-supplier `invalid_transition`; cancelling the order leaves the request `converted`; F-16 removing a converted line clears the request line's pointer. **AC-94:** the query count for a 50-line conversion is recorded (not asserted against a cap) and product facts are read once for the batch.
- [ ] **Step 18: `GetPurchaseOrder`, `ListPurchaseOrders`.** *(AC-81 to AC-83, AC-84, AC-85.)* Every field AC-81 names, money as strings; a soft-deleted supplier's order still reads its snapshot; list filters including an inverted date range (`validation`); permission rows.
  - **Gate: `composer check` (end of chunk E).**

### Chunk F: approval, amendment, receipt, events

- [ ] **Step 19a: `SubmitPurchaseOrder`, `SyncOrderApproval`.** *(AC-54 to AC-59, AC-97 approved.)* Submit refusals (`no_lines`, `supplier_inactive` including deleted, `unknown_location`); no stored figure changes on submit (row compares every column); the `WorkflowContext` fields; `NotRequired` approves with one audit row and one `purchase_order.approved` event whose per-line payload matches the lines; `Pending`; `workflow_failed` writes no audit and no event; sync mapping for all five instance outcomes and both `on_reject`; mismatch is `invalid_transition`.
- [ ] **Step 19b: amendment, `ReopenPurchaseOrder`, `CancelPurchaseOrder`, `ClosePurchaseOrder`.** *(AC-60 to AC-67, AC-97.)* A dataset over every field x status giving applied or `locked` (naming the field; the message names reopen); reopen clears the instance, audits the reason and the old id, writes the event, and a resubmit opens a **new** instance (`evaluate()` called again); reopen with an application row is `already_received` (F-10); cancel reason rules and the event only when approved; close from both statuses with the event; repeats are no-ops; `order_closed` for `received`/`closed`/`cancelled` edits.
- [ ] **Step 19c: `ApplyReceivedQuantity`.** *(AC-68 to AC-80, AC-96.)* AC-70 end to end through the slice; AC-71 to AC-74; AC-75 per status (F-9 reading); AC-76 replay identical and different; AC-77 validation dataset (F-15 reading); AC-79 no stock, ledger or `quantity_incoming` token (also step 21); one audit row and one `received_applied` event per real application; nested inside a caller's open transaction it does not commit on its own (the caller's rollback removes it); its docblock states it carries no permission check and why (AC-96).
  - **Mutations:** A1 lock the line before the header (the SQL-shape row pinning `for update` on `purchase_orders` as the first statement goes red; run-time proof is step 22's); A2 accept a delta taking a column below zero (AC-74 boundary red); A3 write the event on a replay (AC-76 row red).
  - **Gate: `composer check` (end of chunk F).**

### Chunk G: sweeps, scans, engine, documents, close-out

- [ ] **Step 20: `PurchasingInvariantTest`.** A scripted day through the real slices: three suppliers; a request with a supplier line, submitted under a real definition, approved, synced, converted; a direct order, amended, submitted (`NotRequired`); one reopened and resubmitted; partial and full receipts in a different unit (AC-70) with one reversal; one closed short; one cancelled. Assert: numbers gapless per type; one audit row per write and none per replay (actions a subset of `PurchasingAuditActions::ALL`); every header total equals the sum of its lines plus shipping; every status equals `ReceiptRollup` over its stored lines; every event carries no supplier name, address or `bank_details`; no `bank_details` content in any audit row.
- [ ] **Step 21: `QaPurchasingSourceRulesTest` (host),** code-only via `SourceRules::codeOnly`. (a) No token in `packages/purchasing/src` from the F-1 list (`unit_conversions`, `base_unit_id`, `tax_rates`, `tax_group_taxes`, `price_list_items`, `is_inclusive`, `number_sequences`, `workflow_instances`, `workflow_tasks`, `workflow_events`, `customer_ledger`, `stock_levels`, `stock_movements`, `journal_`, `quantity_incoming`, `grns`); (b) no `*`, `/`, `multipliedBy(`, `dividedBy(` outside `Support/Money.php`, and `Money::product(` called only from the request-amount helper; (c) no `(float)`, `floatval`, `float ` type; (d) no `routes/`, `Route::`, `Cache::`, `dispatch(`; (e) anti-vacuity: T-027's factor scan and T-029's AC-25 scans enumerate a `packages/purchasing/src` file and report zero with no purchasing exemption. Red-first planted files for each rule, then deleted. *(AC-46, AC-79, AC-93.)*
- [ ] **Step 22: `PurchasingSchemaEngineTest`** (database `flexipos_t8test_t034_tenant`, created and dropped by **exact name** through `EngineDatabase`, never `LIKE`). Written, skipped under the hold except the hold-refusal case: `information_schema` decimals equal the map; JSON validity checks; AC-78 two connections applying to two lines of one order (the second waits on the header, final status matches the sums); concurrent first-use `PO` numbering through two `CreatePurchaseOrder` calls (the `transaction(..., 2)` retry); `DeleteSupplier` racing `CreatePurchaseOrder` (one waits, the outcome is consistent); a tenant-B read of a tenant-A id on a real engine; migrating twice is a no-op. *(AC-78, AC-88, AC-89 engine halves; AC-98.)*
- [ ] **Step 23: cross-tenant rows on the stand-in.** One row per root entity (supplier, request, order, application): created in `tenant-a`, read and written by id in `tenant-b` through every get and one write slice each, `not_found`, nothing written in either. *(AC-88.)*
- [ ] **Step 24: documents.** Describe, never quote, old wording (memory note). `docs/database.md` §6.6 "As built (T-034)": the six tables, the three header snapshots and `line_no`/`subtotal`/`discount_amount` additions, the applications table, the deferred columns, the two state machines, the lock order (Ruling 7) as the order FP-255 must keep, the base-to-base rule. §6.1: the `PR`/`PO` prefixes. §6.4: `PurchaseLinePricing`. `docs/architecture.md` module table: Purchasing points at `packages/purchasing` with what is and is not built. Follow-ups to `lead`.
- [ ] **Step 25: final gate.** `composer check`, counts and `test` seconds against step 0; then `composer test:fast` once. More than 10 % on the `test` step is a stop and a report (FP-328).

## Test strategy

- **Unit (pure):** `PurchaseLineCalculator`, `Money`, `BusinessClock`, both status machines as full transition datasets, `ApprovalOutcome`, `ReceiptRollup`, `OrderRollup`, `Amendments`, `SupplierCode`, `Redaction`.
- **Integration (SQLite stand-in, real implementations):** one file per slice against the real `UnitResolver`, `PricingEngine`/`PurchaseLinePricing`, `TaxGroupDirectory`, `DocumentNumbers`, `PermissionResolver`, `AuditTrail`, `Outbox` and, for the approval rows, the real `WorkflowGate`. `FakeWorkflowGate` only for the call-shape and failure datasets. Every refusal row compares purchasing's tables, `audit_logs`, `outbox_events`, `number_sequences` and `workflow_instances` whole before and after. Lock order pinned as SQL text on the stand-in (`LockMarkingGrammar` from pricing's test support, which `Package_Pricing_Tests` grants; sales' copy is not reachable); run-time proof is step 22's.
- **Static:** `PurchasingTenantSchemaTest`, `TenantDecimalPrecisionTest`, `QaPurchasingSourceRulesTest`, `UomColumnSetsTest` (now non-vacuous for two more tables), deptrac with the demo control.
- **Engine:** `PurchasingSchemaEngineTest`, skipped under `ENGINE_HOLD`.
- **Mutations:** P1–P3, R1–R3, S1, W1–W2, A1–A3, each reverted byte for byte.

| ACs | Proved by |
|---|---|
| 1–12, 17 | 9 |
| 13, 14 | 10 |
| 15, 16, 18 | 11 |
| 19–25, 36, 39 | 12, 4 |
| 26–29, 37 | 7, 13 |
| 30–33 | 17 |
| 34, 35, 38 | 14 |
| 40, 41, 50, 53 | 15 |
| 42–49, 51 | 2, 7, 16 |
| 52 | 6, 8, 12, 13, 19a |
| 54–59 | 19a |
| 60–67 | 7, 19b |
| 68–80 | 7, 19c, 22 (AC-78) |
| 81–83 | 18 |
| 84–86 | 6, 11, 14, 18, every slice file |
| 87 | every slice file, 20 |
| 88 | 4, 23, 22 |
| 89 | 4, 5 |
| 90 | every refusal row, 12, 15, 17 |
| 91, 92 | 6 (registry pins), every slice file |
| 93 | 1, 21 |
| 94 | 8, 17 (recorded counts) |
| 95 | 6 |
| 96 | 19c |
| 97 | 19a, 19b, 19c, 20 |
| 98 | 22, the gate discipline above |

## Risks & rollback

1. **No sync trigger (highest).** Nothing in production moves a `pending_approval` document once an approver decides, until a caller invokes `Sync*`. Harmless today (no routes, no inbox), but FP-256 and the approver inbox must call it, or L-1's sweep must exist before go-live.
2. **Contract edit in pricing.** Additive and separate, but pricing is T-029's code. `qa` reviews steps 2 and 3 as their owners' code.
3. **NOT VERIFIED under `ENGINE_HOLD`:** the lock order, the `transaction(..., 2)` retry, the first-use gap-lock deadlock, AC-78 concurrency, JSON checks. Same debt class as FP-447, FP-448, FP-453.
4. **Merge renumbering.** T-031 to T-033 add migrations and contract layers; versions 74–79, the table count, `IdempotencyKeysSchemaTest` and the catalogue are renumbered at merge (accepted). Republish, never hand-merge.
5. **Query cost.** `snapshot()` is 5 queries per line; a 200-line order is about 1000 resolver queries plus pricing. Measured (AC-94), not capped; FP-426 is the remedy.
6. **Create replay after an edit is `replay_conflict`.** A client that retries a create after the document was edited gets a refusal, not the row. Safe; noted for FP-256.
7. **List scoping costs one permission decision per branch** when no branch filter is given. Fine at tens of branches; a "branches where I hold key K" resolver query is the remedy if measured slow.
8. **A receipt reversed after close** is `order_not_open` (F-9). FP-255 must reverse before closing, or a later task allows reversals on `closed`.
9. **Supplier delete vs. a concurrent request line** naming the supplier: the line takes the supplier `FOR SHARE` (Ruling 7 row 2), so the delete waits; proved only on the engine (step 22).

**Rollback:** everything is new except the pricing contract, two prefixes and the shared test files below. The migrations are forward-only and no tenant outside this branch has run them; rollback is abandoning the branch.

## Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock`, `deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | package wiring | 1 |
| `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php` | `'purchasing'` in the literal (line 446) | 1 |
| `packages/contracts/src/Pricing/*` (3 new), `packages/pricing/src/Engine/DatabasePricingEngine.php`, `PricingServiceProvider.php` | Ruling 2 | 2 |
| `packages/organization/src/Numbering/DatabaseDocumentNumbers.php`, `packages/organization/tests/Numbering/DocumentNumbersTest.php` | two prefixes, one row | 3 |
| `database/catalogue/tenant_schema_versions.json`, `.env.example` | republished; 73 → 79 | 4 |
| `tests/Helpers.php` `tenantTreeTables()`, `MigrationTreeDisciplineTest` (66 → 72), `TenantBaselineBuildsTest` (tables, +27 FKs), `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest` (`$t034`) | schema | 4 |
| `tests/Feature/IdempotencyKeysSchemaTest.php` | top 79, file `2026_11_16_003406_create_purchase_order_receipt_applications_table`, `expand` | 4 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | the T-034 map and the absence scan | 5 |
| `packages/access/tests/Support/AuditCatalogueTest.php` | `purchasing` in both module lists (lines 49, 92) | 6 |
| `tests/Feature/QaAccessHttpSurfaceTest.php` | line 684: the six branch-scoped purchasing keys in the sorted list | 6 |
| `UomColumnSetsTest`, `BranchForeignKeysContractRunTest`, `SchemaCataloguePublishTest`, `QaPricingSourceRulesTest`, `QaCatalogSourceRulesTest`, the other permission-registry sweeps, every sales test | **expected green with no edit**; red on any is a stop | 2–6 |
| `docs/database.md`, `docs/architecture.md` | step 24 | 24 |

Anything red that is not in this table means stop and report to `architect`.

## Follow-ups for `lead` (backlog rows, not built here)

- **A trigger for `SyncOrderApproval`/`SyncRequestApproval`** (L-1): an engine outbox event on instance completion consumed by purchasing, a console sweep, or the approver inbox calling sync after `decide()`.
- **FP-250:** a `Contracts\Purchasing` read contract for `quantity_incoming` (rebuildable), in preference to maintaining it from events alone (fact 10).
- **FP-255:** calls `ApplyReceivedQuantity` inside its receipt transaction, keeps Ruling 7's lock order, maintains `received_value`, owns over-receipt tolerance (Q-1) and reversal after close (risk 8).
- **Catalog and Organization fact contracts** (F-1): two packages now read `products`, `branches`, `locations` directly.
- **FP-331:** the eleventh support copy, now including a second `BusinessClock`.
- **FP-426:** a batch unit resolve; T-034 records the 1-line and 50-line counts.
- **Unit code on printed purchase orders** (Q-4, D-27) and a stored `tax_breakdown` on order lines for reprint and AP.
- **Approver notifications** map `purchase.approved` to `purchase_order.approved` (F-19).
- **Deferred columns** (D-3): `payable_account_id`, `default_expense_account_id` (FP-262), `opening_balance` (FP-265), `custom_fields` (when `custom_field_definitions` exists).
- **Engine evidence** for step 22's cases, alongside FP-453, before the hold lifts.

## Questions for `lead`

- **L-1: build a sync trigger now?** Default: no; the pull slices only, and a follow-up row. The cheapest real trigger is a console command `purchasing:sync-approvals` sweeping `pending_approval` documents (about one step in chunk F, no engine change). Say if you want it.
- **L-2: accept Ruling 2's pricing contract addition** (an edit in T-029's package) rather than an AC-25(b) exemption? Default: the contract.
- **L-3: relay F-1 to F-19 to `ba`.** None blocks; each default is built. F-4 (branch-currency fallback), F-9/F-10 (receipt status and reopen rules) and F-15 (zero quantity delta) change visible behaviour.

## Questions for the human

None of the plan's own. The spec's Q-1 to Q-4 stand with their built defaults, and F-3 (deliveries to a branch-less central warehouse) is a product question `lead` may want to put to the owner.
