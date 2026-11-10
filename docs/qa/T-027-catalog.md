# QA Report: T-027 Catalog

## Verdict: PASS

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `CreateCategoryTest` (code unique/non-blank, validation naming the field) | PASS |
| AC-2 | `CreateCategoryTest`, `CategoryPathTest` (path/depth computed; unknown/soft-deleted parent refused) | PASS |
| AC-3 | `UpdateCategoryTest` (move rewrites every descendant incl. soft-deleted, one transaction) | PASS |
| AC-3a | `CategoryPathTest`, `CreateCategoryTest`, `UpdateCategoryTest` (depth 17 accepted, 18/500-char overflow refused, naming the row) | PASS |
| AC-4 | `UpdateCategoryTest` (self / descendant cycle refused) | PASS |
| AC-5 | `DeleteCategoryTest` (live child / live product blocks; soft-deleted does not) | PASS |
| AC-6 | `CatalogTenantSchemaTest` (`categories` exact column list excludes the two deferred FK columns) | PASS |
| AC-7 | `CreateBrandTest`, `CatalogTenantSchemaTest` (code and name both independently unique) | PASS |
| AC-8 | `DeleteBrandTest` (live product blocks; soft-deleted does not) | PASS |
| AC-9 | `UnitLadderTest` (≥1 output guard, `factor_below_one` from chain and from a product row) | PASS |
| AC-10 | `UnitLadderTest` (cross-dimension refused even with a hostile global row present) | PASS |
| AC-11 | `CreateUnitTest`, `UpdateUnitTest` (precision 0–4 default 4, base/factor pairing, cycle via update, dimension immutable); DB `CHECK` clause `[ENGINE]`, correctly NOT VERIFIED under `ENGINE_HOLD` (`CatalogSchemaEngineTest`, held) | PASS (handler half); NOT VERIFIED (engine half, as spec requires) |
| AC-12 | `SnapshotCalculatorTest` (Q-11 fractional-base rejection on stock-unit precision 0, control at precision 1) | PASS |
| AC-13 | `CreateUnitConversionTest`, `CatalogTenantSchemaTest` (self-conversion, cross-dimension, factor ≤0, duplicate naming the existing row id, global+product coexist) | PASS |
| AC-13a | `CreateUnitConversionTest` (product row `to_unit_id` must be the product's stock unit, naming it; global row control accepted) | PASS |
| AC-14 | `CreateUnitConversionTest` (unknown / soft-deleted product refused) | PASS |
| AC-15 | `UnitLadderTest` (exhaustion → `no_conversion_path`, never a default of 1) | PASS |
| AC-16 | `SnapshotCalculatorTest`, `UnitSnapshotShapeTest` (round line unit first, then base, HALF_UP, exact order) | PASS |
| AC-17 | `QaCatalogSourceRulesTest` (no file outside `Units/` names `unit_conversions`/`base_unit_id` or multiplies by a factor; planted-file negative controls) | PASS |
| AC-18 | `CreateProductTest` (required fields, sku blank/51-char/duplicate incl. by case) | PASS |
| AC-19 | `ProductRulesTest`, `CreateProductTest` (variant/variant_parent parentage matrix) | PASS |
| AC-20 | `DeleteProductTest` (variant_parent with live child; component/modifier-link reference guards) | PASS |
| AC-21 | `CreateProductTest` (variant_options/variant_values JSON round-trip exactly) | PASS |
| AC-22 | `UpdateProductTest` (different stock_unit_id refused whole, byte-identical row incl. other fields in same request; same value accepted) | PASS |
| AC-23 | `CreateProductTest`, `UpdateProductTest` (purchase/sales unit dimension-only check; null accepted) | PASS |
| AC-24 | `CreateProductTest` (blank→null, 11 chars refused, duplicate incl. vs soft-deleted) | PASS |
| AC-25/AC-26 | `CreateProductTest` (independent enum validation, no coupling) | PASS |
| AC-27 | `CatalogTenantSchemaTest` (`products` exact column list excludes all six deferred FK columns) | PASS |
| AC-27a | `CreateProductTest` (`custom_fields` refused as unknown field) | PASS |
| AC-28 | `AddProductBarcodeTest`, `CatalogTenantSchemaTest` (blank/65-char/duplicate naming the owning sku; quantity default 1) | PASS |
| AC-29 | `AddProductBarcodeTest` (unit dimension mismatch refused, naming it; same dimension with no path accepted — dimension-only) | PASS |
| AC-30 | `AddProductBarcodeTest`, `UpdateProductBarcodeTest`, `CatalogTenantSchemaTest` (one primary per product, generated-column backstop) | PASS |
| AC-31 | Confirmed by absence: no `barcode_rules` table/parser in tree | PASS (scope boundary) |
| AC-32 | `CreateProductLocationTest` (defaults 0, negative/5-decimal refused, duplicate pair refused) | PASS |
| AC-33 | `CreateProductBranchTest` (null inherits, 0 accepted, negative refused, duplicate refused) | PASS |
| AC-34 | `AddProductComponentTest` (self-component/duplicate refused; factor snapshotted and frozen through the contract) | PASS |
| AC-34a | `ComponentGraphTest`, `AddProductComponentTest` (direct + transitive cycle refused, diamond accepted) | PASS |
| AC-35 | Confirmed by absence: no `product_suppliers` table | PASS (scope boundary) |
| AC-36 | `CreateModifierGroupTest` (`max_select` NULL = unlimited; min>max refused) | PASS |
| AC-36a | `DeleteModifierGroupTest` (live attachment blocks; soft-deleted product does not) | PASS |
| AC-37 | `CreateModifierTest` (all-or-nothing linked triple) | PASS |
| AC-38 | `CreateModifierTest` (soft-deleted linked product refused; linked unit dimension mismatch refused) | PASS |
| AC-39 | `AttachModifierGroupTest` (duplicate pair refused; both sides must be live) | PASS |
| AC-40 | Confirmed: no depletion-posting logic built, stated as scope boundary | PASS (scope boundary) |
| AC-40a | `docs/database.md` line 717 re-read directly: no remaining `unit_factor`-on-modifiers implication; single, consistent statement | PASS |
| AC-41 | Full gate: lint clean, stan 0 errors, deptrac 0 violations; `UnitResolver`/`UnitSnapshot` published only from `packages/contracts/src/Catalog` | PASS |
| AC-42 | `composer check` run independently: 13 failed (all pre-existing, see below) / 3255 passed / 31 skipped; only AC-11's CHECK clause NOT VERIFIED, exactly as the spec requires | PASS |
| AC-43 | `UomColumnSetsTest` (exact `[UOM]` column sets, anti-vacuity, red-first fixtures) | PASS |
| AC-44 | `TenantDecimalPrecisionTest` (every `decimal()` call in the tenant tree matches an allowed (precision,scale) pair; T-027's own exact column map) | PASS |

## Test run summary

- Commands run independently from `modules/host`:
  - `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" check`
- Result: `lint` → `{"tool":"pint","result":"passed"}`; `stan` → "No errors"; `deptrac` → "Skipped violations 0" (no rule violations reported); `test` → **13 failed, 31 skipped, 3255 passed (22676 assertions)**, duration 1995.91s (well inside the 7800s gate limit, ratio 3.91).
- This exactly reproduces the numbers `dev` reported in the backlog ("13 failed (exact pre-existing baseline) / 3255 passed / 31 skipped").
- **Every one of the 44 `packages/catalog/tests/*` suites, and every T-027 host-side static instrument (`QaCatalogSourceRulesTest`, `UomColumnSetsTest`, `TenantDecimalPrecisionTest`, `CatalogTenantSchemaTest`, `MigrationTreeDisciplineTest`, `TenantBaselineBuildsTest`, `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`, `QaToolchainRegistryCoverageTest`, `SchemaCataloguePublishTest`), passed.** `CatalogSchemaEngineTest` correctly held under `ENGINE_HOLD` (WARN/skipped, as required).
- **The 13 failures, individually confirmed not to be a T-027 regression:**
  - 12 are exactly FP-316's long-documented, pre-existing, order-dependent cluster: `ModulesEnvParsingTest`×1, `QaHealthProbeResilienceTest`×2, `QaModuleNameValidationTest`×3, `QaModuleRegistryContractTest`×6 — this cluster has rotated across every task's gate since T-010 and is unrelated to any task's own code (confirmed against this project's own QA-engineer memory and a dozen prior `docs/qa/*.md` reports).
  - 1 is `QaVocabularyRenameSweepTest`, citing the exact same two non-production survivors T-026's own QA already confirmed pre-existing: `.claude/agent-memory/developer/project_t026_create_owner_verify_activate.md:43` and `frontend/package-lock.json:2371` — both last touched by T-026/T-025 commits (`32f5fd0`, `a2bf498`), neither touched by any T-027 commit (confirmed by `git log` on both files), neither is catalog production code.
- No contamination: `php.exe` processes checked both before and during the run; none referenced this worktree. One unrelated `php.exe artisan test --group=slow` process appeared mid-run but its command line carries no path into this worktree and is consistent with the other already-identified unrelated local projects (flexi-crm, Scriber, a tinker script) running on this machine; final counts matched `dev`'s own independently-reported baseline exactly, which is strong evidence against contamination having altered the result.

## Judgment calls verified independently (per the lead's "things flagged" list)

1. **Source-rules scan exemption (`CreateUnitHandler`/`UpdateUnitHandler`/`CreateUnitConversionHandler`/`RemoveUnitConversionHandler`).** Read all four files in full. None computes a resolution factor: `CreateUnitHandler`/`UpdateUnitHandler` only validate `factor`'s format/sign and walk the base-chain to refuse a **cycle** (a write-time structural check, never a resolution read); `CreateUnitConversionHandler`/`RemoveUnitConversionHandler` only CRUD the `unit_conversions` row itself. The exemption is correct, not a hidden violation — and `QaCatalogSourceRulesTest` proves the scan can still fail via planted-file negative controls, so the exemption isn't just hiding a tautology.
2. **8 deferred FK columns.** Read all four `products`/`categories`-touching migrations directly: `tax_group_id`, `purchase_tax_group_id`, `income_account_id`, `cogs_account_id`, `inventory_account_id`, `kitchen_station_id` (products) and `income_account_id`, `kitchen_station_id` (categories) are genuinely absent — not half-built, no orphan nullable column, no dangling index. `CatalogTenantSchemaTest` independently asserts the exact column list excludes every one of them.
3. **AC-27a custom_fields.** `Fields::only()` refuses any field outside each handler's own `FIELDS` constant; `custom_fields` is not in `CreateProductHandler::FIELDS`/`UpdateProductHandler::FIELDS`. A real test (`CreateProductTest`, "refuses custom_fields as an unknown field (AC-27a)") submits it and asserts the refusal.
4. **`docs/database.md` §6.5/line 717 fix.** Read the current text directly: it now states plainly that `product_components` snapshots its factor at write time while a depleting `modifiers` row "carries no stored factor at all" and resolves fresh at consumption (D-62) — matching the shipped schema (`modifiers` migration has no `unit_factor` column; `CatalogTenantSchemaTest` and `UomColumnSetsTest` both assert this). Searched the whole document for any other `D-62`/"snapshotted when" reference to modifiers: none found — the fix is complete and internally consistent, not just locally patched.
5. **Mutation checks (M1–M13).** Not re-run live (would require editing shipped production code, which is outside a QA engineer's file ownership), but independently verified by static reading that every safety-critical guard the mutations target is actually implemented as described, and that the real test files contain the exact discriminating rows the plan's mutation list requires (e.g. `SnapshotCalculatorTest`'s "precision 0, qty 2.5, factor 24 → 3.0000/72.0000, not 60" row directly falsifies M1; `UnitLadderTest`'s hostile-global-row AC-10 case directly falsifies M7; `ComponentGraphTest`'s/`AddProductComponentTest`'s A→B→C→A cases directly falsify M13; `CatalogTenantSchemaTest`'s primary-barcode-backstop insert-and-expect-throw directly falsifies a skipped M12 clear). No gap found between the plan's mutation claims and the shipped guards.
6. **Cross-cutting audit action registration.** `CatalogServiceProvider implements DeclaresAuditActions`; `AuditCatalogue` (packages/access) discovers declared actions dynamically from booted providers via the container (`$this->app->getProviders(DeclaresAuditActions::class)`) rather than a static list, so no `packages/access` file needed editing when Chunk B started declaring catalog actions — confirmed by grep showing zero catalog-specific references anywhere in `packages/access`. `CatalogAuditActions::ALL` (28 entries) was hand-counted against the 28 write-capable handlers among the 33 slices (the other 5 — `GetCategory`, `ListCategories`, `ListProductBarcodes`, and two read paths — are read-only and rightly audit nothing); the count matches exactly.

## Regression risks / notes

- None found. Every handler reviewed (all 33 feature slices, read in full) follows the same transaction/validate-then-write/one-audit-row/named-refusal shape consistently; every negative case named in the spec has a real, discriminating test (not a tautology — e.g. schema tests use real `QueryException`-throwing inserts, not `toContain()` checks).
- `FP-316`'s rotating cluster remains a standing, cross-task gate-hygiene issue (tracked in the backlog) — unaffected by and unrelated to this task.
- The `CHECK (precision BETWEEN 0 AND 4)` clause on `units.precision`, and the handful of other MySQL-only assertions in `CatalogSchemaEngineTest` (FULLTEXT, JSON validity, case-insensitive collation, a case-variant duplicate-sku refusal), remain genuinely NOT VERIFIED until `ENGINE_HOLD` clears (G-1) — this is the one explicitly-acknowledged gap in the spec itself (AC-42), not an oversight.
- `FP-423` (barcode_rules), `FP-240` (Get/List + permission keys), and the other filed follow-ups are correctly out of this task's scope and were not expected to be built here.
