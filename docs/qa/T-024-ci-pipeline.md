# QA Report: T-024 CI pipeline (FP-218)

## Verdict: FAIL

One major defect: dev's own `IN_QA` status-update commit (`c267880`, to `docs/backlog.md`)
introduces a new, reproducible `QaVocabularyRenameSweepTest` failure. That test is part of
`composer test`/`composer check`, so the gate dev reported as "12 failed (pre-existing,
unrelated)" is, at the actual HEAD this QA round inspects, 13 failed — one of them caused by
this task's own change. Per CLAUDE.md/team-protocol, one failing existing test means FAIL.

Everything else inspected — all 4 new files, the workflow shape, the SQL/shell helpers, the
spot-checks, the diff scope — held up under independent re-verification. See below.

## Acceptance criteria coverage

| AC | Test(s) / evidence | Result |
|----|---------|--------|
| AC-1 | `CiWorkflowShapeTest::it parses as YAML...` — re-ran solo, green | PASS |
| AC-2 | same test, `triggers on push and pull_request...` | PASS |
| AC-3 | same test, `runs install, lint, stan, deptrac...` + raw-text check: `grep -n "test:fast"` → no output | PASS |
| AC-4 | same test, `quality job with no services block...` | PASS |
| AC-5 | same test, `has at least two further, distinct jobs...` | PASS |
| AC-6 | same test + `grep -n -i "docker.compose\|docker-compose" .github/workflows/ci.yml` → no output | PASS |
| AC-7 | same test, `gives each migration job its own independent mysql:8.4 service with no volumes` — read `ci.yml` directly, confirmed `image: mysql:8.4`, no `volumes:` key under either `services.mysql` block | PASS |
| AC-8 | same test, `runs the fresh-database job to head with no --to flag...`; read `register-ci-tenant.sql` against `2026_10_14_000105/000112/000113` migrations directly — every NOT-NULL-without-default column is present (see Defects section, none found here) | PASS |
| AC-9 | **[NOT VERIFIABLE UNTIL A REAL RUN]** — spec fencing confirmed present and accurately worded (read `docs/specs/T-024-ci-pipeline.md` lines 108-114); `assert-tenant-state.sh` read for correctness (see below) | FENCED, correctly |
| AC-10 | No assertion exists for this (by design, per plan — containers are job-scoped with no `volumes:`, which AC-7 checks statically); confirmed no `volumes:` anywhere in either migration job's `services.mysql` block | PASS (static half only) |
| AC-11 | `CiWorkflowShapeTest`, same case as AC-7, asserts 2 distinct job keys | PASS |
| AC-12 | same test, `runs the existing-database job to head-1 then to head...` — 3 distinct `tenant:migrate` steps, correct `--to=` placement | PASS |
| AC-13 | **[NOT VERIFIABLE UNTIL A REAL RUN]** — fencing present and accurate (lines 136-142) | FENCED, correctly |
| AC-14 | **[NOT VERIFIABLE UNTIL A REAL RUN]** — fencing present and accurate (lines 143-147) | FENCED, correctly |
| AC-15 | **[NOT VERIFIABLE UNTIL A REAL RUN]** — fencing present and accurate (lines 148-154); static shape (3rd invocation, no `--to`) covered by the AC-12 test case | FENCED, correctly |
| AC-16 | `CiWorkflowShapeTest`, `uses inline dev-only placeholder credentials...` + `grep -n "secrets\." .github/workflows/ci.yml` → no output | PASS |
| AC-17 | `CiWorkflowShapeTest`, `names no host but 127.0.0.1...` — every `_HOST` value is `127.0.0.1`, every `_PORT` value routes through `job.services.mysql.ports` | PASS |
| AC-18 | `CiWorkflowShapeTest`, `sets no continue-on-error anywhere...` + `grep -n "continue-on-error" .github/workflows/ci.yml` → no output | PASS |
| AC-19 | Static half (ordering/fail-fast) covered by the AC-3 test case; runtime skip-reporting half correctly fenced **[NOT VERIFIABLE UNTIL A REAL RUN]** (spec line 183) | PASS (static) / FENCED (runtime) |
| AC-20 | `CiWorkflowShapeTest`, same case as AC-2 (no `paths`/`paths-ignore` anywhere in `on:`) | PASS |
| AC-21 | Design statement per spec; static half (empty-DB job order) covered by the AC-8 test case | PASS |

## Independent verification performed

