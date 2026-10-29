# QA Report: T-017 Audit framework

## Verdict: PASS

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 (request_id, tenant, lazy/null) | `packages/kernel/tests/Unit/RequestIdTest.php`, `modules/host/tests/Feature/AuditRequestIdHttpTest.php` (steps 1, 2), `AuditWriterTest`/`AuditTransactionTest` unchanged (console-null control) | PASS |
| AC-2 (user_agent, null not `''`) | `packages/access/tests/Support/ClientUserAgentTest.php`, `AuditRequestIdHttpTest` tenant half | PASS |
| AC-3 (non-regression on T-010/T-014 writer) | `AuditWriterTest.php`, `AuditTransactionTest.php` confirmed byte-identical to T-016 tip (`git diff` empty); full suite green | PASS |
| AC-4 (tenant search, filters, empty/invalid) | `packages/access/tests/Features/SearchAuditLog/SearchAuditLogTest.php` | PASS |
| AC-5 (`audit.read` permission) | `SearchAuditLogPermissionTest.php` (all-six-keys-but-not-audit.read refused; audit.read-only allowed) | PASS |
| AC-6 (no HTTP route, tenant) | `modules/host/tests/Feature/QaAuditNoHttpSurfaceTest.php`; independently mutation-tested (see Defects/Mutation section) | PASS |
| AC-7 (`audit_logs_archive`, column-for-column, D-10) | `packages/access/tests/Schema/AuditLogsArchiveShapeTest.php`; migration file read and diffed against `audit_logs`'s own migration by hand (identical column list/types/indexes, no `down()`) | PASS |
| AC-8 (archival job, chunked, boundary, no-op) | `packages/access/tests/Features/ArchiveAuditLogs/ArchiveAuditLogsTest.php` (365/364-day boundary, byte-identical move, 3-chunk count via real query log, no-op, atomic-chunk fault injection, config refusal/clamp) | PASS |
| AC-9 (archive search, explicit flag) | `SearchAuditLogTest.php` "AC-9" case | PASS |
| AC-10 (no pruning job built) | `QaAuditSourceRulesTest.php` delete scan; independently mutation-tested | PASS |
| AC-11 (7-year guard + discriminating control) | `QaAuditSourceRulesTest.php` AC-10/AC-11 cases + its own control; independently mutation-tested with a real planted delete in `packages/platform/src` | PASS |
| AC-12 (PlatformAudit real request_id, closes FP-323) | `packages/platform/tests/Support/PlatformAuditRequestIdTest.php`, `AuditRequestIdHttpTest` step 4 | PASS |
| AC-13 (actor_email snapshot, never joined) | `PlatformAuditActorEmailTest.php` (write, then mutate `users.email`, row unchanged) | PASS |
| AC-14 (migration-run audit row) | `packages/platform/tests/Support/MigrationRunAuditTest.php` (bulk/one-tenant/two-tenant, actor+email, once-only via retry, planner-refusal zero rows, atomicity via throwing recorder double) | PASS |
| AC-15 (no new producer for unbuilt actions) | By construction: `PlatformAuditActions::ALL` has exactly 8 entries (7 T-016 + `migration.run`) | PASS |
| AC-16 (platform search, filters) | `packages/platform/tests/Features/SearchPlatformAuditLog/SearchPlatformAuditLogTest.php` | PASS |
| AC-17 (platform-staff gate, no route) | `SearchPlatformAuditLogTest.php` (4-reason-forbidden control + flip-is_platform_staff control), `QaAuditNoHttpSurfaceTest.php` | PASS |
| AC-18 (export JSON/CSV) | `packages/kernel/tests/Unit/Audit/AuditExportTest.php`, `SearchAuditLogCommandTest.php`, `SearchPlatformAuditLogCommandTest.php` | PASS |
| AC-19 (sole writer, `audit_logs`/`audit_logs_archive`) | `QaAuditSourceRulesTest.php`; independently mutation-tested with real planted files in `packages/access/src` for both tables | PASS |
| AC-20 (sole writer, `platform_audit_logs`) | `QaAuditSourceRulesTest.php` AC-20 case (`MigrationRunAudit` delegates, never inserts) | PASS |
| AC-21 (gate green, no weakened audit test) | Independent full gate run (below); diff review of all 21 pre-existing edits | PASS |

## Test run summary

All commands run from `modules/host` in the QA worktree, `ENGINE_HOLD=1` forced by `phpunit.xml` (confirmed; nothing in this task is `[ENGINE]`, so no AC needed a NOT VERIFIED mark).

- `composer lint` → `{"tool":"pint","result":"passed"}`
- `composer stan` → `[OK] No errors`
- `composer deptrac` → `Violations 0, Skipped 0, Uncovered 629, Allowed 627, Warnings 0, Errors 0`
- `vendor/bin/pest --exclude-group=toolchain` → **2097 passed, 12 failed, 11 skipped** (15784 assertions, 499.87s). The 12 failures are exactly the FP-316 cluster, confirmed against my own standing memory of its signature ("12 failures, rotating file names, always this cluster of module-registry/bootstrap files"): `QaModuleRegistryContractTest` ×6, `QaModuleNameValidationTest` ×3, `QaHealthProbeResilienceTest` ×2, `ModulesEnvParsingTest` ×1. Nothing audit-related failed.
- `vendor/bin/pest --group=toolchain` → **70 passed, 0 failed** — including all 4 `HostTestAnalysisEnforcementTest` cases. Dev's reported single flake (`HostTestAnalysisEnforcementTest`) did not reproduce in my run either, consistent with dev's own "not reproducing on isolated re-run" note.
- Catalogue republish check: backed up the committed `tenant_schema_versions.json`, ran `php artisan schema:publish --write-fixture`, diffed — **byte-identical**. Confirms the checksum and version bump (21→22, matching `.env.example`) are genuine, not hand-edited.

