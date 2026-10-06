# Plan: T-006 The unit-of-measure model

Spec: `docs/specs/T-006-unit-of-measure.md` · Decisions: `docs/decisions/T-006-unit-of-measure.md`
(owner U-1 to U-3; D-57 to D-65) · Covers **FP-243**, gates **FP-230**, widens ten rows, opens
**FP-257** and **FP-258** · Branch `ai/T-006-unit-of-measure` from `62cd25d`.

**This plan is written after the work, not before it.** The design was briefed to me outside the
repository and executed in nine commits, so until this file existed the reasoning behind
`docs/database.md` v0.5 lived nowhere a reviewer, a QA pass or a future agent could read it. That is
the same gap I reported when I went looking for the "approved CQRS plan" the brief cited, found no
file in the repository mentioning CQRS, and refused to cite it — so the fix applies to my own work
first. Written retrospectively, every number below is **MEASURED** on this working tree on
2026-10-06 and every step names the commit that carried it, which is a stronger record than a
forecast would have been.

Scope: **documents only.** No migration, no PHP, no test, no stub. MEASURED total
(`git diff --numstat 62cd25d`): **+394/−57 lines** across three files — `docs/database.md`
+233/−49, `docs/architecture.md` +13/−8, and the new 148-line decisions file.

---

## The problem as found

The product owner asked whether the design had considered that the goods-receipt unit and the
selling unit differ, and that this varies by industry. Investigation found something worse than a
missing feature: **the mechanism existed and nothing downstream used it.**

| What v0.4 had | Where | What used it |
|---|---|---|
| `unit_conversions`, per-product factor, note reading "Buy in cases, sell in units" | `docs/database.md` §6.3 :455 | nothing |
| `products.purchase_unit_id`, separate from `products.unit_id` | §6.3 :456 | nothing |
| `product_barcodes.unit_id` + a pack `quantity` | §6.3 :457 | nothing |

MEASURED on the v0.4 document: **`unit_id` appeared on exactly four line tables** —
`purchase_order_items` (:549), `grn_items` (:550), `sale_items` (:600), `product_components` (:461).
Everything else carried a bare `quantity`: the whole stock ledger, levels, batch levels, batches,
reservations, transfers, adjustments, counts, purchase returns, supplier invoices, price history,
quotations, sales orders and restaurant order items.

MEASURED, and this is the finding that mattered: **there was no base-unit statement anywhere in
1251 lines.** `grep -i base` returned `units.base_unit_id`, `journal_lines.base_debit`/`base_credit`,
and the phrase "base currency" — nothing about quantity. Conversion appeared in no posting rule, no
price-resolution rule and no write path. A reader could not answer "what unit is
`stock_levels.quantity_on_hand` in?" from the document, and neither could an implementer.

So the defect was not "units are missing". It was that **the unit stopped at the document edge and
nothing said what was on the other side.** Four tables knew; nothing told the rest.

Three secondary findings fell out of reading for the first one, and each is a bug in its own right:

1. `stock_movements.type` has shipped `production_in` and `production_out` since v0.3 with **no table
   able to be their `reference_type`** — dead schema at the centre of the inventory module.
2. The ledger idempotency key `(reference_type, reference_id, product_id, batch_id, type)` **could
   not express the owner's own scenario** (two lines of one product on one receipt collide), and
   because `batch_id` is nullable and MySQL treats NULLs as distinct it **enforced nothing at all**
   for `tracking_mode = quantity`, which is most products in most shops.
3. The only quantity invariant in the design — `SUM(stock_batch_levels) = stock_levels`, nightly
   (:518) — is **structurally blind**: both sides are unitless and are written from the same
   already-converted number inside one transaction, so a conversion bug satisfies it perfectly.

---

## The organising idea: this is the currency axis, again

