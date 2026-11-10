# T-029: Taxes, price lists and discounts — the PHP calculation engine

Backlog: FP-231, "Taxes, price lists and discounts, with the shared calculation engine in PHP
and TypeScript" (`docs/backlog.md`), matching `docs/architecture.md` §3's Catalog module line
("...price lists, taxes, discounts...") and §6.4 of `docs/database.md`. Decisions D-1 to D-11 are
in `docs/decisions/T-029-taxes-pricing.md`. **No BLOCKING questions** — autonomous/team-mode work;
every open design question was decided and logged there rather than left for the human.

**Scoping call, made before writing a single AC (D-1):** this spec is **one task**, but it is not
all of FP-231. It covers the full backend domain — `tax_authorities`, `taxes`, `tax_rates`,
`tax_groups`, `tax_group_taxes`, `price_lists`, `price_list_items`, `price_list_assignments`,
`discounts` — the two FK columns T-027 deferred onto `products` (`tax_group_id`,
`purchase_tax_group_id`), and a pure PHP calculation engine proven against a checked-in JSON
fixture set. **The TypeScript half of "the shared calculation engine in PHP and TypeScript" is
split out**, the same way T-027 split `barcode_rules`'s dual-engine parser into FP-423: logged as
a new backlog row, FP-432 (D-11), for the lead to add to `docs/backlog.md`. The fixture format
(D-9) is decided here because both engines must agree on it, but only the PHP engine and the
fixtures themselves ship in this task.

## Problem / goal

FlexiPOS has no tax, price-list or discount data at all today: no `tax_authorities`, `taxes`,
`tax_rates`, `tax_groups`, `tax_group_taxes`, `price_lists`, `price_list_items`,
`price_list_assignments` or `discounts` table exists, and `products.tax_group_id`/
`purchase_tax_group_id` were deliberately left unbuilt by T-027 pending this task. Nothing today
can tell a till or a quote screen what a line actually costs after tax, price-list resolution and
a discount — every later task that needs a real line total (Sales/FP-233, Quotations, Purchasing)
is blocked on this one. `docs/database.md` §6.4 already fully designs the schema, the six-step
price-resolution ladder, and the owner-ruled unit-outer ordering within it (U-1); this task is the
first to build any of it: the schema, the deferred FK columns, CRUD for all nine tables, and one
pure calculation engine every later module calls rather than re-deriving tax or price-list
arithmetic itself.

## Users affected

- **A newly provisioned tenant's owner/admin staff**, who today have no way to define tax
  regimes, price lists or discount rules — this task gives them the back-office setup screens'
  data layer (tax authorities/rates/groups, price lists and their scoping, discount definitions)
  needed before any sale, quotation or purchase document can price a line correctly.
