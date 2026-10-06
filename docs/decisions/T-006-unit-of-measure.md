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

**D-64 mechanism corrected, 2026-10-06, after QA run 1 (BUG-2).** The ruling above is unchanged and
still right; the *mechanism* I wrote for it does not exist. "Unique among active rows" was written as
`unique (prefix, length) where active`, and **neither MySQL 8.4 nor MariaDB 10.4 has a partial or
filtered unique index**, so that constraint could not be created. It is now a stored generated column
`active_prefix = IF(is_active, prefix, NULL)` with `unique (active_prefix, length)`, which is the
mirror of the §3.3 sentinel pattern: NULL-distinctness is the *problem* in three other keys and the
*mechanism* here, letting any number of retired rules share a prefix while only one live rule claims
it. Recorded rather than rewritten, because "the decision was right and the SQL was not" is a more
useful thing for the next reader to find than a clean entry.

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

D-70 | `sale_items.refunded_quantity` sits on a document line, so §6.3's "in the stock unit unless the
line says otherwise" does not reach it, and no row said which unit it was in. A line sold as 2 cases
with one single refunded has two readings. QA raised it as BUG-8. | **Base**, by the same rule as
D-59, and stated in the row. | It is a **rollup**, not an entered quantity: it accumulates from refund
documents that may each be written in a different unit, which is exactly the test D-59 applied to
`sales_order_items.delivered_quantity`. Applying the existing precedent rather than inventing a second
rule also keeps §14's completeness list unchanged, since a rollup is not an entered quantity and needs
no unit of its own. Reversible: yes.

D-71 | The new ledger key contains `type`, so a reservation that is taken, released and taken again
against one line would collide on `(reference_line_id, 'reservation', '-', '-')`. QA flagged it as a
residue for FP-250 (note N-4). | For `reservation` and `reservation_release`, `reference_line_id` is
the **`stock_reservations.id`**, not the ordering line's. | Three reservations are three rows with
three ULIDs, so the key separates them with no counter and no extra column, and a release carries its
own reservation's id under a different `type`. It is still "the line that caused the movement" — for a
reservation the reservation *is* the line. The alternative, excluding the two types from the unique
key, would remove replay protection from the only movement types a sync retry is likely to duplicate.
Settled here rather than left to FP-250 because the key is this task's deliverable. Reversible: yes.

D-72 | Which cost does the weighted average consume? §7.2 posts **Dr Inventory at landed cost** while
the formula averaged `grn_items.unit_cost`, and `stock_levels.total_value` is the balance-sheet
figure. Raised as review R-4. | **The inventory cost**: `grn_items.landed_cost` on a receipt,
`unit_cost` on any other inbound movement that has no apportionment. **`landed_cost` is per line
unit**, exactly like `unit_cost` beside it, so `money_in` multiplies it by the **line** quantity and
the unit factor never touches the money side at all (corrected here after QA run 5 — this entry
originally said it "divides by `unit_factor` too", which was the per-base expression BUG-16 removed).
And the reconciliation is stated: `SUM(stock_levels.total_value)` equals the balance of the inventory account
those products resolve to. | Averaging supplier price while the ledger debits landed cost diverges by
the freight on **every receipt, permanently**, with nothing in the design to reconcile them — two
inventory values, both called inventory. D-57 ruled on `unit_cost` across four tables and never
reached the column that actually values inventory, which is the same "one name, two readings" trap
one column to the left. Reversible: yes, but not after a tenant has posted receipts.

D-73 | Can one `stock_transfer_items` line be received in instalments? The header's status enum names
`partially_received`, the line has one `qty_received` pair, and the ledger key would collide on a
second `transfer_in`. Raised as review R-2. | **No: receiving a transfer is one event per line.** The
header carries a single `received_at`/`received_by`, so `partially_received` means *some lines short
or not arrived*, with the shortfall in `qty_variance`. | The alternative is to invent a receiving
document the schema does not have, and inventing one inside a unit-of-measure task is how a second
undiscussed design lands in a document nobody re-reads. Named as a gap for FP-253 instead. The
reading also matches the columns that exist: one `received_at` on the header is one receiving event.
Reversible: yes.