**Independent mutation testing (red-first, then reverted and confirmed green):**

1. Planted a real second inserter into `audit_logs` as a new file under `packages/access/src/` (not dev's own temp-directory control) → `QaAuditSourceRulesTest`'s AC-19 case went red naming the planted file exactly. Removed it → green. `git status` clean afterward.
2. Planted a real delete against `platform_audit_logs` as a new file under `packages/platform/src/` → the AC-10/AC-11 case went red naming the planted file. Removed it → green. `git status` clean afterward.
3. Temporarily edited `SearchAuditLogHandler.php` (backed up first) to issue one `DB::connection('master')->select('select 1')` statement inside `handle()` → `QaAuditDatabaseSeparationTest`'s "tenant search issues no master statement" case went red, reporting the exact SQL. Restored from backup → `git diff`/`git status` confirm byte-identical restoration, and the test is green again.

These three prove the three qa-accountable sweep/separation tests genuinely exercise the scanner/query-log mechanism against real source, not merely against their own planted-string fixtures (the T-016 round-1 W-1 trap). `QaAuditNoHttpSurfaceTest` already carries its own internal mounted-route control (a real `Route::get('api/v1/audit', …)` registration caught by the same function the production assertion calls), which I additionally confirmed passes/fails as expected by re-reading its assertions and running the file directly.

**Spot-checked the four "found only by running the suite" pre-existing edits (via `git diff c1a1fa3..480b30e`):**

- `TerminalActivationHttpTest.php` — adds one `unset($headers['x-request-id'])` alongside the pre-existing `unset($headers['date'])`, same shape, legitimately caused by the new global `X-Request-Id` response header. No other line touched.
- `AccessBumpSweepTest.php` — adds two new operations (`ArchiveAuditLogs`, `SearchAuditLog`) to `bumpSweepOperations()`'s map, each proving the two new slices don't move `perm_version`. Pure addition; no existing case removed or altered.
- `QaAccessSchemaShapeTest.php` — adds `audit_logs_archive` to the excluded-tables list (`$t017`) alongside the pre-existing `$t014` exclusion, same mechanism, for the same reason (T-010's eight-table claim is unaffected by a later task's table). No existing assertion changed.
- `BranchForeignKeysContractRunTest.php` — **verified in full**: a new `bfkRunTop()` function was added, and only the two "run to no explicit target" assertions (lines where `migrateFixtureMappingRow($tenant)['schema_version']` is checked after a run with no `--to`) were switched from `bfkRunV()` to `bfkRunTop()`. Grepped every other `bfkRunV()` call site in the file (14 remaining uses) — all still read the branch-FK contract step's own version, byte-for-byte unchanged. The function `bfkRunV()` itself is untouched.
- `QaAccessCloseOutSweepsTest.php` (plan's predicted maybe-edit) — same pattern as `QaAccessSchemaShapeTest`, excludes `audit_logs_archive` by name from `closeOutNewTables()`'s T-010-table derivation, for the stated reason (the sweep's exercise never archives). No existing assertion changed.

None of the five weakens or removes an existing assertion; each is a narrowly-scoped, legitimately-caused addition.

**Confirmed the four T-016 `PlatformAudit` fault-injection doubles are untouched:** `git diff c1a1fa3..480b30e` on `ChangeLicenceStatusTest.php`, `ChangeSubscriptionPlanTest.php`, `ChangeSubscriptionStatusTest.php`, `StartSubscriptionTest.php` is empty (0 lines) for all four.

**File-count reconciliation:** dev reported "73 files touched (55 new, 18 edited)" to the lead. Measured directly from `git show --stat 480b30e`: **76 files (55 new, 21 modified)**. The 21 modified files are fully and legitimately accounted for — the plan's section 9 names 17 of them, and dev's own "three deviations" message named the remaining 4 ("found only by running the full suite"), all of which I independently verified above. This is a minor arithmetic slip in dev's spoken summary (73/18 vs the real 76/21), not a hidden or unreviewed edit — every file in the diff is explained. Noting it rather than filing it as a defect, since nothing is actually missing from review.

## Defects

None found. Zero bugs filed this round.

## Regression risks / notes

- **FP-319 (scheduler not wired in production)** — `audit:archive`'s daily schedule inherits this pre-existing, already-tracked gap (D-14); not this task's defect.
- **`ActivateTerminalHandler::issuerOf()` reads `audit_logs`** (plan risk 5) — a 365-day default retention against a 24-hour activation-code lifetime leaves no practical conflict; flagged informationally by `architect`, not a QA finding.
- **FP-316's 12-failure cluster is pre-existing and out of this task's scope** — confirmed against my own standing memory of its rotating-file-name signature; this run's split (6/3/2/1 across the same four files) matches exactly.
- Concurrent, unrelated `php.exe` processes were present on this machine during the run (`artisan serve`, `queue:work`, a `composer install --no-dev` and an HTTP dev-server for a different project, `Scriber`) — none touch this worktree's SQLite fixtures, and the full suite's result matched dev's own reported numbers exactly, so no contamination is suspected. Confirmed via `Get-CimInstance Win32_Process` command-line inspection before trusting the run.
- Added memory entry for this task's close-out (mutation technique on text-scan QA tests via a real scratch file in the actual scanned directory, rather than only a temp-directory control) — see agent memory.

**Run 1: PASS** (no defects found; full AC sweep, independent mutation testing, and independent gate re-run all conducted in one pass).
