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
at T-027's close-out and is wrong on this one point. `tax_group_id` gets the full expand +
backfill + contract (add nullable → backfill every existing product to a default tax group →
contract to `NOT NULL`). `purchase_tax_group_id` gets only an expand migration (add nullable FK)
— it is documented nullable, so no backfill and no contract step is needed or correct; adding a
contract step that tightens a documented-nullable column to `NOT NULL` would itself be the bug. |
Reason: `database.md` is this project's authoritative schema source per `CLAUDE.md`; a backlog
summary is not. | Reversible: yes — if the owner later states `purchase_tax_group_id` should be
`NOT NULL` too, that is a fresh expand+backfill+contract cycle, not a reopening of this call.

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
safest reversible guess — a shop can recode it per product afterward, but a migration that left
`tax_group_id` null and then failed the `NOT NULL` contract step would be a hard migration
failure with no remedy inside the migration itself. | Reversible: yes — a seeded default row can
be recoded or deactivated after migration; it is ordinary data.

D-7 | `tax_authorities.payable_account_id` is documented `FK accounts` with no `NULL` (i.e.
`NOT NULL`-documented), and `accounts` (Finance, chart of accounts) does not exist in this
codebase yet — the identical "FK onto a table that isn't built" problem T-027/T-028 both hit, but
discovered here inside this task's *own* new table rather than inherited from a prior task's
close-out. How is `tax_authorities` built without `accounts`? | **Same deferred-column mechanism,
applied up front.** `tax_authorities` is built now with `payable_account_id` and
`receivable_account_id` both nullable in the expand migration (deviating from the `NOT NULL`
documented for `payable_account_id`, because no legitimate value can exist until `accounts`
ships — a `NOT NULL` column with nothing valid to put in it is worse than a temporarily-nullable
one), with no FK constraint written yet. Whichever task ships `accounts` (Finance) owes the
contract migration: add the FK, backfill every existing `tax_authorities` row to a real account,
then tighten `payable_account_id` to `NOT NULL` as documented. `discounts.sales_discount_account_id`
is already documented nullable, so it needs only the expand half (add the column, no FK yet) —
no backfill or contract owed by Finance beyond adding the key itself. | Reason: identical
reasoning to T-027 D-3/D-4 and T-028 D-6, applied consistently rather than inventing a different
shape because this time the gap is inside the task's own new table instead of an older one. |
Reversible: yes — the standard expand/contract shape this codebase already uses everywhere else.

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
