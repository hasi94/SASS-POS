---
name: t029-pricing-chunk-a
description: T-029 chunk A (plumbing, 12 migrations, products trio) - baseline numbers, the host tests a contract step at the tree top reddens, and two instrument blindnesses found
metadata:
  type: project
---

Measured 2026-11-15, branch ai/T-029-taxes-pricing (T-028 chunks A+B underneath, tenant top was 51).

**Numbers.** Step 0 `composer check`: exit 2, 13 failed / 31 skipped / 3353 passed, `test` step 939.58s. The 13 are
env/vocabulary failures (MODULES-unset boots, `QaVocabularyRenameSweepTest` flagging an agent-memory line and
`frontend/package-lock.json`), identical by name after chunk A: 13 / 31 / 3387, `test` 879.66s. Compare by name,
a `sort`-ed list of the `FAILED` lines.

**A contract step at the TOP of the shipped tree reddens `BranchForeignKeysContractRunTest` (2 tests)** - the plan's
section 9 did not name it. Fix: a `bfkRunT029V()` helper by name and one more stop (`T029V - 1`) in the "to the top" runs.
The 24-test kernel planner cluster from T-010 did NOT recur: the corpus already tolerates a contract step.

**Two PHPStan child-process tests time out (60s) in a bare `pest` run after a `phpstan.neon.dist` change**
(`HostTestAnalysisEnforcementTest`, `QaTestCodeAnalysisCoverageTest`): the config edit busts the result cache. Not a
defect: `composer check` runs stan first, which warms it. Run `phpstan` once, then re-run those two.

**`SourceRules::isDestructive` is blind to `->change()` inside `Schema::table($table, ...)` with a VARIABLE table
name** (the T-014 `add_branch_foreign_keys` shape, copied for T-029's contract file). The literal-table spelling is
caught. So the contract file's non-destructiveness rests on my own AC-29.2 regex test, not on the catalogue column.

**`SourceRules::noTimestampColumn` reads any `->timestamp(` call as a column**, including `RunnerClock::timestamp()`
inside a migration. Use `->now()->format(RunnerClock::PRECISION)`.

**The backfill migration seeds a STANDARD default tax group even into an EMPTY tenant** (by design, D-6). Any test that
migrates the whole tree starts with one default `tax_groups` row, so a second `is_default` insert refuses.

**Sites a new tenant migration set edits** (beyond the plan's list): `IdempotencyKeysSchemaTest` top-entry literal,
`BranchForeignKeysContractRunTest`, `CatalogTenantSchemaTest` (products columns and the tree-wide FK list).

Related: [[project-tenant-tree-top-version-is-pinned-everywhere]], [[project-t028-customers-chunk-a]].
