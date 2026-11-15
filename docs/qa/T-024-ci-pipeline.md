# QA Report: T-024 CI pipeline (FP-218)

## Verdict: PASS (Round 3)

Round 1 verdict: FAIL -> Round 2 verdict: PASS (lead's fix commit `fa0a52c` reworded both
`docs/backlog.md` and qa's own agent-memory note to describe, not quote, the forbidden
fragment; independently re-verified below) -> Round 3 verdict: PASS (dev's commit `7851890`
closed reviewer Warnings W-1/W-2/W-3 in `CiWorkflowShapeTest.php`; independently re-verified,
see "Round 3" section below).

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

## Round 2 (fix verification)

Scope of the fix: lead's commit `fa0a52c` ("T-024: fix BUG-1 — a committed vocabulary-sweep
violation in prose"), the only change since the round-1 FAIL at `c267880`.

1. **Diff-scope check.** `git diff c267880..fa0a52c --stat` →
   ```
    .claude/agent-memory/qa-engineer/MEMORY.md         |  1 +
    .../project_t024-ci-pipeline-closeout.md           | 42 ++++++++++++
    docs/backlog.md                                    |  2 +-
    docs/qa/T-024-ci-pipeline.md                       | 75 ++++++++++++++++++++++
    4 files changed, 119 insertions(+), 1 deletion(-)
   ```
   Only doc/agent-memory files touched. No production, workflow, or SQL file implicated — matches
   `git show fa0a52c --stat` exactly.
2. **`docs/backlog.md` re-read directly** (not trusted from the commit message): the T-024 row
   (line 51) now reads "...dev fixed two near-misses — a quoted occurrence of the current GitHub
   Actions job-dependencies key, and a doc-comment spelling out the forbidden fragment" — the fix
   describes rather than quotes the fragment. Confirmed with a targeted grep for the literal quoted
   form (`'services'`) against `docs/backlog.md` — no match.
3. **`QaVocabularyRenameSweepTest` re-run solo**:
   `cd modules/host && "E:\xampp\php\php.exe" vendor/bin/pest --filter QaVocabularyRenameSweepTest`
   → **5 passed (35 assertions)**, 7.78s. Round 1 was 1 failed / 4 passed on this same test. The
   regression is gone.
4. **`CiWorkflowShapeTest` re-run solo**:
   `cd modules/host && "E:\xampp\php\php.exe" vendor/bin/pest --filter CiWorkflowShapeTest` →
   **12 passed (75 assertions)**, 6.80s — unchanged from round 1, confirming the fix commit (doc/memory
   only) didn't touch the shipped CI-pipeline artefacts.
