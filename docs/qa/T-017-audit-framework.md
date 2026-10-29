# QA Report: T-017 Audit framework

## Verdict: PASS (run 2 stands; see "Run 2" section below for the review round-1 fix verification)

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

---

## Run 2: review round 1 fix verification (R-1, S-1, S-2)

Scope: `reviewer` filed one Critical (R-1) in round 1 of code review — `SearchAuditLogHandler.php` returned
`audit_logs.ip` as raw packed binary, so `audit:search --output=x.json` threw `JsonException` on any row with
a real IP, and CSV wrote binary into the cell, violating plan Ruling 11. Two trivial suggestions (S-1, S-2) were
fixed in the same commit. `dev` fixed all three in `200e731` ("T-017: review round 1 fix (R-1, S-1, S-2)"),
reported full gate 552.49s, 2173 passed / 12 failed (FP-316 cluster) / 11 skipped / 16382 assertions. This round
independently re-verifies that commit.

### 1. Diff read (`git show 200e731`)

Exactly 6 files touched, matching dev's own description precisely — no unrelated edit:

| File | Change |
|---|---|
| `packages/access/src/Features/SearchAuditLog/SearchAuditLogHandler.php` | R-1 fix: new private `renderIp()` unpacks `ip` via `AccessLogWriter::unpackIp()` before the row enters `AuditPage` |
| `packages/access/tests/Features/SearchAuditLog/SearchAuditLogCommandTest.php` | 4 new cases: IPv4/IPv6 × JSON/CSV |
| `packages/platform/src/Support/MigrationRunAudit.php` | S-1 fix: `array_unique($tenantIds)` before the one-tenant count check |
| `packages/platform/tests/Support/MigrationRunAuditTest.php` | 1 new case: duplicate `--tenant=A --tenant=A` |
| `packages/access/src/Features/ArchiveAuditLogs/ArchiveAuditLogsHandler.php` | S-2 fix: `$moved += $deleted` (the delete statement's own affected-row count) instead of `$moved += count($ids)` |
| `packages/access/tests/Features/ArchiveAuditLogs/ArchiveAuditLogsTest.php` | 1 new case: an overlapping manual run that already removed one id |

`AccessLogWriter::packIp()`/`unpackIp()` (the unpacker the R-1 fix reuses) were already shipped and unchanged —
confirmed by reading `packages/access/src/Support/AccessLogWriter.php:200-237`; both wrap `inet_pton`/`inet_ntop`
and throw `InvalidArgumentException` on failure rather than silently truncating.

### 2. R-1 independently verified beyond the 4 shipped tests

Read the 4 new cases in `SearchAuditLogCommandTest.php`: they seed one row each with `AccessLogWriter::packIp('192.168.1.10')`
and `packIp('2001:db8::1')`, run the real `audit:search` artisan command end to end (not the handler directly),
and assert the literal dotted/colon string appears in the exported file — genuinely exercising packed IPv4 **and**
IPv6 round-tripping through **both** JSON and CSV export, not a tautology.

Added my own 2 cases (kept in the tree, uncommitted pending dev/lead review) for the specific case named in my
task brief as most likely to break a naive unpack — a leading-zero-byte IPv4 address:
- `10.0.0.1` packs to `0x0A 0x00 0x00 0x01` (embedded NUL byte, second byte). A naive unpack that treats the
  packed column as a C string (stops at the first NUL, or `rtrim`s trailing NULs) would truncate or mangle this
  even though `192.168.1.10` round-trips fine.
- Verified with a throwaway PHP script first (`inet_pton('10.0.0.1')` → `0a000001`, `inet_ntop()` round-trips to
  `10.0.0.1` exactly — PHP's `inet_ntop` takes the fixed 4/16-byte length, not NUL-terminated, so this was always
  safe for this unpacker specifically), then added two real Pest cases exercising it through the actual
  `audit:search --format=json|csv` export path. Both pass against the fix:
  `packages/access/tests/Features/SearchAuditLog/SearchAuditLogCommandTest.php` — "exports a row with a
  leading-zero-byte IPv4 address as JSON/CSV... (R-1, QA)", 2 passed / 4 assertions.

**Mutation-tested all 6 R-1 cases (4 shipped + my 2) against the reverted fix.** Backed up
`SearchAuditLogHandler.php` to the scratchpad, `sed`-reverted line 98 back to
`array_map(static fn (object $row): array => (array) $row, $rows)` (the pre-fix shape), ran
`vendor/bin/pest --filter="R-1"`: **all 4 shipped cases went red** — the two JSON cases threw the exact
`JsonException: Malformed UTF-8 characters, possibly incorrectly encoded` bug report describes, the two CSV
cases failed `toContain()` against the raw packed binary string (visible in the failure diff as a hex blob).
Restored from backup, confirmed `git status`/`git diff` byte-identical, reran green.

### 3. S-1 and S-2 mutation-tested (red-first, then reverted)

Both production fixes temporarily reverted via direct file edit (not the Edit tool, backed up first), confirmed
red, restored, confirmed `git status` clean:

- **S-1**: reverted `$uniqueTenantIds = $tenantIds === null ? [] : array_values(array_unique($tenantIds));` to
  `$uniqueTenantIds = $tenantIds ?? [];` (drops the dedupe). `MigrationRunAuditTest`'s new case went red:
  `Failed asserting that null is identical to 'dup-tenant...'`. Restored, reran green.
- **S-2**: reverted `$moved += $deleted;` to `$moved += count($ids);`. `ArchiveAuditLogsTest`'s new case went
  red: `Failed asserting that 3 is identical to 2.` (the over-count the bug described, caught exactly). Restored,
  reran green.

All three fixes (R-1, S-1, S-2) are proven by a test that cannot pass against the pre-fix code, not merely by a
test that happens to pass against the post-fix code.

### 4. Full gate — a materially harder re-run than usual

The first two full-suite attempts in this worktree were contaminated by processes outside my control and are
**not** trusted as measurements (see Defects/BUG-1 below for the full account). The trustworthy number comes
from a third attempt, run inside a fully isolated `git clone --local` copy of this worktree (own `vendor/`, own
`.env`/`APP_KEY`, own SQLite tenancy fixtures — no file sharing with the worktree or with whatever else was
running in it):

- `composer check` (isolated clone, HEAD `5701c3a`, Composer local-path-repo symlinks restored as Windows
  junctions after `cp -r` had dereferenced them into real copies — see BUG-1b) →
  **2174 passed, 11 failed, 11 skipped, 16383 assertions, test step 453.89s.**
- All 11 failures are confined to exactly the 4 historically-known FP-316 files, with no 5th file and nothing
  audit-related: `QaModuleRegistryContractTest` ×6, `QaModuleNameValidationTest` ×3,
  `QaHealthProbeResilienceTest` ×1, `ModulesEnvParsingTest` ×1.
- This is a 1-test deviation from the previously-stable 6/3/2/1=12 split dev reported and my own run-1 memory
  recorded (`QaHealthProbeResilienceTest` showed 1 of its usual 2 failures this time — "it answers readiness
  with an empty list while the database is unreachable and no module is booted" passed instead of failing).
  Given FP-316 is itself a readiness/timing-sensitive probe cluster (already pre-existing, already tracked, and
  explicitly out of this task's scope per run 1), one case flipping pass/fail between runs reads as this
  cluster's own documented non-determinism, not a new regression — it is still the same 4 files, same
  mechanism, nothing audit-related, and the delta from dev's reported 2173/12/16382 to my 2174/11/16383 is
  exactly one test's worth (+1 passed, −1 failed, +1 assertion).
- `composer lint`/`composer stan`/`composer deptrac` all passed in the isolated clone before the test step
  (`{"tool":"pint","result":"passed"}`, `[OK] No errors`, `Violations 0`).
- My 2 added R-1 leading-zero-byte cases are not part of this clone (it is a `git clone` of the committed
  HEAD, and my addition is uncommitted in the worktree) — verified separately, in isolation, as noted above.

### Defects

#### BUG-1 (informational — environment, not a product defect; not routed to `dev`)

- **What happened**: my first full-gate attempt in this worktree (`composer check`, backgrounded) returned
  **48 failed** (`2139 passed, 11 skipped, 15104 assertions`), scattered across unrelated feature areas —
  `OrganizationTerminalHttpTest`, `PlanLimitEnforcementTest`, `PlanLimitHttpTest`, `QaAccessCloseOutSweepsTest`,
  `QaAccessGuardIsolationHttpTest`, `QaAccessHttpSurfaceTest`, `QaActivationCodeSecrecyTest`,
  `QaAuditDatabaseSeparationTest`, `QaBranchIdFoldTest`, plus the usual FP-316 files. Every failing block was
  immediately preceded by `PHP Warning: unlink(...tenant-a.sqlite): Resource temporarily unavailable` /
  `rmdir(...tenancy-fixture): Directory not empty` — a Windows file-lock signature, not an assertion failure.
- **Root cause, confirmed via `Get-CimInstance Win32_Process`**: a `composer.phar test` → `tools/gate-step.php
  test -- vendor/bin/pest` → `vendor/bin/pest` process chain was running directly against this exact worktree
  path, writing to `t017-suite.txt` then `t017-suite2.txt` in **my own** session scratchpad directory under a
  process chain I never issued (none of my own commands used those filenames). Its window
  (~14:54–15:01 local) overlapped my own gate's test step (~14:57–15:05 local) for about 4 minutes — two full
  suites hammering the same `modules/host/storage/app/tenancy-fixture/tenant-a.sqlite` file at once. This
  matches this project's known "two shared files turn one mistake into a hundred meaningless failures" /
  "serialise every suite run" hazard (own agent memory), but at a larger scale than previously measured, and
  with an unidentified second actor in a worktree the task description said was mine alone.
- **Not filed as a dev-facing bug**: nothing in the 48-failure list reproduced once contention was removed (see
  "Full gate" above); this is a shared-worktree process-isolation issue, not a code defect, so there is no
  production fix for `dev` to make. Recorded here, and in agent memory, as a process hazard for whoever
  schedules concurrent agents into this worktree next.

#### BUG-1b (informational — my own tooling artifact, self-corrected, not routed to `dev`)

- **What happened**: to escape BUG-1's contention I built a `git clone --local` of the worktree and
  `cp -r`'d `modules/host/vendor` into it. That produced a *second*, different spurious failure:
  `QaSuspendedMappingGateTest` > "it attributes the refusal to the kernel..." — `Failed asserting that false is
  true`, trace naming `vendor\flexipos\kernel\src\Tenancy\Tenancy.php`.
- **Root cause**: in the real worktree, `vendor/flexipos/{access,auth,contracts,eventbus,http-client,kernel,
  organization,platform,plugin-sdk,testing,module-demo}` are Composer local-path-repository **symlinks** into
  `packages/*` / `modules/demo` (confirmed `ls -la`, `lrwxrwxrwx`). `cp -r` on Git Bash/Windows dereferences
  symlinks by default, turning them into real, separate directory copies under `vendor/`. The test computes
  `$kernelRoot` from `packages/kernel`'s own canonical path and asserts a stack-trace frame's `realpath()`
  starts with it; with a dereferenced copy, the executing code's `realpath()` resolves to the `vendor/` copy
  instead, so the assertion legitimately fails against **my clone's own setup defect**, not against the
  codebase.
- **Fixed before trusting any number from the clone**: replaced every dereferenced `vendor/flexipos/*` copy
  with a Windows junction (`New-Item -ItemType Junction`, since plain `ln -s` silently created an empty
  directory rather than a symlink or an error in this sandboxed Bash — no `SeCreateSymbolicLinkPrivilege`)
  pointing at the clone's own `packages/*`. Reran `QaSuspendedMappingGateTest` alone — 6/6 passed. Reran the
  full gate with the fix in place — this is the number reported above (11 failed, all FP-316).

### Regression risks / notes

- All run-1 notes still apply (FP-319, `ActivateTerminalHandler::issuerOf()`, FP-316 pre-existing scope).
- **FP-316's cluster held across all three files/counts seen this round**, with one case's pass/fail flipping
  within the cluster (see BUG-1 discussion) — worth a one-line addition to the standing FP-316 memory entry
  rather than a new backlog row, since the lead's own run-1 "6/3/2/1 is exactly stable" note needed a caveat.
- **Process hazard for whoever assigns the next task to this worktree**: confirm no other agent/process is
  mid-run in `D:\Flexiata\NEW-FLEXIPOS\.claude\worktrees\progress-check-1913d7` before trusting a `composer
  check` number taken directly in it; the isolated-clone technique (clone + copy `vendor/` + regenerate
  `.env`/`APP_KEY` + **restore Composer path-repo symlinks as junctions, not plain copies**) is the reliable
  fallback and is now in agent memory.
- My added leading-zero-byte IPv4 QA cases are left in the tree, uncommitted
  (`packages/access/tests/Features/SearchAuditLog/SearchAuditLogCommandTest.php`, +36 lines) for `dev`/`lead`
  to fold into the commit history; they are pure test-file additions, pass against the fix, and were
  mutation-tested in the same way as the shipped R-1 cases (not re-shown above to avoid duplication — see
  section 2).

**Run 2: PASS** — R-1, S-1 and S-2 all independently confirmed correct by mutation testing (red-first, then
green on restore); full gate shape confirmed (same FP-316 cluster, zero audit-related failures) once two
environment-level contamination sources (an unidentified concurrent suite run in the shared worktree; my own
clone's dereferenced Composer path-repo symlinks) were found, explained, and removed. Verdict stands: **PASS**.