D-74 | `base_qty_in` in the valuation formula was undefined, and nightly check 3 forces it to include
the free quantity, so the formula silently closed the free-goods question D-60 left open. Raised as
review W-10, **then corrected after QA run 3 (BUG-10) — the first answer was wrong, and this entry
is rewritten rather than appended to, because the version below is the rule and a reader following
the old one would overstate inventory.** | **Free units enter stock at zero cost.** The free quantity
moves the **quantity** and not the **money**: `money_in` is `ROUND(line.quantity × inventory_cost, 4)`
on the **paid** quantity in line units, while `on_hand` takes
`base_quantity + ROUND(free_quantity × unit_factor, 4)`. (This entry first wrote the money as
`base_quantity × cost_per_base`; that is the per-base expression **BUG-16** removed, because
re-multiplying a rounded quotient leaves 99.9999 against a debit of 100.0000. Corrected here after QA
run 6 flagged that an implementer reads *this* record for the reasoning behind the formula, so a
stale expression here is wrong code rather than an inaccurate sentence.) `avg_cost` falls and
`stock_levels.total_value` stays equal to the inventory account the receipt debited. | **The test is
arithmetic, not preference.** Ten cases at a landed 4 800, two free, 24 per case, from zero stock: the
ledger debits 48 000. Free-in-the-denominator-only puts **48 000** into `total_value` and derives
`avg_cost` = 166.6667 from it; free-at-full-cost puts in **57 600** and derives 200.00 — overstating
inventory by 9 600 on a single receipt, permanently, against the very reconciliation D-72 had added
one paragraph earlier. (This entry first said "166.6667 × 288 = 48 000 exactly", which is the
overclaim **D-76** was written to correct, surviving in the neighbouring record — QA BUG-13. The
product is 48 000.0096; the tie is exact because `total_value` is accumulated, **not** because
multiplying the rounded average back out reproduces it.)
My first answer said free goods "dilute" and then wrote a formula that put the free quantity in the
**numerator as well**, which is not dilution: it books cost nobody was charged. So the honest naming
is by balance-sheet effect — zero cost ties, full cost overstates — and full cost is therefore a
**defect, not a second option a tenant might prefer**, which is what the superseded version offered.
What is genuinely still open is **presentation**: whether the benefit is also disclosed as a purchase
discount received rather than absorbed into the cost of goods on hand. That changes no column and no
total, and it is the owner's. Reversible: yes.

D-75 | The new ledger key dropped `product_id`, which assumed one movement per line per type — and
this document describes three cases where one line moves several products. Raised as review R-1, the
one design regression this task introduced. | **`product_id` returns to the key**:
`unique (reference_line_id, product_id, type, batch_key, serial_key)`. And the rule the key now
states is written beside it: **a line writes at most one movement per
`(product_id, batch_id, serial_id, type)`**, so the same component or modifier appearing twice on one
line is **aggregated into one movement**. | A composite sale, a depleting modifier and an opening
load all write several movements from one row, and without `product_id` the first two could not be
saved at all — a unique violation on every recipe and every kit. It is one column in an index on an
empty table and it costs neither of the two fixes the key exists for. The aggregation half matters as
much: without it the key is a constraint the writer has to dodge rather than a statement about the
world. Reversible: yes, today; not once a tenant holds movements.

