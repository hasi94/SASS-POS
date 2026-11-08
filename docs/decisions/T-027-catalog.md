# T-027 decisions — the catalog

Autonomous/team-mode decisions. Each is the most conventional, safest, reversible choice available,
decided and logged rather than left blocking, per the team protocol. `D-1` numbering restarts for
this task's own file, matching the convention most decisions files in this backlog already use
(T-022, T-023, T-024, T-026).

D-1 | FP-230's own line widens this task to include "the resolver and its snapshot contract" for
units, but T-006 (DONE) shipped **no code at all** — `docs/decisions/T-006-unit-of-measure.md` and
`docs/specs/T-006-unit-of-measure.md` both state outright "the deliverable is two Markdown documents,
not code." Confirmed by search: no `UnitResolver` class, no `units`/`unit_conversions` migration,
nothing matching "Unit" as a domain concept exists anywhere under `packages/*/src`. | Treat
`docs/database.md` §6.3 ("Factor resolution: one ladder, one caller") and decisions D-57 to D-65 in
`docs/decisions/T-006-unit-of-measure.md` as the **settled design contract** this task implements for
the first time, not a contract to read from shipped code. T-027 is the first consumer in the sense
that it must build *to* that already-agreed design rather than redesign it — there is no prior
implementation to avoid contradicting. | The alternative — treating the absence of code as a gap this
task should quietly fill in without flagging it — would under-report a real surprise the task brief
assumed was false. Recorded so nobody downstream re-reads the brief's "consume, don't redesign" line
and goes looking for a class that was never shipped. Reversible: n/a (a finding, not a choice).

