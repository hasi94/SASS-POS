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