D-76 | Writing the worked example for D-74 showed that even the correct formula does not tie to the
cent: `avg_cost` stored at `DECIMAL(18,4)` is 166.6667, and 288 × 166.6667 = 48 000.0096 against a
ledger debit of 48 000. Which of `stock_levels.total_value` and `avg_cost` is the authority? | **`total_value`
is the accumulator** — maintained as `prior total + money actually spent` — and **`avg_cost` is derived
from it** as `total_value / on_hand`, rounded for display and for the outbound cost snapshot.
**Amended after QA run 4 (BUG-14), which found the consequence this entry did not follow through:**
`total_value` accumulates **unconditionally, including through a negative balance**; the zero-and-below
guard moves to the **derivation**, where `avg_cost` holds its last value because there is nothing to
divide by; **outbound movements subtract exactly the `line_cost` §7.1 credits inventory**; and at
exactly zero on-hand any remaining `total_value` is rounding residue, written off through the
inventory-adjustment account so both sides return to zero together. **Amended again after QA run 5:**
`money_in` is `ROUND(quantity × inventory_cost, 4)` — money computed from money, not from a rounded
per-base cost, which would shed 0.0001 per line (BUG-16); the write-off and a `revaluation` are
**value-only `correction` movements** with `quantity = 0`, because `stock_levels` is only ever
updated alongside a movement (BUG-17); and the claim that the leak lands in §7.1's rounding account
is **withdrawn** — that is the tender-rounding account, and the leak needs no account at all,
because `line_cost` is credited to inventory and debited to cost of goods as one number. | The
reverse order makes the balance-sheet figure a product of a rounded average, so it misses the
inventory account by a sub-cent on most receipts and by more as quantities grow — and D-72's whole
point is that those two numbers must be the same number. Accumulating value and deriving the average
makes the reconciliation hold **by construction**; the rounded average then leaks only into the cost
of goods on a sale, where `sale_items.unit_cost` snapshots it anyway and §7.1 already gives rounding
an account. Found by writing the example rather than the sentence, which is the third time on this
task that running an arithmetic claim has changed it. Reversible: yes.

D-77 | `stock_productions.input_value` / `output_value` / `wastage_value` against the `value` column on
each `stock_production_items` row: §6.5 asserts `SUM(output value) + wastage_value = input_value` and
named neither side the authority. Found by the sweep I asked for after D-76, and the **third** instance
of this shape on one task. | **The item rows are the authority; the three header values are derived**
as `SUM(value)` grouped by `role`. | Forced by the design's own residual rule: the rounding residue is
absorbed **on the last output line**, so the lines are what get computed and the header can only be
their sum — the reverse direction would have the header rounding to something no set of lines adds up
to. It is also the house pattern (`account_balances` rebuilds from `journal_lines`; `total_value` leads
`avg_cost` by D-76). The general rule, now stated three times on this task: **an identity between a
summary and its detail needs a declared direction, or it gets answered twice** — which is exactly what
happened with `avg_cost`, three rounds apart. Reversible: yes.

