# FlexiPOS — the domain core: goods in, stock, goods out

**Status:** normative · **Date:** 2026-10-14 · **Companion to:** `docs/patterns.md`

`docs/patterns.md` says *how the codebase is organised*. This says *what the business rules are* for the
three things the owner named as the main concern: **goods receipt, stock, and selling.**

It exists because these three are repeatedly treated as three features. They are **one pipeline with one
invariant**, and building them separately produces three independent sets of valuation bugs. The detailed
schema lives in `docs/database.md` v0.5; this document is the shape and the rules that schema serves.

---

## 1. The shape: a hard core with soft edges

```
   SOFT EDGE                     HARD CORE                    SOFT EDGE
   (customise freely)            (never customise)            (customise freely)

   How goods arrive                                            How goods leave
   ─────────────────                                           ────────────────
   GRN (case/strip/kg)  ─┐                             ┌─  Sale (any unit)
   Opening stock         ─┤                             ├─  Return
   Transfer in           ─┤    ┌───────────────────┐   ├─  Wastage
   Repack output         ─┼──► │   STOCK LEDGER    │ ──┼─  Repack input
   Adjustment up         ─┤    │   append-only     │   ├─  Transfer out
                         ─┘    │   BASE UNITS ONLY │   └─
                               │   every row       │
   unit → base conversion      │   balanced        │        unit → base conversion
   happens HERE, once          └───────────────────┘        happens HERE, once
   factor snapshotted                    │                   factor snapshotted
                                         ▼
                                weighted average
                                total_value accumulator
                                         │
                                         ▼
                                double-entry ledger
                                (one posting service)
```

**The hard core is three things and nothing else:** the stock ledger, the valuation formula, and the
posting service. These do not vary by customer, by industry, or by plugin. A request that appears to
require changing them is answered with **a new document type that posts to the same ledger** — never with
a change to the ledger.

**Everything else is a soft edge.** How a document is entered, in what unit, at what price, with what
barcode, printed on what template, is where all customisation lives.

---

## 2. The rule that makes industry variation possible

**Conversion happens exactly once, when a document line is written, and nowhere else. Below the document
layer there are no units.**

Decided in T-006, recorded as Decision 11 in `docs/architecture.md`. Restated here because it is the
single rule that lets unrelated industries share one ledger:

| Industry | Enters as | Base unit | Ledger sees |
|---|---|---|---|
| Pharmacy | strip of 10 | tablet | tablets |
| Supermarket | 1.250 kg | gram or kg | base quantity |
| Wholesale | case of 24 | piece | pieces |
| Textile | piece | piece | pieces |

So `stock_movements`, `stock_levels`, `stock_batch_levels`, `batches`, `stock_reservations`,
`purchase_price_history` and `journal_lines` get **no `unit_id` column, ever**. Their quantities are base
by definition.

**Every document line stores the factor it used** (`unit_id`, `quantity`, `unit_factor`, `base_quantity`).
Changing a case size next year cannot retro-change last year's receipt, and a rebuilt read model is
deterministic. This is the same shape money already uses — document currency, a snapshotted rate, a base
amount.

---

## 3. Goods receipt — where value enters, and the most dangerous document in the system

Everything a receipt gets wrong becomes **permanently and silently wrong** in the balance sheet.

| Rule | Why it matters |
|---|---|
| **Receive in any unit; convert at the line** | 2 cases + 3 loose pieces of one product is **two lines**. The idempotency key must permit it — keyed on the *line*, not the product |
| **Normalise cost to base before it touches valuation** | A case at 4,800 for 24 must become **200**. Miss this and inventory is overstated **24×** |
| **Free goods enter at zero cost and still dilute `avg_cost`** | QA measured the naive version counting free units at full cost: **+9,600 against the ledger on one receipt** |
| **Landed cost is apportioned across lines** | Freight and duty change `avg_cost`; they are not an expense sitting outside inventory |
| **Batch, expiry and serial are captured at receipt** | They cannot be reconstructed later |
| **Partial and over-receipt compare base to base** | Comparing document quantities makes the received/partially-received transition wrong whenever units differ |
| **A reversal is a new movement** | A supplier return never edits the original receipt. History stays reprintable |

**GRN must be built before selling.** Selling consumes `avg_cost`, and only receipts maintain it.

---

## 4. Stock — the ledger is the truth, levels are a cache

1. **Append-only. A movement is never updated.** A wrong quantity is corrected by posting a reversing
   movement. This is what makes audit and reprint possible at all.
2. **`stock_levels` is a projection.** It can be rebuilt from the ledger, and if the two disagree, the
   ledger wins. Treating levels as the truth is how drift becomes permanent.
3. **Weighted average per (product, location).** `total_value` is the **accumulator**; `avg_cost` is
   **derived** from it:

   ```
   INBOUND:   money_in     = ROUND(line.quantity × inventory_cost, 4)   -- paid only
              total_value += money_in                                   -- ALWAYS, incl. from negative
              on_hand     += |qty_moved|

   OUTBOUND:  line_cost    = ROUND(|qty_moved| × avg_cost, 4)
              total_value -= line_cost

   DERIVED:   avg_cost     = on_hand > 0 ? total_value / on_hand : avg_cost
   ```

   Never store `avg_cost` as the authority. Deriving it is what keeps rounding from accumulating.
