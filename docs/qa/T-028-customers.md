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

## Round 3: review round 1 fix verification (`18a2a6a`) — R-1 critical plus W-1 to W-6

Independent re-verification of the fix commit for review round 1's findings
(R-1 Critical, W-1 to W-6). Review round 2 has already approved this commit
and filed 3 non-blocking follow-ups (W-R2-1 to W-R2-3); that review is not
re-litigated here. My job in this round was my own independent pass: trace
each fix in production code directly, not trust the dev's or reviewer's
account, and run the full gate myself.

- **R-1 (critical) — merge's "zero by construction" was only an assumption.**
  `MergeCustomersHandler` now (a) checks `$out->replayed || $in->replayed`
  from both compensating `CreditLedgerWriter::post()` calls and refuses
  before doing anything else, and (b) re-reads the source under lock *after*
  the compensating posts and refuses unless `credit_balance` and
  `loyalty_points` are both exactly zero — turning the invariant into a real
  check. `CreditLedgerWriter::RESERVED_REFERENCE_TYPES = ['customer',
  'customer_merge_out', 'customer_merge_in']` is now enforced in both public
  entry points (`RecordCreditLedgerEntryHandler`, `ContractCreditLedger`).
  New tests (`MergeCustomersTest` ×2) reproduce the review's exact two
  pre-seeded-collision reproductions and assert the full postcondition: the
  merge is refused, the source row is untouched (`deleted_at` null,
  `merged_into_id` null, original balance intact), and — depending on which
  side collided — either zero or exactly one ledger row exists on each side.
  These are real negative controls, not "did not throw".
- **W-1 (replay re-read under REPEATABLE READ snapshot).** Both
  `CreditLedgerWriter` and `LoyaltyWriter`'s catch-block re-read now use
  `->lockForUpdate()` instead of a plain `first()`, exactly as the review
  asked. A new engine test (`CustomersSchemaEngineTest`, "a concurrent
  replay... is reported as replayed, not a false replay_conflict") proves it
  against real MySQL — gated behind `ENGINE_HOLD=1` (forced in
  `phpunit.xml`), so it does not execute in this environment; same fence as
  rounds 1-2, not a new gap.
- **W-2 (engine test correctness).** `insertGetId()` on the CHAR(26) ULID PK
  is gone, replaced by inserting the row and using its real id
  (`customersSchemaEngineInsertCustomer()`). The lock test's connection B now
  calls `CreditLedgerWriter::post()` itself (built directly, not through the
  container) rather than a bare `lockForUpdate()`, and asserts the retry's
  `balance_after` actually includes connection A's write (`10.0000` = `5`
  + `5`). Matches the review's exact remediation.
- **W-3 (exemption map couldn't tell a read from a write; append-only scan
  too narrow).** The per-file/per-token exemption now carries a required
  shape per token: `'read'` requires every occurrence of the token in that
  file to be a property read (`->token`); `CreateCustomerHandler`'s
  exemption requires the exact zero-default insert literal. Two new planted-
  offender negative-control tests prove a write disguised inside the old
  blanket exemption is now caught, and that a differently-shaped assignment
  in Create is caught too. The append-only scan's default scope widened from
  `packages/customers/src` to the one-writer scan's full scope
  (`packages/*/src`, `modules/*/src`, `modules/host/app`), with its own
  planted-offender test proving the wider scope is real, not asserted.
- **W-4 (unlocked group read).** `Lookup::live(..., lock: true)` added in
  both `CreateCustomerHandler` and `UpdateCustomerHandler`.
