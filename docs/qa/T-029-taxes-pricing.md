# QA Report: T-029 (FP-231) Taxes, price lists and discounts

Branch `ai/T-029-taxes-pricing`, tip `51af736`, QA round 1.

## Verdict: PASS

0 defects. Two acceptance-criteria clauses had no shipped test that could fail; QA added tests for both
(listed under "Coverage gaps closed by QA"). Three mutants survived the shipped suite, two are equivalent
(no observable behaviour change) and one was a real coverage gap, now closed. The MySQL half of D-29 and the
other ENGINE_HOLD items are NOT VERIFIED (written, skipped), see the last section; they are not counted as defects.

## Test run summary

Gate, run by QA from a clean `APP_KEY` environment (`$env:APP_KEY` verified empty), nothing else racing it
(only unrelated `artisan serve` / FLEXIHMS php processes were present):

- Command: `cd modules/host && composer check` redirected to a log, `EXITCODE: 2` read from the log.
- `probes:clean`, `engine:clean`: ran. Lint (Pint `--test`): passed. PHPStan: `[OK] No errors`. Deptrac: 0 violations.
- Test step: **13 failed, 36 skipped, 4018 passed (25734 assertions)**, 941.86 s.
- The 13 failures, by name, are exactly the baseline set and nothing else:
  `QaModuleRegistryContractTest` 6, `QaModuleNameValidationTest` 3, `ModulesEnvParsingTest` 1,
  `QaVocabularyRenameSweepTest` 1 (the same two surviving places, `.claude/agent-memory/developer/project_t026_create_owner_verify_activate.md:43`
  and `frontend/package-lock.json:2371`, neither a T-029 file), `QaHealthProbeResilienceTest` 2.
- The gate ran BEFORE QA added its two test files. After adding them QA re-ran, individually and not as a full gate:
  Pint on `packages/pricing/tests/Engine` (passed), `composer stan` (`[OK] No errors`), and
  `pest tests/Feature/HostTestSuiteCollectionTest.php tests/Feature/QaPricingSourceRulesTest.php tests/Feature/QaVocabularyRenameSweepTest.php ../../packages/pricing/tests`
  = 653 passed, 1 failed (the baseline vocabulary sweep, still the same 2 places). The two new files add 19 tests, so
  the next full gate should read 13 failed / 36 skipped / 4037 passed.

## Hand-checked inclusive-tax cases (D-25 / AC-11.6 / AC-30.6)

Worked from `gross - F = net x (1 + S) + K`, then read against `TaxCalculator::inclusiveNet` and run.

| Case | Hand result | Code |
|---|---|---|
| non-compound 5 % and 10 %, gross 115 | S = 0.15, net 100.0000, amounts 5.0000 and 10.0000, no residue | matches (new test) |
| 10 / 1.15 (gross 10) | net 8.6957, 0.4348 + 0.8696 overshoots by 0.0001, last member 0.8695, total 1.3043 | matches (new test) |
| fixed 1.0000 x qty 2, then compound 10 %, gross 112.2 | F = 2, S = 0.1, K = 0.2, R = 110, net 100, compound base 102.0000, amount 10.2000 | matches (fixture `tax-inclusive-fixed-before-compound`) |
| non-compound 15 % + fixed 0.5 x 24, gross 179 | R = 167, 167 / 1.15 = 145.2174, VAT 21.7826, fixed 12, sum 179.0000 | matches (fixture `tax-inclusive-fixed-comes-off-first`) |
| fixed 0.5 x 24 = 12 on gross 10 | refused `inclusive_amount_too_small` | matches (fixture `tax-inclusive-fixed-exceeds-gross`) |
| fixed 2 then compound 10 %, gross 2.2 / 2.1 / 1.9999 | net 0.0000 with base 2, 0.2 / refused (R = -0.1) / refused | matches (new tests) |

## Acceptance criteria coverage

"Mut" = QA mutated production code and the named tests went red (restored byte for byte, `cmp` identical every
time). "Read" = QA read the test and its assertions and judged it discriminating but did not mutate that clause.
Test paths are under `packages/pricing/tests/` unless a package is named.

