# T-029: Taxes, price lists and discounts — the PHP calculation engine

Backlog: FP-231, "Taxes, price lists and discounts, with the shared calculation engine in PHP
and TypeScript" (`docs/backlog.md`), matching `docs/architecture.md` §3's Catalog module line
("...price lists, taxes, discounts...") and §6.4 of `docs/database.md`. Decisions D-1 to D-24 are
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
(D-9, revised D-21) is decided here because both engines must agree on it, but only the PHP
engine and the fixtures themselves ship in this task.

**Revision 2 (code review round 1).** Decisions D-34 to D-39 amend AC-11.6 (residue skips a
zero-rate member, with sub-criteria 6a to 6d), AC-7 (duplicate `effective_from`), AC-30 (items 9
to 11) and add AC-5a and AC-6a. D-34 corrects D-25; D-38 corrects D-32's silence on the read path.

**Revision 1.** This revision folds in `architect`'s plan-stage review findings F-1 to F-18
against real source (`docs/plans/T-029-taxes-pricing.md`, committed `6848c9a`), each traceable
below by its `F-n` tag. Three were blocking (F-1: AC-22 multiplied by the wrong quantity; F-2:
AC-15's steps 5/6 would sell a case for the price of a single; F-3: AC-1's `NOT NULL` tighten is
classified destructive and would refuse every new tenant's provisioning). Fifteen more correct a
gap or a wrong assumption (F-4 to F-18, decisions D-12 to D-24). D-1, D-4, D-6 (its premise
corrected per F-16, cites §8 now) and the AC-16 transcription all held as first written.

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
  arithmetic exactly against the fixture set this task ships (D-9, D-21).
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
  (nullable) — only `tax_group_id` is `NOT NULL`-documented, so only it needs a backfill. **Both
  columns still need their own contract migration** (corrected per F-4): `SourceRules::
  foreignKeysOnlyInContract` refuses a foreign key onto an already-existing table outside a
  contract-phase file, and `products` already exists, regardless of whether the target column
  ends up nullable or not. See AC-1/AC-2.
  `categories.tax_group_id`/`categories.purchase_tax_group_id` do not appear anywhere in
  `docs/database.md`'s documented `categories` row (§6.3) — the orchestrator's framing of "on
  products/categories" groups this task's two product columns together with T-027's *other*
  deferred `categories` columns (`income_account_id`, `kitchen_station_id`, owned by FP-260/
  FP-239, not this task). This task owns exactly the two `products` columns.
- **The `NOT NULL` tighten itself cannot ship in this task (F-3, D-14).** `SourceRules::
  isDestructive()` classifies any `->change()` against a pre-existing table (which a `NOT NULL`
  tighten is) as destructive, and `PlanMigrationRunHandler::assertNothingDestructive()` refuses to
  run such a migration while `schema.backups.available` is `false` — today's default, since no
  backup facility exists. Tenant provisioning's own `MigrateRemainder` step runs through that same
  planner, so shipping the tighten as originally planned would refuse every new tenant's
  provisioning from the moment this task merges, not just bulk migration runs on existing tenants.
  T-008 D-10 already states the governing policy ("gate a destructive step on backups, don't weaken
  the classifier to dodge it"); this task follows it rather than reopening it.
- `tax_authorities.payable_account_id`/`receivable_account_id` are documented `FK accounts`
  (`payable_account_id` `NOT NULL`), and `accounts` (Finance, chart of accounts) does not exist
  anywhere in this codebase (no migration creates it). **Reversed from this spec's first draft
  (F-17, D-7 now reversed):** rather than building either column nullable with no key, neither is
  built at all — matching this task's own Non-functional requirements ("every column deferred is
  deferred entirely") and T-027/T-028's own precedent for exactly this situation. The same applies
  to `discounts.sales_discount_account_id`.
- `discounts.workflow_definition_id` references `workflow_definitions`, which **does** exist
  (`modules/host/database/migrations-tenant/2026_10_30_000901_create_workflow_definitions_table.php`,
  shipped by T-020) — this FK is buildable now, unlike the Finance-dependent ones. The workflow
  engine's `WorkflowEntityType::Discount` case already exists reserved
  (`packages/contracts/src/Workflow/WorkflowEntityType.php`) but nothing calls
  `WorkflowGate::evaluate()` for it yet (D-8).
- **`customer_groups` and `customers` already exist on this branch (corrected per F-8).** This
  spec's first draft stated they belonged to a sibling, unmerged branch; checked directly, this
  branch (`ai/T-029-taxes-pricing`) was cut from `ai/T-028-customers`'s tip and already contains
  T-028's chunks A and B (`...002801_create_customer_groups_table.php`,
  `...002802_create_customers_table.php`). The real reason `price_list_assignments.scope_id`
  carries no FK is that it is **polymorphic** (§3.3's documented `reference_type`/`reference_id`
  exception: one column, three possible target tables), not that the targets don't exist. See
  AC-14.
- `packages/catalog`'s `UnitResolver` contract and `UnitLadder`/`SnapshotCalculator`
  implementation (T-027, shipped) are the only existing precedent for a pure, DB-free calculation
  class with its own contract — this task's calculation engine follows the identical shape rather
  than inventing a different one. The engine takes a `UnitSnapshot` (produced by `UnitResolver`)
  as input and never re-resolves a factor itself — `UnitLadder`/`UnitCatalogue`/`SnapshotCalculator`
  are internal to `packages/catalog/src/Units` and deptrac denies them to every other package.
- **T-006's own standing obligation is missed by this spec's first draft (F-15, D-21):**
  `docs/architecture.md` §15 and T-006's plan both state that unit resolution joins the shared
  PHP/TypeScript fixtures. This task adds a small catalog-side fixture set for that, since it
  belongs beside the unit model that produces it.
- `docs/database.md` §6.7 (`sale_items`, already shipped schema outside this task) carries
  `list_price`, `unit_price`, `discount_id`, `discount_percent`, `discount_amount`,
  `tax_group_id`, `tax_amount`, `tax_breakdown JSON` — this task's output shapes (resolved price,
  discount amount, tax breakdown) must be able to populate those exact columns without a later
  reshape, since this task does not get to change `sale_items`.
- `products.allow_discount` already exists (T-027) and is not read by this spec's first draft of
  the discount engine — corrected per F-12 (AC-20).

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

