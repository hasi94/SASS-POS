# QA Report: T-034

Suppliers, purchase requests, purchase orders and the received-quantity rollup (backend). Branch `ai/T-034-suppliers-purchase-orders`, tip `e89f819`. Spec revision 2 (AC-1 to AC-98), plan revision 2, decisions D-1 to D-53.

Delivery rule in force: `ENGINE_HOLD`. Nothing connected to a real MySQL/MariaDB engine. The worktree has no `.env`, so app-booting tests print "warnings" instead of "passed" (a PHP warning from a missing `.env` read) but still run every assertion; judged by failures.

## Verdict: PASS (for everything verifiable under the hold)

No defect found: 0 critical, 0 major, 0 minor. One test gap was found by mutation (the `UpdatePurchaseOrderLine` pricing date) and closed with a new test. Engine ACs (AC-78, all lock and deadlock behaviour) are **NOT VERIFIED**, see the list below. The nine env-only failures at the lead's full gate are not cleared by me.

## Acceptance criteria coverage

"stand-in" = the SQLite fixture tenant (real handlers, real migrations, real access/pricing/workflow/audit/outbox engines; no row locks). Test names are file basenames under `packages/purchasing/tests/` (`Features/<Slice>/`, `Documents/`, `Support/`, `Schema/`, `Invariants/`, `Qa/`). Derived by grepping each `AC-n` citation in the tests and then reading the code under the claim. AC-78 and AC-98 are the only two with no stand-in proof.

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | CreateSupplierTest, Schema/PurchasingTenantSchemaTest | PASS (stand-in) |
| AC-2 | CreateSupplierTest | PASS (stand-in) |
| AC-3 | CreateSupplierTest, Support/PurchasingUniquenessTest | PASS (stand-in) |
| AC-4 | CreateSupplierTest, UpdateSupplierTest | PASS (stand-in) |
| AC-5 | CreateSupplierTest | PASS (stand-in) |
| AC-6 | CreateSupplierTest, Schema/PurchasingTenantSchemaTest, UpdateSupplierTest | PASS (stand-in) |
| AC-7 | CreateSupplierTest | PASS (stand-in) |
| AC-8 | CreateSupplierTest | PASS (stand-in) |
| AC-9 | CreateSupplierTest, Support/PurchasingUniquenessTest | PASS (stand-in) |
| AC-10 | UpdateSupplierTest | PASS (stand-in) |
| AC-11 | UpdateSupplierTest | PASS (stand-in) |
| AC-12 | UpdatePurchaseOrderTest, UpdateSupplierTest | PASS (stand-in) |
| AC-13 | ActivateSupplierTest, AddPurchaseRequestLineTest, DeactivateSupplierTest, UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-14 | DeleteSupplierTest, Documents/PurchasingStatusMachineTest, GetSupplierTest | PASS (stand-in) |
| AC-15 | GetSupplierTest | PASS (stand-in) |
| AC-16 | ListSuppliersTest, Support/PurchasingPagerTest | PASS (stand-in) |
| AC-17 | CreateSupplierTest, DeactivateSupplierTest, Support/PurchasingRedactionTest, UpdateSupplierTest | PASS (stand-in) |
| AC-18 | ActivateSupplierTest, CreateSupplierTest, DeactivateSupplierTest, DeleteSupplierTest, GetSupplierTest, ListSuppliersTest, Support/PurchasingAuthoriseTest, UpdateSupplierTest | PASS (stand-in) |
| AC-19 | CreatePurchaseOrderTest, CreatePurchaseRequestTest, Schema/PurchasingTenantSchemaTest | PASS (stand-in) |
| AC-20 | CreatePurchaseRequestTest, Documents/PurchasingPlacesTest, UpdatePurchaseRequestTest | PASS (stand-in) |
| AC-21 | AddPurchaseRequestLineTest, CreatePurchaseRequestTest, GetPurchaseRequestTest, RemovePurchaseRequestLineTest, SubmitPurchaseRequestTest, UpdatePurchaseRequestLineTest, UpdatePurchaseRequestTest | PASS (stand-in) |
| AC-22 | AddPurchaseRequestLineTest, UpdatePurchaseRequestLineTest | PASS (stand-in) |
| AC-23 | AddPurchaseOrderLineTest, AddPurchaseRequestLineTest, Documents/PurchasingProductFactsTest, UpdatePurchaseRequestLineTest | PASS (stand-in) |
| AC-24 | AddPurchaseRequestLineTest, GetPurchaseRequestTest, UpdatePurchaseRequestLineTest | PASS (stand-in) |
| AC-25 | AddPurchaseRequestLineTest, RemovePurchaseRequestLineTest, SubmitPurchaseRequestTest, UpdatePurchaseRequestLineTest, UpdatePurchaseRequestTest | PASS (stand-in) |
| AC-26 | SubmitPurchaseRequestTest, Support/PurchasingMoneyTest | PASS (stand-in) |
| AC-27 | SubmitPurchaseRequestTest | PASS (stand-in) |
| AC-28 | Documents/PurchasingApprovalOutcomeTest, SyncRequestApprovalTest | PASS (stand-in) |
| AC-29 | CancelPurchaseRequestTest | PASS (stand-in) |
| AC-30 | ConvertRequestToOrderTest | PASS (stand-in) |
| AC-31 | ConvertRequestToOrderTest, Documents/PurchasingLineQuoterTest | PASS (stand-in) |
| AC-32 | ConvertRequestToOrderTest | PASS (stand-in) |
| AC-33 | ConvertRequestToOrderTest | PASS (stand-in) |
| AC-34 | GetPurchaseRequestTest | PASS (stand-in) |
| AC-35 | ListPurchaseRequestsTest, Support/PurchasingPagerTest | PASS (stand-in) |
| AC-36 | AddPurchaseRequestLineTest, CreatePurchaseRequestTest | PASS (stand-in) |
| AC-37 | CancelPurchaseRequestTest, ConvertRequestToOrderTest, Documents/PurchasingStatusMachineTest, SubmitPurchaseRequestTest | PASS (stand-in) |
| AC-38 | AddPurchaseRequestLineTest, CancelPurchaseRequestTest, CreatePurchaseRequestTest, GetPurchaseRequestTest, ListPurchaseRequestsTest, RemovePurchaseRequestLineTest, SubmitPurchaseRequestTest, SyncRequestApprovalTest, UpdatePurchaseRequestLineTest | PASS (stand-in) |
| AC-39 | AddPurchaseRequestLineTest, CreatePurchaseRequestTest, RemovePurchaseRequestLineTest, UpdatePurchaseRequestLineTest, UpdatePurchaseRequestTest | PASS (stand-in) |
| AC-40 | CreatePurchaseOrderTest, Schema/PurchasingTenantSchemaTest, Support/PurchasingBusinessClockTest | PASS (stand-in) |
| AC-41 | CreatePurchaseOrderTest, Documents/PurchasingPlacesTest, UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-42 | AddPurchaseOrderLineTest, Documents/PurchasingLineQuoterTest, Schema/PurchasingTenantSchemaTest, UpdatePurchaseOrderLineTest | PASS (stand-in) |
| AC-43 | AddPurchaseOrderLineTest, Documents/PurchasingLineQuoterTest, Support/PurchasingMoneyTest, UpdatePurchaseOrderLineTest | PASS (stand-in) |
| AC-44 | AddPurchaseOrderLineTest, Documents/PurchasingTaxGroupChainTest, UpdatePurchaseOrderLineTest, PurchasingQaCloseOutTest(QA) | PASS (stand-in) |
| AC-45 | AddPurchaseOrderLineTest, CreatePurchaseOrderTest, Documents/PurchasingOrderRollupTest, UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-46 | AddPurchaseOrderLineTest, QaPurchasingSourceRulesTest, Support/PurchasingMoneyTest, PurchasingHostileInputTest(QA) | PASS (stand-in) |
| AC-47 | AddPurchaseOrderLineTest, ConvertRequestToOrderTest, Documents/PurchasingLineQuoterTest, Documents/PurchasingOrderRollupTest, RemovePurchaseOrderLineTest, UpdatePurchaseOrderLineTest | PASS (stand-in) |
| AC-48 | AddPurchaseOrderLineTest, GetPurchaseOrderTest | PASS (stand-in) |
| AC-49 | Documents/PurchasingAmendmentsTest, Documents/PurchasingLineQuoterTest, RemovePurchaseOrderLineTest, UpdatePurchaseOrderLineTest, PurchasingQaCloseOutTest(QA) | PASS (stand-in) |
| AC-50 | UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-51 | AddPurchaseOrderLineTest | PASS (stand-in) |
| AC-52 | ConvertRequestToOrderTest, CreatePurchaseOrderTest, CreatePurchaseRequestTest, Exceptions/PurchasingRefusedTest | PASS (stand-in) |
| AC-53 | AddPurchaseOrderLineTest, CreatePurchaseOrderTest | PASS (stand-in) |
| AC-54 | SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-55 | SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-56 | SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-57 | SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-58 | SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-59 | Documents/PurchasingApprovalOutcomeTest, Invariants/PurchasingInvariantTest, SyncOrderApprovalTest | PASS (stand-in) |
| AC-60 | AddPurchaseOrderLineTest, Documents/PurchasingAmendmentsTest, RemovePurchaseOrderLineTest, UpdatePurchaseOrderAmendmentTest, UpdatePurchaseOrderLineTest, UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-61 | AddPurchaseOrderLineTest, Documents/PurchasingAmendmentsTest, RemovePurchaseOrderLineTest, UpdatePurchaseOrderAmendmentTest, UpdatePurchaseOrderLineTest, UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-62 | Documents/PurchasingAmendmentsTest, ReopenPurchaseOrderTest | PASS (stand-in) |
| AC-63 | Documents/PurchasingAmendmentsTest, UpdatePurchaseOrderAmendmentTest, UpdatePurchaseOrderLineTest | PASS (stand-in) |
| AC-64 | CancelPurchaseOrderTest | PASS (stand-in) |
| AC-65 | ClosePurchaseOrderTest | PASS (stand-in) |
| AC-66 | AddPurchaseOrderLineTest, CancelPurchaseOrderTest, ClosePurchaseOrderTest, Documents/PurchasingAmendmentsTest, Documents/PurchasingStatusMachineTest, RemovePurchaseOrderLineTest, ReopenPurchaseOrderTest, SubmitPurchaseOrderTest, UpdatePurchaseOrderAmendmentTest, UpdatePurchaseOrderLineTest, UpdatePurchaseOrderTest | PASS (stand-in) |
| AC-67 | CancelPurchaseOrderTest, ClosePurchaseOrderTest, Documents/PurchasingStatusMachineTest, ReopenPurchaseOrderTest, SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-68 | Schema/PurchasingTenantSchemaTest | PASS (stand-in) |
| AC-69 | ApplyReceivedQuantityTest, Documents/PurchasingReceiptRollupTest, Invariants/PurchasingInvariantTest | PASS (stand-in) |
| AC-70 | ApplyReceivedQuantityTest, Documents/PurchasingReceiptRollupTest, Invariants/PurchasingInvariantTest | PASS (stand-in) |
| AC-71 | ApplyReceivedQuantityTest, Documents/PurchasingReceiptRollupTest | PASS (stand-in) |
| AC-72 | ApplyReceivedQuantityTest, Documents/PurchasingReceiptRollupTest | PASS (stand-in) |
| AC-73 | ApplyReceivedQuantityTest, Documents/PurchasingReceiptRollupTest | PASS (stand-in) |
| AC-74 | ApplyReceivedQuantityTest, Documents/PurchasingReceiptRollupTest | PASS (stand-in) |
| AC-75 | ApplyReceivedQuantityTest, Documents/PurchasingStatusMachineTest | PASS (stand-in) |
| AC-76 | ApplyReceivedQuantityTest | PASS (stand-in) |
| AC-77 | ApplyReceivedQuantityTest | PASS (stand-in) |
| AC-78 | (held) modules/host/tests/Engine/PurchasingSchemaEngineTest (skipped under ENGINE_HOLD); stand-in: only the sequential two-line AC-71 case in ApplyReceivedQuantityTest | NOT VERIFIED (engine) |
| AC-79 | ApplyReceivedQuantityTest, QaPurchasingSourceRulesTest | PASS (stand-in) |
| AC-80 | ApplyReceivedQuantityTest | PASS (stand-in) |
| AC-81 | GetPurchaseOrderTest | PASS (stand-in) |
| AC-82 | ListPurchaseOrdersTest, Support/PurchasingPagerTest | PASS (stand-in) |
| AC-83 | GetPurchaseOrderTest | PASS (stand-in) |
| AC-84 | CancelPurchaseRequestTest, CreatePurchaseOrderTest, CreatePurchaseRequestTest, GetPurchaseOrderTest, GetPurchaseRequestTest, ListPurchaseOrdersTest, ListPurchaseRequestsTest, SubmitPurchaseOrderTest | PASS (stand-in) |
| AC-85 | AddPurchaseOrderLineTest, CancelPurchaseOrderTest, ClosePurchaseOrderTest, ConvertRequestToOrderTest, CreatePurchaseOrderTest, GetPurchaseOrderTest, RemovePurchaseOrderLineTest, ReopenPurchaseOrderTest, SubmitPurchaseOrderTest, Support/PurchasingAuthoriseTest, UpdatePurchaseOrderLineTest, UpdatePurchaseOrderTest, UpdatePurchaseRequestTest | PASS (stand-in) |
| AC-86 | AddPurchaseOrderLineTest, CancelPurchaseOrderTest, ClosePurchaseOrderTest, ConvertRequestToOrderTest, CreatePurchaseOrderTest, CreatePurchaseRequestTest, CreateSupplierTest, GetPurchaseOrderTest, GetPurchaseRequestTest, ListPurchaseOrdersTest, ListPurchaseRequestsTest, RemovePurchaseOrderLineTest, ReopenPurchaseOrderTest, SubmitPurchaseOrderTest, Support/PurchasingAuthoriseTest, UpdatePurchaseOrderLineTest, UpdatePurchaseOrderTest, UpdateSupplierTest | PASS (stand-in) |
| AC-87 | Invariants/PurchasingInvariantTest | PASS (stand-in) |
| AC-88 | Qa/PurchasingCrossTenantTest, Schema/PurchasingTenantSchemaTest | PASS (stand-in) |
| AC-89 | Schema/PurchasingTenantSchemaTest | PASS (stand-in) |
| AC-90 | ConvertRequestToOrderTest, CreatePurchaseOrderTest, CreatePurchaseRequestTest, ReopenPurchaseOrderTest, Support/PurchasingRefusals | PASS (stand-in) |
| AC-91 | AddPurchaseOrderLineTest, CancelPurchaseOrderTest, ClosePurchaseOrderTest, ConvertRequestToOrderTest, CreatePurchaseOrderTest, CreatePurchaseRequestTest, ListPurchaseOrdersTest, RemovePurchaseOrderLineTest, ReopenPurchaseOrderTest, UpdatePurchaseOrderLineTest, UpdatePurchaseOrderTest, PurchasingHostileInputTest(QA, 5 sweeps) | PASS (stand-in) |
| AC-92 | Exceptions/PurchasingRefusedTest | PASS (stand-in) |
| AC-93 | QaPurchasingSourceRulesTest | PASS (stand-in) |
| AC-94 | AddPurchaseOrderLineTest, ConvertRequestToOrderTest, Documents/PurchasingLineQuoterTest, Documents/PurchasingProductFactsTest, Support/PurchasingQueryLog | PASS (stand-in) |
| AC-95 | Registry/PurchasingRegistryTest | PASS (stand-in) |
| AC-96 | ApplyReceivedQuantityTest | PASS (stand-in) |
| AC-97 | ApplyReceivedQuantityTest, CancelPurchaseOrderTest, ClosePurchaseOrderTest, Invariants/PurchasingInvariantTest, ReopenPurchaseOrderTest, SubmitPurchaseOrderTest, Support/PurchasingOutbox, SyncOrderApprovalTest | PASS (stand-in) |
| AC-98 | PurchasingSchemaEngineTest (written, skipped by `onHold()`); stand-in tests run (this report) | PASS (as stated) |

