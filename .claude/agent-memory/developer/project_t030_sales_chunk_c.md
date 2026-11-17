---
name: t030-sales-chunk-c
description: T-030 Sales chunk C (steps 13-18) - gate numbers, the pricing_refused reason the plan lacked, SalesQueryLog nesting fix, and test-writing traps on the SQLite stand-in
metadata:
  type: project
---

Measured 2026-11-15/16, branch ai/T-030-sales. Final gate: 13 failed / 48 skipped / 4981 passed, `test` step 1203.71s
(reference 1174s, +2.5 percent). The 13 are the usual no-APP_KEY set by name; lint, stan, deptrac clean.

**Plan gap, resolved with one new case.** Ruling 8 says a pricing refusal's code is the cause's own value, but
`SalesRefusal` is a closed enum with no case for `unknown_product`/`not_applicable`. Added `SalesRefusal::PricingRefused`
plus `SalesRefused::fromPricing/fromUnit/fromCredit` and `code()` (cause value for `pricing_refused`). The registry test
pins 43 codes again (43 - refund_not_allowed + pricing_refused).

**`SalesQueryLog::inTransaction` stopped at the first END**, and a ledger posting opens a savepoint inside the writer's
transaction, so every log read after it was silently truncated. It now counts depth. Any lock-order pin that spans a
nested writer needs that version.

**Stand-in traps.** Raw row reads give decimals as int/float: assert with `toEqual('2.0000')` or `toMatchArray`, never
`toBe`. A refused handler rolls back the whole connection transaction, so a "rival" write made from a query listener
inside it disappears too: assert the refusal, not the rival's row. A sale `held` row needs a `sale_holds` hold row or
recall is `invalid_transition`.

**Tooling.** Heredocs with apostrophes die and shell/python string escaping eats `\` in PHP namespaces: write PHP with the
Write/Edit tool, and use a PHP script for multi-site edits. `SalesWorld` (tests/Support) is a class, not functions, because
Pest file-level functions collide across files; per-file helpers use a unique prefix (`asl`, `cs`, `at`).

**Mutations run (all restored with cmp):** M5, M7, M9, M10 (CompleteSale), M8 (TenderRules). M4 needs step 23's scan, not built.
Related: [[project-t030-sales-chunk-a]].
