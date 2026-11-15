# QA Report: T-030 (FP-233) Sales: cart, tenders, hold and recall, refunds, voids, cash shifts

Branch `ai/T-030-sales`, tip `6681adc`, QA round 1. Spec revision 6 (AC-1 to AC-58, 23a, 12A, 12B, 14A, 24A, 27A, 47A, 47B), decisions D-1 to D-77, plan revision 5 with its "As built" notes.

## Verdict: FAIL

1 defect (major). One acceptance-criteria clause is broken in the built code (BUG-1, an approval bypass). The two QA tests that prove it are left in the suite on purpose, so the gate is red by exactly those two tests on top of the 13 baseline failures.

Everything else held. 147 mutation runs over the safety-critical rules: 141 killed, 6 survived the shipped suite (4 real gaps, now closed by QA tests that go red under the mutant; 2 equivalent). Every production file was restored byte for byte after every run (`cmp` identical, 147 of 147), and `git status` shows only the new `packages/sales/tests/Qa/` directory.

Defects by severity: 0 critical, 1 major, 0 minor.

## Test run summary

Mutation work used targeted `pest` runs of `packages/sales/tests` subtrees, one process at a time, nothing racing. Baseline of the whole Sales package before any mutation: **813 passed (3251 assertions), 97 s**.

Gate, run once at the end by QA with `APP_KEY` empty and nothing else racing it:

- Command: `cd modules/host && composer check` redirected to a log, exit code read from its `EXITCODE:` line: **`EXITCODE: 2`**.
- `probes:clean`, `engine:clean` ran (the engine suite is on hold). Pint `--test`: passed. PHPStan: `[OK] No errors`. Deptrac: 0 violations, 0 errors.
- Test step: **15 failed, 55 skipped, 5142 passed (32969 assertions), 1216.11 s.**
- The 15 failures by name: the 13 baseline (`QaModuleRegistryContractTest` 6, `QaModuleNameValidationTest` 3, `QaHealthProbeResilienceTest` 2, `ModulesEnvParsingTest` 1, `QaVocabularyRenameSweepTest` 1) plus the 2 BUG-1 tests in `packages/sales/tests/Qa/QaSalesEndToEndTest.php`. Nothing else failed.
- Against the last measured 13 failed / 55 skipped / 5133 passed: skipped is unchanged and passed is +9, the 9 green QA tests (11 QA tests in all, 2 red on purpose). After BUG-1 is fixed the gate should read 13 failed / 55 skipped / 5144 passed.

## Hand-computed monetary cases (worked before reading the code's answer)

All four are permanent QA tests in `packages/sales/tests/Qa/QaSalesEndToEndTest.php`. Each expected figure is derived in the comment above its test, not read back from the code, and all four matched on the first run.

| Case | Hand result | Code |
|---|---|---|
| Inclusive-tax sale: 3 x 24.0000 on a 10 % inclusive group | gross 72.0000; net 72 / 1.1 = 65.454545 -> 65.4545; tax 72 - 65.4545 = 6.5455 (residue on the last member); line_total 72.0000, sale tax_total 6.5455 | matches |
| Change-giving cash tender in a split: card 30, then cash 50 against the 42 still owing | cash amount 42.0000, tendered 50.0000, change 8.0000; paid_total 72.0000, change_due 8.0000, number S000001 | matches |
| Shift close and reconcile variance (opening 100.0000; the card never touches the drawer; cash counts by AMOUNT, not tendered) | expected 100 + 42 = 142.0000 (a mutant counting `tendered` says 150); counted 135.5 -> variance -6.5000; gross 72.0000, discounts 0.0000, tax 6.5455, net 72.0000; by method CARD 30.0000 / CASH 42.0000; a supervisor holding the key marks it `reconciled` and the figures are untouched | matches |
| Partial refund of an overridden inclusive line: 3 units sold at an approved override of 7.3333 against a list of 24.0000, then refund 1, then refund 2 | original subtotal 21.9999, net 19.9999, tax 2.0000. Refund 1: 7.3333 / 1.1 = 6.666636 -> 6.6666, tax 0.6667, line_total 7.3333, original `partially_refunded`. Refund 2: 14.6666 / 1.1 = 13.333273 -> 13.3333, tax 1.3333, line_total 14.6666, original `refunded`. 7.3333 + 14.6666 = 21.9999 = exactly what was charged; a third refund is `fully_refunded` | matches |