The identical problem was already solved on the money axis, and solved well. §7 :914 says: *amounts
are in the document currency with `base_debit`/`base_credit` in the company currency at the
document's rate*, backed by `exchange_rate DECIMAL(18,8)` on every header and an `exchange_rates`
table. **Quantity is the second dimension of that same shape, and the pattern was simply never
applied to it.**

| | Money — v0.4, already designed | Quantity — v0.5, this task |
|---|---|---|
| What the user entered | `currency` on the header | `unit_id` on the line |
| The rate it converted at | `exchange_rate`, **snapshotted** | `unit_factor`, **snapshotted** |
| The value downstream reads | `base_debit` / `base_credit` | `base_quantity` |
| Where the rate came from | `exchange_rates` | `unit_conversions` + the `units` chain |
| What the ledger stores | company currency | the product's stock unit |

Everything in the task follows from taking that table seriously, which is why the design is **one
rule rather than a list of columns**:

- **R1 — there is a base.** `products.unit_id` becomes **`stock_unit_id`**. The rename *is* the fix:
  the column's meaning was never stated in prose, and a name that carries the meaning cannot drift.
  `purchase_unit_id` keeps its place and a symmetric `sales_unit_id` joins it; both are **UI
  defaults for a document line, never authorities**.
- **R2 — conversion happens once.** At the document line, and nowhere else. *Below the document
  layer there are no units.* Seven named tables therefore carry no `unit_id`, **ever**.
- **R3 — the rate is snapshotted, not resolved.** Changing a case size next year cannot
  retro-change last year's receipt.

Two consequences worth stating because they are what make this cheap rather than expensive:

- **R3 is the effective-dating that `tax_rates` needed a whole table for.** A factor that lives on
  the line needs no `effective_from`, no `effective_to` and no resolve-by-document-date, because the
  line *is* the date-stamped fact. `unit_conversions` therefore needs no effective dating at all.
- **R3 is also a hard dependency of any read model.** A projection is rebuilt by replaying source
  rows; a factor resolved at read time would silently re-quantify history on every rebuild. This
  matters more than it looks, because the owner set a CQRS direction on 2026-10-06 — see the
  correction below about citing it.

---

## Alternatives rejected

- **Add `unit_id` wherever a quantity appears.** The obvious reading of the defect, and wrong: it
  puts a conversion in every write path and a second one in every report, and grows twenty
  unit-bearing tables with twenty chances to convert twice. The boundary is the opposite answer —
  the unit goes on the one row where a human typed a number.
- **Derive every alternate-unit price from the stock-unit price by factor.** Rejected by the owner
  (U-1) with four reasons on record, and the fourth is the expensive one: the unique key that would
  have to change later is on a **till-replicated** table, so deferring this is a full resync of
  every terminal in the field. The other three: the case discount is near-universal; pharmacy
  *inverts* it; and derivation is self-contradictory under rounding when a shop prices a case at 179
  and 24 × 7.50 = 180.
- **`min_qty` instead of a price unit.** Tempting and cheap, and it answers a different question:
  `min_qty` is a volume break on *base* quantity, so `min_qty = 24` charges the case price to a
  customer who picks 24 loose singles off the shelf — which is exactly what the case discount exists
  to prevent.
- **Extend `products.type` with `weighable`.** Rejected: that enum is the bundle/variant/composite
  axis, so a `weighable` member makes weighable **mutually exclusive with `variant`**, which kills
  three grades of rice as three variants of one parent. Weighability is a separate axis.
- **Per-product barcode prefix columns instead of a `barcode_rules` table.** Rejected: the pattern
  belongs to the **scale family**, not the product. One rule serves four thousand PLUs; columns mean
  four thousand copies of one fact and no way to onboard a second scale.
- **Adding `unit_id` to the ledger idempotency key.** Rejected: it still collides when two lines of
  one product in the same unit arrive from two purchase orders on one receipt, and it does nothing
  about the NULL hole, which is the worse of the two bugs.
