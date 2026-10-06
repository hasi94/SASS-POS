# T-006 decisions — the unit-of-measure model

Two kinds of record in one file.

**Part 1** is the product owner's own decisions of 2026-10-06, written down here because
`docs/database.md` v0.5 cites them and a design document should not be the only place a business
decision lives. They are settled and are not to be re-litigated.

**Part 2** continues the autonomous-decision numbering at **D-57** (D-1 to D-56 belong to T-001 to
T-005): the questions the T-006 brief did not settle and that `architect` answered while writing
v0.5. Each is a design decision inside the owner's frame, not a business rule. The four questions
that *are* business rules were not decided here — they went to `docs/database.md` §16 as open
questions Q-9 to Q-12 and to `docs/architecture.md` §23, because answering them for the owner is
exactly the thing this file exists to prevent.

---

## Part 1 — owner decisions, 2026-10-06

The owner's question was whether the design had considered that the receiving unit and the selling
unit differ, and that this varies by industry. It had, partly: a per-product conversion table, a
purchase unit distinct from the product's other unit, and a pack quantity on the barcode. What it
did not have was any statement of what unit anything else was in. Three decisions followed.

**U-1 | Prices carry their own unit** (`price_list_items.unit_id`), rather than every alternate-unit
price being derived from the stock-unit price by the conversion factor. Reasons on record: the case
discount is near-universal; pharmacy *inverts* it, because a loose tablet is priced above one tenth
of a strip; derivation is self-contradictory under rounding, since a shop prices a case at 179 when
24 × 7.50 = 180 and no rounding rule reconciles those; and the unique key that would have to change
is on a till-replicated table, so changing it after launch forces a full resync of every terminal
in the field. Derivation survives as a declared fallback at the bottom of the price ladder.
Reversible: no — not in practice, once tenants hold price lists.

**U-2 | Weighable goods are in scope** for 1.0: embedded-weight scale labels, a PLU code, and a
`barcode_rules` parser. **Live-weight scale hardware is deferred to Phase 6** and will go through
the existing print agent rather than the browser. Reversible: yes for the hardware half.

**U-3 | Repack and break-bulk are in scope** as a real document (`stock_productions`), not as a
pair of offsetting stock adjustments. Reversible: yes.

---

## Part 2 — decisions made while writing v0.5

D-57 | Is `unit_cost` on a document line per **line unit** or per **stock unit**? The brief fixed
the valuation formula, which divides `unit_cost` by `unit_factor`, but four tables carry a column of
that name and two of them mean something else. | **Per document family, stated explicitly in both
places.** On an inbound/purchase line (`purchase_order_items`, `grn_items`, `purchase_return_items`,
`supplier_invoice_items`) `unit_cost` is per **line unit** — it is what the supplier charges for a
case. On an outbound **cost snapshot** (`sale_items.unit_cost`, `stock_production_items.unit_cost`)
it is a copy of `stock_levels.avg_cost` and is therefore per **stock unit**, so it multiplies by
`base_quantity`, not by `quantity`. | Forcing one reading would have been tidier but wrong in one
direction or the other: a purchase price per stock unit is not a number anybody types, and a cost
snapshot per line unit would have to be re-derived every time it is read. One column name with two
readings is how a 24× error gets written by someone who read half the design, so both readings are
now written beside the formula in §6.5 rather than left to be inferred. Reversible: yes.

D-58 | A transfer line records **two** quantity events (sent and received), and the `[UOM]` tag
defines one `quantity`/`base_quantity` pair. | `stock_transfer_items` carries `unit_id` and
`unit_factor` once, and the pair twice: `qty_sent`/`qty_received` in the line unit for display and
`base_qty_sent`/`base_qty_received` as the authority, with `qty_variance` in base. | The alternative
— two units on one line — would have let a transfer be sent in cases and received in singles with
two different factors on the same row, which is unreconcilable against one `product_id`. A unit
mismatch between dispatch and receipt is a receiving error and belongs in `variance_reason`, not in
the schema. Reversible: yes.

D-59 | Should `sales_order_items.delivered_quantity` / `invoiced_quantity` follow the brief's
purchase-order pattern (display unit plus a base column) or simply be base? | **Base, with no
second column**, and the asymmetry is explained in the row itself. | A purchase order is a document
the **supplier** reads, so its progress has to be reportable in the unit it was ordered in — that is
what the brief's `received_base_quantity` buys. Delivery and invoicing progress on a sales order is
internal, accumulates from several documents in possibly different units, and is never shown to a
counterparty in a specific unit, so a second column would be carried for nothing. Flagged rather
than hidden, because an unexplained asymmetry between two mirror-image tables reads as an
oversight. Reversible: yes.

