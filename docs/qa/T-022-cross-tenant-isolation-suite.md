# QA Report: T-022 Cross-tenant isolation suite

## Verdict: PASS (round 2 — review-fix re-verification)

Reviewer's round-1 review found 3 Criticals (R-1, R-2, R-3) and 2 warnings (W-1, W-2),
all confined to `QaRouteCrossTenantSweepTest.php` and `QaJobCrossTenantSweepTest.php`.
Dev fixed all 5 in commit `ed974d7`. I independently re-read both files in full, re-derived
AC coverage against the spec, ran discrimination checks on R-1, R-2 and R-3 (not just R-2/R-3
as asked — R-1 was cheap enough to check too), and re-ran the full gate. All 25 ACs still have
a passing automated test; the three previously-Critical ACs (AC-1/AC-3/AC-4 for R-1, AC-5/AC-7
for R-2, AC-10 for R-3) are now genuinely proven, not just made to compile. No defects.

## Acceptance criteria coverage (unchanged from round 1 except as noted)

Round 1's full AC-1..AC-25 table (`git show b0a290f:docs/qa/T-022-cross-tenant-isolation-suite.md`)
still holds; only the route-sweep (A) and job-sweep (B) rows changed shape and are re-verified below.
Cache-key (C) and plugin-hook (D) sweeps and suite-integrity (E) were untouched by this commit and
were not re-run beyond the full-gate pass below.

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `qaRouteSweepClassify()` over `Route::getRoutes()`, asserted in `AC-1, AC-2` test | PASS |
| AC-2 | `qaRouteSweepAssertFloor()`, 42-name floor; hand-recounted from the constant list, got 42 | PASS |
| AC-3 | `qaRouteSweepByIdAttempt()` driven over `qaRouteSweepDerivedReads()` (R-1: derived from the live registry, not a hand list), floor-checked by `qaRouteSweepAssertReadFloors()` | PASS |
| AC-4 | `qaRouteSweepCollectionAttempt()` over the same derived/floor-checked collection set | PASS |
| AC-5 | `qaRouteSweepWriteAttempt()` over all 28 recipe'd non-GET tenant routes (hand-recounted from `qaRouteSweepRecipes()`, got 28), cross/reverse/own | PASS |
| AC-6 | `qaRouteSweepAssertExcluded()`, 8 named exclusions, fail-closed both ways | PASS |
| AC-7 | R-2: planted read/list/write routes driven through the **same** `qaRouteSweepByIdAttempt`/`qaRouteSweepCollectionAttempt`/`qaRouteSweepWriteAttempt` functions AC-3/AC-4/AC-5 use — closes round 1's own "minor deviation" note that AC-7 didn't literally reuse the shared detector | PASS |
| AC-8 | `qaJobSweepQueuedClasses()` reflection scan = `[MigrateTenantQueueJob]` | PASS |
| AC-9 det. | `qaJobSweepAmbientAttack()` (W-1: extracted, shared with the negative control) scenarios R/L | PASS |
| AC-10 | R-3: `qaJobSweepPayloadFinding()` — tenant-id sentinel + `QA_JOB_SWEEP_FORBIDDEN_NEEDLES` scan, independent of any one planted fixture value | PASS |
| AC-11 | `qaJobSweepTenantFinding()`, allow-list empty, planted no-tenant class caught | PASS |
| AC-24 | both files' `is_dir()` checks on every scanned root | PASS |

## Discrimination checks performed (revert → red → restore → green)

All three performed directly against the real test files (not a copy), each followed
by `git diff --stat` confirming a clean tree before moving to the next.

### R-1 — derived reads replacing the hand-maintained attack list

Added a fabricated, non-existent route name to `QA_ROUTE_SWEEP_BY_ID_READS` and ran
`--filter "AC-3"`:
```
FAILED  Tests\Feature\QaRouteCrossTenantSweepTest > it AC-3: ...
Floor by-id read [qa.discrimination.probe.nonexistent.route] is no longer in the derived read set.
at tests\Feature\QaRouteCrossTenantSweepTest.php:225
```
Reverted; `git diff --stat` empty; re-ran, 1 passed. Confirms `qaRouteSweepAssertReadFloors()`
genuinely discriminates a regression, not just a cosmetic rename.

### R-2 — `JSON_INVALID_UTF8_SUBSTITUTE` on `qaRouteSweepTenantState()`