- **A `split_no` counter on the movement.** Rejected: it needs a deterministic allocation order to be
  idempotent across a replay, which a line ULID already gives for free.
- **Separate input and output tables for repack.** Rejected: disassembly has many outputs plus a
  remainder, and one table with a `role` column makes "value in = value out" a single aggregate
  instead of a join between two tables that must be kept consistent.
- **A restatement document for changing a product's stock unit.** Rejected: it would have to
  re-express every historical movement and nobody will build it. The stock unit is immutable once a
  movement exists; changing it is a new product, and the owner is asked to confirm it as **Q-10**.

---

## Files to change / create

### Change — `docs/database.md` (v0.4 → v0.5; MEASURED 1251 → 1435 lines, +233/−49)

| § | Change |
|---|---|
| header | Status, Date, Decision source (cites the decisions file for U-1…U-3), v0.5 change-log entry in the v0.4 entry's style |
| 3.1 | new **`[UOM]`** row beside `[GL]`/`[SYNC]`; the note on where the tag degenerates |
| 3.2 | factor type; one precision authority each; the fixed rounding order; "a bare `DECIMAL` is `DECIMAL(10,0)`" |
| 4.4 | **FP-158**, both defects, folded in as briefed |
| 6 intro + index | ≈190 → ≈193; index rows 6.3 → 13, 6.5 → 15 |
| 6.1 | `number_sequences.document_type` += `stock_production` |
| 6.3 | ERD fix; `units`, `unit_conversions`, `products`, `product_barcodes`, **`barcode_rules`** (new), `product_components`, `modifiers`; four new prose blocks |
| 6.4 | `price_list_items`; the unit-qualified price ladder; U-1's rationale |
| 6.5 | ERD; `stock_levels`, `stock_movements`, three item tables, **`stock_productions`** + **`stock_production_items`** (new); six new prose blocks |
| 6.6 | five line tables; `received_base_quantity`; `purchase_price_history` normalised |
| 6.7 | four line tables; `sale_items.price_from_label` |
| 6.10–6.15 | ten bare `DECIMAL`s given precisions; `journal_entries.source_type` += `stock_production` |
| 6.18 | replica list += `unit_conversion`, `barcode_rule`, with the blast-radius paragraph |
| 7, 7.2, 7.5 | the quantity twin of the currency bullet; price variance per base unit; count variance; the production posting row |
| 10, 11 | the three tables priced at the hard cap; the new ledger key; `plu_code`; barcode rules are not a hot lookup |
| 12.1, 12.2 | conversion before the row lock; offline, the line's factor wins |
| 14, 15, 16 | three static tests + MariaDB parity; production types no longer dead schema; **Q-9…Q-12** |

### Change — `docs/architecture.md` (v0.4 → v0.5; MEASURED 373 → 378 lines, +13/−8)

Header and change log · §3 Catalog owns the unit model · §7 the 150-table defect · §12 replica
contents · §15 the dual engine · §21 invariants and static tests · §22 decision 11 · §23 questions
6–9.

### Create — `docs/decisions/T-006-unit-of-measure.md`

Part 1: owner U-1/U-2/U-3 on record, because `docs/database.md` cites them and a design document
should not be the only place a business decision lives. Part 2: **D-57 to D-65**, continuing from
D-56.

### Not touched

`docs/backlog.md` (the lead's), `docs/specs/` (`ba`'s), `CLAUDE.md`, `Refference/`, and every line of
code. No migration was written: the one in `database/tenant/migrations/` that this design implies
belongs to FP-230 and FP-250, not here.

---

## Data / API changes

No API. The schema deltas, which are what FP-230 onward will implement:

**The `[UOM]` column set** — `unit_id CHAR(26) NOT NULL` FK `units` · `quantity DECIMAL(18,4)` in
`unit_id` · `unit_factor DECIMAL(18,6)` snapshotted at entry · `base_quantity DECIMAL(18,4)` =
`ROUND(quantity × unit_factor, 4)` · plus `unit_code VARCHAR(50)` on print-bearing lines only,
generalising the snapshot `sale_items` already carried.

