# QA Report: T-028

## Verdict: FAIL

One real defect (BUG-1, major) against AC-6: `customers.status = 'inactive'` cannot
ever be reached through `CreateCustomer` or `UpdateCustomer`, even though the spec
explicitly names it as one of three valid status values. Everything else reviewed —
all 41 ACs, the merge-by-compensation design (D-20), the exemption map on the
source-rules scan, and the full gate — is sound.

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| 1–5 (groups) | `CreateCustomerGroupTest`, `UpdateCustomerGroupTest`, `DeleteCustomerGroupTest`, `ListCustomerGroupsTest` | PASS |
| 6, 6a (customer create, [SYNC], currency lock) | `CreateCustomerTest`, `UpdateCustomerTest` | **PARTIAL — see BUG-1**: `status` enum coverage is incomplete; `inactive` is unreachable |
| 7, 7a (group inheritance, opening balance) | `CreateCustomerTest` | PASS |
| 8 (maintained columns refused whole) | `CreateCustomerTest`, `UpdateCustomerTest`, `MaintainedColumns` + `QaCustomersSourceRulesTest` | PASS |
| 9 (status/blocked_reason consistency) | `CreateCustomerTest`, `UpdateCustomerTest`, `CustomerRules` | **PARTIAL — see BUG-1**: the rule's "every other status" branch is unreachable because no other status exists |
| 10 (delete balance guard) | `DeleteCustomerTest` | PASS |
| 11 (sale fields out of scope) | `CreateCustomerTest` | PASS |
| 12 (possible_duplicate_of) | `CreateCustomerTest`, `UpdateCustomerTest` | PASS |
| 13, 14 (merge) | `MergeCustomersTest` | PASS — compensating-entry design (D-20) verified; source rows byte-identical; oracle fold holds both sides |
| 15, 25, 33, 36 (deferred columns by absence) | `CustomersTenantSchemaTest` | PASS |
| 16–18 (addresses) | `AddCustomerAddressTest`, `UpdateCustomerAddressTest`, `RemoveCustomerAddressTest`, `ListCustomerAddressesTest` | PASS |
| 19 (append-only ledger) | `RecordCreditLedgerEntryTest`, `QaCustomersSourceRulesTest` | PASS |
| 20 (ledger write preconditions) | `RecordCreditLedgerEntryTest`, `CreditLedgerWriter` | PASS; CHECK constraint itself NOT VERIFIED under `ENGINE_HOLD` (as spec requires) |
| 21 (replay/conflict) | `RecordCreditLedgerEntryTest` (incl. the raw-insert backstop case) | PASS |
| 22, 29 (row lock) | `RecordCreditLedgerEntryTest`, `RecordLoyaltyTransactionTest`; lock-holding itself NOT VERIFIED under `ENGINE_HOLD` | PASS (within the stated fence) |
| 23, 24 (arithmetic, credit limit) | `CreditLedgerCalculatorTest` | PASS — every planned mutation (M1–M6) has a discriminating row |
| 26–28, 30–32 (loyalty, tiers) | `LoyaltyCalculatorTest`, `TierRulesTest`, `RecordLoyaltyTransactionTest`, `CreateLoyaltyProgramTest` | PASS — M7–M10 all discriminating |
| 27 (append-only loyalty) | `QaCustomersSourceRulesTest` | PASS |
| 34–37 (notes/communications, pagination) | `AddCustomerNoteTest`, `LogCustomerCommunicationTest`, `ListCustomerNotesTest`, `ListCustomerCommunicationsTest` | PASS |
| 38 (boundary, contract) | `CustomersProviderTest`, deptrac negative control, `QaCustomersSourceRulesTest` | PASS |
| 39 (gate) | full `composer check` | PASS |
| 40 (precision map) | `TenantDecimalPrecisionTest` (T-028 additions) | PASS |
| 41 (pure calculators, one-writer) | `CreditLedgerCalculatorTest`, `LoyaltyCalculatorTest`, `QaCustomersSourceRulesTest` | PASS |

## Test run summary

- Commands:
  - `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" check` (from `modules/host`), output redirected to a log file and read back (never piped/teed).
- Result: **exit 0**. `lint`: passed. `stan`: no errors. `deptrac`: 0 violations, 0 skipped. `test` step:
  - **13 failed / 37 skipped / 3471 passed (25583 assertions)**, `test` step 1299.35s (limit 7800s, ratio 6.00).
  - The 13 failures are exactly the stated pre-existing baseline set (`ModulesEnvParsingTest` ×1, `QaHealthProbeResilienceTest` ×2, `QaModuleNameValidationTest` ×3, `QaModuleRegistryContractTest` ×6, `QaVocabularyRenameSweepTest` ×1) — verified line by line against the log; no new failing class anywhere.
  - Verified before running: no `php.exe` process on this machine referenced `build-56f213`; CPU time of the gate's own process was checked repeatedly through the run to confirm it was computing, not hung.