- **Every later task that prices a line**: Sales (FP-233, resolving `unit_price`/`tax_breakdown`/
  `discount_amount` on `sale_items`), Quotations and Sales Orders (`quotation_items`/
  `sales_order_items`, already shipped schema, `unit_price`/`discount`/`tax` columns these rely
  on this task's engine to compute), Purchasing (`taxes.applies_to = purchase`), and the offline
  till replica (`docs/architecture.md` §12 names price lists, taxes and discounts as replica
  entity types) — none of those consuming modules are built here.
- **The future FP-432 (TypeScript engine) task**, whose entire job is matching this task's PHP
  arithmetic exactly against the fixture set this task ships (D-9).
- Not served by this task: anyone needing a sale, quotation or purchase document actually priced
  end to end (FP-233 and siblings, not built), the offline replica/sync mechanics for this data
  (Sync module, not built), any HTTP/admin-UI surface, or the TypeScript engine itself (FP-432,
  D-1/D-11).

## Current behaviour

Re-derived from the files, not assumed:

- No migration under `modules/host/database/migrations-tenant/` (or any package's tenant
  migrations) creates `tax_authorities`, `taxes`, `tax_rates`, `tax_groups`, `tax_group_taxes`,
  `price_lists`, `price_list_items`, `price_list_assignments` or `discounts`; no
  `packages/pricing` (or equivalently named) directory exists.
- `products.tax_group_id` and `products.purchase_tax_group_id` are documented in
  `docs/database.md` §6.3 but not built — T-027's own close-out note (§6.3 "As built (T-027)")
  states both were deferred because `tax_groups` does not exist, and names this task (FP-231) as
  the owner of the expand/backfill/contract mechanism for both.
- **Correction to `docs/backlog.md`'s FP-231 row (D-5):** the backlog row states "since both are
  `NOT NULL`-documented" for `tax_group_id`/`purchase_tax_group_id`. `docs/database.md`'s actual
  `products` row documents `tax_group_id FK` (`NOT NULL`) but `purchase_tax_group_id FK NULL`
  (nullable). Only `tax_group_id` needs the full expand + backfill + contract cycle;
  `purchase_tax_group_id` needs only the expand half (see AC-1/AC-2).
  `categories.tax_group_id`/`categories.purchase_tax_group_id` do not appear anywhere in
  `docs/database.md`'s documented `categories` row (§6.3) — the orchestrator's framing of "on
  products/categories" groups this task's two product columns together with T-027's *other*
  deferred `categories` columns (`income_account_id`, `kitchen_station_id`, owned by FP-260/
  FP-239, not this task). This task owns exactly the two `products` columns.
- `tax_authorities.payable_account_id` is documented `NOT NULL FK accounts`, and `accounts`
  (Finance, chart of accounts) does not exist anywhere in this codebase (no migration creates it)
  — the same "FK onto an unbuilt table" situation T-027/T-028 both hit, discovered here inside
  this task's own new table (D-7).
- `discounts.workflow_definition_id` references `workflow_definitions`, which **does** exist
  (`modules/host/database/migrations-tenant/2026_10_30_000901_create_workflow_definitions_table.php`,
  shipped by T-020) — this FK is buildable now, unlike the Finance-dependent ones. The workflow
  engine's `WorkflowEntityType::Discount` case already exists reserved
  (`packages/contracts/src/Workflow/WorkflowEntityType.php`) but nothing calls
  `WorkflowGate::evaluate()` for it yet (D-8).
- `packages/catalog`'s `UnitResolver` contract and `UnitLadder`/`SnapshotCalculator`
  implementation (T-027, shipped) are the only existing precedent for a pure, DB-free calculation
  class with its own contract — this task's calculation engine follows the identical shape rather
  than inventing a different one.
- `docs/database.md` §6.7 (`sale_items`, already shipped schema outside this task) carries
  `list_price`, `unit_price`, `discount_id`, `discount_percent`, `discount_amount`,
  `tax_group_id`, `tax_amount`, `tax_breakdown JSON` — this task's output shapes (resolved price,
  discount amount, tax breakdown) must be able to populate those exact columns without a later
  reshape, since this task does not get to change `sale_items`.

## User stories

- As a shop admin, I want to define tax authorities, taxes and their effective-dated rates, and
  bundle them into named tax groups, so that I can assign one tax group to a product and have
  every sale line compute the right tax even years after a rate changed.
- As a shop admin, I want to define price lists scoped to a branch, a customer group, an
  individual customer or a time-boxed promotion, with per-unit and volume-break pricing, so that a
  case of 24 can be priced at 179 while a single stays at 7.50, and a promotion expires on its own
  without me remembering to turn it off.
- As a shop admin, I want to define discounts — percentage or fixed, item-level or bill-level,
  scoped to specific products, categories or brands, with usage limits and an approval threshold —
  so that a cashier can apply a sanctioned discount without inventing their own number.
- As a developer of the next task that prices a line (Sales, Quotations, Purchasing), I want one
  engine that resolves a price-list price, applies the right discount, and computes the tax
  breakdown — correctly ordered and correctly rounded — so that I never have to re-derive that
  arithmetic myself and risk disagreeing with the till.
- As the developer of the future TypeScript engine (FP-432), I want a checked-in, language-neutral
  fixture set proving the PHP engine's exact inputs and outputs, so that I can build and verify
  the offline engine against the same ground truth without guessing at edge cases from prose.

## Acceptance criteria

### A. Deferred FK columns on `products` (owed from T-027)

- **AC-1**: `products.tax_group_id` is added by an **expand** migration as a nullable FK to
  `tax_groups`, a **backfill** that (a) creates one `tax_groups` row (`code = 'STANDARD'`,
  `is_inclusive = false`, `is_default = true`, `is_active = true`) if no row in the tenant
  database already has `is_default = true`, and (b) sets every existing `products` row's
  `tax_group_id` to the resolved default group's id, and a **contract** migration that tightens
  the column to `NOT NULL` — three separate migrations, not one (D-5, D-6). The contract
  migration is refused by a pre-check (naming the offending product ids) if any `products` row
  still has a null `tax_group_id` when it runs.
