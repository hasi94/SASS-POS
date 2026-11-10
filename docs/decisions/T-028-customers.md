# T-028 decisions — customers, groups, credit ledger, loyalty

Autonomous/team-mode decisions. Each is the most conventional, safest, reversible choice available,
decided and logged rather than left blocking, per the team protocol. Numbering restarts for this
task's own file (T-027 precedent).

D-1 | Backlog row FP-232 ("Customers, groups, credit ledger and loyalty") and `docs/architecture.md`
§3's one-line module description name four things. `docs/database.md` §6.9 "Customers and CRM" lists
**eight** tables under that heading: `customer_groups`, `customers`, `customer_addresses`,
`customer_ledger`, `loyalty_programs`, `loyalty_transactions`, `customer_notes`,
`customer_communications`. Is this one task or does it split, the way T-027 (FP-230) split out
`barcode_rules` (FP-423)? | **One task, all eight tables.** Unlike `barcode_rules` — a genuinely
separate mechanism (a dual-language parser with its own fixture-parity discipline) — credit ledger and
loyalty are the same *shape* applied twice (an append-only ledger plus a maintained balance column,
the identical discipline T-006/T-027 already use for quantity), not a second kind of work. The CRM
tables (`customer_addresses`, `customer_notes`, `customer_communications`) are ordinary child-entity
CRUD with no missing-table dependency once `customers` exists, so splitting them out would be slicing
by prose rather than by mechanism. | Backlog precedent cuts both ways and this reads closer to T-008/
T-020/T-026 (one coherent domain built across many steps) than to T-027/FP-423 (two codebases, one
contract, a parity suite). Reversible: yes — any of the eight tables could be pulled into its own
follow-up task later without this spec's schema changing.