D-78 | Which branch of the valuation formula does each of the eighteen movement types take, and on
what cost? Review round 2 R2-2: the inbound branch asserted every inbound cost is per **line** unit,
while the `sale_items` and `stock_production_items` rows said their `unit_cost` is per **stock** unit,
and `stock_transfer_items` said nothing. No sentence anywhere mapped the types to the branches. |
A **branch table** in §6.5 giving all eighteen types a branch, a cost column and a basis, under one
rule: **`money_in` multiplies the *line* quantity only where the cost is a price somebody quoted**
(`landed_cost`, a typed adjustment's `unit_cost`); where the cost is **a copy of an average** — a
refund, a transfer in, a production output — it is already per stock unit and `money_in` multiplies
`base_quantity`, the same expression as the outbound half. | Because the movement is **returning or
relocating value that was already measured**, so it must re-add exactly what was removed. Measured,
on the line-quantity reading: a refund of 1 case at `avg_cost` 200 with factor 24 adds back 200
against the 4 800 the sale removed, understating inventory by **4 600 on every multi-unit refund**;
and an intra-account transfer of 10 cases of 24 at 200 removes 48 000 and adds 2 000, losing **95.8 %
of the value moved with no journal entry to notice**, because §7.5 posts none when both locations
share an account. Rows had to be **ruled** rather than read off, and **QA run 7 found two of those rulings wrong**,
which is recorded here rather than quietly corrected: **`count`** cannot be priced on `line.quantity`
at all, because a count line's `[UOM]` quantity is `counted_quantity` — the total counted — while the
movement's quantity is `difference`; it takes `unit_cost` per **stock** unit on the base `difference`
(system 48, three cases counted at factor 24 = 72 base, movement **24**, so the line-quantity reading
valued three times the movement). And **both production branches read the line's `value`** instead of
multiplying, because §6.5's apportionment puts the rounding residue in that column, so multiplying
would leave the stock ledger and the journal differing by it. `transfer_in` adds the value of what **arrived**; what happens to the
value of a **short** receipt was answered here in QA run 7 and **withdrawn in run 8** — see D-82.
Reversible: yes.

D-79 | `avg_cost` "holds its last value" at or below zero on-hand, but the first movement on a
(location, product) can be **outbound** — an offline sale at a location that allows negative stock, or
a sale that syncs before the opening load — and then there is no last value. Review round 2 W-2. |
It falls back to **`products.cost_price`**, which this design already declares per stock unit. | The
schema already held the answer, so no column is added. An oversell has to be costed at something, and
the order last-value-then-`cost_price` is the only pair of defensible numbers available. The
accumulator ties regardless and the zero-stock write-off eventually recognises any difference, which
is a point in D-76's favour rather than a reason to leave the case undefined. Reversible: yes.

D-80 | Which document raises a **value-only** correction? §6.5's branch table said an ordinary
`increase`/`decrease` adjustment; the write-off paragraph said `revaluation`. Answered twice, in
paragraphs written three rounds apart. Review round 2 W-3. | **`revaluation`** where the correction
carries no quantity — a zero-quantity write-off is neither an increase nor a decrease — and an
ordinary `increase`/`decrease` adjustment where it does carry one. | Both halves of the question had
an answer; neither paragraph knew about the other. Which is FP-184's own shape — one question, two
undeclared answers — occurring **inside the fix for FP-184's own shape**, and the clearest argument
yet that this class is found by re-reading what a change touches rather than by care at the moment of
writing. Reversible: yes.

D-81 | The eighteen movement types had **four** homes after review round 2 — the `stock_movements`
enum, the `reference_line_id` table, the new branch table, and counts in prose — two of them grouping
the types differently, and both of QA run 7's correctness defects were in the newest one. The free
quantity's expression had three. | **One normative table** (§6.5, "THE MOVEMENT TABLE") carrying
identity *and* valuation for all eighteen, with the long reasoning moved into **numbered notes**
beneath so the cells stay scannable. Every other mention cites it: the enum declares the members, §11
names the key, the formula names no branches of its own, and **the prose counts stop counting** —
"sixteen of the eighteen" becomes "every type except the two reservation types, per the table".
`base_free` is defined once in the formula, marked (†), and cited by nightly check 3 and the
`grn_items` row. | Two tables over one key set is two homes, and the splitting reason — that the rows
were 200–400 characters — was sound about *width* and wrong about *structure*. Notes solve the width
without a second table, which is the option I had not considered when I split them. The alternative
of keeping two tables with one declared derived from the other was available and rejected: a derived
table still has to be re-read when the normative one changes, and FP-186 is the evidence that
re-reading is exactly what does not happen. **Prose that counts is the subtlest home of the four** —
it looks like narration rather than duplication, and it goes stale the first time the enum changes.
Reversible: yes, but there is nothing to reverse to that is better.

D-82 | QA run 7's note on a short transfer receipt said the shortfall "stays at the `transit`
location where `transfer_out` put it". QA run 8: the schema cannot express that. The movement table
gives **one** `transfer_out` and **one** `transfer_in` per line and nothing credits transit; a
two-hop shape needs two of each, and **they collide on the idempotency key, which has no location
column.** | **The claim is withdrawn and the question is named, not answered.** Note 9 now records
the two candidate shapes — add a location to the key, or give the transit legs their own movement
types — and routes the value treatment to **FP-253**, beside the sibling question of whether
`qty_variance` is a final or a pending shortfall. | **It is not a unit-of-measure question.** It is
identical at factor 1, so nothing in this task bears on the answer, and the candidate fixes touch the
**idempotency key** — the one artefact this task spent four rounds stabilising. This is exactly the
argument I used to decline the instalment-receipt design in D-73, and it applies with more force here
because the key is in scope of the change. The general form, which is the lead's and which I would
keep: **a note that answers a question outside its task's scope is worse than a note that names the
question, because the first gets implemented and the second gets decided.** Recorded rather than
silently deleted because `stock_transfers` has carried the same unbacked transit claim since v0.4,
and FP-253 needs to know the sentence exists in two places. Reversible: yes — nothing was built.

D-83 | Review round 3 R3-1: the movement table decided each type's **cost** and the formula still
decided the **quantity** generically, as `base_paid = line.base_quantity`. Three instances, all
verified: a count line's `base_quantity` is the *total counted*, so a level holding 48 that receives
72 from a count which found 72 closes at **120** and invariant 1 cannot see it; `stock_transfer_items`
has `base_qty_sent`/`base_qty_received` and **no `base_quantity` at all** (D-58), so the symbol was
undefined for both transfer types; and `base_free` was marked "(†) defined ONCE" while
`free_quantity` exists on **one** of the inbound branch's seven sources, where a NULL addend would
make the whole `on_hand` update NULL. | A seventh column, **"Quantity moved"**, on the movement table,
and the formula's quantity lines defer to it exactly as its cost lines already did: `on_hand ±=
|qty_moved|`. `base_free` is folded into that column on the `grn` row — the only row that has it —
and its definition says so. | The table was normative for everything the ledger stores **except the
one column it is built on**, which is the kind of gap that survives review because each half reads
correctly on its own. Two rows needed a ruling rather than a read-off and are marked ★: `grn`, which
is now the only place `base_free` applies, and the **reservation pair**, whose quantity column no
sentence in the document had ever named — `stock_reservations.quantity`, applied to
`quantity_reserved` and never to `quantity_on_hand`. The rest were readable from note 8, note 9 or
D-58 and are not marked. Same move as R2-2 on the other half of the same expression. Reversible: yes.

D-84 | §6.4 carried **two** price-resolution rules — "unit-matched first, then `NULL`, first hit wins"
and "the greatest `min_qty ≤ base_quantity`" — and **neither was declared the outer one**. They
disagree on a `NULL` row at `min_qty` 24 against a unit-matched row at `min_qty` 1, which decides what
a customer pays. Found by `ba` (F-11), who correctly declined to pick. | **The unit test is OUTER.**
A unit-matched row at any qualifying `min_qty` beats a `NULL` row at any `min_qty`; `min_qty` orders
rows **within** a unit match and never across one; a `NULL` match is reached only when no
unit-matched row qualifies. Stated in §6.4 with the worked pair. | **Ruled here rather than put to
the owner, because the commercial intent was settled when U-1 was.** A unit-matched row is the shop's
statement about *the unit being sold*; a `NULL` row is a statement about the stock unit plus
arithmetic. If a derivation can outrank a typed price for the unit actually being sold, then
`price_list_items.unit_id` buys nothing the moment anyone also sets a volume break — the typed case
price is silently replaced by 24 × the single price, which is the exact substitution U-1 was approved
to prevent. A second reason is independent of intent and decides it on its own: the table is
**replicated to every till**, so with `min_qty` outer, adding one volume break on the stock-unit row
would change **every case price in the shop** with no edit to any case row. Note that the pre-existing
clause "at equal `min_qty` the unit-matched row beats the `NULL` fallback" shows the question was
half-seen when it was written: the author handled the tie and not the ordering. It is removed as
subsumed, because leaving it would suggest equality is the only case that matters. Reversible: yes —
but not after a tenant has priced anything, since the two readings charge different amounts.