| AC | Test(s) | Discriminates |
|----|---------|---------------|
| AC-1 | `Schema/ProductTaxGroupMigrationsTest` (expand, backfill x3, contract refuses dirty data naming a COUNT, contract adds keys, catalogue trio non-destructive); `host Engine/PricingSchemaEngineTest` (MySQL, held) | Mut: contract gains a `->change()` red (M18b); catalogue flag flipped red in `SchemaCataloguePublishTest` (M17b) |
| AC-2 | same file (expand adds both, contract adds both keys in the one file, purchase column left null) | Read |
| AC-3 | `catalog Features/CreateProduct/CreateProductTaxGroupTest`, `UpdateProduct/UpdateProductTaxGroupTest`, `Taxes/DatabaseTaxGroupDirectoryTest`, `host Feature/QaPricingSourceRulesTest` (catalog never names `tax_groups`) | Mut: inactive accepted red (M11), no-default refusal removed red (M16). Update-null guard: equivalent, see notes |
| AC-4 | `Features/CreateTaxAuthority`, `UpdateTaxAuthority`, `Schema/PricingTenantSchemaTest` (exact columns, none of the account columns) | Read |
| AC-5 | `Features/CreateTax/CreateTaxTest`, `UpdateTax/UpdateTaxTest` | Mut: `fixed_cannot_compound` guard removed red (M19) |
| AC-6 | `Features/CreateTaxRate`, `CloseTaxRate`, schema unique key | Mut: overlap boundary `<` to `<=` red (M14) |
| AC-7 | `Engine/RateResolverTest` + 6 `tax-rate-*` / `tax-no-effective-rate` fixtures | Mut: `effective_to` made inclusive red (M8) |
| AC-8 | `Features/CreateTaxGroup`, `UpdateTaxGroup`, schema one-default backstop | Read |
| AC-9 | `Features/AddTaxGroupTax`, `RemoveTaxGroupTax`, `TaxCalculatorTest` (empty group), `tax-empty-group-is-exempt`, `tax-inclusive-empty-group-is-exempt` | Read |
| AC-10 | `Features/UpdateTaxGroup/UpdateTaxGroupTest`, `DatabaseTaxGroupDirectoryTest` | Mut: in-use guard removed red (M10) |
| AC-11 (0-7) | `Engine/TaxCalculatorTest`, 26 `tax-*` fixtures, `DatabasePricingEngineTest`, new `QaPricingEngineTest`, new `QaPricingAdapterTest` | Mut: superseded product-of-factors divisor red (M1), negative-remainder guard red (M2b), compound base ignoring earlier members red (M30). M2 is equivalent, see notes |
| AC-12 | `Features/CreatePriceList`, `UpdatePriceList` | Mut: lower-case currency accepted red (M20) |
| AC-13 | `Features/AddPriceListItem`, `UpdatePriceListItem`, `RemovePriceListItem`, schema `unit_key` sentinel | Read |
| AC-14 | `Features/AssignPriceList`, `UnassignPriceList` (all three scope types, soft-deleted target, wrong-table id) | Mut: soft-deleted target accepted red (M15) |
| AC-15 | `Engine/PriceLadderTest`, 37 `price-*` fixtures | Mut: steps 5 and 6 not derived red (M4a, M4b), factor dropped red (M15), currency filter dropped red (M27), promotion always unscoped red (M28) |
| AC-16 | `PriceLadderTest` ("unit test outer", "greatest qualifying"), `price-unit-outer-beats-lower-min-qty-null-row` and neighbours | Mut: unit-outer branch dropped, 47 red (M24) |
| AC-16a | `PriceLadderTest`, `price-item-discount-percent-*` (3 fixtures) | Mut: item discount_percent ignored red (M25) |
| AC-17 | `PriceLadderTest` refusals, `price-nothing-resolves`, `line-refuses-when-no-price-resolves` | Read. Only the PURE ladder can reach it, see FP-440 note |
| AC-18 | `Features/DeletePriceList/DeletePriceListTest`, `UpdatePriceList` | Mut: assignment guard removed red (M13) |
| AC-19 | `Features/CreateDiscount`, `UpdateDiscount` | Read (applies_to shape, `used_count` refused, account column refused, `buy_x_get_y` target) |
| AC-20 | `DiscountCalculatorTest` (peels one violation at a time through the whole refusal order), 31 `discount-*` fixtures including 7 `discount-order-*` | Mut: ends_at exclusive red (M7a), usage limit off by one red (M7b), allow_discount ignored red (M7e) |
| AC-21 | `Features/DeleteDiscount/DeleteDiscountTest` | Read |
| AC-22 | `Engine/LineCalculatorTest` (one case at 179 gives 179, three cases derived at 180 give 540, three singles), 12 `line-*` fixtures, `DatabasePricingEngineTest` | Mut: subtotal by `baseQuantity` red, 21 tests (M3); tax before discount red, 15 (M13); inclusive adds tax on top red (M14); fixed discount uncapped red (M7c); max_discount ignored red (M7d) |
| AC-23 | `DiscountCalculatorTest` bill cases, 8 `bill-*` fixtures, `LineCalculatorTest` D-19 | Read |
| AC-24 | `Engine/FixtureSetTest`: 114 fixtures in 5 kind directories, lint, replay of every file by glob, anti-vacuity (30+, every kind, unique names, both outcomes), 5 controls that the lint and replay can fail | Read; the controls are the discrimination |
| AC-24a | `catalog Units/UnitFixtureReplayTest` over 10 fixtures in `packages/catalog/fixtures/units/` | Read |
| AC-25 | `host Feature/QaPricingSourceRulesTest` (a), (b), controls that plant a violation, Illuminate-free engine, no routes/cache/dispatch | Read; green in the gate |
| AC-26 | gate: Pint, PHPStan, deptrac steps | n/a (tool output) |
| AC-27 | gate test step | n/a (see run summary) |
| AC-28 | `host Feature/TenantDecimalPrecisionTest` (extended), `Schema/PricingTenantSchemaTest` | Read |
| AC-29 | `Schema/ProductTaxGroupMigrationsTest` AC-29.1, .2, .3 plus control, `PricingTenantSchemaTest` (exact column lists; no `payable_account_id`, `receivable_account_id`, `sales_discount_account_id`) | Mut: contract file gains `->change()` red (M18b). QA also read all 12 migrations: the three names appear only in comments |
| AC-30.1 | `LineCalculatorTest` negative or malformed quantity, `DecimalTest`, `PricingShapesTest` | Mut: money shape accepting a minus sign, 9 red (M21) |
| AC-30.2 | `DiscountCalculatorTest`, `Features/CreateDiscount/CreateDiscountTest` (100 accepted, above refused) | Mut: 100 refused red (M23) |
| AC-30.3 | `PriceLadderTest` "refuses a duplicate min_qty in any order" (1,1,5 / 5,1,1 / 1,5,1 / non-qualifying / `1` vs `1.0000`), 3 `price-duplicate-min-qty-*` fixtures | Mut: step 10a reverted to compare against the best-so-far only, 7 red (M5). QA's own reversed input: the shipped `5,1,1` case at base 10 is refused on the shipped code and priced from the 5-row on the reverted code |
| AC-30.4 | `PriceLadderTest` bytewise + reversed-input fixtures, `DatabasePricingEngineTest` (4 promotion codes `a`,`B`,`9`,`10`, plus two customer lists), `QaPricingSourceRulesTest` D-29 | Mut: M-D1 (a), (b), (c) below. Tax-member half was NOT covered, see gaps (now QA-covered) |
| AC-30.5 | `tax-inclusive-fixed-comes-off-first`, `LineCalculatorTest` fixed tax on base quantity | Mut: fixed base set to the quantity, 7 red (M22) |
| AC-30.6 | none shipped that pins the net, see gaps; now `Engine/QaPricingEngineTest` | Mut: M1 reddens 10 tests, 9 of them the QA ones |
| AC-30.7 | `Features/CreateDiscount/CreateDiscountTest`, `UpdateDiscountTest` | Read |
| AC-30.8 | unknown id: `DatabasePricingEngineTest`. Inactive group: none shipped, see gaps; now `Engine/QaPricingAdapterTest` | Mut: adapter refusing an inactive group, 3 red (M9) |

