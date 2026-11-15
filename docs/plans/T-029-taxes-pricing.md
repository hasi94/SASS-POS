# Plan: T-029 Taxes, price lists and discounts (the PHP calculation engine)

Spec: `docs/specs/T-029-taxes-pricing.md` (AC-1 to AC-30, plus AC-16a and AC-24a). Decisions: `docs/decisions/T-029-taxes-pricing.md` (D-1 to D-30, plus D-21a). Branch `ai/T-029-taxes-pricing`, cut from `ai/T-028-customers` at `8f8295e`. Revision 1 was planned at `4194ce2`, revision 2 at `85dc7ab`, revision 3 at `7280657`.

**Revision 3 (planned at `7280657`, after Chunk B).** Spec now runs AC-1 to AC-30 and decisions D-1 to D-30. Three changes, each detailed in section 0.4:
- the inclusive back-out text now states the exact linear rule of AC-11.6 as corrected by D-25. Chunk B already shipped that rule (`TaxCalculator::inclusiveNet`); this revision only aligns the wording;
- the open ordering risk of D-29 is ruled: **the adapter never orders, the pure engine does all ordering** (Ruling 8, step 16, step 17, step 18);
- the Chunk C/D text is aligned with the names and shapes Chunk B actually shipped (findings K-1 to K-12), and one new step, **10a**, fixes an order-dependence found in the shipped duplicate-`min_qty` check.

**Revision 2.** `ba` folded F-1 to F-18 (section 0.2) into the spec in `85dc7ab`. Each resolution matches the one this plan proposed, so **no step's design changed** between revisions. Only the AC and decision references were updated, plus one scope clarification on AC-25 (below).

Wherever section 0.2 offered an "if `ba` prefers" alternative, that alternative is now **void**: the proposed behaviour is required by the spec. That covers F-8's three-scope check, F-9's compounding, F-11's `discount_percent` and F-3's application-only enforcement (no `CHECK` constraint).

| Finding | Now lives in |
|---|---|
| F-1 (subtotal × line quantity) | D-12, AC-22.1 |
| F-2 (steps 5–6 derive by factor) | D-13, AC-15.5–6 |
| F-3 (no `NOT NULL` tighten; FK-only contract; application enforcement) | D-14, AC-1, AC-3, AC-29.2, Out of scope |
| F-4 (`purchase_tax_group_id` contract FK) | D-5 (edited), AC-2 |
| F-5 (count-only pre-check message) | AC-1 |
| F-6 (`tax_groups` deactivation only) | D-22, AC-10 |
| F-7 (`currency` required) | D-23, AC-12 |
| F-8 (polymorphic `scope_id`; check all three scopes) | D-24, AC-14 |
| F-9 (percent rates, compounding, fixed, `applies_to`, inclusive residue, breakdown shape) | D-15, AC-5, AC-6, AC-7, AC-11 |
| F-10 (ladder filtering and ordering) | D-16, AC-15 |
| F-11 (`discount_percent`) | D-17, **AC-16a** |
| F-12 (discount refusal order, `allow_discount`, document instant) | D-18, AC-20 |
| F-13 (pre-tax bill base) | D-19, AC-23 |
| F-14 (measurable AC-25, named exemption) | D-20, AC-25 |
| F-15 (string decimals; unit fixtures) | D-21, AC-24, **AC-24a** |
| F-16 (§8 `seed_core` premise) | D-6 (edited), AC-1 |
| F-17 (account columns not built) | D-7 (reversed), AC-4, AC-19, AC-29.3 |
| F-18 (`TaxGroupDirectory`) | D-21, AC-3, AC-26 |

**One scope clarification `dev` must apply (AC-25).** The revised AC-25 scopes the scan to files "outside the pricing package's own `Engine/` sub-namespace". Read literally, its token half (a) would report the CRUD slices under `packages/pricing/src/Features/`. Those slices must name `tax_rates`, `tax_group_taxes`, `price_list_items`, `price_list_assignments` and `is_inclusive` to write them. The plan therefore applies the two halves at different scopes:
- **(a) the tokens:** excluded for all of `packages/pricing/src`, and enforced everywhere else (catalog included);
- **(b) multiplication by a `rate`/`percent` token:** excluded only for `packages/pricing/src/Engine/`, so the CRUD slices may not do tax arithmetic either.

This keeps the spec's intent (the arithmetic lives only in `Engine/`), and step 17 records it. It is informational for `ba`.

**Three stale lines in the revised decisions, none of which changes this plan** (informational, for `ba` or `lead`; non-blocking):
- D-5 says the deferred tighten is "see D-13/F-3"; it should say **D-14**.
- D-6 calls `seed_core` "tenant provisioning step 8"; §8 numbers it **step 9**.
- D-21's heading cites F-15 and F-18, but its third bullet is F-18 alone. That is harmless.

**Branch baseline note.** This branch already contains T-028 chunks A and B (eight customer migrations, `packages/customers`) because it was cut from that tip. `APP_REQUIRED_SCHEMA_VERSION` is therefore **51** here, not 43. Every shared file this plan edits (section 9) is also being edited by T-028 on its own branch, so the merge will conflict there (risk 3).

---

## 0. Verification and rulings

### 0.1 `ba`'s findings, re-read in source

