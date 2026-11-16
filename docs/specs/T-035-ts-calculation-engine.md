# T-035: The TypeScript half of the shared tax/price/discount calculation engine

Backlog row: FP-432 (FE). Split out of FP-231 / T-029 (T-029 D-1, D-11). Branch `ai/T-035-ts-calculation-engine`.
Decisions: `docs/decisions/T-035-ts-calculation-engine.md` (D-n below refer to that file; "T-029 D-n" refers to `docs/decisions/T-029-taxes-pricing.md`).

## Problem / goal

T-029 built a pure PHP calculation engine in `packages/pricing/src/Engine` (tax groups, the six-step price ladder, item and bill discounts, one priced line) and a checked-in JSON fixture set in `packages/pricing/fixtures/{tax,price,discount,line,bill}/`. `docs/architecture.md` section 15 requires the same engine to exist a second time in TypeScript so an offline till prints the same totals the server later computes, "driven by shared fixtures". Today `frontend/` contains no calculation engine and no shared-fixture test.

Goal: a framework-free TypeScript engine in `frontend/` whose every output, to the last decimal digit, equals the PHP engine's for the same input, proven by replaying the same fixture files the PHP side replays, with no floats, no clock, no network.

The PHP source and the fixtures are the authority. This spec pins the behaviours that are easy to get wrong in a second language; it does not restate every rule (those are T-029 AC-7, AC-9, AC-11, AC-15 to AC-17, AC-20, AC-22, AC-23, AC-30 and T-029 D-12 to D-43).

## Users affected

- Cashier on the offline till: sees the same line totals, tax and discount offline as the server will compute on sync.
- Shop owner / accountant: a receipt printed offline matches the server document.
- Developers of the till (FP-236) and offline replica (FP-237): get a tested engine to call.

## Current behaviour

- PHP engine exists and is fixture-proven on the PHP side (`packages/pricing/tests/Engine/FixtureSetTest.php`, `tests/Support/FixtureReplay.php`). Fixture format: `{name, kind, discriminates, input, expect | refusal}`; every decimal and integer is a JSON string at full stored scale (4 places, 6 for a factor); dates `YYYY-MM-DD`; instants `YYYY-MM-DDTHH:MM:SS.mmmZ`.
- `frontend/` is an Angular 21 workspace (`projects/admin`, `projects/pos`, `projects/shared`), tests run by `npm test` (`ng test --watch=false`, Vitest). `projects/shared` is the library both apps import. No decimal library is a dependency.
- No TypeScript money, tax, price or discount arithmetic exists.

## User stories

- As a cashier, I want the till to compute line totals, tax and discounts offline, so that I can sell with no internet and the customer sees the right total.
- As a shop owner, I want the offline total to equal the server total, so that synced sales are never silently different from the printed receipt.
- As a till developer, I want a pure, side-effect-free engine with the same inputs and outputs as the server's, so that I can call it from the basket without designing arithmetic.
- As a QA engineer, I want one fixture set that both engines must pass, so that a change on either side that breaks parity fails a gate.
- As a maintainer, I want an unrecognised rule type to be refused loudly rather than guessed, so that a newer server catalogue never produces a wrong offline total.

## Acceptance criteria

### A. Scope and packaging

- AC-1: Given the repository, When `cd frontend && npm test` runs, Then the engine's tests run inside that gate and no PHP, Composer, database or network is needed.
- AC-2: Given the engine's source files, When they are inspected, Then they import nothing from `@angular/*`, `rxjs`, the DOM, or Node (`fs`, `path`), so the engine runs identically in a till, a service worker and a test.
- AC-3: Given the engine is built, When another project in `frontend/` (the till) imports it, Then it is reachable through the `shared` library's public API (location per architect, D-4).
- AC-4: Given the engine, When its public surface is listed, Then it offers five pure operations matching the fixture kinds: tax of an amount under a tax group (`tax`), price ladder resolution (`price`), item discount amount (`discount`), bill discount (`bill`), and one priced line (`line`).

### B. Decimal arithmetic, no floats

