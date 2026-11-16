# Plan: T-034 Suppliers, purchase requests and purchase orders

Spec: `docs/specs/T-034-suppliers-purchase-orders.md` **revision 2** (AC-1 to AC-98). Decisions: `docs/decisions/T-034-suppliers-purchase-orders.md` (D-1 to D-53; where D-31 to D-53 conflict with D-1 to D-30, the later row wins). Backlog FP-254. Branch `ai/T-034-suppliers-purchase-orders`, worktree `.claude/worktrees/purchase-orders`, cut from the T-030 tip `c354a0e` (which contains T-029 pricing).

**Plan revision 2**, planned at `c437232`. Revision 1 (`4d28fef`) was written against spec revision 1; its findings C-1 to C-12, F-1 to F-19 and lead questions L-1, L-2 are all answered by D-31 to D-53 and are folded in below, not repeated. Every claim about built code in this revision was re-read in source at `c437232` (section 0.2).

**Delivery rule (`ENGINE_HOLD`).** `architect` ran no test, no composer and no gate. During a chunk `dev` runs **only** the targeted Pest files named in that chunk plus `composer lint`, `composer stan` and `composer deptrac`. The single full `composer check` is scheduled by `lead`, never started by `dev`. Real-engine tests (`modules/host/tests/Engine/*`) are written and stay skipped. Nothing here connects to a real database engine and nothing claims a green gate.

---

## Revision 2 change log (what moved since revision 1)

| Area | Revision 1 | Revision 2 | Source |
|---|---|---|---|
| Audit action names | 30, including `purchasing_order_rejected` and `purchasing_order_amended` | exactly the spec preamble's **28** snake-case `purchasing_*` names; the two extras dropped (see Q-B1, Q-B2) | D-31, spec preamble |
| Refusal codes | 31, with `unit_refused` | **32**: `unit_refused` replaced by `unit_unresolvable` and `inherited_unit_mismatch` | D-37 |
| Supplier ids | caller-supplied ULID, replayable | **server-generated**; a retry is `duplicate_code`; a supplied `id` field is `validation` (Q-B3) | D-35, AC-1, AC-9 |
| `suppliers.email` | cap 190 | cap and column **191** | D-32 |
| `suppliers.credit_limit` | nullable | **NOT NULL DEFAULT 0**; omitted stores `0.0000`, explicit null is `validation` | D-32, AC-6 |
| List paging | oversize `page_size` refused | oversize **clamped to 100**; non-positive, non-integer or malformed cursor still `validation` | D-34 |
| Order currency | explicit, else supplier, else `currency_required` | explicit, else supplier, else **branch currency**; `currency_required` only if the branch has none (Q-B4) | D-41 |
| Sync trigger | lead question L-1 | settled: **none built**, pull-only, owner-visible follow-up | D-52 |
| Pricing contract, prefixes, clock | lead question L-2 | settled: `PurchaseLinePricing`, `PR`/`PO`, `BusinessClock` copied | D-53 |
| Step 0 | `dev` runs a baseline `composer check` | step 0 is **this document sync**; baselines and gates are `lead`'s | lead instruction |
| Chunk tests | "Gate: composer check" per chunk | each step names its **targeted Pest files**; `lead` runs the chunk gate | lead instruction |
| Shared test edits | partial list | full list with exact edits (section 0.3), including `SchemaCataloguePublishTest`, `LoyaltySaleIdMigrationsTest` and the branch-key list in `TenantBaselineBuildsTest`, which revision 1 missed | re-verification |
| Test basenames | `MoneyTest`, `BusinessClockTest` | **`PurchasingMoneyTest`, `PurchasingBusinessClockTest`** (sales owns the bare names; the tree has no duplicate test basename today) | re-verification |
| Config | in the provider | `modules/host/config/purchasing.php`, the `config/sales.php` / `config/finance.php` precedent | re-verification |
| Version numbers | 74 to 79, "renumbered at merge" | same numbers, plus the rule that **no T-034 test hard-codes a catalogue position** (section 0.4) | lead instruction |

---

## 0. Verification

### 0.1 Facts about the engines (from revision 1, re-confirmed at `c437232`)

1. **The workflow engine never notifies the owning module.** `WorkflowGate` (`packages/contracts/src/Workflow/WorkflowGate.php`) exposes `evaluate(WorkflowEntityType, WorkflowContext): WorkflowDecision`, `instance(string): WorkflowInstanceState`, `cancel(string, ?string, ?string): WorkflowInstanceState`; nothing calls back. `WorkflowEntityType::PurchaseRequest` and `::PurchaseOrder` exist. So `Sync*` are pull slices with no trigger (D-52).
2. **`WorkflowInstanceState`** carries `entityType`, `entityId`, `status` (pending, approved, rejected, cancelled, expired) and `onReject` (stop, return_to_requester). `WorkflowEvaluationFailed` and `WorkflowActionRefused` carry plain strings, not enums (D-43).
3. **`DocumentNumbers`** (`packages/contracts/src/Organization/DocumentNumbers.php`) is `next(string $documentType, ?string $branchId): string`, throws `DocumentNumbersRefused` (enum `DocumentNumbersRefusal`). The implementation `packages/organization/src/Numbering/DatabaseDocumentNumbers.php` line 64 holds `DEFAULT_PREFIXES = ['sale' => 'S', 'refund' => 'R', 'cash_shift' => 'SH']`, padding 6; `purchase_request` and `purchase_order` would get no prefix today. First use of a type inserts the company-wide row after a `FOR UPDATE` read of an absent key (gap-lock deadlock risk, NOT VERIFIED under the hold); T-030's remedy is an outermost `transaction(..., 2)`.
4. **No contract exposes product or location facts.** `Contracts\Catalog` holds only `UnitResolver` and its DTOs; `BranchDirectory::state()` answers a branch only. Purchasing reads `products`, `locations`, `branches` directly, read-only (D-38). Columns verified: `products.type` (cases `simple`, `variant_parent`, `variant`, the non-stock type, `bundle`, `composite`), `is_active`, `is_purchasable`, `track_stock`, `deleted_at`, `purchase_tax_group_id`, `name`; `locations.branch_id` **nullable**, `is_active`, `is_purchasable_to`, `deleted_at`; `branches.timezone` VARCHAR(64), `branches.currency` CHAR(3) **NOT NULL**.
5. **`UnitResolver`**: `snapshot()` costs five queries, `snapshotInherited()` two; no batch method (FP-426). `UnitRefusal` has `InheritedUnitMismatch` and the snapshot cases.
6. **Pricing.** `PricingEngine` has `quoteLine`, `computeTax`, `billDiscount`; `DatabasePricingEngine` (`implements PricingEngine`) owns private `taxGroup()` (keyed by `id`, computes an inactive group normally) and `members()`. `TaxCalculator::compute(array $members, string $amount, string $baseQuantity, string $date, TaxContext $context, bool $inclusive): TaxBreakdown` skips members whose `applies_to` excludes the context. `TaxContext::Purchase` exists. `TaxGroupDirectory::state()` returns `Unknown | Active | Inactive`; `defaultGroupId()` exists. `Engine/Decimal` has `round`, `percentOf`, `text`. **Two implementers of `PricingEngine` exist** (`DatabasePricingEngine`, `packages/sales/tests/Support/FakePricingEngine.php`), so a new method on `PricingEngine` would break the fake: a separate interface is required (D-53a).
7. **`QaPricingSourceRulesTest` AC-25(b)** reports any multiplication next to a token ending in `rate` or `percent` in every `packages/*/src` outside `packages/pricing/src/Engine`; so purchasing may not compute `subtotal x discount_percent / 100` (D-53a).
8. **`[CF]`** was not built on `customers` (T-028), so `suppliers.custom_fields` and `purchase_orders.custom_fields` are not built (D-3).
9. **Clock.** `Flexipos\Sales\Support\BusinessClock::businessDate(string $timezone)` over the kernel `RunnerClock`; deptrac forbids purchasing to import sales, so it is copied (D-53c).
10. **AC-43 by hand** (half-up at 4 per step): subtotal `39.9996`, discount `3.99996` → `4.0000`, net `35.9996`, tax `3.59996` → `3.6000`, line total `39.5996`; AC-45 `39.5996 + 10.0000 + 2.5000 = 52.0996`; inclusive twin net `round(35.9996 / 1.1, 4) = 32.7269`, tax `3.2727`, line total `32.7269 + 3.2727 = 35.9996`. All spec figures are right.

### 0.2 Re-verified at `c437232`: code this plan touches or depends on

