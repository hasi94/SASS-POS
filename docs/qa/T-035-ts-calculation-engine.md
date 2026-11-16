# QA Report: T-035 (FP-432) TypeScript calculation engine

Branch `ai/T-035-ts-calculation-engine`, tip `b2aebb9`, plus QA's new uncommitted test files.

## Verdict: PASS

0 defects. 45 of 45 acceptance criteria have at least one passing automated test (AC-31 is verified on the engine side only, see below). 4 survivors of 74 mutations, all equivalent mutants. QA found 6 gaps in the shipped suite (mutations that survived) and closed each with a new test.

## Acceptance criteria coverage

Spec files are in `frontend/projects/shared/src/lib/pricing-engine/`. "QA" marks a test written in this run. `M..` are mutation ids from the table below that each AC's tests turned red.

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `npm test` ran 23 files, 1465 tests with no PHP, Composer, database or network | PASS |
| AC-2 | source-scan.spec (`import-outside-engine` rule, per-file scan, controls) | PASS |
| AC-3 | line.spec "the public API"; `ng build shared` builds; `dist/shared` exports `calculateTax`/`priceLine` | PASS |
| AC-4 | line.spec "exposes the five operations"; the five fixture kinds replay through them | PASS |
| AC-5 | shape.spec NOT_AMOUNTS (number, bigint, null, NaN, undefined); tax/price/discount specs; amount-fields.spec (QA, 132 cases, every amount field of all five operations); M73, M74 | PASS |
| AC-6 | shape.spec money NOT_AMOUNTS (empty, blank, spaces, trailing newline, sign, exponent, 5th decimal, `.5`, `5.`, comma, 15 digits); M58 | PASS |
| AC-7 | shape.spec rate and factor (`0.999999`, `0`, 13 integer digits, 7 fraction digits); M59, M60 | PASS |
| AC-8 | decimal.spec "7.5 and 7.5000"; shape.spec canonicalises; M75 | PASS |
| AC-9 | decimal.spec "precision beyond a JavaScript number"; differential.spec extremes at `99999999999999.9999` (QA) | PASS |
| AC-10 | decimal.spec "range" (exact max accepted, one unit above refused at every named step); differential.spec (QA); M03, M08 | PASS |
| AC-11 | decimal.spec `text` (zero is `0.0000`, negative is `out_of_range`); M05 | PASS |
| AC-12 | source-scan.spec controls for every banned token; M68, M69, M73 | PASS |
| AC-13 | decimal.spec; fixtures `discount-percentage-rounds-half-up`, `price-item-discount-percent-rounds-once`; differential.spec (QA, 182 tax, 70 discount, 80 line vs an independent oracle); M01, M07 | PASS |
| AC-14 | decimal.spec `percentOf`; M07 | PASS |
| AC-15 | decimal.spec `divideHalfUp` and `toScaleHalfUp` positive and negative ties; M01, M02 | PASS |
| AC-16 | tax.spec, fixtures `tax-inclusive-*`, inclusive-edges.spec (QA: fixed == gross, gross - F - K negative, exactly zero, one above); differential.spec; M09, M10, M11, M17 | PASS |
| AC-17 | tax.spec (filter, order, rate windows, fixed, empty group); fixtures; M12, M14, M15, M19, M20, M21 | PASS |
| AC-18 | tax.spec duplicate `effective_from` (qualifying or not; deleted/inactive member allowed; `no_effective_rate`); M16 | PASS |
| AC-19 | price-ladder.spec, `price-*` fixtures; differential.spec byte-order through the ladder (QA); M22 to M35 | PASS |
| AC-20 | price-ladder.spec duplicate `min_qty` (any row order, other units ignored); M29 | PASS |
| AC-21 | price-ladder.spec `no_price_resolved`; fixture `price-nothing-resolves` | PASS |
| AC-22 | discount.spec refusal order, boundaries; `discount-*` fixtures; discount-targets.spec (QA, single-kind targets and OR semantics); M36 to M47 | PASS |
| AC-23 | discount.spec `calculateBillDiscount`; `bill-*` fixtures; M46 | PASS |
| AC-24 | line.spec; `line-*` fixtures; differential.spec line vectors (QA); M48 to M52 | PASS |
| AC-25 | source-scan (`Date`, `performance.now`); determinism.spec AC-43; M70 | PASS |
| AC-26 | refusal.spec "exactly the seventeen" literal list (compared to PHP `PricingRefusal` cases by reading) | PASS |
| AC-27 | specs read `.reason` or `failureOf` only; refusal.spec | PASS |
| AC-28 | shape.spec, tax/price/discount/line specs "programmer error"; calendar-edges.spec (QA: century leap years, hour/minute/second bounds, non-UTC instants); M62 to M65 | PASS |
| AC-29 | rules.spec; per-operation specs; fixture-set.spec AC-38; M53 to M57 | PASS |
| AC-30 | discount.spec "known and unknown discount types"; M44 | PASS |
| AC-31 | Engine side only: AC-29 tests prove it throws and never falls back. The till's reaction is FP-236 | PASS (engine); till part NOT VERIFIABLE here |
| AC-32 | fixture-set.spec reads `packages/pricing/fixtures` in place; `git ls-files frontend` holds no fixture copy | PASS |
| AC-33 | fixture-set.spec replays every file (120 files) found by glob | PASS |
| AC-34 | fixture-set.spec anti-vacuity controls (floor 30, missing kind, no refusal or result fixture, duplicate name, absent directory) | PASS |
| AC-35 | fixture-set.spec lint controls (JSON number, scale, extra key, both or neither of expect/refusal) | PASS |
| AC-36 | fixture-set.spec AC-36a, b, c | PASS |
| AC-37 | `git diff 81a9169..b2aebb9 -- packages modules print-agent` is empty; no fixture edited | PASS |
| AC-38 | fixture-set.spec "unrecognised rule value surfaces as UnsupportedRuleError" | PASS |
| AC-39 | determinism.spec reverses and rotates every list of every fixture; `ORDER_DEPENDENT` is empty | PASS |
| AC-40 | byte-order.spec; differential.spec (QA) drives U+FF5E against U+1F600 through promotion and step-1 ordering, both row orders; M22, M23, M67 | PASS |
| AC-41 | byte-order.spec int64 extremes; tax.spec and price-ladder.spec "9 before 10"; shape.spec int64 bounds; M14, M64 | PASS |
| AC-42 | source-scan banned-token list plus controls; M69, M70, M71 | PASS |
| AC-43 | determinism.spec stubs `fetch`, `XMLHttpRequest`, `Date` with throwing functions and replays every fixture | PASS |
| AC-44 | determinism.spec deep-frozen inputs, repeat calls; M71 | PASS |
| AC-45 | determinism.spec `assertPlain` (no Promise, no thenable) | PASS |