**Applied to the sixteen tables the brief named**, plus `stock_production_items` which is new, for
seventeen in total: `purchase_request_items`, `purchase_order_items`, `grn_items`,
`purchase_return_items`, `supplier_invoice_items`, `sale_items`, `quotation_items`,
`sales_order_items`, `order_items`, `stock_adjustment_items`, `stock_count_items`,
`stock_transfer_items`, `stock_production_items`, `product_components`, `price_list_items`,
`product_barcodes`, `modifiers`.

**Three of those are master data and take only the part that means something there** —
`price_list_items` (`unit_id` alone; `min_qty` is a base threshold), `product_barcodes` (`unit_id` +
pack `quantity`), `modifiers` (`linked_unit_id` + `linked_quantity`). `product_components` takes the
whole quad but snapshots at BOM-write time (D-62).

**Three new tables**, ≈190 → ≈193 per tenant: `barcode_rules` (~5–20 rows/tenant),
`stock_productions`, `stock_production_items`.

**Changed columns on existing tables:** `products.unit_id` → `stock_unit_id` (NOT NULL), plus
`sales_unit_id`, `is_weighable`, `price_mode`, `plu_code` · `units.dimension` + a `precision` CHECK ·
`price_list_items.unit_id` and a new unique `(price_list_id, product_id, unit_id, min_qty)` ·
`purchase_order_items.received_base_quantity` · `stock_count_items.counted_qty` →
`counted_quantity` + the quad · `stock_transfer_items` base sent/received pair ·
`sale_items.price_from_label` · `modifiers.linked_quantity` / `linked_unit_id` · ten bare `DECIMAL`
columns given explicit precision.

**The ledger key**, which is the one piece of this that would be expensive later:

```sql
+ reference_line_id CHAR(26) NOT NULL                                         -- the line that caused the movement
+ batch_key  CHAR(26) GENERATED ALWAYS AS (COALESCE(batch_id,  '-')) STORED
+ serial_key CHAR(26) GENERATED ALWAYS AS (COALESCE(serial_id, '-')) STORED
  unique (reference_line_id, type, batch_key, serial_key)
```

`reference_type` leaves the key; `(reference_type, reference_id)` stays a non-unique index;
`product_id`/`batch_id` demote to plain indexed columns; header-level movements (`opening`,
`correction`) use the header's own ULID. MariaDB 10.4 parity holds because §14 already records
stored generated columns in indexes as the workaround for its missing functional indexes.

**Enums extended:** `journal_entries.source_type` and `number_sequences.document_type` both gain
`stock_production`.

**Migration cost today: zero.** MEASURED — FP-230 and FP-250…FP-256 are TODO and the only migrations
in the repository are the framework's `users`/`cache`/`jobs`. §9.4's "never rename in one step" rule
is not being waived; `stock_unit_id` is simply the column's first and only name. The same change
after launch would be this rename **plus** a sweep across sixteen tables, a unique-index rebuild on
an append-only ledger holding ~1.5 M rows per tenant per year, and a terminal resync because the
price table is replicated — all of it once **per tenant database**.

---

## Implementation steps, as executed

Nine commits, each one green on the only test this task could affect. Ordered so that every later
section could cite an earlier one rather than forward-reference it.

- [x] **Step 1 — the vocabulary trap first.** Before writing a word: read
      `QaVocabularyRenameSweepTest` to learn exactly which literals are forbidden, because it sweeps
      the **working tree** and `docs/database.md`/`docs/architecture.md` are **not** exempt. MEASURED
      from the test source: the three-letter abbreviation is matched **case-insensitively**, and the
      old top-level directory name is matched only with its trailing slash. Baseline run: green.
      (No commit — this is why there is no commit fixing a red gate.)
