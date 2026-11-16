# Plan: T-035 The TypeScript half of the shared tax, price and discount calculation engine

Spec: `docs/specs/T-035-ts-calculation-engine.md` (AC-1 to AC-45). Decisions: `docs/decisions/T-035-ts-calculation-engine.md` (D-1 to D-17). Branch `ai/T-035-ts-calculation-engine`, planned at `50c7597`. Gate: `cd frontend && npm test` plus the builds in section "Test strategy". Nothing under `packages/` is edited.

## Approach summary

A framework-free TypeScript port of `packages/pricing/src/Engine` and the parts of `packages/contracts/src/Pricing` it uses (`Shape`, the output DTOs, `PricingRefusal`), placed in its own directory `frontend/projects/shared/src/lib/pricing-engine/`. It is exported from `@flexipos/shared` by **one added line** in `public-api.ts`.

- **Wire-shaped I/O.** Each of the five operations takes exactly the fixture `input` object of its kind (snake_case, every decimal and integer a string, `null` for absent) and returns exactly the PHP `toArray()` shape. The ladder `step` is the only number: a literal `1 | 2 | 3 | 4 | 5 | 6`, as PHP emits it. So the replay harness passes `fixture.input` straight in, and the till receives the same shapes the server's JSON uses. snake_case for wire DTOs is already the convention in `auth.models.ts`.
- **Arithmetic.** An internal `Dec` value (`bigint` unscaled plus a small integer scale) gives exact add, subtract and multiply, and one integer half-away-from-zero division primitive. Every Brick call in the PHP has a one-to-one twin: `toScale(4, HalfUp)`, `dividedBy(x, 4, HalfUp)`, `withPointMovedLeft(2)`, `toScale(4, Unnecessary)`. Strings appear at every boundary. No dependency is added.
- **Line-for-line port.** Each PHP class becomes one TS module. The port keeps the PHP check order: the constructor checks run in the order `FixtureReplay` builds the objects (section 5). Each pure static method is a module function with the same name.
- **Three thrown types and no fourth.** `PricingRefused` carries `.reason`, one of exactly 17 values. `PricingInputError` is the programmer error, the twin of PHP's `InvalidArgumentException`. `UnsupportedRuleError` carries `.field` and `.value`. None extends another, so a `catch` aimed at one class cannot catch another.
- **Fixtures read in place.** The test-only helper `testing/fixture-files.ts` gets Node's `fs` at run time through `globalThis.process.getBuiltinModule('node:fs')` (Node 24, both locally and in CI). It never `import`s `fs`, so neither the engine nor the bundler ever sees a Node import. When it cannot find the directory, it throws and lists every path it tried (section 3).
- **Two proof instruments, each with controls.** A source scan covers the engine files and lists them. A runtime run of every fixture replaces `fetch`, `XMLHttpRequest` and `Date` with throwing stubs.

## Alternatives rejected

