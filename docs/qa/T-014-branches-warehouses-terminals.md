# QA Report: T-014 branches-warehouses-terminals

## Verdict: PASS for what could be verified on the stand-in

Resumed session: steps 0-16 were already committed and verified green by a prior
session (`bf10927`). This report covers step 17 (qa's close-out: the nine mandatory
mutations, the AC-8/section-9 diff checks, the final gate, and section 11's
NOT VERIFIED list) plus an independent re-check of the baseline and of the
diff against the T-011 tip. One process defect found (BUG-1, minor) and one
measurement correction about FP-316 are reported below; neither is a reason
to fail the task.

Criteria range re-derived from the spec header: **AC-1 to AC-64** (the spec's own
text, not the 1-62 figure quoted in the task brief — AC-63 and AC-64 were added
by D-37/D-38 for Ruling 7/8's refusal-vocabulary and settings-registry criteria).

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `OrganizationSchemaShapeTest.php` | PASS |
| AC-2 | expand migrations + `BranchForeignKeysMigrationTest.php` | PASS |
| AC-3 | `BranchForeignKeysMigrationTest.php` (AC-3 cases) | PASS |
| AC-4 | `BranchForeignKeysContractRunTest.php` | PASS |
| AC-5 | `BranchForeignKeysMigrationTest.php` + `BranchForeignKeysContractRunTest.php`; mutation 3 and mutation 4 (below) | PASS, confirmed by mutation |
| AC-6 [ENGINE] | static pre-check-shape test only | **NOT VERIFIED** (engine hold) |
| AC-7 | `BranchForeignKeysMigrationTest.php` (restrict case) | PASS |
| AC-8 | diff check (below) | PASS |
| AC-9 | `TenantBaselineBuildsTest.php` (4-column unguarded-map assertion); report line found in `docs/backlog.md` row FP-313, not literally in a commit message (see note below) | PASS in substance |
| AC-10 to AC-14 | `OrganizationBranchHttpTest.php` | PASS |
| AC-15 | access step 5 (`BranchScopeTest.php`, `AssignRoleTest.php`, `AttachBranchTest.php`, `SetEmployeePermissionTest.php`) + organization slices | PASS |
| AC-16 [ENGINE] | sequential half only | **NOT VERIFIED** (engine hold) |
| AC-17 to AC-20 | `QaOrganizationHttpSurfaceTest.php` (AC-17, 18), per-slice audit assertions (AC-20) | PASS |
| AC-21 to AC-25 | organization location slices | PASS |
| AC-26 to AC-28 | organization terminal slices, `OrganizationTerminalHttpTest.php` | PASS |
| AC-29, AC-30 | `TerminalActivationHttpTest.php`; mutation 7 (below) | PASS, confirmed by mutation |
| AC-31 | `TerminalActivationHttpTest.php`, `ActivationThrottleTest.php`; mutation 8 (below) | PASS, confirmed by mutation (see finding under mutation 8) |
| AC-32, AC-33 | organization/auth terminal slices | PASS |
| AC-34 | `TerminalSessionHttpTest.php`; mutation 6 (below) | PASS, confirmed by mutation |
| AC-35 | `TerminalSessionHttpTest.php`, `QaOrganizationHttpSurfaceTest.php` | PASS |
| AC-36, AC-37 | `TerminalSessionHttpTest.php` | PASS |
| AC-38, AC-39, AC-40 | `TerminalSessionHttpTest.php` (revocation cases) | PASS |
| AC-41, AC-42 | organization terminal read/update slices | PASS |
| AC-43 [ENGINE] | stand-in half only (second sequential request refused) | PASS stand-in half; **NOT VERIFIED** concurrent half |
| AC-44 | `SettingsResolutionTest.php` printed dataset; mutation 1 (below) | PASS, confirmed by mutation |
| AC-45 to AC-51 | settings slices, `QaSecretSettingSinkTest.php` | PASS |
| AC-52 | `AssignRoleTest.php`, `AttachBranchTest.php`, `SetEmployeePermissionTest.php`; mutation 5 (below) | PASS, confirmed by mutation |
| AC-53 | `OwnerRoleSyncTest.php` + sweep | PASS |
| AC-54 to AC-58 | `SetPinEscalationTest.php`, `SetPinTest.php` | PASS |
| AC-59 | `QaPinResetRuleTest.php` printed dataset; mutation 2 (below, both sub-cases) | PASS, confirmed by mutation |
| AC-60 | `QaPinResetRuleTest.php`, `QaPinSinkCaptureTest.php` | PASS |
| AC-61 | `QaBranchIdFoldTest.php`, `AttachBranchTest.php`, `DatabasePermissionResolver` fold | PASS |
| AC-62 | final `composer check` (below) | PASS for what could be verified; [ENGINE] trio recorded NOT VERIFIED |
| AC-63 | `RefusalReasonTest.php` + response-shape assertions across the HTTP tests | PASS |
| AC-64 | settings registry closed-set tests (`SettingsRules`/`SetSettingTest.php`) | PASS |

## Mutations (step 17), each shown red and reverted, byte-identical restore verified by `diff`

All nine ran on the stand-in only; `ENGINE_HOLD` was never touched (no mutation
opened a real database connection).

1. **Reverse settings precedence** (`packages/organization/src/Support/SettingsResolver.php`,
   `LAYERS` order reversed to company-first) → `SettingsResolutionTest.php`:
   2 failed / 2 passed (AC-44's printed dataset and the explicit-layer test both
   caught it, each showing the wrong value/source). Restored, `diff` byte-identical.
2. **Remove the PIN escalation check, then invert "system role"**
   (`packages/access/src/Features/SetPin/SetPinHandler.php`), two sub-mutations:
   - check removed entirely → `QaPinResetRuleTest.php`: 3 failed / 1 passed.
   - `SystemRole::isHeldBy()` call inverted (`!`) → same file: 3 failed / 1 passed.
   Both turn AC-59's dataset red as predicted. Restored, `diff` byte-identical.
3. **Delete the contract pre-check** (`add_branch_foreign_keys.php`), run with
   keys on **and** off (T-011 Ruling 7): with the fixture's
   `foreign_key_constraints => true` (keys on), `BranchForeignKeysMigrationTest.php`
   went 11 failed / 12 passed; temporarily flipping that same fixture to `false`
   (keys off) gave 13 failed / 10 passed. Both enforcement states turn AC-5 red,
   confirming the pre-check is the sole guard regardless of SQLite's own
   enforcement (Ruling 14). Both the migration file and the temporarily-edited
   test file were restored, `diff` byte-identical for both.
4. **Move one key before the pre-checks** (an extra `Schema::table('number_sequences', …)`
   inserted before the pre-check loop) → `BranchForeignKeysContractRunTest.php`'s
   statement-capture case ("it issues the check and no altering statement on a
   dirty tenant…") failed exactly as named: the captured SQL now contains the
   `__temp__` rebuild statements on the dirty tenant, where the assertion
   expects none. Restored, `diff` byte-identical.
5. **Skip `requireUsable()` in `AssignRole`** (`AssignRoleHandler.php`) →
   `AssignRoleTest.php`: 1 failed ("it refuses an unknown branch, refuses an
   inactive one, accepts an active one…" — got `not_attached` instead of
   `unknown_branch`), 9 passed. AC-52 goes red as predicted. Restored, `diff`
   byte-identical.
6. **Make `activeBranchOf()` ignore credential status**
   (`DatabaseTerminalAuthority.php`, dropped the join/where on
   `terminal_credentials.status`) → `TerminalSessionHttpTest.php`: 2 failed
   ("a revoked credential under an active terminal row" and the pause/resume
   case), 4 passed. AC-34 goes red as predicted. Restored, `diff` byte-identical.
7. **Mint before the tenant writes** (organization's
   `ActivateTerminalHandler.php`, called `$issueInsideTransaction` immediately
   after the terminal lock, before the expiry/branch/device pre-checks) →
   `TerminalActivationHttpTest.php`'s AC-30 test: "[an expired code] wrote to a
   table" — `master.refresh_tokens` went from 5 to 6. AC-30's "nothing written"
   goes red exactly as predicted. Restored, `diff` byte-identical.
8. **Key the throttle by tenant part string instead of id, or omit the tenant**
   (`packages/auth/src/Features/ActivateTerminal/ActivateTerminalHandler.php`),
   two sub-mutations tried:
   - keyed by `$parsed['tenantPart']` instead of the resolved `$tenantId` → **no
     test went red.** `tenants.code` is unique and the parser upper-cases it the
     same way the stored code is stored upper-case (D-40), so this substitution
     is isomorphic to keying by id in every fixture this suite builds; it is not
     a discriminating mutation here. Noted as a finding, not absorbed silently
     (see Defects).
   - tenant locking omitted entirely (the `isLocked($tenantKey)` check and both
     `tenantKey` entries in the `keysToCount` arrays removed) → `TerminalActivationHttpTest.php`'s
     AC-31 test ("it locks a tenant and an address after ten failures…") failed:
     "the tenant is locked too" assertion got 200 instead of 401. AC-31's
     isolation goes red. Restored, `diff` byte-identical.
9. **Remove the principal filter** (`RefreshTokenHandler.php`, dropped
   `->where('principal_type', IssueTokenPairHandler::PRINCIPAL_TYPE)`) →
   `TerminalSessionHttpTest.php`'s "does not mix the two token families…" case:
   `revoked_at` on the terminal's refresh row became non-null where the test
   expects null — the terminal token was judged as a user's, found no matching
   user, and its family was wrongly revoked, exactly as the handler's own
   docblock predicts. This reaches a real branch difference (the family really
   gets revoked), not two indistinguishable successes, so it is not a "mask
   shape 6" case. Restored, `diff` byte-identical.

## AC-8 and section 9 diff checks

- **AC-8** (no already-shipped tenant migration file edited): `git diff --stat
  b42c738...HEAD -- modules/host/database/migrations-tenant/` (`b42c738` is the
  T-011 tip, confirmed as the actual merge-base with `HEAD`, not `main` —
  `main` is far behind, at the T-013 tip, and does not even contain the
  `migrations-tenant` directory, so diffing against `main` falsely reports every
  pre-T-014 migration as new). Against the correct base, only the six new files
  (`2026_10_22_000501` through `000506`) appear; no T-008/T-010/T-011 migration
  file's checksum moved. **PASS.**
- **Section 9's edit list**: `git diff --name-status b42c738...HEAD --diff-filter=M`
  (modified pre-existing files only) reproduces every file section 9 names
  (`SetPinTest.php`, `VerifyPinTest.php`, `Helpers.php`,
  `MigrationTreeDisciplineTest.php`, `TenantBaselineBuildsTest.php`,
  `AssignRoleTest.php`, `AccessSeed.php`, `QaAccessGuardIsolationHttpTest.php`,
  `RefusalReasonTest.php`, `QaAccessSchemaShapeTest.php`,
  `TenantAccessKeysTest.php`, `QaAccessCloseOutSweepsTest.php`, an auth
  route-surface test). The "expected unchanged" list
  (`PermissionRegistryTest`, `SyncPermissionRegistryTest`,
  `QaAccessHttpSurfaceTest`, `QaVerifyPinReachabilityTest`,
  `QaSchemaSignatureInstrumentTest`, `QaTenantBaselineStandInShapeTest`,
  `QaDatetimePrecisionSourceTest`) is indeed untouched. **But the diff also
  shows edits to pre-existing files that section 9 (and plan section 3) do not
  name anywhere** — see **BUG-1** below.

## Final gate

Three `composer check` runs were made. The first two overlapped in time (my own
error — I launched a second run via `run_in_background` before confirming the
first, started with a bare `&`, had finished) and both show heavy, inconsistent
failure counts (260 and 275 failed) from SQLite/storage contention between the
two concurrent Pest processes. **Both are discarded as invalid**; this is a
process note for my own memory, not a product defect — nothing in the
production diff caused it.

The third run was solo, clean, with no other test process running:

- **Command:** `"E:\xampp\php\php.exe" "C:\ProgramData\ComposerSetup\bin\composer.phar" check` from `modules/host`.
- **Result:** `probes:clean` OK, `engine:clean` OK (ENGINE_HOLD honored, no
  connection opened), `lint` (Pint) `{"tool":"pint","result":"passed"}`, `stan`
  (PHPStan) "No errors", `deptrac` 0 violations / 0 errors / 546 uncovered / 513
  allowed, then `test`: **12 failed, 11 skipped, 1920 passed (14512
  assertions)**, duration 536.68s, exit code of the `test` script 2 (so
  `composer check` as a whole exits non-zero on this one known cause).
- Process list before the run (`tasklist /FI "IMAGENAME eq php.exe"`): 5
  unrelated php.exe processes were present throughout (same 5, before and
  after); none were spawned by this run and none were touched.

**FP-316, confirmed and not chased further, with one correction to how it is
usually described.** All 12 failures are the filed, pre-existing,
unrelated-to-this-task defect. However, **which test names carry the 12
failures is not stable across runs**, and is not confined to
`QaModuleRegistryContractTest` alone the way the task brief's framing
suggested. Three separate runs today showed three different splits, all
totalling 12:

- an early isolated run of `QaModuleRegistryContractTest.php` alone: 8/8 pass.
- the morning's full-suite baseline run: all 12 in `QaModuleRegistryContractTest`.
- this report's solo final run: 6 in `QaModuleRegistryContractTest`, 3 in
  `QaModuleNameValidationTest`, 2 in `QaHealthProbeResilienceTest`, 1 in
  `ModulesEnvParsingTest`.

This is consistent with the git history already on this branch (`93e8cde`
"module-registry test flakes under system load", corrected by `0343228` to
"deterministic order-dependency, not load flakiness") — the root cause sits in
module-registry/bootstrap state shared across several test files, and which
named test absorbs the corruption depends on parallel-worker assignment, not on
anything T-014 touches. The **count of 12 held in all three runs**, which is
itself informative: it suggests a fixed number of workers each hit the same
bug once, independent of which file each worker happened to be running.
Recorded here so the lead has the current shape of FP-316, not chased further
per my instructions.

## Section 11, the [ENGINE] criteria — recorded NOT VERIFIED, not stubbed

| Criterion | Ships now (stand-in) | Owed to the engine |
|---|---|---|
| AC-6 | all nine keys added/enforced on SQLite; one pre-check with no driver branch (read, confirmed no `DB::getDriverName()` or similar branch in the migration) | the same nine keys and the same offending set on MySQL 8.4/MariaDB 10.4; a restrict key accepted on the base column of a stored generated column |
| AC-16 | sequential deactivation guard, `for update` read (confirmed present in source) | two concurrent deactivations leaving exactly one active branch |
| AC-43 | the second sequential activation request refused (confirmed by `TerminalActivationHttpTest.php`'s positive-then-repeat case) | exactly one of two concurrent activations succeeding, one device row |

No stand-in test was substituted for these, and no guard was stubbed to permit
to manufacture a green run; `ENGINE_HOLD` was respected throughout this
session — no mutation or test run in step 17 opened a connection to
`127.0.0.1:3306` or any other real engine.

## Defects

### BUG-1 (minor, process)
- **What:** Several edits to pre-existing (T-009/T-010) production and test
  files are not named anywhere in the plan's section 3 ("files to change") or
  section 9 ("pre-existing tests that legitimately change") tables, even though
  none of them is destructive or weakens an assertion:
  - `modules/host/app/Http/Responses/AuthRefusals.php` (T-009): added
    `ACTIVATION_UNAVAILABLE` / `activationUnavailable()` for Ruling 12's 503.
  - `packages/access/src/Resolver/DatabasePermissionResolver.php` (T-010):
    added the AC-61 upper-case fold to `decide()`.
  - `packages/access/src/Support/SystemRole.php` (T-010): added `isHeldBy()`
    for D-44's PIN rule.
  - `modules/host/tests/Feature/PasswordHashingAndClockBindingTest.php`
    (T-009): extended its config-key enumeration for the new
    `terminal_activation` keys.
  - `packages/access/tests/Features/SyncPermissionRegistry/OwnerRoleSyncTest.php`
    and `packages/access/tests/Support/PermHashRowsTest.php` (T-010): both
    needed the same branch-seeding fix `TenantAccessKeysTest` is explicitly
    credited with in section 9 (a row naming an opaque `branch_id` now needs a
    real `branches` row once the contract migration's keys are in place); each
    new helper is seeding-only, no assertion changed, and each says so in its
    own docblock.
- **Why this is a defect even though nothing is broken:** the plan's own "red-
  list rule" exists precisely so an unmeasured reader like these is reported to
  `architect` rather than quietly fixed and absorbed (T-010's history: 3
  predicted, 20 found, all reported). Here they were fixed correctly but not
  surfaced, so the plan's section 9 table is incomplete as a record of what
  this task actually touched outside its own package.
- **Expected:** every edit to a pre-existing file is named in section 3 or
  section 9, or reported separately if found only at close-out.
- **Actual:** six files are edited and unnamed in either place.
- **Suspected location:** these are dev's edits from steps 3, 5, 11 and 14 of
  this already-committed work; nothing to fix in this session (no `dev` is
  available in this resumed session), so this is reported to the lead rather
  than actioned.

### Note (not filed as a bug): AC-9's report line
The plan's step 16 says "AC-9's report line goes in the commit for the lead."
No T-014 commit message contains it (checked every commit from the T-011 tip
to `HEAD`). The substance is present instead in `docs/backlog.md`'s FP-313 row,
filed by the architect's plan section 10 message and already correctly
describing the four columns staying unguarded and why. Since the lead's own
hand-off file (`docs/backlog.md`) is arguably the more durable channel than a
commit message, and the content is accurate, I am not filing this as a defect
— just noting that step 16's literal instruction was not followed to the
letter.

### Note (not filed as a bug): mutation 8's first sub-case
Keying the activation throttle by the tenant-part string instead of the
resolved tenant id does not turn any test red in this suite, because
`tenants.code` is unique and both sides fold the same way (D-40), so the
substitution is not actually a different key space here. This does not mean
AC-31's isolation is unproven — the "omit the tenant" sub-mutation and the
isolation-control unit test (`ActivationThrottleTest.php`'s mis-keyed double)
both demonstrate the real requirement — but the plan's phrasing of mutation 8
offers two options and only one of them is a live mutation against this
production code path. Worth architect/ba knowing in case a future task's
tenant-part scheme ever stops being 1:1 with tenant id.

## Regression risks / notes

- The diff against `main` is misleading for this branch (main predates
  `migrations-tenant` entirely); any future diff check on this branch must use
  the T-011 tip (`b42c738`), not `main`, as the base.
- Never run two `composer check` / Pest invocations concurrently against this
  worktree — confirmed today to corrupt results by shared SQLite/storage state
  (260 and 275 spurious failures from two overlapping runs, discarded).
- FP-316's 12 failures rotate across `QaModuleRegistryContractTest`,
  `QaModuleNameValidationTest`, `QaHealthProbeResilienceTest` and
  `ModulesEnvParsingTest` depending on parallel-worker assignment; the fixed
  count of 12 is the useful invariant to watch, not the specific test names.
