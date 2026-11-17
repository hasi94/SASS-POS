# Code review: T-034 (FP-254) Suppliers, purchase requests and purchase orders

Branch `ai/T-034-suppliers-purchase-orders`. Reviewers: `reviewer34` (round 1), a second code-reviewer (round 2). Read-only; ran no tests or builds. Saved by the lead.

## Round 1 - tip `e89f819` - APPROVE, no Critical, five Warnings

| ID | Finding | Disposition |
|---|---|---|
| R-1 | `approved -> closed` impossible even after a receipt | fixed `bf84440`: allowed when any receipt-application row exists (D-54) |
| R-2 | `Received` targets lacked `Approved`; rollup did not call `assertCanMoveTo` | fixed `bf84440` (D-55) |
| R-3 | D-12 overrides AC-63 for header `expected_date` in `partially_received` | behaviour kept, logged D-56 |
| R-4 | unknown actor id surfaced as a `QueryException` | fixed `bf84440`: refused `validation` "Unknown actor." (D-57) |
| R-5 | approval sync must run after `decide()` commits | documented in database.md 6.6 and both sync docblocks (D-58) |

Suggestions S-1 (migration docblock; committed migration not edited, FP-460) and S-2 (table allow-list rule with planted control, applied). S-3, S-6, S-8 of round 1 were not recoverable by the developer; re-open from the reviewer's original report if wanted.

## QA - PASS (docs/qa/T-034-suppliers-purchase-orders.md, `d77acce`)
395 passed, 0 failed in the purchasing suite; 35 production mutants, 34 killed; the survivor (re-snapshot priced at the wrong date) is killed by the new close-out test. AC-94 measured: 27 queries per added line, 480 for converting a 50-line request.

## Round 2 - commit `bf84440` - APPROVE, no Critical
State machine checked: closing an approved order with nothing received is still refused inside the transaction after the header lock; reopen and cancel unchanged; nothing else reaches `closed`. New tests fail without the changes.

Warnings (filed as FP-461, non-blocking): the S-2 scanner misses aliased `->table('x as y')`, `join` literals and `Lookup::*`/`Uniqueness::*` table arguments; database.md "Owed to later tasks" should say four direct lookups (adds `employees`).
Suggestions: OrderStatus docblock should read "AC-67 as amended by D-54, D-55"; a comment that the new `assertCanMoveTo` guard is defensive; the actor check accepts a soft-deleted employee (matches the foreign key).

## NOT VERIFIED
All lock and deadlock behaviour (SQLite stand-in has no row locks), AC-78 and real-engine schema checks, MySQL value types, a real `schema:publish`, the composed `composer check`, and the 9 env-only gate failures (no `.env` in the worktree).
