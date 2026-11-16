---
name: t030-sales-chunk-a
description: T-030 Sales chunk A (steps 0-8) - merged-tree baseline numbers, the one unpredicted red, the lock-marking grammar trick, and the plan literal that did not survive contact
metadata:
  type: project
---

Measured 2026-11-15, branch ai/T-030-sales, tenant top 63 -> 73, 58 -> 66 tables.

**Numbers.** Step 0 `composer check` on the merged tree: exit 2, 13 failed / 48 skipped / 4240 passed, `test` step
1040.19s (10 percent stop line 1144s). The 13 are the usual no-APP_KEY set, same names after chunk A, plus nothing.
End of chunk A: 13 failed / 48 skipped / 4333 passed, `test` 1190.08s (+14 percent; an earlier run of the same tree read
1264s, so run-to-run noise is about 6 percent). Two BranchForeignKeysContractRunTest cases grew one fleet run each
(35s -> 49s and 42s -> 59s); the rest is every tree-applying test now running 10 more migration files.

**The one red the plan did not predict: `QaAssertionShapeGuardTest`.** A negated multi-needle
`->not->toContain('a', 'b', 'c')` passes when EITHER needle is missing, so the repo's guard bans it suite-wide. Absence
of N columns is asserted as `expect(present($table, [...names]))->toBe([])` with a collector helper. I had written four
of them from memory of Pest's semantics; the guard caught what no mutation would have.

**LockMarkingGrammar** (organization and pricing test support): a SQLite grammar subclass whose `compileLock` writes
the lock clause back as a comment (`/* for update */`). Unlike the older `MySqlGrammar` + `pretend()` pin, the SQL
really executes, so a code path that depends on a result (a lost insert race, then a retry read) can be followed and
the log shows which reads were locking. It proves the clause was sent, never that the engine blocks.

**Plan literals that needed a reading.** `AuditCatalogueTest` gaining `sales` moves with the first audit-action
declaration (provider declares nothing in chunk A, the plan allows this). `HandlerPinVerifier` is bound with
`bind()` not `singleton()` because the handler it wraps is built per resolution and a singleton would freeze its hasher.
`QaAccessCloseOutSweepsTest` did not go red until `tenantTreeTables()` listed the new tables (it diffs that list).

**Tools.** `schema:publish --write-fixture` republishes the catalogue (pure additions). Pipe pest output through
`sed 's/\x1b\[[0-9;]*m//g'`: coloured long-dataset output is unreadable. Bare `pest` over host Feature tests that
migrate the tree takes minutes: use the background-with-bounded-wait loop, a foreground `until` is moved to background
after 180s.

Related: [[project-t029-pricing-chunk-a]], [[project-tenant-tree-top-version-is-pinned-everywhere]].