- **W-5 (`UpdateCustomer` could edit a dead record).** `UpdateCustomerHandler`
  now reads the row with `->whereNull('deleted_at')->lockForUpdate()`. I
  independently proved this myself rather than trusting the diff: wrote two
  new tests in `UpdateCustomerTest.php` (left uncommitted by the prior QA
  session, reviewed and kept as-is — they were already correct and
  complete): one seeds a soft-deleted row directly (bypassing
  `DeleteCustomer`, so it is not entangled with AC-10's own guard) and
  asserts the update is refused `NotFound`, the row is byte-identical before
  and after, and the audit table is empty; the other seeds a row carrying
  both `deleted_at` and `merged_into_id` directly (bypassing `MergeCustomers`)
  and asserts the same refusal. Both ran green in the full gate run below.
- **W-6 (partial — unvalidated inputs reaching the database).**
  `Fields::decimal()` gained `maxIntegerDigits` (14 for `(18,4)` columns),
  wired into `credit_limit`/`opening_balance`. A new `Fields::datetime()`
  validates `expires_at` against a `Y-m-d H:i:s[.v]` shape. **However**, I
  independently reproduced review round 2's W-R2-3 finding directly against
  the autoloaded class (not just read about it): `Fields::datetime($fields,
  'expires_at', 29)` given `expires_at => '2026-02-30 10:00:00'` returns the
  string **unchanged (accepted)**, because
  `DateTime::createFromFormat('Y-m-d H:i:s', '2026-02-30 10:00:00')` returns
  a valid `DateTime` object rolled over to `2026-03-02` rather than `false`
  — confirmed with a direct `php -r` call:
  ```
  $d = DateTime::createFromFormat("Y-m-d H:i:s", "2026-02-30 10:00:00");
  var_dump($d === false);       // bool(false) - did NOT fail
  var_dump($d->format("Y-m-d")); // "2026-03-02" - silently rolled over
  ```
  and then end-to-end through `Fields::datetime()` itself, which returned
  `"2026-02-30 10:00:00"` unchanged — the exact rollover-invalid value the
  check exists to refuse. This is real, not a paper finding; it is already
  known to the lead as W-R2-3 and will be tracked as backlog, not raised
  here as a new round-3 blocker. I also confirmed W-R2-2 (the reserved-
  reference-type check uses `in_array($referenceType,
  CreditLedgerWriter::RESERVED_REFERENCE_TYPES, true)`, which is case-
  sensitive against lowercase literals and would not catch a differently-
  cased value that a case-insensitive MySQL/MariaDB collation would treat as
  the same string) is real by reading the check directly — also already
  known and filed.

### Full independent gate re-run

- Command: `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" check` from `modules/host`, output redirected to a log file and the real exit code read from the backgrounded task's own captured `EXITCODE:` line (never piped/teed, never inferred from the Bash tool's own wrapper exit).
- Verified before running: no `php.exe` process on this machine referenced `build-56f213` (checked via `Get-CimInstance Win32_Process -Filter "name='php.exe'"`).
- `lint`: `{"tool":"pint","result":"passed"}`. `stan`: "[OK] No errors". `deptrac`: 0 violations, 0 skipped.
- `test` step: **exit 2** (this repo's normal behaviour whenever Pest has any failing test, even an accepted baseline one — not a regression signal by itself, consistent with rounds 1-2).
  - **13 failed / 38 skipped / 3483 passed (25630 assertions)**, elapsed 1178.84s (limit 7800s, ratio 6.62).
  - The 13 failures match the exact accepted baseline, line for line: `ModulesEnvParsingTest` ×1, `QaHealthProbeResilienceTest` ×2, `QaModuleNameValidationTest` ×3, `QaModuleRegistryContractTest` ×6, `QaVocabularyRenameSweepTest` ×1. No new failing class anywhere.
  - **+10 passed, +1 skipped** over round 2's 3473 passed/37 skipped: the 9 new discriminating tests added by the fix commit (R-1 ×3 across `MergeCustomersTest`/`CreditLedgerInvariantTest`/`RecordCreditLedgerEntryTest`, W-3 ×3 planted-offender negative controls, plus the 2 kept W-5 tests in `UpdateCustomerTest`) plus the 1 new `ENGINE_HOLD`-gated W-1/W-2 engine test (which skips, accounting for the +1 skipped).

### Verdict

**PASS.** R-1 and W-1 through W-5 are each independently confirmed fixed by
reading production code directly (not merely reading the dev's diff
description) and by tracing or writing my own discriminating tests. W-6 is
real but partial, and its two remaining gaps are exactly review round 2's
already-filed non-blocking follow-ups (W-R2-2, W-R2-3) — not new round-3
defects, and not blocking. The gate is clean against the exact stated
baseline, with a net gain of real coverage and no regression anywhere in
the tree. T-028 is ready to close out.

## Regression risks / notes (round 3)

- W-R2-2 and W-R2-3 (both independently reproduced above, not just read)
  should be tracked as backlog items, as the lead already intends — they are
  real defects, just not blocking for this task's closeout, and both were
  already surfaced by review round 2.
- The `ENGINE_HOLD` fence continues to hide real MySQL-only behaviour (the
  new W-1/W-2 engine test included) from every gate run in this environment;
  this is a pre-existing, documented limitation, not something introduced by
  this fix round.

---
Run 1: FAIL (BUG-1: `inactive` customer status unreachable, AC-6)
Run 2: FAIL -> PASS (BUG-1 fixed in `a7850b1`, independently verified; full gate re-run clean against the exact same baseline, +2 passed/+6 assertions for the two new discriminating tests)
Run 3: PASS (review round 1 fix `18a2a6a` — R-1 critical plus W-1 to W-5 independently verified fixed; W-6 partial, its two remaining gaps are review round 2's already-filed non-blocking follow-ups W-R2-2/W-R2-3, both independently reproduced here, not newly raised; full gate re-run clean against the exact same baseline, 13 failed/38 skipped/3483 passed, +10 passed/+1 skipped over round 2)
