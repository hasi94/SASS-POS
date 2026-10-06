# T-006: The unit-of-measure model (documents only)

Covers backlog row **FP-243** (the unit model: base unit, conversion boundary, valuation formula),
with the pre-existing defects folded in: **FP-158** (two defects in `docs/database.md` §4.4) and
`docs/architecture.md`'s "roughly 150 tables per tenant". Records the downstream rows widened by
this task (FP-230, 231, 233, 234, 236, 237, 250, 253, 254, 255) and the two new rows FP-257 and
FP-258. Owner decisions U-1 to U-3 and design decisions D-57 to D-65 are in
`docs/decisions/T-006-unit-of-measure.md`; D-66 onward were added by `ba` while writing this spec.

## Provenance: this spec was written after the work

**This specification was written after the implementation, not before it.** `architect` took
`docs/database.md` and `docs/architecture.md` from v0.4 to v0.5 in eleven commits on branch
`ai/T-006-unit-of-measure` (from the T-005 tip, `47e226d`) before any spec existed. The reason is a
finding this task recorded rather than a convenience: the plan was approved outside the repository,
so the team could not see it. A direction that exists only in a plan file is not a decision the team
can act on (backlog, "The CQRS direction is not recorded anywhere in this repository").

Consequences for how to read the criteria:

- The deliverable is **two Markdown documents**, not code. There is no migration, no model, no test
  and no schema in this task. "Implemented" means "the document says it, in the named section".
- Each criterion is a contract for `qa` and `reviewer` to check against the finished documents. It
  does not describe work still to be done, and it proposes none. Where a criterion fails against
  the documents as they stand, that is a **defect** in the documents, and this spec says so (see
  "Findings at the time of writing").
- Because the work came first, a criterion could be fitted to what the text says. To guard against
  that, a section of **Likely-wrong criteria** (AC-48 to AC-58) tests claims a document can make
  without having done the thing: ERD against tables, arithmetic, cross-references, internal
  contradictions.
- `ba` read the current text of both documents and the backlog. `ba` had no shell in this session
  and could not run `git log` or `git diff` against `47e226d`; any criterion that depends on what
  *changed* (as opposed to what the text *now says*) is marked "diff" and is for `qa` to run.

## Problem / goal

The product owner asked on 2026-10-06: *"in POS we more consider about GRN and Selling Part /
different industry use Diff units GRN and Selling Different UNIT / did you consider that"*. The v0.4
design had considered it only partly: a per-product conversion table, a purchase unit beside the
product's other unit, a pack quantity on the barcode. Only four line tables carried a unit. **No
sentence anywhere said what unit any other quantity was in** (ledger, levels, batches,
reservations, transfers, adjustments, counts, returns, supplier invoices, price history,
quotations, sales orders, restaurant order items).

Goal: make the unit of measure the quantity twin of the document-currency rule the design already
states for money, written into the design documents before any code exists, because the same
change after launch costs a column sweep, an index rebuild on an append-only ledger and a resync of
every terminal in the field, once per tenant database.

## Users affected

- `architect` and `dev` of FP-230 and FP-250 to FP-257: the unit model is the contract they build.
- `qa` and `reviewer` of this task: verify documents against numbered criteria.
- The lead: owns the backlog status and `CLAUDE.md`, which cites the document versions.
- The product owner: owns the four open questions Q-9 to Q-12 and settled U-1 to U-3.
- Eventually shop owners and cashiers in pharmacy, grocery, hardware and restaurant trades, who
  receive in one unit and sell in another.

## Current behaviour

(After the task, which is the state `qa` verifies. Pre-task state is in "Problem / goal".)

- `docs/database.md` is **DRAFT v0.5**, `docs/architecture.md` is **v0.5**, both dated 2026-10-06,
  each with a change-log entry naming FP-243.
- The decisions file holds Part 1 (U-1 to U-3, owner) and Part 2 (D-57 to D-65, architect).
- `docs/backlog.md` lists T-006 in the active build table as `IN_DEV`, FP-243 as `IN_DEV (T-006)`,
  FP-257 and FP-258 as new rows, and ten rows carrying a "widened by T-006" note.

## User stories

- As a shop owner, I want to receive goods in one unit and sell them in another (a carton in, a
  strip or a tablet out), so that my stock and my cost are right whichever unit a clerk types.
- As a shop owner, I want a case to be priced at what I choose, not only at a multiple of the
  single, so that a case discount, or a pharmacy's dearer loose tablet, is possible.
- As a grocer, I want weighed goods scanned from a scale label to sell correctly, so that the
  weight on the label becomes the quantity on the sale.
- As a butcher or packer, I want to record a repack or break-bulk as a document, so that value in
  equals value out and the stock of both products moves.
- As an accountant, I want inventory valued from a written, base-unit formula, so that a receipt of
  24 singles in a case cannot overstate inventory 24 times.
- As a developer, I want one place that says what unit every stored quantity is in, so that I do not
  add a unit column and a conversion to every write path.
- As `qa`, I want each rule stated once with its section, so that I can find a contradiction.
- As the product owner, I want the decisions that are mine kept open, so that nothing is decided
  for me by being written down.

## Acceptance criteria

Numbering starts at AC-1 for T-006. "§" without a file means `docs/database.md`; `arch §n` means
`docs/architecture.md`. Every criterion names where to read or what to run.

### Versions, change log and provenance

- **AC-1**: Given `docs/database.md`, when its header table is read, then Status says
  **DRAFT v0.5**, names `architecture.md` v0.5 as its companion, and the Date is 2026-10-06.
- **AC-2**: Given `docs/database.md`, when the header Change log row is read, then it begins with a
  **v0.5** entry naming FP-243 and mentioning each of: the `stock_unit_id` rename, `sales_unit_id`,
  the `[UOM]` column set (§3.1), conversion at the document line with a snapshotted factor,
  the idempotency-key rebuild, prices carrying a unit (U-1), weighable goods and `barcode_rules`
  (U-2), `stock_productions` (U-3) and the retirement of `production_in` / `production_out` as dead
  schema, the formula, the price-variance basis, the three nightly invariants, and explicit
  `DECIMAL` precision; and the **v0.4** entry is still present after it.