Notes on the table: AC-44 and AC-49 gained `Qa/PurchasingQaCloseOutTest`; AC-46 and AC-91 gained `Qa/PurchasingHostileInputTest`. AC-94 counts are under "Measured".

## Test run summary

All commands from `modules/host`, PHP `E:\xampp\php\php.exe`, stdin from `/dev/null`, one at a time.

| Command | Result |
|---|---|
| `vendor/bin/pest ../../packages/purchasing/tests --colors=never` (whole package, before my new files) | **0 failed**, 395 passed, 1011 warnings (env-only `.env` read), 5793 assertions, 431.6 s, exit 0 |
| `vendor/bin/pest` on `QaAccessHttpSurfaceTest`, `TenantBaselineBuildsTest`, `MigrationTreeDisciplineTest`, `BranchForeignKeysContractRunTest`, `SchemaCataloguePublishTest`, `QaPurchasingSourceRulesTest`, `packages/access/tests/Support/AuditCatalogueTest`, `PurchasingInvariantTest`, `PurchasingCrossTenantTest` | **0 failed**, 71 warnings (env-only), 1820 assertions, 1008 s (machine shared with other agents' php), exit 0 |
| `vendor/bin/pest` on my two new files (after the lint fix) | 0 failed, 6 warnings (env-only), 10 assertions |
| `vendor/bin/pint --test .. ../../packages` | `{"tool":"pint","result":"passed"}` (final run, includes my new files; the first run flagged only my two new files for `single_quote` and `ordered_imports`/`fully_qualified_strict_types`, fixed) |
| `vendor/bin/phpstan analyse` after `phpstan clear-result-cache` | `[OK] No errors` (rc 0). A first run reported "Internal error: Child process timed out after 600.0 seconds" while another agent's phpstan ran on the same machine; CPU contention, not a code finding; the rerun was clean |
| `vendor/bin/deptrac analyse` | Violations 0, Warnings 0, Errors 0 (run twice, before and after my lint edits) |

I did not rerun the full suite (as instructed). The lead's full gate at this tip: pint, phpstan, deptrac clean; Pest 10 failed (1 baseline `QaVocabularyRenameSweepTest`, 9 env-only), 1184 passed. I did not reproduce those 10.

### Measured (AC-94)

- `AddPurchaseOrderLine`: 27 queries for one line (asserted at most 40).
- `ConvertRequestToOrder` of a 50-line request: 480 queries; 51 reads of `products` (1 for facts, 50 inside the resolver: each product once per line).
- Per-line resolver cost: `snapshot()` + quote = 9, `snapshotInherited()` + quote = 6, quote only = 4 (FP-426 is the known per-line cost; not worsened).

## New tests added (new files only)

1. `packages/purchasing/tests/Qa/PurchasingQaCloseOutTest.php`: AC-44/AC-49, an edited line is priced at the order's own date, for a cost-only edit (the stored-quad `price()` path) and for a quantity edit (the re-snapshot path). Kills M05b and M05c (both survived the developer's suite).
2. `packages/purchasing/tests/Qa/PurchasingHostileInputTest.php`: AC-91 swept over every array-taking slice, every id-only slice and every list filter. Each field is set, one at a time, to 38 hostile values (null, arrays, float, bool, empty, NUL byte, 5000 characters, `PHP_INT_MAX`, `1e5`, `0x1A`, SQL text, emoji, fullwidth digits, trailing newline, impossible dates, 40 nines, `1.00000`, `-0.0001`, a non-ULID 26-character string, an object) and also removed. Only a success or a `PurchasingRefused` is acceptable. Five tests, all green on the shipped code. Negative control C01: with `Identifier::fold` stripped of its `is_string` guard all five go red, so the sweep does reach the handlers. The first test also asserts more than 50 calls succeeded, so it cannot pass by refusing everything.

