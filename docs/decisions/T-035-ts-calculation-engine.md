# T-035 decisions (TypeScript calculation engine, FP-432)

Autonomous/team-mode. Format: `D-n | Question | Decision | Reason | Reversible`.

D-1 | Where do the shared golden vectors live, and how does the frontend gate read them? | **One source of truth: `packages/pricing/fixtures/{tax,price,discount,line,bill}/*.json`, read in place by the frontend test; no copy, no generated duplicate under `frontend/`.** The fixtures stay hand-derived ground truth that the PHP replay already proves equal to the PHP engine; the TS replay proves equal to the same files. No PHP-side generator is added. How the Vitest run reaches a path outside `frontend/` (Node `fs` in the test only, a build-time asset mapping) is the architect's call, with the constraint that the engine source never imports `fs` (spec AC-2) and the test fails loudly if the directory is absent (AC-34). | Reason: a copy can drift silently, which is the exact failure fixtures exist to prevent; the requirement's "generated from the PHP engine" is satisfied in effect (the PHP gate fails if the engine and fixtures disagree) without adding a PHP change to a frontend-only task. | Reversible: yes.

D-2 | How is decimal arithmetic done without floats? | **Native `BigInt` scaled integers inside the engine, strings at every boundary; no new npm dependency.** Four-decimal values are held as integers scaled by 10^4 (factors 10^6); products and quotients are done exactly in `BigInt`, rounded once by an explicit half-up primitive. | Reason: 14+4 digit amounts exceed 2^53, so `number` is unsafe; `BigInt` is in the platform and TypeScript 5.9; a decimal library (decimal.js and similar) adds a supply-chain dependency and its own rounding configuration to mis-set. | Reversible: yes (internal).

D-3 | What exactly is "half-up" in PHP? | **Round half away from zero** (Brick `RoundingMode::HalfUp`): ties go to the larger magnitude, including for negative intermediates. A rounding primitive must be tested on positive and negative ties because `BigInt` division truncates toward zero. | Reason: the PHP engine rounds with Brick `HalfUp`; the compound running sum and inclusive residue are the places a negative can be rounded. | Reversible: n/a (parity requirement).

D-4 | Where does the engine live and what may it import? | **Pure, framework-free TypeScript in the `shared` Angular library (`frontend/projects/shared`), exported through its public API, with no Angular, RxJS, DOM or Node imports.** Exact folder layout is the architect's. | Reason: both the till (`pos`) and the admin app import `shared`; a pure module is also usable from a service worker or a Web Worker later. | Reversible: yes (move a folder).

D-5 | Is the unit-resolution twin (`SnapshotCalculator`, `packages/catalog/fixtures/units/`) part of this task? T-029 D-21 and `docs/architecture.md` section 15 say FP-432 and FP-237 read those fixtures. | **No.** In scope: the five pricing fixture kinds only. The unit twin is a follow-up backlog row (lead to add), or lands with FP-237 where the replica needs it. The pricing engine takes `unit_factor` and `base_quantity` as plain inputs, as the PHP engine does. | Reason: FP-432's own row names tax, price-list and discount; the unit fixtures have a different kind (`unit`) and input shape and a different PHP class; combining them doubles the task with no shared code. Flagged owner-visible because section 15 reads as if they belong together. | Reversible: yes.

D-6 | What does the engine do with a rule value it does not recognise (a new tax type, a new discount type, a new `applies_to`, list type or scope the server added after this app build)? | **Throw a distinct `UnsupportedRuleError` naming field and value, before any arithmetic; never treat an unknown as zero, exclusive, percentage or "no discount".** The known `buy_x_get_y` stays the named refusal `unsupported_discount_type` at its place in the refusal order, exactly as PHP. The till decides what to do with the error (FP-236). | Reason: a wrong offline total is a financial document nobody authorised (architecture section 15, "never rejected"); failing closed is the only safe default. The shared refusal vocabulary is closed (17 values) and adding an 18th would need a PHP change. | Reversible: yes.