## Mutation results (all restored, `cmp` identical after each)

Command shape: a Python driver replaced one exact string, ran `vendor/bin/pest` over `packages/pricing/tests`
(plus `tests/Feature/QaPricingSourceRulesTest.php`, or the catalog CreateProduct/UpdateProduct dirs for catalog mutants),
then restored the file from a backup and `cmp`-ed it.

M-D1, the three-part adapter-never-orders mutation (D-29 / Ruling 8):

| Part | Mutation | Result |
|---|---|---|
| (a) | `->orderBy('code')` added to the adapter's `price_lists` read | RED, 2: `DatabasePricingEngineTest` "never orders or limits a candidate set in SQL" and `QaPricingSourceRulesTest` "D-29: no file of the pricing read path orders or limits in SQL" |
| (b1) | `strcmp` tie returns 0 in the assignment comparator (steps 1, 2, 4) | RED, 3 (`DatabasePricingEngineTest` customer-list tie, two `PriceLadderTest`) |
| (b2) | `strcmp` tie returns 0 in the promotion comparator (step 3) | RED, 3 (`DatabasePricingEngineTest` promotion tie, `FixtureSetTest` replay, `PriceLadderTest`) |
| (b3) | `strcmp` tie returns 0 in the tax-member comparator | SURVIVED the shipped suite (645 passed). Real gap in AC-30.4's "tax members: of the tax id" clause. With the new `QaPricingEngineTest` tie test: RED, 1 |
| (c1) | `strcasecmp` for `strcmp`, assignment comparator | RED, 1 (the `a`/`B` customer-list test) |
| (c2) | `strcasecmp` for `strcmp`, promotion comparator | RED, 1 (the `a`/`B`/`9`/`10` promotion test) |