4. **Replaying a document is harmless.** The idempotency key is
   `(reference_line_id, type, batch_key, serial_key)`, with **stored generated sentinel columns** so that
   NULL batch and serial values do not silently defeat uniqueness — which is precisely where replay
   protection was weakest, because most products are quantity-tracked.
5. **Negative stock policy is decided once, globally.** Not per customer, not per branch.

---

## 5. Selling — two consistency regimes, deliberately

- **Online: stock is validated and decremented inside the sale transaction.** Never oversell while
  connected.
- **Offline: a paid sale is a fact.** Accept it and reconcile afterwards. Never reject money that is
  already in the drawer. These two are not a contradiction; they are Decision 8.
- **Prices carry their own unit.** A case price of 540 is not 24 × a single price of 25. Pharmacy
  *inverts* the relationship — a loose tablet is priced above one tenth of a strip. Deriving alternate
  unit prices by factor cannot express a case discount and is self-contradictory under rounding.
- **`min_qty` is a volume break on base quantity and cannot substitute for a unit price.** `min_qty = 24`
  charges the case price to a customer picking 24 loose pieces off the shelf — which is what the case
  discount exists to prevent.
- **The receipt snapshots everything needed to reprint it** — unit code, factor, price, tax. Five years
  later the price list has changed; the receipt must not.

---

## 6. How customisation is added — a ladder, in strict order

Try each level in order. **Never skip down a level because it is faster.**

| Level | Mechanism | Cost | Use for |
|---|---|---|---|
| 1 | **Configuration** — settings, price lists, barcode rules, print templates, number sequences | no code | ~80% of requests |
| 2 | **Custom fields** — extension columns or indexed JSON | no per-customer schema change | "we track a supplier batch code" |
| 3 | **Plugin behind a hook** — named hook points, licensed per tenant | one plugin | "post to our own accounting system" |
| 4 | **Module** — new module with a published contract | one module | a whole industry: restaurant, pharmacy |
| 5 | **Fork** | — | ❌ **never** |

**The rule that protects the ladder:** no `if ($industry === ...)` in shared code. The established
supplier in this market ships four separate products across six industries, so a customer outgrowing one
migrates between codebases. One platform flexing by module is the structural advantage, and the first
industry conditional in a shared service is the start of the same divergence.

---

## 7. What will actually go wrong: valuation, not performance

A wrong `avg_cost` is invisible for months, and then the profit and loss is wrong with no way to say when
it started. The protections, in order of value:

1. **The formula is written once, in one place, and never restated.** A fact stated twice is a fact that
   will disagree with itself.
2. **Nightly invariants:**
   - `stock_levels.quantity_on_hand = SUM(stock_movements.quantity)` per (location, product)
   - `SUM(grn_items.base_quantity) = SUM(stock_movements.quantity)` per posted receipt per product
   - `balance_after` is a running sum (sampled)
3. **One existing invariant is structurally blind and must be labelled as such.** The batch-level check
   compares two numbers written from the same already-wrong source inside one transaction, so a unit bug
   passes it silently. Keep it, and **document that it is blind alone**, or someone will delete the real
   checks as redundant.
4. **A formula is not verified until someone has run it.** On T-006 three arithmetic claims changed the
   moment QA executed them, including one that had been relayed twice as settled.

---

## 8. Build order, and why it is load-bearing

1. **T-008** — master schema, tenant baseline, per-tenant migration runner. *In progress.* Nothing can be
   applied to a tenant without it.
2. **Catalog and units** — the base unit, the resolver, the snapshot contract, barcode rules. The
   conversion boundary must exist before anything writes a quantity.
3. **Unit-aware pricing** — before the till, because the unique key on a till-replicated table cannot be
   changed after launch without a full resync of every terminal.
4. **The stock ledger** — the idempotency key, the valuation formula, the invariants. No unit columns.
5. **Goods receipt** — maintains `avg_cost`.
6. **Selling** — consumes `avg_cost`.
7. **Adjustments, transfers, counts**, then purchase orders and supplier invoices.
8. **Repack / production last in its phase** — it is the only document that *reads* a maintained
   `avg_cost`.

**The order is not a preference.** Steps 2 and 3 precede 4 because the till replica and the dual
calculation engine need the conversion contract before the ledger key is cut. Step 4 precedes 5 and 6
because every write path posts through it.

---

## 9. The two tests that outlive this document

Everything above is prose until something enforces it. Two static tests do the work:

- **No `unit_id` column on `stock_movements`, `stock_levels`, `stock_batch_levels`, `batches`,
  `stock_reservations` or `journal_lines`.** Section 2 made executable. Negative control: add the column
  in a scratch migration and the test must go red.
- **Any tenant table with a quantity-family column carries the full unit quad**, unless it is on an
  explicit base-only allowlist. Run against the documented schema before T-006 it named **18 tables** —
  it is the single test that would have caught the entire defect class, and it keeps catching it for
  every table added over the next two years.

Both must be **red before they are green**, against a scratch migration that violates them. A test that
passes against today's schema proves nothing.