The shipped hand-checked rows were re-derived independently too. `CloseCashShiftTest` mixed shift: expected 100 + 110 - 110 + 50 - 20 = 130.0000, variance -5.0000, gross 300.0000, tax 30.0000, net (110 + 220) - 110 = 220.0000, CARD 220.0000, CASH 110 - 110 = 0.0000, the voided sale's tenders excluded because they net to zero. `SaleRollupTest`: 179 - 17.9 = 161.1, tax 15 % = 24.165, total 185.265. Both are correct.

## Acceptance criteria coverage

"Mut" = QA mutated production code and the named tests went red (every mutation restored with `cmp`; ids refer to the appendix). "Read" = QA read the test and its assertions, judged it discriminating, and did not mutate that clause. Test paths are under `packages/sales/tests/` unless a package is named.

| AC | Test(s) | Discriminates |
|----|---------|---------------|
| AC-1 | `Features/CreateSale/CreateSaleTest`: opens a draft (branch, location, shift, currency, business date, server source), business date from the branch timezone, `no_open_shift`, customer unknown / soft-deleted / merged is `not_found`, the shift-closed-between-read-and-lock race row | Read |
| AC-2 | `Features/AddSaleLine/AddSaleLineTest`: unit asked for (factor 24.000000, base 24), snapshots survive a rename, salesperson validated against the employee directory | Read |
| AC-3 | same file: `unit_unresolvable` naming both units | Read |
| AC-4 | same file: every priced column stored as the engine returned it; the engine is asked with the sale's instant, business date, currency, customer, group and branch | Read; A6 to A8 kill the rollup over them |
| AC-5 | same file: `unresolvable_price` through the fake engine, every other refusal under its own name with the cause, a real `unknown_discount` | Read |
| AC-6 | `AddSaleLineTest` locked-lines dataset (pending_payment and held-from-pending locked; draft and held-from-draft editable), `UpdateSaleLineTest`, `RemoveSaleLineTest`, `RecallSaleTest` lines-editable-after-recall, QA `QaSalesGapsTest` latest-hold row | Mut: H2, H3. H1 (oldest hold read) survived the shipped suite and is killed by the QA row |
| AC-7 | `Features/AddSaleLine/SaleLineApprovalsTest` (override with approval, none, payload mismatch, not allowed, `discount_with_override`, out_of_range, inclusive group, fixed tax on base quantity); QA real-chain override | Mut: A1, A2, A5, G5, LW1, LW2, LW3, LW4. **BUG-1: a second line is accepted without its own approval** |
| AC-8 | `Sales/SaleRollupTest`, `AddSaleLineTest` re-sum after every add, `RemoveSaleLineTest` | Mut: A6, A7, A8 |
| AC-9, AC-10 | `AddSaleLineTest` never-an-input fields dataset and the null-cost / `stock_applied = 0` assertions; `Schema/SalesTenantSchemaTest` | Read |
| AC-11 | `Features/AddTender/AddTenderTest`: change on cash (50 against 45), split payment, credit needs a customer and posts no entry, the F-22 implicit-recall rows, caller id kept | Mut: T1, T2, T3, T5, T13 |
| AC-12 | `Tenders/TenderRulesTest` (loyalty and voucher unsupported, inactive method, reference, partial), `AddTenderTest` | Mut: T6, T8, T10, T11, T12, T14 |
| AC-12A | `TenderRulesTest` (refusal on `allows_refund = false`, not consulted on a sale tender), `RefundSaleTest` tenders describe | Mut: T7 |
| AC-12B | `TenderRulesTest` "gives no change on a refund", `RefundSaleTest` tender-over-balance dataset and the zero-total-refund row, override row (j) | Mut: T4, T9, R17 |
| AC-13 | `Features/RemoveTender/RemoveTenderTest` (row deleted, last tender returns the sale to draft, credit included) | Read |
| AC-14 | `Features/CompleteSale/CompleteSaleTest`: `balance_due`, exact balance, credit_sale per credit tender keyed on the tender, ascending discount order, numbering, usage limit, status table | Mut: C1, C3 to C16, C18. **C2 (underpay-only check) survived**: AddTender cannot overpay, so no shipped row reached it; the QA overpaid-sale row now kills it. C17 is equivalent (paid equals total once the check has passed) |
| AC-14A | `CompleteSaleTest` "a zero-total sale takes the ordinary path" (numbered in sequence, discount consumed once, one audit row, empty-lines refusal) | Mut: C4, C8, C9 |
| AC-15 | `CompleteSaleTest` "writes no stock and no ledger of any other kind"; `modules/host/tests/Feature/QaSalesSourceRulesTest` AC-15/AC-31 scan | A scratch file naming `stock_movements` turned the scan red |
| AC-16 | `Features/CreatePaymentMethod`, `UpdatePaymentMethod`, `DeletePaymentMethod` tests (in-use guard by status dataset, deferred columns refused) | Read |
| AC-17, AC-18, AC-19 | `HoldSaleTest`, `RecallSaleTest` (restores the recorded status; terminal and shift move, cashier does not; `no_open_shift`; three trail rows) | Mut: H1 (gap, closed), H2, H3 |
| AC-20 | `Features/ListSales/ListSalesTest` "a sale held at terminal A for the whole branch, nothing of another branch", cursor paging | Read |
| AC-21 | `Features/VoidSale/VoidSaleTest` "before completion": no approval, one negative mirror per tender, originals byte-identical, no ledger row | Mut: V2, V10, V11 |
| AC-22 | `VoidSaleTest` "a completed sale": approval required, mirrors, `credit_note` keyed on the mirror, `shift_closed`, replayed ledger row, merged customer; QA real-chain void | Mut: V1, V3 to V9, V12, L4. The QA row proves the discount `used_count` is NOT released on a void (no shipped row asserted it) |
| AC-23 | `RequestApprovalTest`, `DecideApprovalTest` (PIN verified first and outside a transaction, self-approval, permission at the branch, null scope) | Mut: D1 to D8 |
| AC-23a | `SaleLineApprovalsTest` gated-discount rows; QA real-chain gated discount | Mut: LW5. **BUG-1 applies** |
| AC-24 | `Approvals/ApprovalGateTest` plus the consuming slices' refusal rows | Mut: G1, G2, G4 to G10, L5. **G3 (reference_type) survived**: the shipped row changed type and id together; the QA row isolates the type and kills it |
| AC-24A | `ApprovalGateTest` branch row, `VoidSaleTest` and `SaleLineApprovalsTest` seeded-at-another-branch rows, `RefundSaleTest` "passes the refund's own branch" | Mut: G1 |
| AC-25 | `RefundSaleTest` "writes a refund row numbered R000001 ..." and the goodwill row | Mut: R18 |
| AC-26 | `RefundSaleTest` "the unit of a refund line" (inherited factor after `unit_conversions` changes, stock unit factor 1, third unit refused, goodwill through the ladder); owner suite `packages/catalog/tests/Units/SnapshotInheritedTest` | Read |
| AC-27 | `RefundSaleTest` "the price of a refund line" (quantity tier, original `completed_at`, goodwill at now) | Mut: R5, R6, R7 |
| AC-27A | `RefundSaleTest` describe "an overridden line" rows (a) to (k); QA real-chain two-part inclusive refund | Mut: R1, R2, R3, R5, R6, LW2 (the plan's M12 to M14 reproduced) |
| AC-28 | `RefundSaleTest` "the cap on what can be refunded" | Mut: R8 to R11, R15, R25, R27. R12 is equivalent: with every line fully refunded the status is already `refunded`, and a drifted row still refuses as `over_refund` |
| AC-29 | `RefundSaleTest` "the approval" describe, `DecideApprovalTest` goodwill row | Mut: R19, R20, R21, D1, D2 |
| AC-30 | `RefundSaleTest` tenders and credit-refund describes | Mut: R13, R16, R17, R26 |
| AC-31 | `RefundSaleTest` "writes no stock, no general-ledger row and no usage release" | Read |
| AC-32 | `Features/OpenCashShift/OpenCashShiftTest` (numbering, `shift_already_open`, unique-key race row) | Mut: N1, N2 |
| AC-33 | `Features/RecordCashMovement/RecordCashMovementTest` | Mut: S21, S22, S23 |
| AC-34 | `CloseCashShiftTest` blocks and variance rows | Mut: S4, S8, S8b, S8c, S14 |
| AC-35 | `CloseCashShiftTest` hand-checked mixed shift, `Shifts/ShiftTotalsTest` | Mut: S1 to S3, S5 to S7, S9 to S12, S15 to S18 |
| AC-36 | `Features/ReconcileCashShift/ReconcileCashShiftTest`; QA real reconcile | Mut: S19, S20 |
| AC-37 | `RecordCashMovementTest` replay rows, `CloseCashShiftTest` replay, `ReconcileCashShiftTest` no-op, `AddTenderTest` idempotency describe | Mut: S13, S22, S23, I1, I2, I4, I5. **I3 (replayed with only the method changed) survived**: the shipped row changed the reference too; the QA row isolates it |
| AC-38 | `packages/catalog/tests/Units/SnapshotInheritedTest`, `UnitFixtureReplayTest` (5 inherited fixtures) | Read (owner suite) |
| AC-39 | `packages/access/tests/Pins/HandlerPinVerifierTest` (indistinguishable null; the rolled-back counter hazard pinned) | Read (owner suite) |
| AC-40 | `packages/organization/tests/Numbering/DocumentNumbersTest` | Read (owner suite) |
| AC-41 | `packages/pricing/tests/Discounts/DiscountUsageTest` (exactly `usage_limit` uses succeed) | Read (owner suite) |
| AC-42 | `modules/host/tests/Feature/QaSalesSourceRulesTest` (a), (b), the AC-27A static half, (c), each with a planted control | Planted `$a * $b`, `$a / $b`, `Money::product(`, a `tax_rates` token and `stock_movements` in a real file under `packages/sales/src`: 5 rows went red; file removed |
| AC-43 | `Schema/SalesTenantSchemaTest` exact column sets for all eight tables; `TenantDecimalPrecisionTest` `t030DeferredColumnOffenders` with a scratch-migration control | Read; grep of the ten migrations finds no `tenant_id` and no float or double column |
| AC-44 | `modules/host/tests/Feature/TenantDecimalPrecisionTest` (the T-030 map, red-first control with a wrong pair); only (18,4), (18,6), (9,4), (18,8) are used | Read |
| AC-45 | `Schema/LoyaltySaleIdMigrationsTest` (8 rows: expand, contract, dirty data refused naming a count) | Read |
| AC-46 | `SalesTenantSchemaTest`, plus the slices that read `sale_holds`, `consumed_at`, `subtotal` | Read |
| AC-47 | `AddSaleLineTest` "refuses a product that is not sellable" (three facts), `UpdateSaleLineTest` | Read |
| AC-47A, AC-47B | `RefundSaleTest` "the sellability exemption" (goodwill and referenced, three facts each) | Read. `RefundSaleHandler` never calls the check, so there is no line to mutate; the rows are the proof |
| AC-48 | `CompleteSaleTest`, `VoidSaleTest`, `RefundSaleTest` "refuses a replayed posting as credit_refused carrying replay_conflict" | Mut: C13 |
| AC-49 | `CompleteSaleTest` "refuses a customer with no currency, or another one" | Read |
| AC-50 | numbering describes in `CompleteSaleTest`, `RefundSaleTest`, `OpenCashShiftTest` (a real yearly refusal included) | Mut: C10, R18, N1 |
| AC-51 | `HoldSaleTest`, `RecallSaleTest`, `CompleteSaleTest` invalid_transition datasets; `SaleRollupTest` out_of_range | Mut: C14, C15, C16, A5 |
| AC-52 | `AddSaleLineTest`, `UpdateSaleLineTest`, `RefundSaleTest` zero-quantity rows | Read |
| AC-53 | `RefundSaleTest` live-state dataset: `usage_limit_reached`, `inactive`, `unknown_discount`, `discount_not_allowed`, `unknown_product` | Read. `not_applicable` is in the AC's reason list but has no dataset row and no scenario in the AC maps to it; noted, not a defect |
| AC-54 | `RefundSaleTest` customer copy, goodwill-customer describe, other-branch `not_refundable` | Mut: R14, R28 |
| AC-55 | `SaleLineApprovalsTest` re-price rows | Read. **Over-broad: BUG-1** |
| AC-56 | `AddTenderTest` "adds the tender again when it was removed in between" | Read |
| AC-57 | `RefundSaleTest` promotion-ended and group-changed rows | Read |
| AC-58 | `CompleteSaleTest` "still consumes a discount that was deactivated", `DiscountUsageTest` | Read |

### Mutation work by rule

| Rule | Mutation ids |
|---|---|
| Money arithmetic | A1 to A9, LW1, LW4 |
| Tender rules | T1 to T14 |
| Approval binding, branch and single consumption | G1 to G10, V6, V8, R19 to R21, L5 |
| Refund price paid (AC-27A) | R1, R2, R3, R5, R6, R7, LW2 |
| Over-refund and refund status | R8 to R12, R14, R15, R25, R27 |
| Void rules | V1 to V12 |
| Cash shift totals | S1 to S18 |
| Lock order | C5, C6, L1 to L5, R23, R24, S14 |
| Idempotent replay | I1 to I5, S13, S22, S23 |
| Zero-total completion | C4, C8, C9 |
| Document numbering | C10, R18, N1 |
| Deadlock retry | C11, R22, N2 |

The full result list is the appendix.

## Coverage gaps closed by QA (new tests in `packages/sales/tests/Qa/`)

`QaSalesGapsTest.php` (5 rows, all green) closes the four real survivors:

- C2: an overpaid pending sale (a raw tender of 12.0000 on an 11.0000 sale) is refused `balance_due`, writing nothing.
- G3: an approval whose `reference_type` alone differs (same id) is `approval_mismatch` and stays unconsumed, both ways round.
- I3: a tender replayed under the same id with only the payment method changed is `replay_conflict`.
- H1: a sale held from draft, recalled, tendered and held again from `pending_payment` is locked while held and recalls to `pending_payment` (the latest hold decides).

`QaSalesEndToEndTest.php` (4 green rows, 2 red rows that prove BUG-1): the real RequestApproval -> DecideApproval (real PIN, real branch permission) -> consuming write chain for a price override, a void, a referenced refund, a goodwill refund and a gated discount (every shipped consuming-write test seeds its approval row by hand); the void-never-releases-usage clause; and the four hand-computed monetary cases above. Pint `--test` passed on both files and PHPStan passed in the gate.

## Defects

### BUG-1 (major)
- AC: AC-7, AC-23a, AC-24 (AC-55 and D-59 are the clause that was widened).
- Failing tests: `packages/sales/tests/Qa/QaSalesEndToEndTest.php` "refuses a SECOND new line at an override price without its own approval, as approval_required" and "refuses a SECOND new line with a gated discount without its own approval, as approval_required". Through the real chain one `price_override` approval (product P, unit U, price 8.0000) is requested, decided by PIN and consumed by line 1. Then `AddSaleLine` adds a new line of 100 units of P in unit U at `unit_price` 8.0000 with **no** `approval_id`.
- Expected: `approval_required`. AC-7: an override is "accepted only when accompanied by a decided approval_requests row". AC-24: a consumed approval is single-use. AC-55 and D-59 relax this only for `UpdateSaleLine` re-pricing **the same line** ("an approval already consumed by this sale for the same line, discount and override price").
- Actual: the second line is accepted, priced at 8.0000, `price_overridden_by` is set to the first approval's approver, and no approval is consumed. The same happens for a gated discount (AC-23a), and for a removed-and-re-added line, or an `UpdateSaleLine` that turns an ordinary line into an overridden one. One supervisor approval therefore authorises any number of override or discounted lines, in any quantity, in that sale.
- Suspected location: `packages/sales/src/Approvals/LineApprovals.php`, `cover()` and `priorConsumed()`. The prior-consumption lookup matches by sale, type, branch and payload only, not by line, and it is reached from `AddSaleLineHandler` (lines 107 and 109) as well as from `UpdateSaleLineHandler`. The fix is to allow that path only from `UpdateSaleLine`, for the line that carries it (record which line consumed the approval, or compare with that line's own stored override price or discount). Deleting the path altogether is wrong: two shipped rows need it for the same-line re-price (`SaleLineApprovalsTest` "lets a re-price keep an approval this sale already consumed" and the gated-discount re-price row), and mutation LW5 (path deleted) reddened exactly those two.
- If the lead rules the sale-wide reading intentional, AC-55 and D-59 must be reworded ("covers any line of this sale with the same payload") and the two QA tests flipped; as written they contradict the code.

## Regression risks / notes

- **Shared tests edited by T-030, all justified.** `QaAccessHttpSurfaceTest` (host): the exact-equality list of branch-scoped permission keys now includes `sales.approvals.decide` and `sales.shifts.reconcile`. D-69 mandates them as the first real branch-scoped keys; the plan predicted "no edit" (row 1480) and was wrong, but only that one assertion changed and the route refusal below it is untouched. It is a hard-coded list, so the next module that declares a branch-scoped key reddens it again; a subset assertion would be sturdier (minor, not filed). `CustomersTenantSchemaTest`: T-028's AC-33 absence of `loyalty_transactions.sale_id` is inverted to presence and the foreign-key count goes 17 to 18, required by AC-45 and covered by `LoyaltySaleIdMigrationsTest`. `AuditCatalogueTest`: `sales` joins the two module lists, a pure consequence of the new audit-action provider. Also on the predicted-red list and checked: `IdempotencyKeysSchemaTest` (catalogue top 63 to 73), `MigrationTreeDisciplineTest` (58 to 66 tables), `QaToolchainRegistryCoverageTest`, `TenantBaselineBuildsTest`, `TenantDecimalPrecisionTest`.
- **Q-1 default** (refuse with the engine's reason, write nothing) is what `RefundSaleHandler` does; five of the six reasons AC-53 names have a dataset row. **Q-2 default** (accept a `0.0000` line from an unpriced product) is what `AddSaleLine` does (`AddSaleLineTest` "accepts a product nobody priced, quoting 0.0000"). Both stay owner questions; the rows named above change with the ruling.
- **Money and decimals.** Every money and quantity column is a DECIMAL at one of the four allowed pairs; no `tenant_id` and no float or double column in any of the ten migrations; no `bcmul`, `intdiv`, `round()`, `%` or `**` in `packages/sales/src`; the only multiplication is `Money::product()`, called from `LineWriter` only; result objects carry money as strings. Two `(float)` / `number_format` conversions exist (`Money::of()` for a float input and `RefundSaleHandler::factor()`), both only for the SQLite stand-in that hands a DECIMAL back as a number (R-8); MySQL returns a string, so they are not reached in production. Not a defect.
- **Sales never touches stock or the general ledger.** Writes go only to Sales' own eight tables (read-only access to `products`, `discounts`, `customers`, `terminals`, with a lock on `customers`); no `stock_` or `journal_` token apart from the two allowed column names; `sales.stock_applied` stays 0 and `unit_cost` stays null (asserted).
- **deptrac.** Baseline 0 violations. A planted file in `packages/sales/src` importing `Flexipos\Catalog\Units\DatabaseUnitResolver` and `Flexipos\Pricing\Engine\PriceLadder` produced 2 violations; the file was removed.
- **NOT VERIFIED (ENGINE_HOLD, no real MySQL).** `modules/host/tests/Engine/SalesSchemaEngineTest` runs one of its eight cases and the other seven are skipped. The lock order is proven as SQL text on the stand-in (which compiles no lock clause), and the deadlock retry (`transaction(..., 2)`) is proven only against a faked deadlock; neither is proven on a real engine. The plan's two-connection `CompleteSale` row was not built (the developer records why). `usage_limit_reached` after a concurrent last use and the shift-versus-sale lock race are likewise stand-in only.
- **Documented, accepted risks seen again:** a held-from-pending sale with a cash tender is counted in the drawer that completes it (risk 6); a customer merged after a credit sale makes the void or refund `credit_refused` (risk 7); an overridden line refunds only in its own unit (risk 14); a completion refused at commit leaves a locked `pending_payment` sale, and `CompleteSaleTest` walks the way out.
- **Equivalent mutants:** C17 (`paid_total` taken from the total instead of the tender sum) and R12 (the per-sale `fully_refunded` check; the next line refuses the same state as `over_refund`).
- **Mutation-driver artefact:** G10 ran as a fatal under the combined test list (rc 255, no summary) and was re-run on `ApprovalGateTest` alone, where it goes red in 2 tests; the table shows that figure.
- Two QA tests are red on purpose (BUG-1) and go green when `LineApprovals` is fixed.

## Appendix: mutation run results

Each row: a production edit, the targeted test subtree run, the number of tests that went red, and the file restored and compared byte for byte afterwards (`cmp` identical for all 147). Ids ending `-recheck` re-ran a surviving mutant against the QA test file.

| ID | Mutation | Result | Tests red |
|----|----------|--------|-----------|
| A1 | product rounds Down not HalfUp | KILLED | 3 |
| A2 | product adds instead of multiplies | KILLED | 25 |
| A3 | subtract plus | KILLED | 11 |
| A4 | negate is identity | KILLED | 8 |
| A5 | fits always true | KILLED | 3 |
| A6 | rollup total sums subtotal | KILLED | 9 |
| A7 | rollup tax sums discount | KILLED | 6 |
| A8 | rollup discount sums tax | KILLED | 2 |
| A9 | compare reversed | KILLED | 23 |
| T1 | over-balance > to >= | KILLED | 3 |
| T2 | change reversed | KILLED | 4 |
| T3 | change branch amount=offered | KILLED | 3 |
| T4 | refund gives change | KILLED | 3 |
| T5 | partial < to <= | KILLED | 1 |
| T6 | partial never refused | KILLED | 1 |
| T7 | allows_refund unchecked | KILLED | 3 |
| T8 | reference never required | KILLED | 4 |
| T9 | no-balance check removed | KILLED | 3 |
| T10 | loyalty and voucher tenderable | KILLED | 5 |
| T11 | loyalty tenderable | KILLED | 3 |
| T12 | inactive method accepted | KILLED | 3 |
| T13 | credit tender needs no customer | KILLED | 1 |
| T14 | zero offer accepted | KILLED | 1 |
| C1 | balance check removed | KILLED | 3 |
| C2 | underpay only refused | SURVIVED | 0 |
| C3 | overpay only refused | KILLED | 3 |
| C4 | empty sale completable | KILLED | 2 |
| C5 | discount consume order reversed | KILLED | 1 |
| C6 | discount sort dropped | KILLED | 1 |
| C7 | discount distinct dropped | KILLED | 1 |
| C8 | zero total skips discount consume | KILLED | 1 |
| C9 | zero total skips numbering | KILLED | 1 |
| C10 | number from refund sequence | KILLED | 8 |
| C11 | retry attempts 1 | KILLED | 2 |
| C12 | change_due zero | KILLED | 1 |
| C13 | credit_sale posting skipped | KILLED | 7 |
| C14 | refund doc completable | KILLED | 1 |
| C15 | held completable | KILLED | 1 |
| C16 | completed completable twice | KILLED | 1 |
| C17 | paid_total = total not sum | SURVIVED (equivalent) | 0 |
| C18 | credit posted for non-credit too | KILLED | 14 |
| G1 | branch binding dropped | KILLED | 4 |
| G2 | type binding dropped | KILLED | 4 |
| G3 | reference_type dropped | SURVIVED | 0 |
| G4 | reference_id dropped | KILLED | 5 |
| G5 | payload binding dropped | KILLED | 7 |
| G6 | consumed_at never written | KILLED | 14 |
| G7 | already-used check dropped | KILLED | 5 |
| G8 | status check dropped | KILLED | 6 |
| G9 | unknown approval row check dropped | KILLED | 2 |
| G10 | decided_by returns requested_by | KILLED (ApprovalGateTest alone) | 2 |
| V1 | shift-closed check removed | KILLED | 2 |
| V2 | mirror same sign | KILLED | 2 |
| V3 | completed void needs no approval | KILLED | 8 |
| V4 | credit note skipped | KILLED | 4 |
| V5 | refunded sale voidable | KILLED | 2 |
| V6 | void approval type refund | KILLED | 8 |
| V7 | refund document voidable | KILLED | 1 |
| V8 | approval not bound to sale id | KILLED | 7 |
| V9 | status stays completed | KILLED | 4 |
| V10 | mirror only first tender | KILLED | 2 |
| V11 | voided_by not set | KILLED | 2 |
| V12 | completed flag always true | KILLED | 3 |
| R1 | overridden branch dropped (M12) | KILLED | 13 |
| R2 | unit check dropped (M14) | KILLED | 1 |
| R3 | override refunded at list price | KILLED | 13 |
| R5 | anchor instant = now | KILLED | 2 |
| R6 | anchor date = today | KILLED | 1 |
| R7 | original discount ignored | KILLED | 7 |
| R8 | over_refund > to >= | KILLED | 42 |
| R9 | over_refund removed | KILLED | 1 |
| R10 | refunded_quantity overwritten not accumulated | KILLED | 3 |
| R11 | all-refunded < to <= | KILLED | 7 |
| R12 | fully_refunded check dropped | SURVIVED (equivalent) | 0 |
| R13 | refund tender positive | KILLED | 5 |
| R14 | branch check dropped | KILLED | 1 |
| R15 | partially_refunded not refundable | KILLED | 5 |
| R16 | unpaid remainder accepted | KILLED | 2 |
| R17 | refund flag false in TenderRules | KILLED | 3 |
| R18 | refund number from sale sequence | KILLED | 6 |
| R19 | refund approval type void | KILLED | 57 |
| R20 | referenced refund bound as goodwill | KILLED | 50 |
| R21 | approval not consumed (gate call dropped) | KILLED | 11 |
| R22 | deadlock attempts 1 | KILLED | 2 |
| R23 | original sale not locked | KILLED | 1 |
| R24 | shift not locked | KILLED | 1 |
| R25 | original status not updated | KILLED | 8 |
| R26 | paid_total of the refund zero | KILLED | 1 |
| R27 | fully-refunded vs not_refundable not distinguished | KILLED | 1 |
| R28 | goodwill customer dropped | KILLED | 1 |
| S1 | cash filter -> card | KILLED | 11 |
| S2 | settled-status filter dropped | KILLED | 4 |
| S3 | movement sign inverted | KILLED | 10 |
| S4 | variance sign | KILLED | 3 |
| S5 | opening cash ignored | KILLED | 24 |
| S6 | shift filter dropped (all shifts) | KILLED | 2 |
| S7 | movements not filtered by shift | KILLED | 1 |
| S8 | draft only blocks close | KILLED | 1 |
| S8b | pending only blocks close | KILLED | 1 |
| S8c | held blocks close | KILLED | 1 |
| S9 | net adds refunds | KILLED | 1 |
| S10 | sales_count counts refunds too | KILLED | 1 |
| S11 | gross sums total | KILLED | 2 |
| S12 | by-method uses tendered | KILLED | 1 |
| S13 | replay conflict never raised | KILLED | 2 |
| S14 | close lock dropped | KILLED | 1 |
| S15 | refunds_count always zero | KILLED | 1 |
| S16 | tax sums discount | KILLED | 1 |
| S17 | counted_cash not stored | KILLED | 4 |
| S18 | closed_by not stored | KILLED | 1 |
| S19 | reconcile permission dropped | KILLED | 4 |
| S20 | reconcile open shift allowed | KILLED | 1 |
| S21 | movement shift-open check dropped | KILLED | 4 |
| S22 | movement replay amount compare dropped | KILLED | 2 |
| S23 | movement replay never returns original | KILLED | 1 |
| N1 | shift number from sale sequence | KILLED | 4 |
| N2 | open shift attempts 1 | KILLED | 2 |
| I1 | tender replay lookup dropped | KILLED | 5 |
| I2 | tender replay amount compare dropped | KILLED | 2 |
| I3 | tender replay method compare dropped | SURVIVED | 0 |
| I4 | tender replay sale compare dropped | KILLED | 1 |
| I5 | tender replay reference compare dropped | KILLED | 1 |
| L1 | tender method lock dropped | KILLED | 1 |
| L2 | sale lock dropped (lockSale) | KILLED | 9 |
| L3 | open shift lock not shared | KILLED | 3 |
| L4 | void shift lock dropped | KILLED | 1 |
| L5 | approval row lock dropped | KILLED | 2 |
| D1 | requester may decide own | KILLED | 1 |
| D2 | sale cashier may decide | KILLED | 1 |
| D3 | permission check dropped | KILLED | 3 |
| D4 | pending check dropped | KILLED | 3 |
| D5 | pin failure not refused | KILLED | 3 |
| D6 | decision method remote | KILLED | 1 |
| D7 | decider stored as requester | KILLED | 2 |
| D8 | permission asked at the null branch | KILLED | 7 |
| H1 | heldFrom takes the oldest hold | SURVIVED | 0 |
| H2 | held-from-pending lines editable | KILLED | 2 |
| H3 | pending_payment lines editable | KILLED | 4 |
| C2-recheck | underpay only refused, against the QA file | KILLED | 1 |
| G3-recheck | reference_type dropped, against the QA file | KILLED | 2 |
| I3-recheck | tender replay method compare dropped, against the QA file | KILLED | 1 |
| H1-recheck | heldFrom takes the oldest hold, against the QA file | KILLED | 1 |
| LW1 | override line total = tax only | KILLED | 18 |
| LW2 | override tax dated today (M13) | KILLED | 2 |
| LW3 | price_overridden_by = requester | KILLED | 1 |
| LW4 | override priced from the list price | KILLED | 21 |
| LW5 | prior-consumed approval path deleted | KILLED | 2 |

## History

Run 1: FAIL (BUG-1).
