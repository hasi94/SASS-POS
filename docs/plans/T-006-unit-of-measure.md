# Plan: T-006 The unit-of-measure model

Spec: `docs/specs/T-006-unit-of-measure.md` · Decisions: `docs/decisions/T-006-unit-of-measure.md`
(owner U-1 to U-3; **D-57 to D-65** and **D-70 to D-80** mine, D-66 to D-69 `ba`'s) · Covers **FP-243**, gates **FP-230**, widens ten rows, opens
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
| 6.10–6.15 | **eight** of the eleven bare `DECIMAL`s given precisions — the other three sit in §6.3 `product_barcodes`, §6.4 `price_list_items` and §6.5 `stock_movements`, fixed with those rows; `journal_entries.source_type` += `stock_production` |
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
D-56, with **D-70 and D-71** appended after QA run 1 and D-64's mechanism corrected in place.

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
`sale_items.price_from_label` · `modifiers.linked_quantity` / `linked_unit_id` · eleven bare `DECIMAL`
columns given explicit precision.

**The ledger key**, which is the one piece of this that would be expensive later:

**`docs/database.md` §6.5 is the normative declaration of this key; the block below is a copy.** It is
kept because a plan an implementer reads should show the shape, and labelled because **four of the
seven places this key was written went stale** when round 1 added `product_id` (review round 2 R2-1,
FP-181). A copy that does not say it is a copy is the defect.

```sql
+ reference_line_id CHAR(26) NOT NULL                                         -- the line that caused the movement
+ batch_key  CHAR(26) GENERATED ALWAYS AS (COALESCE(batch_id,  '-')) STORED
+ serial_key CHAR(26) GENERATED ALWAYS AS (COALESCE(serial_id, '-')) STORED
  unique (reference_line_id, product_id, type, batch_key, serial_key)
```

`reference_type` leaves the key; `(reference_type, reference_id)` stays a non-unique index;
`batch_id` demotes to a plain indexed column. **`product_id` stays**, because one line can move
several products — a composite's components, a depleting modifier, an opening load — and §6.5 now
carries a row per movement type saying what `reference_line_id` names, plus the rule that a line
writes at most one movement per `(product_id, batch_id, serial_id, type)` with duplicates aggregated.
Dropping `product_id` was the one design regression this task introduced; see "Found by review, not
by me". MariaDB 10.4 parity holds because §14 already records
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
- [x] **Step 10 — this plan document**, after the lead observed that the reasoning existed nowhere a
      reviewer, a QA pass or a later agent could read it.
- [x] **Step 11 — five defects from `ba`'s spec pass** (F-1, F-2, F-3, F-6, F-7). Every one is a
      **count or a scope stated in prose that nobody checked against the thing it describes**: the
      tag census said seventeen in one place and sixteen in three; the Catalog index row was not
      raised when `barcode_rules` was added; "all four columns" could not pass; "print-bearing" was
      never enumerated; and `product_barcodes.unit_id` was nullable in the table row but not in the
      note. The same shape as the three I had just caught in the brief, now in my own output — which
      is the argument for FP-176 being a cross-reading obligation rather than one role's discipline.
- [x] **Step 12 — QA run 1 (FAIL: 65/67, one major, seven minor)**, fixed in `13cc57f` and this
      file. See "Found by QA, not by me" below; the major one is the only defect on this task that
      was a **design** error rather than a count or a citation.
- [x] **Step 13 — review round 1 (CHANGES REQUESTED: 4 Critical, 11 Warning, 7 Suggestion)**,
      fixed in `5d5074c`. See "Found by review, not by me"; R-1 is the only **regression** on this
      task, and the fix round introduced it rather than the original pass.
- [x] **Step 14 — QA run 3 (FAIL: one major, two minor)**, fixed in `b52301a`. BUG-10 is the
      W-10 fix from step 13 being wrong in the opposite direction; see below.
- [x] **Step 15 — QA run 4 (FAIL: one major, one minor)**, fixed in `f442cd1`. BUG-14 is a shape
      none of the earlier four covers; my framing of it is below.
- [x] **Step 16 — QA run 5 (three minor), the last fix round.** Fixed in `903b89d`. Scoped by the
      lead to four items with everything else to rows, on the reasoning that the four majors are
      closed, nothing is implemented, and a 1 400-line design document absorbs polish
      indefinitely. The defect count across five rounds went 9 → 4 → 3 → 2 → 3.
- [x] **Step 17 — review round 2 (CHANGES REQUESTED: 2 Critical, 4 Warning, 1 Suggestion)**, fixed
      in `bfe9677`. Both Criticals are the same shape as each other and as FP-181: **a corrected fact
      that did not reach every place it was stated.** R2-1 was round 1's own fix missing from the
      key's normative declaration; R2-2 was a rule asserted in the formula and contradicted by three
      table rows, with no sentence anywhere mapping the eighteen movement types to the two branches.
      D-78, D-79, D-80.
- [x] **Step 18 — QA run 7 (FAIL: two correctness, two minor), plus a consolidation.** Fixed in
      `f61322c`. The two correctness defects were both in the branch table I added in step 17 — one
      round after naming the shape that produces them — so the round's real work was **merging the
      two tables into one**, with the long reasoning in numbered notes. See "The seventh home, and
      the edit that never landed".
- [x] **Step 19 — QA run 8 (one correctness, two minor).** Fixed in `HASH`. BUG-27 withdrew a
      claim rather than building the mechanism behind it: my note 9 said a short transfer receipt's
      shortfall "stays at the `transit` location", and the schema cannot express that — one
      `transfer_out` and one `transfer_in` per line, nothing crediting transit, and a two-hop shape
      collides on the idempotency key, which has no location column. **A note that answers a question
      outside its task's scope is worse than a note that names the question**, because the first gets
      implemented and the second gets decided. That is the lead's formulation and it is the one I
      would keep; it is also the argument I used myself in D-73 and then failed to apply one round
      later, to a question whose candidate fix touches the very key this task spent four rounds
      stabilising. D-82.

---

## Corrections to the brief, with the measurement

A plan that hides where its brief was wrong teaches the next reader nothing. All five were reported
before the gate ran; all five were accepted.

**C-1 — the table count is right against the document and wrong against the tables.** MEASURED: the
§6 domain index **summed to 189** before this task, while a literal count of the tables named in §6
gives **193 before → 196 after**. I implemented the briefed figure (≈190 → ≈193) and did **not**
silently re-denominate, because §10's whole arithmetic and the backlog row both rest on it, and
§10's table models a rounded **200 tables per tenant** (190 core + 10 plugin) whose
20 000/40 000/60 000 figures would all move at 203. I added one sentence saying the table keeps its
rounded model. Filed as **FP-177**.

**C-1a — and my first explanation of *why* the index was low did not survive being checked, which is
the same defect twice.** I originally named four low rows and attributed them to multi-table rows
("`currencies / exchange_rates`" and friends counted once). `ba`'s spec pass tested that against
Catalog and found Catalog has no multi-table row at all; re-measuring every domain shows the
attribution is wrong for three of the four. The real picture, counted three ways per domain:

| § | Index says | Markdown rows | Tables named | Mechanical explanation |
|---|---|---|---|---|
| 6.3 Catalog | 13 → **14** (fixed) | 14 | 14 | **none** — no multi-table row exists here. The declared 12 was simply one short of 13, and **T-006 then added `barcode_rules` without raising the row**, so this task carried the defect forward for one pass |
| 6.10 Finance | 19 | 17 | 20 | **none.** It has *three* multi-table rows; counting each once gives 17, not 19, so "rows, not tables" over-explains and the real figure sits between the two |
| 6.16 Notifications | 6 | 6 | 7 | **yes** — the only domain the original story fits: one multi-table row (`webhook_endpoints / webhook_deliveries`) counted once gives exactly 6 |
| 6.21 System | 8 | 7 | 9 | **none.** Its one three-table row counted once gives 7, not 8; 8 is what you get by counting 9 tables and not counting Laravel's own `migrations` |

MEASURED after this pass: **17 of 21 domains have declared == tables**, the index sums to **193**
(equal to the figure §6 and §10 both state), and **three** domains remain one low — Finance,
Notifications and System. Those three are FP-177's whole remaining scope. The lesson I would keep:
"four numbers are wrong" was right and cheap to verify; "and here is why" was a story fitted to one
example and never checked against the other three, which is exactly the shape of the defects I had
just finished reporting in the brief.

**C-2 — "the approved CQRS plan" could not be cited.** MEASURED: `grep -rni cqrs docs/` returns
**nothing**. The only record is the owner's directive of 2026-10-06, and the flavour — code-level
separation, projections, or full event sourcing — is not pinned, which matters because the three
imply very different schemas. So I wrote the dependency as a property of **any** read model (a
projection replayed with a read-time factor re-quantifies history on every rebuild), attributed the
direction to the owner with its date, and noted that the architecture revision is still owed. I did
not use the brief's term "claim-mark projection", which I could not source. This is the finding that
this plan file exists because of.

**C-3 — there are **eleven** bare `DECIMAL`s, not five, in nine table rows.** Corrected after QA run 1
(BUG-7): this plan first said ten, and ten is wrong. MEASURED on the base document,
`git show 47e226d:docs/database.md | grep -coE 'DECIMAL([^(]|$)'` = **11**, in these nine rows — the
five the brief named (`product_barcodes.quantity`, `price_list_items.min_qty`,
`payslip_lines.quantity`, `journal_lines.tax_base`, `statutory_rates.floor`), plus
`expense_categories.approval_threshold` and `budget_monthly`, `salary_structure_components.amount`
and `percent`, `workflow_instances.amount`, **and `stock_movements.quantity DECIMAL signed`** — which
is the one I fixed without counting, because it was already in front of me as part of rewriting that
row for the new idempotency key. So the deliverable was complete and only the arithmetic about it was
not: all eleven are fixed, and `grep DECIMAL | grep -v 'DECIMAL('` now matches prose only.
The signed-quantity column on the stock ledger is the one I would least have wanted to leave as
`DECIMAL(10,0)`, which is a fair argument for counting a sweep rather than totting it up from a list.
Related and deliberately **not** changed: `employee_salary_components.amount`/`percent` and
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

## Found by QA, not by me

Run 1 was a FAIL: 65 of 67 criteria, one major and seven minor. All eight are closed in `13cc57f`
and in this file. The one worth reading is BUG-1, because it is the only defect on this task that was
a **design** error rather than a count, a citation or a scope:

**I reintroduced, in §6.4, the exact defect I had just finished documenting in §6.5.** The price key
was specified `unique (price_list_id, product_id, unit_id, min_qty)` over a **nullable** `unit_id`, so
two rows both meaning *priced per stock unit* for one list, product and break were both legal and row
order decided which one priced a sale. Three sections earlier, this plan's own text calls that NULL-
distinctness hole "the second bug, and the worse" of the two in the v0.4 ledger key, and fixes it with
a generated `COALESCE` column. I wrote the diagnosis and the cure, and then did not apply either one
section later — in the one table the POS replicates to every till, whose unique key cannot be changed
after launch without the resync that is the entire reason U-1 was decided now rather than deferred.
QA found it by applying the document's own reasoning to the document, and also found the same shape on
`unit_conversions`, which ladder step 2 had just made load-bearing.

The fix was not two patched keys. The pattern is named once in **§3.3** as a convention — a nullable
column whose `NULL` is a *meaningful value* gets a stored generated sentinel, and the key is built on
the sentinel — and cited from all three sites, plus its mirror at `barcode_rules.active_prefix`, where
NULL-distinctness is the mechanism rather than the bug. Four columns, three sites, one rule, and the
next nullable unique key somebody designs has somewhere to look. **The lesson I would keep:** a
defect I have just written a paragraph about is not thereby a defect I will notice; the paragraph
needs to become a rule in the conventions section, where the next design reads it, or it protects
only the table it was written about.

The other seven: `unique ... where active` could not be built on either engine (BUG-2, so D-64's
ruling stands and its SQL does not); §9.4's one-step prohibition is about renaming a **table**, not a
column (BUG-3, AC-52); the immutability paragraph had no pointer to Q-10 (BUG-4, AC-63); the valuation
formula guarded a zero denominator when the real hazard is the **negative** on-hand this design
explicitly allows (BUG-5); nightly check 1 did not say whether the two reservation types are summed
(BUG-6, which also settled QA's N-4 as D-71); `refunded_quantity` had no stated unit (BUG-8, D-70 by
D-59's existing rule); and this plan's bare-`DECIMAL` count was wrong (BUG-7, C-3 above).

Also closed from QA's notes rather than its bug list: `architecture.md` §7 attributed "190" to a §10
that now computes 193 — the new number going stale in the very sentence that fixed the old "150".

## Found by review, not by me

Round 1 was **CHANGES REQUESTED**: four Criticals, eleven Warnings, seven Suggestions — every Critical
a single sentence, three of the four in §6.5. The reviewer's verdict on the model was that it holds;
the defects were on sentences. Closed in `5d5074c`. The one worth reading:

**I dropped `product_id` from the ledger key and argued only the column next to it.** The prose
justified removing `reference_type` (a ULID is globally unique), said `product_id` and `batch_id`
"demote to ordinary indexed columns", and then argued only `batch_id`'s demotion — which `batch_key`
genuinely covers. `product_id`'s was never argued, and it was wrong: this document describes **three**
cases where one line moves several products, and two of them could not have been saved at all. A
composite or recipe depletes its components from one `sale_items` line; a **depleting modifier** adds
another product against the same line, from the `linked_quantity`/`linked_unit_id` columns *this task
added*; and an opening load writes one movement per `stock_adjustment_items` row — which also makes
"`opening` is header-level" wrong one section after the adjustment schema says otherwise. **The v0.4
key handled all three**, because it carried `product_id`. So the round that closed BUG-1 opened a
regression of the same family: a unique violation on every restaurant recipe and every kit.

**The lesson is narrower than BUG-1's and worth keeping separately:** when a column leaves a key, the
argument has to be made *for that column*, not for the key. I wrote one argument covering two
removals and checked it against one of them. A list of what a key no longer contains is a list of
claims, and each needs its own sentence — which is now what §6.5 has, as a row per movement type.

The other three Criticals: the snapshot protected the original document and not the one that reverses
it, so a return re-resolved the factor and could remove a different quantity than the receipt put in
(**R-3** — and the reviewer is right that **D-58 already derives this rule for the harder case**, so I
had it where it was hardest and never asked it on the easy one); the valuation formula averaged
`unit_cost` while §7.2 debits inventory at **landed cost**, which would have diverged the stock ledger
from the general ledger by the freight on every receipt, permanently (**R-4**, D-72); and "one event
per line per type" was still assumed for `correction` and `transfer_in` after D-71 had found exactly
that bug for the reservation pair (**R-2**, now a table of all eighteen types, D-73).

**One place I did not take the reviewer's reading.** R-2 proposed that a transfer line can be received
in instalments and the key must separate them. The header carries a single `received_at`/`received_by`,
so the schema's own columns say receiving is **one event**, and `partially_received` means *some lines
short*. Instalment receipts would need a receiving document this schema does not have, and inventing
one inside a unit task is how a second undiscussed design lands in a document nobody re-reads. Named
as a gap for FP-253 instead (D-73).

**And the W-10 fix in that same commit was wrong, which QA run 3 caught.** I wrote that the formula
"does dilute" `avg_cost` with free goods — and then defined its input as `base_qty_in = paid + free`
used in the **numerator as well as** the denominator, which is not dilution at all: it books cost
nobody was charged, leaves the average unchanged at 200, and overstates `total_value` by 9 600 on a
single ten-case receipt. **It broke the R-4 reconciliation added four paragraphs earlier, in the same
commit.** The fix is one term in one place — the free quantity belongs in the denominator only — and
§6.5 now carries the two readings as a worked table against the general ledger debit, because the
claim is checkable in four numbers and my sentence about it was not. Third lesson of the same shape:
**a formula is not a claim until it has been run.** I asserted a behaviour and specified its
opposite, in a paragraph whose own next sentence was a reconciliation the opposite breaks.

**QA run 4 then found a fifth shape, and it is not any of the four above.** The `on_hand <= 0` guard
was QA's own BUG-5 suggestion, written when `avg_cost` was the thing computed and `total_value` was
derived from it; under that reading, resetting the prior layer protected a derived average from a
nonsensical weighting and was correct. **D-76 then made `total_value` the authority** — also correct —
and the same guard became a rule that discards a real balance: from on-hand −5 with `total_value`
−500, a receipt of 10 at 100 left `total_value` at 1 000 against an inventory account of 500, and
derived an `avg_cost` of 200 where every unit had cost 100. Nothing was overclaimed, no count was
wrong, no citation went unopened, no true paragraph failed to travel, and **neither fix was wrong when
it was written.** The defect was in the *pair*.

**My framing, offered because the lead asked for a better one than "re-read every earlier fix":**

> **A guard belongs to the number it protects, not to the formula it was written in.** When a formula
> is re-expressed, its guards do not move with it — they stay where they were typed, now protecting
> whichever expression happens to sit there.

And the structural cause is narrower and more useful than vigilance: **`avg_cost` and `total_value`
hold the same information.** Any two columns that must agree have an authority question, and *every*
rule touching either one depends on the answer — so naming an authority is not documentation, it is a
change to every one of those rules. v0.4 looked as though it had named one, writing
`total_value (= on_hand × avg_cost, maintained)`, but that declares a **formula**, not a **direction**,
which is exactly why the question could be answered twice, differently, three rounds apart.

**The sweep the framing implies was then run, and found two more instances — three on one task.**
QA swept for redundancy on the lead's request and found `stock_productions.input_value` /
`output_value` / `wastage_value` against the item rows' `value`, with §6.5 asserting
`SUM(output value) + wastage_value = input_value` and naming neither side. Ruled as **D-77**: the
item rows are the authority, forced by the design's own residual rule, since the residue is
absorbed on a *line* and a header can only be the sum of lines. So the general rule now has three
witnesses and can be stated plainly: **an identity between a summary and its detail needs a
declared direction, or it gets answered twice.**

That makes the check cheap and bounded rather than a habit: **when you declare an authority between
redundant columns, list the rules that touch either column and re-read that list.** Here it was four
items long — the formula, the guard, the `stock_levels` row and the outbound update — and the fourth
was not written down at all until QA asked for it. A list of four is a five-minute check; "re-read
every earlier fix" is a resolution nobody keeps.

**Review round 2 then found the sixth shape, and it is the one this task will be remembered for.**
Both Criticals were *a corrected fact that did not reach every place it was stated.* R2-1: round 1's
own fix — putting `product_id` back in the ledger key — was present in the SQL block and absent from
the **table row**, which is the normative declaration because every other key in this document is
declared in its row, and absent from §11, which is the second place an index-builder reads. The key
was stated seven times and four of them were stale, so the Critical I had closed was still live at
both sites where it would actually be implemented. R2-2: the inbound branch asserted every inbound
cost is per line unit and **cited D-57 for a reading D-57 gives the opposite of**, while three table
rows said otherwise and no sentence mapped the eighteen movement types to the two branches.

**What makes this shape different from the other five:** the others are defects of *authorship* —
something was overclaimed, miscounted, unopened, or settled twice. This one is a defect of
*distribution*. The fact was correct, known, and written down; it simply did not travel to the other
places that state it. And the cost is higher than any of the others, because an implementer who
follows the document **exactly** produces the error — 4 600 understated on every multi-unit refund,
95.8 % of the value of an intra-account transfer gone with no journal entry to notice.

**The check is structural and cheap: a fact that is stated more than once has to be stated once.** The
fix for R2-1 was not to correct four sentences but to delete three of them, leaving §6.5 as the only
place the key's columns appear — FP-181's own remedy applied to the artefact that filed FP-181. The
fix for R2-2 was the same move: one table, eighteen rows, one rule, and no second place to disagree
with it. **Where this task used a single source, nothing went stale; every one of the six shapes
happened in a fact that had two homes.**

## The seventh home, and the edit that never landed

Two things from QA run 7, and the second is worse than the first.

**The branch table became the eighteen types' fourth home, and both of that round's correctness
defects were in it.** I added it to fix R2-2 — one round *after* writing "a fact that is stated more
than once has to be stated once" — and I split it from the identity table because the rows were
already 200–400 characters. That reason was sound about **width** and wrong about **structure**: notes
beneath a table solve width without a second home, and I did not consider them. The fix is one table
for all eighteen with numbered notes, every other mention citing it, and **the prose counts stopped
counting** — "sixteen of the eighteen" is now "every type except the two reservation types, per the
table". Prose that counts is the subtlest of the four homes: it reads as narration rather than
duplication, and it goes stale the first time an enum changes. D-81.

Two of the six rulings in that table were also wrong, and one is worth keeping: **`count` is the only
item table in §6 whose `[UOM]` quantity is not the quantity that moves.** `counted_quantity` is the
total counted; the movement is `difference`. Every other ruling I made transferred from the uniform
rule; that one could not, because the premise it rested on — "the line's quantity is what moved" — is
false for exactly one table. **A uniform rule needs a check against the one row that defines its
terms differently**, and the way to find that row is to ask, for each table, *what does this line's
quantity mean?* rather than to apply the rule and see if it fits.

**And the one I have to report rather than bury: two of review round 2's fixes never reached the
file.** My edit script hit an assertion on a pattern that had already changed, exited, and wrote
nothing — so four edits were lost, including W-3 and W-5, and I reported all of them as closed on the
strength of the script's earlier assertions passing. The decisions file ended up carrying **D-80, a
record of a ruling that was not in the schema it ruled on.** Found only because this round sent me
back into the same paragraphs.

That is the sixth shape again — a verification performed against the wrong artefact — and this time
the wrong artefact was *an assertion about my own intent* rather than the document. **An edit is not
done when the tool reports success; it is done when the artefact contains it.** The practice that
follows is one line and I now use it: after any scripted edit, grep the file for the new text, and
treat a zero count as a failure to investigate rather than a pattern to retype. It is also why a
decision record must never be written in a separate step from the change it records — if the change
fails, the record becomes a confident description of something that does not exist.

Of the eleven Warnings, **W-1 is the one I would want remembered**: nightly check 3 adds
`free_quantity`, which had no type, no `NOT NULL` and no default — so `base + ROUND(NULL × f, 4)` is
NULL, `SUM` skips the row, and the check fails on **every** receipt line without a bonus. That is the
cry-wolf argument I had just written, inside my own fix for a cry-wolf defect, two rounds running.

## Decisions

Nine decisions the brief did not settle are recorded in
`docs/decisions/T-006-unit-of-measure.md` as **D-57 to D-65**, plus **D-70** (`refunded_quantity` is base, by D-59's rule) and **D-71** (a reservation's `reference_line_id` is its own reservation row) added after QA run 1. They are not restated here. The three a
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
- **How free goods are *presented*, not how they are valued.** This started as a deferral — "a
  valuation decision, not a schema one" — and the deferral did not survive, because the formula had
  to say what its own input was and therefore decided the question either way (review W-10, then
  QA BUG-10). It is now settled: **free units enter at zero cost**, because that is the only reading
  under which `total_value` equals the inventory account the receipt debited. The arithmetic is in
  §6.5 as a two-row table so the next reader can check it rather than trust it. What is still deferred
  is presentation — whether the benefit is also disclosed as a purchase discount received — which
  changes no column and no total (D-74).
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
| **FP-250** Levels + ledger | the new **five-column** idempotency key with the two generated columns, and the §6.5 table of what `reference_line_id` names per type · the base-only boundary on seven tables · **the branch table**: every movement type's branch, cost column and basis, with `money_in` multiplying the *line* quantity only where the cost is a quoted price and `base_quantity` where it is a copy of an average (D-78) · **`total_value` as the accumulator and `avg_cost` derived from it** — `+ money_in` and `− line_cost`, matching what §7.2 debits and §7.1 credits, accumulating through a negative balance, with the guard on the *derivation* and the zero-stock residue written off through the inventory-adjustment account (D-76) · the three nightly invariants, check 3 **including free goods** |
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
  `decimal()` without a precision is forbidden in a tenant migration. (3) The `[UOM]` tag is
  **complete wherever it is claimed** — a unit column on all seventeen tagged tables, plus
  `unit_factor` and one base column per entered quantity column on **fourteen** of them, with the
  **three** that cannot pass exempt **by name** (review S-2: `product_components` is master data but
  passes unaided, and exempting a table that would pass is how an exemption list becomes a list of
  tables nobody checks). §14 carries the exemption list in
  full. It is written that way because the first draft specified it as "all four columns on every
  tagged table", and MEASURED, that would have failed on **five of the seventeen** on the day it was
  written (`price_list_items`, `product_barcodes`, `modifiers` for the degenerate tag;
  `stock_count_items` and `stock_transfer_items` because their entered quantity is not called
  `quantity`). A criterion specified so that it must fail is worse than none, because the first
  person to implement it weakens it to green (D-67).
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
| **The inventory arithmetic** is the most dangerous in the feature. After QA run 5 the money side is an identity (`money_in` comes from `quantity × inventory_cost`, not from a rounded per-base cost), so the 24× error has exactly **one** home left: adding `line.quantity` to `on_hand` instead of `base_paid + base_free`, which puts 48 000 against 10 units instead of 288 and derives 4 800 instead of 166.6667 — with no error and nothing to notice until a stock-take | the formula is written down in base units with its zero-denominator guard, and D-57 states both readings of `unit_cost` beside it |
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