- **AC-1** (revised per F-3/D-14 — the `NOT NULL` tighten is deferred): `products.tax_group_id`
  is added by three separate migrations: an **expand** migration adding it as a nullable FK-less
  column; a **backfill** migration that (a) creates one `tax_groups` row (`code = 'STANDARD'`,
  `is_inclusive = false`, `is_default = true`, `is_active = true`) if no row in the tenant
  database already has `is_default = true`, and (b) sets every existing `products` row's
  `tax_group_id` to the resolved default group's id (D-6, citing `docs/database.md` §8's
  `seed_core` step, which already lists "default tax group and taxes" among a new tenant's seed
  data); and a **contract** migration that adds the foreign key onto `tax_groups` only — **not**
  a `NOT NULL` tighten, because that is a `->change()` against a pre-existing table, classified
  destructive, and refused by the migration planner (and by tenant provisioning's own
  `MigrateRemainder` step) while no backup facility exists (F-3). The contract migration's
  pre-check refuses to add the FK if any `products` row's `tax_group_id` names a nonexistent
  `tax_groups` row, naming the **count** of offending rows only — never an id, since the runner
  stores the failure message verbatim (F-5) and no shipped contract migration names an id either.
  `NOT NULL` is enforced at the application layer in the meantime (AC-3) until a follow-up task,
  gated on the backup facility existing, ships the tighten as its own contract migration (logged
  as a new backlog row, see Out of scope).
- **AC-2** (revised per F-4 — an expand-only column still needs its own contract step):
  `products.purchase_tax_group_id` is added by an **expand** migration (nullable, no key) and a
  **contract** migration that adds the foreign key onto `tax_groups` — both columns' foreign keys
  are written in the **same** contract migration file as AC-1's (one contract file, not two
  consecutive ones that would force two separate fleet runs). No backfill and no `NOT NULL`
  tighten apply to this column, because it is documented nullable (D-5) — every existing product's
  `purchase_tax_group_id` is left null throughout.