D-2 | What does "credit ledger" mean: a mutable balance column, a full transaction ledger with a
derived balance, or both? | **Both, with the ledger as the authority.** `customer_ledger` (append-only,
per `docs/database.md` §6.9) is the source of truth; `customers.credit_balance` is a **maintained**
column, written only inside the same transaction as a ledger insert, never edited independently —
exactly the "snapshot, don't recompute" discipline `docs/architecture.md` §21 and the
`account_balances`/`stock_levels.total_value` precedents already establish elsewhere in this schema.
`docs/database.md` itself documents the exact transaction shape at line ~1719: `SELECT customers FOR
UPDATE, check credit_limit, INSERT ar_documents, customer_ledger; UPDATE customers.credit_balance` —
this task builds the ledger-plus-maintained-balance half of that line (the `ar_documents` insert is
Finance's, D-7). | Not invented: the document already states the shape, this decision is reading it
rather than designing it. A single mutable balance column with no ledger would make "snapshot, don't
recompute" a rule this task's own domain violates on day one. Reversible: n/a (reading the design).

D-3 | What does "loyalty" mean concretely? `docs/database.md` §6.9 already specifies `loyalty_programs`
(earn/redeem/tier rules as JSON) and `loyalty_transactions` (append-only, signed `points`, maintained
`balance_after`), plus `customers.loyalty_points`/`loyalty_tier`. No further design is written down. |
**Adopt the documented shape as-is**: points accrual and redemption through an append-only
`loyalty_transactions` ledger, the same maintained-balance discipline as D-2, with `loyalty_tier`
derived from `tier_rules` JSON (D-11) rather than a second, independently-settable column. | Nothing
here is invented business logic — it is the schema already on the page, built for the first time.
Reversible: n/a.

D-4 | Are customer groups coupled to FP-231's price lists (`price_list_assignments.scope_type =
customer_group`)? | **No coupling built here.** `customer_groups` carries its own `discount_percent`
and `loyalty_multiplier` columns, stored and returned as plain data; this task does not read or write
`price_list_assignments`, and does not implement any pricing ladder. | `docs/database.md` §6.4 documents
the pricing ladder (`customer price list → customer-group list → ...`) as a resolution order owned by
FP-231's shared calculation engine, not by catalog or customer data entry — the identical boundary
T-027/D-9 already drew for `purchase_unit_id`/`sales_unit_id` versus `UnitResolver`. Inventing a read of
`price_list_assignments` here would duplicate FP-231's resolver. Reversible: yes — additive once FP-231
ships.

D-5 | Is a customer branch-scoped or tenant-wide? | **Tenant-wide.** `docs/database.md` §6.9's
`customers` row carries no `branch_id` column at all; only `customer_ledger` carries an **optional**
`branch_id FK NULL` ("where the activity happened," not an ownership scope). This matches the project's
core rule (`docs/architecture.md` §1) that the tenant database is the isolation boundary, branches are
not — the same reading T-010/T-014 already gave `employees`/`products` (branch-scoped *assignment*,
tenant-wide *identity*). | Reversible: n/a (reading the schema as written).

D-6 | Eight columns across this task's tables reference tables that do not exist in the shipped tree,
measured directly (no migration creates any of them): `customers.receivable_account_id` → `accounts`
(Finance, chart of accounts, TODO); `customer_ledger.ar_document_id` → `ar_documents` (Finance, TODO);
`loyalty_transactions.sale_id` → `sales` (FP-233, Sales, TODO); `customer_communications.delivery_id` →
a notification-delivery table (Notification module, TODO). | **None of these four columns are built by
this task** — exact precedent T-027/D-3 and D-4 already set for `products.tax_group_id`/`accounts`/
`kitchen_station_id` and `product_suppliers`/`suppliers`: a nullable FK with no target table is not a
foreign key, and building it now would not save the later step, since the FK-onto-an-existing-table
contract migration (per this codebase's migration source rules) is owed either way once the target
ships. Each owning task adds the column and the constraint together. For `loyalty_transactions.sale_id`
specifically, this also defers the documented `unique (sale_id, type) where sale_id not null`
idempotency key to FP-233 — until Sales exists, nothing can write a non-null `sale_id` anyway, so no
idempotency capability is actually lost today. | Reversible: yes, each independently, additive when the
owning table ships.

D-7 | Should this task include the CRM tables `customer_notes` and `customer_communications`, which
FP-232's own title text does not name ("groups, credit ledger and loyalty")? | **Yes, included** (see
D-1) — `docs/database.md` groups all eight tables under one §6.9 heading and neither CRM table has a
missing-table dependency once `customers` exists (aside from D-6's `delivery_id`, deferred on its own).
Excluding them would be slicing by the backlog line's prose rather than by any real technical or
mechanism boundary, the opposite problem from the one T-027/D-2 solved by splitting out `barcode_rules`.
| Reversible: yes — could be pulled into a follow-up task later with no schema change needed to do so.

D-8 | `customers.credit_balance`, `loyalty_points` and `loyalty_tier` are maintained columns (D-2/D-3).
Should the ordinary customer create/update handler accept them as input fields at all? | **No — refused
outright, not silently dropped.** A create or update payload that supplies any of the three is refused
in full (the whole request, not a partial apply), naming the offending field(s) — the identical shape
T-027/AC-22 uses for `stock_unit_id` immutability. Only the ledger-writing features (D-2/D-3) may change
these columns, and only inside the same transaction as the ledger/loyalty row they derive from. |
Silently ignoring the field would let a caller believe they set a balance that was quietly discarded —
worse than a visible refusal. Reversible: yes — relaxing to "ignored" is additive if ever wanted, though
there is no documented reason to want it.

D-9 | Should soft-deleting a customer with an outstanding non-zero `credit_balance` be allowed? Nothing
in `docs/database.md` states a rule. | **Refused when `credit_balance != 0`.** The soft-delete guard
mirrors the existing shape (T-027 AC-5/AC-8/AC-20): a reference or an unresolved state blocks deletion,
named in the refusal. An unsettled balance is exactly that kind of state — hiding a customer who still
owes, or is owed, money would make the AR-adjacent balance unreachable through the ordinary listing.
History (ledger/loyalty rows, addresses, notes) does **not** block deletion on its own — those rows
remain valid against the row's id either way. | The safe, reversible direction: refusing deletion while
money is owed is strictly more conservative than allowing it, never more permissive. Reversible: yes —
relaxing this is additive.

D-10 | `customers.possible_duplicate_of` and `merged_into_id` are documented columns with no documented
detection algorithm or merge procedure. What does this task build? | **Manual flagging only, plus a
merge feature scoped to what this task owns.** No automatic duplicate-detection matching rule is
invented (nothing documents one). A `MergeCustomers` feature reassigns this task's own child rows
(`customer_addresses`, `customer_ledger`, `loyalty_transactions`, `customer_notes`,
`customer_communications`) from the losing to the surviving customer in one transaction, recomputes the
surviving customer's `credit_balance`/`loyalty_points` by re-summing the reassigned ledger/loyalty rows
(not by naively adding the two prior balances, which would double-count nothing only by chance), sets
the loser's `merged_into_id` and soft-deletes it. Reassigning a **future** reference — `sales.
customer_id`, `ar_documents.customer_id`, `orders.customer_id`, and the rest §6.7/§6.10 name — is
explicitly **not** built here, since none of those tables exist yet; flagged as a note for whichever
task ships them (D-13). | The reversible, bounded reading: merge what you can prove correct today (your
own tables), not what you'd have to guess about tomorrow. Reversible: yes.

D-11 | How is `customers.loyalty_tier` derived from `loyalty_programs.tier_rules` JSON, and against
which balance — the current points balance, or a separate lifetime-earned total `docs/database.md`
does not carry a column for? | **Recomputed after every `loyalty_transactions` write, evaluated against
the customer's current `loyalty_points` balance** (the only balance a column exists for). | Building a
second, undocumented "lifetime points" column to drive tiering would be inventing a business rule
nobody wrote down; the current-balance reading uses only what the schema already states. This can read
wrong for a program that means "lifetime spend" by "tier," which the owner may clarify later.
Reversible: yes — a lifetime-points column is additive if the owner wants tiering on a different basis.

D-12 | Should a credit-extending ledger entry (`type = credit_sale` or `debit_note`) be refused when it
would push `balance_after` past `credit_limit`, and does `credit_allowed = false` block credit
regardless of amount? | **Yes to both, unconditionally, with no override path in this task.**
`credit_allowed = false` refuses any credit-increasing entry regardless of amount; `credit_allowed =
true` still refuses an entry that would push `balance_after` past `credit_limit`. `opening`, `payment`,
`refund`, `credit_note`, `adjustment` and `write_off` are never refused by this rule — they are either
corrective/reducing or (for `opening`) a one-time setup fact, not new credit being extended. No manager-
override/approval path is built here: `docs/database.md` §6.7's `approval_requests` table (which could
carry a `credit_limit` override type) belongs to Sales (FP-233/FP-234), not to this task — a future
caller that needs an override records its own approval and retries, or posts a correcting `adjustment`
row afterward. | The safe, reversible direction for a brand-new domain with no caller yet: refuse rather
than invent an override mechanism nothing has asked for. Reversible: yes — an override path is additive
once Sales exists to drive it.

D-13 | Symmetrically, should a `redeem` loyalty entry be refused when it would drive `loyalty_points`
negative? | **Yes, `redeem` only.** `earn`, `adjust`, `expire` and `transfer` are not refused by this
rule — `adjust` is an explicit manual correction, `expire`/`transfer` model points leaving by a
documented mechanism rather than a customer overspending a balance they do not have. | Mirrors D-12's
shape for the identical reason — this is the one type where "spending more than you have" is the actual
failure mode worth a hard refusal, not every negative-points case. Reversible: yes.

D-14 | Per the project's §3.2 convention ("a bare `DECIMAL` is a bug, not a shorthand") and no documented
precision for loyalty `points`, what precision does this task use? | **`DECIMAL(18,4)`** for every money
column this task adds (`credit_limit`, `credit_balance`, `debit`, `credit`, `balance_after`, `opening_
balance`, `lifetime_value`, `value` on `loyalty_transactions`), matching every other money column in
this schema. **`DECIMAL(18,2)`** for `loyalty_transactions.points` specifically, not an integer — an
`earn_rule` of "points per currency unit" can legitimately produce a fractional accrual (e.g. 0.5 points
per currency unit on a 3-unit sale), and rejecting that would be inventing a whole-number-only business
rule nobody documented. | Reversible: yes — narrowing `points` to an integer later is a data migration,
not a design change, if the owner states points are always whole.

D-15 | Does `customer_ledger` need the same `CHECK (debit = 0 OR credit = 0) AND (debit >= 0 AND credit
>= 0)` shape `journal_lines` carries (§6.10), even though §6.9's row for `customer_ledger` does not spell
it out? | **Yes, by the same convention.** The handler-level refusal is provable today against the
stand-in; the database `CHECK` constraint itself is `[ENGINE]`-fenced, NOT VERIFIED until G-1, matching
T-027/D-17's identical fencing for `units.precision`'s `CHECK` (SQLite cannot express or exercise a
`CHECK` constraint). The row-lock-and-recompute step of D-2/D-12 (`SELECT ... FOR UPDATE`) is fenced the
same way for the identical reason FP-300 already names for a different domain: correctness under
concurrent writers cannot be proven on a stand-in with no real row locking. | Consistency across every
ledger-shaped table in this schema is the point — a customer-money ledger is not entitled to a looser
constraint than the accounting ledger it feeds. Reversible: n/a (a fencing statement, not a business
choice).

D-16 | `customer_addresses` carries no `[SOFT]` tag (§6.9) — unlike every other table this task builds.
Is this a documentation gap or deliberate? | **Read literally: hard delete.** Removing a
`customer_addresses` row is a real `DELETE`, not a soft delete, since the schema as written gives it no
`deleted_at` column. | Inventing a soft-delete column the document does not list would be adding
schema beyond what is specified, the opposite error from D-6. If the owner wants addresses to survive a
customer merge/undo path, that is a future schema change, not a reading of today's document. Reversible:
yes — additive (`[SOFT]`) if ever wanted.

D-17 | `docs/backlog.md` FP-308 (found by `qa` on T-010): an employee list with a page size and no
offset/cursor made employees past the first page unreachable. Does this task repeat that gap? |
**No — every list feature this task ships (`customers`, `customer_groups`, `customer_addresses`,
`customer_ledger`, `loyalty_transactions`, `customer_notes`, `customer_communications`) supports
offset-or-cursor pagination from its first version**, stated as a non-functional requirement rather than
left to be found later by `qa` a second time. | Cheapest possible fix is building it in from the start;
FP-308 already measured the cost of not doing so once. Reversible: n/a (a built-in requirement, not a
reversible trade-off).

---

## Decisions D-18 to D-30 — added after `architect`'s plan-stage review (findings F-1 to F-19)

Committed plan: `docs/plans/T-028-customers.md` (`ab78fa3`). Thirteen findings (F-1 to F-13) needed a
spec or decision edit; six more (F-14 to F-19) are informational and are either accepted outright or
recorded as confirmed without changing this task's design. Numbering continues from D-17 rather than
restarting, since these are corrections to and extensions of the decisions above, not a fresh file.

D-18 (corrects the premise behind D-6, F-1) | The spec's "Current behaviour" claimed
`custom_field_definitions` exists (T-008/T-011), so `[CF]` on `customers` had somewhere to validate
against. `architect` measured this false: no migration creates it — T-008 D-4 deliberately left it out
of the tenant baseline, and nothing since has built it. T-027's own F-1 found the identical error on
that task. | **Corrected.** `customers.custom_fields` is not built by this task, following T-027/
AC-27a's precedent exactly; a `custom_fields` field in a create/update command is refused as unknown. |
A finding accepted as measured, not re-argued — `architect` read the same absence T-027's own review
already found once on a sibling task. Reversible: yes — additive once `custom_field_definitions` ships.

D-19 (corrects D-6, F-2) | D-6's own text said each owning task adds "the column and the constraint
together", and separately miscounted its own four deferred columns as "eight" in one place. `architect`
found (1) this codebase's migration source rules treat a foreign key onto an **already-existing** table
as a **contract**-phase migration, distinct from the expand step that adds the column — the identical
correction T-027's own F-2 made there; and (2) `docs/database.md` §6.16 makes `deliveries.id` a
**BIGINT AUTO** key, so `customer_communications.delivery_id` will be `BIGINT UNSIGNED` when built, not
a ULID like every other identifier column in this schema. | **Corrected.** Every D-6 deferral now states
"an expand migration (the column) followed by a separate contract migration (the foreign key)"; the
count is stated as "four" (named individually, never counted loosely again, per this backlog's own
FP-181/FP-184 convention); `delivery_id`'s eventual `BIGINT UNSIGNED` shape is flagged explicitly so the
owning task does not have to rediscover it. | Reversible: yes.

D-20 (corrects AC-13/D-10, F-3 — the important one) | D-10's original resolution had `MergeCustomers`
reassign `customer_ledger.customer_id` and `loyalty_transactions.customer_id` directly and then
recompute the target's balances by a fresh sum. `architect` found this **edits append-only financial
rows**, contradicting this task's own AC-19/AC-27 and `docs/database.md` §3.3 ("financial rows are
immutable after posting; a correction is a new row"), and corrupts every moved row's `balance_after`,
which describes the *source's* running balance, not the target's. | **Overturned, not merely corrected
— D-10's `MergeCustomers` design is replaced.** The merge moves the **balance**, not the rows, through
the same writers every other entry already uses: a compensating `adjustment` pair for credit (one entry
zeroing the source, a mirror entry crediting the target, references `customer_merge_out`/
`customer_merge_in`), and a compensating `transfer` pair for loyalty (the enum value exists for exactly
this). Only `customer_addresses`/`customer_notes`/`customer_communications` — which carry no append-only
constraint — move by a direct `customer_id` update. The source's ledger/loyalty rows **stay on the
source** as its permanently-closed history. The target must also be live and unmerged (merging onto a
dead record would let merges chain). | `architect`'s own alternative ("accept rows whose `balance_after`
no longer matches their own customer's running balance") was explicitly advised against, and rightly —
it would make the one invariant this entire domain exists to protect provably false on disk. The
compensating-entry mechanism is strictly safer and uses no writer this task does not already build for
an unrelated reason. Reversible: n/a (a correctness defect, not a preference).

D-21 (corrects AC-21, F-4) | AC-21's original wording ("a no-op that returns the original row, never an
error") would silently drop a second `payment` recorded against the same `(reference_type, reference_id,
type)` key but with a **different amount** — exactly the kind of silent data loss idempotency is meant
to prevent, not cause. `architect` cited `T-021`'s `RequireIdempotencyKey` precedent: a reused key whose
fingerprint differs is a refusal (`IDEMPOTENCY_KEY_REUSED`), not a silent success. | **Corrected.** A
replay is only a no-op when `customer_id`, `debit`, `credit` and `currency` all match the stored row;
any mismatch is refused as `replay_conflict`, naming the existing row's id. | The cheapest, safest
reading once a real precedent exists in this same codebase: match it rather than invent a softer rule.
Reversible: yes — loosening to "always accept silently" is additive if ever wanted, though nothing
documents a reason to want it.

D-22 (resolves a tension between AC-26 and AC-32, F-5) | AC-26 stored `tier_rules` as fully opaque JSON
with "no server-side schema validation beyond valid JSON," while AC-32 needs to evaluate that same JSON
against a points balance to produce a tier. As originally written, neither criterion could be
implemented without contradicting the other — opaque data cannot be evaluated, and an evaluation rule
needs something to evaluate. | **One minimal validated shape, decided rather than left opaque**: `null`,
or a non-empty list of `{tier: string ≤ 50 chars, min_points: decimal string ≥ 0, ≤ 4 dp}` objects, with
unique tier names and unique thresholds; the tier for a given balance is the entry with the greatest
`min_points` at or below it. `earn_rule`/`redeem_rule` stay fully opaque, because nothing in this task
ever evaluates either — the caller supplies `points` directly (AC-28). | The narrowest fix that makes
AC-32 implementable without inventing anything AC-26 didn't already imply by existing at all (a column
named `tier_rules` that nothing reads was always going to need *some* shape the moment AC-32 was
written). Reversible: yes — widening the shape (e.g. date-bounded tiers) is additive.

D-23 (corrects AC-28, F-6) | AC-28's original wording required an "existing, non-soft-deleted"
`loyalty_programs` row. `architect` measured `loyalty_programs` carries **no `[SOFT]` tag** in
`docs/database.md` §6.9 (`[ID] [TS]` only) — there is no `deleted_at` column for "non-soft-deleted" to
read. Separately, the earlier draft's retirement rule blocked only `redeem` against an inactive program,
leaving `earn` still possible against a program nobody can redeem from any more. | **Corrected on both
counts.** A program is retired via `is_active = false`, not soft-deleted; an inactive program refuses
**both** `earn` and `redeem` (`program_inactive`). `adjust`, `expire` and `transfer` remain allowed
against an inactive program, so a customer merge (D-20) and ordinary corrections still work after a
program is retired. | The asymmetric earn/redeem gate in the original draft was simply incomplete, not a
deliberate boundary — nothing documents a reason a retired program should still accrue new points.
Reversible: yes.

D-24 (corrects D-14/AC-40, F-7) | D-14 chose `DECIMAL(18,2)` for `loyalty_transactions.points`, reasoning
that a fractional earn rate is legitimate and no integer type is documented. `architect` measured that
`(18,2)` is **not** one of the four precision/scale pairs `TenantDecimalPrecisionTest` already allows
(§3.2: `(18,4)`, `(18,6)`, `(9,4)`, `(18,8)`), so building it would turn that static test red on arrival
— a self-inflicted gate failure, not a finding about business rules. Separately, AC-40's own column list
omitted five columns that exist in the real schema: `customers.loyalty_points`, `customer_groups.
default_credit_limit` (both `(18,4)`), and `customer_groups.discount_percent`/`loyalty_multiplier` (both
`(9,4)`, the rate/percentage pair). | **`points` corrected to `DECIMAL(18,4)`** — D-14's own underlying
reasoning (fractional accrual is legitimate) is satisfied just as well at scale 4, matching every
quantity column elsewhere in this schema, with zero cost. **AC-40's list corrected to the complete,
named set** across both precision pairs this task actually uses. | Keeping the fractional capability
while landing on a scale the gate's own allow-list already recognises is strictly better than either
inventing an integer-only rule or adding a fifth pair to the allow-list for one column. Reversible:
yes — tightening to an integer later is a data migration, not a design change, if the owner states
points are always whole.

D-25 (gap, F-8) | Neither the original spec nor D-2/D-14 addressed currency at all, despite
`customers.currency` and `customer_ledger.currency` both being documented columns — and a single
`credit_balance` cannot sum two different currencies without silently misrepresenting what it holds.
No company-currency table exists yet (`company_profile` is not built) to fall back on. | **An entry's
currency must equal its customer's own `currency`**, refused otherwise (`currency_mismatch`); a customer
with no `currency` set cannot take any ledger entry (`currency_required`); `UpdateCustomer` refuses a
currency change once any ledger row already exists for that customer; `MergeCustomers` refuses when the
two customers' currencies differ and the source's balance is not zero. | The safe, reversible direction
for a domain with no multi-currency conversion mechanism built anywhere yet: refuse mixing currencies
rather than silently add two numbers that mean different things. Reversible: yes — a real conversion
step is additive once `company_profile`/`exchange_rates` exist and are wired to this domain.

D-26 (gap, F-9) | AC-23/AC-30 as originally written described debit/credit sides and point signs as
what each type "normally" carries, which is not a rule a safety calculator can enforce — "normally"
has no refusal attached to it. | **Hard rules, enforced by the calculator before any row is written**:
credit ledger — debit-only (`credit_sale`, `debit_note`), credit-only (`payment`, `refund`,
`credit_note`, `write_off`), either-side (`opening`, `adjustment`); loyalty — `earn` positive, `redeem`
negative, `expire` negative, `adjust`/`transfer` either sign. A violation is refused (`wrong_side`/
`wrong_sign`). | Turning a descriptive "normally" into a checked rule is the whole point of building a
pure, testable calculator (AC-41) rather than leaving the arithmetic to each handler's own judgment call
every time. Reversible: yes — `refund` could be loosened to either-side later (a cash refund of an
overpayment is a plausible real case) with a one-row, one-table-cell change to the rule, not a redesign.

D-27 (gap, F-10) | AC-7 originally copied only `credit_limit`/`payment_terms_days` from the customer's
group at creation, leaving `credit_allowed` to default independently — so a customer created in a
credit-enabled group would still be refused credit until someone set the flag by hand, defeating the
point of a group default existing at all. Separately, nothing stated whether `credit_limit` could be
null, and "no limit" has no representation in a schema whose refusal rule is `balance_after >
credit_limit`. | **`credit_allowed` is copied from the group exactly like the other two** (defaulting to
`false` with no group); **`customers.credit_limit` is `NOT NULL DEFAULT 0`** — a customer needing no
practical ceiling is given a large literal limit, not a null one, and a customer marked
`credit_allowed = true` with a limit of `0` is refused every credit sale, which is the safe direction
when no real limit was ever set. | The original gap would have made every group's `credit_allowed`
default inert for any customer who didn't also get the flag set by hand — not a boundary, an oversight.
Reversible: yes.

D-28 (gap, F-11) | `customers.opening_balance` is a documented column that nothing in the original spec
addressed — a free-standing balance sitting beside a ledger-maintained `credit_balance` is exactly the
two-sources-of-truth problem D-2 exists to prevent, and leaving it unaddressed would have shipped that
problem by omission rather than by decision. | **`CreateCustomer` with a non-zero `opening_balance`
posts an `opening` entry through the credit ledger writer** in the same transaction as the customer row,
reference `customer`/the new id; this requires a `currency` to be set (D-25). `opening_balance` is
immutable after creation — an update supplying it is refused in full, the same shape as the other
maintained columns (AC-8). | The alternative — plain data nothing reads — would have been a silent
no-op feature: a shop could set an opening balance that never shows up in the ledger or the maintained
`credit_balance`, which is worse than not building the column at all. Reversible: yes — this is an
additive write path, not a schema change, if ever revisited.

D-29 (gap, F-12) | `merged_into_id` was described only as "settable manually" in AC-12's original
wording, with no statement that it was exempt from the ordinary customer update path — meaning an admin
could set it by hand and mark a merge that moved nothing. | **`merged_into_id` is writable only by
`MergeCustomers`** (D-20); the ordinary create/update handler refuses it whole, the identical shape AC-8
already uses for `credit_balance`/`loyalty_points`/`loyalty_tier`. `possible_duplicate_of` remains
manually settable and must reference another existing customer, never the row itself. | The same
"maintained column, refused on the ordinary path" discipline D-8 already established for three columns,
extended to the one more that needed it. Reversible: yes.

D-30 (gap, F-13) | Neither AC-20 nor AC-28 as originally written stated which customers may take a
ledger or loyalty write at all — nothing refused posting against a soft-deleted or already-merged
customer, and nothing distinguished `credit_sale`/`debit_note` (which should require an active customer)
from every other type (which should not, since a blocked customer can still have a payment or refund
recorded against them). | **Every ledger and loyalty write requires a live, unmerged customer**
(`customer_not_live` otherwise); **`credit_sale` and `debit_note` additionally require `status =
active`** (`customer_not_active`); every other type is allowed regardless of `status`. A merge's own
source-side compensating entries (D-20) are written before the source is soft-deleted, so they are not
refused by this same rule. | The precondition a financial write needs on its subject was simply absent
before; stating it explicitly, and narrowly (only the two credit-extending types need "active"), is the
safe default rather than either refusing everything or refusing nothing. Reversible: yes.

**Informational findings (F-14 to F-19), accepted or confirmed without a structural change:**

- **F-14** (accepted): `[SYNC]` columns on `customers`/`loyalty_transactions` are built present but
  inert — defaulted, carrying no foreign key, read by nothing in this task — rather than deferred
  entirely, since D-6's own test for deferring a column ("its target table is missing") does not apply
  to columns with no target table at all. AC-6 states this.
- **F-15** (accepted): `RecordLoyaltyTransaction` accepts an optional caller-supplied `id` as a replay
  key (the same id and payload is a replay; the same id with a different payload is `replay_conflict`),
  giving loyalty a real idempotency guarantee today rather than none, narrowing AC-33's "known
  limitation." Declining this would have left loyalty with no replay key at all until FP-233 ships
  `sale_id` — accepted as the clear improvement.
- **F-16** (corrects Assumptions): the `version`-column precedent cited was wrong — `restaurant_tables`
  and `payment_methods` do not exist in this codebase; the real precedent is `products.version` (T-027).
  Corrected in the spec's Assumptions section.
- **F-17** (confirmed): `customer_groups.code` uniqueness is this task's own addition, not something
  `docs/database.md` §6.9 states explicitly (only `customers.code` is marked unique there) — accepted as
  the same reasonable addition T-027/F-12 made for `brands`.
- **F-18** (confirmed, no edit needed): AC-32 evaluates `tier_rules` against the **program of the
  transaction just written**, not some notion of "the customer's one program" — a customer can
  legitimately accrue under more than one program, and inventing a "one active program" rule would be a
  business rule nobody documented. The spec's AC-32 already states this explicitly after D-22.
- **F-19** (accepted): soft-deleting the current default `customer_groups` row clears its `is_default`
  in the same transaction, so AC-2's backstop never has to represent "the default is a deleted row."
  AC-2 states this.