- **AC-3**: Given `docs/architecture.md`, when its Status and Change log rows are read, then Status
  says **v0.5**, the Change log opens with a **v0.5** entry naming FP-243 and stating the table
  count change ≈190 → ≈193, and the v0.4 entry follows it unaltered in substance.
- **AC-4** (diff): Given `git diff 47e226d..HEAD --stat`, when run, then every changed path is under
  `docs/` (this task changed no code, no test, no `CLAUDE.md`, nothing under `Refference/`), and
  `qa` records the list. Files expected: `docs/database.md`, `docs/architecture.md`,
  `docs/backlog.md`, `docs/decisions/T-006-unit-of-measure.md`, `docs/specs/T-006-unit-of-measure.md`,
  `docs/plans/T-006-unit-of-measure.md` (when it lands).
- **AC-5**: Given this spec's "Provenance" section, then it states that it was written after the
  implementation and gives the reason (the plan was approved outside the repository). Given
  `docs/backlog.md`, then the "not recorded anywhere in this repository" finding is present.
- **AC-6**: Given `docs/backlog.md`, when the active build table is read, then it has a T-006 row
  with status `IN_DEV`, and rows FP-243, FP-257 and FP-258 exist; FP-230, 231, 233, 234, 236, 237,
  250, 253, 254 and 255 each contain the words "widened by T-006" (ten rows exactly).
  Status values are the lead's; `qa` checks presence, not the status choice.

### The owner's decisions are on record in two places

- **AC-7** (U-1): Given `docs/decisions/T-006-unit-of-measure.md` Part 1 and §6.4 of the database
  document, then both state that prices carry their own unit; `price_list_items` has a `unit_id`
  column whose `NULL` means "priced per stock unit, derive by factor"; the unique key is
  `(price_list_id, product_id, unit_id, min_qty)`; and §6.4 gives the case-discount, pharmacy-inversion,
  rounding (24 × 7.50 = 180 against a case at 179) and replicated-unique-key reasons.
- **AC-8** (U-1, ladder): Given §6.4 "The six steps keep their shape", then each of the first four
  price steps is tried first with `unit_id = :line_unit`, then with `unit_id IS NULL`, first hit
  wins; and the last two steps (`product_branches.selling_price`, `products.selling_price`) are
  stated to be per stock unit and to always derive. The same section states that `products.selling_price`,
  `min_selling_price`, `msrp`, `cost_price` and `product_branches.selling_price` are all per stock
  unit; the `products` row in §6.3 says "all four per stock unit" for the first four of those.
- **AC-9** (U-1, `min_qty`): Given §6.4, then it states `min_qty` is a volume break on **base**
  quantity and explains why it cannot replace the unit (24 loose singles must not get the case
  price).
- **AC-10** (U-2): Given §6.3 "Weighable goods", then `products.type` is stated **not** extended;
  weighability is `is_weighable`, `price_mode enum(per_unit, per_weight, open_price)` and
  `plu_code`; and the §6.3 `products` row declares those three columns. **Live-weight scale
  hardware is deferred to Phase 6** and the backlog carries it as FP-258 "deferred here
  deliberately".
- **AC-11** (U-3): Given §6.5 "Repack and break-bulk" and the `stock_productions` /
  `stock_production_items` rows, then repack is a document (not a pair of offsetting adjustments),
  and §15 states `production_in` / `production_out` are "no longer dead schema" because the new
  tables are their `reference_type`.

### The base unit (§6.3)

