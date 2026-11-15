# T-029 decisions (taxes, price lists, discounts)

Autonomous/team-mode work (no human in the loop for this spec round). Each open design
question below was decided by `ba`, logged here, most-conventional/safest/most-reversible
option chosen per the standing protocol.

D-1 | Is this one task or does it split, the way T-027 (FP-230) split `barcode_rules` out as
FP-423? | **Split.** This spec (T-029/FP-231) covers the full backend domain — `tax_authorities`,
`taxes`, `tax_rates`, `tax_groups`, `tax_group_taxes`, `price_lists`, `price_list_items`,
`price_list_assignments`, `discounts`, the two deferred FK columns owed from T-027
(`products.tax_group_id`, `purchase_tax_group_id`), and a pure PHP calculation engine (tax,
price-list resolution, discount) plus a checked-in JSON fixture set the engine is proven
against. The TypeScript implementation of the same engine and any frontend test wiring that
consumes those fixtures is split into a new backlog row (logged below, D-11) rather than built
here. | Reason: a dual-language engine with its own parity-testing discipline is a genuinely
separate mechanism from the backend schema and arithmetic it mirrors — the exact shape T-027
already used once for `barcode_rules`'s dual-engine parser (FP-423), and today `frontend/` has
no shared-fixture test precedent to extend (checked: no existing `*.spec.ts` or fixture loader
references a PHP-shared JSON format). Building a second engine with no proven fixture format
yet, in the same task as the schema that format depends on, would mean redoing the TS side the
moment the PHP side's output shape changes during review. | Reversible: yes — recombining is a
backlog edit, not a schema change.

D-2 | Is a tax group a named bundle of one or more tax rates evaluated together? | **Yes, exactly
as `docs/database.md` §6.4 already states, not invented.** `tax_groups` is the named thing a
product references (`products.tax_group_id`); `tax_group_taxes` is the join naming which `taxes`
belong to the group and in what `sequence`; each `taxes` row resolves its effective rate from
`tax_rates` by date. A sale line computes one tax amount per member tax, summed (or compounded
per `taxes.is_compound` and `sequence`) into one `tax_breakdown` for the group. | Reason: the
schema is unambiguous; no invention needed. | Reversible: n/a (reading the schema, not deciding
it).

D-3 | Are price lists customer-group-scoped, branch-scoped, date-ranged, or some combination? |
**Combination, exactly as documented.** `price_lists.type` is one of `standard`,
`customer_group`, `promotion`, `branch`; `starts_at`/`ends_at` make every price list optionally
date-ranged regardless of type; `price_list_assignments` is the many-to-many scoping table
(`scope_type` one of `branch`, `customer_group`, `customer`) that lets one price list attach to
more than one branch, group or individual customer. The six-step resolution order (customer →
customer-group → promotion by priority → branch → `product_branches.selling_price` →
`products.selling_price`) is documented verbatim in §6.4 and is not this task's to invent. |
Reason: the schema and the resolution order are both already fully specified. | Reversible: n/a.

D-4 | Does a discount apply after a price-list price is resolved, or instead of one — no
precedence rule is stated in `docs/database.md` for how `discounts` interacts with the price
ladder's output? | **After.** The price ladder (§6.4, D-3) resolves `unit_price` for the line
first; a discount (`discounts.scope` = `item` or `bill`) is then applied as a reduction against
that already-resolved price/line total, never as an alternative path that bypasses price-list
resolution. A discount's `type` (`percentage`, `fixed`, `buy_x_get_y`) acts on the resolved
`line_total`/`subtotal`, the same order `sale_items` already implies by carrying both
`list_price` (pre-discount) and `unit_price`/`discount_amount`/`line_total` as separate columns
(§6.7) — a column shape that only makes sense if resolution precedes discounting. | Reason: most
conventional retail-POS behaviour, and the only reading consistent with `sale_items`' own
documented column set, which this task must not contradict since `sale_items` is already shipped
schema outside this task's scope. | Reversible: yes — a future task could add a discount type
that competes with price-list resolution (e.g., "best of" promotions), but that is new ground,
not a correction of this call.

D-5 | `docs/backlog.md`'s FP-231 row states "since both are `NOT NULL`-documented" for
`products.tax_group_id`/`purchase_tax_group_id`, implying both need expand + backfill + contract.
`docs/database.md`'s actual `products` row (§6.3) states `tax_group_id FK` (no `NULL` — i.e.
`NOT NULL`-documented) but `purchase_tax_group_id FK NULL` (explicitly nullable). Which governs? |
**`docs/database.md` governs** — it is the schema document; the backlog row is a summary written
at T-027's close-out and is wrong on this one point: only `tax_group_id` is `NOT NULL`-documented,
so only it needs a backfill. **Corrected per `architect`'s F-4**: "`purchase_tax_group_id` needs
only an expand migration" is false. `SourceRules::foreignKeysOnlyInContract` refuses a foreign key
onto an already-existing table outside a `contract`-phase file, and `products` already exists —
so **both** columns need expand (add the nullable column) **and** a contract migration (add the
FK), and only `tax_group_id` additionally needs a backfill before its contract step can also
tighten it to `NOT NULL` (deferred further still — see D-14/F-3 below). Both FKs are written in
the **same single contract file** (T-014 D-31 precedent: one contract file per logical change, not
two consecutive ones the planner would force into two fleet runs). | Reason: `database.md` is
this project's authoritative schema source per `CLAUDE.md` for the nullability question; the
migration-phase question is governed by `SourceRules`, which `architect` checked directly against
source and `ba`'s first pass missed. | Reversible: yes — if the owner later states
`purchase_tax_group_id` should be `NOT NULL` too, that is a fresh backfill+tighten cycle on top of
the FK this task already adds.

