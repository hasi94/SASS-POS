# T-027: Catalog — categories, brands, units, products and variants, modifiers

Backlog: FP-230, "Catalog: products, variants, barcodes, categories, brands, units, modifiers,"
widened by T-006 (DONE, documents only — see Current behaviour) to include "unit dimension, the
resolver and its snapshot contract, the stock/purchase/sales unit split, weighable flags and PLU, and
`barcode_rules` with a parser in both engines (PHP and TypeScript)." Decisions D-1 to D-11 are in
`docs/decisions/T-027-catalog.md`. **No BLOCKING questions** — this is autonomous/team-mode work;
every open design question was decided and logged there rather than left for the human.

**Scoping call, made before writing a single AC (D-2):** this spec covers one slice of FP-230 —
categories, brands, units/conversions/`UnitResolver`/the snapshot contract, products (including
variants and the stock/purchase/sales unit split, weighable flags and PLU as columns), exact-match
product barcodes, product-to-location and product-to-branch settings, product components (bundles/
kits/composites/recipes), and modifiers. **`barcode_rules` and its dual-engine (PHP + TypeScript)
parser are filed as a new backlog row, FP-423**, and are out of scope here — see D-2 for the full
reasoning and Out of scope below.

## Problem / goal

FlexiPOS has no product catalog at all today: no `products`, `categories`, `brands`, `units` table,
no `UnitResolver`, nothing a till or an admin screen could sell against. `docs/architecture.md` §3
and `docs/database.md` §6.3 already fully design this (the base/purchase/sales unit split, the
conversion ladder, variants as a self-referential `products` row, weighable goods, barcode exact-match
plus rules-based fallback, modifiers) — T-006 settled the unit-model design question the owner raised
("GRN unit is not the selling unit") by writing it into those two documents, but **shipped no code at
all** (D-1). This task is the first one to build any of it: the schema, the `UnitResolver` contract,
and CRUD for the entities a shop needs before it can sell a single item.

This is also the first task in a new feature phase. Every task before it (T-001 to T-026) built
platform/infrastructure — tenancy, auth, the module host, the plugin framework, the workflow engine,
tenant provisioning. T-027 is the first business-domain feature: the thing the till and the admin app
will actually read from. Confirmed with the human before starting, given the scale (backlog row).

## Users affected

- **A newly provisioned tenant's owner/admin staff**, who today have no way to describe what their
  shop sells. This task gives them categories, brands and products to set up before the till can be
  used at all.
- **Every later Phase 1/2 task that reads catalog data**: pricing/discounts (FP-231), sales (FP-233),
  stock validation (FP-234), the till UI (FP-236), offline sync (FP-237), stock levels (FP-250),
  purchasing (FP-254) — each of those backlog rows already names "widened by T-006" and depends on
  the unit split, the resolver, and the weighable/PLU columns this task builds existing and correct
  before they can be built on top of it.
- **`UnitResolver`'s future callers** (every module above): this task is the one and only place the
  conversion ladder is implemented — `docs/database.md` §6.3's "one ladder, one caller" rule means no
  later module may re-implement or special-case it.
- Not served by this task: anyone needing taxes, price lists, discounts (FP-231), suppliers/purchase
  documents (FP-254), stock levels/ledger (FP-250), kitchen stations/printing (FP-239), or chart of
  accounts (FP-260) — each depends on a table this task does not build, see D-3/D-4 and Out of scope.

## Current behaviour

Re-derived from the files, not assumed:

- No catalog table exists anywhere in the shipped tree. Measured: no migration under
  `modules/host/database/migrations-tenant/` creates `products`, `categories`, `brands`, `units`,
  `unit_conversions`, `product_barcodes`, `product_locations`, `product_branches`,
  `product_components`, `modifier_groups`, `modifiers`, or `product_modifier_groups`.
- **T-006 is DONE but shipped no code** (D-1): its own spec states "the deliverable is two Markdown
  documents, not code... There is no migration, no model, no test and no schema in this task."
  `docs/database.md` §6.3 and §16 (Q-9 to Q-12), and `docs/decisions/T-006-unit-of-measure.md` (owner
  decisions U-1 to U-3, design decisions D-57 to D-65) are the settled design this task implements for
  the first time — confirmed by search: no `UnitResolver` class or anything matching "Unit" as a
  domain concept exists under `packages/*/src` today.