| Claim | Verdict | Evidence |
|---|---|---|
| D-1: split the TypeScript engine out as FP-432, the FP-423 precedent | **Holds** | FP-423 (`docs/backlog.md` line 258) was split for "two codebases, one shared contract, parity testing", which is the same shape. Nothing in this task's scope depends on the TS engine. The only shared artefact is the fixture format, and this task decides it. One coupling `ba` left open is closed in F-15: decimals must be JSON **strings**, or a JS float silently disagrees in the fourth decimal. |
| D-5: the backlog is wrong, and only `tax_group_id` is `NOT NULL`-documented | **Holds for the backlog text. "`purchase_tax_group_id` is expand only" is false (F-4)** | `docs/database.md` line 491: `tax_group_id FK, purchase_tax_group_id FK NULL`. But `SourceRules::foreignKeysOnlyInContract` (AC-34) refuses a foreign key onto an existing table outside a `contract` file, and `products` exists. Both columns need expand + contract. Only `tax_group_id` also needs a backfill. §6.3 "As built (T-027)" says the same ("an expand migration for the column and a separate contract migration for the foreign key"). |
| AC-1: the contract migration tightens to `NOT NULL` | **Not shippable as written (F-3)** | `SourceRules::isDestructive()` flags any `->change()` (or raw `ALTER … MODIFY`) on a pre-existing table. `PlanMigrationRunHandler::assertNothingDestructive()` refuses every run that would apply such a file while `schema.backups.available` is false (the default, `config/schema.php`). `platform`'s `MigrateRemainder` provisioning step goes through that same planner, so every new tenant would fail to provision. T-008 D-10 itself says "a contract step that adds a NOT NULL is not destructive". The classifier is stricter than D-10, and this task must not change it. |
| The price-list ladder must call the published `UnitResolver` | **Holds, with one structural consequence (Ruling 4)** | `Contracts\Catalog\UnitResolver::resolve()` / `snapshot()`. `UnitSnapshot` carries `unitFactor` (`DECIMAL(18,6)`, ≥ 1) and `baseQuantity`. `UnitLadder`, `UnitCatalogue`, `Dimension` and `SnapshotCalculator` are internal to `packages/catalog/src/Units`, and deptrac denies them to other packages. The engine therefore takes the caller's `UnitSnapshot` as input and never derives a factor. AC-13's reachability check calls `UnitResolver::resolve()` and maps `UnitResolutionRefused` to `unresolvable_unit`. **But** T-027's `QaCatalogSourceRulesTest` ("no file outside `Units/` multiplies by a factor", which scans `packages/*/src`) will report the one sanctioned `price × unit_factor` in this package. That needs a named exemption (Ruling 10) and is not a reason to rename variables. |
| AC-16 transcription of §6.4 | **Exact** | §6.4 lines 604–612: unit-matched rows with `min_qty ≤ base_quantity`, greatest `min_qty`, used as typed. Otherwise `unit_id IS NULL` rows, same test, `stock_unit_price × unit_factor`. Otherwise go to the next step. `min_qty` is compared with **base** quantity on both branches, as the spec says. One gap: §6.4 is written "for this price list", and a step can hold several lists (F-10). |
| D-4: discount after price-list resolution | **Holds. Nothing in `docs/` states an order** | §6.4 is silent. §6.7 `sale_items` carries `list_price`, `unit_price` ("per the line's `unit_id`"), `discount_id`, `discount_percent`, `discount_amount`, `tax_amount`, `tax_breakdown`, `line_total` as separate columns, which only makes sense if resolution comes first. `Refference/ARCH.md` places discount between cart and payment ("Cart → Discount → Payment") and says nothing about price lists. Discount-before-tax also holds. |
| D-6: no documented default tax group, so seed `STANDARD`, non-inclusive | **Holds in effect, but the premise is imprecise (F-16)** | `Refference/ARCH.md` is silent on tax defaults. `docs/database.md` §8 step 9 `seed_core` **does** list "default tax group and taxes", without contents, and T-026's as-built note hands that to the owning module. Seeding in the backfill migration covers new tenants too, because migrations run before `seed_core`. `is_inclusive = false` is harmless: an empty group computes zero tax either way (AC-9). |
| D-7: build `payable_account_id` nullable with no FK | **Reverse it: defer the three account columns entirely (F-17)** | T-027 D-3/D-4 and T-028 D-6 deferred their account columns **by not building them**. The spec's own NFR says "every column deferred in this task is deferred entirely — no key-less placeholder". A nullable, unkeyed `payable_account_id` gives Finance nothing: Finance still owes expand + backfill + contract, and must now also clean whatever unvalidated ULIDs admins typed. That is the cleaner alternative `lead` asked about: do not build the column, and leave the documented `NOT NULL` as it is. |
| D-8: `workflow_definitions` exists; `WorkflowEntityType::Discount` is reserved | **Holds** | `…000901_create_workflow_definitions_table.php`; `WorkflowEntityType.php` line 30. |
| AC-12: currency defaults to `company_profile.currency` | **False (F-7)** | No migration creates `company_profile`. T-028 F-8 found the same thing. |
| AC-14: `customer_groups`/`customers` are not reachable from this branch | **False on this branch (F-8)** | `…002801_create_customer_groups_table.php` and `…002802_create_customers_table.php` are in this tree. The real reason for "no FK" is different: `scope_id` is **polymorphic** (§3.3's `reference_type`/`reference_id` exception). |
| AC-10: soft-deleting a `tax_groups` row | **No `[SOFT]` on `tax_groups` (F-6)** | §6.4 line 595: `[ID] [TS]` only. This is T-028 F-6's shape. |
| Spec: `products.selling_price` at steps 5–6 is used directly | **Unsafe (F-2)** | §6.3 line 491: "cost_price, selling_price, min_selling_price, msrp (**all four per stock unit**)". `product_branches.selling_price` is the branch override of the same figure (its migration docblock: "null inherits `products.selling_price`"). |
| Spec: AC-22 `subtotal = unit_price × base_quantity` | **Wrong (F-1)** | §6.7: `unit_price` is "per the line's `unit_id`". 1 case at 179 with `base_quantity` 24 would charge 4296. |

### 0.2 Findings routed to `ba`: all resolved in `85dc7ab`

The findings follow unchanged, as the record of what was routed. The resolution in each is now the spec's own text (see the mapping table at the head of this plan), and every "if `ba` prefers" or "alternative" clause below is void.

**Spec or decision edits needed (F-1 to F-11):**

- **F-1 (correction to AC-22): subtotal is `unit_price × quantity`, where quantity is in the line unit.** `unit_price` is per line unit (§6.7), so it must be multiplied by `UnitSnapshot.quantity`, never by `baseQuantity`. `base_quantity` is still the `min_qty` comparator (AC-16) and the basis for any `fixed` tax (F-9).
  - Plan: Ruling 6 step 2, step 9, and fixtures `line/case-of-24`.
- **F-2 (correction to AC-15): steps 5 and 6 derive by the factor.** `product_branches.selling_price` and `products.selling_price` are per **stock** unit, so `unit_price = round(price × unit_factor, 4)`, exactly like the `unit_id IS NULL` branch. Used as typed, a case line would sell at the single price. That is the "silent 1" bug §6.3 exists to stop, moved into money.
  - Plan: Ruling 5, step 8, mutation P3.
- **F-3 (correction to AC-1, AC-29): no `NOT NULL` tighten in this task.**
  - Every file that calls `->change()` on `products` is catalogued `is_destructive`. With backups unavailable, the planner refuses it for bulk runs **and for provisioning**. That leaves three options:
    - (a) change the classifier (kernel safety rule; out of this task's remit);
    - (b) flip `SCHEMA_BACKUPS_AVAILABLE` (false: no backup facility exists);
    - (c) defer the tighten.
  - **Plan: (c).**
    - Ship expand (column) → backfill (default group, every product set) → contract (the **two foreign keys** only; adding a key is not destructive, and the T-014 `add_branch_foreign_keys` precedent applies).
    - `NOT NULL` is enforced by the application until the tighten ships:
      - `CreateProduct` always writes a group (AC-3 default, or refuses `no_default_tax_group`);
      - `UpdateProduct` refuses `tax_group_id = null`;
      - an integration test asserts that no product row has a null `tax_group_id` after every write path.
    - **The `NOT NULL` tighten becomes a follow-up row, gated on the backup facility** (section 10).
    - Side benefit: every existing raw-insert seed of `products` in the tests stays valid.
  - Alternative if `ba`/`lead` want database-level enforcement now: an engine-only `CHECK (tax_group_id IS NOT NULL)` through a new `CheckConstraint::notNull()`, which is T-028's `oneSided` shape and is not classified destructive. It costs a kernel edit and is proven only under G-1, so the plan does not default to it.
- **F-4 (correction to D-5, AC-2): `purchase_tax_group_id` needs expand + contract.** The FK onto existing `products` is contract-phase (SourceRules AC-34). There is no backfill and it stays nullable. Both keys go in the **same single contract file** (T-014 D-31: one contract file, not two consecutive ones the planner would force into two fleet runs).
- **F-5 (correction to AC-1): the pre-check names a count, never ids.** The runner stores the failure message verbatim (T-011 D-10), and every shipped contract file (`add_branch_foreign_keys`) names table, column and **count** only. The message names the offending row count and the remedy.
- **F-6 (correction to AC-10): `tax_groups` has no `[SOFT]`.**
  - There is no delete slice; a group is retired with `is_active = false`, and that is refused while referenced (AC-10's count).
  - Deactivating the **default** group clears `is_default` in the same transaction (T-028 F-19's shape). Afterwards `CreateProduct` without `tax_group_id` refuses `no_default_tax_group`, and does not silently pick an inactive group.
- **F-7 (correction to AC-12): `company_profile` does not exist.** `price_lists.currency` is **required** (CHAR(3), `^[A-Z]{3}$`). T-028 F-8 rejected silently defaulting a currency for the same reason. Once `company_profile` ships, defaulting is an additive change.
- **F-8 (correction to AC-14): restate why there is no FK, and existence-check all three scopes.**
  - `scope_id` names one of three tables, so it **cannot** carry a key: §3.3's polymorphic exception, not a branch-dependency accident.
  - Because `customer_groups` and `customers` exist in this tree, the plan existence-checks all three scope types (`Lookup::live`: the row exists and is not soft-deleted). That is the same direct existence-check precedent catalog uses for `branches` (`CreateProductBranchHandler`). A dangling assignment would otherwise be silent dead data.
  - If `ba` prefers the spec's "branch only", step 14 drops two dataset rows.
- **F-9 (gaps in AC-11): the rate scale, compounding, `fixed` taxes and `applies_to`.**
  - **Rates are percentages:** `15.0000` means 15 %. §3.2's "rate/percentage `DECIMAL(9,4)`" cannot hold 12.345 % as a fraction (0.12345 needs 5 dp). AC-11's `1 + total_rate` reads `1 + total_rate/100`.
  - **Compound:** a compound member's base is the line base **plus every member amount before it in `sequence`**, compound or not (the conventional definition). `ba` wrote "every non-compound member that precedes it", which differs only when a group has two compound members. The plan takes the conventional reading; `ba` confirms or flips one line in `TaxCalculator`.
  - **`type = fixed`:** `rate` is an amount per **stock** unit (§6.3: product money is per stock unit), so `amount = round(rate × base_quantity, 4)`. A fixed tax cannot be compound (refused at write, `fixed_cannot_compound`). In an inclusive group, fixed amounts are taken out of the gross before the percentage back-out; if they exceed it, the result is `inclusive_amount_too_small`.
  - **`applies_to`:** the engine is told the context (`sale` | `purchase`) and skips members whose `applies_to` excludes it.
  - **Inactive or soft-deleted member taxes are skipped.** Deactivating is how a shop stops charging a tax. A live member with no effective rate is still `no_effective_rate` (AC-7).
  - **Inclusive rounding (superseded in detail by D-25 / AC-11.6; Chunk B shipped the corrected rule):** the net comes from the exact linear relation `G − F = net × (1 + S) + K` (F the fixed total; S and K built exactly while walking the members in `sequence`, per AC-11.6), so `net = round((G − F − K) / (1 + S), 4)` is the only rounding. Taxes are then computed forward from that net. The rounding residue `G − net − Σtaxes` is added to the **last percentage member in `sequence`**, so `net + Σtaxes = G` exactly. This replaces "iteratively, tax by tax": it does not accumulate rounding at each step, and it fixes which member absorbs the cent. (Revision 2 of this bullet divided by a multiplier taken as the product of the members' factors; that holds only when every percentage member compounds, which is the defect D-25 records.)
  - **Breakdown entry:** `{tax_id, code, tax_code, type, is_compound, rate, base, amount}`. `tax_code` is the printed code and may be null. `base` is what a tax return needs.
- **F-10 (gaps in AC-15): what each step selects.**
  - **Every** candidate list must be `is_active`, not soft-deleted, have its window contain the document instant (`starts_at ≤ at ≤ ends_at`, a null bound being open), and have `currency` equal to the document currency (a list in another currency is skipped, never converted). D-3 already says every list type is optionally date-ranged; AC-15 applied the window only to promotions.
  - **Steps 1, 2 and 4** select lists **by assignment** (`scope_type` `customer`, `customer_group`, `branch` matching the line). They consider every **non-promotion** list; `type` is not a further filter. AC-15's "type = standard" at step 1 alone is dropped, because §6.4 says "customer price list", not "standard list". If `ba` wants `type` to be enforced, the place is a write-time rule on assignments, not the ladder.
  - **Step 3:** `type = promotion` lists. A promotion with **no** assignments applies everywhere. One **with** assignments applies only when one of them matches the line's customer, group or branch. A branch-only promotion is the ordinary case, and §6.4's "active promotion lists by priority" does not say promotions are unscoped.
  - **Several lists in one step:** they are tried in order `assignment.priority` desc (steps 1, 2, 4 only), then `price_lists.priority` desc, then `price_lists.code` asc as the deterministic tiebreak. The first list that prices the line wins (§6.4: "first hit wins").
- **F-11 (gap): `price_list_items.discount_percent` is stored but has no meaning in the spec.** A stored number the engine ignores is a trap. **Plan:** the ladder returns `list_price` (the typed or derived price) and `unit_price = round(list_price × (1 − discount_percent/100), 4)` when the winning item has a `discount_percent`, plus that percent. This maps onto `sale_items.list_price` / `unit_price`. `ba` may instead rule it informational, which removes one ladder line and two fixtures.

**Gaps in the engine's discount half (F-12, F-13):**

- **F-12 (gaps in AC-20, AC-22): when a discount applies.** The engine refuses, in this order:
  - `unknown_discount`;
  - `discount_scope_mismatch` (a `bill` discount passed to a line, or the reverse);
  - `inactive`, `not_yet_started`, `expired`, `usage_limit_reached` (AC-20), checked against the **document instant** passed in, never the wall clock, because the engine is pure and a fixture must replay identically next year;
  - `discount_not_allowed` when `products.allow_discount = false` (a T-027 column the spec does not mention);
  - `not_applicable`: item scope, when `applies_to` names at least one list and the product's id, `category_id` or `brand_id` is in none of them (OR across the three). A null or empty `applies_to` applies to all;
  - `below_min_amount`: `subtotal` (item) or the bill base (bill) is below `min_amount`;
  - `unsupported_discount_type` for `buy_x_get_y` (AC-22).

  `requires_approval` and `approval_threshold` are not evaluated (D-8).
- **F-13 (correction to AC-23): the bill discount base is pre-tax.** `Σ line_total` includes exclusive tax, which applies the bill discount to tax: the opposite of D-4's discount-before-tax. **Plan:** base = `Σ discounted_subtotal` of the supplied lines. Allocation, and the re-taxation of each line after allocating, stay with the future Sales caller (AC-23 unchanged on that point).

**Informational. The plan applies these, and `ba` may confirm or change them (F-14 to F-18):**

- **F-14 (AC-25 wording):**
  - "Computing a percentage/rate multiplication against a tax or discount value" is not measurable as written. The instrument (step 17) scans for two things outside `packages/pricing/src`: (a) the code tokens `tax_rates`, `tax_group_taxes`, `price_list_items`, `price_list_assignments`, `is_inclusive` and `is_compound`; (b) a multiplication or `multipliedBy(` next to a token ending in `rate` or `percent`.
  - (b) is **measured** on today's tree at step 17. A pre-existing hit is reported to `architect`, never exempted silently. This follows the memory rule: name the instrument by what it scans.
  - The pure engine needs a narrow exemption in T-027's factor rule (Ruling 10), recorded in the spec's AC-25 text.
- **F-15 (AC-24, D-9): the fixture format must be TS-safe, and unit resolution is owed.**
  - Every decimal in a fixture is a JSON **string** with its full scale (`"7.5000"`, factor `"24.000000"`). A JSON number is refused by the fixture lint (step 10).
  - T-006's FP-231 obligation ("unit resolution joins the shared PHP/TypeScript fixtures", `docs/plans/T-006-unit-of-measure.md` line 679; `docs/architecture.md` §15) is missed by the spec. **Plan:** a small `packages/catalog/fixtures/units/` set (round to the line unit's precision first, then the base, HALF_UP), replayed by a **catalog** test against `SnapshotCalculator`, so no boundary is crossed. FP-432 and FP-237 read the same files.
- **F-16 (D-6's premise):** §8 `seed_core` lists a default tax group (see 0.1). No `seed_core` change is needed, and D-6's text should cite §8.
- **F-17 (reverses D-7, edits AC-4, AC-19, AC-29):**
  - `tax_authorities.payable_account_id` and `receivable_account_id`, and `discounts.sales_discount_account_id`, are **not built**. A command naming them is refused as unknown (`Fields::only`), the T-027 AC-27a shape.
  - Finance (FP-260) owes expand + contract for all three, plus a backfill + tighten for `payable_account_id`. AC-29's static test then asserts their **absence** (step 4).
- **F-18 (AC-3, design):** catalog validates and defaults the tax group through a new published contract, `Contracts\Pricing\TaxGroupDirectory` (`state(id): TaxGroupState`, `defaultGroupId(): ?string`). It mirrors `Organization\BranchDirectory`/`BranchState` exactly, and keeps "one default" in one owner. Catalog never reads `tax_groups` itself.

### 0.3 Rulings

**Ruling 1: placement.** A new path package `packages/pricing` (`flexipos/pricing`, namespace `Flexipos\Pricing\`), with contracts under `packages/contracts/src/Pricing/`.
- **Name:** §6.4 is "Pricing and tax", the documented server entry point is `pricing.quote`, and the spec proposes the path.
- **Not inside `packages/catalog`**, although `docs/architecture.md` §3's Catalog row lists "price lists, taxes, discounts". A separate deptrac layer is what *proves* pricing reaches units only through `UnitResolver`, and catalog only through `TaxGroupDirectory`. Inside catalog, that edge would be invisible. Step 19 corrects §3's row.
- **Direct reads of other packages' tables** are limited to existence checks and the read-only price facts (`products.selling_price`, `category_id`, `brand_id`, `allow_discount`, `tax_group_id`, `purchase_tax_group_id`, `stock_unit_id`; `product_branches.selling_price`; `branches`/`customer_groups`/`customers`/`workflow_definitions` existence). That is the `Lookup` precedent every package already uses. A catalog "product facts" contract is rejected as premature (section 2).
- `Support/` helpers (`TenantConnection`, `Fields`, `Identifier`, `Lookup`) are the **ninth** copy (FP-331).

**Ruling 2: the published contract** (`packages/contracts/src/Pricing/`, plain PHP, no vendor imports):

| Symbol | Shape |
|---|---|
| `PricingEngine` | `quoteLine(LineQuote $q): PricedLine`; `computeTax(TaxQuote $q): TaxBreakdown`; `billDiscount(BillQuote $q): BillDiscount`. All three throw `PricingRefused`. Docblock: the **only** place tax, price-list and discount arithmetic happens; never read `tax_rates`/`price_list_items` yourself (AC-25) |
| `LineQuote` | `productId`, `UnitSnapshot $unit` (from `UnitResolver::snapshot()`, never hand-built), `at` (UTC `DATETIME(3)` string), `documentDate` (`Y-m-d`, branch-local), `currency` (3 letters), `?customerId`, `?customerGroupId`, `?branchId`, `?discountId` |
| `TaxQuote` | `taxGroupId`, `amount` (4 dp), `baseQuantity` (4 dp), `documentDate`, `TaxContext $context`. For purchase lines and any caller with its own price |
| `BillQuote` | `discountId`, `at`, `list<string> $discountedSubtotals` (F-13) |
| `PricedLine` | `listPrice`, `listDiscountPercent?`, `unitPrice`, `PriceSource $source` (step 1–6, `?priceListId`, `unit_matched` / `derived` / `product`), `subtotal`, `discountAmount`, `?discountId`, `discountedSubtotal`, `taxGroupId`, `TaxBreakdown $tax`, `lineTotal`. Canonical 4-dp strings |
| `TaxBreakdown` | `isInclusive`, `net`, `list<TaxLine> $lines`, `total`; `TaxLine` = F-9's entry shape. `toArray()` is exactly the `sale_items.tax_breakdown` JSON |
| `BillDiscount` | `discountId`, `base`, `amount` |
| `TaxGroupDirectory`, `TaxGroupState` | F-18 (`active`, `inactive`, `unknown`) |
| `TaxContext` | `sale`, `purchase` |
| `PricingRefused` / `PricingRefusal` | `RuntimeException` with `public readonly PricingRefusal $reason`: `unknown_product`, `unknown_tax_group`, `no_effective_rate`, `inclusive_amount_too_small`, `no_price_resolved`, `unknown_discount`, `discount_scope_mismatch`, `inactive`, `not_yet_started`, `expired`, `usage_limit_reached`, `discount_not_allowed`, `not_applicable`, `below_min_amount`, `unsupported_discount_type`, `invalid_amount`, `out_of_range` |

| `Shape` (shipped in Chunk B, K-2) | the shared shape checks (`money`, `rate`, `factor`, `ulid`, `date`, `instant`, `currency`) used by both the DTOs and the pure engine's inputs, so the two layers cannot drift. A malformed amount is `PricingRefused(invalid_amount)`; a malformed id, date, instant or currency is an `InvalidArgumentException` naming the field. `ulid()` folds to upper case |

That is **16 files** (Chunk B added `Shape`; also `PriceBasis` and `PriceSource` are their own files). The DTO constructors check **shape only** (ULIDs, decimal patterns, date formats), which is `UnitSnapshot`'s own discipline.

**Ruling 3: arithmetic.** `brick/math` `BigDecimal` throughout, never a float (T-027 Ruling 5; already locked).
1. Inputs: money and quantity `^\d{1,14}(\.\d{1,4})?$`, rate `^\d{1,5}(\.\d{1,4})?$`, factor from `UnitSnapshot`. Anything else is `invalid_amount`.
2. **Round at each named step, `HALF_UP`, scale 4, and never carry an unrounded value into the next step** (AC-22, §3.2's order). The named steps are exactly: derived price, list-percent price, subtotal, discount amount, each tax amount, inclusive base. Additions and subtractions of 4-dp values are exact.
3. Percent arithmetic is `x × p / 100`, computed exactly and then rounded once. Never `x × (p/100 rounded)`.
4. The inclusive back-out follows AC-11.6 as corrected by D-25 (shipped in Chunk B, `TaxCalculator::inclusiveNet`): `S`, `K` and the fixed totals are built exactly while walking the members in `sequence` (each `r/100` is an exact fraction of at most 6 dp, so every product is a finite decimal), and the only rounding is `net = round((G − F − K) / (1 + S), 4)`, HALF_UP. `1 + S` equals the product of every `1 + r/100` only for an all-compound group with no fixed member; for non-compound 5 % and 10 % it is `1.15`, not `1.155`.
5. Range: any result above `99999999999999.9999` is `out_of_range`. Output is canonical, and a zero is never negative.

**Ruling 4: the unit factor comes from the caller's `UnitSnapshot`, never from a table.** The engine's only factor arithmetic is `price × UnitSnapshot.unitFactor` (Ruling 5), in a single method, `PriceLadder::derive()`. Nothing in `packages/pricing` names `unit_conversions`, `base_unit_id` or `units.factor`, and T-027's own scan proves that. The snapshot's trust boundary is the one `UnitSnapshot`'s docblock already states. `quoteLine` does not re-resolve the factor, because a refund line (FP-233's `snapshotInherited`) legitimately carries an older one.

**Ruling 5: `Engine\PriceLadder` (pure; AC-15 to AC-17, F-2, F-10, F-11).** `resolve(LadderInput): LadderOutcome`. As shipped, `LadderInput` holds the line (`unitId`, `unitFactor`, `baseQuantity`; **no `quantity`**, which lives on `LineInput` because only the subtotal uses it, K-1), `at`, `currency`, the three scope ids, the candidate `list<LadderList>` (each with `listId`, `code`, `ListType $type`, `currency`, `priority`, `startsAt`/`endsAt`, `isActive`, **`isDeleted`**, `list<LadderAssignment>` and the product's `list<LadderItem>`), `branchSellingPrice?` and `sellingPrice?`. Ties between lists fall to a bytewise `strcmp` on `code` (D-29).
1. Filter the lists (F-10). Build step 1 (customer assignments), step 2 (group), step 3 (promotions, scoped per F-10), step 4 (branch), each ordered per F-10.
2. For each list in order, apply the **unit-outer rule**:
   - (a) items with `unitId = line unit` and `minQty ≤ baseQuantity`: take the greatest `minQty` and use its price **as typed**;
   - (b) otherwise items with `unitId = null`, the same test: `derive(price)`;
   - (c) otherwise try the next list, then the next step.
3. Step 5: `branchSellingPrice` non-null gives `derive()`. Step 6: `sellingPrice` non-null gives `derive()`. Otherwise `no_price_resolved`.
4. If the winning item has a `discountPercent`, apply it (F-11).

A duplicate `minQty` within one branch cannot happen (it is the unique key). The ladder still asserts it, refusing **`invalid_amount`** (D-28, AC-30.3) rather than picking by order. As shipped, that check is itself order-dependent (K-9); step 10a makes it a function of the item set.

**Ruling 6: `Engine\LineCalculator` (pure; AC-22, F-1).** The fixed order:
1. `unitPrice` (from the ladder);
2. `subtotal = r(unitPrice × quantity)`;
3. the discount (`DiscountCalculator`, F-12): percentage `r(subtotal × value / 100)` capped at `maxDiscount`; fixed `min(value, subtotal)`. Then `discounted = subtotal − discountAmount`;
4. tax on `discounted` (`TaxCalculator`);
5. `lineTotal = discounted + tax.total` (exclusive) or `discounted` (inclusive).

**Ruling 7: `Engine\RateResolver` and `Engine\TaxCalculator` (pure; AC-7, AC-9, AC-11, F-9).**
- `RateResolver::resolve(list<RateRow>, date)` returns the row with the greatest `effectiveFrom ≤ date` and (`effectiveTo` null or `> date`). Otherwise it refuses with `no_effective_rate`. Dates are compared as `Y-m-d` strings, which order correctly by construction.
- `TaxCalculator::compute(members, amount, baseQuantity, date, context, inclusive)` does: filter (F-9) → order by `sequence` → resolve rates → run forward (exclusive) or apply the exact linear back-out of AC-11.6 / D-25 (Ruling 3.4, shipped as `inclusiveNet`) plus the residue rule (inclusive). An empty group gives `net = amount`, no lines, total `0.0000`. A fixed member's breakdown `base` is the line amount (the net, inclusive), never the quantity (D-30). Members tied on `sequence` fall to a bytewise `strcmp` on the tax id (D-29).

**Ruling 8: the DB adapter, `Engine\DatabasePricingEngine`** (implements `PricingEngine`). It reads exactly the rows the pure classes need, builds their inputs and calls them. It holds **no arithmetic, no ordering and no selection**. The ordering lives in `PriceLadder` and `TaxCalculator`, so a fixture proves it.

**The D-29 ruling (revision 3): the adapter never orders; the pure engine does all ordering.** Rejected: a "proven-equal" SQL order (`ORDER BY code COLLATE utf8mb4_bin` or `BINARY code`). It would make the database a second source of truth for an order the TypeScript twin (FP-432) must reproduce from the fixtures alone, it is spelled differently on MySQL, MariaDB and SQLite, and the shipped engine re-sorts its input anyway, so a SQL order could only ever be redundant or wrong. Concretely:
- **No `ORDER BY`, no `orderBy(`, no `LIMIT`/`first()` over a candidate set** (lists, assignments, items, group members, rates). Every candidate row is passed. `first()` is allowed only on a primary-key or unique-id lookup (the product, the tax group, the discount, the `product_branches` row).
- **SQL predicates are id equality only:** `product_id`, `price_list_id IN`, `tax_group_id`, `tax_id IN`, discount `id`. Currency, window, `is_active` and the soft-delete flag of lists and taxes are **not** filtered in SQL; they are passed through (`LadderList::$isDeleted`, `TaxMember::$isDeleted`/`$isActive`) and the pure engine skips them, so the fixture-proven filter is the only filter. The one exception is the discount: `DiscountRule` has no deleted flag, so a soft-deleted discount is simply not loaded and surfaces as `unknown_discount` (K-6).
- **Normalisation, the adapter's only transformation** (K-5): every id read is folded to upper case (the engine compares ids with `===`, and `Shape::ulid` folds the quote's ids), every decimal is cast `(string)` and handed to the engine's `Shape` checks, every `DATETIME(3)` is rewritten from `Y-m-d H:i:s.v` to the `YYYY-MM-DDTHH:MM:SS.mmmZ` instant, integers (`priority`, `sequence`, `usage_limit`, `used_count`) are cast `(int)`.
- **Candidate lists:** every `price_lists` row having at least one `price_list_items` row for the product (one query, `whereIn` on the ids from the items), with all their assignments and that product's items.
- **The tax group:** `quoteLine` uses `products.tax_group_id`; a null or dangling id gives `unknown_tax_group`. `computeTax` uses `TaxQuote::$taxGroupId` exactly as given: `TaxQuote` carries no product id, so **choosing `purchase_tax_group_id ?? tax_group_id` is the purchasing caller's job**, not the adapter's (K-3; follow-up in section 10). The `TaxContext` only filters members by `applies_to`.
- A product that is unknown or soft-deleted gives `unknown_product`.
- Bound as a singleton in `PricingServiceProvider`.

**Ruling 9: CRUD.** The T-027/T-028 slice shape:
- `Features/<X>/{X, XHandler, XResult}`;
- `array $fields` plus `?string $actorId`, with `Fields::only`;
- codes trimmed, with the duplicate pre-check on `lower()` and the unique index as the backstop;
- one audit row per write, and refusals write nothing.

21 slices:

| Area | Slices | ACs |
|---|---|---|
| Authorities | `CreateTaxAuthority`, `UpdateTaxAuthority` | 4 |
| Taxes | `CreateTax`, `UpdateTax` | 5, F-9 |
| Rates | `CreateTaxRate`, `CloseTaxRate` (sets `effective_to`/`note` only; `rate` and `effective_from` are immutable once written, because documents resolved against them) | 6, 7 |
| Groups | `CreateTaxGroup`, `UpdateTaxGroup` (defaults, `is_active` with the AC-10 guard, F-6), `AddTaxGroupTax`, `RemoveTaxGroupTax` | 8–10 |
| Price lists | `CreatePriceList`, `UpdatePriceList`, `DeletePriceList` (soft, with the AC-18 guard) | 12, 18 |
| Items | `AddPriceListItem`, `UpdatePriceListItem` (`price`, `discount_percent`), `RemovePriceListItem` (hard delete; the table has no `[TS]`/`[SOFT]`) | 13 |
| Assignments | `AssignPriceList`, `UnassignPriceList` (hard delete) | 14, 18 |
| Discounts | `CreateDiscount`, `UpdateDiscount` (refuses `used_count`), `DeleteDiscount` (soft) | 19–21 |

- `AddTaxGroupTax` also refuses a duplicate `sequence` within a group. That is backed by a `unique (tax_group_id, sequence)` index, which is not documented. Without it, compounding order would depend on row order.
- Overlap checks (AC-6) and default-clearing (AC-8) run inside a transaction holding `lockForUpdate()` on the parent `taxes` row and on the old default row respectively (on SQLite a no-op; `[ENGINE]`).
- **No list or get slices:** no AC asks for them, and the admin-UI/API task owns them (section 10).

**Ruling 10: the T-027 factor-rule exemption.**
- `QaCatalogSourceRulesTest` gains `QA_CATALOG_FACTOR_MULTIPLICATION_EXEMPT = ['packages/pricing/src/Engine/PriceLadder.php']`. It is resolved through `qaCatalogResolveExemptPaths` (W-3: a rename fails loudly) and documented as §6.4's sanctioned *price* derivation from a `UnitSnapshot` factor, never from a column.
- The scan stays on for every other file, and the exemption is scoped to that one rule only, the same discipline as the two existing lists.
- `qa` owns that file. The edit is in section 9's list.

**Ruling 11: test cost.** T-028 Ruling 12, unchanged.
- The pure engine carries most of the cases, as datasets plus fixtures.
- Each slice gets one fixture-backed file.
- `composer check` runs at each chunk boundary.
- If a chunk adds more than 10 % to the `test` step, stop and report to `lead` (FP-328).

**Ruling 12: the vocabulary sweep.** Before step 5, grep `QaVocabularyRenameSweepTest`'s retired spellings against every enum value here: `standard`, `customer_group`, `promotion`, `branch`, `percentage`, `fixed`, `buy_x_get_y`, `item`, `bill`, `sale`, `purchase`, `both`, `monthly`, `quarterly`, `yearly`. They are checked, not assumed.

### 0.4 Revision 3 addendum: reconciled with the shipped Chunk B (`fbf2aa1`) and the corrected spec (`7280657`)

**Spec corrections folded in.** AC-11.6 (rewritten by D-25) and AC-30 (D-26 to D-30). F-9's inclusive bullet, Ruling 3.4, Ruling 7, section 2 and step 7 now state the exact linear rule; Chunk B already ships it, so **no Chunk B code changes for D-25**. D-26 (negative quantity), D-27 (percentage discount above 100), D-28 (duplicate `min_qty`) and D-30 (a fixed line's `base`) are as built.

**The D-29 ruling.** The adapter never orders and never filters beyond id equality; the pure engine does all ordering and all filtering (Ruling 8). Proof is in four places: step 10a (the pure ladder is insensitive to input order), step 16 (codes `10`, `9`, `B`, `a`, inserted in reverse order, through the adapter; M-D1 (a) to (c)), step 17 (a source scan that the adapter file contains no ordering call) and step 18 (the same case on MySQL with a control that the column collation really orders `a` before `B`).

**Drift between the plan and what Chunk B shipped (K-1 to K-12).** Items marked *fixed* are corrected in the text above; the rest are instructions for Chunk C/D.

| # | Finding | Action |
|---|---|---|
| K-1 | `LadderInput` has **no `quantity`**; the line-unit quantity is on `LineInput` (the only consumer is the subtotal) | fixed in Ruling 5. The adapter builds `LadderInput` from `UnitSnapshot::$unitId/$unitFactor/$baseQuantity` and passes `UnitSnapshot::$quantity` to `LineInput` |
| K-2 | `Contracts\Pricing\Shape` is a 16th contract file | fixed in Ruling 2, section 3, step 5 |
| K-3 | `TaxQuote` has no product id, so the adapter **cannot** pick `purchase_tax_group_id` inside `computeTax`. Revision 2's Ruling 8 bullet and step 16 bullet assumed it could | fixed: `computeTax` uses the group it is given; the purchasing caller picks the column (section 10). The spec never required the adapter to pick it, so no spec change |
| K-4 | `Engine/` shipped 22 files, not 14: `TaxType`, `TaxApplies`, `ListType`, `ScopeType`, `DiscountType`, `DiscountScope` (enums), `LadderAssignment` and `ProductFacts` | fixed in section 3. The CRUD slices validate enum fields with these same enums' `tryFrom`, so the stored strings and the engine's cases cannot drift |
| K-5 | The engine compares ids with `===` and requires the `Z` instant shape and string decimals; the database gives `CHAR(26)` ids in whatever case was written, `Y-m-d H:i:s.v` datetimes and, on SQLite, numeric values | fixed in Ruling 8 (normalisation is the adapter's one transformation) and tested in step 16 |
| K-6 | `DiscountRule` has no deleted flag (unlike `LadderList` and `TaxMember`) | fixed in Ruling 8: a soft-deleted discount is not loaded and surfaces as `unknown_discount`; tested in step 16 |
| K-7 | `tests/Support/Build.php` exists with `Build::id` and `Build::reasonOf`; `PricingSeed` and `pricing-fixture.php` do not exist yet | fixed in section 3: Chunk C creates `PricingSeed` and reuses `Build::id`; slice tests use `Build::reasonOf` only for `PricingRefused` (the CRUD slices throw `PricingAdminRefused`, a different exception) |
| K-8 | Fixtures require `discriminates`, use snake_case input keys, and encode integers as strings | fixed in section 4's fixture format; step 19 documents the shipped key set, not revision 2's |
| K-9 | **Order-dependence in the shipped duplicate-`min_qty` check** (`PriceLadder::greatestQualifying`): a duplicate is refused only if it ties the best item seen so far, so `1, 1, 5` refuses and `5, 1, 1` prices. Today's data cannot hit it (the unique key), but the pure engine's result depends on row order, which is exactly what D-29 forbids, and the TypeScript twin would have to copy the iteration order | **new step 10a**. Needs a one-line spec clarification (Questions, item 1) |
| K-10 | `TaxGroupDirectory`'s docblock puts the upper-case fold on the caller | fixed in step 13 |
| K-11 | AC-19 stores `applies_to` "as supplied", but `DiscountRule` needs three lists of strings; a malformed stored value would make the adapter fail at quote time instead of the admin at write time | step 15 validates the shape (keys and list-of-strings only, no existence check). Needs a spec confirmation (Questions, item 2) |
| K-12 | The spec does not say what `quoteLine`/`computeTax` do with an **inactive** tax group. AC-10 prevents deactivating a referenced group, so `quoteLine` cannot meet one through a product, but `computeTax` takes any id | plan default: compute it normally (only an unknown id is `unknown_tax_group`), because deactivation retires a group for new assignments and a caller re-taxing an older document must still get an answer. Informational for `ba` |

**Also recorded:** the factor-rule exemption (Ruling 10) landed in Chunk B, not step 17; `BranchForeignKeysContractRunTest` (Chunk A) was missing from section 9 and is now listed.

---

## 1. Approach summary

1. A new `packages/pricing`. Everything outside it reaches it only through `Contracts\Pricing` (`PricingEngine`, `TaxGroupDirectory`).
2. Nine new tenant tables (§6.4, minus the three deferred account columns), plus three migrations on `products`: expand (two nullable columns), backfill (default `STANDARD` group, every product set), and **contract (two FKs only)**. `NOT NULL` is enforced by the application until backups exist (F-3).
3. A **pure, DB-free engine** in `Engine/`: `RateResolver`, `TaxCalculator`, `PriceLadder` (the six steps with unit-outer, `min_qty`-inner selection; steps 5–6 derive by factor), `DiscountCalculator` and `LineCalculator`. Arithmetic is exact `BigDecimal`, rounded HALF_UP at named steps. The unit factor always comes from the caller's `UnitSnapshot`.
4. A checked-in JSON fixture set (`packages/pricing/fixtures/`, decimals as strings), replayed in full by the PHP tests and linted for TS-safety. Unit-snapshot cases sit beside catalog (F-15).
5. One DB adapter gathers rows and calls the pure classes. 21 CRUD slices, plus `tax_group_id`/`purchase_tax_group_id` on catalog's product handlers through `TaxGroupDirectory`.
6. Static instruments: precision map (AC-28), migration phases and absences (AC-29), the one-engine source scan (AC-25), and T-027's factor rule with a single named exemption.

## 2. Alternatives rejected

- **Tightening `NOT NULL` now via `->change()`:** destructive under the classifier. It would refuse provisioning (F-3).
- **Changing `SourceRules::isDestructive`** to exempt nullability tightening: a kernel safety rule, and outside this task's remit. Recorded as a follow-up.
- **Building the account columns nullable without keys (D-7 as written):** placeholder data Finance must clean, and it contradicts the spec's own NFR (F-17).
- **Putting pricing inside `packages/catalog`:** it loses the deptrac edge that proves the `UnitResolver`-only discipline (Ruling 1).
- **A catalog `ProductPricingFacts` contract** in place of direct column reads: there is no second consumer yet, and existence and price reads are the established `Lookup` precedent. Revisit if catalog's product shape changes.
- **The engine calling `UnitResolver::snapshot()` itself:** it double-resolves every line Sales already snapshots, and it breaks inherited-factor refunds (Ruling 4).
- **Renaming variables to dodge T-027's factor scan:** evasion. The exemption is named and narrow (Ruling 10).
- **A JSON Schema file for the fixtures:** the lint test (step 10) plus the §6.4 as-built note is enough until FP-432 asks for more.
- **"Iterative, tax by tax" inclusive back-out:** it compounds rounding at each step. The exact linear relation of AC-11.6 (D-25) plus one rounding gives a stable net (F-9; shipped in Chunk B).
- **Dividing the inclusive gross by the product of every `1 + rate/100`** (revision 2's wording): correct only when every percentage member compounds; for two non-compound members it leaves a residue that is not a rounding artefact (about 0.43 on a gross of 115 at 5 % and 10 %; D-25).
- **Ordering candidate rows in SQL, even with a binary collation** (D-29): a second source of truth the TypeScript twin cannot see, and engine-specific. The adapter passes rows unordered and the pure engine orders (Ruling 8).

## 3. Files to create

`dev` writes every file. `Qa*` files are `qa`-accountable.

### `packages/contracts/src/Pricing/` (new; shipped in Chunk B)
The 16 files of Ruling 2.

### `packages/pricing` (all new)

| Path | What |
|---|---|
| `composer.json` | `flexipos/pricing` 0.1.0, requiring `php ^8.2`, `brick/math ^0.14` and `flexipos/contracts`, the `packages/customers/composer.json` shape |
| `src/PricingServiceProvider.php` | binds `PricingEngine` to `Engine\DatabasePricingEngine` and `TaxGroupDirectory` to `Taxes\DatabaseTaxGroupDirectory`; `implements DeclaresAuditActions`. No routes and no commands |
| `src/Engine/{Decimal,RateRow,RateResolver,TaxMember,TaxType,TaxApplies,TaxCalculator,LadderInput,LadderList,LadderAssignment,LadderItem,LadderOutcome,ListType,ScopeType,PriceLadder,DiscountRule,DiscountType,DiscountScope,DiscountCalculator,ProductFacts,LineInput,LineCalculator}.php` | Rulings 3 to 7, **shipped in Chunk B** (22 files; the six enums and `LadderAssignment`/`ProductFacts` are the K-4 additions). Pure: no `Illuminate\` import (step 17 asserts that) |
| `src/Engine/DatabasePricingEngine.php` | Ruling 8 |
| `src/Taxes/DatabaseTaxGroupDirectory.php` | F-18 |
| `src/Support/{TenantConnection,Fields,Identifier,Lookup}.php` | the ninth copy (FP-331) |
| `src/Exceptions/PricingAdminRefused.php` | the CRUD refusals: `validation`, `notFound`, `duplicate`, `inUse`, `overlap`, `maintained`, `unresolvableUnit`, `noDefaultTaxGroup` |
| `src/Registry/PricingAuditActions.php` | built from each handler's `ACTION` |
| `src/Features/<21 slices>/…` | Ruling 9 |
| `fixtures/{tax,price,discount,line,bill}/*.json` | step 10 |
| `tests/Support/{Build.php,FixtureReplay.php}` (shipped in Chunk B) | `Build`: pure-input constructors, `Build::id(label)` (a 26-char upper-case ULID-shaped id) and `Build::reasonOf(callable)` (the refusal value, or null); `FixtureReplay` loads, lints and maps the JSON |
| `tests/Support/PricingSeed.php` (new in Chunk C) | raw-insert seeds (unit, category, product, branch, group, tax, rate, list, item, assignment, discount). Use `Build::id` for ids so seeded rows and fixtures share one id shape. No separate `pricing-fixture.php` is needed unless a slice test wants shared Pest helpers (K-7) |
| `tests/Engine/{DecimalTest,RateResolverTest,TaxCalculatorTest,PriceLadderTest,DiscountCalculatorTest,LineCalculatorTest,FixtureSetTest}.php` | Chunk B |
| `tests/Engine/DatabasePricingEngineTest.php` | step 16 |
| `tests/Contracts/{PricingShapesTest,PricingEnumsTest}.php` | step 5 |
| `tests/Schema/{PricingTenantSchemaTest,ProductTaxGroupMigrationsTest}.php` | steps 2 and 3 |
| `tests/Support/PricingProviderTest.php` | step 1 |
| `tests/Features/<X>/<X>Test.php` | one per slice |

### `packages/catalog`

| Path | What |
|---|---|
| `fixtures/units/*.json` (new) | F-15: about 8 snapshot cases |
| `tests/Units/UnitFixtureReplayTest.php` (new) | replays them against `SnapshotCalculator` |
| `src/Features/CreateProduct/CreateProductHandler.php`, `UpdateProduct/UpdateProductHandler.php` | AC-3 via `TaxGroupDirectory`; the two fields join `FIELDS` |
| `src/Exceptions/CatalogRefused.php`, `Support/CatalogRefusal.php` | `unknown_tax_group`, `inactive_tax_group`, `no_default_tax_group` |

### `modules/host`

| Path | What |
|---|---|
| `database/migrations-tenant/2026_11_10_002901_create_tax_authorities_table.php` … `002912_…` | in this order: 01 tax_authorities, 02 taxes, 03 tax_rates, 04 tax_groups, 05 tax_group_taxes, 06 `add_tax_group_columns_to_products` (expand), 07 `backfill_product_tax_groups` (`@phase backfill`), 08 price_lists, 09 price_list_items, 10 price_list_assignments, 11 discounts, 12 `add_product_tax_group_foreign_keys` (`@phase contract`). `IdentifierColumn`, `dateTime(…, 3)`, VARCHAR(30) enums, full decimal arguments, no `down()`, docblocks naming the deferred columns (F-17) and the deferred tighten (F-3) |
| `tests/Feature/QaPricingSourceRulesTest.php` | step 17 |
| `tests/Engine/PricingSchemaEngineTest.php` | step 18 |

## 4. Data / API changes

**Schema:** nine new tenant tables plus two columns on `products`. There is no master change.

| Table | Columns as built | Keys |
|---|---|---|
| `tax_authorities` | `id`, TS, `code` VARCHAR(50), `name` VARCHAR(150), `country` CHAR(2) NULL, `registration_no` VARCHAR(50) NULL, `filing_frequency` VARCHAR(30) NULL | unique `code`. **Not built:** `payable_account_id`, `receivable_account_id` (F-17) |
| `taxes` | `id`, TS, `deleted_at`, `authority_id`, `code` VARCHAR(50), `name` VARCHAR(150), `tax_code` VARCHAR(20) NULL, `type` VARCHAR(30), `is_compound`, `applies_to` VARCHAR(30), `is_recoverable`, `is_withholding` (all default false), `is_active` default true | unique `code`; FK `authority_id` |
| `tax_rates` | `id`, TS, `tax_id`, `rate` DECIMAL(9,4), `effective_from` DATE, `effective_to` DATE NULL, `note` VARCHAR(255) NULL | unique (`tax_id`, `effective_from`); FK `tax_id` |
| `tax_groups` | `id`, TS, `code` VARCHAR(50), `name` VARCHAR(150), `is_inclusive` default false, `is_default` default false, `default_marker` TINYINT UNSIGNED NULL stored `CASE WHEN is_default THEN 1 END`, `is_active` default true | unique `code`; unique `default_marker` (T-028 backstop) |
| `tax_group_taxes` | `tax_group_id`, `tax_id`, `sequence` TINYINT UNSIGNED | PK (`tax_group_id`, `tax_id`); unique (`tax_group_id`, `sequence`) (Ruling 9); FK `tax_group_id` **cascade**, `tax_id` |
| `products` (+2) | `tax_group_id` CHAR(26) NULL, `purchase_tax_group_id` CHAR(26) NULL | FKs onto `tax_groups` in migration 12 |
| `price_lists` | `id`, TS, `deleted_at`, `code` VARCHAR(50), `name` VARCHAR(150), `type` VARCHAR(30), `currency` CHAR(3), `priority` SMALLINT default 0, `starts_at` / `ends_at` DATETIME(3) NULL, `is_active` default true | unique `code` |
| `price_list_items` | `id`, `price_list_id`, `product_id`, `unit_id` NULL, `min_qty` DECIMAL(18,4) default 1, `price` DECIMAL(18,4), `discount_percent` DECIMAL(9,4) NULL, `unit_key` CHAR(26) stored `COALESCE(unit_id,'-')` | unique (`price_list_id`, `product_id`, `unit_key`, `min_qty`); index (`product_id`); FK `price_list_id` **cascade**, `product_id`, `unit_id` |
| `price_list_assignments` | `id`, `price_list_id`, `scope_type` VARCHAR(30), `scope_id` CHAR(26), `priority` SMALLINT default 0 | unique (`scope_type`, `scope_id`, `price_list_id`); FK `price_list_id`; **no FK on `scope_id`** (polymorphic, F-8) |
| `discounts` | `id`, TS, `deleted_at`, `created_by`, `updated_by`, `code` VARCHAR(50), `name` VARCHAR(150), `type` VARCHAR(30), `value` DECIMAL(18,4), `scope` VARCHAR(30), `applies_to` JSON NULL, `min_amount` / `max_discount` / `approval_threshold` DECIMAL(18,4) NULL, `requires_approval` default false, `workflow_definition_id` NULL, `starts_at` / `ends_at` DATETIME(3) NULL, `usage_limit` INT UNSIGNED NULL, `used_count` INT UNSIGNED default 0, `is_active` default true | unique `code`; FKs `workflow_definition_id`, `created_by`, `updated_by`; `JsonValidity` on `applies_to`. **Not built:** `sales_discount_account_id` (F-17) |

Notes on the schema:
- **Foreign keys:** 11 on new tables (taxes 1, tax_rates 1, tax_group_taxes 2, price_list_items 3, price_list_assignments 1, discounts 3), plus 2 in the contract file. `RESTRICT` everywhere except §6.4's two `CASCADE`s.
- **Backfill (07):**
  - when no `tax_groups` row has `is_default`, it inserts `STANDARD`/`Standard` (non-inclusive, default, active) with `Str::ulid()` and `RunnerClock` timestamps;
  - it then updates every product with a null `tax_group_id`;
  - it is retry-safe by construction: a re-run finds the default and no null rows.
- **Contract (12):** the T-014 shape exactly. Each data-clean count runs first, and the failure message names table, column and count only (F-5). A key already present is skipped. There is no driver gate (T-014 step 1 measured SQLite's rebuild). It is **not** destructive (no `change`), and step 3 asserts that from the catalogue row.
- **Precision map** (AC-28): (18,4) `price_list_items.price`, `min_qty`, `discounts.value`, `min_amount`, `max_discount`, `approval_threshold`; (9,4) `tax_rates.rate`, `price_list_items.discount_percent`. No fifth pair.
- **`APP_REQUIRED_SCHEMA_VERSION` 51 → 63**, and the catalogue is republished (`php artisan schema:publish --write-fixture`).
- **Planner consequence (migration 12 is a contract step):** a fleet below version 62 must be run to `--to=62` first, as with T-014. Provisioning-status databases do not block.

**Contracts:** Ruling 2. **HTTP, console, jobs, cache, hooks, outbox:** none. **Config:** none (no list slices, so no page size). **Dependencies:** the `flexipos/pricing` path package. `brick/math` is already locked, so the `composer.lock` diff must add only the path package entry.

**Fixture format (D-9 / F-15)**, one case per file:

```
{ "name": "...", "kind": "tax|price|discount|line|bill", "discriminates": "...",
  "input": { ... exactly the pure class's input, snake_case keys, ids as opaque 26-char strings ... },
  "expect": { ... } | "refusal": "<PricingRefusal value>" }
```

As shipped (K-8): `discriminates` is required (one sentence naming what the case proves), input keys are snake_case, and **integers are strings too** (`priority`, `sequence`, `step`, `used_count`), because the lint bans every JSON number. Decimals are strings at full scale. Dates are `YYYY-MM-DD`. Instants are `YYYY-MM-DDTHH:MM:SS.mmmZ`. There are no JSON numbers, no product lookups and no container. The exact key set is written into `docs/database.md` §6.4 "As built (T-029)" at step 19.

## 5. Boundary impact

- **`deptrac.yaml`**, the T-028 shape:
  - `Package_Pricing` = `^Flexipos\\Pricing\\(?!Tests\\).*`, ruleset `[Package_Kernel, Package_Contracts]`;
  - `Package_Pricing_Tests` gets those plus `Package_Pricing` and `Package_Access` (fixture);
  - `HostApp` and `HostTests` gain the layers;
  - **`Module_Demo` is not granted `Package_Pricing`** (the AC-26 instrument);
  - `Package_Catalog` needs no new edge, because `Contracts\Pricing` sits inside `Package_Contracts`.
- **PHPStan:** both paths. **Composer** (host): `require` gains `flexipos/pricing: *`; `autoload-dev` gains the tests namespace. Then `composer update flexipos/pricing` and `composer validate --no-check-publish`.
- **`bootstrap/providers.php`:** `PricingServiceProvider` after `CatalogServiceProvider`. Catalog resolves `TaxGroupDirectory` lazily inside its handlers, so provider order is not load-bearing. Step 13 proves that by resolving the handler with only the contract bound.

## 6. Implementation steps

The rules carried from T-027/T-028 §6 are unchanged:
- red-first, with a fixture or by leaving something out, and never by stubbing a guard to allow;
- the **red-list rule** after steps 1, 2, 3 and 13 (record it, compare it with section 9, and stop on anything section 9 does not name);
- no child processes;
- unique test basenames;
- list foreign `php.exe` processes before each gate run.

Mutations are run once, reverted, and recorded in `dev`'s hand-back with the red-then-green counts.

### Chunk A: plumbing, schema, static instruments

- [x] **Step 0: baseline.**
  - `composer check`: record passed, failed and skipped, the `test` seconds, and the process list.
  - The spec revision is committed (`85dc7ab`, D-12 to D-24, AC-16a, AC-24a). Build from the revised ACs, not from this plan's paraphrase, and not from the first draft in `0d840cf`.
  - *(No AC.)*
- [x] **Step 1: package plumbing.**
  - `composer.json`, an empty provider, and the section 5 edits. Add `'pricing'` to `QaToolchainRegistryCoverageTest`'s literal.
  - **`PricingProviderTest`:** the provider is loaded and `BigDecimal` autoloads.
  - **Negative control:** a scratch class in `modules/demo/src` importing `Flexipos\Pricing\Engine\PriceLadder` fails deptrac; the same class importing `Flexipos\Contracts\Pricing\PricingEngine` passes. Delete both.
  - **AC-25 baseline:** run step 17's pattern (b) (multiplication next to a `rate`/`percent` token) over the real tree **now**, before any pricing source exists, and record the hit list in the hand-back. A non-empty list is reported to `architect`.
  - *(AC-26 instrument; AC-25's pre-code measurement.)*
- [x] **Step 2: the nine table migrations (01–05, 08–11), with `PricingTenantSchemaTest`.**
  - Each table's sorted column list equals section 4 **exactly**, with nullability. That proves the **absence** of the three account columns (F-17).
  - Every unique key, by raw double insert:
    - **`unit_key`:** two `unit_id = NULL` rows for the same list, product and `min_qty` are refused; a NULL row and a unit row are accepted;
    - `default_marker`;
    - `(tax_group_id, sequence)`;
    - the assignment triple;
    - every `code`.
  - The FK set equals the 11 keys, and the two cascades delete their children.
  - `SourceRules::inspect()` reports nothing, and no file declares `down`.
  - *(The schema halves of AC-4 to AC-6, AC-8, AC-9, AC-12 to AC-14 and AC-19.)*
- [x] **Step 3: the `products` trio (06, 07, 12), with `ProductTaxGroupMigrationsTest`.**
  - Expand: two nullable columns, no key. Existing seeded products survive.
  - Backfill:
    - with zero groups, it creates exactly one `STANDARD` default and sets every product;
    - with a pre-seeded default `VAT15`, it creates no group and sets every product to `VAT15`;
    - a re-run changes nothing;
    - `purchase_tax_group_id` stays null on every row (AC-2).
  - Contract:
    - **red-first:** seed a product whose `tax_group_id` names a missing id; the file refuses, the message names `products.tax_group_id` and `1 row(s)` and no id (F-5), and nothing changes;
    - when clean, both keys exist;
    - a re-run is a no-op.
  - The catalogue rows read: 06 `expand`, 07 `backfill`, 12 `contract`, **all three `is_destructive = false`** (F-3's proof).
  - **Mutation S1:** write the tighten as `->change()` in 12, and the destructive assertion goes red. Revert.
  - **Predicted red:** section 9's schema list.
  - *(AC-1, AC-2; D-5, D-6, D-14.)*
- [x] **Step 4: AC-28 and AC-29 statics.**
  - Extend `TenantDecimalPrecisionTest`'s column map with section 4's precision map, under a T-029 comment. The allow-list stays at four pairs. **Red-first:** a fixture `decimal('rate', 9, 2)` is reported.
  - AC-29, in `ProductTaxGroupMigrationsTest`:
    - **AC-29.1:** the file that adds the columns (06) contains no FK call and no `change`;
    - **AC-29.2:** the contract file (12) contains no `change` (and step 3 asserts `is_destructive = false` from its catalogue row);
    - **AC-29.3:** no tenant migration names `accounts` in a key, and none declares `payable_account_id`, `receivable_account_id` or `sales_discount_account_id` (D-7 reversed).
    - **Red-first:** a scratch migration under a fixture directory declaring `payable_account_id` is reported. Delete it.
  - **Gate: `composer check` (end of chunk A).**
  - *(AC-28, AC-29.)*

### Chunk B: the arithmetic core (safety-critical, pure)

Steps 5 to 10 are **done** (`fbf2aa1`: 112 fixtures, 19 mutation checks). The step text below is kept as the record; where Chunk B departed from it, section 0.4 says so.

- [x] **Step 5: contracts.** Ruling 2's files (16 as shipped, `Shape` included), after Ruling 12's sweep.
  - **`PricingShapesTest`:** well-formed instances are accepted. Each of these is refused by name: a hand-built `LineQuote` with a malformed ULID, a 5-dp amount, a `2026-13-01` date, a `Z`-less instant, or a 2-letter currency.
  - **`PricingEnumsTest`:** `PricingRefusal`, `TaxContext` and `TaxGroupState` are each exactly Ruling 2's list.
  - *(Groundwork for AC-22 and AC-26.)*
- [x] **Step 6: `Decimal` and `RateResolver`.**
  - `Decimal`: the parse rows of Ruling 3.1 (float, exponent, 5 dp, blank and negative are refused); HALF_UP at `.00005`; never `-0.0000`; the range edge.
  - `RateResolver`, with rows 2024-01-01 → 2025-01-01 at 15 and 2025-01-01 → open at 18:
    - 2024-12-31 gives 15; **2025-01-01 gives 18** (`effective_to` is exclusive); 2023-12-31 gives `no_effective_rate`;
    - a gap between two closed rows gives `no_effective_rate`;
    - unsorted input gives the same results.
  - **Mutations:**
    - **R1:** `effective_to >= date`. The 2025-01-01 row goes red;
    - **R2:** return `0` on no match. The refusal row goes red.
  - *(AC-7.)*
- [x] **Step 7: `TaxCalculator`.** Datasets with exact strings.
  - **Exclusive, single 15 %:** 100.0000 gives 15.0000, total 115.
  - **Two non-compound** (5 %, 10 %): base 100 gives 5 + 10.
  - **Compound** (F-9): 5 % then compound 10 % on 100 gives 5 + 10.5. With two compounds, the second's base includes the first compound.
  - **Rounding:** 33.3333 × 15 % gives `5.0000` (from 4.999995); one row at an exact `.00005`.
  - **Inclusive, single 15 %:** 115.0000 gives net 100, tax 15. Also 9.9900 gives net 8.6870, tax 1.3030, and they sum to the gross.
  - **Inclusive, compound:** `base + Σ = gross` exactly on a dataset of 20 awkward grosses. The residue lands on the last percentage member.
  - **Fixed:** 0.5000 per stock unit × base 24 gives 12. In an inclusive group, the fixed amount comes off first; a gross below it gives `inclusive_amount_too_small`.
  - **Fixed before compound, inclusive** (added by D-25, shipped as fixture `tax-inclusive-fixed-before-compound`): a compound percentage after a fixed member also taxes the fixed amount, which is the `K` term of AC-11.6. Fixed 1.0000 × base 2, then compound 10 %, on gross 112.2000: `F = 2`, `S = 0.1`, `K = 0.2`, net `100.0000`, compound base `102.0000`, amount `10.2000`. Non-compound 5 % and 10 % on gross 115.0000 give net `100.0000` and no residue (AC-30.6). The revision 2 product-of-factors divisor fails both rows.
  - **Filters:** a `purchase`-only member is skipped in `sale` context; an inactive member is skipped; an empty group gives zero tax with `net = amount` (AC-9); a live member with no rate gives `no_effective_rate`.
  - **Order:** `sequence` drives compounding, not input order.
  - **Mutations:**
    - **T1:** a compound base excluding prior taxes. The compound rows go red;
    - **T2:** round `r/100` before multiplying. The rounding row goes red;
    - **T3:** inclusive as exclusive. The inclusive rows go red;
    - **T4:** drop the residue step. The sum-to-gross dataset goes red;
    - **T5:** ignore `applies_to`. The filter row goes red.
  - *(AC-9, AC-11.)*
- [x] **Step 8: `PriceLadder`.** A single 7.50 per stock unit, case factor 24.
  - **Unit-outer (§6.4's table):** a NULL row at `min_qty` 24 against a unit-matched row at `min_qty` 1, for 1 case (base 24), picks the **case row**.
  - **Unit-matched:** case row 179 gives 179.0000 as typed.
  - **Derived:** only a NULL row at 7.50 gives 180.0000 for a case.
  - **`min_qty` inner:** unit-matched rows at 1 and 48. 1 case gives the 1-row; 2 cases (base 48) give the 48-row. NULL rows at 1 and 24 behave the same way for singles.
  - **Each step wins in turn** (six rows): a customer list beats a group list beats a promotion beats a branch list beats the branch selling price beats the product selling price. Each later source is present in each row, which proves that later steps are never consulted.
  - **F-2:** step 5 and step 6 for a case give `× 24`.
  - **F-10:**
    - an inactive, soft-deleted-flagged, out-of-window or other-currency list is skipped;
    - two promotions are tried in priority order; a priority tie is broken by `code`;
    - a branch-scoped promotion is skipped at another branch;
    - an unassigned promotion applies everywhere;
    - two customer lists are tried in assignment-priority order;
    - a `promotion`-typed list assigned to a customer is not tried at step 1.
  - **F-11:** `discount_percent` 10 on 179 gives list 179, unit 161.1000.
  - **AC-17:** nothing at all gives `no_price_resolved`.
  - **Mutations:**
    - **P1:** `min_qty` outer. The unit-outer row goes red;
    - **P2:** the least qualifying `min_qty`. The 2-case row goes red;
    - **P3:** step 6 used as typed. The F-2 row goes red;
    - **P4:** ignore the window. The out-of-window row goes red;
    - **P5:** promotions ascending. The priority row goes red;
    - **P6:** fall through to the next step when a list has a NULL-row match. The derived row goes red.
  - *(AC-15 to AC-17, AC-16a; D-13, D-16, D-17.)*
- [x] **Step 9: `DiscountCalculator`, `LineCalculator`, bill.**
  - **Percentage:** 10 % of 179 gives 17.9000, capped at `max_discount` 15. **Fixed:** 200 on 179 gives 179 (never negative).
  - **AC-20 refusals,** each with the earlier reasons satisfied: unknown, scope mismatch (both directions), inactive, not yet started, expired (at `ends_at` + 1 ms; at exactly `ends_at` it is accepted), usage limit reached (`used_count = usage_limit`), `allow_discount = false`, `not_applicable` (and accepted by category, by brand, and with a null or empty `applies_to`), below `min_amount`, `buy_x_get_y`.
  - **AC-20's order is pinned:** a discount that is both `expired` and `not_applicable` reports `expired`; one that is both `discount_not_allowed` and `below_min_amount` reports `discount_not_allowed`.
  - **Line, F-1:**
    - 1 case at 179, 10 % discount, 15 % exclusive gives subtotal 179, discount 17.9, discounted 161.1, tax 24.165 rounded to 24.1650, total 185.2650;
    - **3 singles at 7.50** gives subtotal 22.5000, not ×base;
    - the inclusive variant gives `lineTotal = discounted`.
  - **Bill (F-13):** base = Σ discounted subtotals, 5 % and capped.
  - **Mutations:**
    - **D1:** multiply by `baseQuantity`. The case row goes red;
    - **D2:** tax before discount. The line row goes red;
    - **D3:** drop the fixed cap. The 200-on-179 row goes red;
    - **D4:** wall-clock time instead of `at`. The expired row goes red when its `at` is in the past;
    - **D5:** bill base from line totals. The bill row goes red.
  - *(AC-20, AC-22, AC-23; D-12, D-18, D-19.)*
- [x] **Step 10: the fixture set and its replay.**
  - At least 30 files covering AC-24's list: tax (exclusive and inclusive × compound and non-compound, fixed, empty group, no rate), price (unit-matched, derived, each of the six steps, F-2, F-10, F-11, no price), discount (percentage, fixed, capped, each refusal), line (three composed, including case-of-24 and inclusive), bill.
  - **`FixtureSetTest`:**
    - (a) the **lint**: every file parses, has exactly the allowed top-level keys, has no JSON number anywhere (a recursive walk), and has a unique `name`;
    - (b) the **replay**: every file in the directory, enumerated by glob, runs through the pure class its `kind` names and equals `expect` or `refusal`;
    - (c) **anti-vacuity**: the count is ≥ 30, and each `kind` appears.
  - **Red-first:**
    - a scratch fixture with `"price": 7.5` fails the lint;
    - a scratch fixture with a wrong `expect` fails the replay;
    - delete both.
  - **Catalog's `UnitFixtureReplayTest`** (F-15): about 8 snapshot cases (precision-first rounding, HALF_UP, factor 24, mass 0.001 chain) replayed through `SnapshotCalculator`, with the same lint.
  - **Gate: `composer check` (end of chunk B).**
  - *(AC-24, AC-24a; D-21.)*

### Chunk C: CRUD and the catalog edge

- [ ] **Step 10a: make the duplicate-`min_qty` refusal order-independent (K-9; AC-30.3, D-28).** Do this first in Chunk C; it is a pure-engine fix and touches no CRUD file.
  - As shipped, `PriceLadder::greatestQualifying()` refuses a duplicate only when it ties the best item **found so far**. Items at `min_qty` 1, 1, 5 with base 10 are refused; the same items in the order 5, 1, 1 are priced at the 5-row. Two inputs that differ only in row order therefore give different outcomes, which breaks D-29's premise (order is the engine's, never the caller's) and the TypeScript twin's parity.
  - **Fix:** for each unit key the ladder evaluates in a list (the line's unit; then the null unit, only when the unit-matched branch found nothing), refuse `invalid_amount` when any two items share a `minQty`, **whether or not they qualify** (compare with `BigDecimal::compareTo`, so `1` and `1.0000` are one value). Items of other units are not inspected, so one bad row cannot poison an unrelated line.
  - **Fixtures (new):** `price-duplicate-min-qty-below-the-break-is-refused` (items 5, 1, 1 in that order, base 10) and `price-duplicate-min-qty-above-the-base-is-refused` (items 1, 48, 48, base 24). Both are `refusal: invalid_amount`. The existing `price-duplicate-min-qty-is-refused` stays green.
  - **Mutation P7:** restore the shipped "tie with the current best" check. Both new fixtures go red. Revert.
  - **Order-insensitivity dataset in `PriceLadderTest`:** every `price` fixture replayed with its `lists`, each list's `items` and `assignments` reversed gives the same outcome or refusal. This is the pure-engine half of D-29's proof; step 16 carries the adapter half.
  - *(AC-30.3, AC-30.4; D-28, D-29.)*

- [ ] **Step 11: authorities, taxes and rates** (six slices).
  - **AC-4:** a blank or duplicate code (also by case); `payable_account_id` refused as **unknown** (F-17); `filing_frequency` enum checked.
  - **AC-5:** an unknown authority; the enum fields; the defaults; `fixed` + `is_compound` refused (F-9).
  - **AC-6:**
    - a negative rate;
    - `effective_to` before `effective_from`;
    - **overlap:** an open row then a second open row is refused; closed [2024, 2025) then [2025, open) is accepted; [2024-06, …) inside it is refused; the duplicate `(tax_id, effective_from)` key is backed by the index;
    - `CloseTaxRate` refuses changing `rate` or `effective_from` (unknown field) and refuses an `effective_to` that would create an overlap or invert the range.
  - **M-C1:** treat an open `effective_to` as closed. The open-open row goes red.
  - *(AC-4 to AC-6.)*
- [ ] **Step 12: tax groups and members, plus `DatabaseTaxGroupDirectory`.**
  - **AC-8:** create A default, then B default: exactly one default remains, and it is B. This holds through create and update.
  - **AC-9:** an unknown group or tax; the duplicate pair; a duplicate `sequence`; `sequence` outside 0 to 255 is refused (the `TINYINT UNSIGNED` column and `TaxMember`'s own range); removing the last member is accepted.
  - **AC-10 (as F-6):** deactivation is refused while an active, non-soft-deleted product references the group via either column, naming the count. It is accepted after that product is soft-deleted. Deactivating the default clears `is_default`.
  - **Directory:** `state()` for an active group, an inactive group and an unknown id; `defaultGroupId()` before and after clearing.
  - **M-C2:** skip default-clearing. The backstop refuses, and the test goes red.
  - **M-C3:** count only `tax_group_id`. The purchase-column row goes red.
  - *(AC-8 to AC-10.)*
- [ ] **Step 13: catalog AC-3.**
  - `CreateProduct`:
    - omitting `tax_group_id` gives the default;
    - an unknown or inactive group is refused, naming the field;
    - with no default, it refuses `no_default_tax_group` and writes nothing;
    - `purchase_tax_group_id` is validated when given and null when omitted.
  - `UpdateProduct`: the same checks; `tax_group_id: null` is refused (F-3's application `NOT NULL`).
  - **Id folding (K-10):** `TaxGroupDirectory`'s docblock says the id is compared exactly and the caller folds it to upper case first. Both handlers fold `tax_group_id` and `purchase_tax_group_id` with `strtoupper` before calling `state()` and before writing, and a test passes a lower-case id of an active group and asserts it is accepted and stored upper case. `DatabaseTaxGroupDirectory` (step 12) compares exactly and does not fold.
  - **Invariant:** after the catalog suite's product writes, a query finds no product with a null `tax_group_id`.
  - Existing `CreateProductTest`/`UpdateProductTest` expectations gain `tax_group_id` (predicted red, section 9).
  - **Seam:** with a fake `TaxGroupDirectory` bound, the handler resolves it, which proves the contract and not the table is used. The step 17 scan proves that catalog never names `tax_groups`.
  - *(AC-3.)*
- [ ] **Step 14: price lists, items and assignments** (seven slices).
  - **AC-12:** a missing currency (F-7); a bad type; the window ordering; the defaults.
  - **AC-13:**
    - an unknown list or product;
    - **`unit_id` unreachable**: a cross-dimension unit is refused `unresolvable_unit` through the real `UnitResolver`;
    - **control:** a case unit with a product conversion is accepted, and so is `unit_id` = the stock unit;
    - `min_qty` 0 or negative is refused; `discount_percent` 100.0001 is refused;
    - **duplicate by `unit_key`:** two NULL-unit rows at the same `min_qty` are refused, naming the existing row; `1` and `1.0000` are the same `min_qty` (compare numerically, never as strings). This is the write-time twin of the ladder's D-28 refusal.
  - **Codes:** a list code differing from an existing one only by case is refused as a duplicate (the `lower()` pre-check). That is what keeps D-29's bytewise tiebreak a total order: no two live codes can compare equal under either rule.
  - **AC-14 (F-8):** a bad scope type; an unknown or soft-deleted branch, group or customer is refused; the duplicate triple; `priority` defaults to 0.
  - **AC-18:** deleting a list with assignments is refused, naming the count; after unassigning, the delete is accepted, and its items remain.
  - **M-C4:** catch `UnitResolutionRefused` and accept. The cross-dimension row goes red.
  - *(AC-12 to AC-14, AC-18.)*
- [ ] **Step 15: discounts** (three slices).
  - **AC-19:**
    - each required field; percentage above 100 is refused and **exactly 100 is accepted** (D-27, AC-30.2, matching `DiscountRule`); negative money is refused;
    - **`applies_to` shape (K-11):** a JSON object whose only keys are `products`, `categories` and `brands`, each optional and, when present, a list of strings; anything else is refused `validation`, naming the field. Ids are **not** existence-checked (AC-19) and are stored as supplied. This is the shape `DiscountRule` takes; without it a stored value such as `{"products": "X"}` could not be loaded by the adapter;
    - `buy_x_get_y` without `applies_to.products` gives `missing_target`;
    - `applies_to` round-trips as given, and non-JSON is refused;
    - `used_count` on create or update is refused **whole** (the row is byte-identical, with no audit row);
    - an unknown `workflow_definition_id` is refused;
    - `sales_discount_account_id` is refused as unknown (F-17);
    - the window ordering.
  - **AC-21:** a soft delete leaves the row in place (`deleted_at` set), with no cascade.
  - **Gate: `composer check` (end of chunk C).**
  - *(AC-19, AC-21.)*

### Chunk D: the adapter, scans, engine, documents

- [ ] **Step 16: `DatabasePricingEngine`** (`DatabasePricingEngineTest`, SQLite stand-in).
  - It seeds the rows equivalent to five chosen fixtures (unit-matched case, derived case, promotion at a branch, composed inclusive line, bill) and asserts that `quoteLine`/`billDiscount` equal those fixtures' `expect` exactly. That proves the adapter adds nothing. Seed with `PricingSeed` and `Build::id`, and load the expectation through `FixtureReplay`, so the fixture file stays the single source.
  - **Refusals:** `unknown_product`; a soft-deleted product; `unknown_tax_group` (an unknown id given to `computeTax`, and a product whose `tax_group_id` is null, seeded by raw insert); `unknown_discount` (an unknown id, and a **soft-deleted** discount, K-6).
  - **Pass-through filters (Ruling 8):** a soft-deleted list, an inactive list and an inactive member tax are seeded and reach the engine, which skips them (the result equals the fixture that has them absent).
  - **Normalisation (K-5):** a list item seeded with a lower-case `unit_id`, and an assignment with a lower-case `scope_id`, still match; a list window seeded as `DATETIME(3)` `2025-06-15 09:30:00.000` is accepted at exactly that instant.
  - **The `UnitSnapshot` is used as given:** a snapshot obtained from the real `UnitResolver::snapshot()` for 2 cases prices at 2 × 179.
  - **`computeTax` uses the group it is given** (K-3): with a product whose `purchase_tax_group_id` differs from `tax_group_id`, `computeTax(TaxQuote(group = X, context = purchase))` computes group X and skips its `sale`-only members. The adapter never looks at a product here.
  - The container resolves `PricingEngine` to the adapter.
  - **Bytewise ties end to end (D-29, AC-30.4).** Seed four promotion lists, unassigned, equal `priority`, codes `a`, `B`, `9`, `10`, **inserted in that order** (so insertion and rowid order is the reverse of the expected order), each pricing the product differently. Bytewise order is `10` < `9` < `B` < `a`. Assert the winner is `10`; deactivate it, `9`; then `B`; then `a`. Under a case-insensitive collation `a` would beat `B`, and under PHP's `<=>` `9` would beat `10`, so each pair discriminates one hazard. Repeat with two customer lists on equal assignment and list priority, codes `a` and `B` (step 1's comparator is a separate code path). A member tie on `sequence` cannot be seeded (the unique key refuses it), so that tie stays proven by the pure dataset of step 7.
  - **M-D1 (revised):** (a) add `orderBy('code')` to the adapter's list query: no result changes, which shows the order is the engine's; (b) replace the engine's `strcmp` tie with `0` (stable sort keeps the fetch order): the bytewise-tie case goes red, which shows the test does not pass by insertion order luck; (c) replace `strcmp` with `strcasecmp`: the `B`/`a` row goes red. Revert each.
  - **Honest limit:** the SQLite stand-in's default collation is already bytewise, so this file cannot show a collation hazard by itself; it proves that nothing in the adapter relies on fetch order. The MySQL half is step 18.
  - *(AC-15 to AC-17, AC-22 end to end; AC-30.4.)*
- [ ] **Step 17: statics.**
  - **`QaPricingSourceRulesTest` (host),** code-only via `SourceRules::codeOnly`:
    - **AC-25 (a), the tokens:** no file under `packages/*/src`, `modules/*/src` or `modules/host/app` outside **`packages/pricing/src`** contains the six tokens; migrations are excluded by path. The CRUD slices write these tables, hence the package-wide scope (see the head of this plan). `tax_groups` is additionally banned in `packages/catalog/src` (AC-3);
    - **AC-25 (b), the arithmetic:** the multiplication-by-`rate`/`percent` pattern over the same directories outside **`packages/pricing/src/Engine/`**, so `Features/` is scanned too. It is **measured on the real tree before any pricing code lands** (run the scan at step 1 and record the result in the hand-back). A pre-existing hit is reported to `architect`, not exempted;
    - `packages/pricing/src/Engine/` imports nothing from `Illuminate\` except in `DatabasePricingEngine.php` (purity);
    - there is no `routes/`, `Route::`, `Cache::`, `dispatch(` or `HookGateway` in the package;
    - `Contracts\Pricing` imports nothing outside contracts and PHP core;
    - **the adapter does not order (D-29, Ruling 8):** `DatabasePricingEngine.php`, code-only, contains none of `orderBy(`, `orderByDesc(`, `latest(`, `oldest(`, `ORDER BY` (case-insensitive) or `->limit(`. It scans that one file by path, so it is named for what it scans;
    - **red-first** with planted files for each rule, then deleted.
  - **`QaCatalogSourceRulesTest`:** Ruling 10's exemption and its scope proof **already landed in Chunk B** (`fbf2aa1`, because the gate needed it as soon as `PriceLadder` existed). Step 17 only re-runs it; no edit.
  - Note for `dev` (from Chunk B): T-027's factor scan reports any multiplication next to a token containing `factor` anywhere in `packages/*/src`. The adapter passes `$snapshot->unitFactor` through and must not multiply near it.
  - *(AC-25, AC-26.)*
- [ ] **Step 18: `PricingSchemaEngineTest`** (database `flexipos_t8test_t029_tenant`, created and dropped by **exact name** through `EngineDatabase`, never with `LIKE`).
  - Under `ENGINE_HOLD`, only the refusal case runs.
  - Written for G-1:
    - `information_schema` decimals equal section 4;
    - the generated `unit_key`/`default_marker` keys refuse on MySQL;
    - `JsonValidity` on `applies_to`;
    - the contract file on a populated `products` table;
    - the rate-overlap lock (two connections; the second times out at `innodb_lock_wait_timeout = 1`);
    - migrating twice is a no-op;
    - **the D-29 collation case:** step 16's four-code promotion case (`a`, `B`, `9`, `10`) run through the real `DatabasePricingEngine` against the engine database, plus a control query asserting that the column's own `ORDER BY code` on that engine puts `a` before `B`. The control proves the hazard is real on this engine; the adapter case proves the adapter is immune to it.
  - *(The `[ENGINE]` halves of AC-6, AC-8, AC-13 and AC-30.4.)*
- [ ] **Step 19: documents.** Describe the old wording; never quote it (memory note).
  - `docs/database.md` §6.4 "As built (T-029)":
    - the deferred account columns;
    - the deferred tighten and why (F-3);
    - rate-as-percent;
    - the compound, fixed, `applies_to` and residue rules, with the inclusive back-out stated as AC-11.6's exact linear rule (D-25) and the fixed line's `base` (D-30); describe the superseded divisor, never quote it;
    - D-29: ties compare bytewise, and the adapter never orders;
    - F-10's selection rules and F-11;
    - the rounding steps;
    - the fixture format.
  - §6.3 "As built (T-027)": the two columns are now built, nullable, with keys.
  - `docs/architecture.md` §3: the Catalog row points pricing at `packages/pricing`.
  - Section 10's follow-ups go to `lead`.
- [ ] **Step 20: final gate.** `composer check` from `modules/host`, with counts and `test` seconds compared against step 0, then `composer test:fast` once.
  - *(AC-27.)*

## 7. Test strategy

- **Unit (pure, the bulk):** `Decimal`, `RateResolver`, `TaxCalculator`, `PriceLadder`, `DiscountCalculator`, `LineCalculator`, the contract shapes and enums. Plus **`FixtureSetTest`**, the single source of truth FP-432 replays, and catalog's `UnitFixtureReplayTest`.
- **Integration (SQLite stand-in):**
  - one file per slice (refusals write nothing; a success writes one audit row, compared whole);
  - `PricingTenantSchemaTest` and `ProductTaxGroupMigrationsTest`;
  - `DatabasePricingEngineTest`, which proves the adapter equals the fixtures.
- **Static:** `QaPricingSourceRulesTest`, `QaCatalogSourceRulesTest` (the exemption), `TenantDecimalPrecisionTest`, deptrac with the demo negative control, and the catalogue `is_destructive` assertion.
- **Engine:** `PricingSchemaEngineTest`, skipped under the hold.
- **Mutations:** S1, R1–R2, T1–T5, P1–P6, D1–D5 (all run in Chunks A and B), P7 (step 10a), M-C1 to M-C4, M-D1 (a) to (c).

| ACs | Proved by |
|---|---|
| 1, 2 | step 3 (the tighten is deferred, D-14; the contract adds FKs only) |
| 3 | step 13 (application-level `NOT NULL`, via `TaxGroupDirectory`) |
| 4–6 | steps 2 and 11 |
| 7 | steps 6 and 10 |
| 8–10 | steps 2 and 12 |
| 11 | steps 7 and 10 |
| 12–14, 18 | steps 2 and 14 |
| 15–17, 16a | steps 8, 10 and 16 |
| 19, 21 | steps 2 and 15 |
| 20, 22, 23 | steps 9, 10 and 16 |
| 24 | step 10 (pricing fixtures, lint and replay) |
| 24a | step 10 (catalog `UnitFixtureReplayTest`) |
| 25 | step 17 (two scopes, per the head of this plan), plus the `QaCatalogSourceRulesTest` exemption |
| 26 | steps 1 and 17 |
| 27 | step 20 |
| 28 | step 4 |
| 29 | steps 3 and 4 |
| 30.1, 30.2, 30.5, 30.6 | steps 6, 7, 9 and 10 (shipped in Chunk B); 30.2's write half in step 15 |
| 30.3 | step 10a (order-independent), plus step 14's write-time twin |
| 30.4 | steps 7, 8 and 10a (pure), 16 (adapter, SQLite), 17 (no-ordering scan), 18 (MySQL collation) |

## 8. Risks & rollback

1. **Spec drift (highest).** The revision is settled (`85dc7ab`). The residual risk is that `dev` reads the first draft (`0d840cf`), which is still in history. There, AC-22, AC-15 steps 5–6 and AC-1 each produce code that passes its own tests and charges or migrates wrongly. Mutations D1 (base-quantity subtotal), P3 (step 6 used as typed) and S1 (`->change()` in the contract file) exist specifically to catch that.
2. **Gate time.** The `test` step was 2408 s at T-027's close, and T-028 is adding about 220 cases. This task adds roughly 250 more, mostly pure datasets. Measure at each chunk; if a chunk adds more than 10 %, stop and report.
3. **Merge conflicts with T-028.** Both branches edit `deptrac.yaml`, `phpstan.neon.dist`, `providers.php`, host `composer.json`/`.lock`, `QaToolchainRegistryCoverageTest`, `TenantDecimalPrecisionTest`, `tests/Helpers.php`, `TenantBaselineBuildsTest`, `MigrationTreeDisciplineTest`, the catalogue JSON and `.env.example`. The migrations themselves cannot collide (`0028xx` against `0029xx`, and file-name order puts T-029 after T-028), but the catalogue must be **republished after merging**, never hand-merged.
4. **The deferred `NOT NULL`.** Until the backup facility ships, a raw SQL insert can write a product without a group. Mitigation: every application path is guarded and tested (step 13), and the follow-up row is explicit.
5. **The contract file blocks a lagging fleet.** This is operational, not a defect. The remedy is a two-run migration (section 4).
6. **SQLite rebuild of `products`** when the keys are added. It has a FULLTEXT index (MySQL-only), FKs and the `sku`/`plu_code` unique indexes. T-014 measured that the rebuild keeps rows, keys and generated columns. Step 3 re-asserts row counts and the `sku`/`plu_code` uniqueness after the rebuild.
7. **Direct reads of catalog columns** (Ruling 1). A rename in catalog breaks pricing silently at run time. `DatabasePricingEngineTest` exercises every column read, so such a rename turns this suite red.
8. **Ordering drift between the adapter and the engine (D-29).** SQLite's default collation is bytewise, so the SQLite stand-in cannot show a collation hazard; only step 18 can, and it runs under G-1 (skipped under `ENGINE_HOLD`). Mitigation: the adapter carries no ordering at all, which step 17 scans for by file and step 16's M-D1 (b) proves is not luck. Until G-1 runs, the MySQL half of AC-30.4 is written, not measured; `dev` and `qa` must say so rather than claim it.

**Rollback:** every file is new except section 9's edits. The migrations are forward-only and no tenant outside this branch has run them, so rollback is to abandon the branch.

## 9. Edits to existing files (the predicted-red list)

| File | Edit | Step |
|---|---|---|
| `modules/host/composer.json`, `composer.lock` | require and autoload-dev; the path package entry | 1 |
| `modules/host/deptrac.yaml`, `phpstan.neon.dist`, `bootstrap/providers.php` | section 5 | 1 |
| `tests/Feature/QaToolchainRegistryCoverageTest.php` | `'pricing'` in the literal | 1 |
| `database/catalogue/tenant_schema_versions.json` | republished | 2, 3 |
| `.env.example` | `APP_REQUIRED_SCHEMA_VERSION` 51 → 63 | 3 |
| `tests/Helpers.php` `tenantTreeTables()` | the nine names, under a T-029 comment | 2 |
| `tests/Feature/TenantBaselineBuildsTest.php` | the FK inventory gains 13 keys | 2, 3 |
| `tests/Feature/QaAccessCloseOutSweepsTest.php`, `QaAccessSchemaShapeTest.php` | a `$t029` list | 2 |
| `tests/Feature/MigrationTreeDisciplineTest.php` | table count +9 (its literal) | 2 |
| `SchemaCataloguePublishTest` | fixed by the republish | 2, 3 |
| `packages/catalog/tests/Schema/CatalogTenantSchemaTest.php` | `products` column list +2 | 3 |
| `tests/Engine/CatalogSchemaEngineTest.php` | `products` columns, if it lists them | 3 |
| `tests/Feature/TenantDecimalPrecisionTest.php` | the T-029 map | 4 |
| `packages/catalog/src/Features/CreateProduct/*`, `UpdateProduct/*`, `Exceptions/CatalogRefused.php`, `Support/CatalogRefusal.php` | AC-3 | 13 |
| `packages/catalog/tests/Features/CreateProduct/CreateProductTest.php`, `UpdateProduct/UpdateProductTest.php` | expectations gain `tax_group_id` | 13 |
| `tests/Feature/QaCatalogSourceRulesTest.php` | Ruling 10's exemption (`qa`) | done in Chunk B (step 8/10) |
| `tests/Feature/BranchForeignKeysContractRunTest.php` | two cases adjusted because migration 12 now tops the tree (accepted Chunk A deviation; `qa` re-verifies) | done in Chunk A (step 3) |
| `docs/database.md`, `docs/architecture.md` | step 19 | 19 |

Anything red that is not in this table means stop and report to `architect`.

## 10. Follow-ups for `lead` (backlog rows or notes, not built here)

- **New row: tighten `products.tax_group_id` to `NOT NULL`.** It is gated on the backup facility (`schema.backups.available`). It is a contract file with a null-count pre-check, and it is destructive under today's classifier (F-3).
- **New row (kernel):** `SourceRules::isDestructive` over-reports a nullability tighten against T-008 D-10's own wording ("a contract step that adds a NOT NULL is not destructive"). Owner: whoever next touches the runner.
- **FP-260 (Finance):** `tax_authorities.payable_account_id` (expand + backfill + contract to `NOT NULL`), `receivable_account_id` and `discounts.sales_discount_account_id` (expand + contract) (F-17).
- **FP-233 (Sales):**
  - call `PricingEngine::quoteLine` with the line's `UnitSnapshot`;
  - allocate `billDiscount` across lines and re-tax them;
  - increment `discounts.used_count` under a row lock;
  - call `WorkflowGate` for `requires_approval`/`approval_threshold` (D-8);
  - handle `price_mode = open_price` and `allow_price_override`, and enforce `min_selling_price` against the discounted unit price;
  - decide whether `customer_groups.discount_percent` (T-028) feeds the engine; §6.4 does not say.
- **The purchasing task:** choose `products.purchase_tax_group_id ?? tax_group_id` before calling `PricingEngine::computeTax` in `purchase` context; `TaxQuote` carries the group, not the product (K-3).
- **FP-233 / T-030 (Sales):** refunds stay unsigned lines (D-26); a signed line means revisiting D-26.
- **FP-432 (TS engine):** order every tie bytewise (D-29), implement the inclusive back-out from AC-11.6 alone (D-25), and replay `packages/pricing/fixtures/` and `packages/catalog/fixtures/units/`, with decimals as strings (F-15).
- **FP-237 (Sync):** the `tax_groups`, `taxes`, `tax_rates`, `price_lists`, `price_list_items`, `price_list_assignments` and `discounts` replica entity types.
- **An admin-UI/API task:** list and get slices for all nine tables.
- **When `company_profile` ships:** default `price_lists.currency` from it (F-7).
- **FP-331:** the ninth copy of the support helpers.

## Questions for the human

**Revision 3, for `ba` (spec wording; none blocks step 10a or Chunk C, because the plan's defaults are stated):**
1. **K-9 / AC-30.3:** state that the duplicate-`min_qty` refusal applies to any two items of the unit keys the ladder evaluates in a list, **whether or not they qualify against the base quantity**, so the outcome never depends on row order. Plan default: exactly that (step 10a).
2. **K-11 / AC-19:** confirm that validating `applies_to`'s **shape** (only `products`, `categories`, `brands`; each a list of strings) is compatible with "stored and returned as supplied, no existence validation". Plan default: validate the shape, never the ids.
3. **K-12:** state what pricing does with an inactive tax group passed to `computeTax`. Plan default: compute it normally.

Revision 2's answer stands for everything else: F-1 to F-18 were resolved by `ba` in `85dc7ab` (D-12 to D-24, AC-16a, AC-24a). The AC-25 scope clarification and the three stale decision lines at the head of this plan are informational. One point `lead` may want to raise with the product owner is **F-3**. It is a consequence of T-008 D-10, which the owner approved: `products.tax_group_id` stays nullable in the database until a backup facility exists, with the application enforcing it in the meantime.