| Subject | Found | Consequence |
|---|---|---|
| `packages/contracts/src/Organization/` | `DocumentNumbers`, `DocumentNumbersRefusal`, `DocumentNumbersRefused`, `BranchDirectory`, `BranchState`, and terminal classes | the contract needs no edit; only the implementation's `DEFAULT_PREFIXES` gains `'purchase_request' => 'PR'`, `'purchase_order' => 'PO'` (step 3). The contract's docblock names only `sale`, `refund`, `cash_shift` as examples; leave it |
| `packages/contracts/src/Pricing/` | 17 files: `PricingEngine`, `LineQuote`, `PricedLine`, `TaxQuote`, `TaxBreakdown`, `TaxGroupDirectory`, `TaxGroupState`, `TaxContext`, `Shape` (`money` 18,4; `rate` 9,4; `factor`; `ulid`; `date`; `currency`; throw `PricingRefused`), `PricingRefusal` (incl. `OutOfRange`, `UnknownTaxGroup`), and others | three new files (step 2). `Shape` validates the new DTOs; `discountPercent <= 100` is an extra check in the DTO |
| Vertical-slice rule, `modules/host/tests/Feature/VerticalSliceLayoutTest.php` | a slice is **any direct child of a directory named `Features`** under `<package>/src`; it must hold `<X>.php` and `<X>Handler.php`, and `<package>/tests/<same path>/Features/<X>/` must hold at least one `*Test.php` | every slice lands in the same commit as its mirrored test folder; no non-slice folder may be named `Features`; slices are flat under `packages/purchasing/src/Features/` (no grouping sub-folder, or the group becomes the "slice") |
| Test namespaces, `QaToolchainRegistryCoverageTest` "records only test trees that genuinely declare no namespace" | the global-namespace set is frozen at `modules/demo/tests`, `packages/auth/tests`, `packages/kernel/tests` | every purchasing test file declares `namespace Flexipos\Purchasing\Tests\...` |
| Test basenames | no duplicate `*Test.php` basename exists in the tree; sales already owns `MoneyTest.php`, `BusinessClockTest.php`, `DecimalTest.php` exists in pricing | purchasing test files are prefixed where a bare name collides; Pest file-level helpers prefixed `pu`, constants `PU_` (none exist today) |
| Top schema version, `modules/host/database/catalogue/tenant_schema_versions.json` | **73**, `2026_11_10_003010_add_loyalty_sale_foreign_key`, `contract`; 73 tenant migration files; 66 tenant tables; `.env.example` `APP_REQUIRED_SCHEMA_VERSION=73` | **next free version 74**; files `2026_11_16_003401_…` to `2026_11_16_003406_…` take 74 to 79 |
| Sibling branch T-032 (`ai/T-032-chart-of-accounts`, tip `81a9169`) | three tenant migrations `2026_11_15_003201` to `003203` at versions **74 to 76**; its plan says further finance files (a contract file at 77, expands after) may follow | see section 0.4 |
| Catalogue regeneration | `schema:publish` without options **writes to the master `schema_versions` table**; `schema:publish --write-fixture` only rewrites the JSON fixture ("Nothing was published") | under `ENGINE_HOLD` use **only** `php artisan schema:publish --write-fixture` |
| Paging helper | `Flexipos\Kernel\Audit\PageSize::clamp()` treats null **or < 1** as the default (T-014 D-28) and clamps above the max; sales' `Pager` uses it | purchasing validates `page_size` itself first (non-integer or < 1 is `validation`, AC-16, D-34), then clamps. This is a deliberate difference from T-014 D-28, recorded here, not a question |
| Sales support set | `BusinessClock`, `Decimal`, `Fields` (`only()` refuses unknown keys as `validation`), `Identifier`, `Lookup`, `Money`, `Page`, `Pager` (by id, by `created_at`), `TenantConnection`, **`Uniqueness`** (case-insensitive code pre-check, deleted rows included) | copy all ten (Uniqueness was missing in revision 1; it is exactly AC-3's pre-check); add `Pager::pageByCode()` for suppliers |

### 0.3 Hard-coded tests a new module and new migrations must keep in sync

Each is red until its edit lands. The edit, the step that makes it, and how T-032 made the same edit (copy its shape to keep the merge mechanical):

| File | Today | T-034 edit | Step |
|---|---|---|---|
| `modules/host/tests/Feature/QaToolchainRegistryCoverageTest.php` line 446 | literal list of 15 packages | add `'purchasing'` between `'pricing'` and `'sales'`; extend the comment on line 445 with "'purchasing' is T-034's." (T-032 adds `'finance'` on the same line: merge conflict, union both) | 1 |
| `packages/access/tests/Support/AuditCatalogueTest.php` lines 48, 49, 92 | `['access', 'catalog', 'customers', 'organization', 'pricing', 'sales']`; with fixture at 92 | add `'purchasing'` after `'pricing'` on 49 and 92, and in the test description string on 48 | 6 (when the provider starts declaring) |
| `modules/host/tests/Feature/QaAccessHttpSurfaceTest.php` line 684 | `['fixture.at_branch', 'sales.approvals.decide', 'sales.shifts.reconcile']` | insert the six branch-scoped keys sorted between: `purchasing.orders.close`, `purchasing.orders.manage`, `purchasing.orders.reopen`, `purchasing.orders.view`, `purchasing.requests.manage`, `purchasing.requests.view` (supplier keys are not branch-scoped and do not appear) | 6 |
| `modules/host/tests/Helpers.php` `tenantTreeTables()` (around line 593) | 66 names, sorted on return | append `suppliers`, `purchase_requests`, `purchase_orders`, `purchase_order_items`, `purchase_request_items`, `purchase_order_receipt_applications` with a "T-034 step 4" comment | 4 |
| `modules/host/tests/Feature/MigrationTreeDisciplineTest.php` line 152 | `toHaveCount(66)` | `toHaveCount(72)` plus a "T-034 step 4 adds the six purchasing tables (66 becomes 72)" comment; the file-count identity holds (one table per file) | 4 |
| `modules/host/tests/Feature/IdempotencyKeysSchemaTest.php` lines 121 to 123 | top 73, `…003010_add_loyalty_sale_foreign_key`, `contract` | top **79**, `2026_11_16_003406_create_purchase_order_receipt_applications_table`, `expand`, with a one-line T-034 note (the house "whoever ships next" pattern; this is the **one** place a top version is pinned) | 4 |
| `modules/host/tests/Feature/TenantBaselineBuildsTest.php` lines 325 to 476 | the exact sorted FK inventory | add the **27** keys of the Data section, each sorted into place, with a T-034 comment | 4 |
| same file, lines 490 to 505 | tables with a key to `branches` | add `purchase_orders`, `purchase_requests` (sorted after `product_branches`) | 4 |
| `modules/host/tests/Feature/QaAccessCloseOutSweepsTest.php` line 149 | `array_diff(…, $t030)` | `$t034 = ['purchase_order_items', 'purchase_order_receipt_applications', 'purchase_orders', 'purchase_request_items', 'purchase_requests', 'suppliers'];` with a one-line reason, appended to the `array_diff` | 4 |
| `modules/host/tests/Feature/QaAccessSchemaShapeTest.php` line 232 | same shape | the same `$t034` list | 4 |
| `modules/host/tests/Feature/SchemaCataloguePublishTest.php` "ships a required schema version the catalogue can produce" | reads `.env.example` | **no edit**; goes green when `modules/host/.env.example` `APP_REQUIRED_SCHEMA_VERSION` moves 73 → **79** (`.env.example` only; never `.env`) | 4 |
| `packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest.php` line 426 | `->and(lsmVersion(LSM_CONTRACT))->toBe(lsmSet()->highestVersion());` | replace with T-032's exact two lines, byte for byte, so the merge is clean: the comment "T-032's finance files sort after T-030's, so this pair is no longer the top of the tree." (reword "T-032's finance files" to "Later tasks' files" only if `lead` prefers; identical text merges cleanly) and `->and(lsmSet()->highestVersion())->toBeGreaterThanOrEqual(lsmVersion(LSM_CONTRACT));` | 4 |
| `modules/host/tests/Feature/TenantDecimalPrecisionTest.php` | exact per-file map ending at `003007` | add the T-034 entries (Data section, declaration order) after the T-030 block; add `t034DeferredColumns()` and its scan modelled on `t030DeferredColumns()` (line 215) | 5 |
| `modules/host/tests/Feature/UomColumnSetsTest.php` | already registers `purchase_request_items` (`quantity → base_quantity`) and `purchase_order_items` (`quantity → base_quantity`, `received_quantity → received_base_quantity`) | **no edit**; expected green; red is a stop | 4 |
| `BranchForeignKeysContractRunTest`, `QaRouteCrossTenantSweepTest`, `QaPlatformDatabaseSeparationTest`, `QaSchemaSignatureInstrumentTest`, `QaOrganizationTenantIsolationTest`, `VerticalSliceLayoutTest`, `QaPricingSourceRulesTest`, `QaCatalogSourceRulesTest`, `QaSalesSourceRulesTest`, every sales test | derive from the tree or the registry | **no edit**; expected green; red is a stop | 1 to 6 |

### 0.4 Schema version numbering and the T-032 merge

- On this branch: versions **74 to 79**, tables 66 → **72**, `APP_REQUIRED_SCHEMA_VERSION` 73 → **79**, FK inventory +27.
- This branch will later absorb T-032. T-032's files are dated `2026_11_15`, T-034's `2026_11_16`, so T-034's six sort after T-032's three and become **77 to 82** (more if T-032 lands further finance files first; T-032's plan names a contract file at 77). Tables become 75, the FK list the union. At that merge: re-run `php artisan schema:publish --write-fixture` (never hand-merge the JSON), take the union in `tenantTreeTables()`, the FK and branch-key lists, the `$t032`/`$t034` lists, `AuditCatalogueTest`, `QaToolchainRegistryCoverageTest`; recompute the count in `MigrationTreeDisciplineTest`, the top in `IdempotencyKeysSchemaTest` and `.env.example` from the merged tree; `composer update flexipos/purchasing flexipos/finance` for the lock.
- **Rule for this task's own code and tests:** no migration names its version; no T-034 test asserts a literal version number or that a T-034 file is the top of the tree. Where a test needs a version it derives it through `MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant))->versionOf($migration)` (the `LoyaltySaleIdMigrationsTest` helper shape), and asserts only that the six are consecutive, `expand`, not destructive, not offline. `IdempotencyKeysSchemaTest` is the single house-convention exception.
- If T-032 later adds a file dated after `2026_11_16`, it interleaves after T-034's; the rule above keeps every T-034 test green through that too.

### 0.5 Rulings

**Ruling 1: placement and reads.** New path package `packages/purchasing` (`flexipos/purchasing`, namespace `Flexipos\Purchasing\`, module name `purchasing`). Publishes **no** contract: `ApplyReceivedQuantity`'s only caller (FP-255) lives in this same package and calls the handler directly inside its own transaction (AC-96). Source reaches `Package_Kernel` and `Package_Contracts` only.
- Contracts used: `UnitResolver`, `PurchaseLinePricing` (Ruling 2), `TaxGroupDirectory`, `DocumentNumbers`, `BranchDirectory`, `PermissionResolver`, `EmployeeDirectory`, `AuditTrail`, `Outbox`, `WorkflowGate`.
- Direct read-only fact reads (D-38): `products` (`name`, `is_active`, `is_purchasable`, `track_stock`, `type`, `purchase_tax_group_id`, `deleted_at`), `locations` (`branch_id`, `is_active`, `is_purchasable_to`, `deleted_at`), `branches` (`timezone`, `currency`, `is_active`, `deleted_at`, and the id list for list scoping). Never written.
- Support: `TenantConnection`, `Fields`, `Identifier`, `Lookup`, `Pager` (+ `pageByCode`), `Page`, `Decimal`, `Money`, `Uniqueness`, `BusinessClock` (reduced to `businessDate()`), copied from `packages/sales/src/Support` with `SalesRefused` swapped for `PurchasingRefused` (FP-331's support-copy debt grows by one); plus purchasing's own `Authorise` and `Redaction`.

**Ruling 2: `Contracts\Pricing\PurchaseLinePricing`** (D-53a).
```php
interface PurchaseLinePricing
{
    /** @throws PricingRefused */
    public function quotePurchaseLine(PurchaseLineQuote $quote): PricedPurchaseLine;
}
// PurchaseLineQuote(quantity 18,4; unitCost 18,4; discountPercent 9,4 and <= 100;
//   taxGroupId ULID; baseQuantity 18,4; documentDate Y-m-d). Context is always Purchase.
// PricedPurchaseLine(subtotal, discountAmount, taxableAmount, tax: TaxBreakdown, lineTotal).
```
- Pure `packages/pricing/src/Engine/PurchaseLineCalculator.php` (no Illuminate import): `subtotal = Decimal::round(quantity x unitCost)`; `discount = Decimal::percentOf(subtotal, percent)`; `taxable = subtotal - discount`; then `TaxCalculator::compute(members, taxable, baseQuantity, date, TaxContext::Purchase, isInclusive)`; `lineTotal = tax.net + tax.total` (equals `taxable` inclusive, `taxable + tax` exclusive, so nobody branches on inclusivity). Overflow is `PricingRefused` `out_of_range`, as `LineCalculator` does.
- `DatabasePricingEngine implements PricingEngine, PurchaseLinePricing`; `quotePurchaseLine()` reuses `taxGroup()` and `members()`, adds no import, keys every read by `id` (the AC-25 "unkeyed first" scan).
- `PricingServiceProvider`: `$this->app->singleton(PurchaseLinePricing::class, fn ($app) => $app->make(PricingEngine::class));` so both contracts resolve to one instance.
- The engine computes an inactive or sale-only group without refusing, so purchasing asks `TaxGroupDirectory::state()` first (D-42).

**Ruling 3: numbering** (D-10, D-53b). `PR` / `PO` prefixes; the number is taken **last** inside the creating transaction, which runs as an outermost `transaction(..., 2)` (`CreatePurchaseRequest`, `CreatePurchaseOrder`, `ConvertRequestToOrder`). `DocumentNumbersRefused` becomes `numbering_refused`, or `number_too_long` for that cause; a unique violation on `number` becomes `numbering_refused`.

**Ruling 4: the two state machines** (D-2; AC-37, AC-67; D-44, D-45).

Requests:

| From | Command | To |
|---|---|---|
| none | `CreatePurchaseRequest` | `draft` |
| `draft` | `SubmitPurchaseRequest`: `NotRequired` / `Pending` | `approved` / `pending_approval` |
| `pending_approval` | `SyncRequestApproval`: approved / rejected (either `on_reject`) / cancelled or expired / pending | `approved` / `rejected` / `draft`, instance cleared / unchanged |
| `draft`, `pending_approval`, `approved` | `CancelPurchaseRequest` | `cancelled` |
| `approved` | `ConvertRequestToOrder` | `converted` |

Orders:

| From | Command | To |
|---|---|---|
| none | `CreatePurchaseOrder`, `ConvertRequestToOrder` | `draft` |
| `draft` | `SubmitPurchaseOrder`: `NotRequired` / `Pending` | `approved` / `pending_approval` |
| `pending_approval` | `SyncOrderApproval`: approved / rejected + `return_to_requester` / rejected + `stop` / cancelled or expired / pending | `approved` / `draft`, cleared / `cancelled` / `draft`, cleared / unchanged |
| `approved`, no receipt-application row | `ReopenPurchaseOrder` | `draft`, cleared |
| `draft`, `pending_approval`, or `approved` with no receipt-application row | `CancelPurchaseOrder` | `cancelled` |
| `approved`, `partially_received`, `received` | `ApplyReceivedQuantity` | from base sums: all zero `approved`, every line complete `received`, else `partially_received` |
| `partially_received`, `received` | `ClosePurchaseOrder` | `closed` |

`sent` ships in the enum and nothing enters or leaves it. `received`, `closed`, `cancelled` refuse every edit, line change, submit and reopen with `order_closed` (`invalid_transition` for a transition); `closed` and `cancelled` refuse a receipt `order_not_open`; `received` accepts any non-zero receipt delta (D-44). A line is complete when `received_base_quantity >= base_quantity`, compared as `BigDecimal` on the stored strings.

**Ruling 5: idempotency** (D-21, D-35).
- **Suppliers** have server-generated ids (`Identifier`, the `CreateCustomerHandler` shape). No id replay: a retried create is `duplicate_code` naming the first row (AC-9). A caller-supplied `id` is an unknown field, refused `validation` by `Fields::only` (Q-B3).
- **Documents** (request, order, request line, order line, receipt application) take the caller's ULID as the row id. Replay compares the normalised input with the stored columns: identical returns the stored row with `replayed = true` (no number, audit or event); any difference is `replay_conflict` naming the id. A document edited since its creation therefore conflicts on a create replay (risk 6).
- State changes are idempotent by outcome: submit on `pending_approval`/`approved`, cancel on `cancelled`, close on `closed`, deactivate on inactive, activate on active, delete on deleted, convert of a `converted` request with the same supplier: no-op success, no audit row, no event.
- `ApplyReceivedQuantity` replays by application id.

**Ruling 6: approval** (D-11, D-43, D-50, D-52).
- `Submit*` calls `WorkflowGate::evaluate()` once, inside the submit transaction, after the document lock. Any `WorkflowEvaluationFailed` is caught only to rethrow `workflow_failed` (previous = the exception, `detail` = its string), which rolls everything back. The catch never continues.
- Request amount (AC-26): `Money::sum` over `Money::product(quantity, estimated_cost)` per line, half-up at 4 (no rate or percent token, so AC-25(b) is not engaged). Order amount: the stored `total`. An amount that will not fit `DECIMAL(18,4)` is `out_of_range` before `evaluate()`.
- `Sync*` locks the document, then reads `WorkflowGate::instance()`. It acts only on `pending_approval`; anything else returns unchanged, `changed = false`. The instance's `entityType`/`entityId` must equal the document's, else `invalid_transition`. Permission: the document's `.view` key at its branch.
- `Cancel*` of a `pending_approval` document calls `WorkflowGate::cancel()`; if the engine answers that the instance is already closed (decided, not yet synced), the cancel proceeds and the audit row records the instance status.
- **No trigger** for `Sync*` exists in this task (D-52, risk 1).

**Ruling 7: transaction and lock order** (D-22).
1. Before the transaction, on autocommit reads: input validation, permission, product facts, `UnitResolver`, tax-group resolution and `PurchaseLinePricing`.
2. The first statement inside every write transaction is a locking read.
3. One global order, never reversed:

| # | Row | Taken by | How |
|---|---|---|---|
| 1 | document header (`purchase_requests` / `purchase_orders`) | every request or order write; a convert locks the **request**; `ApplyReceivedQuantity` locks the **order** header, never a line first | `lockForUpdate()`; status and line count re-read after it |
| 2 | `suppliers` | order create, convert, order submit, a header edit naming a supplier, a request line naming one: `sharedLock()`. Supplier writers: `lockForUpdate()` as their first and only lock, then plain reads of documents | |
| 3 | `workflow_instances` | inside `evaluate()` / `cancel()` | the engine's own locks |
| 4 | `number_sequences` | `DocumentNumbers::next()` | **last** |

4. Then outbox and audit rows. 5. `ApplyReceivedQuantity` opens a nested `transaction()` (savepoint) inside its caller's, never retries, and still locks the header first.

**Ruling 8: the slice shape** (T-027 to T-030). `Features/<X>/{<X>.php, <X>Handler.php, <X>Result.php}`; `array $fields` plus `?string $actorId`; `Fields::only`; each slice asserts its own permission via `Support/Authorise::require($resolver, $actorId, $key, $branchId)` (`not_authorised`, cause the `RefusalReason`), always at the **stored** document branch; supplier keys are asked with a **null** branch; one `AuditTrail::record()` per real write; nothing written on refusal. **31 slices, flat under `src/Features/`, no HTTP route, no console command:**

| Area | Slices | ACs |
|---|---|---|
| Suppliers | `CreateSupplier`, `UpdateSupplier`, `ActivateSupplier`, `DeactivateSupplier`, `DeleteSupplier`, `GetSupplier`, `ListSuppliers` | 1 to 18 |
| Requests | `CreatePurchaseRequest`, `UpdatePurchaseRequest`, `AddPurchaseRequestLine`, `UpdatePurchaseRequestLine`, `RemovePurchaseRequestLine`, `SubmitPurchaseRequest`, `SyncRequestApproval`, `CancelPurchaseRequest`, `ConvertRequestToOrder`, `GetPurchaseRequest`, `ListPurchaseRequests` | 19 to 39 |
| Orders | `CreatePurchaseOrder`, `UpdatePurchaseOrder`, `AddPurchaseOrderLine`, `UpdatePurchaseOrderLine`, `RemovePurchaseOrderLine`, `SubmitPurchaseOrder`, `SyncOrderApproval`, `ReopenPurchaseOrder`, `CancelPurchaseOrder`, `ClosePurchaseOrder`, `ApplyReceivedQuantity`, `GetPurchaseOrder`, `ListPurchaseOrders` | 40 to 83 |

`UpdatePurchaseOrder` / `UpdatePurchaseOrderLine` carry AC-60, AC-61, AC-63: in `draft` every field; in `approved` with nothing received and in `partially_received`, only `notes`, `terms`, header `expected_date` and a line's `expected_date` (in `partially_received` only for an incomplete line); anything else is `locked` naming the field and naming reopen.

**Ruling 9: permission keys** (D-26, AC-95). `PurchasingPermissions` declares eight keys, module `purchasing`: `purchasing.suppliers.view`, `purchasing.suppliers.manage` (not branch-scoped); `purchasing.requests.view`, `purchasing.requests.manage`, `purchasing.orders.view`, `purchasing.orders.manage`, `purchasing.orders.close`, `purchasing.orders.reopen` (branch-scoped). `ConvertRequestToOrder` needs both `requests.manage` and `orders.manage` at the request's branch. `GetSupplier` accepts `view` or `manage` and returns `bank_details` only to `manage`. Every read slice checks a key (D-36). List scoping (AC-35, AC-82): with a `branch_id` filter one decision at it; without, one per `branches` row (inactive and soft-deleted included), then `whereIn` over the allowed set; none allowed is an empty page.

**Ruling 10: `bank_details` redaction** (D-9, AC-17). Every supplier audit image replaces a non-null `bank_details` with `[redacted]`, and with `[redacted, changed]` in the after image when it changed, so `changed_fields` still lists it. Purchasing logs nothing; no refusal message names a `bank_details` value; outbox payloads carry no supplier field but its id.

**Ruling 11: outbox events** (AC-97, D-49, D-51). `Outbox::record(new NewEvent(type, 'purchase_order', id, payload, branchId: order branch))`, same transaction:
- `purchase_order.approved`, `purchase_order.reopened`, `purchase_order.cancelled` (only when the order had been `approved`), `purchase_order.closed`: `{order_id, location_id, lines: [{line_id, product_id, base_quantity, received_base_quantity}]}`;
- `purchase_order.received_applied`: `{order_id, application_id, line_id, product_id, location_id, received_base_delta, received_quantity_delta, received_base_quantity, received_quantity, status}`.

A replay or refusal writes none. `purchase.approved` (notification key) maps to `purchase_order.approved` later; no second event.

**Ruling 12: audit action mapping** (D-31; the 28 names, nothing else).

| Write | Action |
|---|---|
| supplier create / update / deactivate / activate / delete | `purchasing_supplier_created` / `_updated` / `_deactivated` / `_activated` / `_deleted` |
| request create / header edit / line add / line edit / line remove | `purchasing_request_created` / `_updated` / `_line_added` / `_line_updated` / `_line_removed` |
| request submit: `Pending` / `NotRequired` | `purchasing_request_submitted` / `purchasing_request_approved` (note "approval not required") |
| request sync: approved / rejected / cancelled or expired | `purchasing_request_approved` / `_rejected` / `_returned_to_draft` |
| request cancel / convert | `purchasing_request_cancelled` / `purchasing_request_converted` **and** `purchasing_order_created` (two rows, AC-30) |
| order create / header edit (draft or post-approval) / line add / line edit (draft or post-approval `expected_date`) / line remove | `purchasing_order_created` / `_updated` / `_line_added` / `_line_updated` / `_line_removed` (Q-B2) |
| order submit: `Pending` / `NotRequired` | `purchasing_order_submitted` / `purchasing_order_approved` (note "approval not required", submitter) |
| order sync: approved / rejected + `return_to_requester` / rejected + `stop` / cancelled or expired | `purchasing_order_approved` / `_returned_to_draft` / `purchasing_order_cancelled` with the workflow status in the after image (Q-B1) / `_returned_to_draft` |
| order reopen / cancel / close / receipt | `purchasing_order_reopened` / `_cancelled` / `_closed` / `_receipt_applied` |

Subject types `supplier`, `purchase_request`, `purchase_order`; line writes record against the header (D-23).

---

## Approach summary

1. A new `packages/purchasing` owns suppliers, purchase requests, purchase orders and the received-quantity rollup: 31 vertical slices, no routes, six new tenant tables (versions 74 to 79 on this branch).
2. Unit quads come only from `UnitResolver` (`snapshot()` for new or draft-edited lines, `snapshotInherited()` for conversion); every line amount from the new `PurchaseLinePricing` contract; every number from `DocumentNumbers` with new `PR`/`PO` defaults.
3. Approval goes through `WorkflowGate`, fail closed, inside the submit transaction; outcomes are pulled by two sync slices (no trigger exists, D-52).
4. The rollup compares base to base on stored four-decimal strings, locks the order header first, and replays by a caller application id.
5. Three edits outside the package: the pricing contract and its adapter, two number prefixes, and the shared test lists of section 0.3.
6. Cross-module effects are outbox events; nothing touches stock, ledgers or accounts.

## Alternatives rejected

- Discount arithmetic inside purchasing: red on `QaPricingSourceRulesTest` AC-25(b), and contradicts the engine's "only place" docblock.
- A new method on `PricingEngine`: breaks `FakePricingEngine` in sales tests.
- Catalog / Organization facts contracts now: would force a sales migration too; follow-up (D-38).
- Moving `BusinessClock` to the kernel: second user only (D-53c).
- A sync trigger (console sweep or engine event): out of scope (D-52).
- A published `ApplyReceivedQuantity` contract: its only caller is in this package.
- Number at submit: breaks `number unique NOT NULL` on drafts (D-10).

## Files to change / create

`dev` writes every file. `Qa*` test files are `qa`-accountable.

### `packages/contracts/src/Pricing/` (new)
- `PurchaseLinePricing.php`, `PurchaseLineQuote.php`, `PricedPurchaseLine.php` (Ruling 2).

### `packages/pricing`
- `src/Engine/PurchaseLineCalculator.php` (new, pure).
- `src/Engine/DatabasePricingEngine.php`: second interface, `quotePurchaseLine()`.
- `src/PricingServiceProvider.php`: the binding.
- `tests/Engine/PurchaseLineCalculatorTest.php`, `tests/Engine/PurchaseLinePricingTest.php` (new).

### `packages/organization`
- `src/Numbering/DatabaseDocumentNumbers.php`: two prefixes. `tests/Numbering/DocumentNumbersTest.php`: one new case.

### `packages/purchasing` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/purchasing` 0.1.0, `php ^8.2`, `brick/math ^0.14`, `flexipos/contracts *`, PSR-4 `Flexipos\\Purchasing\\` → `src/` (copy `packages/sales/composer.json`) |
| `src/PurchasingServiceProvider.php` | step 1: empty `register()`/`boot()`; step 6: `implements DeclaresAuditActions, DeclaresPermissions` |
| `src/Support/{TenantConnection,Fields,Identifier,Lookup,Pager,Page,Decimal,Money,Uniqueness,BusinessClock,Authorise,Redaction}.php` | Rulings 1, 8, 10 |
| `src/Suppliers/{SupplierCode,SupplierImage,SupplierUse}.php` | AC-2/AC-11 rules; audit images; AC-14 in-use query |
| `src/Documents/{RequestStatus,OrderStatus,Amendments,Places,ProductFacts,TaxGroupChain,LineQuoter,OrderRollup,ReceiptRollup,ApprovalOutcome,OrderEvents,DocumentLocks}.php` | Rulings 4, 6, 7, 11; AC-44 chain; base-to-base status |
| `src/Exceptions/{PurchasingRefused,PurchasingRefusal}.php` | the 32 codes; `?BackedEnum $cause`, `?string $detail`, `previous` |
| `src/Registry/{PurchasingAuditActions,PurchasingPermissions}.php` | Rulings 9, 12 |
| `src/Features/<31 slices>/` | Ruling 8 |
| `tests/Support/{PurchasingProviderTest.php, PurchasingSeed.php, purchasing-fixture.php, FakeWorkflowGate.php, DeadlockingNumbers.php, PurchasingQueryLog.php, PurchasingMoneyTest.php, PurchasingBusinessClockTest.php}` | seeds on `AccessSeed`/`AccessTenantFixture` and `PricingSeed`; `PurchasingSeed::workflowDefinition(entity, minAmount, onReject)` raw-inserts a one-step definition; `DeadlockingNumbers` copied from sales test support |
| `tests/Schema/PurchasingTenantSchemaTest.php` | step 4 |
| `tests/{Documents,Suppliers,Registry}/…Test.php` | pure helpers (names prefixed where a bare basename exists elsewhere) |
| `tests/Features/<X>/<X>Test.php` | one per slice, mirrored (VerticalSliceLayoutTest) |
| `tests/Invariants/PurchasingInvariantTest.php` | step 20 |

### `modules/host`

| Path | What |
|---|---|
| `config/purchasing.php` | `page_size` 25, `max_page_size` 100, `max_lines` 200 (env `PURCHASING_PAGE_SIZE`, `PURCHASING_MAX_PAGE_SIZE`, the `config/sales.php` shape) |
| `database/migrations-tenant/2026_11_16_003401_create_suppliers_table.php` | version 74 |
| `…003402_create_purchase_requests_table.php` | 75 |
| `…003403_create_purchase_orders_table.php` | 76 |
| `…003404_create_purchase_order_items_table.php` | 77 |
| `…003405_create_purchase_request_items_table.php` | 78 (after 04: it keys onto `purchase_order_items`) |
| `…003406_create_purchase_order_receipt_applications_table.php` | 79 |
| `database/catalogue/tenant_schema_versions.json`, `.env.example` | republished via `--write-fixture`; 73 → 79 |
| `composer.json`, `composer.lock`, `bootstrap/providers.php`, `phpstan.neon.dist`, `deptrac.yaml` | wiring (step 1) |
| `tests/Feature/QaPurchasingSourceRulesTest.php` | step 21 |
| `tests/Engine/PurchasingSchemaEngineTest.php` | step 22, skipped under the hold |
| the shared tests of section 0.3 | their listed edits |

Every migration: `@phase expand` docblock naming each deferral, `IdentifierColumn::primary/required/nullable`, `dateTime(…, 3)`, VARCHAR(30) statuses, full `decimal(name, p, s)` arguments, `JsonValidity` on JSON columns, keys inline, no `down()`, the `2026_11_10_003006_create_sale_items_table.php` shape.

## Data / API changes

**Six new tenant tables, no change to an existing table, no master change.** Money and quantity `(18,4)` unless stated. `[BY]` columns key `employees`. Statuses VARCHAR(30) with application enums.

| Table | Columns | Keys | Not built (D-3) |
|---|---|---|---|
| `suppliers` | `id`, `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by`, `code` VARCHAR(50), `name` VARCHAR(150), `legal_name` VARCHAR(150) NULL, `contact_name` VARCHAR(100) NULL, `phone` VARCHAR(30) NULL, `email` VARCHAR(191) NULL, `address` JSON NULL, `tax_no` VARCHAR(50) NULL, `currency` CHAR(3) NULL, `payment_terms_days` SMALLINT UNSIGNED default 0, **`credit_limit` NOT NULL default 0**, `bank_details` JSON NULL, `is_active` default true, `notes` TEXT NULL | unique `code` (all rows); index `name`; FKs `created_by`, `updated_by` | `custom_fields`, `opening_balance`, `payable_account_id`, `default_expense_account_id` |
| `purchase_requests` | `id`, TS, `created_by`, `updated_by`, `number` VARCHAR(30), `branch_id`, `location_id`, `requested_by`, `required_date` DATE NULL, `status`, `workflow_instance_id` NULL, `notes` TEXT NULL | unique `number`; index (`branch_id`, `status`), (`status`); 6 FKs | none |
| `purchase_orders` | `id`, TS, `created_by`, `updated_by`, `number` VARCHAR(30), `supplier_id`, `supplier_name` VARCHAR(150), `supplier_tax_no` VARCHAR(50) NULL, `payment_terms_days` SMALLINT UNSIGNED, `branch_id`, `location_id`, `request_id` NULL, `order_date` DATE, `expected_date` DATE NULL, `status`, `workflow_instance_id` NULL, `currency` CHAR(3), `exchange_rate` (18,8) default 1, `subtotal`, `discount_total`, `tax_total`, `shipping`, `total`, `received_value` (default 0), `terms` VARCHAR(1000) NULL, `notes` TEXT NULL | unique `number`; index (`supplier_id`, `status`), (`branch_id`, `order_date`), (`status`), (`request_id`); 7 FKs | `custom_fields` |
| `purchase_order_items` | `id`, `order_id` (cascade), `line_no` SMALLINT UNSIGNED, `product_id`, `description` VARCHAR(255), `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `unit_cost`, `subtotal`, `discount_percent` (9,4) default 0, `discount_amount`, `tax_group_id`, `tax_amount`, `line_total`, `received_quantity` default 0, `received_base_quantity` default 0, `expected_date` DATE NULL | unique (`order_id`, `line_no`); index `product_id`; 4 FKs | `unit_code` (D-27), `tax_breakdown` |
| `purchase_request_items` | `id`, `request_id` (cascade), `line_no` SMALLINT UNSIGNED, `product_id`, `unit_id`, `quantity`, `unit_factor` (18,6), `base_quantity`, `estimated_cost` NULL, `supplier_id` NULL, `purchase_order_item_id` NULL | unique (`request_id`, `line_no`); index `product_id`, `supplier_id`; 5 FKs | none |
| `purchase_order_receipt_applications` | `id` (caller ULID), `order_id`, `order_item_id`, `received_base_delta` (signed), `received_quantity_delta` (signed), `created_by` NULL, `created_at` | index `order_item_id`, `order_id`; 3 FKs, all RESTRICT | none |

**The 27 foreign keys, exactly as `TenantBaselineBuildsTest` will list them:**
```
purchase_order_items.order_id -> purchase_orders.id            (cascade)
purchase_order_items.product_id -> products.id
purchase_order_items.tax_group_id -> tax_groups.id
purchase_order_items.unit_id -> units.id
purchase_order_receipt_applications.created_by -> employees.id
purchase_order_receipt_applications.order_id -> purchase_orders.id
purchase_order_receipt_applications.order_item_id -> purchase_order_items.id
purchase_orders.branch_id -> branches.id
purchase_orders.created_by -> employees.id
purchase_orders.location_id -> locations.id
purchase_orders.request_id -> purchase_requests.id
purchase_orders.supplier_id -> suppliers.id
purchase_orders.updated_by -> employees.id
purchase_orders.workflow_instance_id -> workflow_instances.id
purchase_request_items.product_id -> products.id
purchase_request_items.purchase_order_item_id -> purchase_order_items.id
purchase_request_items.request_id -> purchase_requests.id      (cascade)
purchase_request_items.supplier_id -> suppliers.id
purchase_request_items.unit_id -> units.id
purchase_requests.branch_id -> branches.id
purchase_requests.created_by -> employees.id
purchase_requests.location_id -> locations.id
purchase_requests.requested_by -> employees.id
purchase_requests.updated_by -> employees.id
purchase_requests.workflow_instance_id -> workflow_instances.id
suppliers.created_by -> employees.id
suppliers.updated_by -> employees.id
```
All RESTRICT except the two cascades.

**Decimal map for `TenantDecimalPrecisionTest`** (file → columns in declaration order; the developer writes the map from the files as written):

| File | Columns |
|---|---|
| `…003401_create_suppliers_table.php` | `credit_limit` (18,4) |
| `…003403_create_purchase_orders_table.php` | `exchange_rate` (18,8); `subtotal`, `discount_total`, `tax_total`, `shipping`, `total`, `received_value` (18,4) |
| `…003404_create_purchase_order_items_table.php` | `quantity` (18,4), `unit_factor` (18,6), `base_quantity`, `unit_cost`, `subtotal` (18,4), `discount_percent` (9,4), `discount_amount`, `tax_amount`, `line_total`, `received_quantity`, `received_base_quantity` (18,4) |
| `…003405_create_purchase_request_items_table.php` | `quantity` (18,4), `unit_factor` (18,6), `base_quantity`, `estimated_cost` (18,4) |
| `…003406_create_purchase_order_receipt_applications_table.php` | `received_base_delta`, `received_quantity_delta` (18,4) |

**Deferred-column scan (`t034DeferredColumns()`):** no T-034 migration declares `custom_fields`, `opening_balance`, `payable_account_id`, `default_expense_account_id`, `unit_code`, `tax_breakdown`, `tenant_id`.

**Refusal vocabulary (`PurchasingRefusal`, 32 codes):**

| Area | Codes |
|---|---|
| General | `validation`, `not_found`, `out_of_range`, `replay_conflict`, `invalid_transition`, `locked`, `not_authorised`, `too_many_lines`, `no_lines` |
| Suppliers | `duplicate_code`, `code_immutable`, `supplier_in_use`, `supplier_inactive`, `unknown_supplier`, `supplier_mismatch`, `currency_required` |
| Places and products | `unknown_branch`, `unknown_location`, `unknown_product`, `product_not_purchasable` |
| Units and pricing | `unit_unresolvable` (cause `UnitRefusal`), `inherited_unit_mismatch` (cause `UnitRefusal::InheritedUnitMismatch`), `pricing_refused` (cause `PricingRefusal`), `no_default_tax_group`, `unknown_tax_group`, `inactive_tax_group` |
| Numbering and workflow | `numbering_refused`, `number_too_long` (cause `DocumentNumbersRefusal`), `workflow_failed` (previous = exception, `detail` = engine string) |
| Orders | `already_received`, `order_closed`, `order_not_open` |

**Audit actions (`PurchasingAuditActions`, module `purchasing`, 28):** the spec preamble's list, mapped in Ruling 12.

**Boundary (`deptrac.yaml`):** paths `../../packages/purchasing/src`, `../../packages/purchasing/tests`; layers `Package_Purchasing` (`^Flexipos\\Purchasing\\(?!Tests\\).*`) and `Package_Purchasing_Tests` (`^Flexipos\\Purchasing\\Tests\\.*`); ruleset `Package_Purchasing: [Package_Kernel, Package_Contracts]`, `Package_Purchasing_Tests: [Package_Purchasing, Package_Contracts, Package_Kernel, Package_Access, Package_Pricing_Tests]`; `HostApp` gains `Package_Purchasing`; `HostTests` gains `Package_Purchasing`, `Package_Purchasing_Tests`; **`Module_Demo` is not granted it.**

**HTTP, console, jobs, hooks:** none. **Dependencies:** the path package only; `brick/math` is already locked.

## Implementation steps

Rules carried from T-027 to T-030: red-first (a fixture, or leaving something out; never stubbing a guard); after each schema step compare the red list with section 0.3 and stop on anything else; no child processes in tests; unique test basenames, Pest helpers prefixed `pu`; never the bare string of the non-stock product type in an array key or tail position (`QaVocabularyRenameSweepTest`), so eligibility is written as the positive list `simple`, `variant`, `composite`; never the three-letter abbreviation of that word anywhere. Mutations are run once, reverted byte for byte, and reported with red/green counts.

**How `dev` runs a targeted file** (Git Bash, from `modules/host`): `"/e/xampp/php/php.exe" artisan config:clear` once, then `"/e/xampp/php/php.exe" vendor/bin/pest <path> [<path> …]`, paths relative to `modules/host` (package tests as `../../packages/<pkg>/tests/…`). Statics: `composer lint`, `composer stan`, `composer deptrac` with the full PHP + composer.phar invocation. Never `composer check`, `composer test`, `composer test:fast`.

### Step 0 (architect, done): plan revision 2
This document, synced to spec revision 2 and D-1 to D-53, code re-verified at `c437232`.

### Chunk A: plumbing, contract edits, schema, statics

Order is fixed; each step ends with its targeted files green before the next starts.

- [x] **Step 1: package plumbing.** *(AC-93's boundary half.)*
  1. Create `packages/purchasing/composer.json` (copy `packages/sales/composer.json`, rename).
  2. Create `packages/purchasing/src/PurchasingServiceProvider.php` (empty, implements nothing yet).
  3. Create `modules/host/config/purchasing.php`.
  4. Edit `modules/host/composer.json`: `"flexipos/purchasing": "*"` in `require` (sorted), `"Flexipos\\Purchasing\\Tests\\": "../../packages/purchasing/tests/"` in `autoload-dev`. Run `composer update flexipos/purchasing` (path repository; if it reaches for the network, stop and report).
  5. Edit `modules/host/bootstrap/providers.php`: `use` + `PurchasingServiceProvider::class`.
  6. Edit `modules/host/phpstan.neon.dist`: `../../packages/purchasing/src` and `../../packages/purchasing/tests`.
  7. Edit `modules/host/deptrac.yaml` per the Boundary paragraph (paths, two layers, two ruleset entries, `HostApp`, `HostTests`).
  8. Edit `QaToolchainRegistryCoverageTest.php` line 446 (section 0.3).
  9. Create `packages/purchasing/tests/Support/PurchasingProviderTest.php` (namespace `Flexipos\Purchasing\Tests\Support`; provider loaded; `BigDecimal` autoloads), modelled on `packages/pricing/tests/Support/PricingProviderTest.php`.
  10. Negative control: a scratch class in `modules/demo/src` importing `Flexipos\Purchasing\Support\…` (any class; create a one-line `Support/Money.php` stub only if needed and delete it) fails `composer deptrac`; one importing `Flexipos\Contracts\Pricing\TaxContext` passes. Delete both.
  - **Targeted:** `../../packages/purchasing/tests/Support/PurchasingProviderTest.php`, `tests/Feature/QaToolchainRegistryCoverageTest.php`, `tests/Feature/VerticalSliceLayoutTest.php`; `composer lint`, `composer stan`, `composer deptrac`.
- [x] **Step 2: `PurchaseLinePricing`.** *(AC-43, AC-44 arithmetic half; D-53a.)*
  1. `packages/contracts/src/Pricing/PurchaseLineQuote.php`, `PricedPurchaseLine.php`, `PurchaseLinePricing.php`.
  2. `packages/pricing/src/Engine/PurchaseLineCalculator.php` (pure).
  3. `DatabasePricingEngine.php`: `implements PricingEngine, PurchaseLinePricing`, `quotePurchaseLine()`.
  4. `PricingServiceProvider.php`: the binding.
  5. Tests `packages/pricing/tests/Engine/PurchaseLineCalculatorTest.php` (pure) and `PurchaseLinePricingTest.php` (adapter on `PricingSeed`): (a) AC-43 exclusive `39.9996 / 4.0000 / 35.9996 / 3.6000 / 39.5996`; (b) inclusive twin `tax 3.2727`, `lineTotal 35.9996`; (c) discount `0` and `100.00` (line total `0.0000`); (d) zero unit cost gives zeros; (e) a sale-only group taxes zero, no refusal; (f) overflow is `out_of_range`; (g) `discountPercent` `100.0001` and a negative refused by the DTO; (h) an inactive group computes normally; (i) a purchase-only member applies, a sale-only one does not; (j) `app(PurchaseLinePricing::class)` is the same instance as `app(PricingEngine::class)`.
  - **Mutations:** P1 discount rounded half-down (a red); P2 tax on `subtotal` not `taxable` (a red); P3 `lineTotal = taxable + tax` for inclusive (b red).
  - **Targeted:** the two new files, `../../packages/pricing/tests/Engine/DatabasePricingEngineTest.php`, `…/QaPricingAdapterTest.php`, `…/QaPricingEngineTest.php`, `../../packages/pricing/tests/Support/PricingProviderTest.php`, `tests/Feature/QaPricingSourceRulesTest.php`; lint, stan, deptrac.
- [x] **Step 3: number prefixes.** *(D-10, D-53b.)* `DEFAULT_PREFIXES` gains the two entries. `DocumentNumbersTest.php`: a new case after line 172: first use gives `PR000001` / `PO000001`, a second `PR000002`, independent rows with prefixes `PR` / `PO`.
  - **Targeted:** `../../packages/organization/tests/Numbering/DocumentNumbersTest.php`; lint, stan.
- [x] **Step 4: migrations 01 to 06 and `PurchasingTenantSchemaTest`.** *(AC-68, AC-88, AC-89; schema halves of AC-1, AC-6, AC-19, AC-40.)*
  1. Write `PurchasingTenantSchemaTest.php` first (namespace `Flexipos\Purchasing\Tests\Schema`, helpers `puSchema*`, modelled on `packages/sales/tests/Schema/SalesTenantSchemaTest.php`): sorted column lists and nullable subsets equal the Data section exactly (proves every D-3 absence); `suppliers.credit_limit` not nullable with default 0; unique keys by raw double insert (`suppliers.code` with a deleted holder, both `number`s, both (`header`, `line_no`)); exactly the 27 keys; both cascades delete lines; deleting a supplier, product, or an order item referenced by an application is refused; migrate twice is a no-op; no `down()`; `SourceRules::inspect()` reports nothing; the six catalogue entries (looked up by name through `MigrationSet::versionOf()`, never by number) are consecutive, `expand`, not destructive, not offline. Run it: red.
  2. Write the six migrations in order 01 → 06.
  3. `php artisan schema:publish --write-fixture` (never without the option). Edit `.env.example` 73 → 79.
  4. Apply the section 0.3 step-4 edits: `Helpers.php`, `MigrationTreeDisciplineTest`, `IdempotencyKeysSchemaTest`, `TenantBaselineBuildsTest` (FK list and branch-key list), `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`, `LoyaltySaleIdMigrationsTest`.
  - **Targeted:** `../../packages/purchasing/tests/Schema/PurchasingTenantSchemaTest.php`, `tests/Feature/MigrationTreeDisciplineTest.php`, `tests/Feature/IdempotencyKeysSchemaTest.php`, `tests/Feature/TenantBaselineBuildsTest.php`, `tests/Feature/QaAccessCloseOutSweepsTest.php`, `tests/Feature/QaAccessSchemaShapeTest.php`, `tests/Feature/SchemaCataloguePublishTest.php`, `tests/Feature/UomColumnSetsTest.php`, `tests/Feature/BranchForeignKeysContractRunTest.php`, `../../packages/sales/tests/Schema/LoyaltySaleIdMigrationsTest.php`; lint, stan. `UomColumnSetsTest` and `BranchForeignKeysContractRunTest` must be green with no edit; red is a stop.
- [x] **Step 5: statics.** *(AC-89, AC-93 schema half.)* `TenantDecimalPrecisionTest`: the T-034 map, red-first with a fixture string `decimal('total', 18, 2)` read back as the wrong pair (the T-030 red-first case shape); `t034DeferredColumns()` scan, red-first with a scratch fixture source naming `opening_balance`.
  - **Targeted:** `tests/Feature/TenantDecimalPrecisionTest.php`, `tests/Feature/MigrationTreeDisciplineTest.php`; lint, stan, deptrac.
  - **Chunk A hand-back:** list of files changed, each targeted run's pass/fail counts, the mutation results; then `lead` schedules `composer check`.

### Chunk B: building blocks

- [x] **Step 6: support, refusals, registry.** The copies (Ruling 1, including `Uniqueness` and `Pager::pageByCode`); `PurchasingRefused` (`because`, `fromUnit`, `fromPricing`, `fromNumbers`, `fromWorkflow`, `fromAccess`, `code()`); `PurchasingPermissions` (8); `PurchasingAuditActions` (28); the provider now implements `DeclaresAuditActions`, `DeclaresPermissions`; `Authorise`; `Redaction`. Edit `AuditCatalogueTest` and `QaAccessHttpSurfaceTest` (section 0.3).
  - Tests: `packages/purchasing/tests/Registry/PurchasingRegistryTest.php` pins the 32 codes, the 28 actions (equal to the spec preamble's list) and the 8 keys with scope; `Authorise` rows for AC-85 (a) to (c) and AC-86; `PurchasingBusinessClockTest` (midnight in `Asia/Colombo` vs UTC); `PurchasingMoneyTest` (`product` half-up, `sum`, never `-0.0000`); a `Pager` case: `page_size` 101 is clamped to 100, `0`, `-1`, `"x"` are `validation`.
  - **Targeted:** the new files, `../../packages/access/tests/Support/AuditCatalogueTest.php`, `tests/Feature/QaAccessHttpSurfaceTest.php`; lint, stan, deptrac.
- [x] **Step 7: document helpers.** `RequestStatus`/`OrderStatus` (Ruling 4 as a full pair dataset), `ApprovalOutcome`, `ReceiptRollup` (AC-70 to AC-74), `OrderRollup` (AC-45, overflow), `Amendments` (AC-61, AC-63).
  - **Mutations:** R1 compare `received_quantity` (AC-70 red); R2 `>` for `>=` (AC-72 red); R3 status from the last line only (AC-71 red).
- [x] **Step 8: lookups and the line quoter.** `Places` (branch via `BranchDirectory`, location per D-40), `ProductFacts` (D-39, one `whereIn` per batch), `TaxGroupChain` (explicit → `purchase_tax_group_id` → `defaultGroupId()` → `no_default_tax_group`; then `state()`: `unknown_tax_group`, `inactive_tax_group`), `LineQuoter` (`snapshot()` or `snapshotInherited()`, then `quotePurchaseLine()` with `documentDate = order_date`; resolver refusals mapped to `unit_unresolvable` / `inherited_unit_mismatch`). Real resolver and engine on `PricingSeed`; query count for one line recorded (AC-94).
  - Targeted: the new test files of steps 7 and 8; lint, stan, deptrac. **Gate: `lead`.**

### Chunk C: suppliers

- [x] **Step 9: `CreateSupplier`, `UpdateSupplier`.** *(AC-1 to AC-12, AC-17.)* Server-generated id; a supplied `id` field is `validation`; AC-2/4/5/6 boundaries (`30` vs `31` chars, email 191 vs 192, `-1`/`3651`, `"2500"` → `"2500.0000"`, omitted → `"0.0000"`, explicit null `validation`, JSON float, 5 decimals, 15 integer digits `out_of_range`); AC-3 against active, inactive and deleted holders (case-insensitive via `Uniqueness`; unique key as backstop); AC-9 a second identical create is `duplicate_code` naming the first id, one audit row in total; AC-10 subset update, no-change writes nothing; AC-11; AC-17 redaction rows.
- [x] **Step 10: `ActivateSupplier`, `DeactivateSupplier`, `DeleteSupplier`.** *(AC-13, AC-14.)* Idempotent repeats; delete refused per blocking request and order status; retained, code reserved, `get` is `not_found`.
- [x] **Step 11: `GetSupplier`, `ListSuppliers`.** *(AC-15, AC-16, AC-18.)* `bank_details` only to `manage`; keyset by `code` with `after_code`; `page_size` 101 clamped; non-positive or malformed cursor `validation`; filters combine; deleted never listed; AC-18 one refusal row per supplier slice, key asked with a null branch, never `BranchRequired`.
  - **Mutation S1:** drop the deleted-row condition from the AC-3 pre-check (deleted-holder row red). Targeted: the seven slice test folders; lint, stan, deptrac. **Gate: `lead`.**

### Chunk D: purchase requests

- [x] **Step 12: create, header edit, lines.** *(AC-19 to AC-25, AC-36, AC-39.)* `transaction(..., 2)`, number last; `requested_by` the actor; no number consumed on any refusal (`number_sequences` compared whole); quad equals `snapshot()`'s; AC-24 ladder edit changes nothing on read; 201st line `too_many_lines`; `locked` outside `draft`; replay; the three `DeadlockingNumbers` rows.
- [x] **Step 13: submit, sync, cancel.** *(AC-26 to AC-29, AC-37.)* `FakeWorkflowGate`: exact `WorkflowContext`, `no_lines` never calls the gate, `workflow_failed` fail-closed, second submit calls nothing. Real engine: approve, reject (either `on_reject`) → `rejected`, cancel → `draft`, re-sync no-op, `expired` via raw update. Audit actions per Ruling 12.
  - **Mutations:** W1 approve on `WorkflowEvaluationFailed`; W2 skip the entity-id match.
- [x] **Step 14: `GetPurchaseRequest`, `ListPurchaseRequests`, permission sweep.** *(AC-21, AC-34, AC-35, AC-38.)* Clamp and validation as step 11. **Gate: `lead`.**

### Chunk E: draft orders and conversion

- [ ] **Step 15: `CreatePurchaseOrder`, `UpdatePurchaseOrder` (draft half).** *(AC-40, AC-41, AC-50, AC-12, AC-53.)* `order_date` branch-local; currency explicit → supplier → **branch** (D-41); `currency_required` reached only per Q-B4; `exchange_rate` 8 decimals; refusals consume no number; supplier change re-copies snapshots; replay; deadlock rows.
- [ ] **Step 16: order lines.** *(AC-42 to AC-49, AC-51.)* As revision 1: AC-43 and twin stored and returned as strings; AC-46 JSON floats `validation`; AC-45 after each change; AC-47 header overflow refuses the line; AC-48 read unchanged after rate/group/price/factor/supplier change; AC-49 re-snapshot only when unit or quantity changes; description default; 201st line; line replay.
- [ ] **Step 17: `ConvertRequestToOrder`.** *(AC-30 to AC-33, AC-94.)* One transaction, two audit rows, `PO` number; `snapshotInherited()` keeps the stored factor; AC-32 refusals (`supplier_mismatch`, `supplier_inactive`, `invalid_transition`, `inherited_unit_mismatch`, `unit_unresolvable`, `pricing_refused`, `unknown_tax_group`, `inactive_tax_group`) write nothing; AC-33 replay; D-48 removing a converted line clears the pointer; 50-line query count recorded.
- [ ] **Step 18: `GetPurchaseOrder`, `ListPurchaseOrders`.** *(AC-81 to AC-85.)* **Gate: `lead`.**

### Chunk F: approval, amendment, receipt, events

- [ ] **Step 19a: `SubmitPurchaseOrder`, `SyncOrderApproval`.** *(AC-54 to AC-59, AC-97.)* Including the rejected + `stop` → `cancelled` row with the Q-B1 audit action and **no** cancel event (never approved).
- [ ] **Step 19b: amendment, `ReopenPurchaseOrder`, `CancelPurchaseOrder`, `ClosePurchaseOrder`.** *(AC-60 to AC-67, AC-97.)* Field x status dataset; reopen and cancel refused `already_received` with any application row, even one reversed to zero (D-45).
- [ ] **Step 19c: `ApplyReceivedQuantity`.** *(AC-68 to AC-80, AC-96.)* AC-75 per D-44; AC-77 per D-47 (zero quantity delta with non-zero base delta accepted; opposite signs refused).
  - **Mutations:** A1 line locked before header; A2 delta below zero accepted; A3 event on replay. **Gate: `lead`.**

### Chunk G: sweeps, scans, engine, documents

- [ ] **Step 20: `PurchasingInvariantTest`.** A scripted day through the real slices (as revision 1); actions a subset of the 28.
- [ ] **Step 21: `QaPurchasingSourceRulesTest`** (host). (a) No token from the D-38 list in `packages/purchasing/src` (`unit_conversions`, `base_unit_id`, `tax_rates`, `tax_group_taxes`, `price_list_items`, `is_inclusive`, `number_sequences`, `workflow_instances`, `workflow_tasks`, `workflow_events`, `customer_ledger`, `stock_levels`, `stock_movements`, `journal_`, `quantity_incoming`, `grns`); (b) no `*`, `/`, `multipliedBy(`, `dividedBy(` outside `Support/Money.php`, and `Money::product(` called only from the request-amount helper; (c) no float cast, `floatval`, float type; (d) no `routes/`, `Route::`, `Cache::`, `dispatch(`; (e) anti-vacuity. Red-first planted files. *(AC-46, AC-79, AC-93.)*
- [ ] **Step 22: `PurchasingSchemaEngineTest`** (written, skipped under the hold). Database created and dropped by **exact name**, never `LIKE`.
- [ ] **Step 23: cross-tenant rows on the stand-in.** One per root entity (supplier, request, order, application) (AC-88, D-46).
- [ ] **Step 24: documents.** `docs/database.md` §6.6 "As built (T-034)", §6.1 prefixes, §6.4 `PurchaseLinePricing`; `docs/architecture.md` module table. Describe, never quote, old vocabulary.
- [ ] **Step 25: final gate** — `lead` runs `composer check`; counts and `test` seconds compared with `lead`'s own baseline (more than 10 % on the `test` step is a stop, FP-328).

## Test strategy

- **Unit (pure):** `PurchaseLineCalculator`, `Money`, `BusinessClock`, both status machines as full transition datasets, `ApprovalOutcome`, `ReceiptRollup`, `OrderRollup`, `Amendments`, `SupplierCode`, `Redaction`.
- **Integration (SQLite stand-in, real implementations):** one test folder per slice against the real `UnitResolver`, `PurchaseLinePricing`, `TaxGroupDirectory`, `DocumentNumbers`, `PermissionResolver`, `AuditTrail`, `Outbox` and, for the approval rows, the real `WorkflowGate`. `FakeWorkflowGate` only for call shape and failure datasets. Every refusal row compares purchasing's tables, `audit_logs`, `outbox_events`, `number_sequences` and `workflow_instances` whole. Lock order pinned as SQL text with pricing's `LockMarkingGrammar` (reachable through `Package_Pricing_Tests`).
- **Static:** `PurchasingTenantSchemaTest`, `TenantDecimalPrecisionTest`, `QaPurchasingSourceRulesTest`, `UomColumnSetsTest`, deptrac with the demo control.
- **Engine:** `PurchasingSchemaEngineTest`, skipped under `ENGINE_HOLD` (AC-98).
- **Mutations:** P1 to P3, R1 to R3, S1, W1, W2, A1 to A3.

| ACs | Proved by step |
|---|---|
| 1 to 12, 17 | 9, 4 |
| 13, 14 | 10 |
| 15, 16, 18 | 11, 6 |
| 19 to 25, 36, 39 | 12, 4 |
| 26 to 29, 37 | 7, 13 |
| 30 to 33 | 17 |
| 34, 35, 38 | 14 |
| 40, 41, 50, 53 | 15 |
| 42 to 49, 51 | 2, 7, 16 |
| 52 | 6, 8, 12, 13, 19a |
| 54 to 59 | 19a |
| 60 to 67 | 7, 19b |
| 68 to 80 | 7, 19c, 22 (AC-78) |
| 81 to 83 | 18 |
| 84 to 86 | 6, 11, 14, 18, every slice |
| 87 | every slice, 20 |
| 88 | 4, 23, 22 |
| 89 | 4, 5 |
| 90 | every refusal row, 12, 15, 17 |
| 91, 92 | 6, every slice |
| 93 | 1, 21 |
| 94 | 8, 17 (recorded counts) |
| 95 | 6 |
| 96 | 19c |
| 97 | 19a, 19b, 19c, 20 |
| 98 | 22, the delivery rule |

## Risks & rollback

1. **No sync trigger (highest).** A decided approval does not move the document until something calls `Sync*` (D-52). Harmless without routes; FP-256 and the approver inbox must call it, or a follow-up trigger must exist before go-live.
2. **Contract edit in pricing** (T-029's package). Additive and separate; `qa` reviews steps 2 and 3 as their owners' code.
3. **NOT VERIFIED under `ENGINE_HOLD`:** lock order, the `transaction(..., 2)` retry, first-use gap-lock deadlock, AC-78 concurrency, JSON checks.
4. **Merge renumbering with T-032** (section 0.4): mechanical but touches about twelve shared files and `composer.lock`; republish, never hand-merge.
5. **Query cost.** About 1000 resolver queries for a 200-line order; measured (AC-94), FP-426 is the remedy.
6. **Create replay after an edit is `replay_conflict`.** Safe; noted for FP-256.
7. **List scoping** costs one permission decision per branch without a branch filter.
8. **A receipt reversed after close** is `order_not_open` (D-44). FP-255 (worktree `goods-receipts`, `ai/T-039-…`) must reverse before closing.
9. **Supplier delete vs. a concurrent request line:** `FOR SHARE` on the supplier; engine proof only (step 22).
10. **`schema:publish` without `--write-fixture` touches the master database.** Named in step 4; a slip would break `ENGINE_HOLD`.

**Rollback:** everything is new except the pricing contract, two prefixes and the shared test edits. Migrations are forward-only and no tenant outside this branch has run them; rollback is abandoning the branch.

## Follow-ups for `lead` (backlog rows, not built here)

- A trigger for `SyncOrderApproval` / `SyncRequestApproval` (D-52): engine event on instance completion, a console sweep, or the inbox calling sync after `decide()`.
- FP-250: a `Contracts\Purchasing` read contract for `quantity_incoming` (rebuildable), with the AC-97 events for notification and an optional cache.
- FP-255: calls `ApplyReceivedQuantity` inside its own transaction, keeps Ruling 7's lock order, maintains `received_value`, owns over-receipt tolerance (Q-1) and reversal after close.
- Catalog and Organization facts contracts (D-38); `BusinessClock` to the kernel at a third user (D-53c); FP-331 support copies.
- FP-426 batch unit resolve; unit code and a stored `tax_breakdown` on printed purchase orders (Q-4, D-27).
- Notification rule mapping `purchase.approved` to `purchase_order.approved` (D-51).
- Deferred columns (D-3) by their owners' expand migrations.
- Engine evidence for step 22 before the hold lifts.

## Questions for `ba` (none blocks; the plan builds the recommended answer)

- **Q-B1. Audit action when an order's approval is rejected with `on_reject = stop` (AC-59: the order becomes `cancelled`).** The preamble's closed list has no `purchasing_order_rejected`. **Recommended:** `purchasing_order_cancelled`, with the after image recording the workflow status `rejected`; no `purchase_order.cancelled` event, because the order was never `approved` (AC-97). Alternative: add `purchasing_order_rejected` to the list (one-line change).
- **Q-B2. Audit action for post-approval edits of `notes`, `terms`, `expected_date` (AC-61, AC-63: "one audit row each").** **Recommended:** `purchasing_order_updated` for header fields and `purchasing_order_line_updated` for a line's `expected_date`, the same actions as draft edits; the before/after images show the status. Revision 1's extra `purchasing_order_amended` is dropped.
- **Q-B3. AC-1 says a caller-supplied supplier id is "ignored/not accepted".** **Recommended:** not accepted, refused `validation` as an unknown field, which is what the house `Fields::only` does and what T-028's `CreateCustomer` does. Silently ignoring it would hide a client bug.
- **Q-B4. `currency_required` is practically unreachable** after D-41: `branches.currency` is `CHAR(3) NOT NULL`. **Recommended:** keep the code as a defensive refusal when the branch's currency is blank or not three letters, and reach it in a test with a stand-in branch row seeded with an empty currency (AC-92 "each reachable by a test"). Alternative: drop it from the vocabulary.

(Recorded, not asked: AC-16/AC-35/AC-82 make a non-positive `page_size` `validation`, which differs from the kernel `PageSize` convention, T-014 D-28, where `< 1` means the default. The spec and D-34 are explicit, so the plan validates first and clamps second.)

## Questions for the human

None of the plan's own. The spec's Q-1 to Q-4 stand with their built defaults.