- **AC-3** (revised per F-18 — catalog never reads `tax_groups` directly): Both columns are
  reachable through the catalog package's existing product create/update handlers (T-027) once
  the migrations above run. `CreateProduct`/`UpdateProduct` validate `tax_group_id`/
  `purchase_tax_group_id` through a new published contract, `Contracts\Pricing\
  TaxGroupDirectory` (mirroring `Organization\BranchDirectory`'s existing shape exactly:
  `state(id): TaxGroupState`, `defaultGroupId(): ?string`) — never by reading the `tax_groups`
  table itself. Supplying an id the directory reports unknown or inactive is refused
  (`unknown_tax_group`/`inactive_tax_group`, naming the field); omitting `tax_group_id` on create
  defaults it to `TaxGroupDirectory::defaultGroupId()` (mirroring AC-1's backfill default), and a
  tenant with no resolvable default at all refuses the create (`no_default_tax_group`) rather than
  writing a null value the application-level `NOT NULL` enforcement (AC-1) exists to prevent.
  Omitting `purchase_tax_group_id` leaves it null. `UpdateProduct` additionally refuses an
  explicit `tax_group_id = null` (part of the same application-level enforcement AC-1 names).

### B. Taxes and tax groups

- **AC-4** (revised per F-17 — the account columns are not built at all): Creating a
  `tax_authorities` row requires `code` (unique, non-blank) and `name`. `payable_account_id` and
  `receivable_account_id` are **not built by this task** — `accounts` (Finance) does not exist,
  and this task's own Non-functional requirements rule ("every deferred column is deferred
  entirely") applies here exactly as it does to every column T-027/T-028 deferred. A create/update
  request naming either field is refused as unknown (`Fields::only`, the same shape T-027/AC-27a
  already uses for `products.custom_fields`). Whichever task ships `accounts` (Finance, FP-260 or
  equivalent) owes the full expand + backfill + contract (tighten to `NOT NULL`) for
  `payable_account_id`, and expand + contract (FK only, stays nullable) for
  `receivable_account_id`.
- **AC-5**: Creating a `taxes` row requires an existing `authority_id`, `code` (unique,
  non-blank), `name`, `type` (`percentage` or `fixed`), `applies_to` (`sale`, `purchase` or
  `both`); `is_compound`, `is_recoverable`, `is_withholding` default to `false`; `is_active`
  defaults to `true`. **`type = fixed` and `is_compound = true` together are refused**
  (`fixed_cannot_compound`, F-9) — compounding a flat amount onto a percentage base has no defined
  meaning in this schema.
- **AC-5a** (D-35, review W-3): Given a tax that has at least one `tax_rates` row (closed,
  open-ended or future-dated, any count), When `UpdateTax` is asked to change `type` or
  `is_compound` to a value different from the stored one, Then it is refused (validation refusal)
  naming the field, with a message telling the caller to create a new tax instead; nothing is
  written. Re-sending the unchanged value is accepted. Given a tax with no rate rows, changing
  `type` or `is_compound` is accepted (subject to AC-5's `fixed_cannot_compound`). On `UpdateTax`
  this refusal is checked FIRST, before `fixed_cannot_compound` (D-42). Other fields
  (`name`, `code`, `applies_to`, `is_active`, `is_recoverable`, `is_withholding`) stay editable.
  Reason: documents were already resolved against the rates under the old meaning (15.0000 as a
  percentage versus per stock unit), the same principle as the immutability of a rate's `rate` and
  `effective_from`.
- **AC-6**: Creating a `tax_rates` row requires an existing `tax_id`, a non-negative `rate`, and
  `effective_from`; `effective_to` is optional and, when supplied, must not be before
  `effective_from` (refused otherwise). Two rows for the same `tax_id` with overlapping
  `[effective_from, effective_to)` ranges are refused (open-ended `effective_to = null` is treated
  as "forever," so a second open-ended row for the same tax is always an overlap) — matches the
  unique `(tax_id, effective_from)` key documented plus an additional range-overlap check the bare
  key alone cannot express. **`rate` is a percentage, stored as typed** (`15.0000` means 15%,
  D-15/F-9) — `tax_rates.rate` is `DECIMAL(9,4)`, which cannot hold a fractional rate
  (`0.15` would need 5+ decimal places for anything beyond a whole percentage point), so no
  fractional interpretation is offered or accepted.
- **AC-6a** (D-37, review S-2): Given a `tax_rates` row whose `effective_to` is already set, When
  `CloseTaxRate` is called for it (with any date, earlier, later or equal), Then it is refused
  (validation refusal) and nothing changes. Closing moves an open-ended rate's end date once; it
  is not an edit path, because moving a set end date re-resolves documents already priced. A
  deliberate correction path is out of scope (follow-up for the owner).
- **AC-7** (effective-rate resolution, a pure function — the tax twin of the price ladder): Given
  a `tax_id` and a document date, the resolved rate is the `tax_rates` row for that tax with the
  latest `effective_from` that is `<= ` the document date and whose `effective_to` is either null
  or `>` the document date. No qualifying row is a refusal (`no_effective_rate`), never a silent
  zero — an invoice must never compute tax as zero because nobody dated a rate. This holds
  regardless of whether the member tax is active or in scope (AC-11's own filters, F-9, run
  first, before a rate is ever looked up) — a tax that **is** in scope with no effective rate is
  still a hard refusal, not an excused zero. **Duplicate `effective_from` (D-36):** if ANY two rate
  rows handed to the pure resolver for one tax share the same `effective_from`, whether or not
  either qualifies on the document date, the resolution is refused `invalid_amount`, so the result
  never depends on row order (the shape of D-31 for price-list items). Real data cannot hold such a
  pair (unique `(tax_id, effective_from)`), so this guards data arriving another way and keeps the
  TypeScript twin from copying an iteration order. **Scope of the check (D-43):** only the rows
  of members that survive the active/deleted filter are checked, so an inactive or deleted
  member's duplicate dates are not refused. The TypeScript engine (backlog FP-432) must do the
  same.
- **AC-8**: Creating a `tax_groups` row requires `code` (unique, non-blank) and `name`;
  `is_inclusive` defaults to `false`; `is_default` defaults to `false`; `is_active` defaults to
  `true`. At most one `tax_groups` row has `is_default = true` — setting a second row's
  `is_default` to `true` clears the previous default in the same transaction, the identical shape
  T-027's `product_barcodes.is_primary` and T-028's `customer_groups.is_default` both already use.
- **AC-9**: `tax_group_taxes` links a `tax_groups` row to one or more `taxes` rows with a
  `sequence`; creating a link requires both ids to exist, refuses a duplicate `(tax_group_id,
  tax_id)` pair (the documented primary key), and refuses a duplicate `sequence` within the same
  group (`unique (tax_group_id, sequence)`, a new key this task adds since compounding order would
  otherwise depend on row insertion order). Removing a tax group's last `tax_group_taxes` row
  leaves the group valid but untaxed — a group with no member taxes computes zero tax for any
  product assigned to it, which is accepted (an explicit tax-exempt group is a legitimate use of
  an empty group, not an error).
- **AC-10** (revised per F-6 — `tax_groups` has no soft delete): `docs/database.md` §6.4
  documents `tax_groups` with `[ID] [TS]` only, no `[SOFT]` tag, so there is no delete slice for
  this table at all. A group is retired by setting `is_active = false`; doing so while the group
  is referenced by any active (non-soft-deleted) `products.tax_group_id` or
  `purchase_tax_group_id` is refused, naming the blocking product count — mirrors T-028/AC-3's
  identical guard for `customer_groups`. Deactivating the current **default** group clears
  `is_default` in the same transaction (the identical shape T-028/F-19 already uses for
  `customer_groups.is_default`), so `TaxGroupDirectory::defaultGroupId()` never names an inactive
  group; `CreateProduct` with no `tax_group_id` supplied and no remaining default refuses
  `no_default_tax_group` (AC-3) rather than silently resolving an inactive one.
- **AC-11** (tax-group calculation, pure — revised per F-9's five gaps):
  Given a tax group's ordered member taxes (via `tax_group_taxes.sequence`), a line amount, a
  base quantity, a document date and a context (`sale` or `purchase`), the engine computes:
  0. **Group lookup (D-33).** `computeTax` given an id the directory does not know is refused
     `unknown_tax_group`. A group that exists but is **inactive** (`is_active = false`) is
     computed normally, with no refusal and no flag: inactivity governs new assignments (AC-3,
     AC-10), not a quote already in flight. Only member taxes are filtered for inactivity (step 1).
  1. **Filter.** Skip any member tax that is inactive, soft-deleted, or whose `applies_to`
     excludes the given context (`sale`/`purchase`/`both`). An empty result after filtering gives
     `net = amount`, no breakdown lines, total `0.0000` (AC-9's "empty group is tax-exempt, not an
     error" still holds). A member that survives the filter but has no rate effective on the
     document date is still a hard `no_effective_rate` refusal (AC-7) — being filtered out
     excuses a tax from applying; having no rate never does.
  2. **Order** the surviving members by `sequence` — compounding order is driven by `sequence`,
     never by the order rows happen to be read in.
  3. **Percentage members:** each amount is `round(base × rate / 100, 4)`, computed as an exact
     multiplication then rounded once (never `base × (rate/100 rounded)`, F-9). A **compound**
     member's `base` is the line base **plus every member amount that precedes it in `sequence`**
     — compound or not. A **non-compound** member's base is always the plain line base.
  4. **Fixed members** (`type = fixed`, never compound — refused at write, AC-5): `amount =
     round(rate × base_quantity, 4)` — `rate` is an amount per the product's stock unit, matching
     every other money column on `products` being per stock unit (§6.3). Its breakdown line's
     `base` is the **line amount** (the net, in an inclusive group), not the quantity (D-30).
  5. **Exclusive groups** (`tax_groups.is_inclusive = false`): each member's amount is added on
     top of the line amount; the breakdown total is the sum, and `net = amount` (the line amount
     is already tax-exclusive).
  6. **Inclusive groups** (rule corrected by D-25; the earlier "product of every `(1 + rate/100)`"
     wording is **superseded**, it holds only when every percentage member is compound): the
     supplied `amount` is gross. The relation between net and gross is exactly linear:
     `gross − F = net × (1 + S) + K`, where `F` is the sum of every fixed member's amount and `S`
     and `K` are exact (never rounded) values built by walking the surviving members in `sequence`
     order, starting from `S = 0`, `K = 0`, `Fb = 0` (`Fb` is the fixed amount accumulated so far):
     - a **fixed** member: `a = round(rate × base_quantity, 4)` (step 4); `Fb = Fb + a`;
     - a **non-compound percentage** member with `f = rate/100`: `S = S + f`;
     - a **compound percentage** member with `f = rate/100`: both updates use the values from
       before this member, `S' = S + f × (1 + S)` and `K' = K + f × (K + Fb)`.
     Then: if `F` is greater than the gross, refuse `inclusive_amount_too_small`; let
     `R = gross − F − K`; if `R` is negative, refuse `inclusive_amount_too_small`; otherwise
     `net = round(R / (1 + S), 4)`, the **only** rounding in the back-out (half up). Each member's
     base and amount are then computed forward from `net` by steps 3 and 4; the residue
     `gross − net − Σ(all member amounts)` that the single rounding can leave is added to the
     **last percentage member in `sequence` whose resolved rate is not zero** (D-34, which
     corrects D-25's "last percentage member"), so `net + Σ(all member amounts) = amount` exactly,
     with a fixed, predictable member absorbing the rounding every time (never "iteratively, tax
     by tax," which would compound rounding at each step). A percentage member whose resolved rate
     is `0.0000` is skipped when choosing the member: it never receives a residue, so its amount
     stays `0.0000` and `amount = base × rate` holds on every line. A non-zero residue always has a
     member to land on: if every percentage rate is zero then `S = 0` and `K = 0`, so
     `net = gross − F` exactly and the residue is zero. Sub-criteria, each a shipped fixture
     (15 % VAT and a levy, both non-compound, no fixed member):
     - **6a** VAT at sequence 1 (15 %), levy at sequence 2 (0 %), gross 9.9900: `net = 8.6870`;
       VAT base 8.6870 and amount `1.3030` (the unrounded 1.30305 would be 1.3031, the residue
       −0.0001 comes off it); levy amount `0.0000`; `net + Σ = 9.9900`; the quote is not refused;
     - **6b** the same group, gross 10.0000: `net = 8.6957`, VAT `1.3043`, levy `0.0000`, sum
       `10.0000`;
     - **6c** the reversed order, 0 % levy at sequence 1 and 15 % VAT at sequence 2, gross 9.9900:
       the same net, VAT and levy amounts as 6a (the residue goes to the 15 % member, which is
       last non-zero), lines listed in `sequence` order;
     - **6d** every percentage member at 0 %, gross 9.9900: `net = 9.9900`, every amount
       `0.0000`, no residue. In a group whose percentage members
     are all compound and with no fixed member, `1 + S` equals the product of every
     `(1 + rate/100)`; with two non-compound members of 5% and 10% it is `1.15`, not `1.155`.
     Worked checks (shipped fixtures): a fixed member of 1.0000 per unit on a base quantity of 2
     followed by a compound 10% on gross 112.2000 gives `F = 2`, `S = 0.1`, `K = 0.2`,
     `R = 110`, `net = 100.0000`, the compound member's base `102.0000` and amount `10.2000`;
     a non-compound 15% with a fixed 0.5000 per unit on a base quantity of 24 and gross 179.0000
     gives `F = 12`, `R = 167`, `net = round(167 / 1.15, 4) = 145.2174`.
  7. **Breakdown entry shape:** `{tax_id, code, tax_code, type, is_compound, rate, base, amount}`
     per surviving member, in `sequence` order, plus a `total`. `tax_code` may be null (it is
     nullable on `taxes`). `base` is each member's own computed base (useful for a tax return).

### C. Price lists

- **AC-12** (revised per F-7 — `currency` is required, not defaulted): Creating a `price_lists`
  row requires `code` (unique, non-blank), `name`, `type` (`standard`, `customer_group`,
  `promotion`, `branch`), and **`currency`** (`CHAR(3)`, `^[A-Z]{3}$`, required — no
  `company_profile` table exists in this codebase to default from; the same gap T-028/F-8 already
  found and resolved the identical way for `customers.currency`). `priority` defaults to `0`;
  `starts_at`/`ends_at` are optional and, when both supplied, `ends_at` must not be before
  `starts_at` (refused otherwise); `is_active` defaults to `true`.
- **AC-13**: Creating a `price_list_items` row requires an existing `price_list_id` and
  `product_id`; `unit_id`, when supplied, must be a unit the product's `UnitResolver` ladder can
  reach for that product (i.e. resolvable to the product's stock unit — refused as
  `unresolvable_unit` otherwise, calling the catalog module's published `UnitResolver` contract
  rather than re-deriving reachability); when omitted, `unit_id` is null, meaning "priced per
  stock unit, derive by factor" exactly as documented (§6.4). `min_qty` defaults to `1` and must
  be a positive base quantity; `price` is required and non-negative; `discount_percent`, when
  supplied, is between `0` and `100` (its meaning when the item wins the ladder is AC-16a, F-11).
  A duplicate `(price_list_id, product_id, unit_key, min_qty)` — where `unit_key` is the
  documented generated sentinel column — is refused, naming the conflicting row (the documented
  unique key).
- **AC-14** (revised per F-8 — `customer_groups`/`customers` exist on this branch; `scope_id` has
  no FK because it is polymorphic, not because its targets are missing): Creating a
  `price_list_assignments` row requires an existing `price_list_id`, `scope_type` (`branch`,
  `customer_group`, `customer`) and a `scope_id`. `scope_id` carries **no FK** at the schema
  level — it is a polymorphic reference naming a row in one of three different tables depending
  on `scope_type`, exactly the documented `reference_type`/`reference_id` exception in
  `docs/database.md` §3.3, not an accident of what happens to exist in this codebase. Because
  `branches`, `customer_groups` and `customers` are all three reachable from this branch, the
  create handler **existence-checks all three scope types** directly against their own tables
  (`branches` for `branch`, `customer_groups` for `customer_group`, `customers` for `customer`) —
  the same direct existence-check-without-an-FK precedent `packages/catalog` already uses for
  `product_branches` against `branches` — refusing an unknown or soft-deleted target id, naming
  `scope_type` and `scope_id`. A duplicate `(scope_type, scope_id, price_list_id)` is refused (the
  documented unique key). `priority` defaults to `0`.
- **AC-15** (the six-step price ladder, pure — the money twin of `UnitResolver`'s own ladder;
  revised per F-2/F-10 for step selection and steps 5–6): Given a line's `product_id`, the line's
  `UnitSnapshot` (carrying `unitId`, `quantity`, `unitFactor`, `baseQuantity` — produced by
  `UnitResolver`, never re-derived by this engine, Ruling 4), an optional `customerId`/
  `customerGroupId`/`branchId`, the document's `currency` and instant, the engine resolves one
  `unitPrice` by trying, in order:
  1. every **non-promotion** `price_lists` row assigned (via `price_list_assignments`) to this
     exact `customerId`;
  2. every non-promotion list assigned to this `customerGroupId`;
  3. every `type = promotion` list whose window contains the document instant: a promotion with
     **no** assignment rows applies everywhere (unscoped); a promotion **with** assignment rows
     applies only where one of them matches the line's customer, customer group or branch — "by
     priority" (§6.4) does not mean promotions are always unscoped, and a branch-limited promotion
     is the ordinary case;
  4. every non-promotion list assigned to this `branchId`;
  5. `product_branches.selling_price` for this product and branch, **derived by the line's own
     unit factor** (F-2: `unit_price = round(selling_price × unitFactor, 4)` — `selling_price` is
     documented "per stock unit" (§6.3), the branch override of the same figure, so using it as
     typed for a non-stock-unit line would sell a case for the price of a single, the same class
     of bug §6.3's unit model exists to prevent, moved into money);
  6. `products.selling_price`, **derived by the same factor** (F-2, identical reasoning).

  **Filtering, within every step (F-10):** a candidate list must be `is_active`, not
  soft-deleted, have a window containing the document instant (`starts_at ≤ at ≤ ends_at`, either
  bound null meaning open — D-3 already states every list type is optionally date-ranged, not
  only promotions), and have `currency` equal to the document's own currency; a list in another
  currency is skipped entirely, never converted (this task performs no currency conversion
  anywhere). **Ordering within one step, when several candidate lists qualify (F-10):**
  `price_list_assignments.priority` descending (steps 1, 2, 4 only), then `price_lists.priority`
  descending, then `price_lists.code` ascending as the final, deterministic tiebreak. The first
  list, in that order, that actually prices the line (AC-16) wins; a list that does not price the
  line at all (no matching `price_list_items` row, not even a derived one) is skipped in favour of
  the next candidate in the same step, and only once every candidate in a step is exhausted does
  the ladder move to the next step. The first step that produces a price wins overall; later steps
  are never consulted once one succeeds.
- **AC-16** (the unit-outer rule within each of steps 1–4, pure, per `docs/database.md`'s own
  ruling — **not invented here**): within a price list considered at any of steps 1–4, the engine
  first looks for `price_list_items` rows with `unit_id` equal to the line's own unit and
  `min_qty <= base_quantity`, taking the one with the greatest qualifying `min_qty` if any exist
  — that price is used exactly as typed, with no further arithmetic. Only if no such row exists
  does the engine fall back to `price_list_items` rows with `unit_id IS NULL` and
  `min_qty <= base_quantity` (again the greatest qualifying `min_qty`), deriving
  `unit_price = round(stock_unit_price × unit_factor, 4)` using the **caller-supplied**
  `UnitSnapshot.unitFactor` — never re-deriving the factor independently, and never re-calling
  `UnitResolver` (Ruling 4: this is what lets a refund line legitimately reuse an older,
  snapshotted factor rather than one that may have since changed). A `min_qty` value on the
  unit-matched branch never loses to a higher `min_qty` on the derived (`unit_id IS NULL`) branch,
  regardless of either value — the unit test is outer, the `min_qty` test is inner, exactly as
  `docs/database.md` §6.4 states and justifies.
- **AC-16a** (new, F-11 — `price_list_items.discount_percent`'s meaning): When the winning
  `price_list_items` row (from AC-16, steps 1–4 only — steps 5/6 have no such row) carries a
  non-null `discount_percent`, the ladder applies it to the resolved price before returning:
  `listPrice` is the typed-or-derived price from AC-16 unchanged; `unitPrice = round(listPrice ×
  (1 − discountPercent / 100), 4)`. Both values are returned, mapping directly onto
  `sale_items.list_price` and `unit_price` (§6.7) — the exact two columns this distinction already
  exists to hold. A stored `discount_percent` the engine never read would be a trap for whoever
  assumed it did something; this makes it load-bearing.
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

- **AC-19** (revised per F-17 — `sales_discount_account_id` is not built): Creating a `discounts`
  row requires `code` (unique, non-blank), `name`, `type` (`percentage`, `fixed`, `buy_x_get_y`),
  `value` (`DECIMAL(18,4)`, D-10 — non-negative; for `percentage` additionally refused above
  `100`), `scope` (`item` or `bill`); `applies_to` (JSON: `{products: [...], categories: [...],
  brands: [...]}`, each an optional list of ids) is stored and returned as supplied. Its **shape
  only** is validated (D-32): it must be an object whose only keys are `products`, `categories`
  and `brands`, each (when present) a list of strings; any other key, or a value that is not a
  list of strings, is refused naming the field. There is no check that any listed id exists
  (mirrors T-028/T-027's precedent; the engine, section E, is the only reader that gives it
  meaning). Null or absent is allowed. `type = buy_x_get_y` additionally requires
  `applies_to.products` to name at least one product (refused otherwise, `missing_target`) since
  a buy-x-get-y rule with no product to apply to is meaningless; this task does not validate
  `value`'s meaning for that type further (the "x" and "y" quantities are not modelled by this
  schema and are out of scope — see Out of scope). `min_amount`, `max_discount`,
  `approval_threshold` are optional non-negative money (`DECIMAL(18,4)`); `usage_limit` is an
  optional non-negative integer; `used_count` defaults to `0` and is maintained-only (a
  create/update request that supplies it is refused, mirroring T-028/AC-8's shape — only the
  engine's own usage-recording path, section E, increments it, and this task does not build that
  path either — see Out of scope); `requires_approval` defaults to `false`;
  `workflow_definition_id`, when supplied, must reference an existing `workflow_definitions` row
  (buildable now, D-8 — existence-checked only, never invoked in this task); **`sales_discount_
  account_id` is not built by this task** (reversed from the first draft — `accounts` does not
  exist, and a command naming this field is refused as unknown, the same shape as AC-4, F-17);
  `starts_at`/`ends_at` follow AC-12's same ordering rule; `is_active` defaults to `true`.
- **AC-20** (revised per F-12 — the exact refusal order, `allow_discount`, document instant not
  wall clock): When the engine (section E) is asked to apply a discount to a line or a bill, it
  refuses, checking in this fixed order (each named reason, not a generic failure), and every
  time-based check is evaluated against the **caller-supplied document instant**, never the
  system clock — a fixture must replay identically regardless of when the test runs:
  1. `unknown_discount` — the id does not resolve to any `discounts` row;
  2. `discount_scope_mismatch` — a `bill`-scope discount is given a line call, or an `item`-scope
     discount is given a bill call;
  3. `inactive` (`is_active = false`), `not_yet_started` (before `starts_at`), `expired` (after
     `ends_at` — exactly at `ends_at` is still accepted), `usage_limit_reached` (`used_count ≥
     usage_limit` when `usage_limit` is set);
  4. `discount_not_allowed` — the product's `allow_discount = false` (a T-027 column, item scope
     only; this spec's first draft did not read it);
  5. `not_applicable` (item scope only) — `applies_to` names at least one product, category or
     brand and the line's own `product_id`, `category_id` and `brand_id` are in none of them (an
     OR across the three lists); a null or empty `applies_to` matches everything;
  6. `below_min_amount` — the item's `subtotal` (item scope) or the bill's discount base (bill
     scope, AC-23) is below `min_amount`;
  7. `unsupported_discount_type` — `type = buy_x_get_y` (AC-19, out of scope).

  A discount whose stored `applies_to` is malformed (AC-30.11) is refused `invalid_amount` when
  the adapter loads it, before this order applies, whatever else is true of it (inactive,
  expired and so on) (D-41).

  `requires_approval`/`approval_threshold` are never evaluated by this check (D-8 stands
  unchanged — no consumer exists yet to gate).
- **AC-21**: Soft-deleting a `discounts` row does not retroactively change any total already
  computed against it — this task records no application history itself (that belongs to
  `sale_items.discount_id`, a sibling, unbuilt table) and performs no cascading check against
  historical use, since none exists yet to check.

### E. The calculation engine

- **AC-22** (the one engine, pure, DB-free, the composition of B/C/D — revised per F-1 for the
  subtotal formula and per F-12's refusal order): Given a line's resolved `unitPrice` (AC-15/
  AC-16/AC-16a), its `UnitSnapshot` (`quantity` in the line's own unit, and `baseQuantity`), an
  optional discount to apply, and the product's resolved `taxGroupId`/document date/context, the
  engine computes, in this fixed order:
  1. **`subtotal = round(unitPrice × quantity, 4)`** — `quantity` is the line-unit quantity
     (`UnitSnapshot.quantity`), **never** `baseQuantity` (F-1: `unitPrice` is documented "per the
     line's `unit_id`", §6.7, so multiplying by `baseQuantity` overcharges by exactly the line's
     own unit-conversion factor — a case at 179 computed against a `baseQuantity` of 24 would
     wrongly charge 4296, not 179). `baseQuantity` keeps its existing jobs: the `min_qty`
     comparator (AC-16) and the basis for a `type = fixed` tax (AC-11.4) — it is removed from this
     one multiplication only, not from the engine.
  2. **The discount** (AC-20's refusal checks first; then the amount): `percentage` gives
     `discountAmount = round(subtotal × value / 100, 4)`, capped at `maxDiscount` when set;
     `fixed` gives `discountAmount = min(value, subtotal)` — a fixed discount never produces a
     negative line; `buy_x_get_y` is `unsupported_discount_type` (AC-20.7). `discountedSubtotal =
     subtotal − discountAmount`.
  3. **The tax breakdown** (AC-11) computed against `discountedSubtotal` — **discount before
     tax**, the conventional order and the only one consistent with `sale_items.tax_breakdown`
     being described as "resolved rate per tax, snapshotted" against the line's actual charged
     amount, not its pre-discount list price (D-4).
  4. **`lineTotal = discountedSubtotal + tax.total`** when the tax group is exclusive, or
     `= discountedSubtotal` unchanged when inclusive (the tax was already backed out of the price,
     not added on top of a discounted amount a second time).

  Every rounding step uses `HALF_UP` at scale 4 (`DECIMAL(18,4)`, mirroring §3.2's quantity
  rounding order): round each named intermediate amount before the next step consumes it, never
  carry an unrounded fraction forward silently. The named steps are exactly: the derived/
  list-percent price (AC-16/AC-16a), `subtotal`, `discountAmount`, each tax amount (AC-11), and
  the inclusive base (AC-11.6).
- **AC-23** (bill-level discounts, pure — revised per F-13 for the base): A `scope = bill`
  discount is not applied per line by this engine at all — it is computed once against a
  caller-supplied list of already-resolved **`discountedSubtotal`** values (each line's
  post-item-discount, **pre-tax** amount — not `lineTotal`, which would apply the bill discount
  to tax as well as price, the opposite of D-4/AC-22's "discount before tax" rule, F-13), summed
  into one base, producing one `billDiscountAmount` the caller (a future Sales task) is
  responsible for allocating back across lines and re-taxing each line afterward however it
  chooses; this task does not invent an allocation rule, since none is documented and no consumer
  exists yet to need one (out of scope).
- **AC-24** (the fixture set, D-9, revised per F-15 for format): A checked-in JSON fixture
  directory (`packages/pricing/fixtures/` or the equivalent path `architect`/`dev` choose) holds
  at least one fixture per: tax-only (inclusive and exclusive, compound and non-compound, fixed,
  empty group, no effective rate), price-list resolution (unit-matched vs. derived per AC-16,
  each of the six ladder steps winning in turn, F-2's steps 5/6 derivation, F-10's filtering and
  ordering, F-11's `discount_percent`), discount-only (percentage, fixed, capped by
  `max_discount`, and each of AC-20's named refusals), and the three composed per AC-22's full
  order including a case-of-24 line and an inclusive line, and one bill-discount case (AC-23).
  **Every decimal value in a fixture is a JSON string at its full stored scale** (`"7.5000"`, a
  factor as `"24.000000"`) — never a bare JSON number, because a JavaScript number loses precision
  at exactly the fourth decimal place these fixtures exist to prove (F-15); dates are
  `YYYY-MM-DD`, instants are `YYYY-MM-DDTHH:MM:SS.mmmZ`. No fixture depends on a live database
  row, a ULID that must pre-exist, or any Laravel container — a future TypeScript engine (FP-432)
  must be able to read and replay every fixture with no PHP runtime involved. This task's own PHP
  tests load, lint (reject any bare JSON number, any malformed key set, any duplicate `name`) and
  assert against every fixture in the directory (no fixture file that exists goes unexercised;
  the set has at least 30 files and covers every named `kind`).
- **AC-24a** (new, F-15/D-21 — T-006's own standing fixture obligation): `docs/architecture.md`
  §15 and T-006's own plan state that unit resolution joins the shared PHP/TypeScript fixtures —
  an obligation this task's first draft missed. A small catalog-side fixture set
  (`packages/catalog/fixtures/units/`, roughly eight cases covering precision-first rounding,
  `HALF_UP`, a case factor of 24, and the mass-dimension base-unit chain) is added, replayed by a
  catalog-owned test against `SnapshotCalculator` directly — not duplicated inside the pricing
  package, since it belongs beside the unit model that produces it. FP-432 and FP-237 (the
  offline replica) both read these same files.
- **AC-25** (revised per F-14 — restated as a measurable instrument, plus a named exemption):
  A static scan, outside the pricing package's own arithmetic sub-namespace (`Engine/`), reports
  any file that either (a) references the literal table/column tokens `tax_rates`,
  `tax_group_taxes`, `price_list_items`, `price_list_assignments`, `is_inclusive`, `is_compound`,
  or (b) performs a multiplication (or a `multipliedBy(` call) adjacent to a variable name ending
  in `rate` or `percent` — mirroring T-027's `QaCatalogSourceRulesTest` precedent for
  `UnitResolver`. The scan is run once against the real tree before any new code is added, and any
  pre-existing hit found is reported rather than silently exempted. **T-027's own existing
  "no file outside `Units/` multiplies by a factor" scan gets one narrow, named exemption** for
  the one file that legitimately derives a price from a `UnitSnapshot` factor (AC-15 steps 5/6,
  AC-16's fallback branch) — recorded in that scan's own exemption list and documented as "the
  sanctioned price derivation from a unit factor, never from a column," never worked around by
  renaming variables to dodge the pattern (which would be evasion, not an exception).

### F. Framework integrity and the gate

- **AC-26** (revised per F-18 to name the second contract): `composer lint`, `composer stan` and
  `composer deptrac` pass with no new suppressions. The engine's published contracts —
  `PricingEngine` (tax/price-list/discount arithmetic) and `TaxGroupDirectory` (AC-3, AC-10's
  default-group lookup) — live under a new `packages/contracts/src/Pricing/` directory (or the
  equivalent `architect`/`dev` choose) and are reached from outside the owning package only
  through those contracts, never the package's internals — the same boundary rule T-027/T-028
  both follow.
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
- **AC-29** (the migration-source rule, restated for this task specifically — revised per
  F-3/F-17): a static test asserts:
  1. the migration file that adds `products.tax_group_id`/`purchase_tax_group_id` (the expand
     step) contains no FK call and no `->change()` call;
  2. the contract migration that adds both foreign keys contains no `->change()` call either (it
     is additive only — F-3's proof that it is not classified destructive);
  3. **no tenant migration this task adds names `accounts` in a foreign key**, and **none
     declares `payable_account_id`, `receivable_account_id` or `sales_discount_account_id`** at
     all (F-17 — asserting the columns' absence, not merely their nullability).
- **AC-30** (engine refusals and ordering ruled after Chunk B, D-26 to D-30):
  1. Given a line quantity or amount that is negative, When the pure engine prices it, Then it is
     refused `invalid_amount` (D-26).
  2. Given a percentage discount whose value is above 100, Then it is refused `invalid_amount`;
     exactly 100 is accepted (D-27).
  3. Given a ladder holding ANY two items of the unit keys it checks (same product and unit) with
     equal `min_qty`, whether or not either qualifies for the quantity, When the ladder reads it,
     Then it is refused `invalid_amount`, and the result never depends on row order: at base 10
     the orders 1, 1, 5 and 5, 1, 1 are both refused (D-28 as corrected by D-31).
  4. Given two lists (or two taxes) that tie on every earlier key, Then the order falls to a
     bytewise comparison of `code` (tax members: of the tax id), so `"10"` sorts before `"9"`; the
     database adapter must produce the identical order, proven by a test (D-29).
  5. Given a fixed tax in a line breakdown, Then its `base` equals the line amount (the net in an
     inclusive group), not the quantity (D-30).
  6. Given the inclusive back-out of AC-11.6, Then non-compound 5% and 10% on gross 115.0000 give
     `net = 100.0000` with no residue (D-25).
  7. Given a discount `applies_to` with a key other than `products`, `categories` or `brands`, or
     a value that is not a list of strings, Then creation is refused naming the field; a
     well-formed `applies_to` naming ids that do not exist is accepted (D-32).
  8. Given `computeTax` for an inactive tax group, Then it is computed normally; given an unknown
     id, Then it is refused `unknown_tax_group` (D-33).
  9. Given an inclusive group whose last percentage member (in `sequence`) resolves to `0.0000`,
     Then the residue goes to the last percentage member with a non-zero rate and the quote is
     not refused; cases 6a to 6d of AC-11.6 (D-34).
  10. Given two rate rows of one tax with the same `effective_from`, qualifying or not, Then
      resolution is refused `invalid_amount` in either row order (AC-7, D-36).
  11. Given the pricing adapter reads a stored `discounts.applies_to` that is not the D-32 shape
      (not a JSON object; a key other than `products`, `categories`, `brands`; a value that is not
      a list; a list entry that is not a string), Then the quote is refused `invalid_amount` and
      the discount is never applied, never widened to apply to everything. A stored JSON `null`
      and a stored JSON `[]` are both "not an object" and are refused (D-40). SQL `NULL`, an
      absent value, and a well-formed object, behave as before (D-38). The refusal is raised at
      load, ahead of AC-20's order, so a malformed discount that is also inactive or expired is
      still refused `invalid_amount` (D-41).

## Out of scope

- The TypeScript implementation of the calculation engine and any frontend test wiring that
  consumes the fixture set (D-1, D-11) — new backlog row FP-432, for the lead to add.
- **The `products.tax_group_id` `NOT NULL` tighten itself** (F-3, D-14) — deferred to a new
  follow-up backlog row, gated on a backup facility existing (so the migration planner no longer
  classifies it as unsafely destructive to apply). `NOT NULL` is enforced at the application
  layer by this task in the meantime (AC-1, AC-3).
- Changing `SourceRules::isDestructive()` to exempt a nullability tighten from the destructive
  classifier — a shared kernel safety rule, out of this task's remit; loosening it for this one
  case would loosen it for every future `->change()` too.
- Actually pricing a real sale, quotation, sales order or purchase document end to end —
  `sale_items`/`quotation_items`/`sales_order_items`/`purchase_order_items` are shipped schema
  this task's engine must be *compatible* with (see Current behaviour) but does not populate;
  that is FP-233/Sales and siblings' concern.
- `tax_authorities.payable_account_id`/`receivable_account_id` and
  `discounts.sales_discount_account_id` — **not built at all** by this task (reversed from the
  first draft, F-17); `accounts` (Finance) does not exist. Whichever task ships it owes the full
  expand + backfill + contract for `payable_account_id`, and expand + contract for the other two.
- Calling `WorkflowGate::evaluate()` for `WorkflowEntityType::Discount` — schema only in this
  task; the consuming call belongs to Sales (FP-233), once a real discount application exists to
  gate (D-8).
- `buy_x_get_y` discount semantics beyond storing `type` and requiring a target product —
  no "x" and "y" quantity columns are documented on `discounts`, and no consumer exists yet to
  define what redeeming one even does (AC-19, AC-20.7).
- Allocating a bill-level discount back across individual lines, and re-taxing each line
  afterward — no allocation rule is documented; left to the future Sales consumer (AC-23).
- Recording that a discount was actually used (incrementing `used_count`, any application-history
  table) — no consumer (a real sale) exists yet to generate that event; `used_count` is built
  maintained-only and unmaintained in this task (AC-19, AC-21).
- The offline replica/sync mechanics for `tax_groups`/`price_lists`/`discounts` entity types
  `docs/architecture.md` §12 names — the Sync module's concern, not this task's.
- Any HTTP/admin-UI surface for managing taxes, price lists or discounts — this task ships the
  domain layer (migrations, models, handlers/contracts, the engine) a future admin-UI/API task
  reads, matching T-027/T-028's precedent for non-HTTP-first tasks.
- A controlled schema for `discounts.applies_to` (or any future JSON field) beyond the documented
  key names, and any existence check of listed ids — stored and returned as supplied, shape-checked
  only (AC-19, D-32), the same boundary T-027/T-028 both already
  drew for their own JSON columns.

## Non-functional requirements

- **One engine, one caller** (AC-25): the same discipline `docs/architecture.md` §21 and T-006/
  T-027/T-028 already established for quantity and balance arithmetic — tax, price-list and
  discount math exists in exactly one place, proven pure and DB-free, never re-derived inline by
  a future consumer. The one sanctioned exception (deriving a price from a `UnitSnapshot` factor)
  is named and narrow, never a silent workaround (AC-25, F-14).
- **Fixture-driven parity from day one** (AC-24, AC-24a, D-9/D-21): the JSON fixture set is not an
  afterthought added once the TypeScript engine exists — it ships with the PHP engine itself,
  decimals as strings at full scale, because it is the only artefact that can prove the two will
  ever agree, and it also closes T-006's own pre-existing obligation for unit resolution.
- **Never a silent zero or a silent skip**: an unresolvable tax rate (AC-7), an unresolvable
  price (AC-17), and an unavailable discount (AC-20) are each refusals naming the reason, never a
  quiet fallback that under- or over-charges a customer.
- **Forward compatibility of deferred columns** (AC-1, AC-4, AC-19, matching T-027/T-028's
  identical rule, and this task's own corrected F-17 application of it): every column deferred in
  this task is deferred **entirely** — no key-less placeholder is added that would still need its
  own later contract migration beyond what is already stated, and no placeholder is added at all
  for a column whose owning table doesn't exist yet.
- **Correct rounding order, matching the quantity precedent** (AC-22): the correct quantity is
  multiplied against the correct unit price (F-1), discount before tax, `HALF_UP` at each named
  step, never carrying an unrounded fraction into the next computation — the money twin of
  §3.2's quantity rounding rule.
- **Concurrency (review W-1, W-2; no new rule, recorded as D-39)**: the rate-overlap check
  (`CreateTaxRate`, `CloseTaxRate`) takes its lock before any plain read, so it judges the latest
  committed sibling rates; and catalog's tax-group validation (AC-3) reads the group and the
  default with a locking read inside the product write's own transaction, so AC-10's deactivation
  guard cannot be bypassed by a concurrent product write. Testable with two connections.
- **No business-rule check against the wall clock** (AC-20, F-12): every discount availability
  check runs against the caller-supplied document instant, so a fixture replays identically
  however long after it was written.

## Assumptions

- A tenant with no pre-existing `tax_groups` row gets one seeded default (`STANDARD`, not
  inclusive) during `tax_group_id`'s backfill migration (D-6, citing §8's `seed_core` step) —
  reversible, since it is ordinary data a shop can recode afterward.
- Discount-before-tax is the correct order (D-4) because it is the only reading consistent with
  `sale_items`'s already-shipped column shape; reversible if a future task's requirements state
  otherwise, but changing it then means revisiting `sale_items` too, not just this engine.
- `discounts.value` is a single `DECIMAL(18,4)` column serving both percentage and fixed discount
  types (D-10) rather than two type-specific columns — reversible via a future expand/contract
  cycle if this proves awkward in practice.
- The `NOT NULL` tighten on `products.tax_group_id` is deferred to a follow-up task gated on a
  backup facility (D-14/F-3) — not a weakening of the original requirement, just a sequencing
  choice forced by the migration planner's own safety rule; application-level enforcement closes
  the gap in the meantime.
- The compounding definition (a compound member's base includes every preceding member's amount,
  compound or not — D-15/F-9) is the conventional reading where the schema states no rule;
  reversible by a one-line change in the tax calculator if the owner states otherwise.
- The inclusive back-out is the exact linear rule of AC-11.6 as corrected by D-25, not the
  product of `(1 + rate/100)` (superseded).
- Engine rulings added after Chunk B (D-26 to D-30): negative quantities refused, a percentage
  discount above 100 refused, a duplicate `min_qty` refused, bytewise code ties, and the fixed-tax
  `base`. D-29 leaves one open risk for the database adapter (see Open questions).
- Rulings D-31 to D-33 (after the plan reconciliation, K-9/K-11/K-12): the duplicate `min_qty`
  refusal covers any two items regardless of qualifying (corrects D-28); `applies_to` is
  shape-validated only; an inactive tax group is still computed, an unknown id is refused.
- Rulings D-34 to D-39 (code review round 1): the inclusive residue skips zero-rate members
  (corrects D-25); `type`/`is_compound` are frozen once a tax has rates; a stored malformed
  `applies_to` is refused, never widened; duplicate `effective_from` is refused by the pure
  resolver; an already-closed rate cannot be closed again; two concurrency rules.
- Residual edge, not ruled (rate magnitude): if the member receiving the residue has a very small
  non-zero rate whose amount rounds to `0.0000`, a negative residue of -0.0001 could still make it
  negative. Not reachable at ordinary rates; if the engine meets it, it refuses `out_of_range` as
  it does today. Flagged for the owner, no new rule invented.
- The price-ladder step-ordering and filtering rules (D-16/F-10 — assignment priority, then list
  priority, then code; the currency/window filter applied uniformly; promotions optionally
  scoped) are the conventional reading of §6.4's prose, made concrete enough to be fixture-proven;
  each is engine logic, reversible without a schema change.
- The engine's fixture directory path and exact contract/class names are left to `architect`'s and
  `dev`'s own choice, following T-027/T-028's identical precedent of leaving implementation-detail
  naming to the plan stage.

## Open questions

1. OPEN RISK (not blocking Chunk B, for the architect before Chunk C/D, D-29): the pure engine
   breaks ties between codes bytewise, but the database adapter's SQL ordering follows the column
   collation, which may order differently (case, accents, numeric-looking codes). The adapter
   must produce the same order as the engine, proven by a test; how (ordering in the adapter after
   reading, or a binary collation on the sort) is the architect's call.

Every other design question this task raised was decided and logged in
`docs/decisions/T-029-taxes-pricing.md` (`ba`'s own calls), per the team's autonomous-mode
protocol.