## Mutation table

Production code mutated one fault at a time and restored byte for byte after each (a Python driver backs up the bytes, requires the pattern once, writes, runs the named Pest files, restores in a `finally`); `git status` was clean of `src/` after every run and at the end. Tests run per mutant: the slice's test folder plus the helper folders for the file. 35 production mutants plus 1 control: 33 killed by the developer's own suite, 1 survivor (M05b), and M05c run only against the new test (so not known to survive the old suite), both closed above.

| # | Mutation | Spec rule | Result (red of tests run) |
|---|---|---|---|
| M01 | `ApplyReceivedQuantity` takes a line `FOR UPDATE` before the header lock | Ruling 7, AC-96 | killed, 1 failed |
| M02 | `SyncOrderApproval` drops the entity-id match | AC-59 | killed, 2 failed |
| M02b | drops the entity-type match instead | AC-59 | killed, 1 failed |
| M03 | rollup status from `received_quantity` instead of `received_base_quantity` | AC-69/70 | killed, 6 failed |
| M04a | replay returns `replayed = false` | AC-76 | killed, 2 failed |
| M04b | `replay_conflict` check disabled | AC-76 | killed, 3 failed |
| M04c | replay ignores the quantity delta | AC-76 | killed, 1 failed |
| M05 | `AddPurchaseOrderLine` prices at a fixed other date | AC-44 | killed, 1 failed |
| **M05b** | `UpdatePurchaseOrderLine` re-snapshot path prices at another date | AC-44/49 | **survived the developer's suite (0 failed)**; killed by the new test (1 failed) |
| **M05c** | `UpdatePurchaseOrderLine` stored-quad `price()` path prices at another date | AC-44/49 | killed by the new test (1 failed); not run against the old suite |
| M06 | currency chain: branch before supplier | AC-40 | killed, 3 failed |
| M06b | currency chain: supplier before explicit | AC-40 | killed, 1 failed |
| M07 | conversion uses a fresh `snapshot()` instead of `snapshotInherited()` | AC-31 | killed, 7 failed |
| M08a | `CancelPurchaseOrder` writes the old status (no-op cancel) | AC-64 | killed, 6 failed |
| M08b | `ClosePurchaseOrder` no-op | AC-65 | killed, 3 failed |
| M09 | `Authorise` drops the branch (asks null) | AC-85 | killed, 76 failed |
| M10 | order create takes the number before the supplier lock | Ruling 3/7, AC-90 | killed, 1 failed |
| M11 | reopen and cancel ignore receipt-application rows | AC-62/64, D-45 | killed, 2 failed |
| M12 | completeness `>` instead of `>=` | AC-72/73 | killed, 8 failed |
| M13 | received columns may go negative | AC-74 | killed, 4 failed |
| M14 | submit treats `WorkflowEvaluationFailed` as `NotRequired` (fail open) | AC-58, D-11 | killed, 1 failed |
| N01 | supplier in-use sees only draft orders | AC-14 | killed, 4 failed |
| N02 | supplier in-use sees only draft requests | AC-14 | killed, 2 failed |
| N03 | order total omits shipping | AC-45 | killed, 6 failed |
| N04 | `ListPurchaseOrders` unscoped by branch | AC-82 | killed, 3 failed |
| N05 | order-line remove keeps the request-line pointer | AC-49 (D-48) | killed, 1 failed |
| N06 | request cancel skips `WorkflowGate::cancel()` | AC-29 | killed, 5 failed |
| N07 | order cancel skips `WorkflowGate::cancel()` | AC-64 | killed, 4 failed |
| N08 | `GetSupplier` shows `bank_details` to a view-only caller | AC-15 | killed, 1 failed |
| N09 | audit before-image not redacted | AC-17 | killed, 3 failed |
| N10 | reopen allowed from `partially_received` | AC-62 | killed, 1 failed |
| N11 | reopen keeps the workflow instance id | AC-62 | killed, 1 failed |
| N12 | supplier change on an order keeps the old payment-terms snapshot | AC-50 | killed, 1 failed |
| N13 | request amount counts a no-estimate line as 1.0000 | AC-26 | killed, 2 failed |
| N14 | tax-group chain: default group before the product's | AC-44 | killed, 4 failed |
| C01 (control) | `Identifier::fold` without the `is_string` guard | AC-91 | the new sweep goes red (5 failed) |

