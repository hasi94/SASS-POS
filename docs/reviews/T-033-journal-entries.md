# Code review: T-033 (FP-261) Journal entries and lines, posted through one service

Branch `ai/T-033-journal-entries`. Reviewer: read-only, ran no tests or builds. Saved by the lead.

## Round 1 - tip `dfc59b4` - APPROVE, no Critical, two Warnings

| ID | Finding | Disposition |
|---|---|---|
| R-1 | `Amounts::fromStored` read floats through `(string) $float` (14 digits), so the SQLite stand-in silently dropped digits (`12345678901.2345` read back as `...2350`); a double cannot hold `99999999999999.9999` | fixed: `sprintf('%.4F')`, docblock states exactness to about 15 digits, red-first tests (3 failed before the fix). Production (MySQL, DECIMAL as string) was never affected |
| R-2 | the comment in `JournalReferences` said plain reads avoid shared locks; on InnoDB the FK check on the line insert takes shared locks on dimension parents after the sequence lock | fixed: comment corrected and the Ruling-9 lock table gained the line; NOT VERIFIED (ENGINE_HOLD); measure after G-1 (FP-466) |

Suggestions: S-1 refuse Unicode format characters in journal text (FP-463); S-2 short-circuit the line scan before the 200-line limit (FP-464); S-3 and S-4 comment-only, applied.

Done well (reviewer): strict string money grammar, BigDecimal with `Unnecessary` rounding, check order exactly as Rulings 7, 8 and D-38, replay before the gate (AC-37), two guards on a lost reversal race, `JournalWriter` the only writer with one three-column update, the `-` purpose sentinel safe against aliasing, authorization before any read or transaction, idempotent migration backfill and a retry-safe contract migration.

## QA - docs/qa/T-033-journal-entries.md (`dfc59b4`)
Gate at `6734ccf`: pint, phpstan, deptrac clean; pest 10 failed, 1208 passed. Nine failures are env-only (no `.env` in the worktree), the tenth is the baseline vocabulary sweep. One minor process defect (an untracked memory file quoting retired vocabulary) was fixed. AC-1..AC-82 each have a passing test; 6 of 7 mutants killed, the survivor (`where status='posted'` in `markReversed`) is killed by the new compare-and-set test.

## NOT VERIFIED
Every `[ENGINE]` clause: AC-25, 26, 30 (lock order, gate versus close), AC-33a, 40, 57 (concurrent numbering, same-key post, reversal race), AC-45 (CHECK enforcement), AC-64 (engine query plan), AC-80 (collation), generated-column and CHECK acceptance on MariaDB 10.4 and MySQL 8.4, and the deadlock/retry case (needs two blocked statements; owed with FP-262). The fix round (R-1, R-2) was verified by targeted runs only (finance directory 444 passed, 0 failed; lint, stan, deptrac clean), not a second full gate.
