# QA Report: T-037 Till application (frontend, mock API)

Branch `ai/T-037-till-application`, tip 82cd8ac (plus one new QA spec file, uncommitted).

## Verdict: PASS (with one environment gap: the PHP `CiWorkflowShapeTest` could not be run, see below)

Zero defects. One coverage gap found and closed with a new spec (BUG-free; M6 below).

## Acceptance criteria coverage

AC -> test mapping derived from the `AC-n` tags in the spec names plus reading the spec bodies where a tag
covers a range. All specs named below passed in the full run.

| AC | Test file(s) | Result |
|----|---|---|
| AC-1, AC-2 | `shared/lib/sales/contract/contract-fixtures.spec.ts` (every fixture parses against key-exhaustive validators; forbidden-key list), `sales-api.spec.ts` (one method per call) | PASS |
| AC-3 | `contract/refusals.spec.ts` (closed union equals the literal list) | PASS |
| AC-4 | `contract-fixtures.spec.ts` (replay + exact JSON equality + coverage table) | PASS |
| AC-5 | `sales-mock/sales-mock.spec.ts` (state machine, one shift per terminal, single-use approvals, tenders, refunds) | PASS |
| AC-6 | `sales-mock/mock-decimal.spec.ts`; `money-rules.spec.ts` (mock never imported by app code) | PASS (no `AC-6` tag, mapped by content) |
| AC-7 | `panels/cart/ports-seam.spec.ts` (stub LINE_PRICER/BARCODE_RESOLVER), `money-rules.spec.ts` seam rules | PASS |
| AC-8 | Build + grep run by hand (below); CI step shape in `ci.yml` | PASS (manual); PHP shape test NOT RUN |
| AC-9 to AC-11 | `panels/shift-open-prompt/shift-open-prompt.spec.ts`, `state/decimal-input.spec.ts` | PASS |
| AC-12 to AC-15 | `dialogs/shift-menu/shift-menu-dialog.spec.ts`, `hold-and-resume`, `recall`, `payment` specs (no_open_shift routing) | PASS |
| AC-16 to AC-20 | `panels/product-grid/product-grid.spec.ts`, `panels/product-search/product-search.spec.ts` | PASS |
| AC-21 to AC-28 | `panels/scan-field/scan-field.spec.ts`, `state/scan-queue.spec.ts`, `dialogs/weight-prompt`, `dialog-host.spec.ts`, `till-page.spec.ts` | PASS |
| AC-29 to AC-38 | `panels/cart/cart.spec.ts`, `dialogs/{line-edit,price-override,discount-picker}` specs | PASS |
| AC-39, AC-40 | `dialogs/customer/customer-dialog.spec.ts`, `payment-dialog.spec.ts` | PASS |
| AC-41 to AC-50 | `dialogs/payment/payment-dialog.spec.ts`, `panels/completion-panel/completion-panel.spec.ts` | PASS |
| AC-51 to AC-54, AC-88 | `state/hold-and-resume.spec.ts`, `dialogs/recall`, `dialogs/confirm` specs | PASS |
| AC-55 to AC-58 | `dialogs/void/void-dialog.spec.ts`, `dialogs/find-sale/find-sale-dialog.spec.ts` | PASS |
| AC-59 to AC-64 | `dialogs/refund/refund-dialog.spec.ts` (AC-62 under the "AC-61 to AC-64" describe), `state/refund-store.spec.ts` | PASS |
| AC-65 to AC-71 | `dialogs/supervisor/supervisor-dialog.spec.ts` (+ stale-approval cases in price-override and discount-picker specs) | PASS |
| AC-72, AC-73, AC-76 | `errors/refusal-catalogue.spec.ts` | PASS |
| AC-74, AC-75 | `till-network-and-leave.spec.ts`, `state/connectivity.spec.ts`, `cart.spec.ts` (lost answer), **new** `state/payment-store-offline.spec.ts` | PASS |
| AC-77 | `money-rules.spec.ts` (canary proven live, below) | PASS |
| AC-78, AC-79 | `format/format.pipes.spec.ts`, `shared/.../decimal-string.spec.ts` | PASS |
| AC-80 to AC-82 | `keyboard/keymap.spec.ts`, `keyboard-e2e.spec.ts`, `ui/dialog-frame.spec.ts`, `dialog-host.spec.ts` | PASS |
| AC-83, AC-84 | `a11y.spec.ts`, `state/notifier.spec.ts` (accessible names, text-not-colour, live regions, 4 s minimum) | PASS for what jsdom can show; layout parts NOT VERIFIED |
| AC-85 | `product-grid.spec.ts` | PASS |
| AC-86 | `sales-api.spec.ts` and `contract-fixtures.spec.ts` forbid `branch_id`/`terminal_id`/`location_id`/`business_date` in bodies (no `AC-86` tag, mapped by content) | PASS |
| AC-87 | `till-network-and-leave.spec.ts` | PASS |
| AC-89 | Gate below | PASS |
| AC-90 | `till-page.spec.ts` plus untouched login/context/auth specs | PASS |
| AC-91 | README section present per plan (no automated test; documentation criterion, not re-read line by line in this run) | NOT VERIFIED by test |
| AC-92 | Fenced NOT VERIFIABLE by the spec | n/a |

