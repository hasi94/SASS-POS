# Code Review: T-029 (FP-231) Taxes, price lists and discounts

Branch `ai/T-029-taxes-pricing`, tip `093f75f`. Reviewer: `reviewer4`.

## Round 1 - Verdict: CHANGES REQUESTED

One Critical, in the inclusive-tax residue rule. The code follows AC-11.6 exactly, but the rule itself fails when the last percentage member's resolved rate is zero. There are 5 Warnings; the concurrency class from T-027 and T-028 shows up again in two places.

Most of the engine is right, checked against the code and not the summaries:
- The D-25 linear back-out is correct. In `inclusiveNet`, S and K use the values from before each member, K covers only the fixed amounts before a compound member, and the net is rounded once, half up.
- Rounding is exact: `percentOf` multiplies exactly and rounds once. The subtotal multiplies by `UnitSnapshot::$quantity`, never the base quantity (D-12). Steps 5 and 6 derive by the factor (D-13).
- Discounts: the refusal order matches AC-20, every window check runs against the document instant, `ends_at` is inclusive, `used_count >= usage_limit` is the boundary, `min_amount` is judged on the subtotal.
- No floats, and no rounding mode other than `HalfUp`/`Unnecessary`, anywhere in `Engine/`.
- The adapter never orders: no ORDER BY, no LIMIT, no `first()` except through a key.

What the reviewer ran: `vendor/bin/pest ../../packages/pricing/tests tests/Feature/QaPricingSourceRulesTest.php` from `modules/host`: 647 passed (2256 assertions), 32.35 s. `composer check` was not re-run (QA ran it twice). The tree was left clean.

## Critical (must fix)

**R-1 | `packages/pricing/src/Engine/TaxCalculator.php:191-199` (and `lastPercentage`, `:332-343`)**

The inclusive residue goes to the last percentage member in `sequence` whatever that member's resolved rate is. If that rate is `0.0000`:
- a negative residue makes that member's amount negative; `Decimal::text()` then refuses it as `out_of_range`, so a valid sale is refused;
- a positive residue puts tax on a 0 % line, so the breakdown shows `amount != base x rate`, which is wrong on a tax return.

A 0 % last member is ordinary configuration: AC-6 allows a rate of 0, and the common case is a levy suspended by a new 0.0000 rate row from a date, or a zero-rated member in a combined group. By hand, for a 15 % VAT at sequence 1 and a levy at 0 % at sequence 2, inclusive:
- gross 9.9900: S = 0.15, net = round(9.99 / 1.15) = 8.6870, VAT = round(1.30305) = 1.3031, levy = 0. The residue is -0.0001, the levy becomes -0.0001, and the quote is refused `out_of_range`.
- gross 10.0000: net 8.6957, VAT 1.3044, residue -0.0001, refused as well.

With residues of about +-0.0001 on a large share of price points, the till cannot sell much of the range under that group. No fixture or test has a zero rate. FP-432 would copy the defect from AC-11.6.

Fix: give the residue to the last percentage member whose resolved rate is not zero (`$entry['member']->type === TaxType::Percentage && ! $entry['rate']->isZero()`). Such a member always exists when the residue is not zero: if every percentage rate is zero then S = 0 and K = 0, so `net = gross - F` exactly and the residue is zero. `ba` logs the rule change as an amendment of AC-11.6 and notes it for FP-432. Add fixtures `tax-inclusive-residue-skips-a-zero-rate-member` (15 % then 0 %, gross 9.9900, expecting VAT 1.3030 and levy 0.0000), the gross 10.0000 case, and the control ordering 0 % then 15 %.

## Warnings (should fix)

**W-1 | `Features/CloseTaxRate/CloseTaxRateHandler.php:149-160`, with `Support/RateRanges.php:491`. The overlap check can run on a stale snapshot** (T-028 W-1's class). The first statement in the transaction is a plain read, `Lookup::any(tax_rates)`, which under InnoDB REPEATABLE READ fixes the snapshot; only then is the parent `taxes` row locked, and `assertNoOverlap` reads the sibling rates with a plain `get()` on that old snapshot. Race: A is [2024-01-01, 2025-01-01). T1 is `CreateTaxRate` B [2025-01-01, open) and holds the lock on `taxes`. T2 is `CloseTaxRate(A, 2026-01-01)`: it reads A, then waits on the lock. T1 commits. T2's overlap check cannot see B and passes, leaving A [2024, 2026) overlapping B, which breaks AC-6. `CreateTaxRate` is safe because its first statement is the locking read. Fix: lock before any plain read (read the rate with `Lookup::any(..., lock: true)`, lock the parent, re-read `$before`), make `RateRanges::assertNoOverlap` a locking read, and add the two-connection case to `PricingSchemaEngineTest`.

**W-2 | catalog `CreateProductHandler.php:146,149`, `UpdateProductHandler.php:327,336`, `Support/TaxGroups.php:26,48`, and `DatabaseTaxGroupDirectory.php:396,412`. Catalog validates the tax group with an unlocked read** (T-028 W-4's class). `UpdateTaxGroup` locks the group, counts live products, deactivates it and clears the default. Meanwhile `CreateProduct`/`UpdateProduct` reads `state()`/`defaultGroupId()` without a lock and sees Active; its insert's FK check waits on `UpdateTaxGroup`'s lock and then succeeds because the row exists and is only inactive. Result: a live product on an inactive group (AC-10's guard bypassed), or a just-retired default written. Fix: give the directory a locking read (`state(string $id, bool $lock = false)`, `defaultGroupId(bool $lock = false)`, doing `->sharedLock()` or `lockForUpdate()`), call it with `lock: true` inside catalog's transaction (both packages resolve the same `tenant` connection, so the lock joins catalog's transaction).