- **AC-2**: `products.purchase_tax_group_id` is added by a single **expand** migration as a
  nullable FK to `tax_groups` — no backfill and no contract migration, because it is documented
  nullable (D-5). Every existing product's `purchase_tax_group_id` is left null after this
  migration runs.
- **AC-3**: Both columns are reachable through the catalog package's existing product
  create/update handlers (T-027) once the migrations above run: supplying `tax_group_id` or
  `purchase_tax_group_id` on `CreateProduct`/`UpdateProduct` validates the id against an existing,
  active `tax_groups` row (refused otherwise, naming the field); omitting `tax_group_id` on create
  defaults it to the tenant's default tax group (mirrors AC-1's backfill default), and omitting
  `purchase_tax_group_id` leaves it null.

### B. Taxes and tax groups

- **AC-4**: Creating a `tax_authorities` row requires `code` (unique, non-blank) and `name`;
  `payable_account_id`/`receivable_account_id` are built nullable in this task (D-7, correcting
  the `NOT NULL` documented for `payable_account_id` — no `accounts` table exists to reference
  yet) and accepted as plain nullable ULID fields with no FK constraint; the FK and its contract
  migration are owed by whichever task ships `accounts` (Finance).
- **AC-5**: Creating a `taxes` row requires an existing `authority_id`, `code` (unique,
  non-blank), `name`, `type` (`percentage` or `fixed`), `applies_to` (`sale`, `purchase` or
  `both`); `is_compound`, `is_recoverable`, `is_withholding` default to `false`; `is_active`
  defaults to `true`.
- **AC-6**: Creating a `tax_rates` row requires an existing `tax_id`, a non-negative `rate`, and
  `effective_from`; `effective_to` is optional and, when supplied, must not be before
  `effective_from` (refused otherwise). Two rows for the same `tax_id` with overlapping
  `[effective_from, effective_to)` ranges are refused (open-ended `effective_to = null` is treated
  as "forever," so a second open-ended row for the same tax is always an overlap) — matches the
  unique `(tax_id, effective_from)` key documented plus an additional range-overlap check the bare
  key alone cannot express.
- **AC-7** (effective-rate resolution, a pure function — the tax twin of the price ladder): Given
  a `tax_id` and a document date, the resolved rate is the `tax_rates` row for that tax with the
  latest `effective_from` that is `<= ` the document date and whose `effective_to` is either null
  or `>` the document date. No qualifying row is a refusal (`no_effective_rate`), never a silent
  zero — an invoice must never compute tax as zero because nobody dated a rate.
- **AC-8**: Creating a `tax_groups` row requires `code` (unique, non-blank) and `name`;
  `is_inclusive` defaults to `false`; `is_default` defaults to `false`; `is_active` defaults to
  `true`. At most one `tax_groups` row has `is_default = true` — setting a second row's
  `is_default` to `true` clears the previous default in the same transaction, the identical shape
  T-027's `product_barcodes.is_primary` and T-028's `customer_groups.is_default` both already use.
- **AC-9**: `tax_group_taxes` links a `tax_groups` row to one or more `taxes` rows with a
  `sequence`; creating a link requires both ids to exist and refuses a duplicate `(tax_group_id,
  tax_id)` pair (the documented primary key). Removing a tax group's last `tax_group_taxes` row
  leaves the group valid but untaxed — a group with no member taxes computes zero tax for any
  product assigned to it, which is accepted (an explicit tax-exempt group is a legitimate use of
  an empty group, not an error).
- **AC-10**: Soft-deleting or deactivating (`is_active = false`) a `tax_groups` row that is
  referenced by any active, non-soft-deleted `products.tax_group_id` or `purchase_tax_group_id` is
  refused, naming the blocking product count — mirrors T-028/AC-3's identical guard for
  `customer_groups`.