## Test run summary

- `cd frontend && npm test`: pos 38 files / **533** passed (531 dev-reported + 2 from my new spec), admin 1 file / **2** passed, shared 13 files / **393** passed. 0 failed, 0 skipped. Before adding my spec the numbers were exactly the dev's: pos 531, admin 2, shared 393.
- `npx ng build pos`: exit 0. `npx ng build admin`: exit 0.
- `grep -l -e "demo@flexipos.test" -e "flexipos-sales-mock" dist/pos/browser/*.js dist/admin/browser/*.js`: prints nothing, exit 1.
- Positive control: `npx ng build pos --configuration development` (to a scratch output dir), same grep prints `main.js`; each sentinel individually occurs once in it. So the grep can fail.
- `frontend/dist` deleted afterwards; scratch dev bundle deleted.
- `grep -rn "\.only(\|\.skip(\|fit(\|xit(" projects`: prints nothing, exit 1.
- Money-rules and seam scans, canary check: planted `projects/pos/src/app/till/zz-qa-plant.ts` containing `parseFloat`, `Number(` and an import of `ServerLinePricer`. Result: 2 failures (`money rules (AC-77)` and `seam rules (AC-7)`). File removed; tree clean.
- PHP `CiWorkflowShapeTest`: **NOT RUN.** The worktree has no `modules/host/vendor`, and the main checkout's vendor is stale (it lacks the later packages and the path packages are symlinks into main). I copied it and rebuilt `vendor/flexipos` from the worktree's `packages/`, but the class map still lacked `Flexipos\Platform\...` and `module-demo`, and the only fix (`composer install`) needs the network, which was refused. The copied vendor was deleted (`git status` clean apart from my spec and the developer's memory notes). Static substitute: I read the T-037 step in `.github/workflows/ci.yml` (lines 296-318) against the assertions in `CiWorkflowShapeTest.php` (lines 615-670): it still contains the `demo@flexipos.test` literal, `for app in pos admin`, `dist/"${app}"/browser`, a `0) ... exit 1 ;;` arm and a `*) ... exit 1 ;;` arm, and sits after both builds. I expect it to pass, but this is a reading, not a run. The lead should run it where `vendor` is usable.

## Mutation table (production code, each restored by `git checkout`; tree verified clean after)

| # | Mutation | Result | Killed by |
|---|---|---|---|
| M1 | ScanQueue `concatMap` to `mergeMap` | KILLED (2) | `scan-queue.spec` AC-28 ordering, `weight-prompt-dialog.spec` "holds a later scan behind it" |
| M2 | Tender id regenerated on every submit (`if (true)`) | KILLED (3) | `payment-dialog.spec` lost-answer cases |
| M3 | Stale approval never restarts (`isStaleApproval` returns false) | KILLED (6) | supervisor, price-override, discount-picker specs |
| M4 | Mock no longer consumes an approval (single use) | KILLED (4) | void, refund, price-override, supervisor specs |
| M5 | Refund quantity cap removed (`> 99999`) | KILLED (4) | `refund-dialog.spec` AC-60, `refund-store.spec` |
| M6 | `addTender` offline guard removed | **SURVIVED** the shipped suite; KILLED (1) by my new `payment-store-offline.spec` | new spec |
| M6b | `removeTender` offline guard removed | KILLED (1) by new spec | new spec |
| M6c | `complete` offline guard removed | KILLED (1) by new spec | new spec |
| M7 (first try) | notifier refusal-first, line 51 edit | invalid mutant (TS2367 compile error), discarded | n/a |
| M7a | notifier: a note delays an arriving refusal (`&& false`) | KILLED (3) | `notifier.spec`, `a11y.spec`, `scan-queue.spec` |
| M7b | notifier minimum display 4000 ms to 1000 ms | KILLED (1) | `notifier.spec` "still shows a refusal at 3.9 seconds" |
| M8 | Leave-confirm skipped for every status | KILLED (3) | `till-network-and-leave.spec` |
| M11 | Sign-out confirm skipped (`if (false)`) | KILLED (2) | `till-network-and-leave.spec` |
| M9 | Void of a completed sale skips the approval path | KILLED (4) | `void-dialog.spec` |
| M12 | Void reason dropped from the approved void call | KILLED (2) | `void-dialog.spec` |
| M10 | Refund id and tender ids regenerated on each submit | KILLED (1) | `refund-dialog.spec` "keeps the ids and the approval" |
| M13 | Cart `ready()` offline guard removed | KILLED (2) | `till-network-and-leave.spec` |
| M14 | Reload-after-lost-line-write dropped | KILLED (3) | `cart.spec` AC-75, `hold-and-resume.spec` |

Totals: 18 valid mutants (counting M6/M6b/M6c separately), 17 killed by the shipped suite, 1 survivor (M6 family) now killed by a new spec. One invalid mutant discarded. Driver script is in the scratchpad, not the repo.

## Defects

None.

### Coverage gap closed (not a production defect)
- The payment store's own offline guards (`addTender`, `removeTender`, `complete`) had no direct test; the dialog also disables the buttons while offline, so only the UI layer was covered. New spec: `frontend/projects/pos/src/app/till/state/payment-store-offline.spec.ts` (2 tests, both fail when a guard is removed).

## Regression risks / notes

Known caveats from the brief, reported as caveats and not as bugs:
- AC-44 vs AC-76 `exceeds_balance` wording: the catalogue keeps AC-76 ("More than the balance"), the payment dialog uses AC-44 text ("Amount is more than the balance"); the refund dialog uses AC-64 ("More than is owed back"). Three wordings for one code; the spec contradicts itself.
- The mock seed has no sales; the specs build state through named `given` scenarios.
- `numbering_refused` has no mock trigger (no fixture); the message exists in the catalogue (spec iterates every code) but no end-to-end path exercises it.
- A lost-response void does not reload the sale automatically (follow-up).

NOT VERIFIED (cannot be shown in jsdom or without a real backend):
- AC-19 44x44 px tile size and visible focus ring; AC-83 WCAG contrast and the 1024x768 no-horizontal-scroll layout. The spec's own plan sets these to SCSS review; I did not review the SCSS.
- AC-84 audibility to a real screen reader (only the live-region roles and text are asserted).
- AC-91 README content (documentation criterion, not tested, not re-read here).
- AC-92 (real Sales routes, scanner, scale, cash drawer, touch display) is fenced by the spec.
- PHP `CiWorkflowShapeTest`: not run (see above).
- Performance claims (100 ms acknowledgement, 200-line cart) are asserted only as "no input lost" in jsdom, not timed.

Other notes: `ng test --include=<glob>` was unusable for focused runs here (no tests matched, then a vitest worker timeout under load); mutation runs used the full `ng test pos` (about 60 s each) instead. A concurrent PHP process on the machine slowed runs but caused no failure.

History: Run 1: PASS.
