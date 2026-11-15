# T-030 decisions — Sales: cart, tenders, hold and recall, refunds, voids, cash shifts

Autonomous/team-mode decisions. Each is the most conventional, safest, reversible choice
available, decided and logged rather than left blocking, per the team protocol. Numbering
restarts for this task's own file (T-027/T-028/T-029 precedent). This spec is written
**spec-ahead** of two unfinished dependencies (T-028/FP-232 Customers, T-029/FP-231
Taxes/pricing) — every decision that touches either names the dependency as a contract and
flags where its exact shape is still moving, rather than redesigning it.

D-1 | `docs/backlog.md` lists FP-234 ("Stock validated and decremented inside the sale
transaction, rejecting insufficient stock") as its **own** row, separate from FP-233, and
widened by T-006 independently of FP-233. Does this task perform real stock validation or
decrement when a sale completes? | **No.** This task builds the sale/refund/void/hold/tender/
shift *documents* and their state machine only. `sales.stock_applied` is written `false` by
every path this task ships and is never set `true` here; `sale_items.unit_cost`/`line_cost` and
`sales.cost_total` (the COGS snapshot, documented as a copy of `stock_levels.avg_cost`) stay
`NULL` — there is no `stock_levels` table yet (Inventory, FP-250, TODO) to copy from. Whichever
task implements FP-234 is the one that validates and decrements stock inside `CompleteSale`'s
own transaction and flips `stock_applied`; this task's `CompleteSale` handler completes without
calling any stock mechanism at all — not a stub, not a feature flag, nothing to switch on later.
| Same split precedent as T-027/FP-423 (barcode parsing) and T-029/FP-432 (the TypeScript
engine): FP-234 is a named, independently-scoped backlog row with its own widening note, not a
sub-step of this one. Building a stock check against a ledger that does not exist would be
inventing the mechanism FP-234 exists to build. Reversible: yes — FP-234 is additive once
Inventory ships.

D-2 | `sale_items.unit_cost`/`line_cost`/`refunded_quantity` and `sales.cost_total` are
documented columns this task's own schema widening (`[UOM]`) touches directly. Given D-1, are
they built at all? | **Built as nullable (or zero-default where the schema already defaults
them), always unwritten by this task.** The `[UOM]` quad itself (`unit_id`, `unit_factor`,
`quantity`, `base_quantity`) **is** written by every line this task creates — that is this
task's own core widening and has nothing to do with Inventory. Only the **costing** columns
(`unit_cost`, `line_cost`, `cost_total`) stay null, because they require `stock_levels.avg_cost`,
which does not exist. `refunded_quantity` is written and maintained by this task's own refund
path (D-9) — it is a rollup over this task's own documents, not a copy from Inventory. |
Separating "what this task's own widening requires" from "what Inventory's absence blocks" is
the same test T-027 D-3 and T-029 D-7 already applied to FK columns, extended here to plain
decimal columns with no FK at all. Reversible: yes — FP-234/FP-250 populate them later without a
schema change.

D-3 | `docs/backlog.md`'s FP-233 row states this task **owes** `UnitResolver::
snapshotInherited()`, "reuses `SnapshotCalculator`, not a new calculation." `UnitResolver` is a
published contract in `packages/contracts/src/Catalog/`, implemented by
`packages/catalog/src/Units/DatabaseUnitResolver` (T-027, DONE). Does this task edit a package
it does not own? | **Yes, by the backlog's own explicit instruction.** This task adds one method
to the published `Contracts\Catalog\UnitResolver` interface and its `DatabaseUnitResolver`
implementation: `snapshotInherited(string $referencedLineUnitId, string $referencedLineFactor,
string $requestedUnitId, string $stockUnitId): UnitSnapshot` (exact signature is `dev`'s to
design) — given the unit and factor a prior line already snapshotted, and the unit the new
(refund/exchange) line is entered in, it returns a `UnitSnapshot` that **reuses the inherited
factor when `requestedUnitId` equals the referenced line's unit**, and otherwise converts
**base-to-base** (never re-walking the ladder), exactly as `docs/database.md` §6.5 states for
every inheriting line (`purchase_return_items.grn_item_id`, `sale_items.refund_of_item_id`, and
the other two named there). No new rounding or ladder logic is introduced — it calls the existing
`SnapshotCalculator` the same way `snapshot()` already does, just seeded from a frozen factor
instead of a freshly-resolved one. This is the only edit this task makes outside `packages/
pricing`'s own new package and `packages/customers`' existing one (D-4/D-5). | The backlog row
is explicit that this task owes the method, and the method's *reason to exist* (refund/exchange
lines) is this task's own domain — building it anywhere else would separate the method from its
only caller. Reversible: yes — the new method is additive to an existing interface; nothing
about it changes `snapshot()`'s existing behaviour or signature.

D-4 | Every priced cart/sale/refund line needs tax and price-list resolution.
`packages/pricing` (T-029/FP-231) is still mid-implementation on its own branch, not merged. Does
this task design pricing arithmetic? | **No.** Every line this task prices calls
`Contracts\Pricing\PricingEngine::quoteLine()` (per T-029's own published contract, Ruling 2),
passing a `UnitSnapshot` from `UnitResolver` (never re-deriving a factor itself) and reading back
`unitPrice`, `subtotal`, `discountAmount`, `taxGroupId`, `TaxBreakdown`, `lineTotal`. A bill-level
discount calls `PricingEngine::billDiscount()`. This task's spec states the **call shape and the
columns each return value lands on** (`sale_items.unit_price`/`list_price`/`discount_amount`/
`tax_amount`/`tax_breakdown`/`line_total`, `sales.bill_discount_value`), never the arithmetic
inside the engine. | Matches the explicit instruction to name `packages/pricing` as a contract
dependency, not redesign it — the same boundary T-028/D-4 already drew against FP-231 for
customer-group pricing. | Reversible: n/a (a boundary statement). **Flagged**: `PricingEngine`'s
exact method/DTO names are still moving on T-029's own branch (two plan revisions already
corrected its shape) — this spec's references are to the contract's *documented intent*
(quote a line, quote a bill discount, take a `UnitSnapshot`, return a tax breakdown), and
`dev` reconciles the literal signature against whatever T-029 actually ships before writing a
line of Sales code, exactly as the task brief instructs ("read it directly from the other
worktree... once committed").

D-5 | A `credit_sale` tender (paying on account) needs a customer credit-ledger entry.
`packages/customers` (T-028/FP-232) is also still mid-implementation, not merged, and its own
D-6 explicitly defers `loyalty_transactions.sale_id`'s foreign key "until Sales exists." What
does this task call, and does it build loyalty earning? | **This task calls a published customer
credit-ledger contract** (T-028's own eventual `Contracts\Customers\...` shape — not yet named on
T-028's branch at spec time) to post a `credit_sale` entry when a sale's balance is settled by
store credit, passing the sale's id as the entry's `reference_id`/`reference_type = 'sale'` (the
same reference pair `docs/database.md` §6.9 documents `customer_ledger` keying on). **Loyalty
earning is explicitly not automatic.** Nothing in `docs/database.md` or the architecture states
that completing a sale always posts a `loyalty_transactions` row, and T-028's own `loyalty_
transactions.sale_id` is optionally-null by design (not every ledger entry is sale-sourced) — so
this task does not call a loyalty-earn feature from inside `CompleteSale`. A future caller (the
till UI, or a later task) that wants "earn points on every sale" invokes Customers' own
`RecordLoyaltyTransaction` directly, supplying this task's own `sales.id`, after the sale
completes. | Mirrors D-4's boundary exactly (name the contract, do not redesign the domain it
belongs to), and additionally avoids inventing an "earn on every sale" business rule nobody
documented — the identical caution T-028/D-11 and T-029/D-8 already applied to their own
undocumented trigger questions. Reversible: yes — auto-earning is additive once a real earn
policy is written down. **Flagged**: the exact contract name/shape is unconfirmed until T-028
lands; `dev` reconciles against the real published interface before building.

D-6 | `sales.journal_entry_id`, `sales.ar_document_id`, `cash_shifts`' `[GL]` tag, and `cash_
movements.financial_account_id FK NULL` all reference Finance tables (`journal_entries`,
`ar_documents`, `financial_accounts`) that do not exist (FP-260/FP-263, both TODO). Does this
task build these as key-less placeholders? | **No — deferred entirely, built present where the
column is a plain nullable field with no FK at all, and simply not built where the schema would
require one with no target.** `sales.ar_document_id` and `cash_movements.financial_account_id`
are both documented `FK NULL`-nullable on an existing design; this task ships them as plain
nullable columns with **no** foreign-key constraint (the FK itself is Finance's contract-phase
migration to add once `accounts`/`ar_documents` exist, following T-027/D-3's and T-029/D-5's own
precedent for an FK onto a not-yet-existing table). Nothing this task writes ever sets either
column to a non-null value — there is no accounting posting service to call (`docs/
architecture.md` §10's "one posting service" is Finance's, unbuilt). `journal_entry_id` is **not
built at all** on `sales` in this task (it is not required by any AC this task states, and its
only shown appearance is the summary ERD, not the column list) — same test T-027/D-3 applied to
six columns, applied here to one. `[GL]` on `cash_shifts` is likewise inert: the shift's own
totals (`gross`, `discounts`, `tax`, `net`, `totals_by_method`) are written and readable, but no
code in this task posts them to a ledger. | Consistent application of the already-established
"defer the whole column, not a key-less placeholder" rule, now applied to a plain nullable
column with genuinely no FK rather than only to FK columns — the same caution either way: Finance
would otherwise inherit cleanup of unvalidated references. Reversible: yes, additive once
FP-260/FP-263 ship.

D-7 | Does this task build the full `payments`/`payment_allocations`/`gateway_configs`/
`gateway_transactions`/`settlements` apparatus documented in `docs/database.md` §6.8
("Payments"), given FP-233's own title names only "tenders"? | **No — this task builds `sale_
tenders` and a minimal `payment_methods` table only.** A tender is recorded directly on `sale_
tenders` (method, amount, tendered, change, reference) with no parallel `payments`-table
projection, and `payment_methods.financial_account_id`/`gateway` stay unbuilt/unused (D-6's same
reasoning — no Finance account to point at, and `docs/architecture.md`'s own open question 3
states "no payment gateways live at launch; cash, card as reference, and credit"). The full §6.8
apparatus — idempotent gateway capture/refund, settlement reconciliation, AR allocation — has no
documented trigger in this task's own backlog line and no live gateway to drive it. | FP-233's
own title is the scope boundary ("tenders," not "payment processing"), reinforced by the
product's own stated launch default (no live gateways) — building reconciliation machinery for
gateways nobody will configure yet would be speculative. Filed as a forward note on Out of scope
rather than a new backlog row, since whichever task first wires a live gateway or AR allocation
is the natural owner. Reversible: yes — `sale_tenders.gateway_transaction_id`/`payment_id` stay
documented, nullable, unused columns ready for that task.

D-8 | `sales.price_from_label` and the till's ability to enter a weighable line by a scanned
scale label both depend on `barcode_rules` (FP-423, TODO, catalog's dual-engine parser). Does
this task build any part of that? | **No.** `price_from_label` is written `false` by every line
this task creates; nothing in this task parses a barcode or infers a quantity from an embedded-
weight label. A weighable product's line quantity is always caller-supplied (typed or from a
connected scale outside this task's API surface) in this task's own scope. | FP-423 is the named
owner of the parsing mechanism; this task provides the column and nothing that fills it with
anything but the default. Reversible: yes, additive once FP-423 ships.

D-9 | `sale_items.kitchen_station_id` depends on a kitchen-station table (FP-239, Printing/
Restaurant, TODO). Does this task build it? | **No — same treatment as T-027/D-3's identical
column on `products`/`categories`.** The column is not built on `sale_items` in this task. |
Consistent application of an already-established rule to the same kind of gap recurring on a
sibling table. Reversible: yes.

D-10 | `docs/database.md` §6.7 lists `quotations`/`quotation_items` and `sales_orders`/`sales_
order_items` beside `sales` under one heading, and also `floor_areas`/`restaurant_tables`/
`orders`/`order_items`/`kots` (the restaurant domain) and `type = exchange` sales. None of these
is named in FP-233's own title ("cart, tenders, hold and recall, refunds, voids, cash shifts").
Are they in scope? | **No to all.** Quotations and sales orders are pre-sale documents with their
own lifecycle (draft → sent/confirmed → converted) that do not touch a cash shift or a tender at
all — a different mechanism, not a sub-step of the till. The restaurant tables (`floor_areas`
through `kots`) are explicitly the **Restaurant** module in `docs/architecture.md` §3's own table,
a sibling of Sales, not a part of it. `type = exchange` is a real, documented sale type (closing
G-10) but is not named in FP-233's title, and building it correctly needs both a refund path and
a sale path reconciled into one document — cleaner as its own follow-up once both this task's
refund flow and FP-234's stock-decrement are real, rather than guessed at now. | Reading the
backlog row's own title as the scope boundary, the same discipline T-027/D-2 applied when it
split `barcode_rules` out by naming the row precisely rather than by the module table's prose.
Filed as a backlog note (new row, logged in the spec's Out of scope) rather than silently
dropped. Reversible: yes — each is additive, no schema conflict with what this task ships.

D-11 | `sales.price_list_id` is a documented header-level override. Does this task implement a
way to force a specific price list onto every line of a sale, bypassing the per-line ladder
`PricingEngine` otherwise runs? | **No — the column is built, stored and returned, but this
task's `quoteLine()` call never reads it as a forcing parameter**, because T-029's own published
`LineQuote` DTO (Ruling 2) carries no "forced price list" field — there is nothing in the
dependency's contract to pass it to. If T-029 ships such a parameter before this task is
implemented, wiring `sales.price_list_id` through is a pure addition to the call site, not a
redesign. | Consistent with D-4's boundary: this task does not invent a pricing-engine capability
that contract does not expose. Reversible: yes.

D-12 | `approval_requests.decision_method enum(pin_local, remote)` — does this task build both
decision transports? | **`pin_local` only.** A manager/supervisor PIN entered at the requesting
terminal is verified through Identity's existing PIN-verification mechanism (T-010, DONE) —
exact contract name to be confirmed against that package at build time, named as a dependency
per the same discipline as D-4/D-5. `remote` (an approval decided from another device or the
back office while the till waits) has no documented transport (no push mechanism, no second
terminal session concept exists yet) and is not built: the enum value is stored as a legal
column value for forward compatibility, but no feature in this task can produce a `remote`-
decided row. | `docs/database.md` §6.7 itself calls the in-till PIN path "the deliberately
separate, offline-capable" mechanism — that is the one with no undocumented transport to invent.
Reversible: yes, additive.

D-13 | For which `approval_requests.type` values does this task build a request/decide flow?
(`discount`, `refund`, `void`, `price_override`, `credit_limit`, `negative_stock`, `custom`.) |
**`discount`, `void`, `refund`, `price_override` are built** — each is triggered by this task's
own domain logic (a discount exceeding a caller-supplied threshold, voiding a completed sale,
refunding without the original sale present, or a cashier overriding a resolved price). `credit_
limit` is **not built here** — it is Customers' own refusal (T-028/D-12: "no manager-override/
approval path is built in this task... a future caller records its own approval and retries"),
and this task is that future caller only in the sense that it is free to request one, but
building the trigger means reading Customers' refusal reason, which is this task's to wire once
both exist, not to pre-build blind. `negative_stock` is **not built** — it requires FP-234's
stock-validation to exist at all (D-1). `custom` is a plugin extension point this task does not
exercise. | Scoping each trigger to a check this task's own domain can actually observe today,
the same discipline D-1/D-2 already apply to stock. Reversible: yes, each additive once its
triggering domain exists.

D-14 | Is an approval required before voiding a **completed** sale, before voiding a **not-yet-
completed** one (draft/held/pending_payment), and before any refund? Nothing in `docs/database.md`
states a rule distinguishing these. | **Voiding a sale that has not yet completed needs no
approval** (nothing financially final has happened — the cart is simply abandoned, matching the
architecture's own phrase "writes nothing financially final"). **Voiding a completed sale, and
every refund, requires a decided (`approved`) `approval_requests` row of the matching type before
the write is accepted**, refused otherwise (`approval_required`). | The safe, reversible default
for a financial-correction path with no documented exception: require sign-off once money has
actually moved, not before. Reversible: yes — a configurable exemption (e.g., self-approval
within N minutes) is additive if the owner wants it later; nothing here documents one.

D-15 | Can a cashier void or refund their **own** completed sale by approving their **own**
approval request (self-approval), given PINs are per-employee but nothing states a segregation
rule? | **No — the decider's employee id must differ from the sale's own `employee_id`**, refused
otherwise (`self_approval_not_allowed`). | The conventional retail-POS control (and the entire
reason a PIN-gated approval exists rather than a simple confirmation dialog) is that a second
person signs off; allowing the same person to approve their own correction would make the gate
decorative. Reversible: yes — relaxing this for a single-cashier shop is a configuration flag a
future task could add; nothing here forecloses it.

D-16 | What exactly does `VoidSale` do to stock and money, given D-1 says this task performs no
real stock decrement at all — so a "completed" sale under this task's own scope never actually
moved stock or posted to a ledger in the first place? | **`VoidSale` is a pure status transition**
on `sales`/`sale_items` (`status = voided`, `voided_by`, `void_reason`, `voided_at`) plus the
**reversal of any recorded tenders** (each `sale_tenders` row is reversed by a mirror entry of the
opposite sign, never edited or deleted, matching this schema's "financial rows are immutable"
convention already applied elsewhere in this codebase). It does **not** write a `sale_void` stock
movement (D-1 — there is no real movement to reverse yet) and does **not** touch any customer
ledger row beyond what D-5 already scopes (a `credit_sale` tender that is reversed posts its own
compensating ledger entry through the same Customers contract D-5 names, mirroring a `payment`
type). | The honest statement of what this task can actually do without Inventory/Finance: void
the document and reverse the money this task itself recorded, and nothing it did not itself
record. Reversible: yes — FP-234 adds the real stock reversal without touching this shape.

D-17 | Symmetrically, what does `RefundSale` write, given the same absence of real stock/ledger
machinery? | **A new `sales` row, `type = refund`, `reference_sale_id` pointing at the original,
with its own `sale_items` rows (`refund_of_item_id` set, inheriting the original line's unit via
D-3's `snapshotInherited()`), its own `sale_tenders` (money paid back — negative-signed tenders,
per the existing `sale_tenders.amount signed` column), and its own pricing (tax/discount
re-quoted through `PricingEngine` for the refunded quantity, not copied verbatim from the original
line, because a partial refund of a line that itself carried a quantity-tiered price-list row can
legitimately re-resolve a different price-list step — the engine, not this task, decides that).**
No stock movement and no GL posting are written, for the identical reason D-16 gives. A refund
can be **partial** (fewer lines, or a smaller quantity on a line, than the original) or **full**.
| Reading "a refund is its own flow, not a negative sale" literally: it is a new, independently
numbered document, not a signed adjustment bolted onto the original row. Reversible: yes —
FP-234/Finance wiring is additive once each exists.

D-18 | Can a line be refunded for **more** than its own remaining (un-refunded) quantity, or can
a sale be refunded after it has already been fully refunded? | **No to both — refused.**
`sale_items.refunded_quantity` (already a documented rollup column, D-2) is checked before every
new refund line: `requested_base_quantity + refunded_quantity ≤ original_line.base_quantity`,
refused otherwise (`over_refund`) naming the remaining quantity. A sale whose every line's `base_
quantity` already equals its `refunded_quantity` is itself refused for any further refund
(`fully_refunded`), and its `sales.status` reaches `refunded` (vs. `partially_refunded` for a
sale with some, not all, lines at or above zero remaining). | The conventional, safe default for
a reversal mechanism with no documented tolerance for over-refunding: a hard ceiling at exactly
what was sold. Reversible: yes — relaxing to allow goodwill over-refund is additive if ever
wanted; nothing documents a reason to want it.

D-19 | Can a refund be created with **no** original sale at all (`refund_of_item_id` null on
every line) — a "goodwill return with no original line," which `docs/database.md` §6.5 note 3
explicitly anticipates for the stock-movement side? | **Yes, built as a documented capability**:
a refund with `reference_sale_id = null` is legal, each line's `refund_of_item_id` stays null,
and `UnitResolver::snapshot()` (the ordinary ladder, not `snapshotInherited()`) resolves its unit
factor fresh, since there is no prior line to inherit from. It still requires the same approval
gate as any other refund (D-14) and the same tender-reversal shape (D-17). | The schema's own
note anticipates this exact case for the stock side; building the sales-document side to match
is completing a case the design already names rather than inventing one. Reversible: yes.

D-20 | How is a sale's **number** allocated — the "invoice numbers allocated per terminal from a
reserved prefix" rule `docs/architecture.md` §12 states? | **Through Organization's existing `number_
sequences` mechanism** (T-010/T-014, DONE; `docs/database.md` §6.1, widened for the branch-scoped
uniqueness fix FP-180 already closed) — named as a dependency, not redesigned, the same boundary
D-4/D-5 apply to pricing and customers. `document_type = 'sale'` (and `'refund'` for refund
documents, since they are their own numbered document per D-17) are the two sequence keys this
task consumes; it does not add a third document type to the sequence enum beyond what Organization
already supports unless that package's own enum needs widening, which is this task's to do when it
is actually built, not to pre-guess here. | Consistent with the rest of this spec's boundary rule:
read an existing, shipped mechanism rather than inventing a second numbering scheme. Reversible:
n/a (reading an existing contract).

D-21 | `cash_shifts.status` reaches `reconciled` as its terminal state. `docs/backlog.md`'s FP-263
("Cash and bank accounts, transfers, petty cash," Finance, TODO) is the natural owner of matching
a shift's cash drops against a real bank deposit. Where does this task's responsibility end? |
**This task owns `open` → `closing` → `closed` → `reconciled` as a status progression with no bank-
matching logic behind the last step.** `CloseCashShift` requires a `counted_cash` figure from the
cashier, computes `variance = counted_cash − expected_cash`, and sets `status = closed`.
`ReconcileCashShift` is a separate, later feature (callable by a supervisor, not necessarily the
cashier who closed it) that simply records `reconciled_by`/the transition to `reconciled` — it
performs no comparison against any Finance record, because `financial_accounts`/bank-statement
data does not exist (D-6). Finance's own task is free to add a real matching step **before**
allowing that same transition once it exists; this task's `reconciled` is a placeholder milestone,
not a claim that reconciliation happened. | The same "defer the whole mechanism, not half of it"
discipline applied to a status value instead of a column: it is honest about doing nothing
automatic rather than quietly implying a check that isn't there. Reversible: yes.

D-22 | Can more than one cash shift be open on the same terminal at once, or must a terminal have
an open shift before a cashier can create a sale at all? | **At most one open shift per terminal**
(`OpenCashShift` refuses `shift_already_open` if the terminal has one), and **`CreateSale` refuses
`no_open_shift`** when the terminal has none — the conventional retail-POS rule (a till cannot ring
up a cash sale with no drawer declared open), and the only reading consistent with `sales.shift_id
FK cash_shifts NULL` (nullable only because a non-cash-tendered, back-office-created sale might
legitimately have none — see D-23). | Nothing documents an exception, and the safe default is to
require the shift the schema's own FK suggests every till sale carries. Reversible: yes — a
documented exception (e.g., a card-only kiosk with no drawer) is additive.

D-23 | `sales.shift_id FK cash_shifts NULL` is nullable. Given D-22's rule, when is it actually
null? | **Only for a sale created through a path this task does not build** — there is no "back-
office sale creation" feature in this task's own scope (every `CreateSale` call in this task
requires a terminal and therefore an open shift, D-22). The column stays nullable because the
schema is shared with any future non-till sale path, not because this task's own writes ever
leave it null. | Reading the nullability as "future-proofing for a document shape outside this
task," not as "this task's own normal case," the same distinction D-2 draws for costing columns.
Reversible: n/a (a reading, not a choice).

D-24 | Does `HoldSale`/`RecallSale` reassign `sales.employee_id` (the cashier/commission key) to
the recalling cashier, or keep the original? | **Kept unchanged.** `RecallSale` updates `terminal_
id` to the recalling terminal (so "possibly by a different terminal" is satisfied) and records who
recalled it in an append-only history (a `hold_recall_log`-shaped audit trail — `dev`'s table name
to choose, not a business rule), but `employee_id` stays the cashier who opened the sale, because
it is documented as the commission/payroll key (`docs/database.md` §6.7, line 1199's own
parenthetical: "commission and payroll key off this"). A deliberate cashier reassignment is a
separate, undocumented feature this task does not build. | Protecting a column the schema itself
states is load-bearing for a downstream module (Commission, FP-255) from being silently overwritten
by an unrelated convenience feature. Reversible: yes — an explicit "reassign cashier" action is
additive if ever documented as wanted.

D-25 | Can a **held** sale be recalled by resuming it into `pending_payment` directly (skip
`draft`), or must every recall land back in `draft`? | **Recall always lands in the status the sale
held in immediately before `HoldSale` was called** (recorded on the hold itself), which for every
sale this task can produce is `draft` or `pending_payment` (the only two statuses a sale can be
held from, since `held` is reachable only from those two) — never a fixed single target status. |
The literal reading of "a sale can be parked and resumed": resuming restores what was parked,
rather than resetting it to a fixed starting point that could silently discard an in-progress
partial payment. Reversible: n/a (reading the feature's own stated purpose).

D-26 | Every list feature this task ships — does it repeat FP-308's "a page size with no offset/
cursor made rows past the first page unreachable" gap, the same standing check T-028/D-17 already
applied? | **No** — every list feature this task ships (sales, sale lines read-back, approval
requests, cash shifts, cash movements) supports offset-or-cursor pagination from its first
version, stated as a non-functional requirement rather than left to be found later by `qa` a
second time. | Cheapest possible fix is building it in from the start, per the same measured cost
T-028 already cited. Reversible: n/a (a built-in requirement).

D-27 | `docs/database.md` §3.2's "a bare `DECIMAL` is a bug" convention and the four allowed
`(precision, scale)` pairs `TenantDecimalPrecisionTest` enforces (`(18,4)`, `(18,6)`, `(9,4)`,
`(18,8)`) — which pairs does this task's own new money/quantity columns use? | **`DECIMAL(18,4)`**
for every money and quantity column this task adds that is not a rate (`sales.subtotal` through
`balance_due`, `sale_items.unit_price` through `line_cost`, `cash_shifts`' money columns, `cash_
movements.amount`), matching every other money/quantity column in this schema; **`DECIMAL(18,6)`**
is reserved for `unit_factor` only (already the documented scale, §6.5), never reused here for
anything this task adds, since nothing this task writes needs 6 dp. No fifth pair is introduced. |
Reusing the existing allow-list rather than widening a static test that exists specifically to
keep that set small and intentional — T-029/D-10's identical reasoning. Reversible: yes —
narrowing or widening later is a data migration, not a design change.

D-28 | `docs/database.md` §6.7 line ~1211 states `cash_shifts`' `[SYNC]` tag and this schema's
general offline-replication intent (`docs/architecture.md` §12). Does this task build the offline
push/pull mechanism for sales/shifts? | **No.** `[SYNC]` columns (a client-generated id, a per-
terminal sequence, `client_updated_at`-shaped fields where documented) are built **present but
inert** on every table this task ships that carries the tag — defaulted, read by nothing in this
task's own handlers — exactly the same treatment T-028/F-14 already gave its own `[SYNC]` columns,
for the identical reason: the offline sync mechanism itself is FP-237's row, not this one's, and a
column with no target table to defer against (unlike an FK) is built present rather than omitted.
| Consistent with the established "defer the mechanism, not the column, when there is no missing
table to point the column at" rule. Reversible: yes, additive once FP-237 wires real sync.

---

## Decisions D-29 to D-50 — added after `architect6`'s review of the plan (findings F-1 to F-21)

Committed plan: `docs/plans/T-030-sales.md`. `architect6` read T-027/T-028's real shipped code
and T-029's committed plan directly (not this spec's paraphrase of either) and found 21
inconsistencies (4 serious — F-2 to F-5 — plus F-1, F-6, F-7, F-10, F-12, F-13, F-15 to F-17 that
change behaviour, and F-8, F-9, F-11, F-14, F-18 to F-21 that are small corrections). Each is
resolved below rather than left blocking, per the team protocol, continuing the numbering from
D-28 rather than restarting, since these are corrections to and extensions of the decisions above.
Two items (F-1's bill-discount question and F-13's refund-price question) were offered to the
lead as product questions; the lead ruled on both directly and D-35/D-38 below record that ruling
rather than leaving it open.

D-29 (corrects D-5, D-16, AC-12/AC-21; F-2, F-3, F-4 — the important ones) | Three defects in the
original credit-ledger design, found by `architect6` reading `packages/customers`' real shipped
contract rather than this spec's assumption of its shape:
1. **The ledger key was wrong (F-2).** `customer_ledger` is unique on `(reference_type,
   reference_id, type)`. D-5 keyed a `credit_sale` entry on `sales.id` — a sale with **two**
   credit tenders would collide on that key (a silent replay for the same amount, `replay_conflict`
   for a different one), losing the second tender's debit entirely.
2. **The timing was wrong (F-3).** D-5/AC-12 posted the debit when the tender was *added*. AC-13
   then let that same tender be deleted outright (a plain `DELETE`), orphaning the debit — the
   stated reason ("no money has moved outside this task's own bookkeeping") was false for a
   credit tender specifically, which *is* this task's own bookkeeping.
3. **The void-compensation type was wrong (F-4).** D-16 compensated a reversed credit tender with
   a `payment`-type entry. `payment` is documented as "a customer-initiated payment"; a void is a
   credit correction reducing what is owed, which is `credit_note`'s own documented description.
   Both sit on the credit side of the ledger (so either would pass the `wrong_side` calculator
   check, D-26 of T-028), but only one matches what actually happened.

| **Corrected, all three:**
- The ledger key is `reference_type = 'sale_tender'`, `reference_id = sale_tenders.id` — one row
  per credit **tender**, never per sale, so a true replay guard survives a second credit tender
  on the same sale.
- The debit is posted inside `CompleteSale`'s own transaction, once per credit tender on the sale
  — not at `AddTender`. `AddTender` itself only checks that the sale has a customer
  (`customer_required` otherwise); it writes no ledger row and risks nothing by being deleted.
  `RemoveTender` goes back to being a plain delete with nothing to orphan, because nothing was
  posted yet. This is `docs/database.md` §12.1's own documented shape for a credit sale: resolve
  and lock, then insert the ledger row, inside the completion step — not the tender-capture step.
- A void's compensating entry is `credit_note`, keyed `('sale_tender', <the mirror tender's id>,
  credit_note)`. A refund's compensating entry (D-17 unaffected) stays `refund`, since a refund
  genuinely returns the customer's money rather than merely cancelling a debit that should never
  have posted.
| Each is a correctness fix against the real contract and the real unique key, not a preference —
`architect6` read `Contracts\Customers\CustomerCreditLedger` and `customer_ledger`'s actual
migration directly. Reversible: no for the key/timing shape (a correctness fix); yes for which
compensating type a future case might use, if ever a documented reason emerges.

D-30 (corrects D-12, AC-23; F-5) | D-12 assumed PIN verification was "callable through a published
contract." `architect6` measured this false: no `Contracts\Access` symbol verifies a PIN at all —
`Flexipos\Access\Features\VerifyPin\VerifyPinHandler` is internal to `packages/access`, denied to
other packages by deptrac, and it writes its own `access_log` row plus a lockout counter **inside
its own transaction**. Separately, D-12's own text already noted "a correct PIN proves who is
deciding, not that they may decide" without following through on what stops any cashier
approving any other cashier's void. | **Three corrections:**
1. **A new published contract, `Contracts\Access\PinVerifier`** (`verify(string $employeeCode,
   string $pin, ?string $ip = null): ?string`, returning the employee id or null — never throwing,
   never distinguishing *why* it failed, so a wrong PIN, an unknown code and a locked employee are
   indistinguishable to the caller). `packages/access` implements it by delegating to the existing
   `VerifyPinHandler`.
2. **It is called before this task opens its own approval-decision transaction**, never inside
   one. Calling it inside a transaction that later rolls back (for example on `self_approval_not_
   allowed`, decided after the PIN check) would roll back Access's own lockout-counter increment
   with it, defeating the lockout the instant it matters most — a wrong PIN on a self-approval
   attempt. The contract's own docblock states the caller's obligation.
3. **A correct PIN alone is not authority to decide.** Two new branch-scoped permission keys,
   `sales.approvals.decide` and `sales.shifts.reconcile`, declared by this task's service provider
   and checked through the existing `PermissionResolver::decide()` contract (Identity/Access,
   T-010, DONE) before a decision is accepted, refused `not_authorised` otherwise. Without this,
   D-15's segregation rule (the decider must differ from the requester/sale's cashier) is only
   half a control — any cashier who happens to know a second employee's PIN could still decide.
| Each closes a real gap `architect6` found by reading the actual package boundary and the actual
transaction shape, not a preference. Reversible: yes — the contract and the permission keys are
both additive; nothing about either forecloses a future second decision transport.

D-31 (corrects D-20, AC-14/AC-25; F-6) | D-20 assumed "Organization's existing `number_sequences`
mechanism" could simply be called. `architect6` measured only the **table** exists — no package
reads or writes `number_sequences`, and no contract allocates from it; nothing in this codebase
has ever needed to before this task. | **A new published contract,
`Contracts\Organization\DocumentNumbers`** (`next(string $documentType, ?string $branchId):
string`, throwing `DocumentNumbersRefused`), implemented in `packages/organization` (the existing
owner of §6.1's sequence table). It locks the branch-scoped row if one exists, otherwise the
company-wide row, inserting a company-wide default row on first use; it uses a portable
`lockForUpdate()` plus increment (not a MySQL-only trick, since the suite runs on SQLite), and a
concurrent first-use race on the unique key retries once rather than failing. **Prefixes, because
`sales.number` is unique across both `sale` and `refund` rows and a shared counter with no prefix
would collide the moment both types exist**: `sale` → `S` (6-digit pad, `S000001`), `refund` → `R`
(`R000001`), and a new `cash_shift` document type → `SH` (`SH000001`) for `cash_shifts.number` —
`cash_shift` is a plain new enum value on an existing `VARCHAR(30)` application-level column, not
a schema change. `reset_period` values other than `never` are refused
(`unsupported_reset_period`) — nothing can create a sequence row with one today, since there is no
numbering CRUD yet, so refusing it is the safe default rather than building for a case nobody can
reach. The architecture's own "allocated per terminal from a reserved prefix" (§12) describes the
**offline** case, which is FP-237's, and stays unbuilt here. | Reading what the schema actually
has (a table, nothing else) rather than what the first-pass spec assumed was already wired, the
same measured-not-assumed discipline every prior task's `architect` review already applied.
Reversible: yes — the prefix scheme and padding are configuration-shaped choices a future task
could widen without a structural change.

D-32 (corrects D-3, AC-26/AC-41; F-7) | D-3's proposed `snapshotInherited()` signature had no
`productId` and no `quantity`, and trusted a caller-supplied `stockUnitId` — none of which the
pure `SnapshotCalculator::compute()` call it delegates to can actually work with, and trusting a
caller's stock-unit id would let a wrong one silently mis-convert a refund. Separately, AC-41's
"a different, but still resolvable, unit" was never pinned down to a specific rule, and §6.5's own
text ("inherits... when it uses the same `unit_id`, and otherwise converts base to base... never
re-resolves through the ladder") only has **one** reading that genuinely never touches the ladder:
the requested unit is either the referenced line's own unit (take its factor) or the product's
stock unit (factor exactly `1.000000`) — anything else has no base-to-base path that avoids the
ladder. | **Corrected signature and rule:** `snapshotInherited(string $productId, string
$referencedUnitId, string $referencedUnitFactor, string $unitId, int|string $quantity):
UnitSnapshot`. The resolver reads the product's own stock unit itself (never a caller-supplied
one). If `$unitId` equals `$referencedUnitId`, the factor is the referenced one, unchanged
(`FactorSource::Inherited`, a new enum case). If `$unitId` equals the product's stock unit, the
factor is `1.000000`. Any third unit is refused `inherited_unit_mismatch` (a new
`UnitRefusal::InheritedUnitMismatch` case), naming both units — never re-walked through
`UnitLadder`. AC-41's fixture set is corrected to this exact shape (same-unit-inherit, stock-unit,
and a refused third-unit case). | The only reading of §6.5's own stated constraint that is
actually implementable without touching the ladder; found by `architect6` checking the real
`SnapshotCalculator` signature directly rather than trusting this spec's first-pass guess at what
it needed. Reversible: no for the refusal rule (it is what "never re-resolves through the ladder"
requires); yes for the exact enum/exception names, which are `dev`'s to finalise.

D-33 (corrects AC-9, D-8/D-9; F-8) | The original spec's AC-9 said `sale_items.kitchen_station_id`
is "written only with its schema default" — implying the column is built. D-9 already said the
column is **not built at all**, the identical contradiction `architect6` flagged. Separately,
`architect6`'s own schema read found several more columns this spec never addressed at all:
`sales.ar_document_id`/`sales_order_id`/`order_id`/`journal_entry_id`, `cash_shifts.journal_
entry_id`, `cash_movements.financial_account_id`, `sale_tenders.gateway_transaction_id`/
`payment_id`, `payment_methods.financial_account_id`/`gateway`/`settings`, `sale_items.batch_id`/
`serial_id`, and every `[CF]` `custom_fields` column this task's tables carry. | **D-9 wins.**
`kitchen_station_id` is not built on `sale_items` (AC-9 corrected to drop the claim it is). Every
column named above is likewise **not built** — the same "defer the whole column, not a key-less
placeholder" rule D-6/D-7 already state, now named exhaustively rather than partially, so AC-39's
absence proof has a complete list to check against. | Consistent application of an
already-established rule to a longer, more completely measured list than the first pass found.
Reversible: yes, additive once each owning module ships.

D-34 (new; F-9) | `architect6` read T-028's own plan (section 10, its forward-notes list) and
found four items it explicitly named as debts owed to this task, none of which the first-pass
spec picked up: `loyalty_transactions.sale_id` plus `unique(sale_id, type)` (T-028/D-6's own
deferred FK); maintaining `customers.first_sale_at`/`last_sale_at`/`lifetime_value`; reassigning
`sales.customer_id` on a customer merge; and a credit-limit override path. | **The FK is
discharged here** (this task's own migration set adds `loyalty_transactions.sale_id` as an expand
step and its FK onto `sales` as a contract step, exactly the shape T-027/T-029 already use for an
FK onto a table that didn't exist at the time the nullable column was first justified) —
`RecordLoyaltyTransaction` itself stays Customers' feature; wiring a real `sale_id` through it is
left to whichever caller wants automatic earning (D-5 unchanged: no automatic earning is built
here). **The other three are logged as forward notes, not built**: `first_sale_at`/`last_sale_at`/
`lifetime_value` and a merge-time `sales.customer_id` reassignment can each be recomputed from
`sales` rows at any later point, so nothing is lost by deferring them, and a credit-limit override
needs a real approval-and-retry caller this task is free to become later but does not pre-build
blind today (same reasoning as D-13's `credit_limit` approval-type deferral). | The FK is a
concrete, already-designed schema debt with a named owner (this task, by construction — nothing
else can supply a real `sale_id`); the other three are genuinely re-derivable and have no
documented urgency. Reversible: yes, all four.

D-35 (new; F-1, the lead's ruling) | `PricingEngine::billDiscount()` (T-029) returns one amount
for a whole bill; correctly applying it needs splitting that amount across lines in proportion to
each line's pre-tax amount and then re-taxing every line, because an exclusive tax charged on an
undiscounted base is simply wrong. That splitting-and-residue rule is itself discount arithmetic,
which this task's own AC-38 forbids living in `packages/sales`, and T-029's own D-19 explicitly
left the allocation rule to "the future Sales caller" with no rule stated. The lead ruled directly
rather than asking the product owner: **defer bill-level discounts by default.** | `sales.bill_
discount_id` and `bill_discount_value` are built as columns (matching the documented schema) but
are **never written by any feature in this task** — `CreateSale` refuses both as unknown fields
if supplied. The allocator design `architect6` sketched (proportional to `discountedSubtotal`,
HALF_UP residue on the largest line, ties to the lowest `line_no`, a refund line taking its
share in proportion to refunded base quantity, one named AC-38 exemption) is filed as a named
follow-up backlog row for whichever task next owns bill-level discount splitting — not silently
dropped, and not built speculatively against a rule nobody has actually ratified. | The safe,
reversible default for a cross-cutting arithmetic rule with no documented precedent and a real
AC-38 boundary conflict: ship the cart without the feature, rather than invent the splitting rule
inside the one package explicitly forbidden from doing exactly that arithmetic. Reversible: yes —
additive the moment an allocation rule is agreed and a package boundary is chosen for it.

D-36 (corrects AC-6/AC-17; F-10) | AC-6's original wording allowed line edits on any `held` sale.
`architect6` found this inconsistent with AC-6's own stated reason: a sale held from `pending_
payment` already has one or more tenders recorded against it, and editing its lines after that
would silently invalidate an already-recorded tender amount — exactly the failure AC-6 exists to
prevent for `pending_payment` itself. | **Corrected.** A sale's lines are editable only when
`status = draft`, or `status = held` **with its own recorded `from_status = draft`** (D-39/F-18
below is what records that). A sale held from `pending_payment` refuses every line edit
(`sale_not_editable`) exactly as a `pending_payment` sale itself already does — recalling it first
(back to `pending_payment`) is the only path to changing anything about it, and even then its
lines stay locked, only its tenders are editable (AC-13). | Closing the one case the original
wording's own stated reason already implied but the literal status list did not enforce.
Reversible: yes — narrowing further or relaxing this is ordinary handler logic, not a schema
change.

D-37 (new; F-12) | No feature in the first-pass spec consumed a `type = discount` `approval_
requests` row at all, despite `discount` being one of the four approval types D-13 names as built.
Separately, T-029's own plan (section 10) named `discounts.used_count`/`usage_limit` as a debt
owed to this task — without an increment, a discount's usage limit can never actually fire, making
the column pure decoration. | **Two features, both built:**
- When a line's `discount_id` names a `discounts` row with `requires_approval = true`
  (`discounts.requires_approval`, read directly as a read-only fact through the established
  `Lookup` precedent — T-029/Ruling 1, not a new contract), the line is refused `approval_
  required` unless a consumed `discount`-type approval exists whose `payload.discount_id` matches
  (F-21's binding rule, D-47). `approval_threshold`/`workflow_definition_id` are **not** evaluated
  — what a threshold compares against is undocumented, and this is filed as a forward note rather
  than guessed.
- A new published contract, `Contracts\Pricing\DiscountUsage::consume(string $discountId): void`
  (implemented in `packages/pricing`, under a row lock, throwing `PricingRefused(usage_limit_
  reached)` once `used_count` would exceed `usage_limit`), called once per distinct discount
  actually used on the sale, inside `CompleteSale`'s own transaction. Usage is never released on a
  later void or refund — the conservative, safe direction (a used slot stays used even if the sale
  is later undone), reversible if the owner later wants usage returned on a full void.
| Closing a real functional gap `architect6` found by reading T-029's own stated debts rather than
this task's first-pass silence on them. Reversible: yes, both — the threshold/workflow deferral and
the no-release-on-void rule are each additive/reversible independently.

D-38 (new; F-13, the lead's ruling; **AMENDED by D-77: an overridden line is refunded at the stored
price, not re-quoted. The rest of this entry stands for every non-overridden referenced line.**) | Re-quoting a refund line through `PricingEngine::quoteLine()`
"now" (at the refund's own instant) would pay the customer back today's price, not what they
actually paid — wrong the moment a price-list row changes between the sale and the refund, which
is exactly the ordinary case a promotion's `ends_at` creates. The lead ruled directly: **refund at
the original sale's price**, the standard retail convention. | **A referenced refund line (one
with `refund_of_item_id` set) is re-quoted with the original sale's own `completed_at` as the
engine's `at`, the original sale's `business_date` as `documentDate`, and the original line's own
`discount_id`** — so the ladder resolves against the state of the world at the moment of the
original sale, not today's. A **goodwill** refund line (D-19, no original line to read) has
nothing to anchor to and is quoted at the refund's own instant, since there is no "original price"
for a sale that never existed in this system. This still re-quotes **through the engine** rather
than copying the stored price verbatim (AC-27's own original reasoning stands: a partial refund's
smaller quantity can legitimately land on a different price-list step than the original full
quantity did) — it is the *instant*, not the *method*, that changes. One residual gap, named
rather than hidden: price-list **rows themselves are not versioned**, so a list row edited (not
replaced) after the sale still changes the answer even quoted at the original instant; closing
that would need a pro-rata-of-the-stored-line calculation, which is discount/price arithmetic
AC-38 forbids in this package, and is filed as a forward note, not solved today. | The
conventional, customer-facing-correct default for "what does a refund pay back," decided directly
rather than escalated, per the team protocol — reversible if the owner later states a different
rule (e.g., always current price) is wanted. Reversible: yes.

D-39 (new; F-15) | Several shift-lifecycle edges were left open by the first pass, each with a
real failure mode `architect6` traced through: closing a shift that still has in-flight sales,
recalling a sale onto a terminal with a different (or no) open shift, voiding a completed sale
after its shift has already closed, the sign convention for each `cash_movements.type`, and the
exact source rows behind `AC-35`'s shift-close figures. | **Resolved, each:**
- `CloseCashShift` refuses `shift_has_open_sales` while any `draft` or `pending_payment` sale still
  names the shift — a `held` sale does not block the close (it carries no open tender yet to
  reconcile against).
- `RecallSale` moves the recalled sale's `shift_id` to the **recalling terminal's own open
  shift**, refused `no_open_shift` if that terminal has none (D-22's existing rule, applied at the
  recall boundary too, since "possibly a different terminal" (the user story) means the shift
  changes along with the terminal).
- Voiding a **completed** sale (AC-22) is allowed only while its original shift is still `open` —
  refused `shift_closed` once the shift has moved past that, directing the cashier to a refund
  instead. Without this, a void's mirror tender (D-16/AC-21) would land on a shift that has
  already been counted and closed, and the drawer that actually has to pay it out would never see
  it reflected.
- **Movement signs** (`cash_movements.type`): `cash_in` and `float_in` **add** to expected cash;
  `cash_out`, `float_out`, `drop`, `petty_cash` and `bank_deposit` **subtract**. `amount` itself is
  always stored positive; the sign is a property of `type`, never of the stored number, so a
  report can always recover "how much was ever handled" without needing to know the sign rule
  first.
- **AC-35's figures**, stated precisely rather than left to each implementation to interpret:
  `sales_count` counts `type = sale` rows with `status` in `{completed, refunded, partially_
  refunded}`; `refunds_count` counts `type = refund` rows; `gross`/`discounts`/`tax` sum `subtotal`
  /`discount_total`/`tax_total` over those same sale rows; `net` is `Σ sale.total − Σ refund.
  total`; `totals_by_method` sums each tender's **`amount`**, never `tendered` (which would
  double-count the change that already left the drawer again as a separate payment-method entry).
| Each is a concrete rule for an edge the first pass left implicit, found by `architect6` tracing
what each figure's own source rows actually are rather than accepting the column names at face
value. Reversible: yes, each independently — none is a schema change.

D-40 (new; F-16) | AC-7's price-override rule, as first drafted, did not state what gates an
override, how it interacts with an item discount, or how its tax is computed, and `min_selling_
price` (a documented floor) was never addressed at all. | **Resolved:**
- An override is refused `price_override_not_allowed` when the product's `allow_price_override`
  (a T-027 column) is `false` — read directly as a fact, the same `Lookup` precedent D-37 uses.
- An overridden line carries **no** item discount — `discount_with_override` refuses the
  combination outright, rather than leaving an undefined interaction between two price-changing
  mechanisms on the same line.
- It is priced by calling `quoteLine()` once (for `listPrice` and `taxGroupId` only, never for the
  final price), then `subtotal = round(override × quantity, 4)` — the **one** multiplication this
  task's own arithmetic performs, confined to `Support/Money.php` (Ruling 3) — and `computeTax()`
  on that subtotal; `lineTotal = tax.net + tax.total`, a formula that holds for an inclusive or
  exclusive tax group alike, so this task never branches on inclusivity itself.
- `min_selling_price` is documented **per stock unit**, so enforcing it against a line-unit price
  needs a factor multiplication — arithmetic AC-38 forbids outside the two named contracts — and
  is filed as a forward note rather than built with an ad hoc exception to that rule.
  `price_mode = open_price` products are not specially handled either; such a product can still be
  sold through an approved override today, which is sufficient until a real open-price flow is
  designed.
| Each closes an interaction the first pass left undefined, resolved the same way every other
undefined interaction in this spec is: refuse rather than guess, and name the one piece of
arithmetic this task's own calculator is allowed. Reversible: yes, each.

D-41 (new; F-17) | `payment_methods.type` includes `loyalty` and `voucher`, and the first pass
never stated whether a tender of either type is accepted. | **Refused, `unsupported_method_type`.**
Neither has a backing ledger this task can call: a `loyalty` tender would need to actually redeem
points (Customers' feature, not invoked automatically per D-5), and a `voucher` tender would need
to actually burn a voucher (no voucher table exists anywhere in this schema). Accepting either
without a real deduction happening somewhere would be free money. | The safe default for a tender
type with no backing mechanism: refuse it rather than silently accept money that was never really
taken from anywhere. Reversible: yes, additive once either mechanism exists.

D-42 (small; F-11) | Neither `payment_methods.rounding_rule`/`surcharge_percent` nor `sales.
rounding`/`service_charge` had a stated behaviour. | **No rounding tolerance is configured
anywhere in this task, so it is treated as exactly zero** — `CompleteSale`'s balance check (AC-14)
requires tenders to sum to `sales.total` exactly, and `sales.rounding`/`service_charge` are always
written `0.0000`. `payment_methods.rounding_rule` and `surcharge_percent` are stored, returned, and
not applied by anything in this task — a forward note, not a silent no-op nobody can see (the
columns are visibly present and visibly unused, not hidden). | The safe default when no rounding
policy is documented: exact balance, not an invented tolerance. Reversible: yes, additive.

D-43 (small; F-14) | Are a refund's own line quantities and money columns signed, given the
original sale's `type` already carries the direction? | **Unsigned on the refund's own `sale_
items` (quantities and amounts are positive, exactly like an ordinary sale line — the document's
own `type = refund` carries the direction, not the numbers inside it), and signed on its `sale_
tenders` (negative — money leaving the till back to the customer, the same signed-`amount`
column every tender already uses).** A refund completes when `Σ sale_tenders.amount = −sales.
total`. | Matches the engine's own inputs (unsigned) and the existing signed-tender column exactly,
without inventing a second sign convention for the same fact. Reversible: n/a (a reading of the
existing column shapes).

D-44 (small; F-18) | Three ACs needed a schema element the first pass's Data section never named:
the hold/recall audit trail (AC-19), marking an approval consumed (AC-24), and a plain-sum header
rollup that never has to branch on tax inclusivity (AC-8). | **Three additions, each documented in
`docs/database.md`'s eventual "As built (T-030)" note:**
- `sale_holds` (new table): `id`, `sale_id` (cascade), `action` (`hold`/`recall`), `from_status`,
  `to_status`, `terminal_id`, `employee_id`, `created_at` — the append-only trail AC-19 needs, and
  the source of the `from_status` D-36/D-25 both read.
- `approval_requests.consumed_at` (new nullable column) — set by the write that actually consumes
  an approved request (D-47's `ApprovalGate`), so a second write cannot replay the same approval
  (AC-24).
- `sale_items.subtotal` (new column, pre-discount/pre-tax line amount) — so the header rollup
  (AC-8) is a plain sum of stored columns and never has to re-derive or branch on an inclusive vs.
  exclusive tax group to know what a line's "before discount" figure was.
| Each is a genuine gap between what the ACs require and what the originally-read schema already
had a column for — not an invented business rule, a missing storage location for a fact this
spec's own ACs already needed. Reversible: n/a (additive schema, needed for correctness as
written).

D-45 (small; F-19) | Several cross-references inside the first-pass spec and this decisions file
pointed at the wrong AC/decision number (an artefact of drafting order, not a substantive error).
| **Corrected directly in the revised spec** rather than left as a known error: AC-11's reference
to a later AC is now AC-37 (idempotency); AC-13's reference to "AC-16's void/refund paths" is now
AC-21/AC-25; AC-26's reference is now AC-25/D-19; AC-40's "all at (18,4)" is restated as "every
money and quantity column," since `unit_factor`/`discount_percent`/`surcharge_percent`/`exchange_
rate` correctly use the schema's other three already-allowed pairs, not a fifth. D-13's own text
("refunding without the original sale present") is corrected to match D-14/AC-29's actual rule
(every refund needs an approval, not only a goodwill one). | Bookkeeping corrections with no
behavioural content of their own. Reversible: n/a.

D-46 (small; F-20) | `sale_items.discount_percent` had no stated value in the first pass, and
`PricingEngine`'s `PricedLine` carries no *item*-level discount percent field at all — its
`listDiscountPercent` is the **price-list's own** percent (T-029/D-17/F-11), a different fact
entirely (a price-list row's own markdown, not this line's applied discount's rate). | **`sale_
items.discount_percent` is written `null` on every row this task creates.** Nothing this task
reads or computes maps onto it, and inventing a value (e.g., backing it out from `discount_
amount`/`subtotal`) would be manufacturing a number nothing asked for. | The honest statement that
a documented column has no source this task can honestly fill, rather than a guessed-at
backfill. Reversible: yes, additive if a real source for this fact is identified later.

D-47 (small; F-21) | What exactly does an `approval_requests` row have to match before a write may
consume it? The first pass stated the general shape (D-14/D-22's approval gate) without pinning
down the exact `reference_type`/`reference_id`/payload per approval `type`. | **A fixed binding
table, enforced by `ApprovalGate::consume()` before any write proceeds:**

| Approval type | `reference_type` | `reference_id` | Payload match |
|---|---|---|---|
| void | `sale` | the sale being voided | — |
| refund, against a sale | `sale` | the original sale | — |
| refund, goodwill | `goodwill_refund` | `null` | — |
| price override | `sale` | the sale | `{product_id, unit_id, unit_price}`, must equal the write |
| discount | `sale` | the sale | `{discount_id}`, must equal the write |

A mismatch on any of these refuses `approval_mismatch`, naming nothing the approval itself did not
already carry. **A refund approval carries no amount ceiling** — approving a refund authorises the
refund request as a whole, not a maximum amount; a request for a materially different amount than
what was approved is still only checked against the fields in the table above. This gap is named
as a forward note (a future "approval amount must be within X% of the requested amount" rule is
additive) rather than invented today, since nothing documents what tolerance, if any, is wanted. |
Makes "what is this approval actually authorising" a checked, enumerable fact instead of an
implicit assumption buried in each handler. Reversible: yes — the binding table can gain new rows
or tighter payload matches (including an amount ceiling) without a structural change.

D-48 (new; package/package-boundary notes, informational) | Where does this task's code live, and
what may it read from other packages? Not previously stated explicitly. | **A new path package,
`packages/sales` (`flexipos/sales`), publishing no contract of its own yet** — nothing outside it
calls into Sales today; FP-234 is free to add one when it needs to read a completed sale's lines
for stock depletion. Direct reads outside its own tables are limited to existence checks and
read-only facts (the established `Lookup` precedent every prior task in this backlog already
uses): `products`, `customers`, `terminals`, `branches`, and `discounts.requires_approval` (D-37).
Sales never names `customer_ledger`, `credit_balance`, `balance_after`, `number_sequences`,
`used_count`, `unit_conversions`, `base_unit_id`, or any T-029 tax/price-table token directly — a
source-rules scan (this spec's AC-38, unchanged in substance) enforces that. | Not `ba`'s call to
make structurally (architect/dev's placement decision, per T-027/D-11's own precedent), recorded
here only so the boundary is not mistaken for something left unstated. Reversible: yes; not a
business decision in any case.

D-49 (new; the state machine, informational, consolidating D-1, D-6, D-11 to D-19, D-21 to D-25,
D-36, D-39) | Several decisions above each state one transition or one edge of the sale/shift
state machine in isolation. For a single point of truth, the full `sales.status` machine this
task's handlers implement is: `—` →(`CreateSale`)→ `draft`; `draft` →(first `AddTender`)→
`pending_payment`; `held`, when held from `pending_payment`, →(first `AddTender` after recall and
re-tender)→ `pending_payment`; `pending_payment` →(last `RemoveTender`)→ `draft`; `draft` or
`pending_payment` →(`HoldSale`)→ `held` (recording `from_status`, D-44); `held` →(`RecallSale`)→
exactly its recorded `from_status` (D-25); `pending_payment`, or `draft` with a zero total and at
least one line, →(`CompleteSale`)→ `completed`; `draft`/`held`/`pending_payment` →(`VoidSale`, no
approval, D-14)→ `voided`; `completed`, only while its shift is still open (D-39), →(`VoidSale`,
approval required, D-14/D-22)→ `voided`; `completed` or `partially_refunded` →(`RefundSale`
against it)→ `partially_refunded` or `refunded` (D-18, depending on whether every line is now
fully refunded). `failed` and `exchange` are not reachable through any feature this task ships
(D-10). | A consolidation, not a new rule — stated once so a future reader does not have to
reassemble it from nine separate decisions. Reversible: n/a.

D-50 (new; idempotency and locking, informational, consolidating Rulings 6-7 of the plan) | How is
replay-safety and concurrency-safety achieved across this task's writes, stated once rather than
per-feature? | **Caller-supplied ids double as the idempotency key** for `AddTender` and
`RecordCashMovement` (`docs/database.md` §6.8's own stated precedent: "the row id equals the POS
tender ULID") — a replay with an identical payload under the same id is a no-op returning the
original row; a different payload under that id is `replay_conflict` (T-028/D-21's shape). `Close
CashShift`/`ReconcileCashShift` use the **shift's own id** as their natural key, since a shift can
only be closed/reconciled once in substance — no separate idempotency-key table is introduced
anywhere in this task. **Every write locks the `sales` row `FOR UPDATE` first**, so two concurrent
tenders on the same sale cannot both pass the balance check; `UnitResolver`/`PricingEngine` calls
happen **before** that lock is taken (they touch no row this task's own transaction needs to
protect), and `DocumentNumbers`/`DiscountUsage`/the customer-ledger write happen **inside** it,
after the sale (and, for a refund, the original sale) is locked — the same "resolve before you
lock, except what must be inside the lock" ordering `docs/database.md` §12.1 already states. There
is no stock lock in this task's own scope (D-1), so there is no ordering conflict with Inventory's
future lock sequence to reconcile. | A consolidation of an ordering rule that otherwise would be
restated, inconsistently, inside every individual feature's own decision. Reversible: n/a.

---

## Decisions D-51 to D-54 — added after `architect6`'s reconciliation of plan revision 2
(findings F-22 to F-25, none blocking)

These four were found while reconciling the committed plan (`2b44b8c`) against spec revision 2 —
each is a small drift between an AC's literal wording and the plan's own, more precise reading.
None changes this task's scope or any decision D-1 to D-50 already made; each is folded in here
so the spec matches the plan exactly rather than leaving a wording gap for `qa`/`dev` to resolve
differently than `architect6` already did.

D-51 (corrects AC-11/AC-18; F-22) | AC-11 (revision 2) allows `AddTender` on a sale `held` with
`from_status = pending_payment`, and AC-18 (`RecallSale`) is the feature that moves a `held` sale
back to that status. As written, the two left it unstated whether a cashier must call `RecallSale`
explicitly before tendering a held-from-`pending_payment` sale, or whether tendering it directly is
itself allowed. | **Tendering a sale held from `pending_payment` performs an implicit recall**:
`AddTender` on such a sale, in the same transaction as recording the tender, runs exactly
`RecallSale`'s own status/terminal/shift reassignment (AC-18) before recording the tender — a
separate explicit `RecallSale` call first is not required, though still legal and a no-op status-
wise if the recalling terminal is the same one tendering. A sale held from `draft` still has **no**
implicit recall path (D-49's state machine is unchanged for that case) — only a sale already
holding at `pending_payment` has tenders to resume adding to, which is the only case where
"add a tender" and "resume this sale" are the same cashier action in practice. | The conventional
till behaviour (a cashier does not perform two separate taps to resume a part-paid sale and then
tender the rest) applied narrowly, to the one case where the two actions are otherwise
indistinguishable in intent. Reversible: yes — requiring an explicit recall first is a stricter,
additive tightening if ever wanted.

D-52 (corrects AC-24; F-23) | AC-24's original wording used `approval_already_used` for both "a
`DecideApproval` call against a row that is no longer `pending`" and "a consuming write against an
`approved` row whose `consumed_at` is already set" — two different causes collapsed into one
refusal code, which makes a caller's retry logic unable to tell "someone already approved/rejected
this" from "this approval was already spent." | **Split by cause, matching `ApprovalGate`'s own
refusal list (Ruling 5 of the plan):** `DecideApproval` against a row whose `status` is no longer
`pending` is refused `approval_not_pending`; a consuming write (`VoidSale`/`RefundSale`/the price-
override or discount-approval check) against a row whose `consumed_at` is already set is refused
`approval_already_used`; a consuming write against a row whose `status` is not `approved` at all
(e.g. still `pending`, or `rejected`) is refused `approval_not_approved`. Three distinct causes,
three distinct codes. | The same "a refusal code is a fact a caller can act on" discipline this
whole backlog applies elsewhere — collapsing two different failures into one code would make a
caller's own retry/reporting logic guess. Reversible: yes — a future caller-facing simplification
could remerge codes, but nothing today asks for that.

D-53 (corrects AC-2; F-24) | AC-2's original wording validated a line's optional salesperson
`employee_id` against "Organization's employee directory." `architect6` measured this imprecise:
the published contract that actually exposes an employee-existence/validity check is
`Contracts\Access\EmployeeDirectory` (Identity/Access, T-010, DONE) — employees are an Access
concept in this codebase's own module split (`docs/architecture.md` §6: "employees... live in the
tenant database," owned by Identity, not Organization, which owns branches/terminals/number
sequences). | **Corrected.** AC-2 now names `Contracts\Access\EmployeeDirectory` as the contract
this task validates a line-level `employee_id` against, matching every other named contract in
this spec being named precisely rather than by its owning concept loosely stated. | A factual
correction (the right package, not a different rule), found by checking the real contract rather
than restating this spec's own first-pass assumption. Reversible: n/a (naming correction).

D-54 (corrects AC-41; F-25) | AC-41's original wording described `DiscountUsage::consume()`
refusing `usage_limit_reached` "once `used_count` would reach `usage_limit`" — read literally, that
refuses the call that brings a discount from `usage_limit − 1` **to** `usage_limit`, meaning a
discount with `usage_limit = 10` could only ever be used 9 times, one short of what the column
name states. | **Corrected: exactly `usage_limit` uses succeed; the `usage_limit + 1`th is
refused.** `consume()` refuses only the call that would push `used_count` **past** `usage_limit`
(i.e. the call made when `used_count` already equals `usage_limit`), not the call that brings it
to exactly that figure. | The literal meaning of a column named `usage_limit`: it is the count of
uses allowed, not the count of uses allowed minus one. A boundary-off-by-one found by `architect6`
checking the exact arithmetic rather than the prose. Reversible: n/a (a correctness fix to an
off-by-one, not a judgement call).

---

## Decisions D-55 to D-70 — added after the reconciliation of plan revision 3 against the shipped
T-028 and T-029 code (findings R-1 to R-41, questions Q-1 to Q-11), on the lead's rulings

Claims were verified on this branch: `PricingRefusal` has 17 cases; `CreditRefusal` has 16;
`DatabasePricingEngine` refuses a soft-deleted product and loads a discount `whereNull('deleted_at')`;
`DiscountCalculator` refuses on the current `is_active` and on `usedCount >= usageLimit`; the
ledger's reserved reference types are an exact `in_array` over three values.

D-55 (Q-1; OPEN, owner-level; not decided here) | A referenced refund line is re-quoted through the
engine, which checks the discount's and product's state today. Which rule applies when that state
has changed? | **Recorded as an open owner question, not decided.** Default built by the plan:
refuse with the engine's own reason and write nothing (AC-53). Affected cases: discount usage limit
filled, discount deactivated or deleted, `allow_discount` off, discount scope narrowed, product
soft-deleted (goodwill refunds too). Options: (a) refuse and tell the cashier, cheap but a return
can become impossible; (b) refund the original line's recorded amounts without re-quoting, always
refundable but a partial refund needs a split that AC-42 forbids Sales to compute, or an additive
pricing quote mode (a T-029 change). Must be ruled before Chunk D is accepted. | Owner-visible
money behaviour, so not the BA's to settle. Reversible: yes.

D-56 (Q-2; OPEN, owner-level; not decided here) | Should a line priced `0.0000` from the product's
own price be accepted? | **Recorded as an open owner question.** Default built: accept a `0.0000`
line (FP-440 makes an unpriced product quote 0.0000, so `no_price_resolved` is unreachable through
the real engine). With the zero-total-draft-completes-with-no-tender rule, an unpriced product can
leave free. Safer alternative: refuse such a line unless explicitly marked free (an approved
override). Must be ruled before Chunk C is accepted. | Owner-visible money behaviour. Reversible: yes.

D-57 (Q-3) | May an inactive, unsellable or variant-parent product be rung up? | **No.** Refused
`product_not_sellable` (a new refusal), naming the failed fact. Goodwill refund lines are not
checked for sellability (AC-47). | Neither the engine nor the earlier spec checks these flags; a
variant parent is not a sellable item. Reversible: yes.

D-58 (Q-4) | Whose customer does a refund carry, and may it be at another branch? | A referenced
refund copies the original sale's customer and snapshots; a goodwill refund takes an optional,
live-checked customer. A refund at a branch other than the original's is refused `not_refundable`
(currency, shift and approval branch follow the original). | The conventional reading; the spec was
silent. Reversible: yes (relaxing it is additive).

D-59 (Q-5) | Does re-pricing a line need a new approval after one was consumed? | No: an approval
already consumed by this sale for the same line, discount and override price covers a re-price
(for example a changed quantity). A different discount or price needs a new approval (AC-55). |
Matches AC-23a's "a consumed approval exists" reading. Reversible: yes (stricter later).

D-60 (Q-6) | A removed tender's late `AddTender` retry? | Accepted and documented: it re-adds the
tender. A tombstone is a follow-up before offline replay (FP-237). | Online till only for now.
Reversible: yes.

D-61 (Q-7; corrects D-50) | D-50 says every write locks the `sales` row first. | **Reworded.** Every
write takes its locks in the plan's Ruling 7 order: the shift locked by id then re-checked, then
`sales`, then approvals, then payment methods, then discounts in ascending id, a reserved slot for
FP-234's stock, then customers, and `number_sequences` last; and every write's first statement is a
locking read. D-50's other content (idempotency keys, resolve-before-lock) stands. | Closes the race
between writes and `CloseCashShift`; D-50 was informational. Reversible: n/a.

D-62 (Q-8) | Should customers reserve `sale_tender` as a ledger reference type? | Yes, but not
built here: a follow-up row for the lead, together with case-folding of the reserved check (FP-435).
Sales is protected meanwhile by D-66. | A customers change, outside this task's boundary.

D-63 (Q-9; keeps D-38) | A line is quoted when added, but a refund is quoted at the sale's
`completed_at`. | Keep D-38. Named as a residual gap beside the unversioned price lists: a promotion
that ended between the add and the completion prices the refund differently from the charge.

D-64 (Q-10) | A refund uses the customer's current group, because the sale stores none. | Accepted;
named as a residual gap: a customer moved to another group can be refunded at a different group
price.

D-65 (Q-11) | `DiscountUsage::consume()` re-checks existence and the limit only. | Accepted:
`is_active` and the date window are facts checked at quote time. Deactivated-in-between discounts are
still consumed (AC-58).

D-66 (R-4; extends D-29) | A ledger post returning "replayed" could be silently accepted, since a
manual ledger entry can pre-seed a tender's key (`sale_tender` is not reserved, and the reserved
check is exact and case-sensitive while the MySQL key is case-insensitive). | `CompleteSale`,
`VoidSale` and `RefundSale` each refuse any replayed posting as `credit_refused` with cause
`replay_conflict`, and the whole transaction rolls back (AC-48). Each of those operations makes the
first and only post for its key, so a replay is always a conflict. | Prevents a credit sale
completing with no debit. Reversible: n/a.

D-67 (R-40; corrects AC-5's description of the refusal) | What does a refusal carry? | A Sales
reason **and** the foreign refusal as its cause (the cause's enum type is always carried, since
`unknown_product`, `invalid_amount`, `out_of_range` and `replay_conflict` exist in more than one
enum). New Sales refusal codes: `invalid_transition`, `out_of_range`, `numbering_refused`,
`number_too_long` (a document number over 30 characters; carried as the numbering refusal's cause),
and `product_not_sellable`. A pricing refusal keeps its own value, except `no_price_resolved`, which
is `unresolvable_price`. | The caller can act on both facts. Reversible: yes.

D-68 (R-20; corrects AC-11/AC-14) | When is a credit tender's currency checked? | At completion
only: `AddTender` checks only that the sale has a customer; `CompleteSale` refuses `credit_refused`
when the customer's currency is missing or differs from the sale's (AC-49). Credit amounts are
positive strings (`credit_sale` debit, `credit_note` and `refund` credit); the ledger writes its own
audit row, so the invariant is one Sales audit row per write plus one Customers audit row per posting.

D-69 (R-25; informational) | `sales.approvals.decide` and `sales.shifts.reconcile` are the first real
branch-scoped permission keys. | Tests must cover both a branch-scoped grant and a company-wide grant,
and a branch-scoped key with no branch is refused. No routes exist, so the HTTP-surface rule is not
triggered.

D-70 (R-14, R-39; small) | Input hygiene found by reconciliation. | A quantity of zero or less is
refused `validation` before unit resolution (the resolver accepts zero and negatives); every rollup
that cannot fit `DECIMAL(18,4)` is `out_of_range`; no client date or instant is accepted; a
malformed id is `validation`, never an unhandled error (AC-51, AC-52).

---

## Decisions D-71 to D-74 — the lead's four rulings, recorded from the T-030 backlog row

The rulings are written in `docs/backlog.md`'s T-030 row ("Lead rulings still to be written into the
spec by `ba` before chunks C and D"). Each is the lead's decision, not the BA's; recorded here with
the reason, and written into the spec as a suffix AC so no number moves. Owner questions Q-1 (D-55)
and Q-2 (D-56) are unchanged: Q-1 (refund after a discount's usage limit is hit or the product is
deleted) is open, default refuse with the engine's reason (AC-53); Q-2 (a product with no price quotes
0.00) is open, default allow (accepted, with the risk named).

D-71 (lead ruling 1; AC-12A, extends D-41/AC-12 and AC-30) | A refund tender onto a payment method
whose `allows_refund` is `false`: which refusal? | **`unsupported_method_type`.** The refund is
refused and nothing is written. The plan's separate `refund_not_allowed` code is dropped. | One
refusal code for "this method cannot take this tender", the same code AC-12 already uses for the
`loyalty` and `voucher` types, so a caller handles one case, not two. Reversible: yes (a distinct
code is a rename).

D-72 (lead ruling 2; AC-24A, extends D-47/AC-24) | Does an approval's binding include the branch?
| **Yes.** An approval granted at one branch cannot be consumed by a write at another; the write is
refused `approval_mismatch` (the code D-47 already uses for any binding mismatch; the ruling named
no code, so this is the BA's reading) and the approval stays unconsumed. D-47's binding table gains
the branch as a checked field for every row, goodwill refunds included. | An approver's authority is
branch-scoped (D-30, D-69: `sales.approvals.decide` is checked at the request's branch), so an
approval usable at another branch would carry authority past the scope it was granted in.
Reversible: yes (drop the branch from the check).

D-73 (lead ruling 3; AC-47A, extends D-57/AC-47) | A goodwill refund of a product that is now
inactive or deleted: is it subject to the sellability check? | **No, exempt.** `product_not_sellable`
is never raised for a goodwill refund line. Note for the lead: the ruling exempts the Sales
sellability check only. A soft-deleted product is still refused by the pricing engine
(`unknown_product`), which is owner question Q-1's territory (D-55, AC-53); until Q-1 is ruled that
refusal stands, so a goodwill refund of a deleted product is still refused. | A returned item may
no longer be on sale; refusing the return for that reason would punish the customer for the shop's
catalogue change. Reversible: yes.

D-74 (lead ruling 4; AC-14A, extends D-49 and the Revision 4 zero-total clause) | A zero-total
completion (a fully discounted sale): its own path or the ordinary one? | **The ordinary one.** Same
number allocation (`DocumentNumbers`, `sale`), same discount-usage consumption, same ledger step (no
posting, since no tender rows exist), same single audit row, same status and timestamps, no stock or
GL effect (as for every sale, D-1), and no `sale_tenders` rows are required. | One completion path
means no special case to drift: a free sale is still a numbered, audited document. Reversible: n/a
(a statement that no special case exists).

---

## Decisions D-75 to D-77 — three lead rulings found while building Chunks C and D

D-75 (lead ruling; AC-47B, extends D-57/D-73/AC-47A; resolves plan question 1) | Does a referenced
refund line (with `refund_of_item_id`) run the sellability check, as a goodwill line does not? |
**No.** No refund line, referenced or goodwill, is checked for `is_active`, `is_sellable` or
`variant_parent`; the check is for `AddSaleLine` and `UpdateSaleLine` only. The engine's refusals
(AC-53) still apply. | The same reason as D-73: a catalogue change after the sale must not block a
return of something the customer was sold. Matches the built `RefundSaleHandler`. Reversible: yes.
Reversible flag: yes.

D-76 (lead ruling; AC-12B, extends D-71/AC-30; resolves plan question 2) | Does a refund tender give
change? | **No.** A refund pays out; an offered payout over what is still owed back is
`exceeds_balance` even on a method with `allows_change = true`, and `tendered` is null (with
`change_given = 0.0000`). Sale tenders are unchanged. | Taking change back from the customer on a
payout is not a real flow, and AC-30's "AC-12's method rules exactly" was ambiguous on it. Matches
the built `RefundSaleHandler` and `TenderRules` (`$refund = true`). Reversible: yes (remove the refund
branch from the over-balance check only). Reversible flag: yes.

D-77 (lead ruling, new; AC-27A; **amends D-38 and AC-27, does not delete them**) | D-38 and AC-27
re-quote every referenced refund line through `PricingEngine::quoteLine()` at the sale's `completed_at`.
A line sold with an approved price override (AC-7) is not priced by the engine at all, so that re-quote
returns the list price and refunds more than the customer paid. Found while building `RefundSale`. |
**A referenced refund line whose original sale line carried a price override (`price_overridden_by`
set) is refunded at the stored line's actually-charged `unit_price`, never more than was paid.** The
refund line is built by the same override path the sale used (AC-7/D-40, `LineWriter::price()`'s
override branch): one `quoteLine()` call with D-38's anchors for `list_price` and `tax_group_id` only;
`subtotal = round(stored unit_price × refund quantity, 4)` through `Money::product()` (the one
permitted multiplication); tax by the engine's `computeTax()` on that subtotal; `line_total = tax.net
+ tax.total`; no discount. No new override approval is consumed (the refund's own approval, AC-29, is
the gate), the product's current `allow_price_override` is not read, and the refund line's
`price_overridden_by` is null. Discount and tax proration for every non-overridden line stay as AC-27
and D-38 state; a goodwill line is unaffected. **Consequential BA reading, not part of the ruling:** a
refund of an overridden line must use the original line's own unit, else `inherited_unit_mismatch`,
because restating the stored price in another unit needs division AC-42 forbids. | Pays back exactly
what was charged, the same retail convention that motivated D-38, and reuses an arithmetic path the
package already owns rather than adding a second. AC-57's promotion/group drift does not apply to
overridden lines. Reversible: yes (the same-unit reading separately: yes). Reversible flag: yes.