- **AC-11** (tax-group calculation, pure): Given a tax group's ordered member taxes (via
  `tax_group_taxes.sequence`), a line amount, and a document date, the engine computes one amount
  per member tax by resolving each tax's effective rate (AC-7) against the document date, then
  summing (or compounding per `taxes.is_compound`: a compound tax's base includes the tax amount
  of every non-compound member that precedes it in `sequence`, never a tax that follows it) into
  a `tax_breakdown` array of `{tax_id, tax_code, rate, amount}` entries plus a total. When
  `tax_groups.is_inclusive = true`, the supplied line amount is treated as tax-included and the
  engine backs the tax out of it (`base = amount / (1 + total_rate)` for a group with no compound
  member; a compound group backs out iteratively, tax by tax, in `sequence` order) rather than
  adding tax on top.

### C. Price lists

- **AC-12**: Creating a `price_lists` row requires `code` (unique, non-blank), `name`, `type`
  (`standard`, `customer_group`, `promotion`, `branch`); `currency` defaults to the tenant's
  `company_profile.currency` when not supplied; `priority` defaults to `0`; `starts_at`/`ends_at`
  are optional and, when both supplied, `ends_at` must not be before `starts_at` (refused
  otherwise); `is_active` defaults to `true`.
- **AC-13**: Creating a `price_list_items` row requires an existing `price_list_id` and
  `product_id`; `unit_id`, when supplied, must be a unit the product's `UnitResolver` ladder can
  reach for that product (i.e. resolvable to the product's stock unit — refused as
  `unresolvable_unit` otherwise, calling the catalog module's published `UnitResolver` contract
  rather than re-deriving reachability); when omitted, `unit_id` is null, meaning "priced per
  stock unit, derive by factor" exactly as documented (§6.4). `min_qty` defaults to `1` and must
  be a positive base quantity; `price` is required and non-negative; `discount_percent`, when
  supplied, is between `0` and `100`. A duplicate `(price_list_id, product_id, unit_key, min_qty)`
  — where `unit_key` is the documented generated sentinel column — is refused, naming the
  conflicting row (the documented unique key).
