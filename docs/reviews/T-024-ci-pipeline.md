# Review: T-024 — CI pipeline

## Round 1 — APPROVE (implementation commit `d96af66`)

No Criticals. The CI configuration (`.github/workflows/ci.yml`, `.github/ci/register-ci-tenant.sql`, `.github/ci/assert-tenant-state.sh`, `modules/host/tests/Feature/CiWorkflowShapeTest.php`) is correct for what this task proves without a real GitHub Actions run. `ENGINE_HOLD`'s one sanctioned exemption (D-1 — the human's own explicit ruling that a CI-only, ephemeral, disposable database service container is outside the hold's scope) was used correctly and not overreached: the `quality` job has no service container at all, and only the two migration jobs' own throwaway containers ever imply a real-engine connection, which happens only inside an actual GitHub Actions run nobody on this team can trigger.

D-13's `provisioning`-status claim was independently re-verified against `PlanMigrationRunHandler::assertContractStepsUnblocked()` (lines 99, 375) and `TenantStatusPolicy::NAMED_ONLY_STATUSES` (line 57) — correct. Both T-023 lessons were applied directly to the workflow: the healthcheck password is one argv token (`--password=`), and a real `SELECT VERSION()` step follows `mysqladmin ping` since ping passes even on an auth failure.

**Warnings (folded into a fix round, per this project's practice when a Warning undermines a test's own stated purpose):**
- **W-1** `CiWorkflowShapeTest`'s AC-17 port check never looked at the MySQL service's `ports:` list — a hardcoded `3306:3306` mapping (the shared engine's conventional address) would have slipped through.
- **W-2** The test never read `.github/ci/register-ci-tenant.sql` at all, and never checked that `migrate --database=master`/`schema:publish` precede the register step.
- **W-3** The test didn't pin either T-023 lesson the workflow applies correctly (one-argv-token password, post-ping version check) — if either were reverted, the test would stay green.

**Suggestions filed as follow-ups (non-blocking):** FP-401 to FP-403 (a case-sensitivity gap, a missing ordering assertion, a missed long-flag password variant) plus FP-404 (a stray hung process found during QA, not a code defect).

## Round 2 — APPROVE (fix commit `7851890`)

All three Warnings closed, independently re-verified by both `qa` and `reviewer`:

- **W-1 closed.** `expect($mysql['ports'] ?? null)->toBe([3306])` — strict equality against the YAML parser's actual output (`- 3306` parses as the integer `3306`; `3306:3306` or any fixed mapping parses as a string and fails). QA independently discrimination-tested this against the real file.
- **W-2 closed.** A new test reads `register-ci-tenant.sql` directly, asserting `CREATE DATABASE flx_t_ci` present and no `DROP`/`IF NOT EXISTS` in the *executable* SQL — a new `ciRegisterTenantSqlExecutable()` helper strips `--`-comment lines first, since the file's own explanatory header comments literally contain those words and a raw-text check would fail against the already-approved file for the wrong reason. Reviewer verified the comment-stripping is sound (LF line endings confirmed via `.gitattributes`/`git ls-files --eol`; the file uses only whole-line `--` comments; no executable line starts with `--`; the helper can only err toward failing, never toward hiding something real). Step-order assertions (register after `migrate --database=master`/`schema:publish`) each guard against a missing step (`-1`) before the ordering comparison, so they can't pass vacuously.
- **W-3 closed.** Assertions that the healthcheck uses `--password=` (not space-split `-p`) and that a `SELECT VERSION()`/`8.4` step precedes the first `tenant:migrate` step.

All three fixes were discrimination-tested independently by `dev`, then again independently by `qa` (editing the real shipped files, confirming red for the named reason, restoring, confirming green) — not just taken on report.

**New Suggestions (non-blocking, filed as follow-ups):**
- **S-1** (FP-401) The `IF NOT EXISTS` check is case-sensitive while the `DROP` check is case-insensitive.
- **S-2** (FP-402) The version-check-before-migrate assertion doesn't also require it before `migrate --database=master`.
- **S-3** (FP-403) The password-format check misses a space-separated long `--password` flag.
- **S-4** (not filed — already proven by dev's own discrimination test) `substr_count(..., 'provisioning') === 2` proves the word appears twice but not which row each occurrence belongs to; acceptable for a shape test.

## QA

- Round 1: FAIL, 1 major defect (a doc-prose vocabulary-sweep violation in the lead's own backlog commit, not production code) — fixed, round 2 PASS.
- Round 3 (Warning-fix verification): PASS, 0 new defects. Independently discrimination-tested all three Warning fixes against the real shipped files. Caught and correctly diagnosed a contaminated gate run (a stray, unrelated `php artisan test` process sharing the worktree) before it could be mistaken for a regression — discarded and re-ran clean: 2763 passed / 12 failed (pre-existing `ModuleHost`-boot cluster) / 23 skipped, matching the task's predicted baseline exactly.

## Final verdict

**APPROVE.** T-024 is DONE.