## Test run summary

Run from `frontend/` in the T-035 worktree.

| Command | Result |
|---|---|
| `npm test` | 23 files passed, 1465 tests passed, 0 failed. Shipped suite alone: 18 files, 964 tests. QA added 5 files, 501 tests |
| `npx ng build pos` | complete, rc 0 |
| `npx ng build admin` | complete, rc 0 |
| `npx ng build shared` | Built Angular Package, rc 0 |
| `npx tsc -p projects/shared/tsconfig.spec.json --noEmit` | rc 0 |
| `npx prettier --check projects/shared/src/lib/pricing-engine` | all files use Prettier code style, including QA's files |
| `grep -l "inclusive_amount_too_small" dist/admin/browser/*.js dist/pos/browser/*.js` | no output (rc 1): no engine code in the admin or pos bundles |

PHP, Composer and Pest were not run, as instructed. The PHP gate slot belongs to another task.

### Independent parity check against PHP (read line by line)

Compared `Decimal`, `Shape`, `TaxCalculator`, `RateResolver`, `PriceLadder`, `DiscountCalculator`, `DiscountRule`, `LineCalculator`, `LineInput` and the `Ladder*`, `TaxMember`, `RateRow`, `PricedLine`, `TaxLine`, `BillDiscount`, `PriceSource` constructors against the TS files. No divergence found in:

- Half-up rounding away from zero, including negative ties (`divideHalfUp` adjusts by sign).
- `text()` (unrounded value is `invalid_amount`; range then sign check, so a negative is `out_of_range`).
- Range check placement (`round`, `percentOf`, `text`).
- Order of checks and constructor argument evaluation:
  - Replay build order: list assignments and items are built before the list's own currency and instant checks.
  - Refusals and thrown errors keep PHP's order between build and compute phases.
  - Ladder source id checked before the outcome is computed.
  - `PricedLine` id checks run after every `text()` call.
  - `TaxLine` runs the id check after the rate, base and amount `text()` calls.
- ULID checked and upper-cased only where PHP checks it: tax line id, price source list id, bill discount id, priced line discount and tax group ids. Raw (not upper-cased) ids are compared for ordering.
- Refusal vocabulary: 17 values, same order as PHP.
- UTF-8 byte order via code points (`strcmp` equivalent). Valid Unicode is equal under both orders.
- D-45: the TS twin does not range-check the residue-added amount, as PHP does not; it is refused later in `text()`. Test exists.
- The pattern anchors: JS `$` without the `m` flag equals PCRE `/D`.

Differences that are deliberate and recorded by the architect (Q-3, Q-5 and AC-5, not defects): `UnsupportedRuleError` before any other check, a stricter `subtotal` shape check on the `discount` kind, and a non-string amount refused `invalid_amount` where PHP's replay throws a harness exception.

### Differential test (QA)