1. **`CiWorkflowShapeTest.php` re-run solo**: `E:\xampp\php\php.exe vendor/bin/pest tests/Feature/CiWorkflowShapeTest.php` → **12 passed (75 assertions)**, matching dev's reported number exactly.
2. **`register-ci-tenant.sql` read against the real migration column definitions** (`2026_10_14_000105_create_tenants_table.php`, `..._000112_create_database_servers_table.php`, `..._000113_create_tenant_databases_table.php`), no engine run. Every NOT-NULL column without a default is supplied for all three inserted rows (`database_servers`, `tenants`, `tenant_databases`), including the easily-missed `profile` column on `tenant_databases` (no default, present). Status value is the literal string `provisioning` on both the `tenants` and `tenant_databases` rows, matching D-13's requirement. `CIMIGRATIONSERVERXXXXXXXXX` / `CIMIGRATIONTENANTXXXXXXXXX` are each exactly 26 ASCII characters, matching the `IdentifierColumn::primary()`/`ulid()` `CHAR(26)` column type (read `packages/kernel/src/Schema/Support/IdentifierColumn.php`). No `DROP`, `CREATE DATABASE` has no `IF NOT EXISTS`, matching the plan.
3. **D-13's claim verified directly against source**, not trusted from the decision doc: `PlanMigrationRunHandler::BLOCKING_EXEMPT_STATUS = 'provisioning'` (line 99) and `TenantStatusPolicy::NAMED_ONLY_STATUSES = ['provisioning', 'failed']` (line 57) — both confirmed by `grep`.
4. **`assert-tenant-state.sh` syntax check only**: `bash -n .github/ci/assert-tenant-state.sh` → no errors. Read for correctness: it reads `tenant_databases.schema_version` (master) and `flx_t_ci.migrations` row count (tenant) for AC-9/AC-13/AC-14, and optionally `tenant_migration_runs.total` for AC-15's "zero jobs planned" check — this is the right pair of stores per the architect's AC-9/AC-13 correction (version lives in master, row-per-migration lives in the tenant). No engine was ever contacted.
5. **MySQL healthcheck password/version-check re-read directly from `ci.yml`**: `--password=ci-root-dev-only` is one argv token (T-023 BUG-1 lesson applied), and both migration jobs have a separate "Verify MySQL 8.4 and the credentials" step running `mysql ... SELECT VERSION()` and failing unless the result starts with `8.4` — this is the T-023 review correction (`mysqladmin ping` passes even on auth failure) correctly applied, confirmed present in both `migrate-fresh` and `migrate-existing`.
6. **Host/port scan**: every `_HOST` value in the parsed workflow is `127.0.0.1`; every `_PORT`-suffixed value routes through `job.services.mysql.ports`; `job.services.mysql.ports['3306']` appears exactly where the plan says it should (`DB_MASTER_PORT` export step). No `secrets.` reference anywhere (`grep` confirms). No `docker compose`/`docker-compose` reference anywhere.
7. **`QaVocabularyRenameSweepTest` re-run solo**: `E:\xampp\php\php.exe vendor/bin/pest tests/Feature/QaVocabularyRenameSweepTest.php` → **FAILED, 1 failed / 4 passed (35 assertions)**. See BUG-1 below. This directly contradicts dev's backlog claim ("`QaVocabularyRenameSweepTest` 5/5 (dev fixed two near-misses...)").
8. **Full gate, `composer check` from `modules/host`**: **13 failed, 23 skipped, 2761 passed (19308 assertions), 1072.37s**. Compare to dev's reported 2762 passed / 12 failed / 23 skipped — the totals (2797) match exactly, but one case moved from passed to failed: `QaVocabularyRenameSweepTest`'s single case, confirming it is not flake, it is a real, newly-introduced, reproducible regression. The remaining 12 failures match the long-standing, independently-documented `QaModuleRegistryContractTest` state-pollution cluster (confirmed by test name in this run's own output — "it fails fast when the named provider class cannot be autoloaded", "it names the offending module in the exception..." — the same cluster T-023's QA report and others describe as pre-existing, 15/15 green standalone, unrelated to any task).
9. **Diff scope**: `git diff c251ab5..d96af66 --stat` shows exactly the 4 files dev reported (`.github/ci/assert-tenant-state.sh`, `.github/ci/register-ci-tenant.sql`, `.github/workflows/ci.yml`, `modules/host/tests/Feature/CiWorkflowShapeTest.php`) plus `docs/backlog.md`, `docs/decisions/T-024-ci-pipeline.md`, `docs/specs/T-024-ci-pipeline.md` (BA/plan-cycle doc edits, expected). `CLAUDE.md`, `.env.example`, `composer.json`, `composer.lock` are untouched. `git status --short` at HEAD (`c267880`) is clean (everything already committed, including the defect).

## Test run summary

