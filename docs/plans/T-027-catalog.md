# Plan: T-027 Catalog

Spec: `docs/specs/T-027-catalog.md` (AC-1 to AC-44, plus AC-3a, AC-13a, AC-27a, AC-34a, AC-36a and AC-40a; groups A to I). Decisions: `docs/decisions/T-027-catalog.md` (D-1 to D-19), as corrected in `2a58f28`.

**Revision 2.** `ba` folded F-1 to F-13 (section 0.2) into the spec in `2a58f28`, as D-12 to D-19, and each correction matches the resolution this plan proposed. Wherever the original text of this plan offered a "fallback" in case `ba` declined, that fallback is now void: the checks are required by the spec. Design authority: `docs/database.md` §3.1 to §3.3, §6.3, §6.5 ("Units stop at the document line", "The factor is snapshotted"), §14, §16 Q-9 to Q-12; `docs/decisions/T-006-unit-of-measure.md` U-1 to U-3, D-57 to D-84.
Backlog: FP-230 (FP-423 split out). Branch: `ai/T-027-catalog`. Gate directory: `modules/host`.

**`architect` did not measure the gate baseline.** Step 0 does. The last recorded figure is the `test` step at **1232 s warm** (`composer.json` `extra.flexipos.gate`, 2026-11-05), already over the old 900 s limit and now run under `process-timeout` 7800. This task adds roughly 250 cases. That makes test cost a first-order design constraint here (Ruling 13, risk 1).

---

## 0. Verification and rulings

### 0.1 `ba`'s findings, re-read in source

| Claim | Verdict | Evidence |
|---|---|---|
| D-1: T-006 shipped no code. No `UnitResolver`, no units or catalog migration | **Holds** | `git grep -il "UnitResolver\|unit_conversions\|stock_unit_id"` over every tracked file outside `docs/` and `Refference/` returns nothing. The tenant tree's 31 files contain no catalog table. `packages/contracts/src` has no `Catalog/`. T-006's commits (`cef6a17` and earlier) touch only docs and doc-assertion tests. T-027 is the **first implementation**, with nothing to integrate with. |
| D-2: `barcode_rules` and its two-engine parser split cleanly into FP-423 | **Holds, and the split is clean** | No column T-027 builds references `barcode_rules`. The coupling runs one way only: FP-423 reads `products.plu_code`, `products.sku` and `product_barcodes.barcode` (its `lookup` enum) and `units` (its `unit_id`). T-027 provides each of those with the unique index the lookup needs. §6.3's resolution order is "exact first, rules on a miss", so the exact table never needs to know whether rules exist. One note for FP-423, not a coupling: the exact-lookup *function* (barcode to product, unit and quantity) is not built here. T-027 ships the data and the index, and FP-423 ships the reader for both stages. |
| D-3/D-4: the FK columns to `tax_groups`, `accounts`, kitchen stations and `suppliers` defer cleanly | **Deferral holds. The mechanism `ba` describes does not (finding F-2)** | See F-2. Building the columns now would save nothing, because the later work is owed either way. |
| D-7: a variant is a `products` row with `type` `variant` and `parent_id` pointing at a `variant_parent` | **Holds** | §6.3: `type enum(simple, variant_parent, variant, service, bundle, composite), parent_id FK NULL` on the one table, with the ERD self-edge `PRODUCTS ||--o{ PRODUCTS : variant_of` and the index `(parent_id)`. There is no second table. The two added constraints follow from the enum's own vocabulary. |
| D-8: no coupling between `is_weighable`, `price_mode` and `plu_code` | **Holds** (second look below, Ruling 9) | Nothing in §6.3 "Weighable goods", U-2 or D-57 to D-84 states one. |
| D-9: purchase and sales units are checked for dimension only | **Holds, and it should be applied consistently (F-3)** | §6.3 calls both "UI defaults for a document line, never authorities". Conversion rows normally follow the product. |
| "Current behaviour": `custom_field_definitions` exists (T-011/T-014), so `[CF]` on products, categories and brands can validate | **False (F-1)** | No migration creates `custom_field_definitions`. T-008 D-4 lists it as **not** in the baseline, and nothing since has built it. §6.3 also tags only `products` with `[CF]` (and `[BY]`). `categories` and `brands` carry `[ID] [TS] [SOFT]` only. |

### 0.2 Findings routed to `ba`: all resolved in `2a58f28`

| Finding | Now in the spec as | Decision |
|---|---|---|
| F-1 | "Current behaviour" corrected; **AC-27a**; Out of scope | D-12 |
| F-2 | AC-6, AC-27, Out of scope, the NFR (eight named columns; expand plus contract; `tax_group_id` backfill) | D-13 |
| F-3 | **AC-29**, **AC-38** (dimension match required) | D-14 |
| F-4 | **AC-20** (component and modifier guards), **AC-36a** (modifier group guard) | D-15 |
| F-5 | **AC-34a** (transitive cycle) | D-16 |
| F-6 | **AC-11** `[ENGINE]` clause; **AC-42** names it as the one exception | D-17 |
| F-7 to F-12 | **AC-13a**, **AC-3a**, AC-36 (`NULL`), AC-12/AC-16 wording, AC-11 default 4, AC-7 | D-18 |
| F-13 | **AC-40a** (flagged for `lead`, not resolved) | D-19 |

**One reading this plan applies.** AC-20 blocks deletion while the product is the component of "a live `product_components` row". That table has no `[SOFT]` column, so "live" is read as **a row whose parent product is not soft-deleted**. This is what this plan already said ("a component of a live product"). A component whose only parent is soft-deleted can be deleted.

The original findings follow, unchanged, as the record of what was routed.

- **F-1 (correction).** `custom_field_definitions` does not exist, and `[CF]` sits on `products` alone.
  - **Plan:** `products.custom_fields` is **not built**. This follows T-014's precedent exactly: `branches` deferred its custom fields "for their own task" (`…000501_create_branches_table.php` docblock, T-014 D-10).
  - **Spec edit:** correct the "Current behaviour" bullet and add `custom_fields` to Out of scope.
  - **Fallback (void, see revision 2):** if `ba` wants the column now, it becomes a nullable JSON column that the handlers refuse to write. That is one migration line and one refusal.
- **F-2 (correction to D-3, AC-6, AC-27 and the forward-compatibility NFR).** Adding a deferred column later is **not** "an ordinary expand-step migration".
  - `SourceRules` AC-34 makes a foreign key onto an existing table a **contract**-phase migration. The precedents are `…000401_add_updated_by_foreign_key_to_settings_table.php` and `…000506_add_branch_foreign_keys.php`. So FP-231, FP-239 and FP-260 each owe an expand migration (the column) **plus** a contract migration (the key, with a data-clean pre-check).
  - `products.tax_group_id` is documented **without** `NULL`, so FP-231 also owes a backfill before it can be made `NOT NULL`.
  - Deferral is still right. A key-less column built now still needs that same contract step later, and would sit unguarded in between.
  - Also: the counts in the spec disagree with each other ("Three columns", "six", "six/seven"). The real number is eight: six on `products` and two on `categories`. Name them rather than count them.