D-2 | This task's scope, as scoped by the brief and FP-230's own line, is enormous: products, variants,
categories, brands, units (dimension/resolver/snapshot), the stock/purchase/sales unit split, weighable
flags and PLU, and `barcode_rules` with a parser in **both** PHP and TypeScript. Is this one task or
several? | **Split in two.** This spec (T-027) covers categories, brands, units + conversions +
`UnitResolver` + the snapshot contract, products (including variants, the unit split, weighable/PLU
columns), `product_barcodes` (exact-match only), `product_locations`, `product_branches`,
`product_components`, and modifiers — all pure CRUD/schema/domain-service work, one engine (PHP),
no parser. **`barcode_rules` and its dual-engine (PHP + TypeScript) parser are filed as a new backlog
row, FP-423**, deferred to its own task. | Three reasons, not one: (1) backlog precedent already
splits exactly this way — FP-231 ("Taxes, price lists and discounts, with the shared calculation
engine in PHP and TypeScript") is already a *separate* row from FP-230 for the identical reason: a
dual-engine numeric parser/calculator is a distinct technical concern from catalog CRUD, with its own
fixture-parity testing discipline, not a sub-step of entity management. (2) FP-201/FP-202's own
precedent (`docs/backlog.md`, "Why FP-201 and FP-202 are split") states the test directly: burying a
second, differently-shaped piece of work inside a large diff makes the diff unreviewable; a barcode
parser that must behave identically in two languages is reviewed and tested completely differently
from a product CRUD handler. (3) The large single-task precedents in this backlog (T-008, T-020,
T-026) are each **one coherent mechanism** (a migration runner, a workflow engine, a provisioning
pipeline) built across many plan steps — not a grab-bag of seven unrelated nouns that happen to share
a module boundary. Catalog's "seven sub-areas" are not seven slices of one mechanism; `barcode_rules`
parsing is the one genuinely separate mechanism among them (two codebases, one contract, a parity
test suite), the rest are conventional entity CRUD over a schema `docs/database.md` already fully
specifies. Reversible: yes — FP-423 can be pulled back in or further split later; nothing about this
spec's schema prevents it.

D-3 | Three columns documented on `products`/`categories` (`tax_group_id`, `purchase_tax_group_id`,
`income_account_id`, `cogs_account_id`, `inventory_account_id`, `kitchen_station_id`) reference tables
that do not exist in the shipped tree: `tax_groups` (FP-231, Taxes/price lists — TODO), `accounts`
(FP-260, Finance chart of accounts — TODO), and whatever table owns a kitchen station (FP-239,
Printing — TODO; also touches Restaurant). Measured directly: no migration file anywhere creates
`tax_groups`, `accounts`, or a kitchen-station table. | **None of these six columns are built by this
task.** `products` and `categories` ship without them. Each is the responsibility of the task that
ships its owning table, added then as an ordinary expand-step migration (`docs/database.md` §9.4) —
exactly the same resolution T-026 used for `seed_core` (D-5 there: "none of those tables exist yet...
seeding them here would be inventing schema and data for modules that do not exist") and the same
shape T-020 used for `approver_type` values with no data source (architect routed it to `ba`, who
confirmed refuse-rather-than-invent). | A nullable foreign-key column with no target table is not a
foreign key, and it is not reversible for free: a tenant-side migration adding a column today and a
real `FOREIGN KEY` constraint on the same column months later, after Finance/Printing/Taxes ship, is
two migrations and a backfill window instead of one. Omitting it now and letting the owning task add
column-plus-constraint together is the cleaner expand step, not a shortcut. Reversible: yes.

D-4 | `product_suppliers` (§6.3) links a product to a `suppliers` row, but `suppliers` is owned by
Purchasing (FP-254, "Suppliers, purchase requests and purchase orders" — TODO) and does not exist.
Unlike D-3's six columns, this is a **whole table** whose only reason to exist is a relationship to a
module that is not built. | **`product_suppliers` is not built by this task.** Deferred to FP-254,
which creates `suppliers` and is the natural place to add the join table that depends on it — filed as
a note on this task's Out of scope, not a new backlog row, since FP-254 already names the dependency
in its own backlog line. | Same reasoning as D-3, one level further: a table with every column but one
pointing nowhere is not a smaller version of the real table, it is dead weight that would need its own
later migration to add the one FK that makes it useful. Reversible: yes.

D-5 | `docs/database.md` §16 carries four still-open owner questions this task's schema depends on
directly: Q-9 (is every conversion factor to base ≥ 1), Q-10 (is `products.stock_unit_id` immutable
once stock has moved), Q-11 (are fractional base quantities ever legal when the base unit is `each`),
Q-12 (does `product_branches` need its own `sales_unit_id`). Each already carries a stated working
proposal in the document itself ("assume yes for 1.0", "proposal: …", "no for 1.0"), not a blank. |
**Adopt each document's own stated proposal as this task's working assumption**, unchanged: Q-9 yes
(enforced as a validation on `UnitResolver`'s output, AC-9), Q-10 yes (enforced as immutability in the
service layer from the row's first save — see D-6 below for the narrower form this task can actually
build), Q-11 yes (`units.precision = 0` rejects a fractional `base_quantity` rather than rounding it),
Q-12 no (no `sales_unit_id` added to `product_branches`). | These are not business rules this task is
inventing — they are proposals the document already states and the owner has not yet overturned, and
adopting the document's own working default is the conventional, reversible choice every prior task
facing an open-but-proposed question in this backlog has made (T-008's engine-allow-list precedent,
T-023's conservative `ENGINE_HOLD` reading). Overturning any of the four is the owner's call, not
`ba`'s, and nothing here forecloses it. Reversible: yes, each independently — a validation relaxation
(Q-9, Q-11) or a new nullable column (Q-12) if the owner answers differently.

D-6 | Q-10's "immutable once stock has moved" cannot be tested as stated inside this task: nothing
that moves stock (Inventory, FP-250 onward) exists yet, so there is no "has moved" state to observe. |
This task builds and tests the **narrower, always-available half**: `stock_unit_id` is immutable from
the product's first save onward (no code path updates it, ever, once the row exists) — strictly safer
than "immutable after the first movement," never looser. The movement-aware relaxation (allowing a
change before any movement exists) is left to whichever Inventory task can actually observe a
movement, filed as a forward note rather than a new row, since FP-250's own backlog line already
carries the unit-model widening that would be the natural place to loosen this. | Building the exact
rule as stated would require inventing a stand-in notion of "has this product moved" with no ledger
to ask, which is indistinguishable from guessing. The safe direction to err is "more restrictive than
intended," never "more permissive than intended" — the latter risks exactly the restatement-document
problem §6.3 says nobody will build. Reversible: yes — relaxing an immutability rule is additive.

D-7 | Does a `variant`-type product need its own identity distinct from the `variant_parent`, or is a
variant simply a `products` row? `docs/database.md`'s own ERD and column list already answer this:
`products.parent_id FK NULL` is self-referential and `type enum(simple, variant_parent, variant,
service, bundle, composite)` lives on the same table — there is no second table. | **No new identity.**
A variant is a `products` row with `type = 'variant'` and `parent_id` pointing at a `variant_parent`
row; this task adds the two constraints the enum's own names imply and the schema does not yet state
explicitly: a `variant` row's `parent_id` must reference a row of type `variant_parent` (never another
`variant`, `simple`, etc.), and a `variant_parent` row's own `parent_id` must be null (it is the top of
its own chain, not nested). | This is reading the schema's existing design literally, not inventing a
new one — the enum names a closed small set of types and a self-referential FK, and "a variant's
parent is itself a variant" or "a variant_parent nested under another parent" are states the schema's
own vocabulary has no word for. Reversible: yes — a future task could add nested parents if a real
need appears.

D-8 | `products.is_weighable`, `price_mode`, and `plu_code` are three independent columns
(`docs/database.md` §6.3, "Weighable goods"). Should this task enforce a cross-column rule — e.g.
`is_weighable = true` requires `price_mode = per_weight`, or requires a non-null `plu_code`? Nothing
in `docs/database.md` or the T-006 decisions states such a coupling. | **No invented coupling.** The
three columns are validated independently (allowed enum/format/length each), and no combination is
refused on the grounds that it is unusual. The interaction between `is_weighable`, `plu_code`, and a
barcode's parsed `kind` only matters once a scan happens, which is `barcode_rules`' job (FP-423, D-2),
not catalog data entry's. | Inventing "a weighable product must be `per_weight`" would be a business
rule nobody wrote down — a tenant might legitimately want a weighable product priced `open_price` (a
butcher's "ask the till" price) with no PLU at all because every one of their labels embeds the
weight directly (`kind = embedded_weight`, no PLU lookup needed). Refusing that combination would be
guessing. Reversible: yes — a constraint can always be added later if FP-423's work reveals a real
need for one.

D-9 | Should this task validate, at product save time, that `purchase_unit_id`/`sales_unit_id` are
actually resolvable to `stock_unit_id` through `UnitResolver`'s ladder (so a UI default is never a
dead end)? | **No** — these two columns are validated only for existence and `dimension` compatibility
with `stock_unit_id`'s own dimension at the `units` level (same dimension required, since no ladder
step ever bridges two dimensions); full resolvability (a real conversion row or base-chain path) is
checked by `UnitResolver` at the moment a document line actually needs the conversion, not at catalog
entry time. | A tenant creating a product before entering its case-to-single conversion row is the
normal order of operations (`docs/database.md` §6.3's own example: "buy in cases, sell in units" is set
up as a `unit_conversions` row, typically *after* the product exists). Requiring the conversion to
already exist before the product can be saved would invert that order for no documented reason.
Reversible: yes — tightening this later from "same dimension" to "actually resolvable" is additive.