The two orderings the plan names (header before line, number last) are pinned by tests that read the order of the SQL statements issued. They prove statement order and the clause sent, not that the engine blocks.

## Defects

None.

## Observations (not defects)

- `ApplyReceivedQuantity` with a null actor (documented as allowed, AC-96) writes `purchase_orders.updated_by = NULL` when the status changes, overwriting the previous editor; the audit row and the application row also carry a null actor. FP-255's receive slice should pass the receiving employee.
- `ConvertRequestToOrder` copies the request's `required_date` into the order's `expected_date` (AC-30) with no check against the conversion day, so an order can be created with `expected_date` earlier than `order_date` if the request sat approved past its required date. AC-41 covers create and header edit only, so this follows the spec.
- AC-28 / AC-59 (D-52): nothing triggers `SyncRequestApproval` or `SyncOrderApproval`. A decided approval does not move a document until a caller also calls the sync slice. This is the spec's own owner-visible follow-up, restated.
- Tooling: `rm -rf modules/host/storage/framework/cache/phpstan` is refused (permission denied) while any PHPStan worker has the cache open; `vendor/bin/phpstan clear-result-cache` is the way. Two stray interactive Python processes of mine (an empty heredoc) wrote 600 MB to a background-task output file; I killed them and deleted that file. No repository file was involved.

