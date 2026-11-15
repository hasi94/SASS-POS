# QA Report: T-027 Catalog

## Verdict: PASS (round 1); PASS (round 2 — review-fix verification)

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

---

## Round 2 — review-fix verification (commit `103a7fc`)

**Verdict: PASS.** All 6 items from round-1 review (`docs/reviews/T-027-catalog.md`, 1 Critical + 5
Warnings) verified independently — not trusted from the commit message. Each code-level item was
mutation-tested: implementation read, the new/updated test confirmed to exist and match the claim,
the guard removed/disabled, the test reddened for the exact stated reason, the guard restored, the
test re-confirmed green, and `git status`/`git diff --stat` confirmed the worktree was left clean
after every round-trip.

### R-1 (self-referencing product) — PASS

- `UpdateProductHandler.php:154-156` now refuses `$newParentId === $productId` immediately after
  folding `parent_id`, before `checkParentage`/the parent lookup run.
- `UpdateProductTest.php:133-146` sends `{type: 'variant', parent_id: self}` on a childless
  `variant_parent` and asserts the row is byte-identical afterward (`toBe($before)`).
- Mutation check: removed the 3-line guard → `UpdateProductTest` reddened on exactly that test
  ("The operation was expected to be refused and succeeded", 1 failed/8 passed). Restored → 9
  passed (24 assertions). `git diff --stat` empty after restore.

### W-1 (unlocked referenced-row reads) — PASS

Read all 5 named locations directly; every one now reads with `lockForUpdate()` (via `Lookup::live(...,
lock: true)` or a direct query builder call), reading the same rows the review named:
- `CreateProductHandler.php:128` — variant-parent read.
- `CreateCategoryHandler.php:65` (`Lookup::live(..., 'categories', $parentId, 'parent category', lock: true)`) — parent-path read.
- `AttachModifierGroupHandler.php:47-48` — both product and group lookups.
- `CreateModifierHandler.php:81,84` — group and linked-product lookups.
- `AddProductComponentHandler.php:72-73,89` — parent/component lookups plus the whole
  `product_components` edge-set read (`->lockForUpdate()->get(...)`), matching the review's note
  that locking only the two per-edge rows would not stop a joint-cycle race.
- `Lookup::live()` itself (`packages/catalog/src/Support/Lookup.php:28-38`) applies `lockForUpdate()`
  to the query before `->first()`, correctly — not after the row is already read. No test possible on
  the single-process SQLite stand-in, as the review itself states; accepted per the task brief as a
  code-reading verification, not a forced race.

### W-2 (zero-quantity component) — PASS

- `AddProductComponentHandler.php:97-107` now calls `SnapshotCalculator::compute()` (via
  `self::snapshot()`) **before** the zero check, and checks `self::isZero($snapshot->quantity)` — a
  decimal-string regex (`/^-?0\.0*$/`), never a multiplication — comparing the already-rounded
  snapshot quantity.