D-6 | `tax_group_id`'s contract migration requires every existing product to already have a
non-null value before the column can be tightened. What does the backfill write? | **A seeded
default tax group.** If no `tax_groups` row has `is_default = true` at migration time, the
backfill creates one (`code = 'STANDARD'`, `name = 'Standard'`, `is_inclusive = false`,
`is_default = true`, `is_active = true`) inside the same migration; every existing `products` row
with a null `tax_group_id` is then set to that default group's id before the contract migration
runs. A tenant that already has a tax group marked `is_default` uses that one instead of creating
a second. | Reason: `tax_groups.is_default` already exists for exactly this purpose (mirrors
`customer_groups.is_default`, T-028's identical "at most one default, auto-create on first use"
shape) and a product with no stated tax treatment defaulting to "standard, not inclusive" is the
safest reversible guess — a shop can recode it per product afterward, and a migration that left
`tax_group_id` null would have had no remedy inside the migration itself. **Corrected per
`architect`'s F-16:** the premise "no documented default" was imprecise — `docs/database.md` §8
(`seed_core`, tenant provisioning step 9) already lists "default tax group and taxes" among what
a new tenant is seeded with, without stating its contents; this decision is what supplies those
contents, cited against §8 rather than invented from nothing. No change to `seed_core` itself is
needed, because this task's backfill migration runs before `seed_core` ever would for a new
tenant, covering both an upgraded existing tenant and a freshly provisioned one with the same
migration. | Reversible: yes — a seeded default row can be recoded or deactivated after
migration; it is ordinary data.

D-7 | `tax_authorities.payable_account_id` is documented `FK accounts` with no `NULL` (i.e.
`NOT NULL`-documented), and `accounts` (Finance, chart of accounts) does not exist in this
codebase yet — the identical "FK onto a table that isn't built" problem T-027/T-028 both hit, but
discovered here inside this task's *own* new table rather than inherited from a prior task's
close-out. How is `tax_authorities` built without `accounts`? | **REVERSED, per `architect`'s
F-17 — do not build `payable_account_id`, `receivable_account_id` or
`discounts.sales_discount_account_id` at all.** `ba`'s original call ("build nullable, no FK yet")
is wrong for the same reason this spec's own Non-functional requirements section already states
and `ba` failed to apply to its own new finding: "every column deferred in this task is deferred
entirely — no key-less placeholder column is added now that would still need its own later
contract migration." T-027 D-3/D-4 and T-028 D-6 both deferred their account columns *by not
building them at all* — a nullable, unkeyed `payable_account_id` would give Finance nothing to
build on and would additionally force Finance to clean up whatever unvalidated ULIDs an admin
typed into it in the meantime, which is strictly worse than a column that does not exist yet. A
create/update command that supplies any of the three field names is refused as unknown
(`Fields::only`), the same shape T-027/AC-27a already uses for `products.custom_fields`. Whichever
task ships `accounts` (Finance, FP-260 or equivalent) owes the **full** expand + backfill +
contract (tighten to `NOT NULL`) for `payable_account_id`, and expand + contract (FK only, stays
nullable) for `receivable_account_id` and `sales_discount_account_id` — exactly as if this task
had never touched `tax_authorities`/`discounts` at all. | Reason: consistency with this task's own
stated NFR, and with T-027/T-028's actual precedent, both of which `ba`'s first pass contradicted
by trying to be "helpful" with a placeholder column. | Reversible: yes — building the column later
is the standard expand/backfill/contract shape, unaffected by this reversal.

D-8 | `discounts.workflow_definition_id FK NULL` — does this task wire the workflow engine
(`packages/workflow`, T-020, shipped) to actually gate a discount, given `WorkflowEntityType::
Discount` already exists reserved for this? | **No — schema only.** This task adds the column
and its FK (both already buildable: `workflow_definitions` exists, shipped by T-020) but does not
call `WorkflowGate::evaluate()` anywhere, because the moment that call matters is when a discount
is actually applied to a sale, and `sales`/`sale_items` approval flow (FP-233, Sales) is not built
yet. `requires_approval`/`approval_threshold` are likewise stored and returned as plain data with
no enforcement logic in this task. | Reason: mirrors T-028/AC-24's identical boundary ("no
override/approval path is built in this task... a future caller handles that") for the same
reason — the consuming module does not exist. | Reversible: yes — Sales wires the gate call later
without touching this task's schema.

D-9 | What format do the shared PHP/TypeScript calculation-engine fixtures take, given no
TS engine or fixture-consuming test exists yet in this codebase to extend? | **A checked-in JSON
fixture directory, read by this task's PHP tests directly today, and left in a shape a future
TypeScript engine (D-11) can read byte-for-byte identically** — one fixture file per scenario
(tax-only, price-list-only unit-matched vs. derived, discount-only, and the three combined),
each stating inputs (`product_id`-free — a pure arithmetic input: line unit, quantity, candidate
price-list rows, candidate tax-group rows, candidate discount rows, document date) and the
expected output (`unit_price`, `tax_breakdown`, `discount_amount`, `line_total`), mirroring the
"driven by shared fixtures" language `docs/architecture.md` §15 already uses and the
`SnapshotCalculator`/`UnitLadder` precedent of a pure, DB-free, independently unit-tested
calculator (T-027). No fixture depends on a live database row, a ULID that must exist, or any
Laravel container. | Reason: the fixture format is the one artefact both engines must agree on
byte-for-byte, so it has to be decided now even though only one of the two engines is being built
— deferring the decision to the TS task would let that task invent a second, possibly
incompatible, format. | Reversible: yes — JSON is the most conventional, most portable choice for
a format two different language runtimes must both parse exactly.