Under SQLite the (a) mutation is caught only by the query-log and source-scan tests, not by an end-to-end order
test, because SQLite's default collation is already bytewise (the dev's own header says so). That is the honest
limit; the MySQL end-to-end half is held.

Other mutants, all RED unless stated: M1 inclusive divisor as product of factors (10), M2b negative-remainder guard
(2), M3 subtotal by `baseQuantity` (21), M4a/M4b steps 5/6 not derived (5, 7), M5 step 10a reverted (7),
M7a/b/c/d/e discount edges (2, 5, 4, 5, 4), M8 rate end day (2), M9 inactive-group refusal (3, QA tests),
M10 deactivation guard (3), M11 inactive group accepted by catalog (2), M13 tax before discount (15),
M14 inclusive adds tax (3), M15 factor dropped on derivation (16), M16 no-default refusal (1),
M17b catalogue flag (2), M18b contract `->change()` (1), M19 fixed may compound (2), M20 currency (2),
M21 negative money (9), M22 fixed base is quantity (7), M23 discount 100 (1), M24 unit-outer dropped (47),
M25 item discount_percent ignored (6), M27 currency filter (2), M28 promotion scope (1), M30 compound base (13),
plus M6a/b/c above and the delete-price-list guard (1), rate-overlap boundary (5), assign soft-deleted target (1).

Survivors:

- **M2** (remove the `fixedTotal > gross` refusal): all 645 pass. EQUIVALENT: the next check
  (`remaining = gross - fixedTotal - constant < 0`) refuses the same case with the same reason; only the message text
  differs. Redundant defensive code, not a gap.
