# Code review: T-032 (FP-260) Chart of accounts, fiscal years and periods

Branch `ai/T-032-chart-of-accounts`. Reviewers `reviewer32`, `reviewer32b`, read-only. Saved by the lead from their reports.

## Round 1 - tip `997a682` - APPROVE, no Critical, five Warnings
W-1 the period gate's locking read could lock many rows (fixed: one latest-period row, end date checked in PHP). W-2 year-name check shared-locked every year out of order (fixed: compared in PHP over the ascending locked chain). W-3 reactivate and deactivate locked parent and child in opposite orders (fixed: parent shared first). W-4 a subtype change was unguarded (fixed: AC-15a, `AccountRules::resubtype`). W-5 four as-built deviations without a decision (logged as D-31 to D-33). Suggestions S-1 (always-true loop), S-3 (memoise referrers) applied.

Judgement on the named deviations: year name cap 46 (a period is named `<year> P01`, so 47 to 50 cannot name its own periods; rename now capped the same), `no_fiscal_period`, `invalid_transition` on reopening a non-closed year, year reopen not locking periods, period names not unique: all acceptable.

## Round 2 - tip `3432504` - APPROVE, no Critical, three Warnings
R-1 create locked the latest year before the chain (cycle with rename and reopen) - fixed: chain first. R-2 under `utf8mb4_unicode_ci` an accent pair collides on `unique(name)` but not in PHP, so the backstop gave the wrong reason - fixed: the backstop decides by elimination. R-3 docblocks overstated the lock order - reworded; retype (child before parent) and the late re-lock path added to D-33's known pairs. Polish round applied S-a to S-e.

NOT VERIFIED (ENGINE_HOLD): every lock and deadlock behaviour, the race ACs (AC-12, 17, 21, 35, 44, 50, 57), real-collation behaviour, the contract migration on a populated engine, the two-attempt deadlock retry. The usage ports (AC-14, 15, 19, 22, 23, 37, 41) refuse nothing in production until FP-261 binds them.