D-10 | `discounts.value` must represent either a percentage (`type = percentage`) or a money
amount (`type = fixed`), but `TenantDecimalPrecisionTest` (`docs/database.md` §3.2) allows exactly
four `(precision, scale)` pairs and adding a fifth is a static-test change this task should avoid
unless genuinely necessary. Does `value` need two different columns or precisions? | **One
column, `DECIMAL(18,4)`, for both.** A percentage value (e.g. `15.5000`) fits the money/quantity
pair exactly as well as the `(9,4)` rate pair does, and reusing the money pair avoids introducing
a fifth allowed `(precision, scale)` pair into a static test that exists specifically to keep that
set small and intentional. `min_amount`, `max_discount` and `approval_threshold` are money and use
the same `DECIMAL(18,4)` pair for the same reason. | Reason: no documented column forces a second
pair; reusing an existing one is the safer, smaller diff. | Reversible: yes — a later task could
split `value` into `percent_value`/`amount_value` if the single-column shape proves awkward; that
is a new expand/contract cycle, not a reopening of this call.

D-11 | New backlog row for the split-out TypeScript half (D-1). | Logged as a new row in
`docs/backlog.md` (FP-432, FE stream): "The TypeScript tax/price-list/discount calculation engine
— mirrors T-029/FP-231's PHP engine arithmetic exactly, proven against the same checked-in JSON
fixture set (D-9), consumed by the till for offline totals. Depends on T-029/FP-231 (PHP engine
and fixture format) being DONE." | Reason: makes the split from D-1 concrete and schedulable
rather than an unlogged gap. | Reversible: yes — ordinary backlog editing.

---

**Revision 1.** The decisions below (D-12 to D-21) resolve `architect`'s plan-stage findings
F-1 to F-3 (blocking) and F-6 to F-15, F-18 (further corrections), found by re-reading this
task's real dependencies in source (`docs/plans/T-029-taxes-pricing.md`, committed `6848c9a`).
D-5, D-6 and D-7 above were edited in place rather than appended, since each corrects or reverses
an existing call on the same question; everything below is new ground `ba`'s first pass simply
did not reach.

D-12 (F-1, blocking) | AC-22's original wording computed `subtotal = unit_price × base_quantity`.
`unit_price` is documented "per the line's `unit_id`" (§6.7) — the line's own unit, not the stock
unit `base_quantity` is expressed in. Multiplying by `base_quantity` overcharges by exactly the
line's own unit-conversion factor (a case at 179 priced against `base_quantity` 24 would charge
4296, not 179). | **`subtotal = unit_price × quantity`, where `quantity` is the line-unit quantity
(`UnitSnapshot.quantity`), never `baseQuantity`.** `base_quantity` keeps its existing job as the
`min_qty` comparator (AC-16) and as the basis for a `type = fixed` tax (D-15/F-9) — it is not
removed from the engine, only removed from this one multiplication. | Reason: the only reading
consistent with §6.7's own words, found by `architect` checking the documented column meaning
directly rather than trusting the spec's first-pass arithmetic. | Reversible: no — this is a
correctness fix, not a judgement call; the wrong formula is simply wrong.

D-13 (F-2, blocking) | AC-15's price-ladder steps 5 and 6 read `product_branches.selling_price`
and `products.selling_price` and use them directly. Both are documented "per stock unit" (§6.3) —
the same class of bug T-027's unit model exists to prevent, moved from stock into money: a case
line resolving to the single's stock-unit price would sell a case for the price of one unit. |
**Steps 5 and 6 derive by the unit factor, exactly like the `unit_id IS NULL` fallback inside
steps 1–4**: `unit_price = round(selling_price × unitFactor, 4)`, using the factor the caller's
`UnitSnapshot` already carries — never re-resolved independently (mirrors D-14/Ruling 4 below).
When the line unit **is** the stock unit, `unitFactor = 1` and the derived price equals the stored
price unchanged, so this is strictly a generalisation of the previous (wrong) behaviour, not a
special case bolted on. | Reason: the identical "silent 1" argument `docs/database.md` §6.3
already makes for quantity, applied to money, which `ba`'s first pass missed by reading §6.4's
prose (which discusses only `price_list_items`) without checking what step 5/6's own source
columns are documented as being "per." | Reversible: no — correctness fix.