D-10 | Is a TypeScript twin of `UnitResolver` in scope here, given §3.2's "identical in the PHP and the
TypeScript engine" rounding-order rule and FP-237's note that an offline till needs the same unit
arithmetic the server has? | **No** — this task ships the PHP implementation only. A TypeScript twin
(for the offline till / replica) is explicitly FP-237's concern ("the replica must carry unit
conversions and barcode rules, or an offline till computes a different quantity than the server"),
which is a separate, already-scoped backlog row, and FP-231 already establishes the "shared
calculation engine in PHP and TypeScript" pattern this task's own successor can follow rather than
inventing a second one. | Building a TypeScript resolver with no replica, no offline fixture harness,
and no till code to call it from (none of FP-236/FP-237/FP-238 exist yet) would be code with no
consumer and no way to prove parity — exactly the kind of "second mechanism built alongside the one
already anticipated" this task's own brief warns against. Reversible: yes — FP-237 picks this up
directly.

D-11 | Package/module placement: `docs/architecture.md` §3's repository-layout diagram still shows
`modules/<module>/` for business modules, but every module actually shipped so far
(`packages/organization`, `packages/access`, `packages/platform`, `packages/workflow`,
`packages/eventbus`, `packages/auth`) lives under `packages/`, not `modules/`. | Not `ba`'s call to
make — this is `architect`'s/`dev`'s placement decision, following whichever precedent they judge
correct, consistent with every prior spec in this backlog ("the exact interface and class names are
the architect's/developer's to design, not restated here," T-026 AC-15). Recorded here only so the
discrepancy is not mistaken for something this spec settled. | Reversible: yes; not a business
decision in any case.