- [x] **Step 2 — conventions and the tag** → **`65e1075`**. §3.1 `[UOM]`, §3.2 precision authority
      and rounding order, §6.3 in full (base unit, ladder, dimension, weighable, `barcode_rules`),
      §6.4 in full (U-1), and **FP-158** in §4.4. Grouped because the tag has to exist before any
      table can carry it, and because FP-158 is in the same file with the same owner.
      (Covers R1, U-1, U-2, the ladder, the fold-in.)
- [x] **Step 3 — the boundary and the ledger** → **`c0102a2`**. §6.5: R2 worded against :914 with
      the money↔quantity table, R3, the key fix with both bugs and both rejected alternatives, the
      weighted-average formula, `quantity_incoming`, repack (U-3), and the three nightly invariants
      beside the blind one. (Covers R2, R3, U-3, items 1, 4, 6, 10.)
- [x] **Step 4 — the line tables** → **`93796ba`**. §6.6 and §6.7: the quad on nine line tables,
      `received_base_quantity`, `purchase_price_history` normalised. (Covers items 3 and part of 2.)
- [x] **Step 5 — everything that reads a quantity** → **`bb7ccb0`**. §7 ¶1, §7.2 variance, §7.5,
      §6.18 replica, §10, §11, §12.1, §12.2, §14, §15, §16, and the bare-`DECIMAL` sweep.
      (Covers items 2, 5, 8 and the "Also" list.)
- [x] **Step 6 — `docs/architecture.md`** → **`505d380`**, including the pre-existing 150-table
      defect. Last of the two documents deliberately, so it could cite a v0.5 that already existed.
- [x] **Step 7 — the decisions file** → **`ca2e52e`**. Owner U-1…U-3 plus D-57…D-64.
- [x] **Step 8 — review my own output** → **`c5dae52`**, **`88ddbc1`**, **`ddeaed8`**. Three wording
      defects of my own, one named flag (`price_from_label`, D-65), **one substantive correction to
      the brief's own invariant** (free goods, below), two index notes, and a pipe escape in a §7.5
      row I had edited. This step found the only real error in the delivered text, which is the
      argument for keeping it as a step rather than trusting the first pass.
- [x] **Step 9 — report** the five brief corrections with their measurements, which produced FP-177,
      FP-178 and the backlog note that the CQRS direction is unrecorded.

---

## Corrections to the brief, with the measurement

A plan that hides where its brief was wrong teaches the next reader nothing. All five were reported
before the gate ran; all five were accepted.

**C-1 — the table count is right against the document and wrong against the tables.** MEASURED: the
§6 domain index **summed to 189** before this task (192 after), while a literal count of the table
rows in §6 gives **193 today → 196 after**. Four index rows are each one low: **Catalog 12/13,
Finance 19/20, Notifications 6/7, System 8/9** — the misses are `currencies / exchange_rates`,
`webhook_endpoints / webhook_deliveries`, `jobs / job_batches / failed_jobs`, and the thirteenth
catalog row. I implemented the briefed figure (≈190 → ≈193) and did **not** silently re-denominate,
because §10's whole arithmetic and the backlog row both rest on it, and §10's table models a rounded
**200 tables per tenant** (190 core + 10 plugin) whose 20 000/40 000/60 000 figures would all move at
203. I added one sentence saying the table keeps its rounded model. Filed as **FP-177**.

**C-2 — "the approved CQRS plan" could not be cited.** MEASURED: `grep -rni cqrs docs/` returns
**nothing**. The only record is the owner's directive of 2026-10-06, and the flavour — code-level
separation, projections, or full event sourcing — is not pinned, which matters because the three
imply very different schemas. So I wrote the dependency as a property of **any** read model (a
projection replayed with a read-time factor re-quantifies history on every rebuild), attributed the
direction to the owner with its date, and noted that the architecture revision is still owed. I did
not use the brief's term "claim-mark projection", which I could not source. This is the finding that
this plan file exists because of.

