# QA Report: T-028

## Verdict: PASS (round 2, after BUG-1 fix)

Round 1 found one real defect (BUG-1, major) against AC-6: `customers.status =
'inactive'` could not ever be reached through `CreateCustomer` or `UpdateCustomer`,
even though the spec explicitly names it as one of three valid status values. `dev`'s
fix (`a7850b1`) is verified below, independently, not merely read. All 41 ACs, the
merge-by-compensation design (D-20), the exemption map on the source-rules scan, and
the full gate are sound.

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| 1–5 (groups) | `CreateCustomerGroupTest`, `UpdateCustomerGroupTest`, `DeleteCustomerGroupTest`, `ListCustomerGroupsTest` | PASS |
| 6, 6a (customer create, [SYNC], currency lock) | `CreateCustomerTest`, `UpdateCustomerTest` | PASS (round 2 — BUG-1 fixed) |
| 7, 7a (group inheritance, opening balance) | `CreateCustomerTest` | PASS |
| 8 (maintained columns refused whole) | `CreateCustomerTest`, `UpdateCustomerTest`, `MaintainedColumns` + `QaCustomersSourceRulesTest` | PASS |
| 9 (status/blocked_reason consistency) | `CreateCustomerTest`, `UpdateCustomerTest`, `CustomerRules` | PASS (round 2 — `checkStatusConsistency()` generalized to "anything but Blocked") |
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
- Result: **exit 2** (corrected — my first reading of this mis-attributed the Bash tool's own wrapper exit status to `composer check`; the real exit code, captured from the backgrounded task's own output, was 2, which is this repo's normal behaviour whenever Pest has any failing test, even an accepted baseline one — see `dev`'s note in `.claude/agent-memory/developer/project_t028_customers_bug1_fix.md`). `lint`: passed. `stan`: no errors. `deptrac`: 0 violations, 0 skipped. `test` step:
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

No other defect found in round 1. Everything else — the full merge rewrite (D-20), the
replay/conflict semantics (D-21/F-4), the currency lock (F-8), the hard side/sign rules
(F-9), the tier-rules shape (F-5), the `opening_balance` write path (F-11), and the
per-file/per-token exemption on the source-rules scan — matches the spec and the plan,
with real discriminating tests, not just passing ones.

## Round 2: BUG-1 fix verification (`a7850b1`)

- **Fix diff, read directly** (`git diff fe06398 a7850b1 --stat` restricted to
  production code): exactly two files touched —
  - `packages/customers/src/Customers/CustomerStatus.php`: adds `case Inactive =
    'inactive';`.
  - `packages/customers/src/Customers/CustomerRules.php::checkStatusConsistency()`:
    generalized from `$status === CustomerStatus::Active && $blockedReason !== null`
    to `$status !== CustomerStatus::Blocked && $blockedReason !== null` — this is the
    second half of the real bug (not just the missing case): without it, an `Inactive`
    row could have slipped through with a non-null `blocked_reason` even after the enum
    case was added, since the old check only ever compared against `Active` by name.
- **Independently re-executed, not just read**, via `php -r` against the autoloaded
  classes (separately from any test file):
  - `CustomerStatus::tryFrom('inactive')` now resolves to `CustomerStatus::Inactive`
    (previously `NULL`).
  - `CustomerRules::checkStatusConsistency(CustomerStatus::Inactive, 'because')` throws
    `validation` ("A inactive customer may not carry a blocked_reason." — grammatical
    "A"/"An" nit only, not a functional defect).
  - `CustomerRules::checkStatusConsistency(CustomerStatus::Inactive, null)` passes.
- **New tests read, not just counted**: `CreateCustomerTest`/`UpdateCustomerTest` each
  gained one genuinely discriminating case ("accepts status inactive, and refuses it
  carrying a blocked_reason (AC-6, AC-9)") that exercises both the success path (status
  round-trips to `'inactive'` in the stored row) and the refusal path (a non-null
  `blocked_reason` on `inactive` is refused) — not merely an enum-existence check.
  Confirmed both ran and passed in my own gate re-run (log lines 2965 and 3116).
- **Checked for sibling incomplete enumerations repo-wide**: `grep -rln "CustomerStatus"
  packages/ modules/` (excluding PHPStan's own cache noise) returns exactly the two
  files above — no other file enumerates `CustomerStatus` cases by name. The only other
  status-adjacent check, `CreditLedgerWriter`'s `$customer->status === 'active'`, is a
  plain string comparison against the literal `'active'`, safe by construction
  regardless of how many statuses exist (it only ever asks "is this one specific
  value", never enumerates "all other values"). `ListCustomersHandler`'s status filter
  is a raw pass-through, not enum-validated, so it was never affected either.
- **Full independent gate re-run** (not trusted from `dev`'s own numbers): `composer
  check` from `modules/host`, same method as round 1 (output redirected to a log file,
  real exit code read from the backgrounded task's own captured `EXITCODE:` line, never
  inferred from the task-completion notification — see the correction below).
  - Result: **exit 2** (expected on this repo whenever the baseline failures are
    present — not a regression signal by itself; see the note under Round 1's
    corrected exit-code line). `lint`: passed. `stan`: no errors. `deptrac`: 0
    violations, 0 skipped.
  - `test` step: **13 failed / 37 skipped / 3473 passed (25589 assertions)**, 1490.75s
    (limit 7800s, ratio 5.23).
  - The 13 failures are, line for line, the exact same 5 known baseline classes as
    round 1 (`ModulesEnvParsingTest`, `QaHealthProbeResilienceTest` ×2,
    `QaModuleNameValidationTest` ×3, `QaModuleRegistryContractTest` ×6,
    `QaVocabularyRenameSweepTest` ×1) — no new failure.
  - **+2 passed, +6 assertions** over round 1's 3471/25583 — exactly the two new
    discriminating tests, nothing else moved. This matches `dev`'s own reported delta,
    confirmed independently rather than taken on trust.
- **A correction to my own round 1 report, made in round 2**: I had written "Result:
  exit 0" for round 1's gate run. That was wrong — I misread the Bash tool's
  background-task-completion notification (which reports the exit status of the whole
  shell command, here a trailing `echo` that always succeeds) as the exit status of
  `composer check` itself. The real captured code, sitting in the backgrounded task's
  own `.output` file the entire time, was `EXITCODE:2`, both in round 1 and round 2 —
  this repo's normal behaviour whenever Pest has any failing test, even an accepted
  baseline one. The round 1 pass/fail/skip counts and verdict were correct regardless;
  only that one sentence was wrong, and it is now corrected above. Noted in memory so I
  don't repeat it.

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
Run 2: FAIL -> PASS (BUG-1 fixed in `a7850b1`, independently verified; full gate re-run clean against the exact same baseline, +2 passed/+6 assertions for the two new discriminating tests)