- **AC-14**: Creating a `price_list_assignments` row requires an existing `price_list_id`,
  `scope_type` (`branch`, `customer_group`, `customer`) and a `scope_id`; `scope_id` is stored and
  validated as a plain ULID with **no FK**, matching the documented column (no FK is stated for
  `scope_id` because `branches` is the only one of the three scoped tables that exists in this
  codebase today — `customer_groups`/`customers` belong to T-028, a sibling, unmerged branch, not
  a dependency this task may assume). When `scope_type = branch`, `scope_id` is validated against
  an existing `branches` row; when `scope_type` is `customer_group` or `customer`, no existence
  check is performed (out of scope — those tables are not reachable from this task's own branch).
  A duplicate `(scope_type, scope_id, price_list_id)` is refused (the documented unique key).
  `priority` defaults to `0`.
- **AC-15** (the six-step price ladder, pure — the money twin of `UnitResolver`'s own ladder):
  Given a line's `product_id`, `unit_id` (the unit the line is actually in), `base_quantity`, an
  optional `customer_id`/`customer_group_id`/`branch_id`, and a document date, the engine resolves
  one `unit_price` by trying, in order: (1) a `price_lists` row of `type = standard` assigned
  (via `price_list_assignments`) to this exact `customer_id`; (2) assigned to this
  `customer_group_id`; (3) `price_lists` of `type = promotion` active on the document date
  (`starts_at <= date <= ends_at` or either bound null), ordered by `priority` descending, the
  first that prices this product; (4) assigned to this `branch_id`; (5) `product_branches.
  selling_price` for this product and branch; (6) `products.selling_price`. The first step that
  produces a price wins; later steps are never consulted once one succeeds.
- **AC-16** (the unit-outer rule within each of steps 1–4, pure, per `docs/database.md`'s own
  ruling — **not invented here**): within a price list considered at any of steps 1–4, the engine
  first looks for `price_list_items` rows with `unit_id` equal to the line's own unit and
  `min_qty <= base_quantity`, taking the one with the greatest qualifying `min_qty` if any exist
  — that price is used exactly as typed, with no further arithmetic. Only if no such row exists
  does the engine fall back to `price_list_items` rows with `unit_id IS NULL` and
  `min_qty <= base_quantity` (again the greatest qualifying `min_qty`), deriving
  `unit_price = stock_unit_price × unit_factor` using the factor `UnitResolver` already resolved
  for this line — never re-deriving the factor independently. A `min_qty` value on the
  unit-matched branch never loses to a higher `min_qty` on the derived (`unit_id IS NULL`) branch,
  regardless of either value — the unit test is outer, the `min_qty` test is inner, exactly as
  `docs/database.md` §6.4 states and justifies.
- **AC-17**: When no step of AC-15 produces a price (every list/assignment exhausted and
  `products.selling_price` itself is somehow absent — a data-integrity condition, since
  `selling_price` is a required column on `products`), the resolution is a refusal
  (`no_price_resolved`), never a silent zero.
- **AC-18**: Soft-deleting a `price_lists` row referenced by any `price_list_assignments` row is
  refused, naming the blocking assignment count; deleting the last assignment for a price list
  does not delete the price list itself. Soft-deleting or deactivating a `price_lists` row does
  not delete its `price_list_items` rows — they become unreachable (no assignment / inactive
  list resolves to them) rather than orphaned data to clean up separately.

### D. Discounts

- **AC-19**: Creating a `discounts` row requires `code` (unique, non-blank), `name`, `type`
  (`percentage`, `fixed`, `buy_x_get_y`), `value` (`DECIMAL(18,4)`, D-10 — non-negative; for
  `percentage` additionally refused above `100`), `scope` (`item` or `bill`); `applies_to`
  (JSON: `{products: [...], categories: [...], brands: [...]}`, each an optional list of ids) is
  stored and returned as supplied with no server-side existence validation against those ids —
  mirrors T-028/T-027's precedent for a JSON field with no invented controlled structure beyond
  documented key names (the engine, section E, is the only reader that gives it meaning). `type
  = buy_x_get_y` additionally requires `applies_to.products` to name at least one product (refused
  otherwise, `missing_target`) since a buy-x-get-y rule with no product to apply to is
  meaningless; this task does not validate `value`'s meaning for that type further (the "x" and
  "y" quantities are not modelled by this schema and are out of scope — see Out of scope).
  `min_amount`, `max_discount`, `approval_threshold` are optional non-negative money
  (`DECIMAL(18,4)`); `usage_limit` is an optional non-negative integer; `used_count` defaults to
  `0` and is maintained-only (a create/update request that supplies it is refused, mirroring
  T-028/AC-8's shape — only the engine's own usage-recording path, section E, increments it);
  `requires_approval` defaults to `false`; `workflow_definition_id`, when supplied, must reference
  an existing `workflow_definitions` row (buildable now, D-8 — existence-checked only, never
  invoked in this task); `sales_discount_account_id` is accepted as a plain nullable ULID with no
  FK (D-7 — `accounts` does not exist); `starts_at`/`ends_at` follow AC-12's same ordering rule;
  `is_active` defaults to `true`.
- **AC-20**: A discount active for use (`is_active = true`, current date within
  `[starts_at, ends_at]` when either is set, and `used_count < usage_limit` when `usage_limit` is
  set) is "available"; one that is not available is refused when the engine (section E) is asked
  to apply it, naming the specific reason (`inactive`, `not_yet_started`, `expired`,
  `usage_limit_reached`).
- **AC-21**: Soft-deleting a `discounts` row does not retroactively change any total already
  computed against it — this task records no application history itself (that belongs to
  `sale_items.discount_id`, a sibling, unbuilt table) and performs no cascading check against
  historical use, since none exists yet to check.

### E. The calculation engine

- **AC-22** (the one engine, pure, DB-free, the composition of B/C/D): Given a line's resolved
  unit price (AC-15/AC-16), its `base_quantity`, an optional discount to apply, and the product's
  resolved `tax_group_id`/document date, the engine computes, in this fixed order: (1)
  `subtotal = unit_price × base_quantity`; (2) `discount_amount` from the supplied discount
  against that subtotal (`percentage`: `subtotal × value / 100`, capped at `max_discount` when
  set; `fixed`: `min(value, subtotal)` — a fixed discount never produces a negative line;
  `buy_x_get_y`: out of scope, AC-19, returns a refusal if asked to compute one) giving
  `discounted_subtotal = subtotal - discount_amount`; (3) the tax breakdown (AC-11) computed
  against `discounted_subtotal` — **discount before tax**, the conventional order and the only one
  consistent with `sale_items.tax_breakdown` being described as "resolved rate per tax,
  snapshotted" against the line's actual charged amount, not its pre-discount list price (D-4).
  `line_total = discounted_subtotal + tax_breakdown.total` when `tax_groups.is_inclusive = false`,
  or `= discounted_subtotal` unchanged when `is_inclusive = true` (the tax was already backed out
  of the price, not added on top of a discounted amount a second time). Every rounding step uses
  `HALF_UP` to the currency's stored precision (`DECIMAL(18,4)`, mirroring §3.2's quantity
  rounding order: round each intermediate amount before the next step consumes it, never carry
  unrounded fractions forward silently).