**C-3 — there are ten bare `DECIMAL`s, not five.** MEASURED: the five named, **plus**
`expense_categories.approval_threshold`, `expense_categories.budget_monthly`,
`salary_structure_components.amount`, `salary_structure_components.percent`,
`workflow_instances.amount`. The instruction said "every bare occurrence", so all ten were fixed;
`grep DECIMAL | grep -v 'DECIMAL('` now matches only the change-log sentence. Related and
deliberately **not** changed: `employee_salary_components.amount`/`percent` and
`price_list_items.price` are **untyped**, not bare — the §3.2 convention covers them and there is no
`DECIMAL` keyword for MySQL to read as `(10,0)`. The hazard is the word without the precision, and
§3.2 now says so.

**C-4 — nightly check 3 as briefed would have cried wolf on every receipt with a supplier bonus.**
`SUM(grn_items.base_quantity) = SUM(stock_movements.quantity)` ignores `grn_items.free_quantity`,
which is physical stock and must arrive in the ledger. A new invariant that fires spuriously is
switched off within a month, which would have cost exactly the check that is the only one of the
three able to see a unit bug. Corrected to
`SUM(base_quantity + ROUND(free_quantity × unit_factor, 4))`, with the matching posting sentence on
the `grn_items` row.

**C-5 — `[UOM]`'s `unit_id NOT NULL` cannot hold on three of the sixteen tables.**
`product_barcodes.unit_id` and `price_list_items.unit_id` are **meaningfully** nullable — NULL is the
"priced per stock unit, derive by factor" row that the whole U-1 fallback depends on — and
`supplier_invoice_items` has nullable `product_id` for freight and service lines, which have no stock
unit to convert into. Documented as the degeneracy note in §3.1 and as **D-61**, so the static test
that checks the tag is complete knows about the one exception before it is written.

Minor, no impact: the brief cites `sale_items.unit_code` at `:47`; :47 is the snapshot-columns
*principle* and names `product_name`/`unit_price`/`tax_breakdown`. The column is on the §6.7 row, and
that is what the generalisation is anchored to.

---

## Decisions

Nine decisions the brief did not settle are recorded in
`docs/decisions/T-006-unit-of-measure.md` as **D-57 to D-65** and are not restated here. The three a
reviewer should read first:

- **D-57** — `unit_cost` means per **line unit** on a purchase line and per **stock unit** on an
  outbound cost snapshot. One column name with two readings is how the 24× error gets written.
- **D-59** — `sales_order_items` keeps delivered/invoiced in base, deliberately asymmetric with
  `purchase_order_items.received_quantity`, because a purchase order is a document the *supplier*
  reads.
- **D-62** — a recipe's factor is snapshotted at BOM-write time. Flagged in the file as *arguably the
  owner's*; not blocked on, because it is reversible by a backfill over tens of rows per tenant.

---

## Open questions, and what each answer costs

All four are in `docs/database.md` §16 and mirrored in `docs/architecture.md` §23. They are business
rules, so they were **not** decided here.

| # | Question | If yes (the proposal) | If no |
|---|---|---|---|
| **Q-9** | Is every factor to base ≥ 1 — is the stock unit always the smallest sellable unit? | a validation rule on `unit_conversions`, and `base_quantity` stays integral for counted goods | the ladder must accept factors below 1, `base_quantity` acquires repeating decimals, and the §3.2 rounding order needs re-deriving. Relaxing later is a validation change; tightening later is a data migration — which is why it is asked now |
| **Q-10** | Is `stock_unit_id` immutable once stock has moved? | a service-layer guard, nothing else | somebody must design and audit a restatement document that re-expresses every historical movement |
| **Q-11** | Are fractional base quantities legal when the base is a countable `each`? | `units.precision = 0` makes the writer **reject** rather than round | 0.5 of a tin exists, and both roundings are wrong answers to a question that should not have been asked |
| **Q-12** | Does `product_branches` need a `sales_unit_id`? | nothing now | an additive column on an already-replicated table, i.e. a resync of every terminal — cheap now, expensive after launch |