**W-3 | `Support/TaxFields.php:18`, `Features/UpdateTax/UpdateTaxHandler.php:264-277`. `type` and `is_compound` can change on a tax that already has rates.** `CloseTaxRate` makes `rate` and `effective_from` immutable because documents resolved against them, but `UpdateTax` lets `type` flip from `percentage` to `fixed`, silently turning every historic `15.0000` (15 %) into 15.0000 per stock unit, and lets `is_compound` flip. Both change how every past document re-taxes and what every future quote charges; no test covers either. Fix: refuse a change to `type` or `is_compound` while any `tax_rates` row exists for the tax ("create a new tax instead"), or remove both from `UPDATE_FIELDS`; add a test.

**W-4 | `Engine/DatabasePricingEngine.php:299-300` and `:330-341`. A malformed stored `applies_to` widens the discount to every product.** JSON that does not decode to an array becomes `[]`; a list value that is not an array becomes `[]`; non-string entries are dropped; `DiscountRule::targetsEverything()` then reads three empty lists as "applies to everything". So `{"products": "X"}` or `{"products": [123]}` turns a discount meant for one product into a tenant-wide one. The write path validates the shape (D-32), but raw SQL, a future sync or an import can still write a bad value, and the adapter fails open. Fix: when the stored value is not the D-32 shape, refuse instead of widening (`invalid_amount` is the nearest reason in the closed list, or add a named reason); add an adapter test seeding a malformed row by raw insert.

**W-5 | `Engine/RateResolver.php:178`. Duplicate `effective_from` values depend on row order.** The docblock says the result does not depend on row order, but with two qualifying rows on the same `effectiveFrom` (for example [2024-01-01, 2024-06-01) and [2024-01-01, open) on 2024-03-01) the strict `>` keeps whichever came first. The unique `(tax_id, effective_from)` key stops real data, but D-29 and D-31 ruled that the pure engine's result must never depend on order; `PriceLadder` already refuses its equivalent duplicate, and FP-432 would otherwise have to copy PHP's iteration order. Fix: refuse `invalid_amount` when any two rows share `effectiveFrom`, whether or not they qualify (the D-31 shape); add fixture `tax-rate-duplicate-effective-from-is-refused`.

## Suggestions (optional)

- S-1 | `Engine/LadderItem.php:418`, `Engine/DiscountRule.php:229`: `version_compare($value, '100.0000', '>')` is correct only because `Shape` canonicalises to four fraction digits; use `BigDecimal::compareTo`, as `AddPriceListItemHandler` and `DiscountFields` already do.
- S-2 | `CloseTaxRateHandler.php:138-162`: an already-closed rate can have `effective_to` moved earlier or later, which re-resolves past documents. Needs a `ba` ruling: refuse when `effective_to` is already set, or accept it as a deliberate correction path.
- S-3 | `UpdatePriceListItemHandler.php:192` edits items of a soft-deleted list (harmless, unreachable); `AssignPriceListHandler.php:66` checks the target's existence without a lock, so a target soft-deleted at that moment can leave a dead assignment. Low impact.
- S-4 | `Support/Uniqueness.php:389-394`: the docblock says the `code` unique index is `ascii_bin`; it is the connection default `utf8mb4_unicode_ci`, which ignores case and accents. Behaviour is fine (the backstop is caught and refused as a duplicate); correct the comment.
- S-5 | `QaCatalogSourceRulesTest` (`QA_CATALOG_FACTOR_MULTIPLICATION_EXEMPT`): the exemption covers the whole `PriceLadder.php` file; a stricter proof would assert the file holds exactly one factor multiplication, inside `derive()`.
- S-6 | `DatabasePricingEngine.php:155`: step 5 uses `product_branches.selling_price` even when `is_available = false`; outside this spec, worth a line in the FP-233 follow-ups.
- S-7 | `DatabasePricingEngine.php:266`: a link to a tax that does not exist is refused as `unknown_tax_group`, which names the wrong thing; unreachable behind the FK.

## Done well

- Engine arithmetic is exact and matches D-25: the S, K and Fb recurrence re-derived from `gross - F = net x (1+S) + K` matches `inclusiveNet` line for line; it rounds once, refuses twice by name, and works forward from the net.
- Every money input goes through one `Shape` site: amounts are strings with integer-digit caps (14/4, 5/4, 12/6) matching the column widths, and dates and instants use `checkdate`, so 2026-02-30 is refused; the CRUD `Fields` helpers carry the same caps.
- D-29 / Ruling 8 holds in the code, and the scans cover SQL ordering, PHP sorting and unkeyed `first()`/`value()`, each with a planted-violation control and a scope proof.
- The step 10a duplicate-`min_qty` fix is a function of the item set (compares with `compareTo`, inspects only the unit keys it checks, has an order-insensitivity dataset).
- Locking in most slices is right: most handlers' first statement is the locking read, so later plain reads see the latest committed rows. `CloseTaxRate` is the exception (W-1).
- Migrations match D-14 and F-17: expand, backfill and contract, all catalogued `is_destructive: false`; none of the three Finance account columns exists.
- The catalog change is narrow: ids are folded before the directory call (K-10), and the explicit-null refusal on both handlers enforces F-3's application-level NOT NULL.
- Test quality is high: the fixture lint forbids JSON numbers, the replay walks every file with an anti-vacuity count, and controls prove the lint and the replay can fail.