- Mutation/negative-control spot checks I re-derived directly from source (not just reading `dev`'s claims):
  - `CreditLedgerCalculator`: order of checks, `wrong_side` before `over_limit`, `>` not `>=` at the limit, resulting-balance vs amount-alone (80/100/30 case) — all present as discriminating rows in `CreditLedgerCalculatorTest`.
  - `TierRules::tierFor`: confirmed it compares via `BigDecimal::compareTo`, not string comparison (the "100" vs "9" trap is pinned by name in `TierRulesTest`).
  - `MergeCustomersHandler`: confirmed the compensating-entry design is implemented exactly as D-20/Ruling 9 states — source ledger/loyalty rows are reassigned nowhere, only `customer_addresses`/`customer_notes`/`customer_communications` move by direct `UPDATE`.
  - `QaCustomersSourceRulesTest`'s three-handler exemption map (`CreateCustomerHandler`, `DeleteCustomerHandler`, `MergeCustomersHandler`) is per-file **and** per-token (`qaCustomersMaintainedColumnOffenders()` checks `in_array($token, $allowedTokens, true)` for the specific token, not a blanket pass for the file), and the test file includes its own planted-file negative control proving the scan can fail. Confirmed by reading the exemption map and its three dedicated tests directly — it does not quietly let anything else through.
- Ran `php -r` directly against the autoloaded `CustomerStatus` enum to confirm BUG-1 (see below) independently of any test file.

## Defects

### BUG-1 (major) — AC-6: `customers.status` cannot ever be `inactive`

- **Steps / failing evidence:** `packages/customers/src/Customers/CustomerStatus.php` declares only two cases:
  ```php
  enum CustomerStatus: string
  {
      case Active = 'active';
      case Blocked = 'blocked';
  }
  ```
  `CreateCustomerHandler`/`UpdateCustomerHandler` both resolve the submitted `status`
  through `CustomerRules::status()`, which calls `CustomerStatus::tryFrom()` and
  refuses (`validation`) anything that isn't one of those two. I confirmed this
  directly against the autoloaded class:
  ```
  php -r '... var_dump(\Flexipos\Customers\Customers\CustomerStatus::tryFrom("inactive")); ...'
  NULL
  ```
  No test anywhere in the suite ever supplies `status: 'inactive'` and expects success
  — `CreateCustomerTest`'s only status-validation case uses `'bogus'`, and every other
  status test uses `'active'`/`'blocked'` only. The `status` column itself is a plain
  `VARCHAR(30)` with no DB-level check, so the gap is purely in the application enum.
- **Expected (AC-6):** "`status` defaults to `active` (one of `active`, `blocked`,
  `inactive`)." AC-9 also presupposes a third status exists ("every other status
  requires `blocked_reason` to be null" only has teeth if there is a status besides
  `active` and `blocked`).
- **Actual:** a create or update request with `status: 'inactive'` is refused as an
  unknown/invalid status; there is no way to ever reach `status = 'inactive'` on a row.
- **Suspected location:** `packages/customers/src/Customers/CustomerStatus.php` (missing
  `case Inactive = 'inactive';`); `CustomerRules::checkStatusConsistency()` would also
  need its "every other status requires null" branch extended from "not Blocked" to
  explicitly cover `Inactive` the same way it covers `Active` (currently it only checks
  `Active !== null` and `Blocked === null`, which happens to be correct once the case
  exists, but is worth re-reading once the enum is fixed).

No other defect found. Everything else — the full merge rewrite (D-20), the replay/
conflict semantics (D-21/F-4), the currency lock (F-8), the hard side/sign rules (F-9),
the tier-rules shape (F-5), the `opening_balance` write path (F-11), and the
per-file/per-token exemption on the source-rules scan — matches the spec and the plan,
with real discriminating tests, not just passing ones.

## Regression risks / notes

- The gate ran clean against the exact stated baseline; nothing in T-028 introduced a
  new failure, flake, or widened exemption elsewhere in the tree.
- `QaCustomersSourceRulesTest`'s own first test (`'every scanned directory actually
  exists...'`) would itself fail loudly if any of the three exempted file paths were
  ever renamed or moved, so the exemption map cannot silently go stale.
- BUG-1 is a pure enum/validation gap, not a schema or migration problem — the `status`
  column already accepts the string `'inactive'` at the database level, so the fix is
  contained to `CustomerStatus` and whatever tests should accompany it (create/update
  success with `status: 'inactive'`, and the `blocked_reason` consistency check against
  it).

---
Run 1: FAIL (BUG-1: `inactive` customer status unreachable, AC-6)
