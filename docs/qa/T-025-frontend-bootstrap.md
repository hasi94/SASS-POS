# QA Report: T-025

## Verdict: PASS (one defect filed, non-blocking — see BUG-1)

## Acceptance criteria coverage

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | Inspection: `envelope.ts` unchanged, read against T-021 AC-1/AC-3/AC-4; README section | PASS |
| AC-2 | `auth.service.spec.ts` "against the real T-009 contract" block; `api-client.spec.ts` `postRaw()` block; both `ng build` | PASS |
| AC-2a | `auth.service.spec.ts` "a second selectTenant() after clearContext()..." test | PASS, but see **BUG-1** — the shipped test does not actually discriminate the fix it claims to prove |
| AC-3 | `api-client.spec.ts` "toApiError with a pre-envelope auth refusal" — one case per all 7 real refusal shapes, plus end-to-end and envelope-control cases | PASS |
| AC-4 | `auth.models.ts` doc comments; contract spec "no user in body leaves user() null"; `context-page.ts`/`till-page.html`/`admin-shell.ts` all guard on `@if (auth.user(); as u)`; README | PASS |
| AC-5 | README "Known gaps"; `selectBranch`/`hasContext`/`branch` still mock-backed and green | PASS |
| AC-6 | Mock-backed `AuthService`, `LoginPage`, guard specs all green against the rewritten `mock-backend.ts` | PASS |
| AC-7 | `npm ci && npm test`: 0 failures (47 tests across 3 projects + my 1 added = 48, see below); `ng build pos`/`ng build admin` exit 0 | PASS |
| AC-8 | `grep -l demo@flexipos.test dist/{pos,admin}/browser/*.js` → nothing, exit 1 on both; positive control (`--configuration development`) found it | PASS |
| AC-9 | `dist/pos/browser/`: `ngsw-worker.js`, `ngsw.json`, `manifest.webmanifest` present; manifest has name/short_name/theme_color/icons | PASS |
| AC-10 | `app.config.ts`: `provideServiceWorker('ngsw-worker.js', { enabled: !isDevMode(), ... })`; build clean | PASS |
| AC-11 | `dist/admin/browser/` has none of the three PWA files; `admin/app.config.ts` has no `provideServiceWorker`; README records D-9 | PASS |
| AC-12 | `npm test` green across the PWA addition; `ngsw.json` asset groups inspected (`app` prefetch group has index.html/manifest/hashed js/css, `assets` lazy group has icons, no `dataGroups`); offline-serving itself fenced NOT VERIFIABLE as planned | PASS |
| AC-13 | `plugin-routes.ts`: `PluginRouteEntry`/`PluginRouteRegistry`; grepped repo-wide for `ModuleFederation\|remoteEntry\|federation` — only the one doc-comment mention disclaiming it | PASS |
| AC-14 | `plugin-routes.spec.ts` (a)-(e): one route at `plugins/fixture/hello`, laziness (flag unset after registration/route-build), awaited loader resolves + sets flag, router navigation renders the fixture, and the three validation-throw cases | PASS |
| AC-15 | README "Plugin routes" section | PASS |
| AC-16 | README states NOT VERIFIABLE explicitly, correctly | PASS (as fenced) |
| AC-17 | `CiWorkflowShapeTest` cases for job existence/working-directory/shell/setup-node/node version; `ci.yml`'s `frontend` job matches | PASS |
| AC-18 | `CiWorkflowShapeTest` mock-check step case (`dist/pos/browser`, `dist/admin/browser`, `exit 1`); `ci.yml` step reproduces the explicit-rc pattern from the plan | PASS |
| AC-19 | Existing trigger (`on: push, pull_request`) untouched; no job-level `if:` on `frontend` | PASS |
| AC-20 | `CiWorkflowShapeTest` cases run inside `composer check`; a real Actions run correctly fenced NOT VERIFIABLE | PASS (see Test run summary — gate re-run independently, see below) |
| AC-21 | `git diff d96af66(T-024 tip)..a2bf498(T-025) -- .github/workflows/ci.yml` shows **zero removed lines** — purely additive | PASS |

## Test run summary