- **AC-23** (bill-level discounts, pure): A `scope = bill` discount is not applied per line by
  this engine at all — it is computed once against a caller-supplied list of already-resolved
  line totals (`sum(line_total) `), producing one `bill_discount_amount` the caller (a future
  Sales task) is responsible for allocating back across lines however it chooses; this task does
  not invent an allocation rule, since none is documented and no consumer exists yet to need one
  (out of scope).
- **AC-24** (the fixture set, D-9): A checked-in JSON fixture directory
  (`packages/pricing/tests/fixtures/` or the equivalent path `architect`/`dev` choose) holds at
  least one fixture per: tax-only (inclusive and exclusive, compound and non-compound), price-list
  resolution (unit-matched vs. derived per AC-16, each of the six ladder steps winning in turn),
  discount-only (percentage, fixed, capped by `max_discount`), and the three composed per AC-22's
  full order. Every fixture states its inputs and expected output as plain JSON with no database
  row, no ULID that must pre-exist, and no Laravel container dependency — a future TypeScript
  engine (FP-432) must be able to read and replay every fixture with no PHP runtime involved. This
  task's own PHP tests load and assert against every fixture in the directory (no fixture file
  that exists goes unexercised).
- **AC-25**: Nothing outside the engine's own package re-derives tax, price-list or discount
  arithmetic — a boundary/static test (mirroring T-027's `QaCatalogSourceRulesTest` precedent for
  `UnitResolver`) refuses a file outside the engine's own namespace from reading
  `tax_rates`/`tax_group_taxes`/`price_list_items` columns directly or computing a percentage/
  rate multiplication against a tax or discount value.

### F. Framework integrity and the gate

- **AC-26**: `composer lint`, `composer stan` and `composer deptrac` pass with no new
  suppressions. The engine's published contract(s) live under a new
  `packages/contracts/src/Pricing/` directory (or the equivalent `architect`/`dev` choose) and are
  reached from outside the owning package only through that contract, never its internals — the
  same boundary rule T-027/T-028 both follow.
- **AC-27**: `composer test:fast` and `composer test` both pass, proving every criterion above.
- **AC-28** (static precision test, extends `TenantDecimalPrecisionTest`): every `DECIMAL` column
  this task adds names one of the four already-allowed `(precision, scale)` pairs
  (`docs/database.md` §3.2) — no fifth pair introduced (D-10):
  - **`DECIMAL(18,4)`**: `price_list_items.price`, `min_qty`; `discounts.value`, `min_amount`,
    `max_discount`, `approval_threshold`.
  - **`DECIMAL(9,4)`**: `tax_rates.rate`; `price_list_items.discount_percent`.
  - `TenantDecimalPrecisionTest`'s own column-map assertion (the second `it()` block) is extended
    with this task's migration files, following T-028's own precedent of extending that same test
    rather than writing a parallel one.
- **AC-29** (the migration-source rule, restated for this task specifically, D-5/D-7): a static
  test asserts that `products.tax_group_id`'s contract migration (the `NOT NULL` tighten) and
  `tax_authorities`'s eventual `accounts` FK are never written as part of the same migration file
  that adds the column itself — mirroring the `SourceRules`/`MigrationTree` boundary T-027/T-028
  both already satisfy, applied here to this task's own two genuinely new deferred-column cases
  (AC-1, AC-4/D-7).

## Out of scope

- The TypeScript implementation of the calculation engine and any frontend test wiring that
  consumes the fixture set (D-1, D-11) — new backlog row FP-432, for the lead to add.
- Actually pricing a real sale, quotation, sales order or purchase document end to end —
  `sale_items`/`quotation_items`/`sales_order_items`/`purchase_order_items` are shipped schema
  this task's engine must be *compatible* with (see Current behaviour) but does not populate;
  that is FP-233/Sales and siblings' concern.
- `tax_authorities.payable_account_id`/`receivable_account_id`'s foreign key and its contract
  migration — `accounts` (Finance) does not exist; deferred (D-7) to whichever task ships it.