- **M12** (remove `UpdateProductHandler`'s explicit `tax_group_id === null` guard): all 30 pass. EQUIVALENT:
  `Identifier::fold(null, ...)` right below refuses the same input, and the test asserts the refusal and a byte-identical row.
- **M6b3**: a real gap, closed below.

## Coverage gaps closed by QA (test files added, no production change)

1. `packages/pricing/tests/Engine/QaPricingEngineTest.php` (15 tests). AC-30.6 had no shipped test pinning the
   NET of two non-compound percentages: the shipped property test proves `net + total = gross`, which the superseded
   product-of-factors divisor also satisfies because the residue absorbs the difference; only 1 fixture reddened under
   that wording. These cases state hand-derived nets (115 gives 100, 230 gives 200, 10 gives 8.6957, 5 % then compound 10 %
   on 115.5 gives 100, fixed equal to the gross, one step short). Also the AC-30.4 tax-id tie (M6b3).
2. `packages/pricing/tests/Engine/QaPricingAdapterTest.php` (4 tests). AC-30.8's inactive half: `computeTax` on an inactive
   exclusive group, an inactive inclusive group, `quoteLine` on a product whose group was deactivated, and the unknown-id control.

## Chunk A deviation, re-verified

`modules/host/tests/Feature/BranchForeignKeysContractRunTest.php`, diff against the pre-T-029 file: one new helper
`bfkRunT029V()` (finds T-029's contract version by name), one case renamed "two runs" to "three runs" with its second stop
moved to `bfkRunT029V() - 1` and a third run to the top, one case "three runs" to "four runs" with one more stop. Every
original assertion is kept (seeded rows unchanged, the three branch keys present after the key step, the version reached);
the only added assertion is the third run's success and top version. Minimal, and it still proves what it proved: the
planner rule that a contract step must run alone. The other edited tests (`IdempotencyKeysSchemaTest`,
`MigrationTreeDisciplineTest` 51 to 63 / 49 to 58 tables, `QaAccessCloseOutSweepsTest`, `QaAccessSchemaShapeTest`,
`QaToolchainRegistryCoverageTest`, `CatalogTenantSchemaTest`, `AuditCatalogueTest`) are additive "T-029's tables/module"
extensions in the same style as T-028's.

## Other things the lead asked about

- **D-14:** `products.tax_group_id` stays nullable, the contract migration only adds two foreign keys (no `->change()`, 12 files checked),
  the committed catalogue marks all three phases `is_destructive: false` (and a flipped flag is caught by `SchemaCataloguePublishTest`).
  Application layer: `CreateProduct` writes the default group or refuses `no_default_tax_group`; it also refuses an explicit null, which is
  stricter than AC-3 and tested. `UpdateProduct` refuses an explicit null.
- **D-17 / AC-29:** none of `payable_account_id`, `receivable_account_id`, `sales_discount_account_id` is built; confirmed by
  `PricingTenantSchemaTest` exact-column assertions and by reading every migration (comments only).
- **FP-440:** `products.selling_price` is `NOT NULL DEFAULT 0`, so the adapter always supplies a step-6 price and a product with no
  price set quotes at `0.0000` rather than `no_price_resolved`. Nothing in the spec promises otherwise: AC-17 words the null case as "a
  data-integrity condition, since `selling_price` is a required column". It does sit uneasily with the NFR "never a silent zero";
  AC-17 is provable only against the pure ladder. Not a defect to fail on, filed as FP-440.
- **Lead-visible wrinkle, not a defect:** AC-6 accepts `effective_to == effective_from` (spec says "must not be before"), which makes a
  zero-width `[from, to)` row that can never resolve. Harmless.

## Defects

None.

## Regression risks / notes

- The pure engine is the only place a tie, a price or a tax is decided; the two QA files and `FixtureSetTest` are the guard.
  FP-432 (TypeScript twin) must reproduce D-25's linear back-out and the bytewise tie-break on tax ids; the new
  `QaPricingEngineTest` cases are cheap to port as extra fixtures.
- The SQLite stand-in cannot expose a collation hazard; D-29's real protection is "the adapter never orders" (query-log and
  source-scan tests, both mutation-verified) and the pure engine's bytewise sort.
- Gate time is 941.86 s warm; the limit in the manifest leaves ratio 8.28.

## NOT VERIFIED under ENGINE_HOLD (written, skipped, not counted as defects)

- `modules/host/tests/Engine/PricingSchemaEngineTest.php`, all six cases: the nine tables on MySQL 8.4 with the documented decimal shapes;
  the generated `unit_key` / `default_marker` keys refusing a second row and `applies_to` JSON validity; the contract file adding both foreign keys
  on a populated `products` table; the `tax_rates` overlap check holding the parent `taxes` row (a second session waits and times out; `lockForUpdate`
  is a no-op on SQLite); **the MySQL half of D-29, "the real adapter orders four equal-priority promotions bytewise on this engine, which sorts them otherwise"**.
- Therefore also unverified: any real-collation difference between the adapter's read order and the engine's bytewise order, and the concurrency
  behaviour of the rate-overlap write path.

---
Run 1: PASS (0 defects; 2 coverage gaps closed by QA tests).

---

# Run 2 (review round 1 fix round, commit 5d466d2)

## Verdict: PASS (0 defects, 2 minor notes)

## Per-finding verification (own mutations, each restored and checked with `cmp`/`git diff`)

| Finding | Evidence | Discriminates? |
|---|---|---|
| R-1 residue skips a zero-rate member (D-34) | Hand-computed: 15 % + 0 %, gross 9.9900: net 9.99/1.15 = 8.6870, VAT 9.99 - 8.6870 = 1.3030, levy 0.0000. Gross 10.0000: net 8.6957, VAT 1.3043. Reversed order same. All-zero: net 9.9900, total 0. Three members 15/2/0 at 9.9900: net 9.99/1.17 = 8.5385; VAT 1.2808, NBT 0.1708 forward, residue -0.0001 lands on NBT = 0.1707; total 1.4515. All match the fixtures (typed by hand, not read back). QaPricingEngineTest and QaPricingAdapterTest still green. | M1 (type only, the old rule): 7 red. M2 (FIRST non-zero): 6 red. Yes. |
| W-1 lock order (CloseTaxRate: taxes then tax_rates; assertNoOverlap locking) | Code reads tax_id before the transaction, then locks `taxes`, then `tax_rates` (same order as CreateTaxRate). | Drop `lockForUpdate` in assertNoOverlap: L2 red. Plain read as first statement inside the transaction: L1 red. Plain `Lookup::any` of `taxes`: L1 red + CloseTaxRate test red. Drop the `taxes` lock in UpdateTax: L1 red. Yes. See note N-1. |
| W-2 directory lock (`bool $lock = false`) | Catalog `TaxGroups::assertUsable` and `resolve` pass `lock: true` for `state()` and `defaultGroupId()`. Only implementers are `DatabaseTaxGroupDirectory` and three anonymous test doubles, all updated; no other caller. `default_marker` is `storedAs('CASE WHEN is_default THEN 1 END')`, so it is set for every default row. | Drop `lock: true` on `state`: L4 + 2 catalog tests red. On `defaultGroupId`: L4 + 1 catalog test red. Yes. See note N-2. |
| W-3 AC-5a freeze of type / is_compound | UpdateTaxTest: closed/open/future rate, same-value resend accepted, no-rate change allowed, frozen refusal reported before fixed_cannot_compound. | Disable the check: 6+ red. Yes. |
| W-4 malformed `applies_to` (D-38/40/41) | 11 stored shapes (JSON null, `[]`, nested list, non-string entry, unknown key, bare string/number, string/object value) refused `invalid_amount`; NULL and `{}` still mean everything; bill path covered. | Remove the object check: 5 red. Removing only `array_is_list` survives, an equivalent mutant (an object-decoded value is never an assoc array, `! is_array` already refuses it). |
| W-5 duplicate effective_from (D-36/43) | RateResolverTest both row orders; new QA test: duplicates on an inactive member and on a deleted member are ignored (group still prices 115 -> 100/15), on an active member refused in either order. | Disable the check: 4 red. Yes. |
| S-1 BigDecimal 100 comparison | Code reads correctly. | Reverting to `version_compare` survives: equivalent for the normalised 4-decimal shapes `Shape` already enforces (not a defect). |
| S-2 / AC-6a already-closed rate | CloseTaxRateTest. | Disable the check: 3 red. Yes. |
| S-4 error text names the tax | Test asserts the new message. | Not mutated separately. |
| S-7 Uniqueness docblock only | Comment-only change, no behaviour. | n/a |

## Gate (composer check, no APP_KEY, log read from file, `EXITCODE:2` as expected)
- pint passed, phpstan passed, deptrac 0 violations, engine step held (ENGINE_HOLD).
- Tests: 13 failed / 41 skipped / 4110 passed (4109 baseline + my 1 new test). Failed set by name equals baseline: QaModuleRegistryContractTest 6, QaModuleNameValidationTest 3, QaHealthProbeResilienceTest 2, ModulesEnvParsingTest 1, QaVocabularyRenameSweepTest 1. 1066.8 s, ratio 7.31.
- Added test: `QaPricingEngineTest` "ignores duplicate effective_from rows on an inactive or deleted member and refuses them on an active one".

## Notes (not defects)
- N-1: rule L1 only requires the FIRST statement in the transaction to be a locking read; it does not check WHICH table. Locking `tax_rates` before `taxes` in CloseTaxRate (the deadlock order) survives the whole suite. Only the held engine case can prove order. Suggest L1 pin the first locked table to `taxes`.
- N-2: making `DatabaseTaxGroupDirectory` ignore `$lock` (`if (false)`) survives the directory unit tests; SQLite compiles `sharedLock` to nothing. Rule L3 is a source scan and was run in that batch but only checks the source text shape, so the guard is the scan plus the held engine proof.

## NOT VERIFIED under ENGINE_HOLD
Run-time lock proof (PricingSchemaEngineTest E-1 to E-5, row locks invisible on SQLite), MySQL half of D-29, contract migration on a populated table, real shared-lock vs `UpdateTaxGroup` conflict, real `default_marker` unique behaviour.

Run 2: PASS (round 1 PASS -> fix round PASS).