- AC-5: Given any money, quantity, rate or factor input, When it is not a JSON/JS string, Then the engine refuses it `invalid_amount`. This includes a JS `number`, a `bigint`, `null` where a value is required, and `NaN`.
- AC-6: Given a money or quantity string, When it is not `^\d{1,14}(\.\d{1,4})?$`, Then it is refused `invalid_amount`. Covers: empty, blank, leading or trailing space, a sign (`-1.0000`, `+1`), an exponent (`1e3`), a fifth decimal (`1.00001`), a bare dot (`.5`, `5.`), a comma, more than 14 integer digits.
- AC-7: Given a rate or percentage string, When it is not `^\d{1,5}(\.\d{1,4})?$`, Then it is refused `invalid_amount`. Given a unit factor, When it is not `^\d{1,12}(\.\d{1,6})?$` or is below 1 (for example `0.999999`), Then it is refused `invalid_amount`.
- AC-8: Given valid inputs `7.5` and `7.5000`, When either is used, Then they are the same value; every output decimal is canonical (exactly four decimals, no thousands separator, no exponent).
- AC-9: Given an amount above `2^53` in minor units (for example `99999999999999.9999`), When it is added, multiplied or rounded, Then no digit is lost (the engine's arithmetic is exact; verified by a vector no `number` could pass).
- AC-10: Given a result (after any named rounding step) whose absolute value exceeds `99999999999999.9999`, When it is produced, Then the engine refuses `out_of_range` at the same steps PHP does (`Decimal::round`, `Decimal::percentOf`, `Decimal::text`). Exactly `99999999999999.9999` is accepted.
- AC-11: Given an output value of zero reached through subtraction or rounding, When it is serialised, Then it is `0.0000`, never `-0.0000`. A negative value reaching an output is refused `out_of_range`, as PHP does.
- AC-12: Given the engine's source files, When a static scan runs, Then it finds none of: `parseFloat`, `Number(`, unary `+` coercion of a string, `Math.`, `.toFixed`, `.toPrecision`, `Intl.`, `toLocaleString`, a numeric literal with a decimal point or exponent. The scan carries a control (a scratch source containing each token) proving it can fail, and reports the file list it scanned so an empty glob is a failure, not a pass.

### C. Rounding identical to PHP

- AC-13: Given any named step, When it rounds, Then it rounds to four decimals half-up with ties going away from zero (Brick `RoundingMode::HalfUp`), and the rounded value is what the next step consumes. The named steps are exactly: derived price (`stock price x factor`), list-percent price, subtotal, discount amount, each tax amount, the inclusive net. Adding and subtracting four-decimal values is exact and not re-rounded.
- AC-14: Given `x * p / 100`, When computed, Then it is multiplied exactly and rounded once, never `x * round(p/100)`. A vector where the two give different results is replayed (T-029 Ruling 3.3).
- AC-15: Given an exact tie (`0.00005` at the fifth place, `1.00005`, `2.50005`), When rounded, Then the result goes up, not to even, not down; given a negative intermediate (the inclusive residue, the compound running sum) that is rounded, Then ties also go away from zero. A unit test of the rounding primitive covers positive and negative ties, because BigInt division truncates toward zero and this is the classic divergence.
- AC-16: Given the inclusive back-out of T-029 AC-11.6 (the exact linear rule: slope `S`, constant `K`, fixed total `F`, `net = round((gross - F - K) / (1 + S), 4)` once), When run, Then results equal PHP's, including the residue going to the last percentage member in `sequence` whose resolved rate is not zero (T-029 D-34), refusing `inclusive_amount_too_small` when `F` exceeds the gross or `gross - F - K` is negative.

### D. Engine behaviour parity (restated only where easy to miss)

- AC-17: Given a tax group, When tax is computed, Then member filtering (deleted, inactive, `applies_to` not covering the context), ordering by `sequence` then bytewise `tax_id`, rate resolution (latest `effective_from` on or before the date, `effective_to` strictly after the date), compounding (base plus every earlier member amount), fixed taxes (`round(rate x base_quantity)`, base shown is the line amount), and the empty-group result (`net = amount`, no lines, total `0.0000`) equal PHP's.
- AC-18: Given two rate rows of one surviving member share an `effective_from`, When tax is computed, Then it is refused `invalid_amount` whether or not either qualifies (T-029 D-36); given the duplicate belongs to a deleted or inactive member, Then it is not refused (T-029 D-43). No rate effective on the date for a surviving member is `no_effective_rate`, never zero.
- AC-19: Given the price ladder, When resolved, Then step order 1 to 6, the list filters (active, not deleted, window inclusive at both ends against the document instant, same currency), unscoped versus scoped promotions, ordering (assignment priority descending, list priority descending, code ascending bytewise), unit-matched versus derived basis, greatest qualifying `min_qty` against the base quantity, derivation of steps 5 and 6 by the unit factor, and the item `discount_percent` applied once to the resolved price equal PHP's.
- AC-20: Given two items of one list for the same unit key with equal `min_qty`, When the ladder inspects that unit key, Then it refuses `invalid_amount` whether or not either qualifies, and independent of row order (T-029 D-31). Items of other units are not inspected.
- AC-21: Given no step prices the line, When resolved, Then the refusal is `no_price_resolved`.
- AC-22: Given a discount, When applied to a line or a bill, Then the refusal order is exactly: `unknown_discount`, `discount_scope_mismatch`, `inactive`, `not_yet_started`, `expired` (equal to `ends_at` accepted, one millisecond after refused), `usage_limit_reached` (`used_count >= usage_limit`), `discount_not_allowed` (line only), `not_applicable` (line only, OR across products, categories, brands; all three empty means everything), `below_min_amount`, `unsupported_discount_type` (`buy_x_get_y`). A percentage discount is capped by `max_discount`; a fixed discount is `min(value, base)`; a percentage value above 100 is refused `invalid_amount` when the rule is built, before any other check.
- AC-23: Given a bill discount, When applied, Then its base is the sum of the lines' discounted (pre-tax) subtotals, an empty list gives base `0.0000`, and the output is `{discount_id, base, amount}`.
- AC-24: Given a line, When priced, Then the order is ladder, `subtotal = round(unit_price x quantity)` with the line-unit `quantity` never `base_quantity`, discount, `discounted_subtotal = subtotal - discount`, tax on the discounted subtotal in the sale context with `base_quantity` feeding fixed taxes, `line_total = discounted_subtotal + tax.total` (exclusive) or `discounted_subtotal` (inclusive). A negative quantity is refused `invalid_amount` (T-029 D-26).
- AC-25: Given the engine never receives or reads a wall clock, When any time check runs (discount window, price-list window), Then it compares the supplied document instant, as fixed-width strings, and the supplied document date for rate windows. No `Date` object, `Date.now`, or `performance.now` exists in the engine.

### E. Refusals, errors and unknown rule types

- AC-26: Given the engine refuses, When the caller handles it, Then the reason is one of exactly the seventeen `PricingRefusal` values: `unknown_product`, `unknown_tax_group`, `no_effective_rate`, `inclusive_amount_too_small`, `no_price_resolved`, `unknown_discount`, `discount_scope_mismatch`, `inactive`, `not_yet_started`, `expired`, `usage_limit_reached`, `discount_not_allowed`, `not_applicable`, `below_min_amount`, `unsupported_discount_type`, `invalid_amount`, `out_of_range` (the engine itself never raises the first two, which are the server adapter's; the TS vocabulary still lists the full closed set so a shared fixture or server response can name them). The TS engine introduces no eighteenth reason. A test compares the TS list to the literal list above.
- AC-27: Given a refusal, When it is asserted in a test, Then the comparison is on the machine value only, never on the message text.
- AC-28: Given an id, date, instant, currency, or integer field that is malformed (not a 26-character alphanumeric id, not a valid `Y-m-d`, not a valid UTC `YYYY-MM-DDTHH:MM:SS.mmmZ` instant with a real calendar date and in-range time, not three upper-case letters, not a decimal-integer string, a negative `used_count` or `usage_limit`, a blank discount or tax-group id), When the engine receives it, Then it throws a programmer-error (distinct from a refusal and not catchable as one), mirroring PHP's `InvalidArgumentException`. It never returns a result and never produces a refusal reason.
- AC-29: Given an input holding a rule value the engine does not recognise, namely a `tax.type` other than `percentage`/`fixed`, a `tax.applies_to` other than the three known values, a list `type` or assignment `scope_type` or discount `scope` or discount `type` outside the known sets, or a tax context other than sale/purchase, When the engine is called, Then it throws an `UnsupportedRuleError` naming the field and value, before any arithmetic, and returns no total. It never treats the unknown as zero, as exclusive, as a percentage, or as "no discount".
- AC-30: Given a discount whose type is the known value `buy_x_get_y`, When applied, Then the result is the refusal `unsupported_discount_type` at its place in the refusal order (AC-22), not an `UnsupportedRuleError`. The known-but-unsupported case and the unrecognised case are distinct and each has a test.
- AC-31: Given a `UnsupportedRuleError`, When it reaches the till, Then it is the till's signal that it cannot price offline for that line; the engine does not fall back to a price, a rate or a discount of its own. (The till's reaction is FP-236's; this task proves only the engine's behaviour.)

### F. Shared golden fixtures

- AC-32: Given the fixture set at `packages/pricing/fixtures/*/*.json`, When the frontend test runs, Then it reads those same files in place. There is no copy of them under `frontend/`, no second fixture directory and no generated duplicate that can drift (D-1).
- AC-33: Given every fixture file found by glob (a file dropped in is exercised without anyone listing it), When replayed through the TS operation its `kind` names, Then the engine's output serialised to the fixture shape deep-equals `expect`, or refuses with exactly the `refusal` value. The ladder step, an integer in PHP output, is compared as a string, as the fixture stores it.
- AC-34: Given the fixture set, When the TS test starts, Then it fails (does not skip, does not pass vacuously) if the directory is missing, empty, holds fewer than thirty files, lacks any of the five kinds, or lacks a result-expecting and a refusal-expecting fixture for any kind. Every `name` is unique and equals its file name, and its `kind` equals its directory.
- AC-35: Given a fixture file, When linted by the TS test, Then it fails if any value anywhere is a JSON number (every decimal and integer must be a string), if a decimal-looking string is not at full scale (4 places, or 6 for a factor), if a top-level key other than `name`, `kind`, `discriminates`, `input`, `expect`, `refusal` appears, or if it holds both or neither of `expect` and `refusal`. Mirrors `FixtureReplay::lint`.
- AC-36: Given the replay and the lint, When a control is applied (a scratch fixture with a wrong expected amount, a scratch fixture with a bare JSON number, a refusal fixture naming a different reason), Then each fails the harness, proving it can fail.
- AC-37: Given the fixtures are the ground truth agreed with PHP, When a TS result differs from a fixture, Then the TS engine is wrong and is fixed; the fixture is not edited in this task (D-9). If a fixture is itself believed wrong, the case is reported to the lead and resolved on the PHP side with its own gate.
- AC-38: Given the fixture inputs are the only thing the replay feeds the engine, When a fixture contains an `unknown`/unsupported rule value (none today), Then the harness would surface the `UnsupportedRuleError`, not swallow it as a refusal. A scratch-fixture control proves this.

### G. Determinism and ordering

- AC-39: Given the same input supplied with its list members in any order (tax members, rate rows, price lists, items, assignments, discount rules), When computed, Then the output is identical, except where the PHP engine itself refuses a duplicate key. A permutation test shuffles each list of a representative set of fixtures and expects equal output.
- AC-40: Given codes and ids compared for ordering (list `code`, tax `tax_id` tiebreak), When compared, Then the comparison is by UTF-8 byte order, equivalent to PHP `strcmp`, not by locale, not numeric, not by UTF-16 code unit. A vector uses `"10"`, `"9"`, `"a"`, `"B"` and one code with a non-BMP character against a high-BMP character, expecting the byte order.
- AC-41: Given priorities, sequences and usage counts (integer strings in the fixture), When compared, Then they are compared as integers (`"9"` before `"10"`), never as strings and never through a JS `number` where it could lose digits (values up to 64-bit signed are accepted; beyond that, a programmer-error per AC-28).

### H. No network, no ambient state

- AC-42: Given the engine's source files, When a static scan runs, Then it finds none of: `fetch`, `XMLHttpRequest`, `WebSocket`, `navigator`, `localStorage`, `sessionStorage`, `indexedDB`, `Date`, `Math.random`, `crypto.getRandomValues`, `process.env`, `setTimeout`, `setInterval`, `async`/`await`/`Promise`. The scan has a control and lists the files scanned (AC-12 shape).
- AC-43: Given a test that replaces `fetch`, `XMLHttpRequest` and `Date` with functions that throw, When every fixture is replayed, Then all results are unchanged and nothing throws, proving no hidden dependency.
- AC-44: Given any operation, When called twice with the same input, Then it returns equal results, mutates none of its arguments (inputs are deep-frozen in the test), and keeps no module-level state.
- AC-45: Given the engine is synchronous, When called, Then it returns a value or throws; it never returns a Promise or observable.

## Out of scope

- The unit resolution twin (`SnapshotCalculator`, `UnitLadder`, `InheritedFactor`) and its fixtures `packages/catalog/fixtures/units/` (D-5). The pricing engine takes `unit_factor` and `base_quantity` as inputs, exactly as the PHP one does.
- Barcode-rule parsing (FP-423) and the offline replica / sync (FP-237).
- Wiring the engine into the till page, basket state, receipts or the sales payload (FP-236, FP-233 consumers).
- Any change to the PHP engine, `packages/pricing/fixtures`, the PHP gate, backlog or other docs.
- Loading, caching or storing price lists, tax groups or discounts from the server; the engine receives plain data.
- Currency conversion, `buy_x_get_y`, discount approval or workflow, allocation of a bill discount to lines, rounding to cash denominations (none exists in the PHP engine).
- Formatting, localisation or display of amounts.
- A PHP-side generator command that writes fixtures from the engine (D-1, owner-visible question 5).

## Non-functional requirements

- Correctness over speed. No performance target is invented; a full fixture replay must finish within the normal `npm test` run (no per-test timeout raised).
- Zero new runtime dependency for decimal arithmetic (D-2); any other new dev dependency needs the architect's justification.
- Accessibility, security and permissions: not applicable (a pure library, no UI, no data access). Money amounts are not logged by the engine.
- The engine must not import anything that makes the `shared` library's production bundle larger for the admin app beyond the engine's own code (tree-shakeable exports).

## Open questions

None blocking. See "Owner-visible questions" below for choices made on the owner's behalf.

## Assumptions

- T-029 / FP-231 (the PHP engine and the fixture set) is complete and merged before this task's QA; today the code and fixtures are present in the tree while backlog row FP-231 still reads SPEC. If the fixture set changes while this task is in flight, the TS replay is re-run against the new set (D-9).
- Fixture shapes in the tree (read directly for this spec) are the format contract: top-level `name/kind/discriminates/input/expect|refusal`, snake_case keys, strings for all decimals and integers, `null` for absent optional values.
- The fixture `expect` shapes are: `tax` the `TaxBreakdown` (`is_inclusive`, `net`, `lines[]` of `tax_id, code, tax_code, type, is_compound, rate, base, amount`, `total`); `price` the ladder outcome (`list_price`, `list_discount_percent`, `unit_price`, `source{step, price_list_id, basis}`); `discount` `{discount_amount}`; `bill` `{discount_id, base, amount}`; `line` the priced line (all of the above combined plus `subtotal`, `discount_amount`, `discount_id`, `discounted_subtotal`, `tax_group_id`, `tax`, `line_total`).
- Only the fixed-point arithmetic of PHP's `Decimal` and `Shape` is mirrored; the PHP `PricingEngine` database adapter (unknown product, unknown tax group, malformed `applies_to` read from storage) has no TS twin and is out of scope.
- The frontend test runner (Vitest through `ng test`) can read files outside `frontend/` at test time; if it cannot, the architect resolves it (D-1) without introducing a copy.

## Owner-visible questions (defaults already built in)

1. Should the unit-resolution twin (`SnapshotCalculator`, catalog unit fixtures; `docs/architecture.md` section 15 says unit resolution belongs to the engine) be built in this task? Default built in: no, a separate follow-up row; this task is the pricing engine of FP-432 and takes the factor as input (D-5).
2. What should the till do when the engine says "unsupported rule" (a newer server catalogue the old app build cannot interpret)? Default built in: the engine throws `UnsupportedRuleError` and never guesses; the till's policy (block the line, ask for a manual price, force an update) is FP-236's decision (D-6).
3. The requirement says fixtures are "generated from the PHP engine". They are hand-derived ground truth checked against PHP by the PHP replay, which is the stronger form of the same guarantee. Default built in: no generator; the PHP replay proves PHP equals the fixtures and the TS replay proves TS equals them (D-1).
4. Nothing on the PHP side currently stops the PHP refusal list, a tax type or a discount type from gaining a new value without the TS twin hearing about it. Default built in: out of scope here (frontend only); recommend a follow-up row for a PHP-side guard that pins the sets, and the TS side already pins them (AC-26, AC-29).
5. The tiny-rate residue edge (T-029 D-45: a non-zero rate whose amount rounds to 0.0000 can receive a negative residue and be refused `out_of_range`) is unruled by the owner. Default built in: the TS twin copies PHP as built, so both refuse identically; if the owner rules, both sides change together.