- `E:\xampp\php\php.exe vendor/bin/pest tests/Feature/CiWorkflowShapeTest.php` → 12 passed (75 assertions), 1.11s.
- `E:\xampp\php\php.exe vendor/bin/pest tests/Feature/QaVocabularyRenameSweepTest.php` → 1 failed, 4 passed (35 assertions), 4.18s.
- `bash -n .github/ci/assert-tenant-state.sh` → syntax OK, not executed.
- `grep -n "secrets\."/"docker.compose"/"test:fast\|continue-on-error" .github/workflows/ci.yml` → all empty, as required.
- `cd modules/host && composer check` → **13 failed / 23 skipped / 2761 passed (19308 assertions)**, 1072.37s wall. Exit reflects the test step's failure (gate is `@probes:clean @engine:clean @lint @stan @deptrac @test`; lint/stan/deptrac all reported clean before the test step ran, consistent with dev's report — not independently re-confirmed line-by-line beyond their presence in this same run's log, since the composer script runs them in one invocation and the tail of the captured log is what's quoted above).

## Defects

### BUG-1 (major)
- **Steps / failing test**: `cd modules/host && "E:\xampp\php\php.exe" vendor/bin/pest tests/Feature/QaVocabularyRenameSweepTest.php` (solo, and reproduced inside the full `composer check` gate run).
- **Expected**: 5 passed, 0 failed — dev's own backlog-row claim ("`QaVocabularyRenameSweepTest` 5/5 (dev fixed two near-misses — a quoted `'services'` literal and a doc-comment spelling out the forbidden fragment)").
- **Actual**: 1 failed, 4 passed. The sweep reports: `docs/backlog.md:51: the former quoted key or JSON field`.
- **Root cause**: `docs/backlog.md` line 51 (the T-024 row, written by dev's own `c267880` "move to IN_QA" commit) contains the literal text `` a quoted `'services'` literal `` while *describing*, in prose, the near-miss dev says they fixed elsewhere. That description itself quotes the forbidden word in quotes (`'services'`), which is exactly the pattern `QaVocabularyRenameSweepTest`'s `'the former quoted key or JSON field'` check (`/['"]services['"]/`) exists to catch. Confirmed by direct `git show`: the string `'services'` is absent from `docs/backlog.md` at the prior commit (`024a276`, `T-024: move to IN_DEV`) and present at `c267880` — i.e. introduced by this task's own status-update commit, not pre-existing.
- **Impact**: This is a real, reproducible, committed (not merely working-tree) regression — stronger than the historical "untracked scratch note" version of this trap (see agent memory: `project_gate-split-and-shared-state.md`), because it ships on the branch itself and would be red for anyone, on any machine or in CI, who checks out `c267880` and runs `composer check`. Dev's own gate-count claim ("12 failed... unrelated") does not hold at the commit QA was asked to verify; the actual number is 13, with one directly attributable to this task.
- **Suspected location / fix**: `docs/backlog.md` line 51 — reword the T-024 backlog row to *describe* the near-miss rather than quote it (e.g. "a quoted plural literal" instead of `` `'services'` ``), per this project's own established convention for this exact trap. This is a documentation fix, not a workflow/SQL/test-file change — none of the 4 shipped CI-pipeline artefacts are implicated.
- **Not independently re-verified by QA**: whether `docs/decisions/T-024-ci-pipeline.md` or `docs/specs/T-024-ci-pipeline.md` (also touched by this commit range) carry the same or a related trap — the sweep's own report names only one surviving location (`docs/backlog.md:51`), so no further search was needed to confirm this specific finding, but a broader re-sweep after the backlog fix is still the correct re-verification step.

## Regression risks / notes

- The `QaModuleRegistryContractTest` cluster (12 failures in this run) is the same long-documented, cross-task, state-pollution-under-full-suite-only baseline gap (15/15 green standalone) that has appeared in T-021/T-022/T-023's own QA and dev reports; nothing in this task's diff touches that test, its subject (`App\Support\ModuleHost`), or `MODULES`/`.env`. Not re-litigated here beyond confirming the failure names match the known cluster signature.
- Everything in the 4 CI-pipeline artefacts themselves (`ci.yml`, `register-ci-tenant.sql`, `assert-tenant-state.sh`, `CiWorkflowShapeTest.php`) held up under independent re-reading against real migration/handler source, with no engine ever contacted (per the task's hard constraint and `ENGINE_HOLD`/D-1).
- Once BUG-1 is fixed (a `docs/backlog.md` wording-only change), re-run should be a fast re-verification: re-run `QaVocabularyRenameSweepTest` solo for 5/5, then re-run the full gate once to confirm it returns to 12 failed / 2762 passed / 23 skipped (or whatever the `QaModuleRegistryContractTest` cluster's current flaky count is) with no other change.