- **Tables this task's own columns reference already exist**, shipped by prior tasks: `branches` and
  `locations` (T-014, `modules/host/database/migrations-tenant/2026_10_22_000501..502_*.php`),
  `employees` (T-010/T-014, for `created_by`/`updated_by`/`manager_employee_id`-style references where
  this task's tables carry `[BY]`).
- **Tables six documented columns reference do not exist**, measured directly (no migration creates
  any of them): `tax_groups` (FP-231, TODO), `accounts` (FP-260, TODO), and whatever table would own a
  kitchen station (FP-239, TODO). This affects `products.tax_group_id`, `products.
  purchase_tax_group_id`, `products.income_account_id`, `products.cogs_account_id`, `products.
  inventory_account_id`, `categories.income_account_id`, and `categories.kitchen_station_id`/
  `products.kitchen_station_id` — none of the six/seven are built by this task (D-3).
- **`suppliers` does not exist** (FP-254, TODO, Purchasing) — `product_suppliers`' only reason to
  exist is linking to it, so this task does not build `product_suppliers` either (D-4).
- `custom_field_definitions` and `settings_registry` (T-011/T-014) already exist, so the `[CF]` tag on
  `products`/`categories`/`brands` has somewhere to validate against, unchanged by this task.
- No module in this codebase declares a published "Catalog" contract today (measured: no
  `packages/contracts/src/Catalog/` directory exists). This task adds it.

## User stories

- As a shop admin, I want to organise products into categories and brands, so that staff can find what
  they are looking for and reports can roll figures up by category.
- As a shop admin, I want to define the units my shop buys and sells in — a case, a box, a kilogram, a
  gram — and tell the system how they convert, so that receiving a carton of 24 and selling singles
  keeps stock and cost correct without my typing the math on every document.
- As a shop admin in a trade where the receiving unit differs from the selling unit (a carton of soap
  received, singles sold; a sack of rice received, kilograms weighed out), I want the system to convert
  correctly and refuse a conversion that makes no sense (kilograms to litres), so that a wrong setup
  does not silently falsify my stock.
- As a shop admin, I want to create a parent product with variants (small/medium/large, or three grades
  of the same grain), so that one product definition doesn't need to be duplicated three times with no
  relationship between the copies.
- As a shop admin selling a weighed good (produce, meat, bulk grain), I want to flag a product as
  weighable and give it a scale PLU, so that it can later be sold by weight from a scale label.
- As a restaurant or deli admin, I want to define modifier groups (size, extras, cooking preference)
  with their own price and stock effects, so that a cashier can customise an order item correctly.
- As a developer of the next Phase 1/2 task (pricing, sales, stock, till, offline sync, purchasing), I
  want one place that resolves a unit conversion correctly, so that I never have to decide for myself
  whether a case means 12 or 24.

## Acceptance criteria

### A. Categories

- **AC-1**: Creating a category requires `code` (unique, non-blank) and `name`; `parent_id` is
  optional. A blank or duplicate `code` is refused with a validation message naming the field, before
  any row is written.
- **AC-2**: On create, `path` and `depth` are computed, never supplied by the caller: a root category
  (`parent_id` null) has `depth = 0` and `path` equal to its own id; a child's `depth` is its parent's
  `depth + 1` and its `path` is the parent's `path` plus its own id. **Negative case**: a `parent_id`
  that does not exist, or that names a soft-deleted category, is refused.
- **AC-3**: Moving a category (updating `parent_id`) recomputes `path`/`depth` for that category **and
  every descendant** in the same transaction — a materialised path that goes stale the moment anything
  moves under it is a defect, not a cosmetic gap, since `docs/database.md` names it a reporting
  roll-up's source of truth.
- **AC-4** (negative/edge case): A category cannot be set as its own ancestor — moving category A to be
  a child of one of A's own descendants is refused, never silently creating a cycle a materialised path
  cannot represent.
- **AC-5**: Soft-deleting a category (`[SOFT]`) that still has active (non-deleted) child categories or
  active products referencing it as `category_id` is refused, naming the blocking reference; soft-
  deleting a category with none succeeds and the row is excluded from normal listings but remains
  readable by id.
- **AC-6** (D-3, scope boundary): `categories.income_account_id` and `categories.kitchen_station_id`
  are **not** built by this task — `accounts` (FP-260) and a kitchen-station table (FP-239) do not
  exist. Each is added by the task that ships its owning table, as an ordinary expand-step migration.

### B. Brands

- **AC-7**: Creating a brand requires `code` (unique, non-blank) and `name` (unique, non-blank); a
  blank or duplicate value in either refuses with a validation message naming the field. **Negative
  case**: two brands may not share a name even with different codes, and vice versa.
- **AC-8**: Soft-deleting a brand that is referenced by any active product's `brand_id` is refused,
  naming the blocking reference; a brand with none soft-deletes normally.

### C. Units and conversions

- **AC-9** (the ladder's output guard, Q-9/D-5): A published `UnitResolver` contract exposes
  `resolve(product, fromUnit): factor` (exact method/interface name is `architect`'s/`dev`'s to choose,
  D-11), implementing the five-step ladder of `docs/database.md` §6.3 in order — (1) product-scoped
  `unit_conversions` row, (2) global (`product_id IS NULL`) `unit_conversions` row, (3) the `units.
  base_unit_id`/`factor` chain, (4) `1` when `fromUnit` is the product's own `stock_unit_id`, (5) reject
  with a named error, never a silent `1`. **Every resolved factor is validated ≥ 1** before being
  returned (Q-9's adopted proposal, D-5); a chain that would resolve to a factor below 1 is rejected
  with a named error rather than returned, even though step 3 reads master data with no product-
  specific validation possible on `unit_conversions` itself (the exact gap `docs/database.md` §6.3
  names: "no validation on `unit_conversions` could ever see it").
- **AC-10**: Resolution refuses when `fromUnit` and the product's `stock_unit_id` do not share
  `units.dimension` (cross-dimension conversion), at every ladder step — including a product-scoped or
  global `unit_conversions` row that itself crosses dimensions, which is refused at write time (AC-13),
  not only at resolution time.
- **AC-11**: Creating a `units` row requires `code` (unique), `name`, and `dimension` (one of `count`,
  `mass`, `volume`, `length`, `area`); `precision` defaults within `0`–`4` and a value outside that
  range is refused (the column's own `CHECK`). `base_unit_id`/`factor` are both optional but must be
  supplied together — a unit with one and not the other is refused. **Negative case**: `base_unit_id`
  naming a unit of a different `dimension` is refused (gram cannot chain under a litre); a non-positive
  `factor` is refused; a `base_unit_id` chain containing a cycle (unit A's base is B, B's base is A, or
  a longer loop) is refused.
- **AC-12** (Q-11/D-5): A unit with `precision = 0` causes any computed `base_quantity` that is not a
  whole number to be **rejected**, not rounded — the adopted proposal for "is a fractional base
  quantity ever legal when the base unit is `each`." This is proved as a property of the snapshot
  computation (AC-16), since no document-line table exists yet to carry a real quantity.
- **AC-13**: Creating a `unit_conversions` row requires `from_unit_id`, `to_unit_id` (both existing
  `units` rows, and distinct from each other — a self-conversion is refused), and a positive `factor`;
  `product_id` is optional (null = global). Both units must share `dimension`, refused otherwise
  (mirrors AC-10, enforced here at the data-entry boundary rather than only at resolution). The unique
  key is `(product_key, from_unit_id, to_unit_id)` with `product_key` the §3.3 sentinel
  (`COALESCE(product_id, '-')`), so exactly one global rule and exactly one per-product rule may exist
  for any ordered unit pair. **Negative case**: a second global row for the same `(from_unit_id,
  to_unit_id)` pair is refused as a duplicate, naming the existing row.
- **AC-14**: A `unit_conversions` row naming a non-null `product_id` that does not exist, or that names
  a soft-deleted product, is refused.
- **AC-15** (negative/edge case, ladder exhaustion): Given a product and a `fromUnit` with no
  product-scoped row, no global row, no base-unit chain reaching the stock unit, and `fromUnit` is not
  the stock unit itself, `UnitResolver::resolve()` rejects with a named "no conversion path" error —
  never a default of `1`.
- **AC-16** (the snapshot contract — FP-230's own "and its snapshot contract" line): A published value
  object/DTO (exact name `architect`'s/`dev`'s to choose) captures the `[UOM]` quad `docs/database.md`
  §3.1 defines for any future document line: `unit_id`, `quantity`, `unit_factor` (the resolved factor,
  snapshotted at construction, never re-resolved later), and `base_quantity` computed as `ROUND(quantity
  × unit_factor, 4)`. Construction enforces the fixed rounding order §3.2 requires: the entered
  `quantity` is rounded to the **unit's own** `precision` first, and `base_quantity` is computed from
  that already-rounded value — never the reverse order. **Negative case**: constructing the snapshot
  with a `unit_factor` the caller supplies directly (bypassing `UnitResolver`) is not this object's
  concern to prevent — callers are expected to obtain `unit_factor` only from `UnitResolver::resolve()`,
  stated in the contract's own documentation, the same trust boundary `docs/database.md` already places
  on every document-line writer.
- **AC-17** (out-of-scope boundary, stated for the reader, mirroring T-026's "scope boundary" ACs): This
  task does not build any table that *carries* the `[UOM]` quad on a real document line (`sale_items`,
  `grn_items`, and the other fifteen tables §3.1 names) — those belong to Sales, Purchasing, Inventory
  and the other owning tasks, each of which is expected to use `UnitResolver` and the snapshot contract
  this task ships rather than re-implement the ladder (D-10 — a TypeScript twin for the offline till is
  explicitly FP-237's job, not built here).

### D. Products — identity, type and variants

- **AC-18**: Creating a product requires `sku` (unique, non-blank, ≤ 50 characters), `name`,
  `category_id` (existing, non-soft-deleted), `stock_unit_id` (existing `units` row), and `type` (one
  of `simple`, `variant_parent`, `variant`, `service`, `bundle`, `composite`). `brand_id`,
  `purchase_unit_id`, `sales_unit_id` are optional. A blank/duplicate `sku`, or a missing/invalid
  required field, is refused before any row is written, naming the field.
- **AC-19** (D-7, the variant relationship — reading the existing enum/self-FK design literally, not a
  new one): A `variant`-type product's `parent_id` must reference an existing product of type
  **`variant_parent`** — referencing a `simple`, `service`, `bundle`, `composite`, or another `variant`
  row is refused, naming the required type. A `variant_parent`-type product's own `parent_id` must be
  null — a `variant_parent` nested under anything is refused. Every type other than `variant` must have
  `parent_id` null (only a `variant` row is ever a child).
- **AC-20** (negative/edge case): Soft-deleting a `variant_parent` that still has active (non-deleted)
  `variant` children is refused, naming the blocking children; soft-deleting a childless
  `variant_parent`, or any `variant`, `simple`, `service`, `bundle`, or `composite` row, succeeds
  normally (subject to AC-22/AC-24's own reference checks).
- **AC-21**: `variant_options`/`variant_values` (JSON) are stored and returned as supplied with no
  server-side schema validation beyond "valid JSON" — this task does not invent a controlled vocabulary
  for variant attributes (size/colour/grade), since none is documented.

### E. Products — the unit split, weighable goods and PLU

- **AC-22**: `stock_unit_id` is the base unit (`docs/database.md` §6.3, "the rename is the fix") and is
  **immutable from the product's first save onward** — no update path changes it, ever (D-6: this is
  the narrower, always-provable half of Q-10's "immutable once stock has moved"; the full rule needs a
  real stock ledger this task does not build). **Negative case**: an update request that includes a
  different `stock_unit_id` than the row currently holds is refused entirely (the whole update, not a
  silent partial application), naming the immutable field.
- **AC-23**: `purchase_unit_id` and `sales_unit_id`, when supplied, must reference an existing `units`
  row of the **same `dimension`** as `stock_unit_id` — refused otherwise (D-9: checked for dimension
  compatibility only, not full resolvability through `UnitResolver`, since a conversion row is normally
  set up after the product exists). Either may be left null; a null value is a valid, meaningful state
  ("defaults to the stock unit"), not a gap to fill in later.
- **AC-24**: `plu_code`, when supplied, is at most 10 characters and unique across all products
  (including soft-deleted ones, mirroring the column's own `unique` with no partial-index escape on
  this engine); a blank string is treated as null (no PLU), never as the empty-string value colliding
  with itself across products.
- **AC-25**: `is_weighable` (bool), `price_mode` (one of `per_unit`, `per_weight`, `open_price`), and
  `plu_code` are each validated independently; **no cross-column coupling is enforced** (D-8) — a
  weighable product may be `per_unit`, `open_price`, or `per_weight`, and may or may not carry a
  `plu_code`. This is a stated design boundary, not a gap: the interaction with how a barcode scan
  resolves a weighable item is FP-423's concern (`barcode_rules`), not catalog data entry's.
- **AC-26**: `track_stock` (bool) and `tracking_mode` (one of `none`, `quantity`, `batch`, `serial`) are
  independent columns, validated for allowed values only; this task does not enforce `tracking_mode =
  none` when `track_stock = false` or any other coupling between them, since none is documented and
  Inventory (FP-250 onward) is better placed to state what each combination means operationally.
- **AC-27** (D-3, scope boundary): `products.tax_group_id`, `purchase_tax_group_id`,
  `income_account_id`, `cogs_account_id`, `inventory_account_id`, and `kitchen_station_id` are **not**
  built by this task — `tax_groups` (FP-231), `accounts` (FP-260), and a kitchen-station table (FP-239)
  do not exist. Each is added by the task that ships its owning table.

### F. Product barcodes (exact-match only)

- **AC-28**: Creating a `product_barcodes` row requires `product_id` (existing) and `barcode`
  (non-blank, ≤ 64 characters, unique across the whole table); `unit_id` is optional (null means "this
  barcode scans as the product's own stock unit," per `docs/database.md` §3.1's nullable-`unit_id`
  table) and `quantity` defaults to `1` when not supplied. **Negative case**: a duplicate `barcode`
  value (even for a different product) is refused, naming the existing product it already belongs to.
- **AC-29**: `unit_id`, when supplied, is not required to share `stock_unit_id`'s dimension at this
  layer — a barcode's `unit_id` plus `quantity` is a pack-size statement interpreted through
  `UnitResolver` at scan time (FP-423's concern), and this table only stores the two values, validated
  for existence and format.
- **AC-30**: At most one `product_barcodes` row per product has `is_primary = true`; setting a second
  row's `is_primary` to `true` for the same product automatically clears the previous primary flag in
  the same transaction, never leaving two.
- **AC-31** (out-of-scope boundary, stated for the reader): This task builds exact-match lookup data
  only — insert/update/delete/list `product_barcodes` rows and the unique index that serves "~99% of
  scans" per `docs/database.md` §6.3. The `barcode_rules` table, its priority-ordered in-memory
  matching, and the embedded-weight/embedded-price parsing logic are **not** built here — FP-423.

### G. Product locations, branches and components

- **AC-32**: Creating a `product_locations` row requires an existing `product_id` and `location_id`
  pair (unique together, `PK(product_id, location_id)`); `reorder_level`, `reorder_qty`, `max_level`
  default to `0` when not supplied and are stored as `DECIMAL(18,4)` in the product's stock unit, never
  re-interpreted against any other unit. A duplicate `(product_id, location_id)` pair is refused as
  already existing rather than creating a second row.
- **AC-33**: Creating a `product_branches` row requires an existing `product_id` and `branch_id` pair
  (unique together); `selling_price`, when supplied, overrides `products.selling_price` for that branch
  only and is otherwise left null (inherits the product-level price) — this task stores the override,
  it does not implement the pricing ladder that reads it (FP-231's concern).
- **AC-34**: Creating a `product_components` row requires `parent_product_id` and `component_product_id`
  (both existing products, and distinct from each other — a product cannot be its own component,
  refused), `unit_id`, and `quantity`; `unit_factor`/`base_quantity` are computed through `UnitResolver`
  and **snapshotted at write time** (D-62, `docs/decisions/T-006-unit-of-measure.md`) — a later change
  to the unit's conversion factor never silently re-scales an already-written recipe row. **Negative
  case**: a `(parent_product_id, component_product_id)` pair that already exists is refused as a
  duplicate rather than creating a second line (the unique key `docs/database.md` names).
- **AC-35** (out-of-scope boundary): `product_suppliers` is **not** built by this task — `suppliers`
  (FP-254) does not exist (D-4).

### H. Modifiers

- **AC-36**: Creating a `modifier_groups` row requires `name`; `min_select`/`max_select` default to `0`
  and unlimited respectively when not supplied, and `min_select > max_select` (both supplied and
  non-default) is refused as an impossible range.
- **AC-37**: Creating a `modifiers` row requires `group_id` (existing) and `name`; `price_delta`/
  `cost_delta` default to `0`. **When `linked_product_id` is supplied, `linked_quantity` and
  `linked_unit_id` are both required** (`docs/database.md` §6.3: "depletes stock' with no quantity and
  no unit was unimplementable") — refused if either is missing while `linked_product_id` is set. When
  `linked_product_id` is null, `linked_quantity`/`linked_unit_id` must also be null (no depletion
  target, no depletion amount).
- **AC-38** (negative/edge case): `linked_product_id`, when supplied, must reference an existing,
  non-soft-deleted product; `linked_unit_id` must reference an existing `units` row, with no dimension
  check against the linked product enforced here (mirrors AC-29's reasoning — the depletion arithmetic
  belongs to whichever module actually depletes stock, not yet built).
- **AC-39**: `product_modifier_groups` is a simple join (`PK(product_id, group_id)`); creating a
  duplicate pair is refused rather than creating a second row. Both `product_id` and `group_id` must
  reference existing, non-soft-deleted rows.
- **AC-40** (out-of-scope boundary, stated for the reader, mirroring T-020/T-026's "no modules exist
  yet" framing): The actual stock-depletion posting rule for a modifier's `linked_product_id` is
  "deliberately unspecified" by `docs/database.md` itself and belongs to the restaurant workstream —
  this task stores the three columns correctly and enforces the all-or-nothing rule of AC-37, nothing
  about what later reads them to deplete stock.

### I. Framework integrity and the gate

- **AC-41**: `composer lint`, `composer stan` and `composer deptrac` pass with no new suppressions.
  `UnitResolver` and the snapshot contract are published from a new `packages/contracts/src/Catalog/`
  directory (or the equivalent `architect`/`dev` choose, D-11) and reached from outside the owning
  module only through that published contract, never the concrete implementation's internals — the
  same boundary rule every prior module-owning task follows.
- **AC-42**: `composer test:fast` and `composer test` both pass, proving every criterion above. No
  criterion in this spec is marked `[ENGINE]` — every table, constraint and computation this task adds
  is provable against the stand-in or by static/structural reading, matching how T-008/T-009's engine
  fencing worked for pure-schema, non-connection-opening work.
- **AC-43**: A static test asserts every `[UOM]`-tagged column set this task adds (`product_components`'
  full quad; `product_barcodes`' `unit_id`/`quantity` pair; `modifiers`' `linked_unit_id`/
  `linked_quantity` pair) matches §3.1's degenerate-tag table exactly — no extra or missing column —
  mirroring the static-assertion idiom T-006/T-008/T-014 already use for schema invariants.
- **AC-44**: A static test asserts no tenant migration this task adds declares a bare `decimal()` with
  no precision (§3.2's "a bare `DECIMAL` is a bug, not a shorthand"), and that every `DECIMAL` column
  this task adds matches its documented precision (`DECIMAL(18,4)` for quantities, `DECIMAL(18,6)` for
  `units.factor`/`unit_conversions.factor`, `DECIMAL(9,4)` for percentages where used).

## Out of scope

- `barcode_rules` and its PHP + TypeScript parser (scale-label resolution order, `embedded_weight`/
  `embedded_price`/`plu`/`gs1_128`/`isbn`/`internal` kinds, check-digit schemes) — filed as a new
  backlog row, **FP-423** (D-2). `product_barcodes`' exact-match data is built here; the fallback
  parser is not.
- `product_suppliers` — `suppliers` does not exist (FP-254, Purchasing) — D-4.
- `tax_group_id`/`purchase_tax_group_id` on `products` — `tax_groups` does not exist (FP-231) — D-3.
- `income_account_id`/`cogs_account_id`/`inventory_account_id` on `products`, `income_account_id` on
  `categories` — `accounts` does not exist (FP-260, Finance) — D-3.
- `kitchen_station_id` on `products`/`categories` — no kitchen-station table exists (FP-239,
  Printing/Restaurant) — D-3.
- A TypeScript implementation of `UnitResolver` or the snapshot contract, for the offline till — FP-237
  (D-10).
- Live-weight scale hardware integration — already deferred to Phase 6 by the owner (U-2,
  `docs/decisions/T-006-unit-of-measure.md`), unaffected by this task.
- The pricing ladder that reads `product_branches.selling_price`/`price_list_items` — FP-231.
- The posting rule for a modifier's stock depletion — "deliberately unspecified," restaurant
  workstream, per `docs/database.md` itself (AC-40).
- Any HTTP/admin-UI surface for catalog management — this task ships the domain layer (migrations,
  models, handlers/contracts) that a future admin-UI task (FP-240) and API layer read; no routes are
  added here, matching how prior non-HTTP-first tasks in this backlog (T-020, T-026) scoped themselves.
- Relaxing `stock_unit_id` immutability once a real movement exists — needs Inventory (FP-250 onward)
  to exist first (D-6).

## Non-functional requirements

- **Correctness over convenience in unit arithmetic**: `UnitResolver` never returns a default or
  best-guess factor; every unresolvable or cross-dimension case is a named refusal (AC-9/AC-10/AC-15),
  because a silent wrong factor is a stock and cost error that compounds invisibly, exactly the failure
  mode `docs/database.md` §6.3 names as the reason this design exists.
- **One ladder, one caller**: `UnitResolver` is the only code in the tenant application that converts a
  quantity between units for a product. No handler in this task re-implements any step of the ladder
  inline.
- **Isolation from other tenants**: nothing this task adds reads or writes across tenant databases —
  every table and handler operates on the single tenant connection, mirroring T-022's permanent
  cross-tenant sweep (no new route, queued job, cache key, or plugin hook is introduced that would need
  a new sweep entry).
- **Forward compatibility of deferred columns**: every column/table deferred by D-3/D-4 is deferred as
  a clean expand step (a later migration adds the column/table once its target exists), never as a
  placeholder column with no FK that would need to be dropped and re-added.

## Assumptions

- Q-9, Q-10 (narrowed per D-6), Q-11, and Q-12's document-stated proposals are adopted as this task's
  working rules, unchanged, pending the owner's own confirmation (D-5). Overturning any is the owner's
  call, not a defect in this task.
- `variant_options`/`variant_values` carry no server-validated vocabulary (AC-21) — a future admin-UI
  or import task may add one without this task's schema changing.
- `is_weighable`/`price_mode`/`plu_code` carry no cross-column coupling (D-8/AC-25); `track_stock`/
  `tracking_mode` likewise (AC-26). Both are explicit boundaries, not oversights.
- `purchase_unit_id`/`sales_unit_id` are validated for dimension compatibility only, not full
  resolvability, at product save time (D-9/AC-23).

## Open questions

None — every design question this task raised was decided and logged in
`docs/decisions/T-027-catalog.md` (`ba`'s own calls), per the team's autonomous-mode protocol. The
four genuinely owner-level questions (Q-9 to Q-12) remain open in `docs/database.md` §16 exactly as
T-006 left them; this task adopts their stated proposals as working assumptions (D-5) rather than
blocking on them, and nothing here forecloses the owner answering differently later.
