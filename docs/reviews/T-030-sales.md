# Code review: T-030 (FP-233) Sales

Branch `ai/T-030-sales`. Reviewer: `reviewer4`, read-only. Saved by the lead from the reviewer's report.

## Round 1 - tip `a2984c0` (after the BUG-1 fix) - APPROVE, no Critical

The reviewer read the code and ran nothing. Inputs: spec rev 6, decisions D-1 to D-77, plan rev 5, QA round 1.

### BUG-1 fix
`LineApprovals.php` and `UpdateSaleLineHandler.php`: the already-consumed path opens only when `UpdateSaleLine` passes a line that, re-read under the sale lock, already carries the override (same product, unit, stored unit price) or the same discount. `AddSaleLine` passes no line, so every new line needs its own approval. Walked: remove and re-add, plain line moved onto the override price, override dropped then restored, unit change at the same price, discount removed and re-applied. All need a fresh approval. No other consuming path (void, refund, goodwill, reconcile) lets one approval authorise more than its binding.

### Warnings

| ID | Finding | Disposition |
|---|---|---|
| W-1 | Partial refunds capped by quantity only, so their sum could exceed what was charged (tier prices; fractional quantities on override lines) | FIXED in the review fix round: AC-27B, D-78 |
| W-2 | An approved, unconsumed approval is a bearer token: no requester binding, no expiry, no goodwill amount ceiling | DEFERRED (D-81); owner question |
| W-3 | `paid_total` and `balance_due` stale while a sale is `pending_payment` | FIXED: AC-13A, D-79 |
| W-4 | `priorConsumed()` could name another line's approver in `price_overridden_by` | FIXED: AC-55A, D-80 |
| W-5 | Most write slices check neither branch scope nor permission themselves | DEFERRED to route layer (FP-236), D-81 |
| W-6 | Customer row locked exclusively in `CreateSale` (throughput, not deadlock) | DEFERRED until the engine is available, D-81 |

### Suggestions (not actioned)
S-1 return the stored result when `CompleteSale` is retried after a lost response; S-2 re-read `requires_approval` under the lock; S-3 branch sequence rows sharing a prefix would collide (latent); S-4 `cascadeOnDelete` from sales to lines, tenders and holds, prefer RESTRICT; S-5 correct `OpenCashShiftHandler` docblock about when the number is taken; S-6 name the direct table reads in `docs/patterns.md`; S-7 prefer the real approval chain over hand-seeded approvals in new tests.

### Verified by reading code
Money (`Money.php` exact parse, no silent rounding, one multiplication in `LineWriter::overridden()`); one lock order across all writers (shift, sale, approval, payment method, discounts ascending, customer, number sequence last) with no cycle; `transaction(..., 2)` retry only as the outermost transaction; replay handling; one audit row per successful write and none on refusal or replay; snapshots; tenant isolation (no tenant column, one bound raw SQL); migrations 003001 to 003009 expand and 003010 the single contract file, nothing destructive; contracts additive; deptrac boundaries.

### NOT VERIFIED
Everything that needs the engine (ENGINE_HOLD): real lock waits and gap locks, the first-use number-sequence deadlock and the single retry against a real deadlock, `usage_limit_reached` after a concurrent last use, close-versus-write race, the two-connection `CompleteSale` case (never built), MySQL JSON normalisation and DECIMAL-as-string handling. `SalesSchemaEngineTest` runs 1 of 8 cases. Whether `AuditWriter` rolls back with a refused write was relied on from earlier tasks.

## Fix round - W-1, W-3, W-4 (developer `dev40`)
Gate after the fix: 13 failed (baseline set by name) / 55 skipped / 5161 passed, 1281 s. Re-check by the reviewer is owed (changed items only).