D-60 | `grn_items.free_quantity` (supplier bonus goods) has no unit of its own. | It is in the
line's `unit_id` and converts with the **same snapshotted `unit_factor`** as the line. The posting
rule for whether free goods dilute `avg_cost` is **not** touched by this task. | Two cases free with
a ten-case line is one commercial fact in one unit; giving the bonus its own unit would invite a
second factor on one row for no observed requirement. Reversible: yes.

D-61 | `[UOM]` declares `unit_id NOT NULL`, but `supplier_invoice_items` has nullable `product_id`
for non-stock lines (freight, a service charge), and a row with no product has no stock unit to
convert into. | `unit_id` is nullable **on that table only**, and only where `product_id` is null.
The constraint is "a line with a product has a unit", not "every line has a unit". | The alternative
— a synthetic `each` unit on a freight line — would put a quantity of 1 freight into a product's
stock ledger arithmetic. Recorded because it is the one documented exception to a tag that is
otherwise absolute, and a static test checking the tag has to know about it. Reversible: yes.

D-62 | When is the factor on a `product_components` (bill of materials) row snapshotted — when the
recipe is written, or each time it is consumed? | **When the recipe row is written.** | Consistent
with the rule for every other line: the recipe states "250 g of flour", and re-sizing a unit must
not silently re-scale every recipe that mentions it. The cost is that a recipe written before a
correction to a unit keeps the old factor until someone edits the recipe, which is visible and
fixable; the alternative is invisible and is not. This one is **arguably the owner's**, not mine — it
is a business question about whether recipes track unit corrections — but it is reversible by a
backfill over a table with tens of rows per tenant, so it did not justify blocking. Reversible: yes.

D-63 | An offline sale arrives carrying a `unit_factor` that disagrees with what the server resolves
now. Which wins? | **The line's snapshotted factor wins**, and the mismatch opens a
`stock_conflicts` row. | This is the existing rule in §12.2, not a new one: a completed, paid sale is
a fact and is never rejected or recomputed. Re-resolving on arrival would change the quantity a
customer has already walked out with, and would do it silently. The mismatch itself is real
information — a replica that was stale at the moment of sale — and belongs in the conflict queue a
manager already works. Reversible: yes.

D-64 | `barcode_rules` needs a uniqueness rule, and the brief specified columns without one. | Unique
on `(prefix, length)` among active rows, with `priority` breaking the order of evaluation rather
than the uniqueness. | Two active rules matching the same prefix and length cannot both be right and
make the parse result depend on row order, which is the worst kind of intermittent bug: it changes
when someone adds a rule. `priority` still earns its keep for rules of different lengths that share
a prefix. Reversible: yes.

D-65 | The brief says an `embedded_price` scale label leaves the line "flagged", without saying
where the flag lives. | One boolean on the sale line: `sale_items.price_from_label`. | A flag with
no column is not a flag. Naming it costs one boolean on a table that is not replicated to the till,
and it is the only way to find the lines whose **quantity was computed from money** rather than the
other way round — which is what an embedded-price label does, and the thing a mis-configured scale
corrupts silently. Not added to `order_items`: a restaurant does not scan scale labels, and the
column can follow if one ever does. Reversible: yes.

---

## Part 3 — decisions made by `ba` while writing the spec (after the implementation)

D-66 | Which tables are "print-bearing" and so carry `unit_code`? `docs/database.md` §3.1 defines the
rule but does not list the set. | The spec checks only the three tables whose rows say so
(`sale_items`, `order_items`, `quotation_items`) and records that no other row mentions `unit_code`.
It does not assert that a printed purchase order or transfer note is missing one. | Asserting a gap
would be inventing a business rule; asserting nothing would hide the ambiguity. The finding goes to
the lead as F-7 instead. Reversible: yes.

D-67 | The static test "every `[UOM]` table has all four columns" (§14) cannot hold for the three
degenerate tags or the differently named quantity columns. Is that a defect or acceptable? | A defect in
the text, reported as F-3 and written as AC-53 failing until §14 or §3.1 names the exemptions. The spec
does not soften the criterion. | The instruction was to write the criterion anyway where something was
decided but not done. Reversible: yes.

D-68 | The brief says sixteen `[UOM]` tables; the text, counted, has seventeen. Which is right? | Neither
is chosen. AC-23 requires the documents to state one number or name the exclusion; F-1 reports it. |
Choosing would mean deciding which table is not a document-line table, and that is `architect`'s
design, not `ba`'s. Reversible: yes.

D-69 | `ba` had no shell and could not run `git diff 47e226d..HEAD`. How are criteria about what changed
handled? | They are marked "diff" and assigned to `qa`; every other criterion is checked against the current
text. | Reading the working tree is what `ba` could do; claiming a diff was inspected would be false.
Reversible: yes.