D-7 | Which failures are refusals and which are errors? | **Mirror PHP `Shape`**: a malformed money, quantity, rate or factor is the refusal `invalid_amount` (data a person can mistype); a malformed id, date, instant, currency or integer is a thrown programmer-error (built by code, never typed). Neither carries message text that tests may assert on. | Reason: parity with `Shape` and `PricingRefused` as built (`packages/contracts/src/Pricing/Shape.php`). | Reversible: yes.

D-8 | Does the TS side add refusal reasons or change any rule? | **No.** It carries the same closed 17-value vocabulary, including `unknown_product` and `unknown_tax_group` which only the PHP database adapter raises, so a server response can still be typed. | Reason: parity. | Reversible: n/a.

D-9 | What if replay shows a TS-PHP difference, or a fixture looks wrong? | **PHP and the fixtures are the authority; fix TS.** This task does not edit `packages/pricing/**`. A suspected wrong fixture or PHP bug is reported to the lead and fixed on the PHP side under the PHP gate. New shared vectors for TS-found hazards go into the PHP fixture set via a separate change. Meanwhile TS-only unit tests (rounding primitive, byte-order compare, BigInt precision, ban scans) are allowed in the frontend; they are not parity claims. | Reason: frontend-only task and one authority. | Reversible: yes.

D-10 | How are codes and ids ordered? | **UTF-8 byte order**, equivalent to PHP `strcmp`; not locale, not numeric, not UTF-16 code unit (JS `<` differs for non-BMP versus high BMP characters). Priorities, sequences and usage counts are integer strings compared as integers (BigInt), up to 64-bit signed. | Reason: T-029 D-29 ruled bytewise; the TS default would be wrong for some inputs. | Reversible: yes.

D-11 | Is the fixture lint duplicated in TS? | **Yes**, a TS lint equal to `FixtureReplay::lint` (strings only, full scale, key set, name equals file, kind equals directory) plus anti-vacuity checks and controls. | Reason: the TS gate must not trust the PHP gate to have run on the same files. | Reversible: yes.

D-12 | How is "no network, no clock, no floats" proven? | **Two instruments, each with a control**: a static scan of the engine's source files for banned tokens (named for what it scans, lists the files it scanned, fails on an empty set) and a runtime run of the full fixture set with `fetch`, `XMLHttpRequest` and `Date` replaced by throwing stubs. | Reason: project lesson (an instrument must be named by what it scans; a control on the wrong file reports success). | Reversible: yes.

D-13 | Is the TS engine synchronous? | **Yes**: returns a value or throws; no Promise or observable. | Reason: pure arithmetic; the till calls it inside its own state update. | Reversible: yes.

D-14 | Is the till wiring part of this task? | **No.** Library, tests and public API only; FP-236 (till) and FP-237 (offline) consume it. | Reason: FP-432 is the engine half. | Reversible: yes.

D-15 | Gate. | **`cd frontend && npm test`** only (no PHP gate, no `composer check`). The minimum fixture count is 30, matching the PHP anti-vacuity floor, and not a hard-coded larger number, so adding fixtures on the PHP side never breaks the TS gate. | Reason: instruction from the lead; a hard-coded count would make the TS gate fail on a legitimate PHP-side addition. | Reversible: yes.

D-16 | Dependency state: backlog still reads FP-231 as SPEC. | **Proceed; the engine and fixtures are in the tree.** The risk (the PHP side moving during this task) is handled by AC-33/AC-34 reading the live directory, so a later change is caught by re-running. | Reason: no reason to wait on a bookkeeping status. | Reversible: yes.

D-17 | Negative-tiny-rate residue (T-029 D-45) and other PHP behaviours as built. | **Copy PHP as built**, including refusing `out_of_range` for a negative residue on a tiny-rate member. | Reason: parity first; the owner has not ruled; both sides change together if they do. | Reversible: yes.

## Owner-visible questions (defaults built in)

1. Unit-resolution twin (D-5): default not in this task; separate follow-up row.
2. Till behaviour on an unsupported rule (D-6): default engine throws, till policy decided in FP-236.
3. "Generated from PHP" fixtures (D-1): default no generator; hand-derived fixtures proven by both replays.
4. A PHP-side guard pinning the refusal, tax type and discount type sets so the TS twin cannot be silently outgrown: default a recommended follow-up, not built here.
5. T-029 D-45 residue edge (D-17): default both sides identical as built.