First reproduced the general mechanism standalone (no DB): `json_encode()` on a string
containing `inet_pton('203.0.113.5')` — the test fixture's own default activation IP
(`organization-http-fixture.php`'s `orgHttpActivate()` default) — returns `false` without
the flag (bytes `CB 00 71 05`; `0xCB` is a UTF-8 lead byte with no valid continuation byte
following it), and a substituted string with it. Every seeded tenant world in this file
calls `orgHttpActivated()`, which writes an `access_log` row via `AccessLogWriter::terminalLogin()`
carrying that packed IP — so this bug was live on every single run of this file, not a
hypothetical edge case.

Then reverted the actual line (`return (string) json_encode($state);`, dropping the flag)
and ran `--filter "AC-7"`:
```
FAILED  Tests\Feature\QaRouteCrossTenantSweepTest > it AC-7, R-2: ...
Expecting [] not to be [].
at tests\Feature\QaRouteCrossTenantSweepTest.php:1060  ($writeFindings)
```
Exactly as predicted: with the bug, every tenant-state snapshot already containing an
activated terminal's `access_log` row collapses `json_encode()` to `false` → `""`, so
`qaRouteSweepTenantState('tenant-b')`'s before/after comparison in the planted write's
non-id branch always compares `"" === ""` and never reports a finding — AC-7's write-leak
assertion goes empty and fails. Reverted the revert; `git diff --stat` empty; re-ran, 1
passed (16 assertions).

### R-3 — `qaJobSweepPayloadFinding()`'s sentinel + forbidden-needle check

Temporarily restored the pre-fix shape (only the caller-supplied-needle loop, no sentinel
check, no `QA_JOB_SWEEP_FORBIDDEN_NEEDLES` scan) and ran `--filter "AC-10"`:
```
FAILED  Tests\Feature\QaJobCrossTenantSweepTest > it AC-10: ...
Expecting null not to be null.
at tests\Feature\QaJobCrossTenantSweepTest.php:539
```
The planted credential class's payload literally contains the property name `password`
and value `x`, but the old code only ever checked for the caller-supplied `migrateFixturePassword()`
value, which the generic payload builder could never produce (it always writes `'qa-'.$name`) —
a textbook false-green: a real credential-shaped property sat in the payload the whole time
and nothing caught it. Reverted; `git diff --stat` empty; re-ran, 1 passed (10 assertions).
Also independently reproduced the same old-vs-new behaviour in a standalone script with no
Laravel/DB dependency, confirming it is not an artefact of fixture state.

## Test run summary

- `cd modules/host && composer check` (full gate, cold-ish — a concurrent `composer check`
  from the lead's own invocation plus this run overlapped on a handful of ad-hoc single-file
  Pest runs I did for the discrimination checks above; saw transient
  `unlink(...tenancy-fixture\tenant-a.sqlite): Resource temporarily unavailable` warnings from
  the file contention, but every run — mine and the full gate's — completed and reported
  consistent, correct pass/fail verdicts; no corrupted result observed):
  - `Tests: 12 failed, 23 skipped, 2750 passed (19233 assertions)`.
  - `FLEXIPOS-GATE-MARGIN step=test ... elapsed=833.88s ... ratio=9.35`.
  - Matches the task brief's reported `2750 passed / 12 failed / 23 skipped` exactly (no
    discrepancy this round, unlike round 1's qa-vs-dev off-by-one).
  - All 12 failures are in the pre-existing, already-diagnosed `MissingAppKeyException`/
    `MODULES`-env baseline-gap cluster (`ModulesEnvParsingTest`, `QaHealthProbeResilienceTest`,
    `QaModuleNameValidationTest`, `QaModuleRegistryContractTest` — confirmed `QaModuleRegistryContractTest`
    by name in this run's own output; the other three are the same cluster named in round 1's
    report and the task brief). **No T-022 file is among the 12.**
- Independently re-ran `composer lint` (Pint `--test`): `{"tool":"pint","result":"passed"}`.
- Independently re-ran `composer stan` (PHPStan): `[OK] No errors`.
- Independently re-ran `composer deptrac`: `Violations 0, Skipped violations 0, Errors 0`.
- Targeted re-run of both changed files together (post-restore, clean tree):
  `Tests: 14 passed (596 assertions)`, 68.97s — all AC-1..AC-11/AC-24 cases green, including
  the printed per-route report lines (6 by-id reads, 8 collections, 28 writes — matching the
  hand-recounted floors above).

## Defects

None. No BUG filed.

## Regression risks / notes

- Round 1's own regression-risk note ("AC-7 doesn't literally reuse the shared attempt
  function") is now resolved by R-2's refactor — confirmed by reading the new AC-7 test,
  which calls `qaRouteSweepByIdAttempt()`/`qaRouteSweepCollectionAttempt()`/`qaRouteSweepWriteAttempt()`
  directly against the planted routes.
- `app()->forgetInstance(PlanLimits::class)` (round 1's noted test-harness-only staleness fix)
  is untouched by this commit and still present.
- Running an ad-hoc single-file Pest invocation concurrently with a full `composer check` in
  the same worktree produced benign `unlink`/`rmdir` warnings on the shared
  `storage/app/tenancy-fixture` directory (two processes racing to clean up the same SQLite
  fixture files). Neither run's pass/fail verdict was affected, but per this project's own
  "never overlap gate runs" lesson, this should be avoided when avoidable — I did it here only
  for the three discrimination checks, each a few seconds long, and verified the tree was clean
  and the full gate's final numbers were self-consistent afterward.

## History

- Run 1: PASS.
- Run 2: PASS (review round-1 fixes R-1/R-2/R-3/W-1/W-2 independently re-verified; all three
  Criticals' fixes proven discriminating by revert/red/restore/green; full gate clean, matching
  dev's reported numbers exactly).
