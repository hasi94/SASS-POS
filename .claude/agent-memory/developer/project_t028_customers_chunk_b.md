---
name: t028-customers-chunk-b
description: T-028 Customers chunk B (steps 5-7, the arithmetic core) - what shipped, the float/TypeError resolution, and the gate timing anomaly
metadata:
  type: project
---

Measured 2026-11-10, one `dev` session, continuing from [[t028-customers-chunk-a]].
Shipped steps 5 (12 contract files under `packages/contracts/src/Customers/`), 6
(`Ledger\CreditLedgerCalculator`/`CreditPosition`/`CreditOutcome`/`EntrySide`), 7
(`Loyalty\LoyaltyCalculator`/`LoyaltyOutcome`/`TierRules`), plus `Support\Decimal`
(Ruling 3.1's shared parser) and `Exceptions\CustomersRefused`/`CustomersRefusal`
(not explicitly named as a separate file in the plan's section-3 table, but needed
one session early because `TierRules::parse()` needed a refusal vocabulary - built
following the `CatalogRefused`/`OrganizationRefusal` two-file split exactly).

**The "a float gives invalid_amount" line in the plan is not literally true for the
public calculator signature.** `CreditLedgerCalculator::apply()`/`LoyaltyCalculator::apply()`
are typed `int|string` per Ruling 4/5 exactly as written. In a `declare(strict_types=1)`
file (every test file in this codebase), handing either a float raises `TypeError` at
the call boundary, never reaches the business refusal. Measured precedent already
in the tree: `packages/catalog/tests/Units/SnapshotCalculatorTest.php`'s "refuses a
float quantity" test and `packages/catalog/tests/Contracts/ResolvedFactorShapeTest.php`'s
"refuses a float factor" test both assert `TypeError::class`, via a `mixed`-typed
wrapper function that defeats static analysis but not the runtime type check. Followed
that precedent exactly for T-028's two calculators rather than the plan's literal
wording - a deviation, reported to `lead`, not a silent reinterpretation.

**Gate timing anomaly, flagged per Ruling 12 ("more than 10% growth, stop and report"):**
chunk A's own end-of-chunk gate measured the `test` step at 955.52s; chunk B's end
measured it at 1702.58s (FLEXIPOS-GATE-MARGIN line) - a ~78% jump for 73 added pure
tests. A targeted run of just those 73 new tests took 11.33s. 16 unrelated `php.exe`
processes (other projects' dev servers on this machine, confirmed by command line)
were running throughout the measurement window. The jump is very likely machine
contention, not real suite cost - but it was reported to `lead` rather than silently
waved away, since Ruling 12's own threshold was measured as exceeded. Exit code,
pass/fail/skip counts (13 failed/31 skipped, pass count exactly +73) and the ratio
against the 7800s hard limit (4.58, i.e. ~22% used) all stayed exactly where chunk A
left them otherwise.

**The same exit-code trap as [[gate_reads_live_filesystem_never_edit_during_it]], a
new shape of it:** `composer check > log 2>&1; echo "EXIT_CODE=$?"` (semicolon, not
`&&`) still loses the real exit code to the final `echo`, which always succeeds -
exactly like that memory's `tee | tail` case, just via `;` instead of a pipe. Caught
it by reading the backgrounded task's own stdout (which held only the `echo` line,
"EXIT_CODE=2") rather than trusting the notification's reported code. **Always
capture the gate's own exit code into the echoed string and read it back - never
chain a second command with `;` or `&&`/`||` after a long-running gate command and
trust the chain's own reported status.**