D-14 (F-3, blocking) | AC-1's original wording tightens `products.tax_group_id` to `NOT NULL` in
the contract migration. `SourceRules::isDestructive()` classifies any `->change()` (or raw
`ALTER … MODIFY`) against a pre-existing table as destructive; `PlanMigrationRunHandler::
assertNothingDestructive()` refuses to apply such a migration while `schema.backups.available`
is `false` (today's default — no backup facility exists), and tenant provisioning's own
`MigrateRemainder` step runs through that same planner. Shipping the tighten as written would
refuse every bulk migration run **and every new tenant's provisioning** from the moment this task
merges. | **Defer the `NOT NULL` tighten itself as a follow-up backlog row, gated on the backup
facility existing.** This task ships only: expand (the two nullable columns) → backfill (default
group, every product set, D-6) → contract (**the two foreign keys only** — adding a key is not a
`->change()` and is not classified destructive, the same shape T-014's
`add_branch_foreign_keys` already uses). `NOT NULL` is enforced at the **application** layer in
the meantime: `CreateProduct` always writes a `tax_group_id` (AC-3's default, or refuses
`no_default_tax_group` if none can be resolved) and `UpdateProduct` refuses an explicit
`tax_group_id = null`; a test asserts no product row ever holds a null `tax_group_id` after any
of this task's own write paths. The `NOT NULL` tighten itself becomes a new backlog row, waiting
on the backup facility (a kernel-level prerequisite, out of this task's remit to build) — not a
silent drop of the requirement, and not a reclassification of `isDestructive()` (also out of this
task's remit: that is a shared safety rule, and loosening it to let *this* task through would
loosen it for every future `->change()` too). | Reason: T-008 D-10 already states the policy this
follows ("gate a destructive step on backups, don't weaken the classifier to dodge it") — this is
that existing, already-approved policy applied to a case `ba`'s first pass did not check against
the migration planner at all. | Reversible: yes — the deferred tighten ships the moment backups
exist, as its own ordinary contract migration.

D-15 (F-9) | Four gaps in the tax-group calculation (AC-11) that `ba`'s first pass left
unspecified: the rate's scale, what "compound" means with more than one compound member, how a
`type = fixed` tax computes, and whether `applies_to`/`is_active` filter members. | **Resolved,
each conventionally:**
- **Rates are percentages, not fractions** — `tax_rates.rate = 15.0000` means 15%, consistent
  with `DECIMAL(9,4)` being unable to hold a 5-decimal-place fraction (`0.12345`) at all. Every
  rate multiplication is `amount × rate / 100`, computed exactly and rounded once — never
  `amount × (rate/100 rounded)`.
- **Compounding** (the conventional reading, since two readings are indistinguishable with only
  one compound member in a group): a compound member's base is the line base **plus every member
  amount that precedes it in `sequence`**, whether or not that preceding member is itself
  compound — not "every non-compound member" as `ba`'s first draft stated, which only differs
  from the conventional reading once a group has two or more compound members.
- **`type = fixed`**: `rate` is an amount per the product's **stock** unit (consistent with every
  other money column on `products` being per stock unit, §6.3), so `amount = round(rate ×
  base_quantity, 4)`. A fixed tax cannot also be `is_compound` (refused at write time,
  `fixed_cannot_compound` — compounding a flat amount onto a percentage base has no defined
  meaning). In an inclusive group, every fixed member's amount is subtracted from the gross before
  the percentage back-out runs; if the fixed amounts alone exceed the gross, the result is a
  refusal (`inclusive_amount_too_small`), never a negative base.
- **`applies_to`/`is_active`**: the engine is told whether it is pricing a `sale` or a `purchase`
  line and skips any member tax whose `applies_to` excludes that context; an inactive or
  soft-deleted member tax is likewise skipped (deactivating a tax is how a shop stops charging
  it); a group left with no qualifying member computes zero tax (consistent with AC-9's "an empty
  group is a legitimate tax-exempt assignment, not an error"). A member that is active, in scope,
  but has no rate effective on the document date is still a hard refusal (`no_effective_rate`,
  AC-7) — being in scope does not excuse having no price.
- **Inclusive-group rounding**: the tax-exclusive base is computed once as `round(gross / M, 4)`,
  where `M` is the exact product of each percentage member's `(1 + rate/100)` factor in sequence
  order (an exact decimal, never itself rounded) — not "iteratively, tax by tax," which compounds
  a rounding error at every step. The one-cent residue `gross − base − Σ(tax amounts)` that this
  exact division can still leave is added to the **last percentage member in `sequence`**, so
  `base + Σ(tax amounts) = gross` exactly, with a fixed, predictable member absorbing the
  rounding rather than whichever one happened to round down.
  | Reason: each is the conventional reading where the schema is silent, chosen for being provable
  by a fixed rule rather than left to drift between call sites; the inclusive-rounding rule in
  particular is chosen specifically because it is stable (the same residue target every time)
  rather than cumulative. | Reversible: yes — each is an arithmetic rule inside one pure
  calculator class; changing any one is a method edit, not a schema change.

D-16 (F-10) | AC-15's first draft left several price-ladder selection questions unanswered: what
filters a list into or out of a given step, whether `type` further restricts steps 1/2/4, how
several qualifying lists in one step are ordered, and whether a promotion is scoped at all. |
**Resolved:**
- **Every candidate list**, at every step, must be `is_active`, not soft-deleted, have a window
  containing the document instant (`starts_at ≤ at ≤ ends_at`, either bound null meaning open —
  D-3 already states every list type is optionally date-ranged; the first draft wrongly applied
  the window check only to promotions), and have `currency` equal to the document's own currency
  (a list priced in another currency is skipped entirely, never converted — this task does no
  currency conversion anywhere).
- **Steps 1, 2 and 4 select by assignment**, not by `type`: every **non-promotion** list assigned
  (via `price_list_assignments`) to the line's customer/customer-group/branch respectively is a
  candidate at that step, because §6.4 says "customer price list," not "standard-type list
  assigned to the customer." A tenant that wants to restrict which list types reach a given scope
  enforces that at write time, on the assignment itself — not in the ladder.
  - **Step 3 is `type = promotion` lists.** A promotion with **no** `price_list_assignments` rows
    applies everywhere (an unscoped tenant-wide promotion); a promotion **with** assignment rows
    applies only where one of them matches the line's customer, customer-group or branch —
    "active promotion lists by priority" (§6.4) does not mean promotions are always unscoped, and
    a branch-limited promotion is the ordinary case for a multi-branch tenant.
- **Several qualifying lists within one step** are tried in this order: `price_list_assignments.
  priority` descending (steps 1, 2, 4 only — step 3's unassigned case has no assignment row to
  order by), then `price_lists.priority` descending, then `price_lists.code` ascending as the
  final, deterministic tiebreak so two lists with identical priorities never resolve
  nondeterministically. The first list, in that order, that actually prices the line (AC-16) wins
  — a list that does not price the line (no matching `price_list_items` row at all, not even a
  derived one) is skipped in favour of the next list in the same step, and only once every list in
  a step is exhausted does the ladder move to the next step.
  | Reason: each rule is the conventional reading of §6.4's prose, made concrete enough to be
  provable by a fixture rather than left to whoever writes the query first. | Reversible: yes —
  each is engine logic, not a schema commitment.

D-17 (F-11) | `price_list_items.discount_percent` is a documented column with no stated meaning
in the ladder as first drafted — a stored number the engine silently ignores is a trap for
whoever notices it later and assumes it does something. | **The winning `price_list_items` row's
`discount_percent`, when set, is applied to the resolved price before it is returned**:
`list_price` is the typed-or-derived price (AC-16, D-13) unchanged; `unit_price = round(list_price
× (1 − discount_percent / 100), 4)`. Both values are returned, mapping directly onto
`sale_items.list_price` and `unit_price` (§6.7) — the exact two columns that distinction already
exists to hold. | Reason: a documented, already-provisioned column with a name this specific
("discount_percent" on a *price list item*) is far more likely to be load-bearing than
informational, and leaving it unread is strictly worse than reading it, since reading it costs one
ladder step and two fixtures while ignoring it risks a shop entering values nothing ever honours.
| Reversible: yes — if this proves wrong, a later correction stops reading the column; no schema
change either way.

D-18 (F-12) | AC-20/AC-22's first draft left the exact order and set of discount-applicability
refusals unspecified — which check runs first, whether `products.allow_discount` (a T-027 column
the first draft did not mention) is honoured, and whether checks run against "now" or the
document's own time. | **A fixed refusal order, each check against the caller-supplied document
instant, never the wall clock** (so a fixture replays identically regardless of when it is run):
`unknown_discount` → `discount_scope_mismatch` (a `bill`-scope discount given to a line call, or
the reverse) → `inactive` / `not_yet_started` / `expired` / `usage_limit_reached` (AC-20, each
against the supplied instant) → `discount_not_allowed` when the product's `allow_discount = false`
→ `not_applicable` (item scope only: `applies_to` names at least one product/category/brand and
none of the line's own id, `category_id` or `brand_id` is among them — an OR across the three
lists; a null or empty `applies_to` matches everything) → `below_min_amount` (the item's subtotal,
or the bill's base, is under `min_amount`) → `unsupported_discount_type` for `buy_x_get_y` (AC-22,
out of scope per the spec's own Out-of-scope section). `requires_approval`/`approval_threshold`
are still never evaluated (D-8 stands unchanged). | Reason: `products.allow_discount` already
exists and is exactly the kind of column a discount engine built without checking it would
silently ignore, the identical class of gap D-17 fixed for `discount_percent`; the document-instant
rule follows directly from the engine's own "pure, DB-free, no wall-clock dependency" discipline
already stated for the fixture set (D-9) — a check against `now()` could never be replayed by a
fixture a year later. | Reversible: yes — ordinary engine logic.

D-19 (F-13) | AC-23's first draft summed already-computed `line_total` values (which include
exclusive tax) as the bill-discount base — applying a bill discount to tax as well as price, the
opposite of D-4's "discount before tax" rule for item-level discounts. | **The bill-discount base
is `Σ discounted_subtotal`** (each line's post-item-discount, pre-tax amount), not `Σ line_total`.
Allocating the resulting bill discount back across lines, and re-taxing each line afterward, stay
exactly where AC-23 already placed them — the future Sales caller's concern, since no allocation
rule is documented and this task invents none. | Reason: consistency with D-4's own stated
reasoning, which `ba`'s first pass stated for item discounts but did not carry through to the
bill-level case it wrote three sections later. | Reversible: yes — ordinary engine logic.

D-20 (F-14) | AC-25's first wording ("no file ... computing a percentage/rate multiplication
against a tax or discount value") is not something a static scan can measure as written, and
separately, the one place this engine legitimately must multiply a price by a `UnitSnapshot`
factor (D-13/D-14's derivation step) would otherwise be flagged by T-027's own existing
"no file outside `Units/` multiplies by a factor" scan, since that scan's glob covers every
package, not only catalog's own. | **AC-25 is restated as a concrete, measurable instrument**:
a scan outside the pricing package's own `Engine/` sub-namespace reports (a) any reference to the
literal table/column tokens `tax_rates`, `tax_group_taxes`, `price_list_items`,
`price_list_assignments`, `is_inclusive`, `is_compound`, and (b) any multiplication (or a
`multipliedBy(` call) adjacent to a variable name ending in `rate` or `percent`. **T-027's own
factor-multiplication scan gets one narrowly named exemption** for the one file that legitimately
derives a price from a unit factor (D-13's `PriceLadder` derivation step), recorded in the scan's
own exemption list and documented as "the sanctioned price derivation from a `UnitSnapshot`
factor, never from a column" — never worked around by renaming variables to dodge the pattern,
which would be evasion rather than a stated exception. | Reason: an unmeasurable AC is not an AC;
an unnamed, silent exemption would also be a boundary rule quietly weakened with no record of why
— the project's own "name the instrument by what it scans" discipline applies to both halves of
this fix. | Reversible: yes — a future review can tighten or loosen the exact token list; the
exemption is narrow and reversible by name.

D-21 (F-15) | Two gaps in the shared fixture format, found during plan-stage review: it was not
specified precisely enough for a second language to parse byte-identically, and a pre-existing
obligation — T-006's own decision that unit resolution joins the shared PHP/TypeScript fixtures
(`docs/architecture.md` §15; `docs/plans/T-006-unit-of-measure.md` line 679) — was missed
entirely by this task's first pass. | **Two resolutions:**
- **Fixture decimals are JSON strings at full stored scale** (`"7.5000"`, a factor as
  `"24.000000"`), never a bare JSON number — a JavaScript number silently loses precision at the
  fourth decimal place for exactly the values this engine computes, which would make the fixture
  set worthless for its one stated purpose (D-9). Dates are plain `YYYY-MM-DD`; instants are
  `YYYY-MM-DDTHH:MM:SS.mmmZ`.
- **A small catalog-side fixture set is added** (`packages/catalog/fixtures/units/`, roughly
  eight cases covering precision-first rounding and the mass-dimension chain) replayed by a
  catalog-owned test against `SnapshotCalculator` directly — closing T-006's own standing
  obligation that unit resolution be fixture-proven, which belongs beside the unit model that
  produces it, not duplicated inside the pricing package. FP-432 (D-11) and FP-237 (the offline
  replica) both read these same files.
  | Reason: a float-based fixture format would fail the one job fixtures exist to do; the unit
  fixture gap is a standing, previously-logged obligation this task's first pass simply did not
  check against. | Reversible: yes — both are implementation-shape choices, not business rules.

D-21a (F-18) | How should the catalog package's product-create/update handlers (AC-3) reach
`tax_groups` without reading it directly? | **Catalog validates and defaults a product's tax
group through a new published contract** (`Contracts\Pricing\TaxGroupDirectory`, mirroring the
existing `Organization\BranchDirectory`/`BranchState` shape exactly) rather than reading
`tax_groups` directly — keeping "which group is the default" and "is this group active" owned by
exactly one package, the same discipline every cross-module read in this codebase already
follows. | Reason: the contract-not-direct-read rule is this codebase's existing, consistent
pattern for exactly this shape of cross-module dependency. | Reversible: yes — an
implementation-shape choice, not a business rule.

D-22 (F-6) | AC-10's first draft wrote "soft-deleting a `tax_groups` row" as its guard's trigger,
but `docs/database.md` §6.4 line 595 documents `tax_groups` with only `[ID] [TS]` — no `[SOFT]`
tag, so there is no `deleted_at` column to soft-delete. | **`tax_groups` has no delete slice at
all.** A group is retired by setting `is_active = false`, and AC-10's existing-reference guard
(refusing the change while any active product references the group) attaches to that
deactivation instead of to a delete that cannot exist. Deactivating the current **default** group
clears `is_default` in the same transaction (the identical shape T-028/F-19 already uses for
`customer_groups.is_default`); afterward, `CreateProduct` with no `tax_group_id` supplied and no
remaining default refuses `no_default_tax_group` rather than silently picking an inactive group.
| Reason: the schema simply has no column to soft-delete; this is a correction of fact, not a
judgement call. | Reversible: n/a — reading the schema correctly, not deciding it.

D-23 (F-7) | AC-12's first draft defaulted `price_lists.currency` to `company_profile.currency`
when not supplied. No migration anywhere in this codebase creates `company_profile` — the same
gap T-028/F-8 already found and resolved the same way for `customers.currency`. | **`currency` is
required on every `price_lists` row** (`CHAR(3)`, validated `^[A-Z]{3}$`), with no default and no
silent fallback to a table that does not exist. Once `company_profile` ships, defaulting from it
is a purely additive change to the create handler — nothing about this decision blocks that later
step. | Reason: identical reasoning to T-028/F-8, applied consistently to the same underlying gap
found independently in this task. | Reversible: yes — defaulting is additive once the dependency
exists.

D-24 (F-8) | AC-14's first draft stated `price_list_assignments.scope_id` carries no FK because
`customer_groups`/`customers` "do not exist in this codebase" — checked directly, both tables
**do** exist on this branch (`...002801_create_customer_groups_table.php`,
`...002802_create_customers_table.php`, both committed as part of T-028's chunks A/B, which this
branch was cut from after). The stated reason for "no existence check" was therefore wrong, even
though the original "branch only" scope-narrowing it justified happened to ship something
workable. | **Restated reason, and the check widened to match it:** `scope_id` carries no FK
because it is genuinely **polymorphic** — one column naming a row in one of three different
tables depending on `scope_type` — which is exactly `docs/database.md` §3.3's documented
exception for a `reference_type`/`reference_id` pair (no single FK target, by design, not by
accident of what happens to exist). Because `customer_groups` and `customers` are in fact
reachable from this branch, `price_list_assignments`' create handler existence-checks **all
three** scope types (branch, customer_group, customer) against their respective tables — the
same direct existence-check-without-an-FK precedent `packages/catalog` already uses for
`product_branches` against `branches` — rather than checking only `branch` and leaving the other
two as silently unvalidated dead references. | Reason: the first pass's premise was simply false,
found by `architect` checking the migration tree directly rather than trusting the spec's own
unchecked assumption; a dangling assignment to a nonexistent customer or group would otherwise be
silent, undetected bad data. | Reversible: yes — ordinary handler validation logic, no schema
change.

D-25 (corrects D-15's inclusive-group rounding bullet and AC-11.6) | D-15 and AC-11.6 said the
net of an inclusive amount is `round(remaining gross / M, 4)` with `M` "the product of every
percentage member's `(1 + rate/100)`". That is true only when every percentage member is compound.
For two non-compound percentages (5% and 10%) the gross is net × 1.15 but the product is 1.155,
which would push about 0.5 of the amount into the rounding residue. Found by `dev` in Chunk B;
fixtures `tax-inclusive-fixed-before-compound` and `tax-inclusive-fixed-comes-off-first` fail
under the product wording. | **The exact linear rule replaces it**, verified against
`TaxCalculator::inclusiveNet` and both fixtures. Walk the surviving members in `sequence` order
with exact values `S = 0`, `K = 0`, `Fb = 0`. Fixed member: `Fb += round(rate × base_quantity, 4)`
(and `F`, the total of fixed amounts, grows the same way). Non-compound percentage, `f = rate/100`:
`S += f`. Compound percentage: `S' = S + f(1 + S)` and `K' = K + f(K + Fb)`, both from the old
values. Then refuse `inclusive_amount_too_small` if `F` exceeds the gross or if `R = gross − F − K`
is negative; else `net = round(R / (1 + S), 4)` half up, the only rounding in the back-out. Taxes
are computed forward from `net`; the residue `gross − net − Σ(all amounts)` goes to the last
percentage member in `sequence`. `1 + S` equals the old product only in the all-compound,
no-fixed case. The product-of-`(1 + rate/100)` wording is **superseded** everywhere. The TypeScript
twin (backlog FP-432) can implement this from AC-11.6 alone. | Reason: the exact relation
`gross − F = net × (1 + S) + K` is what the forward computation implies; any other divisor leaves
a residue that is not a rounding artefact. | Reversible: yes — one method.

D-26 | The pure engine refuses a negative line quantity as `invalid_amount` (money and quantity
inputs are non-negative strings, `Decimal`), although `UnitSnapshot` itself allows negatives. |
**Adopted as built.** Forward dependency for the Sales task (FP-233 / T-030): refunds there use
unsigned lines (the document type carries the direction), so this is consistent; if T-030 ever
needs a signed line it must revisit this decision, not work around it. | Reason: a sign inside a
money pipeline with half-up rounding is ambiguous. | Reversible: yes.

D-27 | A percentage discount whose value is above 100 is refused `invalid_amount` (checked in
`DiscountRule`); exactly 100 is allowed. | **Adopted.** | Reason: it would make a negative
discounted subtotal; `invalid_amount` is the closest reason in the closed list. | Reversible: yes.

D-28 | Two items of one price list for the same product and unit with equal `min_qty` are
ambiguous. | **Refused `invalid_amount`** by the pure ladder (`PriceLadder`), the closest reason in
the closed list. Complements AC-13's write-time duplicate refusal for data that arrives by another
path. | Reversible: yes.

D-29 | Ties between codes (price lists on equal priority; tax members on equal sequence fall to the
tax id) were compared with PHP's spaceship operator, which compares numeric-looking strings as
numbers, so `"9"` would sort before `"10"`. | **Compared bytewise (`strcmp`)** in the engine. **OPEN
RISK for architect, Chunk C/D:** the database adapter's SQL ordering follows the column collation
and can differ (case, accents, numeric strings). **Rule the adapter must meet:** its ordering is
identical to the pure engine's, proven by a test using codes that expose the difference (for
example `"10"`, `"9"`, `"a"`, `"B"`). Plan M-D1 (adapter passes lists unsorted) already leaves
ordering to the engine; the architect decides whether the adapter relies on that or sorts
bytewise. | Reversible: yes.

D-30 | What `base` a fixed tax shows in a breakdown line. | **The line amount** (the net in an
inclusive group), not the quantity, as built (`TaxCalculator::forward`; fixture
`tax-inclusive-fixed-comes-off-first` shows base `145.2174` for the fixed line). The TypeScript
twin must match. | Reason: `base` is the amount the tax was assessed against on the line, a
consistent field across member types. | Reversible: yes.

D-31 | **Corrects D-28** (plan finding K-9, a bug in shipped Chunk B code, fixed by plan step 10a).
`PriceLadder::greatestQualifying` refused a duplicate `min_qty` only when it tied the best
qualifying item found so far, so at base 10 the order 1, 1, 5 was refused but 5, 1, 1 was priced
from the 5-row: the result depended on row order. | **The duplicate-`min_qty` refusal
(`invalid_amount`) covers ANY two items of the unit keys the ladder checks (same product and
unit), whether or not they qualify**, so the result never depends on row order. The TypeScript
twin must give identical results. Spec: AC-30.3 reworded. | Reason: real data cannot hold
duplicates (unique key), but the pure engine is the single source of truth. | Reversible: yes.

D-32 | `discounts.applies_to` validation (plan finding K-11). | **Shape only**: the only allowed
keys are `products`, `categories`, `brands`, each a list of strings; no check that any id exists;
null or absent allowed. Violations are refused naming the field. Spec: AC-19 reworded, AC-30.7
added. | Reason: stored as supplied, but a malformed shape would make the engine's reading
undefined. | Reversible: yes.

D-33 | What `computeTax` does with an inactive tax group (plan finding K-12; the spec was silent). |
**Computed normally**; only an unknown id is refused `unknown_tax_group`. Spec: AC-11 step 0,
AC-30.8. | Reason: an inactive group is a configuration state for new assignments, not for quotes
already in flight. | Reversible: yes.

D-34 (code review R-1, Critical; **corrects D-25 and AC-11.6**) | D-25 gave the inclusive rounding
residue to the last percentage member in `sequence`. When that member's resolved rate is
`0.0000` (ordinary: a suspended levy, a zero-rated member) a negative residue makes its amount
negative, which the engine refuses `out_of_range`, so a valid sale is refused; a positive residue
puts tax on a 0 % line, so `amount != base x rate`. Verified against
`TaxCalculator` (the residue step and `lastPercentage` pick by type only). Worked case: 15 % VAT at
sequence 1, 0 % levy at sequence 2, inclusive, gross 9.9900: net 8.6870, VAT 1.3031, residue
-0.0001. | **The residue goes to the last percentage member, in `sequence`, whose resolved rate is
not zero.** A zero-rate member never receives it. Such a member always exists when the residue is
non-zero: if every percentage rate is zero then `S = 0` and `K = 0`, so `net = gross - F` exactly
and the residue is zero. Results: VAT 1.3030 and levy 0.0000 at gross 9.9900; VAT 1.3043 and levy
0.0000 at gross 10.0000 (net 8.6957); reversed order (0 % first, 15 % last) gives the same
amounts. Spec: AC-11.6 reworded with sub-criteria 6a to 6d, AC-30.9. Fixtures to add (named by the
review): `tax-inclusive-residue-skips-a-zero-rate-member`, the gross 10.0000 case, the reversed
order, and an all-zero group. The TypeScript twin (FP-432) implements this from AC-11.6 alone.
Residual: a tiny non-zero rate whose amount rounds to 0.0000 could still go negative on a -0.0001
residue; not ruled, flagged in the spec. | Reason: the residue is a rounding artefact and belongs on
a line that carries tax. | Reversible: yes, one selection in one method.

D-35 (code review W-3) | `UpdateTax` could change `type` (percentage to fixed turns every
historic 15.0000 from 15 % into 15.0000 per stock unit) or `is_compound` on a tax that already has
rates, silently changing how past documents re-resolve and what future quotes charge. | **Refused
(validation) while any `tax_rates` row exists for the tax**, message telling the caller to create a
new tax instead; unchanged values are accepted; with no rates the change is allowed. Spec: AC-5a.
| Reason: the same principle that makes a rate's `rate` and `effective_from` immutable. |
Reversible: yes.

D-36 (code review W-5; same shape as D-31) | With two rate rows on the same `effectiveFrom`, the
resolver keeps whichever came first (strict comparison), contradicting its own "does not depend on
row order" statement. Verified in `RateResolver::resolve`. | **The pure engine refuses
`invalid_amount` when ANY two rate rows of one tax share an `effectiveFrom`, qualifying or not.**
Spec: AC-7 extended, AC-30.10. Fixture to add: `tax-rate-duplicate-effective-from-is-refused`. |
Reason: real data is protected by the unique key, but the pure engine is the single source of
truth and the TypeScript twin must not copy an iteration order. | Reversible: yes.

D-37 (code review S-2) | `CloseTaxRate` accepted a rate whose `effective_to` was already set and
moved the date, which re-resolves documents already priced. | **Refused (validation) when
`effective_to` is already set.** Closing moves an open end date once; it is not an edit path. A
deliberate correction path is out of scope and stays a follow-up for the owner. Spec: AC-6a. |
Reversible: yes.

D-38 (code review W-4; **corrects D-32's silence on the read path**) | D-32 validated
`applies_to` on write only. The adapter turns a non-array, a non-list value or non-string entries
into empty lists, and three empty lists mean "applies to everything", so a bad stored value
(raw SQL, a future sync or import) widens a discount to every product. | **The pricing adapter
never widens a discount.** If the stored value is not the D-32 shape (not an object, a key outside
`products`/`categories`/`brands`, a value that is not a list, an entry that is not a string), the
quote is refused `invalid_amount`, the nearest reason in the closed list. Null or absent still
means everything. Spec: AC-30.11. An adapter test seeds a malformed row by raw insert. |
Reversible: yes (a named reason can replace `invalid_amount` later).

D-39 (code review W-1, W-2; no new business rule) | Two concurrency fixes the developer implements
from the review: the rate-overlap check and `CloseTaxRate` lock before any plain read; catalog's
tax-group validation uses a locking read in the same transaction. The spec had no sentence
implying otherwise; one Non-functional bullet records them. | **Recorded only.** | Reversible: yes.
