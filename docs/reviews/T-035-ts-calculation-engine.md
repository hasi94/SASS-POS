# Code review: T-035 (FP-432) The TypeScript calculation engine

Branch `ai/T-035-ts-calculation-engine`. Reviewer: `reviewer35`, read-only. Saved by the lead from the reviewer's report.

## Round 1 - tip `b2aebb9` - APPROVE, no Critical

The reviewer read every production file in `frontend/projects/shared/src/lib/pricing-engine/`, the four `testing/` helpers and the main specs, and compared them line by line with the PHP engine (`packages/pricing/src/Engine/*.php`, `packages/contracts/src/Pricing/*`, `packages/pricing/tests/Support/FixtureReplay.php`). It ran nothing. It tried to build inputs on which the two engines would differ and found none inside the input domain the fixtures and the PHP engine accept.

### Warnings

| ID | Finding | Disposition |
|---|---|---|
| W-1 | `index.ts` `export type * from './types'` plus `public-api.ts` `export *` puts about 30 generic names at the root of `@flexipos/shared` (`TaxType`, `ListType`, `DiscountType`, `PriceBasis`, `TaxLine`, `PricedLine`...). No collision today, but T-036/T-037 could define their own, and two `export *` of one name stop the library compiling (TS2308) | fix in the fix round: export only the five input types, the five result types and their nested inputs; keep the enum-like unions internal or prefix them |
| W-2 | `testing/fixture-files.ts` `locateRepoRoot` walks up to four parents and accepts the first directory holding both `packages/pricing/fixtures` and `frontend/angular.json`; in the worktree layout the fourth parent is the main checkout, so a worktree without the fixtures could silently replay another checkout's set, defeating AC-34 | fix: anchor on the checkout that holds `frontend/angular.json`, then require the fixtures there and throw if absent |
| W-3 | `decimal.ts:58` `const MAX = dec(MAX_AMOUNT)` is a top-level call and may be kept by the bundler; the README bundle check greps one string only (`inclusive_amount_too_small`, which lives in `refusal.ts`) so a retained `decimal.ts` would pass unseen | fix: make the constant a literal; extend the grep to a marker from each engine module |

### Suggestions
S-1 unary-plus scan rule misses `a * +b` and similar; S-2 ban `parseInt` outside the date helper; S-3 freeze `PRICING_REFUSALS` and the vocabularies at run time; S-4 derive the literal unions from the arrays, use or delete `PRICE_BASES`; S-5 label `'x'` should be `'discountPercent'` (`price-ladder.ts:108`); S-6 module-state scan rule is narrow (`const cache = new Map()`); S-7 `dec()` and `divideHalfUp()` throw a plain `Error`, the header says three thrown types; S-8 a missing required amount key is `invalid_amount` while a missing nullable key is `PricingInputError`; pin one; S-9 PHP's replay of the `discount` kind does no shape check on `subtotal`, so a future hand-written negative subtotal fixture would pass the lint and diverge (belongs in the PHP-side guard follow-up).

### Verified by reading
Rounding half-up away from zero for all four sign combinations; `divideToScale` and `text()` parity; order of checks in every operation matches PHP argument evaluation (PriceSource, PricedLine, BillDiscount, rate rows before the blank check); duplicate `effective_from` for surviving members only (T-029 D-43) and duplicate `min_qty` per unit key (D-31); tax ordering by raw `tax_id` byte order; inclusive residue on the last non-zero percentage member (D-34), the negative tiny-rate residue refused `out_of_range` (D-17); discounts refusal order, `buy_x_get_y` stays a refusal; no float anywhere; inputs never mutated; the fixture harness's controls start from a clean baseline and can fail.

### NOT VERIFIED
`npm test` results and the fixture count (QA runs them), the builds and `prettier`, whether the admin bundle drops the engine, whether ng-packagr includes `testing/*.ts` in the library build (`tsconfig.lib.json` includes `src/**/*.ts`), whether `process.getBuiltinModule` is reachable under the unit-test builder's jsdom, any T-036/T-037 code beyond a grep for colliding names, and any hand recomputation of fixture amounts.

## Fix round 1 - W-1, W-2, W-3 (developer `dev56`, commit `0b1c0a5`)
`npm test` 25 files / 1474 tests passed; `ng build pos`, `admin` and `shared` pass; the widened bundle grep prints nothing. S-3, S-5 and S-7 applied by the developer; the reviewer found S-7's comment change not actually present (see below).

## Round 2 - tip `0b1c0a5` - APPROVE, no Critical

Re-check of W-1, W-2 and W-3 by `reviewer35b`, reading only (no tests or builds run by the reviewer). All three fixed: the barrel exports an explicit allow-list of 9 values and 20 `Pricing`-prefixed types with no `export *`; `locateRepoRoot` anchors on the first ancestor holding `frontend/angular.json`, never walks past it and throws when the fixtures are missing there (the temp-layout test reproduces the original hazard); `MAX` is a literal equal to `99999999999999.9999`. The engine's behaviour is unchanged (no diff in `tax.ts`, `line.ts`, `discount.ts`, `shape.ts`, `byte-order.ts`, `types.ts`).

New Warnings, non-blocking:

| ID | Finding | Disposition |
|---|---|---|
| R2-W-1 | README says the bundle grep has one marker per engine module, but markers cover only `decimal.ts`, `refusal.ts`, `tax.ts` | follow-up: add a `rules.ts` marker (e.g. `unit_matched`) or reword to "per module with top-level state" |
| R2-W-2 | the S-3 `Object.freeze(...)` calls in `rules.ts` and `refusal.ts` run at module load | follow-up: mark `/* @__PURE__ */` |

Suggestions: R2-S-1 add a fourth barrel assertion against inline `export interface|type|const|function|class|enum`; R2-S-2 rename the circular allow-list test; R2-S-3 drop or retarget the circular MAX check; S-7 (`refusal.ts` header still says "three thrown types and no fourth" while `decimal.ts` throws a plain `Error` twice) not applied; empty-string `start` message in `fixture-files.ts` is misleading.

NOT VERIFIED by the reviewer: test and build results, and whether the bundler drops the top-level freeze calls (`sideEffects: false` with path-mapped sources).