- **A decimal library (decimal.js, big.js).** D-2 forbids it. A new supply-chain dependency would also bring its own rounding configuration that can be set wrong.
- **`import fs from 'node:fs'` in a spec.** The `@angular/build:unit-test` builder bundles specs with esbuild before Vitest runs them. Nobody can verify offline whether that bundle resolves Node built-ins, and it may fail with "Could not resolve". `getBuiltinModule` is a property access, so it is immune to bundling.
- **Vitest `globalSetup` with `provide`/`inject`, through `runnerConfig` in `angular.json`.** Kept only as the fallback (step 0). It edits `angular.json`, which T-036 and T-037 also touch, and it relies on builder option merging that cannot be verified without `node_modules`.
- **Copying or generating the fixtures under `frontend/`.** D-1 and AC-32 forbid it.
- **camelCase engine inputs with a mapping layer** (what PHP's `FixtureReplay` does). That would be a second hand-written mapper that can drift. Taking the wire shape directly removes it.
- **Directory name `lib/pricing/`.** T-036 (admin screens for taxes and price lists) may well want `pricing` for its API clients. `pricing-engine` cannot be mistaken for those.
- **Returning refusals as a result union.** PHP throws, and AC-45 allows only "returns or throws". A union would also double the API.

## Files to change / create

All paths are under `frontend/projects/shared/src/`.

**Existing shared files touched (complete list):**
- `public-api.ts`: add one line, `export * from './lib/pricing-engine/index';`, and extend the header comment by one sentence. Nothing else in shared changes.
- `frontend/README.md`: the "Not built yet" line now names the engine as built, and a short "Pricing engine" section is added (what it is, where it lives, how its test reaches `packages/pricing/fixtures`). Documentation only.

`angular.json`, `tsconfig*.json`, `package.json` and `package-lock.json` are **not** touched, except under the step-0 fallback.

**New: engine source.** These files are flat in `lib/pricing-engine/`. They are the only files the source scan covers. Each imports only `./` siblings.
- `lib/pricing-engine/refusal.ts`: `PRICING_REFUSALS` (a 17-value `as const` tuple in PHP enum order), `type PricingRefusal`, and the classes `PricingRefused` (`reason`), `PricingInputError` and `UnsupportedRuleError` (`field`, `value`).
- `lib/pricing-engine/decimal.ts`: `Dec`, `dec(canonical)`, `add`, `sub`, `mul`, `cmp`, `isZero`, `isNegative`, `movePointLeft`, `divideHalfUp(a, b, scale)`, `roundStep` (the `Decimal::round` twin), `percentOf`, `text`, and `MAX_AMOUNT`. These are internal and not re-exported from `index.ts`.
- `lib/pricing-engine/shape.ts`: twins of `Shape::money`, `rate`, `factor`, `ulid` (upper-cases its result), `date`, `instant` and `currency`. Also the replay field guards: `str`, `strOrNull` (`undefined` is a `PricingInputError`, `null` is absent), `bool`, `int` (bigint), `intOrNull`, `list` and `obj`.
- `lib/pricing-engine/byte-order.ts`: `compareBytes(a, b)` (the `strcmp` twin, by code point), `compareInt(a, b)` (bigint), and `cmpDesc` helpers.
- `lib/pricing-engine/rules.ts`: the closed vocabularies `TAX_TYPES`, `TAX_APPLIES`, `TAX_CONTEXTS`, `LIST_TYPES`, `SCOPE_TYPES`, `DISCOUNT_SCOPES`, `DISCOUNT_TYPES`, `PRICE_BASES`, plus `assertKnownRules(kind, input)` (section 4, R-3).
- `lib/pricing-engine/types.ts`: the input and output interfaces (section "Data / API changes").
- `lib/pricing-engine/tax.ts`: `resolveRate` (RateResolver), `calculateTax` (TaxCalculator), and an internal `buildMembers` (the TaxMember and RateRow constructors).
- `lib/pricing-engine/price-ladder.ts`: `resolvePrice` (PriceLadder) and an internal `buildLadder` (the LadderInput, LadderList, LadderAssignment and LadderItem constructors).
- `lib/pricing-engine/discount.ts`: `calculateItemDiscount` (forLine) and `calculateBillDiscount` (forBill), plus internal `buildRules` and `buildProduct`.
- `lib/pricing-engine/line.ts`: `priceLine` (LineCalculator, including the LineInput constructor).
- `lib/pricing-engine/index.ts`: the barrel. It exports only the five operations, the three error classes, `PRICING_REFUSALS`, `PricingRefusal` and the types from `types.ts`.

**New: engine unit specs.** These sit beside the source and are excluded from the scan by their `.spec.ts` suffix: `refusal.spec.ts`, `decimal.spec.ts`, `shape.spec.ts`, `byte-order.spec.ts`, `rules.spec.ts`, `tax.spec.ts`, `price-ladder.spec.ts`, `discount.spec.ts`, `line.spec.ts`.

**New: test harness.** The scan does not cover the `testing/` subdirectory. This mirrors the existing `lib/plugins/testing/` precedent. Nothing here is exported from `public-api.ts`.
- `lib/pricing-engine/testing/fixture-files.ts`: `nodeFs()` (getBuiltinModule plus a loud failure), `locateRepoRoot()`, `loadFixtureSet(root?)` and `readEngineSources(root?)`.
- `lib/pricing-engine/testing/fixture-lint.ts`: `lintFixture(path, decoded)`, a line-for-line twin of `FixtureReplay::lint`. Also `assertFixtureSet(files)` for the AC-34 anti-vacuity checks.
- `lib/pricing-engine/testing/fixture-replay.ts`: `REPLAY` (kind to operation), `replay(case)` (catches only `PricingRefused` and rethrows everything else), `stringify` (number to string, as in PHP's `stringify`), `expected(case)`, and `compareCase(case)` (returns a list of differences and never throws on a mismatch).
- `lib/pricing-engine/testing/source-scan.ts`: `stripCommentsAndStrings(src)`, `scanSource(name, src)` (returns its violations) and `BANNED` (one named regex per token).

**New: harness specs.**
- `lib/pricing-engine/fixture-set.spec.ts`: AC-1, AC-32 to AC-38.
- `lib/pricing-engine/source-scan.spec.ts`: AC-2, AC-12, AC-25, AC-42.
- `lib/pricing-engine/determinism.spec.ts`: AC-39, AC-43, AC-44, AC-45.

## Data / API changes

No server, schema, migration, config or dependency change. The new public TypeScript surface of `@flexipos/shared` is as follows. Names follow the PHP twins. They were checked against the T-036 and T-037 specs (`LinePricer`, `PricingEngine`, `SalesRefusalCode` are deliberately not used).

```ts
// operations: synchronous, pure; each throws PricingRefused | PricingInputError | UnsupportedRuleError
calculateTax(input: TaxInput): TaxBreakdown                       // kind "tax"
resolvePrice(input: PriceInput): PriceOutcome                     // kind "price"
calculateItemDiscount(input: ItemDiscountInput): ItemDiscountResult // kind "discount" -> { discount_amount }
calculateBillDiscount(input: BillDiscountInput): BillDiscountResult // kind "bill" -> { discount_id, base, amount }
priceLine(input: LineInput): PricedLine                           // kind "line"
```

The input interfaces are the fixture `input` shapes exactly:
- `TaxInput`: `{members, amount, base_quantity, date, context, is_inclusive}`
- `PriceInput`: `{unit_id, unit_factor, base_quantity, at, currency, customer_id, customer_group_id, branch_id, lists[], branch_selling_price, selling_price}`
- `ItemDiscountInput`: `{discount_id, discounts[], at, product, subtotal}`
- `BillDiscountInput`: `{discount_id, discounts[], at, discounted_subtotals[]}`
- `LineInput`: `{ladder, quantity, product, tax_group: {id, is_inclusive, members}, document_date, discount_id, discounts[]}`

The nested types are `TaxMemberInput`, `RateRowInput`, `PriceListInput`, `PriceListAssignmentInput`, `PriceListItemInput`, `DiscountRuleInput` and `ProductFactsInput`. Enum-valued fields are typed as their literal unions. At run time every field is treated as `unknown` and checked, because AC-5 needs a `number` passed through a cast to be refused.

The output interfaces are PHP's `toArray()` shapes:
- `TaxBreakdown{is_inclusive, net, lines: TaxLine[], total}` and `TaxLine{tax_id, code, tax_code, type, is_compound, rate, base, amount}`.
- `PriceOutcome{list_price, discount_percent, unit_price, source: PriceSource}` and `PriceSource{step, price_list_id, basis}`. Note that the `price` kind's key is **`discount_percent`**, unlike the line (Q-1).
- `PricedLine{list_price, list_discount_percent, unit_price, source, subtotal, discount_amount, discount_id, discounted_subtotal, tax_group_id, tax, line_total}`.

All outputs are plain objects with no class instances, so they can be structured-cloned to a worker.

## 1. Architect rulings (settling what `ba` left open)

- **R-1 Location and naming.** The engine lives in `frontend/projects/shared/src/lib/pricing-engine/`. It is self-contained: every import is a `./` sibling, and the scan enforces this. Exports are flat and named for tree-shaking. `sideEffects: false` is already set in `projects/shared/package.json`, so `admin`, which never imports the engine, ships none of it (NFR, checked in step 13).
- **R-2 Port order.** For each operation, the TS first runs the rule pre-pass (R-3). Next it runs a **build** phase that mirrors PHP object construction in `FixtureReplay`'s order: arguments left to right, nested constructors first, then the constructor body. Last it runs a **compute** phase that mirrors the static methods. Section 5 lists the order. Consequence one: a malformed rate on a *deleted* tax member is still refused `invalid_amount`, because `RateRow` validates at construction. A *duplicate* `effective_from` on a deleted member is not refused (AC-18), because that check runs only in `resolve` for surviving members. Consequence two: every discount rule in `discounts[]` is built, and so validated, not only the one selected.
- **R-3 Unknown rule values.** `assertKnownRules` walks the whole input before the build phase. Any string enum field outside its set throws `UnsupportedRuleError(field, value)`, including in deleted or inactive rows. PHP's `::from` would throw a `ValueError` on all of them too. A non-string in an enum field is skipped by the pre-pass and becomes a `PricingInputError` in the build phase. The pre-pass covers these fields: `members[].type`, `members[].applies_to`, `context` (tax kind only; `line` always uses sale), `lists[].type`, `assignments[].scope_type`, `discounts[].type` and `discounts[].scope`. `buy_x_get_y` is known, so it passes the pre-pass and is refused `unsupported_discount_type` at its place in the order (AC-30).
- **R-4 Ids.** Ids are checked exactly where PHP checks them (Q-2):
  - The ULID check with upper-casing applies to output ids only: `tax_id` of each *applied* member (TaxLine), the winning `price_list_id` (PriceSource), the line's `tax_group_id` and `discount_id` (PricedLine), and the bill's `discount_id` (BillDiscount).
  - The blank checks are `tax_id`, `code`, `scope_id`, product `id`, discount `id` and `tax_group.id`.
  - Ordering by `tax_id` uses the **un-uppercased** input value, as `strcmp($a->taxId, …)` does.
- **R-5 Integers.** Integers must match `^-?\d+$` and fit in signed 64-bit, otherwise `PricingInputError` (AC-41; PHP's `(int)` would saturate silently). Further limits: `sequence` 0 to 255; `used_count` at least 0; `usage_limit` null or at least 0. Priorities may be negative, as a PHP int can be. All are compared as `bigint`. The fixtures were measured: 120 files, every integer string between `0` and `20` in `priority`, `sequence`, `used_count`, `usage_limit` and `step`, and zero JSON numbers. They are far inside the 64-bit signed range.
- **R-6 Nullable keys.** A nullable key that is missing (`undefined`) is a `PricingInputError`, the same as the replay's "use null". A non-boolean where a boolean is required is a `PricingInputError` (Q-4).
- **R-7 `discount` kind `subtotal`.** It is shape-checked as money (`invalid_amount`). PHP's replay passes `BigDecimal::of` unchecked, but on the line path the subtotal is always engine-made and canonical. This is stricter only for inputs PHP never receives (Q-3).
- **R-8 Byte order.** `compareBytes` walks both strings by code point (`for…of` plus `codePointAt`). For valid Unicode, UTF-8 byte order equals code-point order, so this is PHP `strcmp` without allocating a `TextEncoder` buffer. A lone surrogate sorts at its code point (WTF-8 order). The server never sends one.
- **R-9 Sorting.** Sorting copies the array first (`[...rows].sort(cmp)`), so an input frozen by AC-44 still works. JS sort is stable, like PHP 8's `usort`. That is deliberate: equal keys keep input order on both sides.

## 2. Decimal primitives (D-2, D-3)

- `Dec = { readonly u: bigint; readonly s: number }`. The scale `s` is a small non-negative integer (4 for money and rate, 6 for factor and fraction, larger for products and the compound slope). A scale is never a money value, so the `number` type is safe here.
- `dec('12.3400')` parses a canonical shape string (digits, optional `.digits`) into `u` and `s`. Only strings that `shape.ts` has already accepted, or engine constants, reach it.
- `add`, `sub` and `cmp` align scales by multiplying the smaller-scale value by `10n ** BigInt(d)`. `mul` returns `{u: a.u*b.u, s: a.s+b.s}`. All three are exact.
- `divideHalfUp(n: bigint, d: bigint): bigint` is the **only** rounding site. It computes `q = n / d` and `r = n % d`, both truncating toward zero. If `2n * abs(r) >= abs(d)`, it adds `1n` when `n` and `d` have the same sign and `-1n` otherwise. If `d === 0n`, it throws a plain `Error` (an engine defect, unreachable).
- `toScaleHalfUp(x, 4)`: for `s > 4` it divides `x.u` by `10^(s-4)` through `divideHalfUp`; for `s <= 4` it multiplies up.
- `divideToScale(a, b, 4)`: with `e = 4 + b.s - a.s`, the numerator is `a.u * 10^e` (or the denominator is multiplied by `10^-e` when `e < 0`), and the result goes through `divideHalfUp`.
- `roundStep(x)` is `toScaleHalfUp(x, 4)` followed by the range check (`Decimal::round`). `percentOf(a, p)` is `divideToScale(mul(a, p), 100, 4)` followed by the range check. `movePointLeft(x, 2)` is `{u, s: s + 2}`.
- The range check is `abs(x) > 99999999999999.9999`, which throws `out_of_range`.
- `text(x)`:
  1. If `s > 4` and `x.u % 10^(s-4) !== 0n`, refuse `invalid_amount` (the twin of `RoundingMode::Unnecessary`).
  2. Rescale to 4 and run the range check.
  3. A negative value is refused `out_of_range`.
  4. Format as `intPart + '.' + frac.padStart(4, '0')`.
  
  A `bigint` has no `-0`, so `-0.0000` cannot arise (AC-11).

## 3. Fixture access mechanism (D-1, AC-32, AC-34)

`testing/fixture-files.ts` works as follows:

1. `nodeFs()` reads `(globalThis as unknown as {process?: {getBuiltinModule?: (id: string) => unknown}}).process?.getBuiltinModule?.('node:fs')`. If the result is missing, it throws `Error('pricing fixtures: node:fs is unavailable (needs Node >= 22.3 under Vitest); the replay cannot run')`. That is loud and is never a skip. The fs surface is typed with a local minimal interface (`existsSync`, `statSync`, `readdirSync`, `readFileSync`), so `@types/node` is not needed.
2. `locateRepoRoot()` starts from `process.cwd()`, which is `frontend/` under `npm test`. It walks up at most 4 parents and stops at the first directory that holds both `packages/pricing/fixtures` and `frontend/angular.json`. When it finds none, it throws and lists every candidate it tried. All paths use `/` joins, which Node accepts on Windows.
3. `loadFixtureSet(root)` lists `packages/pricing/fixtures/*/` and then `*.json` in each, the same glob as PHP. It sorts the paths, then reads and `JSON.parse`s each file. It returns `{dir, kind: dirname, name: basename without .json, path, decoded | parseError}`. A missing or empty directory throws.
4. `readEngineSources(root)` lists `frontend/projects/shared/src/lib/pricing-engine/*.ts`, excluding `*.spec.ts`. It returns `{name, text}[]` and throws when the list is empty.

The specs call these at **collection time**, at the top of the file. A throw there fails the spec file in Vitest, which is loud, and the `it` blocks are generated per fixture name from the glob. The engine source never touches `process` or `fs`, and the scan bans `process.env` (AC-42) and every non-`./` import.

**Step-0 fallback**, only if the spike shows `getBuiltinModule` is not reachable: add `"runnerConfig": "projects/shared/vitest-base.config.ts"` to the `shared` test target, with a Vitest `globalSetup` that reads the same directory and `provide`s the parsed set. Specs then `inject` it. The same loud-failure rules apply. This touches `angular.json`, so tell the lead, because it can conflict with T-036 and T-037.

## 4. Source scan (AC-2, AC-12, AC-25, AC-42)

`scanSource(name, src)` returns its violations as `{file, rule, line}`.

1. **Imports.** On the raw text, every `from '...'` and every `import '...'` specifier must start with `./`. Any `import(`, `require(`, `@angular`, `rxjs`, `node:`, `fs` or `path` specifier is a violation (AC-2).
2. **Stripping.** `stripCommentsAndStrings` is one state-machine pass. It removes `//` and `/* */` comments and empties `'…'` and `"…"` string contents. It keeps the quotes and the line breaks, so line numbers stay correct. A backtick anywhere is itself a violation: template literals are banned in engine source, which closes the `${…}` loophole. Messages use `+` concatenation instead. Without stripping, the `MAX_AMOUNT` string `'99999999999999.9999'` would trip the numeric-literal ban.
3. **Banned tokens.** Each token below is one named regex, applied to the stripped text:
   - `parseFloat`, `Number\s*\(`, `Math\.`, `\.toFixed\b`, `\.toPrecision\b`, `\bIntl\b` and `toLocaleString`.
   - Unary plus: `(?:^|[=(,:;?!&|{\[]|\breturn)\s*\+(?![+=])`.
   - Decimal or exponent numeric literal: `(?<![\w$.])\d*\.\d+(?:[eE][+-]?\d+)?\b|\b\d+[eE][+-]?\d+\b`.
   - `\bfetch\b`, `XMLHttpRequest`, `WebSocket`, `\bnavigator\b`, `localStorage`, `sessionStorage`, `indexedDB`, `\bDate\b`, `Math\.random`, `crypto\.getRandomValues`, `process\.env`, `setTimeout`, `setInterval`, `\basync\b`, `\bawait\b`, `\bPromise\b` and `performance\.now`.
   - Module state (AC-44): `^(?:export\s+)?(?:let|var)\b`, with the multiline flag.
4. **Anti-vacuity.** The spec asserts:
   - the scanned file list is non-empty;
   - it is logged with `console.info`;
   - it contains each of `refusal.ts`, `decimal.ts`, `shape.ts`, `byte-order.ts`, `rules.ts`, `types.ts`, `tax.ts`, `price-ladder.ts`, `discount.ts`, `line.ts` and `index.ts`;
   - the scanned byte total is greater than 0.
5. **Controls**, run through the **same** `scanSource` function the real scan uses:
   - One scratch source string per banned rule, with the token in code position. Each must produce exactly that rule, so a broken regex for one token is caught on its own.
   - A scratch file with `import { x } from '@angular/core'` and one with `from '../api/envelope'` must both be flagged.
   - Negative controls: the same tokens inside a comment and inside a `'string'` are **not** flagged. This proves the stripper works without proving the scan passes.

## 5. Build order per kind (R-2; port these exactly)

Throughout this section, "refused" means `PricingRefused` and "error" means `PricingInputError`.

**tax: `calculateTax(input)`**
1. Rule pre-pass.
2. For each member, in input order:
   - read the fields `tax_id`, `code`, `tax_code`, `type`, `is_compound`, `applies_to`, `is_active`, `is_deleted` and `sequence`;
   - build each rate row: `rate` must pass the rate shape, otherwise refused `invalid_amount`; `effective_from` and `effective_to` must be dates; `effective_to` before `effective_from` is an error;
   - then the TaxMember checks: a blank `tax_id` or `code`, fixed and compound together, or `sequence` outside 0 to 255 is an error.
3. Read `amount`, `base_quantity`, `date` and `context`.
4. Compute: `money(amount)`, `money(base_quantity)`, `date(date)`, then `applicable` (filter, sort, resolve each rate with the duplicate check before choosing a winner), then forward or inclusive, then the residue, then the breakdown. The breakdown runs `text()` and the `ulid(tax_id)` check per line.

**price: `resolvePrice(input)`**
1. Rule pre-pass.
2. For each list:
   - read the fields up to `is_deleted`;
   - build its assignments: `scope_type` read, blank `scope_id` is an error, `priority` read as an integer;
   - build its items: `min_qty` and `price` must be money and `discount_percent` a rate (otherwise refused); a `discount_percent` above 100 is an **error**, not a refusal;
   - then the LadderList body: `currency`, `starts_at` and `ends_at` (errors).
3. Read `branch_selling_price` and `selling_price`.
4. The LadderInput body: `unit_factor` (refused), `base_quantity` (refused), `at` (error), `currency` (error), then the two selling prices (refused).
5. Compute `PriceLadder::resolve`. Finish with `PriceSource`'s `ulid(price_list_id)`.

**discount: `calculateItemDiscount(input)`**
1. Rule pre-pass.
2. Build every rule. For each: a blank `id` is an error, then a negative `used_count` or `usage_limit` is an error, then `value`, `min_amount` and `max_discount` must be money (refused), then `starts_at` and `ends_at` (error), then a percentage `value` above 100 is refused `invalid_amount`.
3. Find the rule by `discount_id`, comparing with `===`; the first match wins.
4. Build the product (a blank `id` is an error).
5. `subtotal` must be money (R-7).
6. Compute `forLine`: `instant(at)` (error) first, then the refusal order of AC-22.

**bill: `calculateBillDiscount(input)`**
1. Rules as in the discount kind.
2. Compute `forBill`:
   - `instant(at)`;
   - checks 1 to 3 of the refusal order;
   - `money` of each subtotal, summed;
   - `roundStep(base)` (range check);
   - `amount()`;
   - `text(base)` (unrounded) and `text(amount)`;
   - `ulid(discount_id)`.

**line: `priceLine(input)`**
1. Rule pre-pass.
2. Build the ladder, as in steps 2 to 4 of the price kind.
3. Build the product.
4. Read `tax_group.id` and `is_inclusive`.
5. Build the members, as in step 2 of the tax kind.
6. Build the rules.
7. The LineInput body: a blank `tax_group.id` is an error, `quantity` must be money (refused; a negative is `invalid_amount`), `document_date` must be a date (error).
8. Compute `LineCalculator::compute`. The tax call receives `text(discounted)`, the canonical `base_quantity` and the sale context. The output construction (PricedLine) runs `ulid(discount_id)` when it is not null and `ulid(tax_group_id)`.

## Implementation steps

The developer chunks are A (primitives), B (operations), C (fixture harness) and D (proof instruments and close). Each step ends with `cd frontend && npm test` green.

### Chunk A: primitives (no operation yet)

- [x] **Step 0: Baseline and fixture-access spike.**
  - After the lead's `npm ci`, record the baseline results of:
    - `npm test`;
    - `npx ng build pos`;
    - `npx ng build admin`;
    - `npx ng build shared`;
    - `npx tsc -p projects/shared/tsconfig.spec.json --noEmit`.
  - Create `testing/fixture-files.ts` (section 3) and a temporary assertion in `fixture-set.spec.ts` that `loadFixtureSet()` returns at least 1 file.
  - Run `npm test`. If it fails because `getBuiltinModule` cannot be reached, apply the section-3 fallback and tell the lead.
  - Covers AC-1 and AC-32 (mechanism). Tests: the spike assertion. It is replaced in step 10.
- [x] **Step 1: `refusal.ts` and `refusal.spec.ts`.**
  - Write the 17-value tuple and the three error classes.
  - Tests:
    - the tuple equals the literal list in AC-26, in order, with exactly 17 entries;
    - `new PricingRefused('out_of_range', 'x')` is `instanceof PricingRefused`, while `PricingInputError` and `UnsupportedRuleError` are **not** `instanceof PricingRefused` and vice versa;
    - each error has `.name` set;
    - tests read `.reason` only and never `.message`.
  - Covers AC-26, AC-27 and the "distinct" part of AC-28.
- [x] **Step 2: `decimal.ts` and `decimal.spec.ts`.**
  - Write the section-2 primitives.
  - Tests (TS-only, D-9):
    - `divideHalfUp` on `5n/10n` gives `1n`, `-5n/10n` gives `-1n`, `5n/-10n` gives `-1n`, `-5n/-10n` gives `1n`, `4n/10n` gives `0n`, `-4n/10n` gives `0n` (no `-0`), `15n/10n` gives `2n`, and `-15n/10n` gives `-2n`.
    - `toScaleHalfUp` of `0.00005`, `1.00005` and `2.50005` gives `0.0001`, `1.0001` and `2.5001`. Of `-0.00005`, `-1.00005` and `-2.50005` it gives `-0.0001`, `-1.0001` and `-2.5001`. `-2.50004` gives `-2.5000` (AC-15).
    - `divideToScale(-1, 16000)` gives `-0.0001`; `(1, -16000)` gives `-0.0001`; `(1, 3)` gives `0.3333`; `(2, 3)` gives `0.6667`.
    - `percentOf('100.0000', '12.3456')` gives `12.3456`, not `12.3500`, the value `x × round(p/100, 4)` would produce (AC-14).
    - Precision vectors that no `number` can pass (AC-9): `text(dec('99999999999999.9999'))` round-trips; `99999999999999.9998 + 0.0001` gives the max; `99999999999999.9999 × 1.000000` stays exact; `percentOf(max, '50.0000')` gives `50000000000000.0000`.
    - Range (AC-10): the max is accepted by `roundStep`, `percentOf` and `text`, while max + `0.0001` is refused `out_of_range` by each.
    - `text`: a negative is refused `out_of_range`; `sub(1.0000, 1.0000)` gives `0.0000`; a scale-5 non-tie value is refused `invalid_amount`; `7.5` and `7.5000` give the same text (AC-8, AC-11).
  - Covers AC-8 to AC-11, AC-13 (primitive), AC-14 and AC-15.
- [x] **Step 3: `shape.ts` and `shape.spec.ts`.**
  - Write the Shape twins and the field guards (R-5, R-6).
  - Tests:
    - **Money:** `1.0000` is accepted, and `007.5` canonicalises to `7.5000`. Each of these is refused `invalid_amount`: `''`, `' 1'`, `'1 '`, `'1.0000\n'`, `'-1.0000'`, `'+1'`, `'1e3'`, `'1.00001'`, `'.5'`, `'5.'`, `'1,5'`, 15 integer digits, the number `1`, the bigint `1n`, `null`, `NaN` and `undefined` (AC-5, AC-6).
    - **Rate:** 5.4 digits is the limit (AC-7).
    - **Factor:** `0.999999` and `0.000000` are refused, `1.000000` is accepted, and 12.6 digits is the limit (AC-7).
    - **Errors (`PricingInputError`):** an id of 25 or 27 characters, or one with a dash; `2025-02-29`; `0000-01-01` (PHP `checkdate` rejects year 0); `2025-13-01`; an instant with a 24 hour, a 60 minute or a 60 second; one missing `Z` or milliseconds, or with a non-calendar date; `usd` as a currency; integers `'1.0'`, `'1e3'`, `''`, `2^63` and `-2^63-1`. Both 64-bit bounds are accepted (AC-28, AC-41).
    - `ulid` upper-cases its result.
  - Covers AC-5 to AC-8, AC-28 and AC-41.
- [x] **Step 4: `byte-order.ts` and `byte-order.spec.ts`.**
  - Tests:
    - sorting `['a', '9', 'B', '10', '\u{1F600}', '～']` gives `['10', '9', 'B', 'a', '～', '\u{1F600}']`, and the test also shows that JS default `<` orders the last two the other way (AC-40);
    - `compareInt` sorts `'10'` after `'9'` and handles `-1` and int64 extremes (AC-41).
  - Covers AC-40 and AC-41.
- [x] **Step 5: `rules.ts` and `rules.spec.ts`.**
  - Write the vocabularies and `assertKnownRules` (R-3).
  - Tests: one per field, giving `UnsupportedRuleError` with the right `.field` and `.value` (for example a `members[].type` of `'surcharge'`, a `context` of `'transfer'`, a `discounts[].type` of `'loyalty'`). An unknown value inside a **deleted** member still throws. `buy_x_get_y` does **not** throw. A non-string enum value is left for the build phase.
  - Covers AC-29 (vocabulary).

### Chunk B: operations (port of `packages/pricing/src/Engine`, section-5 order)

- [x] **Step 6: `types.ts`, `tax.ts` and `tax.spec.ts`.**
  - Write `resolveRate` and `calculateTax`. Port `applicable`, `forward`, `inclusiveNet` (slope and constant exactly as the PHP), `sum`, `lastPercentage` and `breakdown`.
  - Tests (TS-only vectors, beyond the fixtures):
    - members supplied in reverse sequence give the same output;
    - an equal-sequence tiebreak by `tax_id` byte order;
    - a duplicate `effective_from` on a surviving member is refused `invalid_amount` even when neither row qualifies, and the same duplicate on a deleted member and on an inactive member is accepted (AC-18);
    - a malformed rate on a deleted member is refused (R-2);
    - an unknown `context` throws `UnsupportedRuleError` before an invalid `amount` is noticed (AC-29);
    - lower-case `tax_id` input is upper-cased in the output;
    - a negative rounded intermediate (an inclusive residue of `-0.0001` on the last non-zero member) is serialised correctly, or refused `out_of_range` where PHP would refuse it (D-17).
  - Covers AC-16, AC-17, AC-18 and AC-13 (tax steps).
- [x] **Step 7: `price-ladder.ts` and `price-ladder.spec.ts`.**
  - Write `resolvePrice`: `qualifies`, `assigned` (assignment priority descending, list priority descending, `compareBytes(code)`), `promotions`, `fromList`, `greatestQualifying` (the duplicate `min_qty` check over all items of the inspected unit before qualification), `derive` and `outcome`.
  - Tests:
    - a duplicate `min_qty` is refused whatever the row order (both orders tested), and duplicates in another unit are ignored (AC-20);
    - two promotions with equal priority and codes `'10'` and `'9'` make `'10'` win (byte order);
    - a priority of `'10'` beats `'9'` (integer order);
    - a `discount_percent` of `100.0001` is a `PricingInputError`, not a refusal;
    - a window instant exactly at `starts_at` and exactly at `ends_at` is accepted.
  - Covers AC-19, AC-20, AC-21 and AC-13 (derived and list-percent steps).
- [x] **Step 8: `discount.ts` and `discount.spec.ts`.**
  - Write `calculateItemDiscount` and `calculateBillDiscount`, porting `available`, `targets`, `amount` and `capped`.
  - Tests:
    - an input that fails every check at once gives `unknown_discount`; removing the first failing condition each time walks down the full AC-22 order;
    - `ends_at` exactly is accepted, and `+1ms` gives `expired`;
    - `used_count === usage_limit` gives `usage_limit_reached`;
    - `buy_x_get_y` gives the refusal `unsupported_discount_type`, while `'loyalty'` gives `UnsupportedRuleError` (AC-30, a pair of tests);
    - a percentage `value` of `100.0001` is refused `invalid_amount` even when another rule is selected (R-2);
    - the bill with `[]` gives base `0.0000`.
  - Covers AC-22, AC-23 and AC-30.
- [x] **Step 9: `line.ts`, `line.spec.ts`, `index.ts` and the `public-api.ts` line.**
  - Write `priceLine`.
  - Tests:
    - the subtotal uses `quantity`, not `base_quantity` (one case of 24);
    - a negative `quantity` is refused `invalid_amount`;
    - an inclusive group's `line_total` equals `discounted_subtotal`;
    - an import through `'@flexipos/shared'`, not a relative path, reaches all five operations (AC-3, AC-4).
  - Covers AC-3, AC-4 and AC-24.

### Chunk C: the shared-fixture harness

- [x] **Step 10: `fixture-lint.ts`, `fixture-replay.ts` and `fixture-set.spec.ts`.**
  - `lintFixture` is a line-for-line copy of `FixtureReplay::lint`. That covers the required keys; exactly one of `expect` and `refusal`; no other top-level key; `kind` in the five kinds and equal to its directory; `name` equal to the basename; `refusal` matching `^[a-z_]+$`; and the walk that rejects any JSON number and any `^\d+\.\d+$` string not ending in 4 or 6 decimals.
  - Two TS-only additions: the `refusal` must be in `PRICING_REFUSALS`, and `discriminates` must be a string.
  - `assertFixtureSet` checks AC-34: the directory exists; there are at least 30 files (D-15, never a larger hard-coded count); all five kinds are present; every kind has at least one expect fixture and at least one refusal fixture; `name` is unique.
  - The spec:
    1. `const set = loadFixtureSet()` and `assertFixtureSet(set)` run at collection time;
    2. `it.each(set)('lint %s')`;
    3. `it.each(set)('replay %s')`, where `compareCase(case)` must return `[]`. `compareCase` deep-equals `stringify(output)` against `expect`, or the refusal value against `refusal`.
  - `REPLAY` is typed `Record<FixtureKind, (input: unknown) => unknown>`, with all five kinds. `replay` catches **only** `PricingRefused`.
  - Controls, run through the same functions:
    - a cloned fixture with one `expect` amount changed by `0.0001` gives a non-empty `compareCase` (AC-36a);
    - a clone with `"priority": 0` as a number gives a lint problem (AC-36b);
    - a refusal fixture whose `refusal` is renamed to `expired` gives a non-empty `compareCase` (AC-36c);
    - a clone whose member `type` is `'surcharge'` makes `replay` throw `UnsupportedRuleError` and return no `{refusal}` (AC-38);
    - `assertFixtureSet` throws on a 29-file subset, on a set with the `bill` files removed, on a set with no refusal fixture for `tax`, and on a duplicated name;
    - `loadFixtureSet('/nonexistent')` throws.
  - If a replay mismatches, fix the TS (AC-37). If the TS is believed right, stop and report the fixture name to the lead; never edit the fixture.
  - Covers AC-1, AC-32 to AC-38 and AC-34.

### Chunk D: proof instruments and close

- [x] **Step 11: `testing/source-scan.ts` and `source-scan.spec.ts`.**
  - Write the section-4 scan over `readEngineSources()`. Each engine file is one `it`, asserting `scanSource(...)` returns `[]`. Add the anti-vacuity assertions and the controls of section 4.
  - Covers AC-2, AC-12, AC-25 and AC-42.
- [x] **Step 12: `determinism.spec.ts`.**
  - AC-39: for every fixture, for every array found anywhere in its `input` (found by walking, so new lists are covered), the reversed version and the rotated-by-one version each give the same `replay` result as the original. A legitimately order-dependent fixture would be named in an explicit exclusion list with a reason; none is expected. A non-empty exclusion list must be reported to the lead.
  - AC-43:
    1. Pre-load all cases.
    2. Save `globalThis.fetch`, `XMLHttpRequest` and `Date`.
    3. Replace each with a function or class that records the call and throws.
    4. Synchronously replay every fixture inside `try`/`finally`, restoring the globals in `finally`.
    5. Only after restoring, assert zero recorded calls and results equal to an unstubbed replay.
  - AC-44: deep-freeze each fixture input, call its operation twice, and expect equal results. A `JSON.stringify` of the input is unchanged afterwards, and no "Cannot assign to read only" error is thrown. Module state is covered by the scan rule in step 11.
  - AC-45: for each operation the result is a plain object (`Object.getPrototypeOf(r) === Object.prototype`) with no `then` property, and every nested object passes the same check.
  - Covers AC-39 and AC-43 to AC-45.
- [x] **Step 13: Docs and the full gate.**
  - Update `frontend/README.md` and the `public-api.ts` header comment.
  - Run the full gate (Test strategy).
  - Check that admin ships no engine: after `npx ng build admin`, `grep -l "inclusive_amount_too_small" dist/admin/browser/*.js` must print nothing, with the same rc handling as the CI mock-backend check. Record the result in the hand-off. This is not a CI step, because `pos` does not import the engine yet either.
  - Covers the NFR (tree-shaking) and AC-1.

## Test strategy

- **Unit** (TS-only, D-9: these are not parity claims): `decimal`, `shape`, `byte-order`, `rules`, `refusal`, plus per-operation specs for the hazards in sections 1 and 5. This includes the negative-tie rounding vectors AC-15 requires, and the BigInt precision vectors no `number` can pass (AC-9).
- **Parity:** `fixture-set.spec.ts` replays all 120 files found by glob, at the time of writing: tax 32, price 37, discount 31, line 12, bill 8. Each kind has both expect fixtures and refusal fixtures. Lint, anti-vacuity and the controls are in the same spec. This is the proof of AC-13 to AC-24 against PHP.
- **Instruments:** `source-scan.spec.ts` (static) and `determinism.spec.ts` (runtime), each with controls that prove they can fail.
- **AC to proof:**

| AC | Proof |
|---|---|
| 1 | gate run |
| 2 | scan imports |
| 3, 4 | `line.spec.ts` imports through `@flexipos/shared` |
| 5 to 8 | `shape.spec.ts` |
| 9 to 11 | `decimal.spec.ts` |
| 12 | scan |
| 13, 14 | `decimal.spec.ts` plus the fixtures (`*-rounds-*`, `price-item-discount-percent-rounds-once`) |
| 15 | `decimal.spec.ts` |
| 16 to 18 | fixtures plus `tax.spec.ts` |
| 19 to 21 | fixtures plus `price-ladder.spec.ts` |
| 22, 23, 30 | fixtures plus `discount.spec.ts` |
| 24 | fixtures plus `line.spec.ts` |
| 25 | scan plus the `Date` stub |
| 26, 27 | `refusal.spec.ts` |
| 28, 41 | `shape.spec.ts` |
| 29 | `rules.spec.ts` plus the control in `tax.spec.ts` |
| 31 | `rules.spec.ts` (nothing is returned) |
| 32 to 38 | `fixture-set.spec.ts` |
| 39, 43 to 45 | `determinism.spec.ts` |
| 40 | `byte-order.spec.ts` plus `price-ladder.spec.ts` |
| 42 | scan |

- **Gate** (all from `frontend/`): `npm test`, `npx ng build pos` and `npx ng build admin` (as in CI). Add `npx ng build shared` and `npx tsc -p projects/shared/tsconfig.spec.json --noEmit` if each was green at the step-0 baseline; if one was red at baseline, record it and do not fix it here. Also run `npx prettier --check projects/shared/src/lib/pricing-engine` (the existing `.prettierrc`). The PHP gate is not run.

## Risks & rollback

- **The test runner cannot reach `getBuiltinModule`.** Mitigated by the step-0 spike before any other code. The fallback is `runnerConfig` plus `globalSetup`, which touches `angular.json`, so tell the lead.
- **Parity drift in a check-order corner case** (a multi-fault input). Mitigated by porting the section-5 order. The fixtures pin single-fault behaviour, so a corner that differs is a TS-only test gap, not a wrong total.
- **The fixture set changes on the PHP side mid-task** (D-16). The replay reads the live directory, so re-running is the check. Nothing is hard-coded except the floor of 30.
- **Export-name collision with T-036 or T-037** in `public-api.ts` (for example a sales `PricedLine` contract). It would surface as a compile error at merge. Resolve it by renaming the later arrival. No current sibling branch exports any of these names.
- **Scan false positives** (for example the word `Date` in an identifier such as `documentDate` is safe because of `\b`, but `Date` as its own word in code is not). Comments and strings are stripped. If a legitimate token is blocked, rename it rather than weakening the regex.
- **Rollback:** delete `lib/pricing-engine/` and the one `public-api.ts` line, and revert the README. No other file and no data is affected.

## Questions for the human

None for the human. Questions for `ba` (non-blocking; this plan builds the recommended answer):

- **Q-1 (spec text versus fixtures).** The spec's Assumptions name the `price` expect shape with `list_discount_percent`. The fixtures and PHP `LadderOutcome::toArray()` use **`discount_percent`** for the `price` kind; only `line` uses `list_discount_percent`. *Recommended:* the fixtures win (AC-37). Correct the assumption line.
- **Q-2 (AC-28 ids).** Read literally, AC-28 makes every malformed id a programmer error. The PHP pure engine ULID-checks **only output ids**: applied `tax_id`, the winning `price_list_id`, `tax_group_id`, the line or bill `discount_id`. It upper-cases them, and it only blank-checks the others (`scope_id`, product `id`, discount `id`, tax `code`). Checking every id would make TS refuse inputs PHP prices, for example a deleted member with a non-ULID id. *Recommended:* mirror PHP placement (R-4) and reword AC-28 to "where PHP checks it".
- **Q-3 (`discount` kind `subtotal`).** PHP's replay does not shape-check it. TS checks it as money (R-7), which follows AC-5. *Recommended:* accept, since it is stricter only on inputs PHP never receives.
- **Q-4 (non-boolean or missing key).** The spec is silent. *Recommended:* `PricingInputError` (R-6), the twin of the replay's `RuntimeException`.
- **Q-5 (AC-29 order).** TS throws `UnsupportedRuleError` before *any* other check, including a refusal on an unrelated malformed amount. PHP would have thrown `ValueError` at mapping. *Recommended:* accept. Unknown-rule-first is the safer signal for the till, and no fixture is affected.