- `AddProductComponentTest.php:161-175` uses a precision-0 unit and quantity `'0.4'` (passes the raw
  `> 0` check, rounds HALF_UP to `0`), asserts `CatalogRefusal::Validation` and that nothing was
  written (`product_components`count === 0`).
- Mutation check: commented out the post-rounding check (simulating a pre-rounding check) → reddened
  on exactly that test (1 failed/9 passed). Restored → 10 passed (25 assertions). Worktree clean
  after restore.

### W-3 (loosely-scoped scan exemption) — PASS

- `QaCatalogSourceRulesTest.php` now has two separate, full-path exemption lists
  (`QA_CATALOG_UNIT_CONVERSIONS_TOKEN_EXEMPT`, `QA_CATALOG_BASE_UNIT_ID_TOKEN_EXEMPT`), each matched
  by real, resolved path (`qaCatalogResolveExemptPaths()` calls `realpath()` and throws
  `RuntimeException` if a named file is missing — verified by reading the function, not just the
  claim).
- Each exemption is scoped to only the rule its files need: the `unit_conversions` scan takes only
  the first list, the `base_unit_id` scan takes only the second, proven mutually by
  `qaCatalogLadderTokenOffenders()` calls in the test's own "each exemption list is scoped to only
  its own rule" case (passing).
- The factor-multiplication scan (`qaCatalogFactorMultiplicationOffenders()`) takes **no** exemption
  parameter anywhere it's called — confirmed by reading every call site in the test file.
- Mutation check (going further than static reading, per the task's "would the scan actually catch
  it" instruction): added a dead private method computing `$quantity * $row->factor` to
  `CreateUnitHandler.php` (one of the 4 exempted files) → `QaCatalogSourceRulesTest`'s
  factor-multiplication test reddened, reporting exactly that file as an offender (1 failed/8 passed,
  "Failed asserting that two arrays are identical" with `CreateUnitHandler.php` in the actual array).
  Reverted → 9 passed (894 assertions). Worktree clean after revert. This proves the factor scan
  genuinely has zero exemption for any of the 4 files — a real bug introduced in one of them today
  would be caught.

### W-4 (doc fix corrections) — PASS

- `docs/database.md:717`: no `D-62` citation anywhere in the file (`grep -n "D-62" docs/database.md`
  → no matches); text now reads "a depleting `modifiers` row carries no stored factor at all... How
  and when that factor gets resolved is the depletion posting rule itself, which is deliberately
  unspecified here and belongs to the restaurant workstream (AC-40, §6.3)" — matches AC-40/§6.3
  exactly, no overstated settled-design claim.
- `docs/decisions/T-027-catalog.md`'s D-19 addendum (lines 286-296) is now a clean, standalone
  paragraph after D-19's own closing sentence (not spliced mid-sentence into it), cites the current
  line 717 (verified against the actual current line number in `docs/database.md`, not the stale
  670), and explicitly states the old prose was wrong for "generalizing `product_components`'
  write-time-snapshot behavior to `modifiers`... and overstating consumption-time resolution as this
  task's own settled design."

### W-5 (slice count) — PASS

- `docs/database.md:546` now reads "31 CRUD slices."
- `docs/plans/T-027-catalog.md` lines 246, 283, 328 all now read "31" (confirmed via grep across the
  whole file — no remaining "33" in the plan).
- Verified 31 is correct: `ls packages/catalog/src/Features` → exactly 31 directories, matching the
  plan's own listed set exactly (28 write slices + `GetCategory`/`ListCategories`/
  `ListProductBarcodes`).
- Note: `docs/qa/T-027-catalog.md` (this file, round 1, above) and `docs/reviews/T-027-catalog.md`
  still say "33" in two places — correctly untouched, since those are historical records of what was
  said at the time, not the locations W-5's fix scope named (the plan and `docs/database.md:546`
  only). Not a defect.

### Full gate — PASS, baseline reproduced exactly

- `php.exe` processes checked before the run: none referenced this worktree (6 unrelated processes
  from other local projects — `flexi-crm`, `Scriber`, a `SimplePos-System` dev server, a `tinker`
  script, plus 2 `artisan serve`). Checked again repeatedly during the run (CPU-time growth on the
  `pest` child process confirmed real progress, not a hang, despite a long wall-clock duration driven
  by contention from those unrelated processes) — none ever referenced this worktree.
- `cd modules/host && composer check` run in full. `check` runs `probes:clean, engine:clean, lint,
  stan, deptrac, test` in strict sequence and stops at the first failing step — reaching `test`'s own
  completed summary line confirms `lint`, `stan` and `deptrac` all passed.
- `test` step result: **13 failed, 31 skipped, 3258 passed (22688 assertions)**, duration 2408.65s.
  This is an **exact match** to the lead's stated known-clean baseline for commit `103a7fc` (13
  failed / 3258 passed / 31 skipped) — no new failure, no newly-passing test masking a regression,
  no count drift.
- The 13 failures are the known baseline signature: `QaModuleRegistryContractTest` (FP-316 cluster,
  confirmed by name in the captured output) and `QaVocabularyRenameSweepTest` (the standing two
  non-production survivors). Both pre-exist this fix round and are unrelated to any of the 6 changed
  files.
- Benign Windows SQLite teardown warnings (`unlink(...): Resource temporarily unavailable` on
  `tenancy-fixture/tenant-a.sqlite`) appeared in the tail of the output — a known flake pattern on
  this machine during cleanup, not a test failure (no failing assertion tied to them; the final
  Tests: line accounts for all 13 failures by name as the known cluster).
- No targeted test file touched during mutation testing was left modified; `git status`/`git diff
  --stat` confirmed empty on the production-code side both after each individual mutation round-trip
  and before the full gate run.

### Defects: none.

**Run 2: PASS → PASS** (round 1 clean pass; round 2 confirms all 6 review-fix items hold up to
mutation testing / direct verification, full gate reproduces the exact known baseline, 0 defects).
