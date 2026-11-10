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