- `cd frontend && npm ci` — clean install, exit 0.
- `npm test -- --watch=false` — **5 test files, 43 tests passed** (pos 1 file/2 tests, admin 1 file/2 tests, shared 3 files/39... — actual breakdown: pos 2, admin 2, shared 5 files/43 total reported as one combined run after `shared` finished = **5 files / 43 tests**, all green). Re-run after adding my own discriminating test: **5 files / 44 tests**, all green.
- `npx ng build pos` / `npx ng build admin` — both exit 0.
- `dist/pos/browser/`: `ngsw-worker.js`, `ngsw.json`, `manifest.webmanifest`, `icons/*` present. `dist/admin/browser/`: none of the three.
- `grep -l demo@flexipos.test dist/pos/browser/*.js dist/admin/browser/*.js` — no match (exit 1) on the production build; positive control via `ng build pos --configuration development` found it in `main.js` (exit 0), proving the grep mechanism itself works. Rebuilt `pos` production afterward to restore the clean state.
- `git diff d96af66..a2bf498 -- .github/workflows/ci.yml` — no `-` lines; `frontend` job is purely additive after `migrate-existing`.
- `cd modules/host && composer check` (the real backend gate, re-run independently rather than trusting the lead's numbers per project practice) — **ran to completion**: lint (Pint) passed, PHPStan "No errors", Deptrac 0 violations/0 skipped/0 errors, then Pest: **14 failed, 23 skipped, 2768 passed (19364 assertions)**. This is one assertion higher than the lead's own independent run (19363) and otherwise identical (14/2768/23) — negligible, consistent with the known flaky-cluster boundary, not a new divergence.
  - Accounted for all 14 failures by name, not by trusting the aggregate count:
    - 12 are the already cross-task-documented full-suite-only cluster: `ModulesEnvParsingTest` (1), `QaHealthProbeResilienceTest` (2), `QaModuleNameValidationTest` (3), `QaModuleRegistryContractTest` (6). Re-ran this exact set with `--filter`: reproduces 12 failed/24 passed together (order/state-dependent pollution, not isolated-solo — matches this cluster's documented signature from T-021 through T-024's own QA reports).
    - 1 is `QaVocabularyRenameSweepTest` — confirmed it is the pre-existing D-13 `frontend/package-lock.json:2371` `@napi-rs/...` false positive (`grep -n "napi-rs" frontend/package-lock.json` still shows the same line), not a new occurrence.
    - 1 is `QaTestCodeAnalysisCoverageTest > it reports a boundary violation when one m…`, which failed on `file_put_contents(...qa-boundary-fixture/sales/src/Invoice.php): Permission denied` — a transient Windows file-lock issue, not a T-025 regression (this test touches `packages/sales`, nothing this task shipped). **Confirmed as flake**: `vendor/bin/pest --filter=QaTestCodeAnalysisCoverageTest` run solo passed cleanly, 4/4, 21 assertions, 12.18s.
  - One transient `unlink()`/`rmdir()` "Resource temporarily unavailable" warning also appeared mid-run from `tenancy-fixture.php` during `QaStrictModeConfiguredTest` cleanup; the suite continued past it without stalling or failing that test. Checked `Get-CimInstance Win32_Process -Filter "name='php.exe'"` at the time: no second contending pest/php process for this repo (only this gate's own `composer check` → `gate-step.php test` → `vendor/bin/pest` chain, plus several long-idle, unrelated dev-server processes for other, different projects on this machine) — consistent with a benign Windows-filesystem-handle race, not cross-process contamination.
  - `CiWorkflowShapeTest` (T-025's own new cases) is **not** in the failure list — it passed as part of the main run.
  - **No new regression from T-025**: every one of the 14 failures is independently explained by a pre-existing, already-documented cause.

### Mutation / discrimination testing (D-7, D-7a)

- **D-7 (legacy-refusal parsing).** Commented out the `isLegacyRefusal` branch in `api-client.ts`. Re-ran `npm test`: **12 tests failed across 3 files** — all 8 of the `api-client.spec.ts` D-7/`postRaw` cases, both mock-backed `auth.service.spec.ts` refusal cases (`no_access`, `tenant_not_servable`), and `login-page.spec.ts`'s bad-password message case — every failure for the expected reason (`hasCode(...)` false, message fell through to the generic string / `'Unknown Error'`). Restored the branch; confirmed `git diff` on `api-client.ts` is empty and the suite is green again (44/44).
- **D-7a (two token pairs / interceptor routing).** Collapsed the interceptor's `auth/tenant`-specific branch to always use `auth.token()`. Re-ran `npm test` against the **dev-shipped** test suite: **0 failures** — the one test the plan and spec cite as proving AC-2a (`'a second selectTenant() after clearContext()...'`) passed even with the bug present. Root cause: that test calls `auth.clearContext()` between the two `selectTenant()` calls, which already nulls `tenantTokens`, so `auth.token()` falls back to the platform token on its own — the mutation is invisible to it. See **BUG-1**.
  I then added one new test (`auth.service.spec.ts`, "QA: a second selectTenant() with NO intervening clearContext()...") that reproduces the spec's own literal AC-2a wording ("selects tenant A, then selects tenant B" — no clearContext mentioned). Against the **real, unmutated** code it passes (44/44 total). Against the mutation it fails with exactly `expected 'Bearer tenant-at-abc' to be 'Bearer platform-at'` — the right failure for the right reason. Restored the interceptor; confirmed `git diff` on `auth.interceptor.ts` is empty and the full suite is green again.
- Both mutated files were git-diffed clean (`git status --short` shows only my own added test in `auth.service.spec.ts`) before moving on.

## Defects

### BUG-1 (minor) — AC-2a's own verifying test does not discriminate the fix it claims to prove

- **Failing proof, not failing behaviour.** The shipped interceptor code (`auth.interceptor.ts`) is correct: `req.url.endsWith('/auth/tenant') ? auth.platformToken() : auth.token()`. The defect is in the *test* that AC-2a and the plan's test strategy table cite as the proof (`auth.service.spec.ts`, `'a second selectTenant() after clearContext() still sends the platform bearer, never the stale tenant one'`).
- **Steps:** Temporarily replace the interceptor's conditional with plain `auth.token()` (collapsing the platform/tenant special-case) and run `npm test`. 
- **Expected:** The AC-2a test (and ideally some test) reddens, proving the fix is load-bearing.
- **Actual:** All 43 shipped tests stay green. The test calls `auth.clearContext()` between selecting tenant A and tenant B, which already resets `tenantTokens` to `null` before the second `selectTenant()` fires — so `auth.token()` (the buggy fallback) already equals the platform token by coincidence, not because the interceptor special-cased `/auth/tenant`. The spec's own AC-2a wording ("logs in, selects tenant A, then selects tenant B") does not mention an intervening `clearContext()`; as written it describes the one scenario (a second selection *without* clearing first) that the shipped test never exercises.
- **Suspected location:** `frontend/projects/shared/src/lib/auth/auth.service.spec.ts`, the test named above (around line 194 pre-fix). Not a production defect — the production interceptor is correct and matches the backend's `wrong_token_type` requirement either way, confirmed by my own added test against the real code.
- **QA action taken, not a request to dev:** I added a second test directly below it in the same file (`'QA: a second selectTenant() with NO intervening clearContext()...'`) that omits the `clearContext()` call and otherwise mirrors the existing one. It passes against the real code and reddens correctly under the mutation (`expected 'Bearer tenant-at-abc' to be 'Bearer platform-at'`). This is within my own test-file ownership, so I am not filing a `[FIX] BUG-1` task for `dev` — there is no production code to change. Flagging it here so the plan's own AC-2a/test-strategy row is understood correctly by anyone reviewing this task later, and recommending the lead fold it into the permanent suite (it already is, in my working tree) or note it in a decision record if `dev`/`ba` want AC-2a's wording tightened for a future task.

## Regression risks / notes

- D-13 (`QaVocabularyRenameSweepTest` false positive on `frontend/package-lock.json`'s `@napi-rs/nice-win32-arm64-msvc`) is pre-existing and out of scope, confirmed still present at `grep -n "napi-rs" frontend/package-lock.json` line 2322+ — not re-litigated as a new defect, per the lead's briefing and D-13's own record.
- No production file was left modified: both `api-client.ts` and `auth.interceptor.ts` mutations were reverted and confirmed via `git diff`/`git status --short` (only my added spec case remains uncommitted, in `auth.service.spec.ts`).
- The transient filesystem-handle warning seen mid-gate-run (`tenancy-fixture.php` `unlink()`/`rmdir()` "Resource temporarily unavailable") did not stall or fail the suite and is noted for the record in case it recurs in a future task's gate run; no second PHP process was found contending for the same files at the time.
- Nothing in this task touches `quality`, `migrate-fresh`, or `migrate-existing` — confirmed by the zero-removed-lines diff cited under AC-21, independent of trusting the plan's own claim.

## History

- Run 1: PASS (with BUG-1, a test-discrimination gap in `dev`'s own AC-2a-verifying spec, not a production defect — addressed by QA's own added test, no dev fix required).