---

## Deliberately deferred

- **Live-weight scale hardware** → **FP-258**, Phase 6, through the existing print agent (owner U-2).
  In scope here: embedded-weight and embedded-price *labels*, PLU, and the parser. Out of scope: a
  scale streaming a live weight to the browser.
- **Repack implementation** → **FP-257**, last in Phase 2. It is the only row that reads a
  *maintained* average cost, so it cannot be built before the valuation formula it depends on.
- **Whether free goods dilute `avg_cost`.** A valuation decision, not a schema one. The document
  states that the quantity must arrive in the ledger (it is physical stock) and says in terms that
  the dilution question is not settled here. Settling it in a schema pass would have been a finance
  decision taken by the wrong role.
- **FIFO costing** — already deferred by §16 Q-3 and untouched.
- **The restaurant depletion posting rule** for `modifiers.linked_product_id`. The columns are added
  because the table is **replicated** and adding them later changes the replica payload; the posting
  rule belongs to the restaurant workstream and is left unspecified on purpose.
- **The `migrations` this design implies.** FP-230 and FP-250, not here.

---

## What implementation now owes — the ten widened rows

MEASURED: ten backlog rows carry a "widened by T-006" note. This is the map from this plan to them,
so whoever picks up FP-230 does not have to re-derive it from a 1435-line document.

| Row | What this task obliges |
|---|---|
| **FP-230** Catalog | `units.dimension` + the precision CHECK · `stock_unit_id`/`purchase_unit_id`/`sales_unit_id` · `is_weighable`, `price_mode`, `plu_code` · `barcode_rules` + the parser **in both engines** · **`UnitResolver` as the only caller**, with the five-step ladder and a reject at the bottom · the immutability guard (Q-10) · the static test that no `unit_id` exists below the document layer |
| **FP-231** Pricing + the shared engine | `price_list_items.unit_id`, the new unique, `min_qty` on base · the six-step ladder with a unit-qualified lookup and NULL-row fallback per step · unit resolution joins the shared PHP/TypeScript fixtures |
| **FP-233** Sales | the quad on `sale_items`, `unit_code` snapshot, `price_from_label`, `unit_cost` per **stock** unit with `line_cost = base_quantity × unit_cost` (D-57) |
| **FP-234** Stock in the sale transaction | **conversion resolves before `SELECT … FOR UPDATE`** — it reads two tables and can reject, and neither belongs inside the critical section · movements written in base |
| **FP-236** Till | scan resolves `product_barcodes` **first**, falls through to `barcode_rules` matched **in memory from the replica** · weighable lines entered by weight · embedded-price lines back-computed and flagged |
| **FP-237** Offline | `unit_conversion` and `barcode_rule` in the **first** replica payload — adding a replicated entity type later is a full resync · on arrival the line's own snapshotted factor wins and a mismatch opens a conflict (D-63) |
| **FP-250** Levels + ledger | the new idempotency key with the two generated columns · the base-only boundary on seven tables · the weighted-average formula with the zero-denominator guard · the three nightly invariants, check 3 **including free goods** |
| **FP-253** Adjustments/transfers/counts | the quad on three item tables · a count entered in the unit the human counted, variance base-only · the transfer's two quantity events (D-58) |
| **FP-254** Suppliers/requests/orders | line quads · `received_base_quantity` and a **base-to-base** `partially_received → received` transition · `quantity_incoming` maintained on approve/receive/close |
| **FP-255** Receipts/invoices/returns/payments | receive in a different unit from the order (two lines of one product on one receipt is the ordinary case) · price variance normalised to `line_total / base_quantity` on **both** sides · `purchase_price_history` written normalised |