- **F-3 (amend AC-29 and AC-38).** D-9's own argument ("no ladder step ever bridges two dimensions") applies equally to `product_barcodes.unit_id` (resolved against the product's stock unit when scanned) and to `modifiers.linked_unit_id` (resolved against the linked product's stock unit when stock is depleted).
  - A cross-dimension value in either column can **never** resolve, so it is a dead row from the moment it is written.
  - **Plan:** both get the same dimension-only check as purchase and sales units. This is still not a resolvability check.
  - **Fallback (void, see revision 2):** if `ba` keeps AC-29 and AC-38 as written, two lines are deleted in steps 14 and 17.
- **F-4 (amend AC-20).** "Subject to AC-22/AC-24's own reference checks" points at two criteria that contain no reference checks.
  - **Plan:** the delete guards mirror AC-5 and AC-8 for every soft-deletable entity something still references:
    - a product cannot be soft-deleted while it is a component of a live product, or the linked product of a modifier;
    - a modifier group cannot be soft-deleted while it is attached to a live product.
  - **Fallback (void, see revision 2):** drop those guards.
- **F-5 (amend AC-34).** The spec refuses only a product being its own component. A **transitive** cycle (A contains B, B contains A) would make every later depletion recursion infinite.
  - **Plan:** refuse any component edge that closes a cycle (Ruling 8). This is the safe direction and reversible.
- **F-6 (AC-11 and AC-42).** `CHECK (precision BETWEEN 0 AND 4)` cannot exist on the SQLite stand-in. Laravel cannot express it, and SQLite has no `ADD CONSTRAINT`; this is the `JsonValidity` precedent.
  - **Plan:** the **handler** refusal is proven on the stand-in. The **column's own CHECK** is written for MySQL and MariaDB and proven only by the engine test, NOT VERIFIED while `ENGINE_HOLD` is on. The same applies to the `FULLTEXT` index and the JSON-validity checks, which no AC names.
  - AC-42's "no criterion is `[ENGINE]`" is therefore wrong for that one clause of AC-11.
- **F-7 (informational, the ladder's lookup keys).** Ruling 3 defines exactly which `unit_conversions` rows steps 1 and 2 read. A row is read only as `from = line unit, to = stock unit`. There is no inversion and no chaining of conversion rows.
  - One write rule follows from that: a **product-scoped** row must have `to_unit_id = that product's stock_unit_id`. Any other target could never be read by the ladder, and `stock_unit_id` is immutable (AC-22), so the rule stays true. **This is an added refusal.**
- **F-8 (informational, AC-2).** The path format is the ids joined by `/`. With 26-character ULIDs in `VARCHAR(500)`, the deepest legal `depth` is **17**. A create or move that would push any row past 500 characters is refused by name.
- **F-9 (informational, AC-36).** "Unlimited" `max_select` is stored as `NULL`.
- **F-10 (informational, AC-16 and AC-12).**
  - "Construction enforces the rounding order" is met by the one documented construction path, `UnitResolver::snapshot()`. The published DTO checks its own shape only, and Ruling 4 gives the reason.
  - AC-12's "a unit with `precision = 0`" means the **stock** (base) unit's precision, because Q-11 is about the base quantity.
- **F-11 (informational, AC-11).** "`precision` defaults within 0–4" picks no value. **The plan's default is 4**, the storage scale, which means no rounding beyond storage. A default of 0 would silently round every fractional entry on a mass unit whose admin forgot the field.
- **F-12 (informational, AC-7).** §6.3 writes `brands` as "code, name unique". The plan follows the spec and makes **both** unique.
- **F-13 (doc finding, for the lead, not `ba`).** The two parts of database.md disagree about modifiers:
  - §6.5 "Units stop at the document line" says a depleting modifier's factor is "snapshotted when that row was written (D-62)";
  - §3.1 and §14 give `modifiers` **no** `unit_factor` column and exempt it by name.

  The plan follows §3.1 and §14, which AC-43 asserts. The restaurant workstream owes the reconciliation.

### 0.3 Second look at D-8 and D-9 (as `ba` asked)

- **D-8 holds.** No combination of the three columns is contradictory *as catalog data*. `ba`'s butcher example (weighable, `open_price`, no PLU, every label embeds the weight) is a real configuration.
  - The one derivable interaction is **with the stock unit's dimension**, not between the three columns. An `embedded_weight` label yields a quantity in a mass unit, so a weighable product stocked in a `count` unit can never resolve that line. `per_weight` pricing on a `count` stock unit has the same problem.
  - That rule belongs to whoever defines how a label's unit resolves, which is FP-423. **Not enforced here**; recorded as a note on FP-423 (section 10).
- **D-9 holds.** Checking only that a conversion is *possible* at product-save time is right, because §6.3's own order of operations creates the conversion after the product.
  - Two consequences, both accepted:
    - a purchase or sales unit of the right dimension with no path fails at document time, with a named error;
    - a sales unit smaller than the stock unit (stock kg, sales g) passes the dimension check and then always fails Q-9's ≥ 1 guard.
  - Both are document-time refusals, which is the documented point of failure. F-3 extends the same check to barcodes and modifiers.

### 0.4 Rulings

**Ruling 1: placement (D-11).** A new Composer path package, `packages/catalog`, named `flexipos/catalog`, with namespace `Flexipos\Catalog\`.
- Every shipped domain lives under `packages/`: organization, access, platform, workflow. The rule from T-014 and T-020 decides it: a domain that every later module reads through a contract is shared infrastructure, not a leaf `modules/*` module booted from `MODULES`.
- Pricing, sales, inventory, purchasing and the till will all read the catalog.
- `docs/architecture.md` §3's diagram is corrected in step 20.
- Rejected: `modules/catalog`. Other business modules would then have no allowed edge onto it except through contracts, which is the same thing, but without the deptrac layer that proves it.

**Ruling 2: the published contract** (`packages/contracts/src/Catalog/`, plain PHP with no vendor imports, like every other contract):

| Symbol | Shape |
|---|---|
| `UnitResolver` | `resolve(string $productId, string $fromUnitId): ResolvedFactor` and `snapshot(string $productId, string $unitId, int\|string $quantity): UnitSnapshot`. Both throw `UnitResolutionRefused`. The docblock states "one ladder, one caller": no module converts any other way, and `unit_factor` on a line must come from here |
| `ResolvedFactor` | readonly. `productId`, `fromUnitId`, `stockUnitId`, `factor` (a canonical 6-decimal string, e.g. `"24.000000"`), `FactorSource $source`, `?string $conversionId` |
| `FactorSource` | backed enum, one case per ladder step that can succeed: `product_conversion`, `global_conversion`, `unit_chain`, `stock_unit` |
| `UnitSnapshot` | readonly, holding the `[UOM]` quad plus `unit_code` (D-66, for the three print-bearing lines): `unitId`, `unitCode`, `quantity` (a 4-decimal string, already rounded to the line unit's precision), `unitFactor` (6 decimals), `baseQuantity` (4 decimals), `source`. The constructor checks **shape only**: ULID, decimal-string formats and scales, `unitFactor ≥ 1`. The docblock says to obtain it only from `UnitResolver::snapshot()` |
| `UnitResolutionRefused` | `extends RuntimeException`, with `public readonly UnitRefusal $reason` and one named constructor per reason |
| `UnitRefusal` | enum: `unknown_product`, `unknown_unit`, `cross_dimension`, `no_conversion_path`, `factor_below_one`, `inexact_factor`, `factor_out_of_range`, `invalid_quantity`, `quantity_out_of_range`, `fractional_base_quantity` |

Not published (no consumer, so no contract yet; this is organization's "bindings arrive with the slices that earn them"):
- a product or barcode reader, which belongs to FP-423 and FP-233;
- an *inherited-factor* snapshot (§6.5: a refund or return line inherits the referenced line's factor and never re-resolves). Its first caller, FP-233 or FP-254, adds `UnitResolver::snapshotInherited()` and reuses Ruling 4's calculator. That is recorded in section 10 so nobody re-implements the arithmetic.

**Ruling 3: the ladder, exactly** (AC-9, AC-10, AC-15; §6.3). `Catalog\Units\UnitLadder` is a **pure** function over data that `DatabaseUnitResolver` loads in three queries: the product row, every `units` row (about 20 per tenant), and the two candidate conversion rows.
0. The product does not exist: `unknown_product`. A soft-deleted product **still resolves**, because resolution is arithmetic, not sellability: a refund of a retired product must convert. An unknown `fromUnit` gives `unknown_unit`.
1. **Dimension pre-check:** `dimension(fromUnit) ≠ dimension(stockUnit)` gives `cross_dimension`.
   - Every step converts the same pair, so one check before step 1 covers every step (AC-10).
   - It also catches a hand-inserted cross-dimension row that bypassed AC-13's write check.
2. **Step 1:** the `unit_conversions` row with `product_id = :product`, `from_unit_id = :from` and `to_unit_id = :stock`.
3. **Step 2:** the row with `product_id IS NULL`, `from_unit_id = :from` and `to_unit_id = :stock`.
4. **Step 3, the chain** (only when `from ≠ stock`). Here `units.factor` means "one of this unit = `factor` × its `base_unit_id`", which is §6.3's own example: gram, base kilogram, factor 0.001.
   - Walk `from` to its root, multiplying factors, giving F(from). Walk `stock` the same way, giving F(stock).
   - **Same root:** factor = F(from) / F(stock), computed exactly with `brick/math` at unbounded scale.
     - If the quotient has no exact 6-decimal form, the result is `inexact_factor`, never a rounded factor. Example: pack-of-10 against pack-of-3 gives 3.333…. An explicit conversion row (step 1 or 2) is the remedy, and it is read first.
     - This is what makes U-2's "mass is where the chain does the work" true for a product stocked in grams and sold in kilograms (1 / 0.001 = 1000), however the tenant chose to point the chain.
   - **Different roots:** fall through.
   - A cycle met while walking (only possible by a hand insert, Ruling 6) also falls through, guarded by a visited set.
5. **Step 4:** `from = stock` gives `"1.000000"` with source `stock_unit`.
6. **Step 5:** `no_conversion_path`. There is **no** default `1` (AC-15).
7. **Output guards** on whatever step 1 to 4 produced:
   - factor < 1 gives `factor_below_one` (Q-9 / D-5, **validated on the output** as §6.3 requires);
   - factor > 999999999999.999999 (DECIMAL(18,6)) gives `factor_out_of_range`.

Rows are never inverted, never chained, and step 1 never falls back to a different `to_unit` (F-7). There is no cache: a cache key would be a new cross-tenant surface (T-022), and the reads are cheap.

**Ruling 4: the snapshot contract** (AC-12, AC-16; §3.1, §3.2, Q-11, D-62, D-66). `Catalog\Units\SnapshotCalculator::compute(ResolvedFactor, lineUnit {id, code, precision}, stockUnitPrecision, quantity)` is pure, uses `brick/math` `BigDecimal`, and never touches a float.
1. `quantity` is an int, or a string matching `^-?\d+(\.\d+)?$`. A float is refused (`invalid_quantity`), which is T-020 Ruling 2's money rule applied to quantity. Sign policy belongs to the caller; the components handler requires a value > 0.
2. **Round the entered quantity to the line unit's `precision` first**, `RoundingMode::HALF_UP`, which is MySQL's `ROUND()` on exact values. It is then rescaled to 4.
3. `base_quantity = ROUND(rounded quantity × unit_factor, 4)`, HALF_UP.
4. **Q-11:** if the **stock** unit's `precision = 0` and `base_quantity` has a non-zero fraction, the result is `fractional_base_quantity`. It is rejected, never rounded.
5. Either value outside DECIMAL(18,4) (more than 14 integer digits) gives `quantity_out_of_range`.

**What is snapshotted, and why** (the T-006 reasoning this contract carries):
- `unit_factor` is frozen on the row so that re-sizing a case cannot retro-change a written document, its landed cost or the average cost derived from it (§6.5 "The factor is snapshotted"). It also makes a projection rebuild deterministic.
- `base_quantity` is the authority every ledger, level and valuation reads. The valuation formula's quantity side reads base (D-74, D-83), while its money side multiplies the **line** quantity by a per-line-unit cost (D-57, D-72). So **both** `quantity` (in the line unit) and `base_quantity` must be stored, and neither can be derived from the other later without the frozen factor.
- `unit_code` lets a receipt reprint the unit that was sold (D-66).

The rounding mode and order are part of the contract text, because FP-237's TypeScript twin must match them exactly (§3.2).

The DTO holds strings. Doing the arithmetic in `packages/contracts` would have given that package its first vendor dependency. Instead, the one construction path is the resolver, and the DTO refuses malformed shapes (F-10).

**Ruling 5: decimal arithmetic.** `brick/math` (`^0.14`) is declared as a **direct** requirement of `packages/catalog`.
- It is already in `composer.lock` at 0.14.8, as a dependency of `laravel/framework`, so no new code is downloaded.
- It is exact at unbounded scale, and it uses GMP or BCMath when present and pure PHP otherwise.
- Rejected:
  - bcmath directly: an undeclared extension requirement (T-020 Ruling 4b), even though CI, Docker and XAMPP all load it today;
  - a hand-written string multiplier like T-020's `DecimalComparator`: step 3 needs exact **division**, and a second home-made decimal library is a liability;
  - floats: wrong in the fourth decimal.

**Ruling 6: units** (AC-11).
- `CreateUnit` checks:
  - `code` ≤ 50, unique, case-insensitively, per Ruling 12;
  - `name` ≤ 150;
  - `dimension` in the five-value enum;
  - `precision` an int 0–4, default 4 (F-11);
  - `base_unit_id` and `factor` both present or both absent;
  - the base exists and has the **same** dimension;
  - `factor` > 0, at most 6 decimals (refused, never rounded), and within DECIMAL(18,6).
- **A cycle cannot be created by `CreateUnit`.** The new row has a fresh id that no row references yet, so AC-11's cycle clause is reachable only through `UpdateUnit`, which is why that slice exists.
- `UpdateUnit` may change `code`, `name`, `precision`, `is_active`, and `base_unit_id` with `factor`. Each is re-validated, and the chain from the new base must not reach the unit itself (a cycle).
- **`dimension` is immutable** once created (-> ba informational). Purchase units, sales units, conversions and barcodes were all validated against it.
- Units have no `[SOFT]`, so there is no delete; retiring a unit means `is_active = false`.
- **`is_active` is stored and not enforced** anywhere in this task. No rule says what an inactive unit forbids, and resolution is arithmetic.

**Ruling 7: categories** (AC-1 to AC-5).
- `path` is the root-to-self ids joined by `/`, and `depth` is the number of ancestors. Both are computed by a pure `CategoryPath`.
- **Move** (`UpdateCategory` with `parent_id`):
  1. In one transaction, lock the moving row and the new parent's ancestor rows with `lockForUpdate()`.
  2. Refuse if the new parent is the category itself, or has the category's `path` as a prefix (AC-4).
  3. Refuse if the parent is missing or soft-deleted (AC-2).
  4. Refuse if the **deepest descendant** would pass 500 characters, naming that row (AC-3a, F-8). A create applies the same check to its own path.
  5. Rewrite the row, then every descendant, found by `path LIKE '<old>/%'` with the pattern escaped, **including soft-deleted ones**. They stay readable by id, so their path must stay true (AC-3).
- Delete: soft. It is refused, naming the blocker, while a live child or a live product references the category (AC-5).
- Reads: `GetCategory` returns a soft-deleted row by id. `ListCategories` excludes soft-deleted rows and is ordered by (`path`).

**Ruling 8: products, variants and components** (AC-18 to AC-27, AC-34).
- **Pure rules** in `ProductRules`, run on create **and on every update**:
  - only a `variant` has a `parent_id`;
  - that parent exists, is live and has type `variant_parent` (AC-19);
  - a `variant_parent` with live children may not change type;
  - a variant whose parent changes is re-checked.
- **`stock_unit_id`:** an update request that carries a value different from the stored one is refused **whole** (AC-22). The same value is a no-op.
- `purchase_unit_id` and `sales_unit_id` must have the stock unit's dimension (AC-23).
- `plu_code`: trimmed, blank stored as `NULL`, ≤ 10 characters, unique (AC-24).
- The enums are validated each on its own, with no coupling (AC-25, AC-26).
- `variant_options` and `variant_values` are any JSON value, stored as given (AC-21).
- **Defaults (-> ba informational; §6.3 gives none):** `track_stock` true, `tracking_mode` `quantity`, `is_weighable` false, `price_mode` `per_unit`, `is_sellable`, `is_purchasable` and `is_active` true, `allow_price_override` false, `allow_discount` true, `cost_price` and `selling_price` 0.0000, `min_selling_price` and `msrp` `NULL`.
- Money: an int or a decimal string, ≥ 0, at most 4 decimals.
- `version` starts at 1 and is incremented by every update.
- **Delete:** soft. It is refused for a `variant_parent` with live variants (AC-20), and for a product that is a component of a live product or the linked product of a modifier (F-4).
- **Components** (AC-34):
  - parent and component both live, and distinct;
  - no cycle: a breadth-first search from the proposed component over existing edges must not reach the parent (F-5);
  - `quantity` > 0;
  - `unit_id`, `quantity`, `unit_factor` and `base_quantity` come from `UnitResolver::snapshot(component, unit, quantity)` **through the contract**, so the handler reaches the interface and never `DatabaseUnitResolver`;
  - `wastage_percent` is 0–100 with at most 4 decimals, default 0.
  - **The factor is frozen on the row** (D-62). Changing the recipe means remove and re-add, which re-resolves. That is D-62's "visible and fixable".

**Ruling 9: barcodes, location and branch settings, and modifiers** (AC-28 to AC-33, AC-36 to AC-40).
- **Barcodes:**
  - `barcode` is trimmed, non-blank, ≤ 64, unique across the table;
  - a duplicate names the product that holds it;
  - `unit_id` is optional and must have the product's stock dimension (F-3);
  - `quantity` > 0, at most 4 decimals, default 1.
  - **One primary per product:** setting `is_primary` clears the product's other primary inside the same transaction (AC-30). The backstop is a stored generated `primary_product_id = CASE WHEN is_primary THEN product_id END` with a plain unique index, the `employee_branches.default_employee_id` shape, deliberately **not** §3.3's sentinel.
  - The barcode value itself is immutable: change it by removing and re-adding.
- **`product_locations`:** the three levels are ≥ 0, at most 4 decimals, default 0, in the stock unit, and never converted (AC-32).
- **`product_branches`:** `selling_price` is `NULL` (inherits) or ≥ 0 (AC-33).
- **Modifier groups** (AC-36):
  - `min_select` default 0;
  - `max_select` `NULL` = unlimited (F-9), and when set it must be ≥ `min_select`;
  - a soft delete is refused while the group is attached to a live product (F-4).
- **Modifiers** (AC-37, AC-38):
  - `linked_product_id`, `linked_quantity` and `linked_unit_id` are all three or none;
  - the linked product is live;
  - the linked unit has that product's stock dimension (F-3);
  - `linked_quantity` > 0;
  - `price_delta` and `cost_delta` may be negative, at most 4 decimals.
- **`product_modifier_groups`:** both sides live; a duplicate pair is refused (AC-39).

**Ruling 10: the slices.** One vertical slice per operation an AC needs, plus the inverse operation wherever data entry would otherwise be irreversible. `UpdateBrand` is included because brand codes and names are unique *including* soft-deleted rows, so a typo could never be fixed by delete and recreate.

| Area | Slices | ACs |
|---|---|---|
| Categories | `CreateCategory`, `UpdateCategory` (fields and move), `DeleteCategory`, `GetCategory`, `ListCategories` | 1–5 |
| Brands | `CreateBrand`, `UpdateBrand`, `DeleteBrand` | 7, 8 |
| Units | `CreateUnit`, `UpdateUnit` | 11 |
| Conversions | `CreateUnitConversion`, `RemoveUnitConversion` (a factor change is remove plus create; snapshots protect history) | 13, 14 |
| Products | `CreateProduct`, `UpdateProduct`, `DeleteProduct` | 18–27 |
| Barcodes | `AddProductBarcode`, `UpdateProductBarcode` (`unit_id`, `quantity`, `is_primary`), `RemoveProductBarcode`, `ListProductBarcodes` | 28–31 |
| Location and branch | `CreateProductLocation`, `RemoveProductLocation`, `CreateProductBranch`, `RemoveProductBranch` | 32, 33 |
| Components | `AddProductComponent`, `RemoveProductComponent` | 34 |
| Modifiers | `CreateModifierGroup`, `DeleteModifierGroup`, `CreateModifier`, `RemoveModifier`, `AttachModifierGroup`, `DetachModifierGroup` | 36–39 |

That is 33 slices. Each is `Features/<X>/{X.php, XHandler.php}`, plus `XResult.php` where it returns an id, with a mirrored `tests/Features/<X>/` (`VerticalSliceLayoutTest`).
- Commands take `array $fields` and `?string $actorId`, the organization shape. An unknown field is refused, not ignored (`Fields::only`).
- **No routes, no console commands, no jobs, no cache, no hooks** (spec Out of scope; T-022's sweeps unchanged).
- Get and List slices for brands, units, products and the child tables are follow-up work for the API/admin task (FP-240).

**Ruling 11: audit.** Every write handler records one `audit_logs` row through `Contracts\Audit\AuditTrail`, with module `catalog`, inside its own transaction. This is the T-014 and T-017 Ruling 3 precedent for tenant master data.
- `CatalogAuditActions::ALL` is built from each handler's `ACTION` constant.
- `CatalogServiceProvider implements DeclaresAuditActions`. `AuditWriter` refuses undeclared actions, so the declaration is required.
- **No permission keys.** Without HTTP they would protect nothing; FP-240 or the API task declares them.
- -> ba informational: the spec does not mention audit, and this is the established default.

**Ruling 12: identifiers and case.**
- Ids are ULIDs, folded to upper case (T-014 AC-61).
- `code`, `sku`, `plu_code`, `barcode` and brand `name` are stored trimmed, as entered. Their uniqueness pre-check compares with `lower()`, because the engine's `utf8mb4_*_ci` collation makes the unique index case-insensitive while SQLite's is not. Without that, the stand-in and production would disagree.
- The unique index stays the backstop: a `UniqueConstraintViolationException` maps to the same named refusal (`CreateBranchHandler`'s shape), which also covers accent-insensitive collisions the pre-check cannot see.

**Ruling 13: test cost.** The gate's `test` step is the constraint (risk 1).
- The ladder, the snapshot arithmetic, category paths, product rules and the component cycle check are **pure classes**, tested without a tenant fixture through datasets.
- Fixture-backed handler tests prove what only the database can: transactions, keys, the audit row and "nothing written on refusal". They are kept to one file per slice.
- Steps run the package's own tests plus the predicted-red files. The full `composer check` runs at each **chunk** boundary (section 6), not after every step.

**Ruling 14: the retired-vocabulary sweep.** `QaVocabularyRenameSweepTest` scans the whole working tree, and the product type enum has a member spelled like the retired module word. These spellings trip it:
- that word quoted in **key position**: followed by `:`, `=>` or `]`. Examples are a dataset key, a JSON key, or the last element of an array literal;
- its quoted plural;
- the studly form followed by `_`;
- the three-letter abbreviation (caseless).

The safe form is a backed enum, `case Service = '…';`, referenced as `ProductType::Service`. Never use the value as a dataset key or as the last element of an array literal; reference the enum. Step 10's red list will name the sweep if this is missed.

---

## 1. Approach summary

1. A new `packages/catalog`, reached from outside only through `Flexipos\Contracts\Catalog\*`: `UnitResolver`, `ResolvedFactor`, `UnitSnapshot` and their enum and exception.
2. Twelve forward-only tenant migrations implement §6.3 column for column, minus the eight deferred FK columns, `custom_fields`, `product_suppliers` and `barcode_rules`. The tree goes from 31 files to 43.
3. `UnitResolver` is the one ladder. Steps 1 to 5 run in §6.3's order behind a dimension pre-check and Q-9's ≥ 1 output guard, the chain step is exact, and it never defaults to 1.
4. `snapshot()` produces the `[UOM]` quad (plus `unit_code`): round the entered quantity to the line unit first, then compute base, HALF_UP, with Q-11's fractional-base rejection. `brick/math` does the arithmetic and no float is ever involved.
5. There are 33 CRUD slices, each in one transaction with one audit row and named refusals:
   - category paths are recomputed for the whole subtree on a move;
   - variants are checked against `variant_parent`;
   - `stock_unit_id` is immutable;
   - the product has one primary barcode;
   - components snapshot their factor when written;
   - modifier depletion columns are all-or-nothing.
6. Static tests cover the `[UOM]` column sets (AC-43) and decimal precision (AC-44), plus a source scan for "one ladder, one caller". An engine test covers what only MySQL can show, skipped under `ENGINE_HOLD`.

## 2. Alternatives rejected

- **`modules/catalog`:** see Ruling 1.
- **The arithmetic inside `packages/contracts`:** it would be the contracts package's first vendor dependency (Ruling 4).
- **Validating Q-9 on `unit_conversions` instead of on the output:** §6.3 says step 3 can produce a factor below 1 that no row validation sees. Writes only check `factor > 0`.
- **Inverting or chaining `unit_conversions` rows:** not in the ladder. It would be a second resolution rule (F-7).
- **Rounding an inexact chain quotient to 6 decimals:** a silent precision loss on every line. Refuse instead, and let an explicit row win.
- **A resolver cache:** a new cross-tenant key surface for three cheap reads.
- **Building the deferred FK columns as key-less placeholders:** the contract step is owed anyway (F-2).
- **Full Get/List CRUD for every table:** about 15 more slices with no consumer. They belong with FP-240's API.
- **One migration per chunk:** three catalogue republishes and three rounds of test-literal edits. One schema step, as T-020 did.

## 3. Files to create

`dev` writes every file. Files named `Qa*` are `qa`-accountable.

### `packages/contracts/src/Catalog/` (new)
`UnitResolver.php`, `ResolvedFactor.php`, `FactorSource.php`, `UnitSnapshot.php`, `UnitResolutionRefused.php`, `UnitRefusal.php` (Ruling 2).

### `packages/kernel`
- `src/Schema/Support/CheckConstraint.php`: `between(string $table, string $column, int $min, int $max)`. It runs only on MySQL or MariaDB (the `JsonValidity` shape), names its constraint `<table>_<column>_range`, and exposes a pure `statement()` for tests. Its integer-only signature means it cannot carry arbitrary SQL.
- `src/Schema/Support/FullTextIndex.php`: `add(string $table, list<string> $columns)`. MySQL or MariaDB only, because SQLite's grammar throws on `fullText()`. It also exposes a pure `statement()`.
- `tests/Schema/CheckConstraintTest.php` and `tests/Schema/FullTextIndexTest.php`: the exact statement text; a no-op on SQLite; an identifier that is not `[a-z_]+` is refused.

### `packages/catalog` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/catalog` 0.1.0, psr-4 `Flexipos\Catalog\` mapped to `src/`, requiring `flexipos/contracts` and `brick/math: ^0.14` |
| `src/CatalogServiceProvider.php` | binds `UnitResolver` to `Units\DatabaseUnitResolver` (singleton) and implements `DeclaresAuditActions`. No routes and no commands |
| `src/Units/{UnitLadder,UnitCatalogue,ConversionRow,SnapshotCalculator,DatabaseUnitResolver,Dimension}.php` | Rulings 3 to 6 |
| `src/Categories/CategoryPath.php` | Ruling 7 (pure) |
| `src/Products/{ProductType,PriceMode,TrackingMode,ProductRules,ComponentGraph}.php` | Ruling 8 (pure). Ruling 14 governs `ProductType` |
| `src/Support/{TenantConnection,Fields,Money,Identifier,Lookup,CatalogRefusal}.php` | the seventh copy of the FP-331 tenant-connection helper. Field and decimal-string validation. Live and any-row fetchers |
| `src/Exceptions/CatalogRefused.php` | named constructors: `validation`, `notFound`, `duplicate`, `inUse`, `immutable`, `cycle`, `tooDeep`, `dimensionMismatch` |
| `src/Registry/CatalogAuditActions.php` | Ruling 11 |
| `src/Features/<33 slices>/…` | Ruling 10 |
| `tests/Support/{CatalogSeed.php,catalog-fixture.php}` | raw-insert seeds for units (`each`, `case`, `kg`, `g`, `l`), categories, products, a branch and a location. `catalogEnterTenant()` enters only and never calls `boot()` (memory note) |
| `tests/Units/{UnitLadderTest,SnapshotCalculatorTest,DatabaseUnitResolverTest}.php` | steps 6, 7, 12 and 13 |
| `tests/Categories/CategoryPathTest.php`, `tests/Products/{ProductRulesTest,ComponentGraphTest}.php` | pure |
| `tests/Contracts/{UnitSnapshotShapeTest,ResolvedFactorShapeTest}.php` | step 5 |
| `tests/Schema/CatalogTenantSchemaTest.php` | step 3 |
| `tests/Features/<X>/<X>Test.php` | one per slice |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_08_002701_create_units_table.php` … `002712_…` | the twelve tables in this order: units, categories, brands, products, unit_conversions, product_barcodes, product_locations, product_branches, product_components, modifier_groups, modifiers, product_modifier_groups. Each is `@phase expand`, has no `down()`, and uses `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) enums and full decimal arguments |
| `tests/Feature/UomColumnSetsTest.php` | AC-43 (step 4) |
| `tests/Feature/TenantDecimalPrecisionTest.php` | AC-44 (step 4) |
| `tests/Feature/QaCatalogSourceRulesTest.php` | step 18 |
| `tests/Engine/CatalogSchemaEngineTest.php` | step 19, skipped under the hold |

Edits to existing files are listed in section 9.

## 4. Data / API changes

**Schema**: twelve tenant tables. No master change, and no existing table is altered.

| Table | Columns as built | Keys and indexes |
|---|---|---|
| `units` | `id`, `code` VARCHAR(50), `name` VARCHAR(150), `dimension` VARCHAR(30), `precision` TINYINT UNSIGNED default 4, `base_unit_id` NULL, `factor` DECIMAL(18,6) NULL, `is_active` default true, `created_at`, `updated_at` | unique `code`; FK `base_unit_id` to units; engine-only `CHECK precision BETWEEN 0 AND 4` |
| `categories` | `id`, TS, `deleted_at`, `parent_id` NULL, `code` VARCHAR(50), `name` VARCHAR(150), `path` VARCHAR(500), `depth` TINYINT UNSIGNED, `image_path` VARCHAR(255) NULL, `colour` VARCHAR(20) NULL, `sort_order` INT default 0, `is_active` default true | unique `code`; index `path`, index `parent_id`; FK `parent_id` to categories |
| `brands` | `id`, TS, `deleted_at`, `code` VARCHAR(50), `name` VARCHAR(150), `logo_path` VARCHAR(255) NULL, `is_active` | unique `code`, unique `name` |
| `products` | `id`, TS, `deleted_at`, `created_by`, `updated_by`, `type` VARCHAR(30), `parent_id` NULL, `sku` VARCHAR(50), `name` VARCHAR(150), `short_name` VARCHAR(40) NULL, `description` TEXT NULL, `category_id`, `brand_id` NULL, `stock_unit_id`, `purchase_unit_id` NULL, `sales_unit_id` NULL, `cost_price`, `selling_price` DECIMAL(18,4) default 0, `min_selling_price`, `msrp` DECIMAL(18,4) NULL, `track_stock`, `tracking_mode` VARCHAR(30), `is_weighable`, `price_mode` VARCHAR(30), `plu_code` VARCHAR(10) NULL, `shelf_life_days` INT UNSIGNED NULL, `is_sellable`, `is_purchasable`, `is_active`, `allow_price_override`, `allow_discount`, `weight` DECIMAL(18,4) NULL, `dimensions` JSON NULL, `variant_options` JSON NULL, `variant_values` JSON NULL, `image_path` NULL, `sort_order` INT, `version` BIGINT UNSIGNED default 1 | unique `sku`, unique `plu_code`; indexes (`category_id`), (`brand_id`), (`is_active`, `is_sellable`), (`parent_id`); engine-only FULLTEXT (`name`, `short_name`, `sku`); `JsonValidity` on the three JSON columns; FKs `parent_id` to products, `category_id` to categories, `brand_id` to brands, the three units to units, `created_by` and `updated_by` to employees |
| `unit_conversions` | `id`, `product_id` NULL, `from_unit_id`, `to_unit_id`, `factor` DECIMAL(18,6), `product_key` (`IdentifierColumn::sentinel`) | unique (`product_key`, `from_unit_id`, `to_unit_id`); FKs to products and units |
| `product_barcodes` | `id`, `product_id`, `barcode` VARCHAR(64), `unit_id` NULL, `quantity` DECIMAL(18,4) default 1, `is_primary` default false, `primary_product_id` (stored generated, `IdentifierColumn::nullable`) | unique `barcode`, unique `primary_product_id`; FK `product_id` **cascade**, `unit_id` to units |
| `product_locations` | `product_id`, `location_id`, `is_available` default true, `reorder_level`, `reorder_qty`, `max_level` DECIMAL(18,4) default 0, `bin_location` VARCHAR(40) NULL, TS | PK (`product_id`, `location_id`); FKs |
| `product_branches` | `product_id`, `branch_id`, `is_available` default true, `selling_price` DECIMAL(18,4) NULL, TS | PK (`product_id`, `branch_id`); FKs |
| `product_components` | `id`, `parent_product_id`, `component_product_id`, `unit_id`, `quantity` DECIMAL(18,4), `unit_factor` DECIMAL(18,6), `base_quantity` DECIMAL(18,4), `wastage_percent` DECIMAL(9,4) default 0, `is_optional` default false, `sort_order` | unique (`parent_product_id`, `component_product_id`); FKs |
| `modifier_groups` | `id`, TS, `deleted_at`, `name` VARCHAR(150), `min_select` SMALLINT UNSIGNED default 0, `max_select` SMALLINT UNSIGNED NULL, `is_required` default false, `sort_order` | — |
| `modifiers` | `id`, `group_id`, `name` VARCHAR(150), `price_delta`, `cost_delta` DECIMAL(18,4) default 0, `linked_product_id` NULL, `linked_quantity` DECIMAL(18,4) NULL, `linked_unit_id` NULL, `kitchen_note` VARCHAR(255) NULL, `sort_order`, `is_active` | FK `group_id` **cascade**; `linked_*` to products and units |
| `product_modifier_groups` | `product_id`, `group_id`, `sort_order` | PK (`product_id`, `group_id`); FKs |

Notes on the schema:
- The timestamp sets follow §6.3 exactly. `units`, `categories`, `brands`, `products`, `modifier_groups`, `product_locations` and `product_branches` get TS. `unit_conversions`, `product_barcodes`, `product_components` and `modifiers` do not.
- `RESTRICT` everywhere except the two `CASCADE`s §6.3 names.
- Foreign keys total **27**, and none of them goes onto a table that existed before this task's own files, so `SourceRules` AC-34 holds.
- `APP_REQUIRED_SCHEMA_VERSION` goes from 31 to 43, and the catalogue is republished (`php artisan schema:publish --write-fixture`).

**Contracts:** Ruling 2, six files. **HTTP, console, jobs, cache, hooks:** none. **Configuration:** none beyond `.env.example`.

**Dependencies:** `flexipos/catalog` (path package) and `brick/math` as a direct requirement of that package (Ruling 5). `brick/math` is already locked. The `composer.lock` diff must add only the path package entry.

## 5. Boundary impact

- **`deptrac.yaml`**, with no exemption added:
  - **Paths:** `../../packages/catalog/src` and `../../packages/catalog/tests`.
  - **Layers:** `Package_Catalog` = `^Flexipos\\Catalog\\(?!Tests\\).*` and `Package_Catalog_Tests` = `^Flexipos\\Catalog\\Tests\\.*`.
  - **Rulesets:**
    - `Package_Catalog: [Package_Kernel, Package_Contracts]`;
    - `Package_Catalog_Tests: [Package_Catalog, Package_Contracts, Package_Kernel, Package_Access]` (for `AccessTenantFixture`, the workflow-tests precedent);
    - `HostApp` gains `Package_Catalog`;
    - `HostTests` gains both layers.
  - **`Module_Demo` is not granted `Package_Catalog`. That is AC-41's instrument.**
- **PHPStan:** both paths. **Composer** (host): `require` gains `"flexipos/catalog": "*"`, and `autoload-dev` gains the tests namespace. Then run `composer update flexipos/catalog` (never `--lock`) and `composer validate --no-check-publish`.
- **`bootstrap/providers.php`:** `CatalogServiceProvider` after `WorkflowServiceProvider`.

## 6. Implementation steps

**Rules carried over from T-019, T-020 and T-026:**
- **Red-first:** show red by leaving something out or with a fixture. Never stub a guard to allow.
- **Red-list rule** after steps 1, 3 and 4: record the red list **before** fixing anything, and compare it with section 9. A file section 9 does not name means stop and report to `architect`.
- **No child processes** (`Artisan::call`).
- **Unique test basenames.**
- **Ruling 14's vocabulary hazard.**
- **List foreign `php.exe` processes** before each gate run.

**Gate cadence (Ruling 13):**
- Within a step: `composer lint`, `stan`, `deptrac`, then `vendor/bin/pest` over `../../packages/catalog/tests` plus the step's predicted-red files.
- **`composer check` in full at the end of each chunk**, compared with the previous chunk's counts. The delta must be zero or explained.
- Task completion is judged only by `composer check`.

### Chunk A: plumbing, schema, static instruments

- [ ] **Step 0: baseline.**
  - Run `composer check` and record: passed, failed and skipped counts; the `test` step's seconds; the FP-328 headroom line; the process list.
  - *(No AC.)*
- [ ] **Step 1: package plumbing.**
  - Create `composer.json` and an empty `CatalogServiceProvider`.
  - Make the section 5 edits, and add `'catalog'` to `QaToolchainRegistryCoverageTest`'s literal.
  - **Test** `tests/Support/CatalogProviderTest.php`: the provider is loaded, and `brick/math`'s `BigDecimal` is autoloadable.
  - **Negative control:** a scratch class in `modules/demo/src` that imports `Flexipos\Catalog\Units\UnitLadder` must fail `deptrac`. The same class importing `Flexipos\Contracts\Catalog\UnitResolver` must pass. Delete both afterwards.
  - *(AC-41 instrument.)*
- [ ] **Step 2: the kernel schema helpers.**
  - `CheckConstraint` and `FullTextIndex`, with their tests.
  - **Mutation:** removing the driver guard makes the SQLite no-op test throw.
  - *(Groundwork for AC-11's CHECK.)*
- [ ] **Step 3: the twelve migrations.**
  - Section 4, plus the section 9 edits and the republish.
  - **`CatalogTenantSchemaTest`**, on its own SQLite stand-in (the T-019 and T-020 shape):
    - each table's sorted column list equals section 4 **exactly**, and nullability is checked. **No lengths or precisions** here (SQLite reports bare types; AC-44 handles those statically);
    - every unique key, checked by a raw double insert. This includes `unit_conversions`: two global rows with the same pair are refused, one global row and one product row are accepted, and two product rows for the same product are refused (AC-13 backstop). `products.sku` and `plu_code` are refused even when the first row is soft-deleted (AC-24);
    - **`primary_product_id`:** a second primary row for one product is refused; two non-primary rows are accepted; another product's primary is accepted;
    - the foreign keys from `pragma foreign_key_list` equal the 27 in section 4;
    - the two cascades delete their children;
    - `SourceRules::inspect()` reports nothing for the twelve files, and none of them declares `down`.
  - **Predicted red:** section 9's schema list. Anything else means stop.
  - *(AC-13, AC-24, AC-28, AC-30 and AC-32 to AC-34 schema halves; AC-6, AC-27, AC-27a and AC-35 shown by the exact column lists: no deferred FK column, no `custom_fields`, no `product_suppliers` or `barcode_rules` table.)*
- [ ] **Step 4: AC-43 and AC-44 static tests (host).**
  - **`UomColumnSetsTest`:**
    - a literal list of §3.1's seventeen `[UOM]` table names, with §14's three by-name exemptions (`price_list_items`, `product_barcodes`, `modifiers`);
    - for each listed table **present** in the tenant tree: (a) it has a unit column; (b) unless exempt, it has `unit_factor` plus a base column for each entered-quantity column; (c) an exempt table has **exactly** its degenerate pair and no `unit_factor` or `base_quantity`;
    - **Anti-vacuity:** at least `product_components`, `product_barcodes` and `modifiers` must be present, so the test cannot pass on an empty tree.
    - **Red-first:** a fixture table set without `unit_factor` on `product_components` is reported, and so is a fixture `product_barcodes` carrying `base_quantity`.
  - **`TenantDecimalPrecisionTest`:**
    - over every tenant migration's code (`SourceRules::codeOnly`), every `decimal()` call's (precision, scale) is one of (18,4), (18,6), (9,4) or (18,8);
    - for T-027's files, an exact map of column to (precision, scale): quantities and money (18,4), the two `factor` columns and `unit_factor` (18,6), `wastage_percent` (9,4);
    - **Red-first:** a fixture `decimal('quantity', 18, 2)` and a two-argument `decimal('x', 18)` are each reported.
    - The bare-`decimal()` half is already `SourceRules` AC-35(a); this test cites it rather than duplicating it.
  - **Gate: `composer check` (end of chunk A).**
  - *(AC-43, AC-44.)*

### Chunk B: the unit model (the safety-critical core)

- [x] **Step 5: contracts.**
  - The six Ruling 2 files.
  - **`UnitSnapshotShapeTest`:**
    - a well-formed instance is accepted;
    - each of these is refused by name: a float anywhere, `quantity` with 5 decimals, `unitFactor` < 1, a malformed ULID, `baseQuantity` with more than 14 integer digits.
  - **`ResolvedFactorShapeTest`:** the same checks for `factor`.
  - **`FactorSource`:** exactly four cases. **`UnitRefusal`:** exactly the ten of Ruling 2.
  - *(Groundwork for AC-9 and AC-16.)*
- [x] **Step 6: `SnapshotCalculator` (pure).**
  - Every case is a dataset row with the exact string expected:
    - **rounding order:** line precision 0, quantity `2.5`, factor 24 gives quantity `3.0000` and base `72.0000` (the reverse order gives 60). Precision 2, quantity `1.005`, factor 3 gives `1.0100` and `3.0300` (reverse gives 3.0150);
    - **HALF_UP:** `2.5` at precision 0 gives `3` and never 2; `-2.5` gives `-3`;
    - base rounding to 4 decimals: `0.3333` × `3.000001` gives `0.9999`, rounded once;
    - **Q-11:** stock precision 0 with base `1.5` gives `fractional_base_quantity`. **Control:** stock precision 1 with the same input is accepted;
    - a float `2.5` gives `invalid_quantity`; `"1e3"` gives `invalid_quantity`;
    - base over 14 integer digits gives `quantity_out_of_range`;
    - `unit_code` is carried through.
  - **Mutations, each run and reverted:**
    - **M1:** compute base before rounding the quantity. The order rows go red;
    - **M2:** use `HALF_EVEN`. The `2.5` row goes red;
    - **M3:** delete the Q-11 check. That row goes red.
  - *(AC-12, AC-16 arithmetic.)*
- [x] **Step 7: `UnitLadder` (pure).** One dataset row per path, over an in-memory `UnitCatalogue` with these units:
  - `each` (root, count, p0); `case` (base each, 24, p0); `kg` (root, mass, p3); `g` (base kg, 0.001, p0); `lb` (base kg, 0.453592); `pack3` (base each, 3); `pack10` (base each, 10); `l` (root, volume).
  - **Cases:**
    - **step 1 beats step 2:** a product row case→each of 12 and a global row of 24 give 12, source `product_conversion`;
    - **step 2:** a global row alone gives 24, source `global_conversion`;
    - **step 3:**
      - stock `each`, line `case`, no rows gives 24, source `unit_chain`;
      - stock `g`, line `kg` gives `1000.000000`;
      - stock `g`, line `lb` gives `453.592000`;
    - **step 3 inexact:** stock `pack3`, line `pack10` gives `inexact_factor`. **Control:** a global row pack10→pack3 of 3.333333 is accepted from step 2;
    - **step 4:** line = stock gives `1.000000`, source `stock_unit`;
    - **step 5 (AC-15):** stock `each`, line a count root `dozen` with no chain and no rows gives `no_conversion_path`;
    - **AC-10:** line `l` on stock `kg` gives `cross_dimension`, **including when a hand-built global row l→kg is present**;
    - **AC-9 guard:** stock `kg`, line `g` gives `factor_below_one`. So does a product row of 0.5;
    - a chain cycle (built directly in the catalogue) falls through to `no_conversion_path` and never loops;
    - an overflowing factor gives `factor_out_of_range`.
  - **Mutations:**
    - **M4:** swap steps 1 and 2. The first row goes red;
    - **M5:** replace the final refusal with a return of 1. The step 5 row goes red;
    - **M6:** delete the ≥ 1 guard. The guard rows go red;
    - **M7:** delete the dimension pre-check. The hand-row case goes red;
    - **M8:** round the chain quotient instead of refusing. The inexact row goes red.
  - *(AC-9, AC-10, AC-15 logic.)*
- [x] **Step 8: units slices** (`CreateUnit`, `UpdateUnit`; Ruling 6).
  - **Tests:**
    - the required fields;
    - the dimension enum;
    - precision −1 and 5 refused, the default is 4;
    - a base without a factor refused, and the reverse;
    - a cross-dimension base refused;
    - factor 0, factor −1 and factor `0.0000001` (7 decimals) refused;
    - a duplicate code refused, also by case (`KG` against `kg`);
    - **cycle via update:** A→B, then B→A refused; A→B→C, then C→A refused. **Control:** C→B re-pointed to another root is accepted;
    - a changed dimension refused;
    - every refusal writes nothing, including no audit row;
    - each success writes exactly one audit row, compared whole.
  - *(AC-11.)*

### Chunk C: categories, brands, products, conversions, and the resolver against the database

- [x] **Step 9: categories** (five slices, `CategoryPath`; Ruling 7).
  - **Pure `CategoryPathTest`:** root, child and grandchild paths and depths; depth 17 accepted and 18 refused.
  - **Handler tests:**
    - AC-1 code refusals (blank, duplicate, duplicate by case);
    - AC-2: an unknown parent and a soft-deleted parent are each refused;
    - **AC-3:** move a subtree three levels deep under another root. Every descendant's `path` and `depth` is rewritten, a soft-deleted descendant is rewritten too, and nothing outside the subtree changes;
    - **AC-4:** moving under self is refused, and moving under a grandchild is refused;
    - **AC-3a:** a create at depth 18, and a move that would push the deepest descendant past 500 characters, are each refused, **naming the row that would overflow**. Nothing is written. **Control:** depth 17 is accepted;
    - **AC-5:** delete is refused while there is a live child and while there is a live product; a soft-deleted child and a soft-deleted product do not block; the deleted row is absent from the list and present by id.
  - **Mutations:**
    - **M9:** skip the descendant rewrite. The AC-3 test goes red;
    - **M10:** skip the ancestor check. The AC-4 test goes red.
  - *(AC-1 to AC-5, AC-3a.)*
- [x] **Step 10: brands** (`CreateBrand`, `UpdateBrand`, `DeleteBrand`).
  - A code shared with different names is refused, and a name shared with different codes is refused (AC-7).
  - Update renames, and uniqueness still applies.
  - Delete is refused while a live product references the brand, and is accepted after that product is soft-deleted (AC-8).
  - *(AC-7, AC-8.)*
- [x] **Step 11: products** (`CreateProduct`, `UpdateProduct`, `DeleteProduct`; `ProductRules`; Ruling 8).
  - **Pure `ProductRulesTest`:** every type against every parent type (AC-19's matrix), through the `ProductType` cases and **not** string-keyed datasets (Ruling 14).
  - **Handler tests:**
    - **AC-18:** each required field missing is refused; `sku` blank, 51 characters, duplicate, or duplicate by case is refused;
    - **AC-19:** a variant under a `variant_parent` is accepted; a variant under a simple product or under a variant is refused; a variant with no parent is refused; a `variant_parent` with a parent is refused;
    - **AC-20:** a `variant_parent` with a live variant cannot be deleted; with only a soft-deleted variant it can;
    - **AC-20 reference guards:** a product cannot be deleted while it is the component of a product that is not soft-deleted, or the linked product of a modifier. Each refusal names the blocking reference. **Control:** once the parent is soft-deleted, the component can be deleted (section 0.2's reading);
    - AC-21: options JSON round-trips exactly;
    - **AC-22:** an update with a different `stock_unit_id` is refused **and the row is byte-identical afterwards, including `name` sent in the same request and `version`**; the same `stock_unit_id` is accepted;
    - **AC-23:** a purchase unit of another dimension is refused; null is accepted;
    - **AC-24:** `plu_code` 11 characters refused; blank becomes null; two blanks on two products are both accepted; a duplicate is refused, including against a soft-deleted product;
    - **AC-25 and AC-26:** every enum combination is accepted (a small cross-product); an unknown value is refused;
    - a type change on a `variant_parent` with children is refused;
    - `version` increments;
    - one audit row per write.
  - **Mutation M11:** let `stock_unit_id` through on update. The AC-22 test goes red.
  - *(AC-18 to AC-27, AC-27a: the exact column list has no `custom_fields`, and a `custom_fields` field in a command is refused as unknown.)*
- [x] **Step 12: conversions and `DatabaseUnitResolver`** (`CreateUnitConversion`, `RemoveUnitConversion`; the binding).
  - **Handler tests:**
    - AC-13: self-conversion refused; cross-dimension refused; factor ≤ 0 refused; a duplicate global row refused **naming the existing row's id**; a global row and a product row for the same pair are both accepted;
    - **AC-13a:** a product row whose `to_unit` is not that product's stock unit is refused, **naming the product's actual stock unit**. **Control:** a global row with the same `to_unit` is accepted;
    - AC-14: an unknown product and a soft-deleted product are each refused.
  - **`DatabaseUnitResolverTest`, on the fixture,** with one integration case per ladder step plus one per refusal, proving the three queries load what the pure ladder expects:
    - resolving through the bound **contract** returns the same factor as `UnitLadder`;
    - a soft-deleted product still resolves;
    - an unknown product gives `unknown_product`.
  - *(AC-9, AC-10, AC-13, AC-13a, AC-14, AC-15 end to end.)*
- [x] **Step 13: `snapshot()` end to end.**
  - Through the contract, on the fixture: a case of 24 on an `each` product, quantity `2.5`, `case` precision 0, gives `3.0000`, `24.000000`, `72.0000`, unit code `case` and source `unit_chain`.
  - A `g` line on a `kg` product is refused with `factor_below_one`.
  - A fractional base on an `each` stock unit is refused with `fractional_base_quantity`.
  - **Gate: `composer check` (end of chunk C).**
  - *(AC-12, AC-16.)*

### Chunk D: barcodes, location and branch settings, components, modifiers

- [x] **Step 14: barcodes** (four slices).
  - AC-28: blank refused; 65 characters refused; a duplicate on another product refused **naming that product's sku**; `quantity` defaults to 1.
  - **AC-29:** a `unit_id` of another dimension is refused, naming the mismatch. **Control:** the same dimension is accepted, including a unit with no conversion path, because the check is on dimension only.
  - **AC-30:** add A as primary, then B as primary. Afterwards exactly one row is primary and it is B, and this was done in one transaction. The update path behaves the same.
  - **Mutation M12:** skip the clearing. The unique backstop throws, and the test sees a refusal instead of success: red.
  - List: ordered with the primary first.
  - *(AC-28 to AC-31.)*
- [x] **Step 15: location and branch settings** (four slices).
  - AC-32: the defaults are 0; a negative level is refused; 5 decimals refused; a duplicate pair refused; an unknown location refused.
  - AC-33: a null price inherits; 0 is accepted; a negative price is refused; a duplicate pair is refused.
  - *(AC-32, AC-33.)*
- [x] **Step 16: components** (`AddProductComponent`, `RemoveProductComponent`).
  - AC-34:
    - a self-component is refused;
    - a duplicate pair is refused;
    - **the snapshot is written:** 250 `g` of a `kg`-stocked flour gives `factor_below_one`, so the test uses a `g`-stocked component: `0.25` `kg` gives factor `1000.000000` and base `250.0000`.
  - **Factor frozen:** remove the global row and add one with a different factor (or change the `units` factor through `UpdateUnit`). The component row is unchanged.
  - **AC-34a:** A→B, then B→A refused; A→B→C, then C→A refused. Each refusal names the cycle, and nothing is written. **Control:** A→B and A→C, then B→C, is accepted: a diamond is not a cycle.
  - **Source scan:** the handler type-hints `Contracts\Catalog\UnitResolver`, never `DatabaseUnitResolver`.
  - *(AC-34, AC-34a.)*
  - **Mutation M13:** limit the cycle search to direct edges. The A→B→C→A case goes red.
- [x] **Step 17: modifiers** (six slices).
  - AC-36: the defaults (`min_select` 0, `max_select` stored as `NULL`); min 3 with max 2 refused; max null with any min is accepted.
  - AC-37: each partial triple (linked product only, product plus quantity, product plus unit) is refused; unit and quantity without a product is refused; all three is accepted; none is accepted.
  - AC-38: a soft-deleted linked product is refused; a linked unit whose dimension differs from the linked product's stock unit is refused. **Control:** the same dimension is accepted.
  - AC-39: a duplicate attach is refused; a soft-deleted side is refused.
  - **AC-36a:** deleting a group attached to a live product is refused. **Control:** when the only attached product is soft-deleted, the delete is accepted.
  - **Gate: `composer check` (end of chunk D).**
  - *(AC-36 to AC-40, AC-36a. AC-40a needs no test: it is the documentation note, F-13.)*

### Chunk E: scans, engine, documents

- [ ] **Step 18: `QaCatalogSourceRulesTest` (host).**
  - **One ladder, one caller (AC-17, NFR):**
    - no file under `packages/*/src`, `modules/*/src` or `modules/host/app`, other than `packages/catalog/src/Units/`, contains the code tokens `unit_conversions` or `base_unit_id`;
    - no file outside `Units/` multiplies by a factor column;
    - this reads code-only text through `SourceRules::codeOnly`.
    - **Red-first:** a scratch file in `packages/organization/src` that selects from `unit_conversions` is reported. Delete it afterwards.
  - **No surface:** `packages/catalog` has no `routes/` directory, and its source contains no `Route::`, `Cache::`, `dispatch(`, `ShouldQueue` or `HookGateway`. Therefore T-022's four sweeps need no entry (AC-41, NFR).
  - **Contracts purity:** `packages/contracts/src/Catalog` imports nothing outside the contracts namespace and PHP core.
  - *(AC-17, AC-41.)*
- [ ] **Step 19: `CatalogSchemaEngineTest`** (database `flexipos_t8test_t027_tenant`, created and dropped by name through `EngineDatabase`, never a sweep).
  - With the hold: the "refuses to reach the engine" case runs, and the rest is skipped.
  - Written now, for when G-1 clears:
    - `information_schema` column types equal section 4's decimals;
    - `precision = 5` is refused by the CHECK;
    - the FULLTEXT index exists;
    - invalid JSON in `variant_options` is refused;
    - the sentinel key and the `primary_product_id` key refuse on MySQL;
    - a case-variant duplicate `sku` is refused by the unique index;
    - migrating twice is a no-op.
  - *(AC-11's `[ENGINE]` CHECK clause, the one exception AC-42 names, stays NOT VERIFIED under `ENGINE_HOLD`. The other engine assertions in this file go beyond any AC, and nothing is claimed for them while the hold is on.)*
- [ ] **Step 20: documents.**
  - **`docs/database.md` §6.3, "As built (T-027)":**
    - the deferred columns, with F-2's expand-plus-contract mechanism;
    - the ladder's lookup keys (F-7);
    - the exact chain;
    - the snapshot's rounding mode;
    - the precision default of 4;
    - `primary_product_id`;
    - the max depth of 17;
    - `max_select` `NULL`.
  - **§14:** the `[UOM]` test now exists, with its file name.
  - **`architecture.md` §3:** business domains ship under `packages/`.
  - **Backlog notes:** FP-423 (barcode reader; the weighable/stock-dimension note from 0.3), FP-231 (`tax_group_id`: expand, backfill, contract), FP-233 and FP-254 (`snapshotInherited`), FP-240 (Get/List slices and permission keys), and a custom-fields row (F-1).
  - Write each in describe-don't-quote form (memory note: the gate sweeps the tree).
  - *(AC-6, AC-27, AC-35 recorded.)*
- [ ] **Step 21: final gate.**
  - `composer check` from `modules/host`; record the counts and the `test` seconds against step 0.
  - Then `composer test:fast` once more, for the inner-loop figure.
  - *(AC-41, AC-42.)*

## 7. Test strategy

- **Unit (pure, no fixture):**
  - `SnapshotCalculatorTest` (AC-12, AC-16), `UnitLadderTest` (AC-9, AC-10, AC-15);
  - `CategoryPathTest` (AC-2 to AC-4), `ProductRulesTest` (AC-19), `ComponentGraphTest` (F-5);
  - the contract shape tests and the kernel helper tests.

  These carry the bulk of the cases, through datasets.
- **Integration (SQLite tenant stand-in via `AccessTenantFixture`):**
  - one test file per slice: the refusals write nothing, including no audit row; each success writes one audit row compared whole;
  - `CatalogTenantSchemaTest` for keys and cascades;
  - `DatabaseUnitResolverTest` for loading.
- **Static (host):** `UomColumnSetsTest` (AC-43), `TenantDecimalPrecisionTest` (AC-44), `QaCatalogSourceRulesTest` (AC-17, AC-41), and deptrac with the demo negative control (AC-41).
- **Engine:** `CatalogSchemaEngineTest`, skipped under the hold (F-6).
- **Mutations:** M1 to M12. Each is run once and reverted, and recorded in `dev`'s hand-back. They cover every safety-critical guard: rounding order and mode, Q-11, ladder order, no default 1, ≥ 1, dimension, inexact chain, subtree rewrite, cycle, stock-unit immutability, single primary.

| ACs | Proved by |
|---|---|
| 1–5, 3a | step 9 |
| 6, 27, 27a, 35 | step 3 exact column lists (and step 11 for 27a), plus step 20 |
| 7, 8 | step 10 |
| 9, 10, 15 | steps 7 and 12 |
| 11 | step 8; the CHECK clause is `[ENGINE]` (step 19, NOT VERIFIED under the hold) |
| 12, 16 | steps 6 and 13 |
| 13, 13a, 14 | steps 3 and 12 |
| 17 | step 18 |
| 18–26 (incl. 20's reference guards) | step 11 |
| 28–31 | steps 3 and 14 |
| 32, 33 | step 15 |
| 34, 34a | step 16 |
| 36–40, 36a | step 17 (40a is a documentation note) |
| 41 | steps 1, 18 and 21 |
| 42 | step 21 (every criterion except AC-11's CHECK clause) |
| 43, 44 | step 4, plus step 19 |

## 8. Risks & rollback

1. **Gate time (highest).** The `test` step was already 1232 s warm. About 250 new cases on a tenant fixture could add minutes.
   - Mitigation: Ruling 13's pure-first design, plus measurements at step 0 and at each chunk boundary.
   - If a chunk adds more than 10 % to the `test` step, stop and report to `lead`, because FP-328 is a lead decision. Do not trim tests.
2. **Stand-in and engine divergence.** The CHECK, FULLTEXT, JSON validity, case-insensitive collation and `lockForUpdate` serialisation of concurrent category moves are proven only on the engine (F-6). Ruling 12's `lower()` pre-check narrows the collation gap. The rest is NOT VERIFIED until G-1, and recorded so.
3. **The arithmetic contract is now fixed for FP-237's TypeScript twin** (HALF_UP, round first, exact chain or refuse). Changing it later changes stored base quantities. It is recorded in database.md in step 20.
4. **AC-20's word "live" on `product_components`**, a table with no `[SOFT]`, is read as "the parent is not soft-deleted" (section 0.2). If `lead` or `ba` reads it differently, only step 11's guard query and its control change.
5. **Vocabulary sweep** (Ruling 14): a quoted product type in key position turns the gate red in an unrelated-looking file.
6. **`composer.lock`.** Only the path package entry may change. `brick/math` must not move version; if it does, revert and use `composer update flexipos/catalog --no-install` diagnostics.

**Rollback:** every file is new except the section 9 edits. The migrations are forward-only, and nothing outside this branch has applied them. Rollback is to abandon the branch; no tenant has run the tree.

## 9. Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock` | require and autoload-dev; path package entry | 1 |
| `modules/host/deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | section 5 | 1 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` | `'catalog'` in the shared-package literal | 1 |
| `database/catalogue/tenant_schema_versions.json` | republished | 3 |
| `.env.example` | `APP_REQUIRED_SCHEMA_VERSION` 31 → 43 | 3 |
| `tests/Helpers.php` `tenantTreeTables()` | the twelve names, under a T-027 comment | 3 |
| `tests/Feature/TenantBaselineBuildsTest.php` | the FK inventory gains the 27 keys; the branch-referencing list gains `product_branches` | 3 |
| `tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | a `$t027` list | 3 |
| `SchemaCataloguePublishTest` | fixed by the republish | 3 |
| `MigrationTreeDisciplineTest` | expected green, because the counts are derived. If red, measure before editing | 3 |
| `docs/database.md`, `docs/architecture.md` | step 20 | 20 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (rows or notes, not built here)

- **FP-423:**
  - the exact-plus-rules barcode reader;
  - a weighable or `per_weight` product needs a mass stock unit to resolve an `embedded_weight` label (0.3);
  - a soft-deleted product's barcodes stay unique and should be skipped by the reader.
- **FP-231:** `tax_group_id` and `purchase_tax_group_id` as expand plus backfill plus contract (F-2).
- **FP-260 and FP-239:** the same, for the account and kitchen-station columns (F-2).
- **FP-233 and FP-254:** `UnitResolver::snapshotInherited()` for reversing and continuing lines (§6.5), reusing `SnapshotCalculator`.
- **FP-240 or the API task:** Get/List for brands, units, products and the child tables; catalog permission keys.
- **A custom-fields task:** `custom_field_definitions`, plus `[CF]` on `products` (F-1) and on `branches` (T-014 D-10).
- **The restaurant workstream:** §6.5 against §3.1 on the factor of a modifier-derived movement (F-13).
- **FP-331:** the seventh copy of the tenant-connection helper.

## Questions for the human

None. F-1 to F-12 were resolved by `ba` in `2a58f28`. F-13 (AC-40a) remains a document inconsistency for `lead`. Q-9 to Q-12 remain the owner's, adopted as D-5 states.