`differential.spec.ts` and `testing/differential-vectors.ts` hold 342 vectors computed by a separate exact-fraction re-implementation of the PHP algorithm. The TS engine matched all 342:

- 192 tax vectors: 0 to 4 members, fixed, compound, inclusive and exclusive, tie amounts, near-max amounts. 20 refusal vectors across the three files cover `inclusive_amount_too_small`, `out_of_range` and `below_min_amount`.
- 70 item-discount vectors.
- 80 priced-line vectors (step-6 price derived by a factor, discount, tax).

This is TS-only evidence (D-9), not a PHP fixture. The oracle was written from the PHP source, not from the TS.

### Mutation testing

Run in a throwaway clone (`git clone` of the worktree at `b2aebb9`, `node_modules` junctioned), so the reviewer's tree was never touched. Each mutation was a single edit to one engine source file, run through `ng test shared`, then restored. The main worktree has no production-source diff.

74 mutations. 64 were killed by the shipped suite. 10 survived it. QA's new tests then killed 6 of those 10. The other 4 are equivalent:

| Id | Mutation | Shipped suite | With QA tests |
|----|----------|---------------|---------------|
| M04 | `checkRange` ignores the sign | survived | survived, equivalent (a negative is refused by the sign check in `text()` with the same reason) |
| M10 | refuse a fixed total equal to the gross | survived | killed (inclusive-edges) |
| M11 | drop the `gross - F - K < 0` refusal | survived | killed (inclusive-edges) |
| M13 | tax id tie-break by JS `<` | survived | survived, equivalent (ids are ASCII alphanumeric; `<` equals byte order, and a non-ASCII id fails the ULID check) |
| M18 | drop the range check on the inclusive net | survived | survived, equivalent (net never exceeds the gross) |
| M22 | promotion code order by JS `<` | survived | killed (differential: U+FF5E vs U+1F600) |
| M23 | step-1 code order by JS `<` | survived | killed (differential) |
| M43 | "targets everything" ignores brands | survived | killed (discount-targets) |
| M62 | leap-year rule without the century rule | survived | killed (calendar-edges) |
| M66 | year limit 32768 | survived | survived, equivalent (the date pattern is `\d{4}`, so a year above 9999 cannot exist) |

The other 64 mutations (all killed by the shipped suite) include:

- Rounding: tie direction, negative ties.
- Range: max boundary.
- Tax: compound base, fixed base, `is_active` filter, `applies_to`, rate window end, duplicate `effective_from`, sequence as string, residue on a zero-rate member, inclusive constant.
- Ladder: factor on steps 5 and 6, window ends, currency, duplicate `min_qty`, greatest `min_qty`, assignment priority, unassigned promotions, item discount percentage.
- Discount: refusal order swaps, `ends_at`, usage limit, `min_amount`, cap, fixed cap, `buy_x_get_y`, a percentage above 100.
- Line: `base_quantity` vs `quantity`, tax context, tax base, inclusive total.
- Unknown-rule pre-pass: one mutation per vocabulary.
- Shape and ban-scan: digit limits, factor floor, ULID case, instant seconds, int64 bound, currency case, UTF-16 byte order, a float token, `Math.`, `Date`, module state, `Number(`, a number accepted as money, zero padding.

## Defects

None.

## Regression risks / notes

- Fixture dependency: `fixture-files.ts` reaches `packages/pricing/fixtures` through `process.getBuiltinModule` and `process.cwd()` (walks up to 4 parents looking for both trees). It needs Node 22.3 or later (Node 24.15 here) and fails loudly otherwise. A repository layout change breaks it by design, not silently.
- The engine test count is large (1465). The determinism and replay specs are per-fixture, so they grow with the PHP fixture set. Run time is about 6 s of test time.
- QA's vector file (`testing/differential-vectors.ts`, 41 KB) is generated once by a throwaway script (not committed). To extend it, rewrite the oracle from the PHP source, do not copy the TS engine's output.
- NOT VERIFIABLE here: (1) PHP execution (not run, as instructed; parity rests on the code comparison, the 120 shared fixtures and the independent oracle); (2) the till's reaction to `UnsupportedRuleError` (AC-31, FP-236); (3) the "tree-shakeable exports" non-functional requirement beyond the bundle grep (the token `inclusive_amount_too_small` is absent from the admin and pos bundles, which proves no engine code was pulled in today, not that it would tree-shake once imported).
- Tooling note: `main` is stale relative to this branch (`git diff main...HEAD` shows 1858 files). Scope checks used `81a9169..b2aebb9`.
- Files QA created (uncommitted): `amount-fields.spec.ts`, `differential.spec.ts`, `inclusive-edges.spec.ts`, `discount-targets.spec.ts`, `calendar-edges.spec.ts`, `testing/differential-vectors.ts`, all in `frontend/projects/shared/src/lib/pricing-engine/`.

Run 1: PASS (0 defects; 6 suite gaps closed with new tests).