- `discounts.sales_discount_account_id`'s foreign key — same reason, same deferred shape (D-7).
- Calling `WorkflowGate::evaluate()` for `WorkflowEntityType::Discount` — schema only in this
  task; the consuming call belongs to Sales (FP-233), once a real discount application exists to
  gate (D-8).
- Existence-checking `price_list_assignments.scope_id` when `scope_type` is `customer_group` or
  `customer` — `customer_groups`/`customers` belong to T-028, a sibling branch this task must not
  assume exists (AC-14).
- `buy_x_get_y` discount semantics beyond storing `type` and requiring a target product —
  no "x" and "y" quantity columns are documented on `discounts`, and no consumer exists yet to
  define what redeeming one even does (AC-19, AC-22).
- Allocating a bill-level discount back across individual lines — no allocation rule is
  documented; left to the future Sales consumer (AC-23).
- Recording that a discount was actually used (incrementing `used_count`, any application-history
  table) — no consumer (a real sale) exists yet to generate that event; `used_count` is built
  maintained-only and unmaintained in this task (AC-19, AC-21).
- The offline replica/sync mechanics for `tax_groups`/`price_lists`/`discounts` entity types
  `docs/architecture.md` §12 names — the Sync module's concern, not this task's.
- Any HTTP/admin-UI surface for managing taxes, price lists or discounts — this task ships the
  domain layer (migrations, models, handlers/contracts, the engine) a future admin-UI/API task
  reads, matching T-027/T-028's precedent for non-HTTP-first tasks.
- A controlled schema for `discounts.applies_to`/`taxes`' (if ever extended) JSON fields beyond
  the documented key names — stored and returned as supplied (AC-19), the same boundary T-027/
  T-028 both already drew for their own JSON columns.

## Non-functional requirements

- **One engine, one caller** (AC-25): the same discipline `docs/architecture.md` §21 and T-006/
  T-027/T-028 already established for quantity and balance arithmetic — tax, price-list and
  discount math exists in exactly one place, proven pure and DB-free, never re-derived inline by
  a future consumer.
- **Fixture-driven parity from day one** (AC-24, D-9): the JSON fixture set is not an afterthought
  added once the TypeScript engine exists — it ships with the PHP engine itself, because it is the
  only artefact that can prove the two will ever agree.
- **Never a silent zero or a silent skip**: an unresolvable tax rate (AC-7), an unresolvable
  price (AC-17), and an unavailable discount (AC-20) are each refusals naming the reason, never a
  quiet fallback that under- or over-charges a customer.
- **Forward compatibility of deferred columns** (AC-1, AC-4, matching T-027/T-028's identical
  rule): every column deferred in this task is deferred entirely — no key-less placeholder is
  added that still needs its own later contract migration beyond what is already stated.
- **Correct rounding order, matching the quantity precedent** (AC-22): discount before tax,
  `HALF_UP` at each step, never carrying an unrounded fraction into the next computation — the
  money twin of §3.2's quantity rounding rule.

## Assumptions

- A tenant with no pre-existing `tax_groups` row gets one seeded default (`STANDARD`, not
  inclusive) during `tax_group_id`'s backfill migration (D-6) — reversible, since it is ordinary
  data a shop can recode afterward.
- Discount-before-tax is the correct order (D-4) because it is the only reading consistent with
  `sale_items`'s already-shipped column shape; reversible if a future task's requirements state
  otherwise, but changing it then means revisiting `sale_items` too, not just this engine.
- `discounts.value` is a single `DECIMAL(18,4)` column serving both percentage and fixed discount
  types (D-10) rather than two type-specific columns — reversible via a future expand/contract
  cycle if this proves awkward in practice.
- `price_list_assignments.scope_id` carries no FK for `customer_group`/`customer` scope types in
  this task (AC-14) because those tables are not yet merged into this branch — not a permanent
  design position, just this task's own dependency boundary; whichever task merges after
  Customers (T-028) may choose to add the FK as a follow-up, not a correction of this spec.
- The engine's fixture directory path and exact contract/class names are left to `architect`'s and
  `dev`'s own choice, following T-027/T-028's identical precedent of leaving implementation-detail
  naming to the plan stage.

## Open questions

None — every design question this task raised was decided and logged in
`docs/decisions/T-029-taxes-pricing.md` (`ba`'s own calls), per the team's autonomous-mode
protocol.