- **AC-12**: Given §6.3 "The base unit" and the `products` row, then the column is named
  **`stock_unit_id`**, FK `units`, `NOT NULL`, declared the base ("every stored quantity for this
  product is in this unit"); and the string `products.unit_id` does not appear in `docs/database.md`
  or `docs/architecture.md` except where describing the former name (`qa` greps `products\.unit_id`
  and reads each hit).
- **AC-13**: Given the same section, then `purchase_unit_id` and `sales_unit_id` are both nullable,
  symmetric, and each stated to be a **UI default for a document line, never an authority**; a goods
  receipt defaults to the purchase unit, a till line to the sales unit, either changeable on the line.
- **AC-14**: Given the same section, then `stock_unit_id` is **immutable once a movement exists**,
  refused by the service layer, with no restatement document, and "changing the stock unit of a
  product with history is a new product". Given Q-10 (§16), then this is *also* recorded as open
  for the owner (AC-63). The two must not contradict: the text states the working rule, the question
  asks the owner to confirm it.
- **AC-15**: Given the "rename costs nothing today" paragraph, then it states that §9.4's
  add / dual-write / drop rule is **not waived** and says why the rename is still legitimate (no
  migration exists in the repository other than the framework's own). `qa` confirms that claim:
  list the migrations directory and find none that creates a product table (the claim is checkable
  against the tree, not only against the document).

### The conversion boundary (§6.5)

- **AC-16**: Given §6.5 "Units stop at the document line", then it states that conversion happens
  **exactly once, when a document line is written, and nowhere else**, and that **below the document
  layer there are no units**.
- **AC-17**: Given the same section, then it names exactly seven tables that carry no `unit_id`
  column "ever": `stock_movements`, `stock_levels`, `stock_batch_levels`, `batches`,
  `stock_reservations`, `purchase_price_history`, `journal_lines`. And **no row for any of those
  seven in `docs/database.md` contains `unit_id`, `unit_factor` or `unit_code`** (`qa` reads each of
  the seven rows: §6.5 for the first five, §6.6 for `purchase_price_history`, §6.10 for
  `journal_lines`). Where a row mentions a unit in prose (for example "in the product's stock
  unit") that is allowed; a column of that name is not.
- **AC-18**: Given the same section, then it contains the money/quantity parallel table with five
  rows (what the user entered, the rate, the converted value, where the rate came from, what the
  ledger stores), and the quantity column agrees with §7's currency statement: `qa` reads §7 and
  confirms the sentence about document currency and `base_debit` / `base_credit` exists there and says
  what this table says it says.
- **AC-19**: Given §14 "Boundary enforcement", then a static test is specified that asserts the
  **absence** of `unit_id`, `unit_factor` and `unit_code` on the seven tables named in AC-17, and the
  list of seven in §14 equals the list in §6.5.
- **AC-20**: Given §6.5 "The factor is snapshotted, not resolved", then each line stores the
  `unit_factor` it used; re-sizing a case cannot retro-change a past receipt, its landed cost or the
  average computed from it; and the text states the snapshot replaces effective-dating
  (`unit_conversions` needs no `effective_from`) and is a precondition for a deterministic read-model
  rebuild.
- **AC-21**: Given §3.1's note on `product_components`, then its factor is snapshotted **when the
  recipe row is written**, not each time it is consumed (D-62). The `product_components` row in
  §6.3 carries `[UOM]` and does not contradict it.

### The `[UOM]` column set (§3.1) and the sixteen tables

- **AC-22**: Given §3.1, then the `[UOM]` row defines `unit_id` (FK `units`, NOT NULL), `quantity`
  `DECIMAL(18,4)` in `unit_id`, `unit_factor` `DECIMAL(18,6)` snapshotted at entry, and
  `base_quantity` `DECIMAL(18,4)` = `ROUND(quantity × unit_factor, 4)`; and says `unit_code VARCHAR(50)`
  is added on **print-bearing** lines.
- **AC-23** (the tag census): Given `docs/database.md`, when `qa` lists every table definition
  carrying the `[UOM]` tag (search `\[UOM\]` and read each row; a row defining two tables, such as
  `stock_adjustments / stock_adjustment_items`, counts the item table), then the set is exactly:
  `product_barcodes`, `product_components`, `modifiers`, `price_list_items`, `stock_adjustment_items`,
  `stock_count_items`, `stock_transfer_items`, `stock_production_items`, `purchase_request_items`,
  `purchase_order_items`, `grn_items`, `purchase_return_items`, `supplier_invoice_items`,
  `quotation_items`, `sales_order_items`, `sale_items`, `order_items`. **That is seventeen.**
  The documents state the number as **sixteen** in four places (`database.md` header change log,
  §6.3 "column sweep across sixteen tables", `architecture.md` change log is silent, backlog T-006
  detail) and as **seventeen** in §3.1. Expected result: the documents state one number, or state
  which of the seventeen is excluded from the sixteen and why. **At the time of writing this
  criterion FAILED** (Finding F-1); resolved in the fix round, true count 17.
- **AC-24** (the three documented exceptions): Given §3.1 and the table rows, then exactly three
  tables have a nullable `unit_id` on the column named `unit_id`, each with its reason stated in
  the document: `price_list_items` (`NULL` = priced per stock unit), `product_barcodes` (`unit_id FK NULL`;
  the barcode carries a pack `quantity` and no unit means the stock unit) and `supplier_invoice_items`
  (nullable only for a non-stock line that has no product, D-61). §3.1 states the first and third
  explicitly; `qa` confirms the second is stated (the row declares `unit_id FK NULL` but §3.1's
  "degenerates on master data" paragraph says only "plus a pack `quantity`").
  A fourth nullable unit reference exists on a differently named column, `modifiers.linked_unit_id`
  and `barcode_rules.unit_id` (the latter is not a `[UOM]` table); `qa` records them so that the
  "three" is not mistaken for "the only nullable unit reference in the schema".
- **AC-25** (the degenerate tags): Given §3.1's "`[UOM]` degenerates on master data", then it says
  `price_list_items` takes `unit_id` only; `product_barcodes` takes `unit_id` plus pack `quantity`;
  `modifiers` takes `linked_unit_id` plus `linked_quantity`; and the `modifiers` row carries both new
  columns (`linked_quantity DECIMAL(18,4) NULL`, `linked_unit_id FK units NULL`) and says the
  depletion posting rule is **deliberately unspecified**.
- **AC-26** (named variations on the quad): Given the three tables whose `quantity` column is
  not literally named `quantity`, then the row says so in words: `stock_count_items.counted_quantity`
  (the `[UOM]` quantity, in `unit_id`; `system_qty`, `difference`, `variance_value` base only);
  `stock_transfer_items` (`qty_sent`/`qty_received` with `base_qty_sent`/`base_qty_received`, one
  `unit_id` and one `unit_factor`, `qty_variance` in base, D-58); `purchase_order_items`
  (`received_quantity` plus `received_base_quantity DECIMAL(18,4)`). `stock_adjustment_items` says
  `quantity` and `base_quantity` are **both signed**.
- **AC-27** (print-bearing): Given `sale_items`, `order_items` and `quotation_items`, then each
  carries `unit_code` (stated in the row), and `sale_items` says it is the snapshot the `[UOM]` tag
  generalises. §3.1 does **not** enumerate the print-bearing set; this spec assumes the three named
  above (D-66) and `qa` records that no other table row mentions `unit_code`.
- **AC-28** (unit cost readings, D-57): Given §6.5 "Valuation, and the formula" and the rows of
  `grn_items`, `purchase_order_items`, `sale_items`, `stock_production_items`, then on a purchase line
  `unit_cost` is per **line unit**, on an outbound cost snapshot (`sale_items.unit_cost`,
  `stock_production_items.unit_cost`) it is per **stock unit** and multiplies by `base_quantity`; both
  readings are written in §6.5, and `sale_items` says `line_cost = base_quantity × unit_cost`.
- **AC-29** (sales order progress, D-59): Given `sales_order_items`, then `delivered_quantity` and
  `invoiced_quantity` are **in base** with no second column, and the row explains the asymmetry with
  `purchase_order_items.received_quantity`.
- **AC-30** (free goods, D-60): Given `grn_items`, then `free_quantity` is in the line's `unit_id`,
  converts with the **same** snapshotted `unit_factor`, the movement is written for
  `base_quantity + ROUND(free_quantity × unit_factor, 4)`, and the row says whether free goods dilute
  `avg_cost` is **not settled by this task**.

### Factor resolution, guards and precision

- **AC-31**: Given §6.3 "Factor resolution: one ladder, one caller", then `UnitResolver` is the only
  code that converts, and the ladder is exactly five steps in this order: product-scoped
  `unit_conversions`; global `unit_conversions`; the `units.base_unit_id` / `units.factor` chain;
  `1` when the line unit is the stock unit; **reject**. The text says there is no sixth step and an
  unresolvable unit is a validation error, never a silent `1`.
- **AC-32**: Given the `units` row in §6.3, then it has `dimension enum(count, mass, volume, length,
  area) NOT NULL`, and the text states cross-dimension conversion (kilogram to litre) is **refused**
  and both units of a `unit_conversions` row must share a `dimension`. The `unit_conversions` row says
  resolution is steps 1 and 2 of the ladder through `UnitResolver`, not a free query.
- **AC-33**: Given §6.3, then "every factor to base is ≥ 1" is stated as a **guard**, labelled an
  assumption the owner is asked to confirm (Q-9), with the reason it matters (integral base
  arithmetic) and what would break it (metres stocked, centimetres sold).
- **AC-34** (one precision authority each): Given §3.2, then stored quantity is `DECIMAL(18,4)`,
  conversion factor `DECIMAL(18,6)`, and `units.precision` is **display and input rounding only,
  never storage**, with `CHECK (precision BETWEEN 0 AND 4)` in the `units` row. The rounding order
  is stated: round the entered `quantity` to `units.precision` **first**, then compute `base_quantity`,
  "identical in the PHP and the TypeScript engine".
- **AC-35** (no bare DECIMAL): Given `docs/database.md`, when `qa` searches `DECIMAL` not followed by
  `(`, then it finds only prose (the change log row and §3.2's explanation of the bug), never a
  column definition; and §14 specifies the static test forbidding `decimal()` without a precision in
  a tenant migration. The documents say "ten occurrences, not the five first thought"; this spec has
  no way to re-derive that number from the current text (the fix is already applied) and `qa`
  recomputes it from `git diff 47e226d..HEAD -- docs/database.md` by counting removed bare-`DECIMAL`
  columns. If the diff shows a different number, record it; the criterion is that **none remain**. **Update after QA run 1:**
  QA counted **11 bare `DECIMAL` occurrences across 9 rows** at the base commit, not ten; the
  documents' "ten" is the figure in doubt, not this criterion.

### The ledger idempotency key (§6.5, §11)

- **AC-36**: Given the `stock_movements` row and §6.5 "The ledger idempotency key", then the key is
  `unique (reference_line_id, type, batch_key, serial_key)`, where `reference_line_id CHAR(26) NOT
  NULL`, and `batch_key` / `serial_key` are `CHAR(26) GENERATED ALWAYS AS (COALESCE(..., '-')) STORED`.
  `(reference_type, reference_id)` remains as a plain non-unique index. Header-level movements
  (`opening`, `correction`) use the header's own ULID as `reference_line_id`.
- **AC-37**: Given the same section, then the two fixed bugs are both stated with their cause: (1) a
  receipt of 2 cases plus 3 loose singles of one product is two lines and collided on the old key;
  (2) a nullable `batch_id` in a unique key means NULLs are distinct, so there was no uniqueness for
  `tracking_mode = quantity` products. Two rejected alternatives (add `unit_id` to the key; a
  `split_no` counter) are recorded.
- **AC-38**: Given §11 "Idempotency uniques" and §14, then §11 lists the new key and not the old
  one `(reference_type, reference_id, product_id, batch_id, type)` as current (it may appear
  as the thing replaced), and §14 says the generated-column mechanism works on MariaDB 10.4 with no
  engine branch. `qa` greps both documents for the old key text and reads each hit as "replaced",
  never "current".

### Valuation, price variance, rollups, incoming (§6.5, §6.6, §7.2)

- **AC-39** (formula): Given §6.5 "Valuation, and the formula", then the formula is written with
  `cost_per_base = line.unit_cost / line.unit_factor` and
  `new_avg = (on_hand × avg_cost + base_qty_in × cost_per_base) / (on_hand + base_qty_in)`, with the
  zero-denominator guard (`new_avg = cost_per_base`). `qa` computes the worked example in the text
  (a case of 24 at 4 800 becomes 200 per single) and confirms 4 800 / 24 = 200 and that the
  stated consequence (inventory overstated by a factor of 24) follows.
- **AC-40** (variance): Given §7.2, then purchase price variance is computed **per base unit on
  both sides** (`line_total / base_quantity` of the invoice line against that of the receipt line)
  and states the 24× fictional-variance failure it prevents; and `supplier_invoice_items` and
  `grn_items` both carry `base_quantity` (via `[UOM]`) so "zero new columns" is true.
- **AC-41** (received rollup): Given `purchase_order_items`, then the `partially_received → received`
  transition compares **base to base** (`received_base_quantity` against the line's `base_quantity`),
  not `received_quantity` against `quantity`.
- **AC-42** (`quantity_incoming`): Given §6.5 "`stock_levels.quantity_incoming`", then it is defined
  as `SUM(base_quantity − received_base_quantity)` over open purchase-order lines for
  `(purchase_orders.location_id, product_id)`, maintained on approve, receive and close; and the
  `stock_levels` row says all three of `quantity_on_hand`, `quantity_reserved`, `quantity_incoming` are
  `DECIMAL(18,4)` in the stock unit.
- **AC-43** (posting rules touched): Given §7.5, then count variances are `base_quantity`-derived,
  and a stock production posts no entry when every product resolves to the same inventory account,
  otherwise Dr output inventory + Dr wastage expense · Cr input inventory with
  `source_type = stock_production` and `source_purpose = production`. Given §6.5, then the same
  rule and the same words appear (no divergence between §6.5 and §7.5).

### The till, the engine and the write paths (§6.18, §12, arch §12 and §15)

- **AC-44**: Given §6.18, then the replica entity types include `unit_conversion` and
  `barcode_rule` (alongside `unit`), and the text says both must be in the **first** replica
  payload because adding an entity type after launch is a full resync of every terminal.
- **AC-45**: Given arch §12 (offline) and arch §15 (frontend), then arch §12 says the till holds
  unit conversions and barcode rules and why, and arch §15 says unit resolution and barcode parsing
  belong to the dual PHP/TypeScript calculation engine **and its shared fixtures**.
- **AC-46** (write paths): Given §12.1, then unit resolution happens **before** `SELECT … FOR UPDATE`
  and inside the locked section every quantity is base; the stated reason is that resolution reads
  `unit_conversions` / `units` and can reject. Given §12.2, then the line's own snapshotted
  `unit_factor` wins on an offline sale and a mismatch opens a `stock_conflicts` row (D-63), and this is
  consistent with the existing "a completed, paid sale is never rejected" rule (arch §12 first rule).
- **AC-47** (weighable resolution): Given §6.3 "Barcode resolution order", then order is exact
  `product_barcodes` lookup first, then `barcode_rules` by `prefix` + `length` in `priority` order
  **in memory from the replica** (no SQL prefix scan), `embedded_weight` yields quantity and unit,
  `embedded_price` fixes `line_total` and back-computes `quantity = line_total / unit_price` and
  records `sale_items.price_from_label = true` (D-65). §11 says `barcode_rules` is not a hot lookup.

### Likely-wrong criteria: claims a document can make without having done the thing

- **AC-48** (ERD agrees with the tables under it, §6.3): Given the §6.3 ERD, then its `PRODUCTS`
  entity lists `stock_unit_id`, `purchase_unit_id`, `sales_unit_id`, `is_weighable` and no
  `unit_id`; its relationships include `UNITS ||--o{ PRODUCTS` three times (stocked_in, purchased_in,
  sold_in); and every column it lists exists in the `products` row. Columns present in the row and
  absent from the ERD (`price_mode`, `plu_code`, `type`...) are allowed (the ERD is not exhaustive);
  columns present in the ERD and absent from the row are a defect.
- **AC-49** (ERD, §6.5): Given the §6.5 ERD, then `STOCK_MOVEMENTS` lists `reference_line_id` and no
  `unit_id`; `STOCK_PRODUCTIONS ||--o{ STOCK_PRODUCTION_ITEMS` and
  `PRODUCTS ||--o{ STOCK_PRODUCTION_ITEMS` exist; and every entity named in the ERD has a row in the
  table beneath it. `BARCODE_RULES` is absent from the §6.3 ERD; record whether that is intended
  (it has no foreign key into another table except `units`, so no relationship is drawn). The
  ERD's `STOCK_MOVEMENTS` omits `batch_key` and `serial_key`; they are generated columns of the key and
  the ERD is not exhaustive, so this is allowed.
- **AC-50** (table counts): Given §6's domain index, then the arithmetic is internally consistent
  **or its inconsistency is recorded**. Read: the index rows sum to **192** (including the three of
  "Plugins"); the headline in §6, §10 and the change logs is **≈193**; the backlog's FP-177 detail records
  a literal row count of **196** and says the index was deliberately **not** adjusted because §10
  models a rounded 200. **Update after QA run 1 and the fix round:** the index now sums to **193**
  (Catalog corrected to 14) against a true table count of **196**; the remaining audit is exactly
  three domains (Finance, Notifications, System), each one low. `qa` re-adds the index, re-counts rows
  per domain, and confirms the figures above or reports the actual ones. The criterion passes if each number is either correct
  or explicitly recorded as a known discrepancy; it **fails** if a number is stated with no
  discrepancy noted. See Finding F-2: the Catalog row appears to be stale for T-006's own table.
- **AC-51** (the 150 correction): Given `docs/architecture.md`, then neither "roughly 150" nor "150
  tables" appears as a per-tenant table count, and §7's "Server placement" paragraph says roughly
  190 and attributes the figure to `database.md` §10. Given §10, then it says ≈193 core plus ≈10
  plugin tables, keeps the rounded 200 model, and states +3 tables moves the hard cap to about 295
  tenants. `qa` checks the stated arithmetic: 3 tables × 300 tenants = 900 files; 900 / 200 tables
  per tenant (the rounded model) = 4.5 tenants; 300 − 4.5 ≈ 295. `qa` reports whether the derivation is
  sound, not only whether the sentence is present.
- **AC-52** (cross-references resolve): Given every `(§x.y)` or `§x.y` reference added by T-006 in
  either document (`qa` finds them with `git diff 47e226d..HEAD -- docs/database.md docs/architecture.md`
  and a search for `§`), then each points to a section whose heading exists **and whose content says
  what the reference claims**. Minimum set `qa` must follow and report on: §3.1 → §6.5 and §7; §6.3
  → §6.4, §6.17, §9.4, §14, §16 Q-9; §6.5 → §6.3 step 5, §6.6, §7, §7.5, §9, §11, §14, §16 Q-3; §6.6 →
  §6.5; §6.7 → §6.3, §6.5; §7.5 → §6.5; §12.1 → §6.3 step 5; §12.2 → §6.5; §14 → §6.5, §3.2;
  §15 → §6.5, §7.5; arch §21 → database.md §6.5; arch §22 decision 11; arch §23 → Q-9 to Q-12.
- **AC-53** (static tests are specified, and consistent): Given §14, then three static tests are
  specified: (1) no `unit_id` / `unit_factor` / `unit_code` on the seven tables; (2) no `decimal()`
  without precision; (3) every `[UOM]` table has all four columns. Given arch §21, then it names the
  same three (the third as "no partial unit column set on a document line"). **Defect risk:** test (3)
  as worded in §14 cannot hold for the degenerate tables of §3.1 (`price_list_items`, `product_barcodes`,
  `modifiers`) nor for tables whose `quantity` has another name (AC-26). This criterion passes if §14
  or §3.1 says which tables the test exempts; it fails if neither does. At the time of writing it
  **FAILS** (Finding F-3).
- **AC-54** (no contradiction on the base): Given every statement about which unit a quantity is in,
  then none says a stored quantity is in the purchase or sales unit. `qa` reads the `stock_levels`,
  `stock_reservations`, `batches` (`initial_quantity`), `stock_batch_levels`, `product_locations`
  (`reorder_level`, `reorder_qty`, `max_level`) and `stock_conflicts` (`expected_qty`, `resulting_qty`)
  rows and records, for each quantity column, whether the document says what unit it is in. The
  boundary rule (AC-16) makes them base; the ones that **say so** are `stock_levels`,
  `stock_movements`. The rest are silent in their own row and rely on the §6.5 rule; `qa` records
  which, and the criterion passes if §6.5's rule is explicit that "every quantity stored anywhere in
  this database for a product is in that product's stock unit unless it sits on a document line"
  (§6.3 "The base unit"). It is a defect only if the rule is absent.
- **AC-55** (vocabulary): Given T-005, then `docs/database.md`, `docs/architecture.md` and
  `docs/backlog.md` use the post-T-005 vocabulary (modules, `MODULES`, `module:make`) and contain none
  of the pre-T-005 identifiers of T-005 AC-4. The gate's vocabulary sweep (AC-57) is the mechanism;
  `qa` also reads the T-006 additions for ordinary slips.
- **AC-56** (the folded FP-158): Given §4.4 "Resolution and caching", then the cache key is
  `tenant:conn:<tenant_id>` with `credential_version` stated as a **value** inside the cached mapping,
  not part of the key; and the resolved secret is cached against `<tenant_id>:<credential_version>`
  and **not for the process lifetime**, so rotation invalidates it by construction. The text of
  both defects is in `docs/backlog.md` "FP-158 detail". The backlog row FP-158 still reads
  "TODO" while §4.4 is fixed; see Finding F-5.
- **AC-57** (the gate): Given the final tree, when the lead runs `composer check` from
  `modules/host`, then it exits 0 with the **same passed-test and assertion counts as the T-005 tip**
  (no code and no test changed; `qa` records both numbers from the T-005 QA report and from the run).
  **`ba` did not run the gate** (instruction: the lead runs it once). A passing gate says nothing
  about the documents' correctness beyond vocabulary and link-style checks; it is not a substitute
  for AC-1 to AC-56.
- **AC-58** (a claim that cannot be checked here): Given the statement "ten occurrences, not five"
  (AC-35), "roughly 1.5 million ledger rows per tenant per year" (§6.3, §11 row estimates agree: 1.5 M)
  and "+3 tables, ≈190 → ≈193 (+1.6 %)": `qa` checks 3 / 190 = 1.58 % and that §11's row estimate
  (1.5 M movements at 1 000 sales a day) is the figure §6.3 quotes. Absolute measurements in prose are
  FP-171's family; the criterion is that each one in T-006's text is either re-derivable from the
  document or labelled as an estimate.

### The three new tables, in full

- **AC-59** (`barcode_rules`, §6.3): Given its row, then columns exactly: `[ID]` `[TS]`, `code`
  unique, `name`, `kind enum(embedded_weight, embedded_price, plu, gs1_128, isbn, internal)`,
  `prefix VARCHAR(4)`, `length TINYINT`, `plu_offset`, `plu_length`, `value_offset`, `value_length`,
  `value_decimals` (all `TINYINT`), `check_digit_scheme enum(none, ean13, gs1_mod10)`,
  `lookup enum(plu, sku, barcode)`, `unit_id FK units NULL`, `priority SMALLINT`, `is_active`, and
  `unique (prefix, length)` among active rows (D-64). It says "~5-20 rows per tenant".
- **AC-60** (`stock_productions` and `stock_production_items`, §6.5): Given their rows, then
  `stock_productions` has `[ID]` `[TS]` `[BY]` `[GL]`, `number` unique, `location_id`, `type
  enum(repack, assemble, disassemble, recipe)`, `production_date`, `status enum(draft, posted,
  cancelled)`, `source_product_id`, `input_value`, `output_value`, `wastage_value`, `posted_at`,
  `posted_by`, `notes` and indexes; `stock_production_items` has `[ID]` `[UOM]`, `production_id` FK
  CASCADE, `role enum(input, output, byproduct, wastage)`, `product_id`, `batch_id` NULL, `expected_quantity
  DECIMAL(18,4)`, `unit_cost`, `cost_share_percent DECIMAL(9,4)`, `value`, `batch_no` / `expiry_date` /
  `mfg_date`, `notes`, index `(production_id, role)`. Given §6.5 "Repack", then cost apportionment is
  stated (relative sales value by default; base-quantity-proportional for a pure repack; the **last
  output line absorbs the rounding residual** so `SUM(output value) + wastage_value = input_value`
  exactly). The new tables appear in the domain index (§6.3 and §6.5 rows) and in the §6.5 ERD.
  `qa` additionally confirms a number-sequence mention for production documents (§15 says "a number
  sequence"; confirm one is defined or note it).

### The three nightly invariants (§6.5)

- **AC-61** (invariants): Given §6.5 "Three nightly quantity invariants", then there are exactly
  three, numbered: (1) `stock_levels.quantity_on_hand` = `SUM(stock_movements.quantity)` per
  (location, product); (2) `balance_after` equals the running sum in `created_at`/`id` order,
  **sampled**; (3) `SUM(grn_items.base_quantity + ROUND(free_quantity × unit_factor, 4))` =
  `SUM(stock_movements.quantity)` per posted receipt per product. The pre-existing check
  `SUM(stock_batch_levels) = stock_levels` is documented as **structurally blind** to a conversion
  bug (both sides are written from one already-converted value) and kept. Check 3 uses the same
  expression as the posting rule in the `grn_items` row (AC-30); `qa` confirms the two expressions are
  identical, because the invariant is only meaningful if it mirrors the posting.
- **AC-62**: Given arch §21, then quantity invariants "run nightly" and are named as three, with the
  third described as the only one that spans the document boundary.

### Open questions are recorded as open, not assumed

- **AC-63**: Given §16, then Q-9, Q-10, Q-11 and Q-12 exist as open questions, bold-marked, each with
  the place it bites and a proposal that is labelled a proposal, and they match this spec's "Open
  questions" below in substance. Given arch §23, then rows 6 to 9 carry the same four with their
  defaults. The proposals are defaults only; no section of either document may state any of them as
  settled in a way that makes the question moot. (§6.3 states Q-9 as a "guard" and Q-10 as a rule
  while pointing at the question; that is permitted because each is labelled "the owner is asked to
  confirm" or points at the question; `qa` confirms each of the four has such a pointer: Q-9 in
  §6.3 yes; Q-10 in §6.3 "immutable" paragraph, `qa` confirms the pointer; Q-11 in §3.2/§16; Q-12 in
  §16 only.)
- **AC-64**: Given the decisions file, then Part 1 records U-1 to U-3 as settled and Part 2 states
  the four business questions were **not** decided there (they went to §16 and arch §23). `qa`
  confirms none of D-57 to D-65 answers Q-9 to Q-12. D-62 (recipe factor snapshot time) is flagged
  by its author as "arguably the owner's"; it is recorded as a decision here and re-surfaced as an
  open question (Open question 5) without being re-decided.

### Scope guards

- **AC-65** (no code): Given `git diff 47e226d..HEAD --stat`, then no file under `modules/`,
  `frontend/`, `print-agent/`, `infra/` or `.github/` changed. (Part of AC-4; separate so a failure
  names the cause.)
- **AC-66** (`CLAUDE.md` and `Refference/` untouched, diff): Given the same diff, then
  `CLAUDE.md` is unchanged and nothing under `Refference/` changed. `CLAUDE.md` line 5 and
  `docs/backlog.md` line 7 cite `architecture.md` and `database.md` as **v0.4**; both are now v0.5.
  This criterion passes if the diff touches neither `CLAUDE.md` nor `Refference/`; the stale citation is
  Finding F-4 for the lead.
- **AC-67** (decisions are append-only): Given `docs/decisions/T-006-unit-of-measure.md`, then
  D-57 to D-65 are byte-identical to their text at the time this spec was written, and `ba`'s
  additions begin at D-66. Parts 1 and 2 headers are unchanged.

## Out of scope

- Any implementation: migrations, models, `UnitResolver`, the barcode parser, the TypeScript engine,
  the replica payload. These belong to FP-230, FP-231, FP-236, FP-237, FP-250 to FP-257.
- Live-weight scale hardware and the print-agent integration: FP-258, Phase 6 (owner U-2).
- Whether free goods dilute `avg_cost` (`grn_items` row: deliberately not settled).
- The depletion posting rule for `modifiers.linked_product_id` (belongs to the restaurant workstream).
- FIFO costing (Q-3), unchanged.
- Recording the CQRS direction in the architecture document (owed, not this task).
- Re-deriving the domain index counts (FP-177), beyond reporting the discrepancy.
- Updating status fields in `docs/backlog.md` (lead).
- Changing D-57 to D-65.

## Non-functional requirements

- Documents only: the gate (`composer check`) must remain green and its test counts unchanged.
- The vocabulary sweep (`qaVocabExemptPaths()`) covers `docs/database.md`, `docs/architecture.md`
  and `docs/backlog.md`: they must not quote pre-T-005 identifiers. `docs/specs/` and `docs/decisions/`
  are exempt.
- Every numbered claim in prose should be re-derivable or dated (FP-171) (AC-58).
- Markdown tables must render: a vertical bar inside a cell is escaped (FP-178 is the known remaining
  one in §8; T-006 fixed one in §7.5; `qa` scans T-006's added rows for an unescaped `|`).

## Findings at the time of writing

For the lead. Each was a criterion that failed against the documents as they stood, or a decision
recorded but not done. `ba` fixed none (documents are not `ba`'s to edit). **Status below is as of
QA run 1 (`docs/qa/T-006-unit-of-measure.md`, commit `bf81e56`) and the architect's fix round, as
reported by the lead; `ba` re-measured only what is marked "re-read".**

| # | Status | One line |
|---|---|---|
| F-1 | **Resolved** | True count is 17 (three independent counts); `stock_production_items` is the seventeenth and is new in v0.5; §3.1 was right and the "sixteen" claims were wrong. Re-read: §3.1 and the change log now both mention seventeen. |
| F-2 | **Resolved** (account corrected) | Catalog now 14 (re-read). See the corrected account in F-2 below. |
| F-3 | **Resolved** | Five tags fail a literal four-column test, not three (`stock_count_items` and `stock_transfer_items` too); §14 now lists the exemptions (13 document-line plus 4 master-data tables). |
| F-4 | **Half resolved, still live** | `docs/backlog.md` fixed; `CLAUDE.md` line 5 still says v0.4 and stays so: owner's file, awaiting acknowledgement. |
| F-5 | **Resolved** | FP-158 is DONE (T-006). |
| F-6 | **Resolved** | Nullable `product_barcodes.unit_id` is stated; the architect also listed `modifiers.linked_unit_id`. |
| F-7 | **Resolved** | "Print-bearing" enumerated as `sale_items`, `quotation_items`, `order_items` (D-66). |

The paragraphs below are the **original accounts as written mid-flight**, kept for the record; where
they disagree with the table above, the table wins (F-2 is corrected in place).

AC-52 and AC-63 failed in QA run 1 because the **document** was wrong; they are not softened and stay
as written until QA run 2 passes them.

- **F-1 (AC-23): sixteen against seventeen.** Seventeen tables carry `[UOM]`. §3.1 says seventeen.
  The header change log, §6.3 ("column sweep across sixteen tables") and the backlog say sixteen.
  Either one is wrong or one table is intentionally excluded and unnamed. The brief this spec was
  written from also says sixteen.
- **F-2 (AC-50): Catalog was stale. RESOLVED; account corrected by QA.** My first account said the
  index showed Catalog at 13 against a true 14 and blamed T-006's `barcode_rules` alone. **That was
  wrong.** QA measured the base commit `47e226d`: Catalog said **12** against a true **13**, and the
  base index summed to 189. Catalog was therefore **already one low before T-006**; `barcode_rules`
  made it one low again at the new figure. The architect's plan had this right. The difference matters
  to FP-177: Catalog is a pre-existing instance of the drift, not purely T-006's doing. I took the
  index on T-006's tip as the only state I read and inferred the past; I had no git and should have
  labelled it as an inference. Now corrected to 14, index 193, true count 196, and FP-177's audit is
  three domains (Finance, Notifications, System). FP-177's stated reason ("a row naming two tables
  together is counted once") does not explain Catalog; it explains the other three.
- **F-3 (AC-53): static test (3) as written cannot be satisfied** by the three degenerate tags or by the
  differently named quantity columns; neither §3.1 nor §14 lists the exemptions.
- **F-4 (AC-66): `CLAUDE.md` line 5 and `docs/backlog.md` line 7 still cite v0.4** for both documents.
  `CLAUDE.md` is the lead's / owner's file; the backlog is the lead's. Not changed here.
- **F-5 (AC-56): backlog row FP-158 still says TODO** though §4.4 now carries both fixes. Status is the
  lead's.
- **F-6 (AC-24): §3.1 does not state that `product_barcodes.unit_id` is nullable** in its
  "degenerates" paragraph, although the row declares `unit_id FK NULL`.
- **F-7 (AC-27): "print-bearing" is not enumerated.** Only `sale_items`, `order_items`, `quotation_items`
  are stated to carry `unit_code`; a printed purchase order or transfer note is not mentioned.
  Logged as D-66, not changed.

## Open questions

The first four are the owner's. They were escalated by `architect` to `docs/database.md` §16
(Q-9 to Q-12) and `docs/architecture.md` §23 and are **not** answered here. Nothing blocks the
documents; each blocks only the later work that would depend on the answer.

1. **Q-9: Is every conversion factor to base ≥ 1?** Working assumption: yes, validated on
   `unit_conversions`. *If yes:* base quantities stay integral for counted goods, the stock unit is the
   smallest thing sold, and a trade stocking in metres and selling in centimetres cannot be modelled
   (it would need a new base). Relaxing later is a validation change. *If no:* factors below 1 are legal,
   `base_quantity` can be a repeating decimal at `DECIMAL(18,4)`, the rounding order of §3.2 matters
   in every line, and the "atom" argument for ledger integrality is lost. Tightening later is a data
   migration. [Not blocking; blocks the validation rule in FP-230.]
2. **Q-10: Is `stock_unit_id` immutable once stock has moved?** Working assumption: yes, in the
   service layer; changing it is a new product. *If yes:* no restatement document; a shop that picked
   the wrong base creates a product and transfers stock. *If no:* a restatement document must
   re-express every historical movement, batch level, average cost and snapshotted factor, and
   be audited; the "no `unit_id` below the document" boundary makes that restatement touch every
   ledger row. [Not blocking.]
3. **Q-11: Are fractional base quantities ever legal when the base unit is `each`?** Proposal: a
   unit with `precision = 0` makes the writer **reject** a fractional base quantity instead of rounding.
   *If rejected:* half a tin cannot be sold from a product whose base is the tin; such a shop needs a
   finer base. *If allowed (round or accept):* `quantity_on_hand` can hold 0.5 of a countable unit and the
   count sheet must show it; rounding either way misstates stock. [Not blocking.]
4. **Q-12: Does a branch need its own default sale unit?** (`product_branches.sales_unit_id`.)
   Proposal: no for 1.0. *If no:* a branch with a different habit relies on price per unit
   (U-1) and the cashier changing the line unit. *If yes:* an additive column on a
   **replicated** table, which is a full resync of every terminal after launch, plus a fourth rung in
   the default-unit choice. [Not blocking.]
5. **Does a recipe (`product_components`) track a later correction of a unit's factor?** D-62 snapshots
   the factor when the recipe is written, so it does not. Surfaced because the decision's author
   called it arguably the owner's. *If it should track:* the factor must be resolved at consumption,
   which breaks the rule that nothing re-resolves after the line is written for this one table. *If not:*
   a recipe written before a correction keeps the old factor until someone edits it. Reversible by
   backfill over tens of rows per tenant. [Not blocking; default stands.]
6. **Does free goods (`free_quantity`) dilute `avg_cost`?** Explicitly not settled by this task
   (§6.6). Owner / valuation decision for FP-255. [Not blocking this task.]

## Assumptions

- "Sixteen tables" in the brief means the `[UOM]` census, and the discrepancy with seventeen is
  reported rather than resolved (F-1). The three documented exceptions are read as the three
  `[UOM]` tables with a nullable column literally named `unit_id` (AC-24).
- The T-005 tip is `47e226d` and the branch point of T-006 (taken from the brief; `ba` could not run
  git to confirm).
- "Print-bearing" means the three tables that say so (D-66).
- `qa` has a shell and runs the "diff" criteria; `ba` read the current text only.
- A criterion that the documents already satisfy is written anyway; one that they fail is reported in
  Findings and left failing, not softened.
- The architect's plan document (`docs/plans/T-006-unit-of-measure.md`) was being written concurrently
  and was not read; no criterion depends on it.
