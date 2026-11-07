# Review: T-025 — Frontend bootstrap completion

## Round 1 — APPROVE (implementation commit `a2bf498`, QA fix `3fc9997`)

No Criticals. `ENGINE_HOLD` confirmed respected: nothing in this diff connects to or assumes a
MySQL/MariaDB engine. The new `frontend` CI job has no `services:` block and no DB env (pinned
by the shape test); the frontend specs use `HttpTestingController` and the mock backend only.
CI additivity confirmed: `git diff 526ce8d..9195b1e -- .github/workflows/ci.yml` shows 50 added
lines, 0 removed — no existing job changed.

What reviewer ran: `npm test` in `frontend/` (5 files, 44 tests, all passed);
`vendor/bin/pest tests/Feature/CiWorkflowShapeTest.php` (20 tests, 133 assertions, all passed).
Did not re-run the full `composer check` (already independently run twice — by the lead and by
`qa` — with matching accounted-for results).

**Warnings (folded into a fix round, per this project's practice when a Warning is a real
latent bug or undermines a test's own stated purpose):**
- **R-1** `frontend/projects/shared/src/lib/ui/context-page.ts:31-33` — tenants the user cannot
  open still render as clickable buttons. The real `LoginResult` tenant item carries
  `selectable`, and the mock's `TEN003` is `selectable: false`, but the template ignores it —
  the user clicks, waits for the round trip, and gets a 403 `tenant_not_servable`.
- **R-2** `modules/host/tests/Feature/CiWorkflowShapeTest.php` (the "inverts grep exit code"
  case) — `->toContain('exit 1')` doesn't test what it claims; the `compgen ... || { exit 1; }`
  guard line already satisfies it even if the real match branch (`0) ... exit 1 ;;`) were
  changed to `exit 0`, silently breaking the job's ability to fail on a leaked mock while the
  test stays green.

**Suggestions filed as follow-ups (non-blocking):**
- **FP-405** (R-1's fix, tracked separately as a follow-up in case it isn't folded — superseded
  by this round's fix)
- **FP-406** (R-2's fix — superseded by this round's fix)
- **FP-407** `plugin-routes.ts`'s `register()` validates only that `pluginId` is non-empty;
  nothing stops an empty `path` (trailing-slash route that never matches), a leading `/`, `..`,
  `**`, or a `pluginId` containing `/`. Not a code-execution risk (entries are bundled
  TypeScript, not runtime/remote data). Also: `admin/app.routes.ts` calls `toRoutes()` once at
  module load; any later `register()` is silently ignored — suggest freezing the registry after
  `toRoutes()` and throwing on a late `register()`.
- **FP-408** `legacy-refusal.ts`: if the server ever omits `status` on a `tenant_not_servable`
  body, the message renders "...while its status is undefined." — use `?? 'unknown'`. Also:
  document that the legacy-refusal fallback in `toApiError()` applies to every endpoint (safe,
  since the envelope branch runs first and `isLegacyRefusal()` excludes real envelopes), and
  that it goes away with FP-369.
- **FP-409** `auth.interceptor.ts` attaches the bearer token to every outgoing request,
  including absolute cross-origin URLs (pre-existing, not introduced by this task) — suggest
  scoping to `API_BASE_URL`. Also: both token pairs now sit in `sessionStorage`; within NFR
  but widens XSS blast radius — record as an input for whoever builds the real refresh flow
  (memory-only or httpOnly-cookie storage).

## Done well

- `postRaw()` is narrow — only `login`/`selectTenant`/`logout` call it; `post`/`get`/`put`/
  `delete` untouched.
- No credential echo: refusal messages are fixed client text plus server `reason`/`status`
  only; nothing from the request leaks into an `ApiError`.
- No XSS surface: all values render through Angular interpolation, no `innerHTML`, no
  `bypassSecurityTrust*`, no token logging.
- D-7a (dual token pairs) is correct and minimal: the interceptor routes `auth/tenant` through
  the platform token and everything else through `token()`; `clearContext()` drops the tenant
  pair; `logout()` clears state before firing revocation calls so a 401 there can't loop;
  `restore()` discards sessions in the old T-012 format; the spec asserts the second
  `auth/tenant` call carries the platform bearer.
- The PWA caches nothing sensitive — `ngsw-config.json` has only `assetGroups`, no
  `dataGroups`; service worker enabled only in `pos`'s production config, `admin` untouched.
- The plugin registry makes no backend call, enforces exactly one loader, rejects duplicate
  mounted paths, and sits under the shell's `contextGuard` so routes inherit auth.
- The mock backend matches the real T-009 shapes and refusal tokens, including
  `wrong_token_type` for a tenant bearer sent to `auth/tenant`.
- The CI grep step handles exit code 2 explicitly and fails on an empty `dist/`; the new shape
  test cases find the job by key and throw if missing — none vacuously true except R-2.

## QA

- Round 1: PASS, 0 defects (one test-coverage-only gap found and closed by `qa` itself, not a
  production bug — see `docs/qa/T-025-frontend-bootstrap.md`).

## Verdict after Round 1

**APPROVE, with R-1/R-2 folded into a fix round** before marking T-025 DONE (same practice as
T-022/T-024: a Warning that is either a real latent bug or undermines its own test's proof gets
fixed, not just filed).