5. **Full gate, `composer check` from `modules/host`**: **12 failed, 23 skipped, 2762 passed
   (19308 assertions)**, 1367.36s (ratio 5.70, well inside the manifest's margin). This now matches
   dev's originally reported numbers exactly (2762 passed / 12 failed / 23 skipped) — the one case
   that had shifted from passed to failed in round 1 (`QaVocabularyRenameSweepTest`) is back to
   passing; the remaining 12 failures are all `QaModuleRegistryContractTest` cases by name
   (`it fails fast when a module declares no psr-4 namespace`, `it fails fast when a module names
   no provider class`, `it fails fast when the named provider class cannot be autoloaded`, `it
   names the offending module in the exception rather than the whole chain`) — the same
   long-documented, cross-task, full-suite-only state-pollution cluster (15/15 green standalone)
   seen in T-021/T-022/T-023's own QA and dev reports. Nothing in this task's diff touches that
   test, its subject (`App\Support\ModuleHost`), or `MODULES`/`.env`.

No new defects found in round 2. BUG-1 is closed.

## Round 3 (Warning fix verification — W-1/W-2/W-3)

Scope: reviewer approved T-024 with 0 Criticals but 3 Warnings, all in `CiWorkflowShapeTest.php`
(hardcoded-port blind spot, tenant-registration-ordering/status blind spot, T-023-lesson-reversion
blind spot). Dev's commit `7851890` ("fix review Warnings W-1/W-2/W-3") claimed 3 new assertions,
13 passed / 101 assertions (was 12/75), and that each was proven red by a one-at-a-time revert then
restored. `git show 7851890 --stat` confirmed the commit touches only
`modules/host/tests/Feature/CiWorkflowShapeTest.php` plus two agent-memory files — no production
file.

1. **Diff-scope check.** `git diff 12f3c03..7851890 --stat` → only
   `.claude/agent-memory/developer/MEMORY.md`, a new agent-memory note, and
   `CiWorkflowShapeTest.php` (91 insertions). `.github/workflows/ci.yml` and
   `.github/ci/register-ci-tenant.sql` are untouched since the reviewer-approved commit — confirmed
   directly, not trusted from dev's report.
2. **`CiWorkflowShapeTest.php` re-run solo**: `cd modules/host && "E:\xampp\php\php.exe" vendor/bin/pest tests/Feature/CiWorkflowShapeTest.php`
   → **13 passed (101 assertions)**, 54.42s. Matches dev's reported count exactly.
3. **Independent discrimination checks on all three new assertions** (not two — did all three),
   each via my own revert of the shipped file, confirmed red for the named reason, then restored
   and re-confirmed green via `git checkout -- <file>` + `git diff --stat` (clean):
   - **W-1** (`ci.yml`, job `migrate-fresh`'s `ports:` changed from `- 3306` to `- '3306:3306'`):
     failed at `CiWorkflowShapeTest.php:364` ("job migrate-fresh does not map an ephemeral,
     container-only mysql port", array diff `3306` vs `'3306:3306'`) — the exact assertion and
     exact reason the Warning named. Restored; 13/13 green again.
   - **W-2** (`register-ci-tenant.sql`, the `tenants` row's status changed from `'provisioning'`
     to `'active'`): failed at `CiWorkflowShapeTest.php:525` (`substr_count($executable,
     'provisioning')` dropped from 2 to 1) — the exact D-13 status-pairing assertion. Restored;
     13/13 green again.
   - **W-3** (`ci.yml`, both jobs' healthcheck `--health-cmd` changed from
     `--password=ci-root-dev-only` to a space-split `-p ci-root-dev-only`): failed at
     `CiWorkflowShapeTest.php:371` (`$options` no longer contains `--password=`) — the exact
     T-023-lesson-reversion assertion. Restored; 13/13 green again.
4. **`QaVocabularyRenameSweepTest` re-run solo**: 5 passed (35 assertions) — unchanged from round 2.
5. **Full gate, `composer check` from `modules/host`.** First attempt (started 17:59, 2080.34s,
   `FLEXIPOS-GATE-MARGIN ratio=3.75`) returned **46 failed / 23 skipped / 2729 passed (17243
   assertions)** — far outside any prior baseline (12 failed / 2762–2763 passed / 19308–19334
   assertions in every round-1/round-2/dev run on this task). Failure list included a
   `ProcessTimedOutException` spawning `pest --list-groups` (60s timeout) and a tenancy-fixture
   SQLite `PDOException: table marker already exists` — both signatures of a second process
   sharing this machine's resources, per this project's own documented trap ("Two shared files turn
   one mistake into a hundred meaningless failures" / never-overlap-gate-runs). Investigated instead
   of reported: `Get-CimInstance Win32_Process -Filter "name='php.exe'"` found PID 35616 running
   `E:\xampp\php\php.exe artisan test --testsuite=Feature` (the exact FlexiPOS-prescribed PHP path),
   started 18:24:04 — squarely inside the first run's 17:59–18:33 window — with near-zero
   accumulated CPU even 38 minutes later (hung, not mine; I started no such process). Treated the
   46-failed run as contaminated and **discarded** it rather than reporting it.
   Re-ran clean (full output to a log file, no `tail` truncation this time):
   **12 failed / 23 skipped / 2763 passed (19334 assertions)**, 1275.35s,
   `FLEXIPOS-GATE-MARGIN ratio=6.12`. This matches the task's predicted baseline exactly
   (2763 passed, one higher than the prior round's 2762, exactly accounted for by dev's new
   `CiWorkflowShapeTest` case: +1 case, +26 assertions — 75→101 in that file is +26, and the
   suite-wide total is 19308→19334, also +26. Two independent counts agreeing on the same +26 is
   the reconciliation). Lint, stan and deptrac all reported clean before the test step
   (`{"tool":"pint","result":"passed"}`, PHPStan `[OK] No errors`, deptrac `Violations 0`).
6. **The 12 failures identified by name** and confirmed as the long-documented, cross-task
   `ModuleHost`-boot-fragility cluster (`App\Support\ModuleHost`-adjacent tests, full-suite-only
   state pollution) already seen in T-021/T-022/T-023/T-024-round-2's own QA and dev reports:
   `ModulesEnvParsingTest::it boots with zero modules when MODULES is unset` (1),
   `QaHealthProbeResilienceTest` (2), `QaModuleNameValidationTest` (3),
   `QaModuleRegistryContractTest` (6). Re-ran each file standalone (solo `pest` invocation per
   file, not filtered together): `QaModuleNameValidationTest` and `QaModuleRegistryContractTest`
   are fully green alone (7/7 and 8/8); `ModulesEnvParsingTest` and `QaHealthProbeResilienceTest`
   each still show 1 failure even run completely alone on this machine (the `ModulesEnvParsingTest`
   one reads `MODULES` as `demo` instead of empty when unset — an `.env`-default leak, not a test-
   order artefact on this machine). No stray `bootstrap/cache/config.php` or `*Probe.php` files
   found (checked directly) that would explain it as leftover state from the discarded contaminated
   run. Nothing in T-024's diff (`ci.yml`, `register-ci-tenant.sql`, `assert-tenant-state.sh`,
   `CiWorkflowShapeTest.php`) touches `App\Support\ModuleHost`, `MODULES`, or `.env` — this is the
   same pre-existing, environment-level cluster this project's QA reports have carried across
   multiple tasks, now confirmed to be slightly wider in membership (4 files, not just
   `QaModuleRegistryContractTest`) than my own prior note stated; memory updated accordingly.

**Round 3 verdict: PASS.** All three Warnings are now closed with independently-reproduced
discrimination evidence (not merely trusted from dev's own revert-test report). No new defect
found. The one contaminated gate run was correctly identified as contamination (not reported as a
regression) and discarded in favour of a clean re-run that matches the task's predicted numbers
exactly.

## Regression risks / notes

- The pre-existing `ModuleHost`-boot cluster (12 failures, same signature both in round 2 and round
  3's clean run) is cross-task baseline noise, confirmed again not touched by this task's diff.
- **Never run `composer check` on a machine with another `php artisan test`/`composer check`
  process active in the same or a sibling worktree** — a contaminated run here showed 46 failed
  (vs. the true 12) with a `ProcessTimedOutException` and a shared-SQLite-fixture `PDOException`,
  both signatures of resource contention, not real regressions. Always capture full `composer
  check` output to a log file (never `| tail -N`) so a surprising failure count can be
  investigated rather than guessed at.
- Everything in the 4 CI-pipeline artefacts themselves (`ci.yml`, `register-ci-tenant.sql`,
  `assert-tenant-state.sh`, `CiWorkflowShapeTest.php`) held up under independent re-reading and,
  this round, independent mutation/discrimination testing of all three new assertions, with no
  engine ever contacted (per `ENGINE_HOLD`/D-1).
- AC-9/AC-10/AC-13 to AC-15 and AC-19's runtime half remain correctly fenced NOT VERIFIABLE until a
  real GitHub Actions run — unchanged, not re-litigated here since the fix touched no workflow file.

Run 1: FAIL -> Run 2: PASS -> Run 3: PASS (Warnings W-1/W-2/W-3 closed, independently re-verified).