## NOT VERIFIED (ENGINE_HOLD and environment)

1. **All lock and deadlock behaviour.** The SQLite stand-in has no row locks. Tests prove the order of statements and the `FOR UPDATE` / `FOR SHARE` clause sent (header first, supplier share lock, number last), not that MySQL blocks, serialises or deadlocks as designed. That covers the gap-lock deadlock risk on the first use of a `number_sequences` row (`DeadlockingNumbers` only simulates the retry), the `transaction(..., 2)` retry, supplier delete versus document write (plan risk 9) and the savepoint behaviour of `ApplyReceivedQuantity` on a real engine.
2. **AC-78** (two simultaneous applies, no lost update): `modules/host/tests/Engine/PurchasingSchemaEngineTest.php` is written and skipped by `EngineConnection::onHold()`. Only the sequential two-line case (AC-71) ran.
3. **Real-engine schema checks**: collation behaviour of the `suppliers.code` unique index (`ab1` against `AB1`) and of `purchase_orders.number`, JSON validity checks, migrating twice is a no-op, `DECIMAL` storage on MySQL 8.4 and MariaDB 10.4. Same skipped engine test.
4. **MySQL value types behind the stand-in**: `DECIMAL(18,4)` comes back as a string on MySQL and as an int or float on SQLite; the code normalises both (`Money::of`), but only the SQLite shape ran.
5. **Nine env-only gate failures** at the lead's full gate (`HostTestSuiteCollectionTest`, `ModuleMakeAtomicGenerationTest`, `QaGeneratedModuleTestDiscoveryTest`, `QaHostSuiteCollectionProofTest` x2, `QaModuleConnectionRemovalTest`, `QaRegenerationGuardTest` x2, `QaTestSuiteIsolationTest`): child-process tests that report "N warning vs N passed" without a `.env`. Not run by me and not reproduced, so not cleared: they stay open until a run with an `.env` on a quiet machine. The tenth, `QaVocabularyRenameSweepTest`, is the known baseline.
6. A real `schema:publish` run: version 79 and `.env.example` `APP_REQUIRED_SCHEMA_VERSION=79` were checked by `SchemaCataloguePublishTest` against the fixture, never against a database.
7. The composed `composer check` gate (I ran its parts above, not the composition).

## Regression risks / notes

- Merge with T-032: the plan (section 0.4) renumbers T-034's six migrations (77 to 82). Tests derive versions instead of pinning them, except `IdempotencyKeysSchemaTest` which pins the top by house convention. Expect conflicts in `tenantTreeTables()`, the FK inventory, the table count in `MigrationTreeDisciplineTest`, `QaToolchainRegistryCoverageTest`, `AuditCatalogueTest` and `.env.example`; regenerate the fixture with `schema:publish --write-fixture`, never by hand.
- Nearby code: `packages/pricing` gained `PurchaseLinePricing` and `PurchaseLineCalculator`, `packages/organization` gained two numbering prefixes; the whole-package run above does not include their own tests (the lead's full gate does).
- My two `Qa/*` test files are Pint- and PHPStan-clean and must stay so (both tools cover tests).
- Mutation scripts, edit lists and raw outputs are in the session scratchpad `qa34/` folder, not committed.

Run 1: PASS (35 production mutants + 1 control, 1 survivor closed with a new test; 0 defects).