Plus the two rows this task opened: **FP-257** repack, **FP-258** the scale.

---

## Test strategy

This task ships no test, because it ships no code. What it specifies is what the implementing rows
must prove, and it is recorded in `docs/database.md` §14 and `docs/architecture.md` §21 so it cannot
be lost:

- **Three static tests.** (1) No `unit_id`, `unit_factor` or `unit_code` column on any of the seven
  tables §6.5 names as below the document layer — the boundary is an **absence**, and an absence only
  stays true if something checks, which is the lesson of `QaVocabularyRenameSweepTest`. (2)
  `decimal()` without a precision is forbidden in a tenant migration. (3) Every `[UOM]` table has all
  four columns, with the one documented exception of C-5, because a line with `quantity` and no
  `base_quantity` is the exact shape of the v0.4 defect.
- **Shared fixtures in both engines.** Unit resolution and barcode parsing join tax, discount and
  rounding under the same fixtures. The quantity is an input to every number the engine computes, so
  a till that resolves a factor differently produces a different total from the same basket.
- **Three nightly invariants**, tested like any other rule, with check 3 the only one that spans the
  document boundary and therefore the only one that can see a unit bug.
- **The case that started this**: a receipt of 2 cases **plus** 3 loose singles of one product, which
  the v0.4 key rejected outright. It belongs in FP-255's tests as a named case, not as an edge case.

**What this task itself ran:** `QaVocabularyRenameSweep` only, four times during the work and once at
the end — MEASURED 5 passed, 35 assertions, 0.82 s. The full gate was run by the lead, not by me:
exit 0, Pint pass, PHPStan no errors, Deptrac 0 violations, Pest 314 passed / 1435 assertions,
134.75 s — unchanged, as a documents-only change should be.

---

## Risks & rollback

| Risk | Mitigation |
|---|---|
| **The highest-blast-radius item is the replica**, not the schema. A till that silently falls back to a global factor sells the wrong quantity, and an offline sale is **never rejected and never recomputed**, so the ledger is wrong forever | `unit_conversion` and `barcode_rule` are in the replica list and in `architecture.md` §12 from day one; unit resolution is in the dual engine under shared fixtures; §12.2 says the line's factor wins and a mismatch opens a conflict |
| **The weighted-average division** is the most dangerous arithmetic in the feature: a case at 4 800 for 24 must become 200 before it touches `avg_cost`, or the balance sheet overstates inventory 24× with no error and nothing to notice until a stock-take | the formula is written down in base units with its zero-denominator guard, and D-57 states both readings of `unit_cost` beside it |
| **The boundary erodes.** Someone adds `unit_id` to `stock_movements` "just for reporting" in the third sprint | the static test, and the §6.5 paragraph that says in terms what the column would cost |
| **The ledger key is the one expensive-later item**: a unique-index rebuild on an append-only table holding ~1.5 M rows per tenant per year, across every tenant database | done now, at zero rows |
| This is a documents-only change, so **rollback is `git revert`** of the nine commits | no data, no schema, no code depends on it yet. The real rollback risk is the opposite: implementing FP-230 against v0.4's silence |

---

## Questions for the human

Four, and they are Q-9 to Q-12 above — all four are business rules about how the owner's shops
actually work, none is answerable from the codebase, and each has its cost-of-being-wrong written
beside it. Q-9 is the one worth answering first: relaxing it later is a validation change, while
tightening it later is a data migration.

One thing the lead did not ask me to record, and that I think belongs here: **the CQRS direction is
still unrecorded in the repository.** It is now noted in the backlog thanks to this task, but there
is no architecture revision, and the three flavours of CQRS imply three different `docs/database.md`
designs. R3 — the snapshotted factor — happens to be correct under all three, which is luck rather
than design. The next schema decision may not be so lucky, and it should not be taken before the
flavour is pinned.
