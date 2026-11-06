# T-025: Frontend bootstrap completion

Backlog: FP-219, "Frontend bootstrap, built against a mock API while the backend is written."
Built earlier as "T-012" (one commit, no spec/plan/decisions/QA/review), carried forward verbatim
onto the current tip by the lead and re-verified working (`npm ci`/`npm test` 21/21, both `ng build`
targets clean, mock backend excluded from production bundles). This task closes the four pieces of
work FP-219's own backlog note names as remaining: API-contract reconciliation against T-021's real,
shipped contract; PWA; plugin remotes; a CI job. Decisions D-1 to D-12 are in
`docs/decisions/T-025-frontend-bootstrap.md`. **No BLOCKING questions** — autonomous/team-mode work;
every open question was decided and logged there rather than left for the human.

Convention, following T-021 to T-024: prefer an acceptance criterion a real, running check can prove
(`npm test`, `ng build`, the mock-exclusion grep, a structural parse of the committed CI YAML) over
anything that would need live infrastructure. Anything that genuinely cannot be verified without
infrastructure this team does not have is fenced **NOT VERIFIABLE**, with the reason, the same pattern
T-022/T-023/T-024 use.

## Problem / goal

The Angular workspace built as T-012 assumed an API contract before T-021 (API foundation) and the
real T-009 authentication endpoints existed. Reading the shipped backend code shows the assumption was
wrong in several concrete, fixable ways (see "Current behaviour"), while the response envelope itself
turned out to match exactly. The workspace also ships no PWA artefacts, no plugin extension point, and
no CI coverage. This task closes all four gaps so FP-219 can move to DONE without carrying known,
un-investigated drift into later tasks that build on top of this frontend.

## Users affected

- Frontend developers on later tasks (the first real till screen, the admin back office), who need the
  auth service to call real endpoints with real shapes instead of ones that were never true.
- Operators and support, who need `ApiError` to surface the real auth refusal token (e.g.
  `invalid_credentials`) instead of a generic "The request failed." once auth calls hit a real backend.
- Till users, who get an installable, offline-tolerant `pos` app once the service worker and manifest
  exist.
- The CI pipeline's readers (dev, reviewer, future contributors), who currently have no signal at all
  if a frontend change breaks `npm test` or either production build.
- Not served by this task: no real backend call succeeds end-to-end yet (the mock backend remains the
  only thing `pos`/`admin` talk to in development; T-009's real endpoints are reachable only once a
  real master database and signing key exist, which is outside this task); no offline data/sync (FP-237)
  and no real plugin bundle loading (no plugin HTTP surface exists, per D-10).

## Current behaviour

Re-derived directly from the shipped frontend and backend code, not assumed:

- `frontend/projects/shared/src/lib/api/envelope.ts` defines `ApiEnvelope<T>` as
  `{success, data, message, errors[{code,field,detail}], meta{request_id}}` and `isEnvelope()` checks
  for `'success' in body && 'meta' in body`. T-021's real, shipped envelope (`docs/specs/T-021-api-foundation.md`
  AC-1/AC-3/AC-4) is exactly this shape. **This part matches with no change needed.**
- T-021 D-1/AC-48 deliberately exempt the existing auth/access/organization/platform/terminal endpoint
  bodies from ever being wrapped in that envelope. Reading `modules/host/app/Http/Controllers/Auth/*`
  and `App\Http\Responses\AuthRefusals` confirms it: every auth response is a raw JSON array, and every
  auth failure is `{"error": "<token>"}` or `{"error":"invalid_request","reason":"<text>"}` — never
  `{success:false, errors:[...], meta:{...}}`. `frontend`'s `isEnvelope()` therefore returns `false` for
  every real auth failure body, and `toApiError()` falls through to a generic
  `"The request failed."`, losing the real refusal token.
- `frontend/projects/shared/src/lib/auth/auth.service.ts`/`auth.models.ts`/`mock/mock-backend.ts` assume:
  - `POST auth/login` with `{email,password}` returning `{token, user:{id,name,email}, tenants:[{id,name,branches:[{id,name}]}]}`.
  - `POST auth/select-tenant` with `{tenant_id}` returning `{token, tenant:{id,name,branches:[...]}}`.
  - `POST auth/logout` with no body returning `{}`.
  The real, shipped endpoints (`packages/auth/routes/api.php`, `LoginController.php`,
  `SelectTenantController.php`, `LogoutController.php`, `LoginResult.php`, `SelectTenantResult.php`,
  `LogoutResult.php`) are:
  - `POST auth/login` with `{email,password}` returning `{access_token, refresh_token, tenants:[{id,code,name,status,selectable,is_default}], default_tenant_id, must_change_password}` — **no `user` object, no `branches` under a tenant, two tokens not one.**
  - `POST auth/tenant` (not `select-tenant`) with `{code}` (not `tenant_id`), requiring a bearer
    (`RequirePlatformToken`) and returning `{access_token, refresh_token, tenant_id, tenant_code}` —
    **different path, different request key, different response shape, two tokens.**
  - `POST auth/logout` with `{refresh_token}` (not empty) returning `{status:"signed_out"}` always,
    whatever token is presented, including none (one answer for every case, by design).
- No real endpoint anywhere lets a signed-in person choose a branch. T-014 (branches/warehouses/
  terminals, DONE) ties branch context to **terminal activation**
  (`ActivateTerminalResult` → `{terminal_id, branch_id, access_token, refresh_token}`), a device-level
  process, not a per-employee-session choice. The frontend's two-step "pick company, then pick branch"
  flow and its `TenantSummary.branches` field have no real backend counterpart today (D-5).
- No service worker, no web manifest, anywhere in `frontend/` (confirmed: `frontend/README.md`'s own
  "Not built yet" section names this).
- No plugin-remote mechanism of any kind in `frontend/`. T-019 (plugin framework, DONE) ships a backend
  hook gateway but explicitly builds no HTTP surface for plugins (`docs/decisions/T-019-plugin-framework.md`
  D-10: "Console/internal commands only... no platform-admin authorization model or HTTP surface exists
  anywhere in this codebase yet"), so there is no real registry or manifest-serving endpoint a frontend
  mechanism could call.
- `.github/workflows/ci.yml` (T-024, DONE) has three jobs (`quality`, `migrate-fresh`,
  `migrate-existing`), all backend-only (`working-directory: modules/host`, PHP/Composer tooling); no
  job installs Node, runs `npm test`, builds either Angular app, or checks the mock-exclusion grep.

## User stories

- As a frontend developer, I want the auth service to call the real T-009 endpoints with their real
  request and response shapes, so that wiring a real backend later is a configuration change, not a
  rewrite.
- As a support engineer, I want a real auth failure (wrong password, no tenant access, etc.) to surface
  its real refusal code through `ApiError`, so that a ticket can be matched to a specific cause instead
  of a generic message.
- As a till operator, I want `pos` to be installable and to keep working (for the shell and static
  assets) across a brief network drop, so that a counter does not go dark the moment Wi-Fi blips.
- As a future plugin author, I want a documented, minimal way to register a lazy-loaded route from a
  plugin bundle, so that FP-296's first real plugins have a frontend convention to target instead of
  inventing one themselves.
- As a reviewer or future contributor, I want CI to run `npm test` and both production builds and check
  the mock-exclusion grep automatically, so that a frontend regression is caught before merge, the same
  way a backend one already is.

## Acceptance criteria

### A. API-contract reconciliation

- AC-1: Given `envelope.ts`'s `ApiEnvelope<T>` and `isEnvelope()`, When compared against T-021's shipped
  envelope (`docs/specs/T-021-api-foundation.md` AC-1/AC-3/AC-4), Then the shapes match exactly (same
  five top-level keys, same nesting, same `errors[]` item shape) and no change is required to
  `envelope.ts` itself. **Verifiable by inspection, recorded in D-1; no code change, no new test.**
- AC-2: Given the real auth endpoints never wrap their bodies in the envelope (T-021 D-1, confirmed
  directly against `AuthRefusals.php`/`LoginController.php`/etc.), When `auth.service.ts` is updated,
  Then it stops assuming an enveloped auth response and instead matches the real, shipped field names:
  `login()` reads `{access_token, refresh_token, tenants, default_tenant_id, must_change_password}`;
  `selectTenant()` posts to `auth/tenant` with `{code}` and reads
  `{access_token, refresh_token, tenant_id, tenant_code}`; `logout()` posts `{refresh_token}`. Verified
  by `npm test` (updated/added unit specs for `AuthService` asserting the exact request body and path
  for each call, and the exact fields read from each response) and by `ng build pos`/`ng build admin`
  staying clean.
- AC-3: Given a real auth failure body is one of `{"error":"invalid_credentials"}`,
  `{"error":"wrong_token_type"}`, `{"error":"no_access"}`, `{"error":"tenant_not_servable", ...}`,
  `{"error":"invalid_request","reason":"..."}`, or `{"error":"signing_unavailable"}` (none of which
  satisfies `isEnvelope()`), When `toApiError()` receives one, Then it returns an `ApiError` whose
  message/code reflects the real `error` token (and `reason` when present) rather than falling through
  to the generic `"The request failed."` branch. Verified by `npm test` (a unit spec per listed shape
  feeding `toApiError()` a matching `HttpErrorResponse` and asserting the parsed `ApiError.hasCode(...)`
  or equivalent reads the real token).
- AC-4: Given `LoginResult` carries no user object, When `auth.models.ts`/`Session` are updated, Then
  `AuthUser`/`session.user` either is removed from the real-contract path or is clearly documented as a
  mock-only convenience not sourced from any real response field; no production code path reads a
  `session.user.email`/`.name` as if the real backend supplied it. Verified by `npm test` and by reading
  the updated `auth.models.ts`/`README.md`.
- AC-5: Given no real endpoint supplies a branch list or lets a session pick a branch (T-014 ties branch
  context to terminal activation, not login), When the reconciliation is done, Then this gap is recorded
  in `frontend/README.md`'s API-contract section as a known, logged limitation (cross-referencing D-5),
  and the branch-selection UI/state in `AuthService` (`selectBranch`, `hasContext`, `branch`) is left
  functioning against the mock only, explicitly marked as not backed by any real endpoint today. **Not a
  code deletion** — the UI is still useful once a real endpoint exists — but it must not be presented in
  the README as part of the reconciled, real contract.
- AC-6: Given the mock backend (`mock-backend.ts`) is updated to match the real request/response shapes
  from AC-2 and AC-3 (so a developer building against the mock is building against the real contract,
  not the old assumed one), When `npm test` runs, Then all consuming specs (`AuthService`, any
  sign-in-screen specs) pass against the updated mock.
- AC-7: Given the full reconciliation (AC-2 to AC-6), When `npm ci && npm test` is run, Then all tests
  pass (count may differ from the prior 21/21 if specs were added or changed; the criterion is 0
  failures, not a fixed count), and `ng build pos`/`ng build admin` both complete with exit code 0.
- AC-8: Given a production build of either app, When
  `grep -l demo@flexipos.test dist/<project>/browser/*.js` is run, Then it prints nothing (the mock
  backend, including its updated real-shaped bodies, stays excluded from production bundles) — the same
  check `frontend/README.md` already documents, re-run after the reconciliation changes.

### B. PWA

- AC-9: Given `ng add @angular/pwa --project=pos` (or the equivalent conventional Angular PWA tooling)
  is applied, When `pos` is built for production (`ng build pos`), Then the output includes
  `ngsw-worker.js`, a generated `ngsw.json` manifest config, and a `manifest.webmanifest` referencing at
  least a name, short_name, theme colour and one icon size, consistent with Angular's own `ng add` output
  shape. Verified by inspecting `dist/pos/browser/` after the build.
- AC-10: Given `pos`'s `app.config.ts` (or equivalent), When the production build runs, Then
  `provideServiceWorker('ngsw-worker.js', { enabled: !isDevMode() })` (or the `ng add`-generated
  equivalent) is registered, so the service worker is never active in a `ng serve`/development build.
  Verified by reading the generated registration code and by `ng build pos` succeeding with no new
  lint/build errors.
- AC-11: Given `admin` receives no PWA treatment in this task (D-9: back-office use case, no offline
  story yet), When `ng build admin` runs, Then it produces no service-worker or manifest artefacts, and
  `frontend/README.md` records the decision and its reason so a later task does not re-discover the gap
  from scratch.
- AC-12: Given the PWA addition, When `npm test` runs, Then the existing 21 (or updated) tests remain
  green — the PWA addition changes build output, not application logic, so no existing spec should need
  to change. **NOT VERIFIABLE beyond this task's own tooling**: whether the service worker actually
  serves cached content offline in a real browser against a real deployed origin cannot be checked by
  this team without a running deployment; fenced NOT VERIFIABLE, confirmed instead by inspecting the
  generated `ngsw.json`'s asset groups for the expected app-shell files.

### C. Plugin remotes

- AC-13: Given D-10 (no real plugin HTTP surface exists anywhere in the backend, per T-019's own D-10),
  When the frontend plugin-remote mechanism is built, Then it is a documented TypeScript convention in
  `@flexipos/shared` — a `PluginRouteEntry` interface (at minimum: a plugin identifier, a route path, a
  lazy `loadComponent`/`loadChildren` loader) plus a small in-memory registry (`PluginRouteRegistry` or
  equivalent) that a host app can read to contribute lazy Angular routes — and nothing else (no module
  federation config, no remote-origin bundle fetch, no call to any backend plugin endpoint).
- AC-14: Given the registry, When one fixture entry is registered and the host app's routes are built
  from it (mirroring T-019's own "one fixture plugin" proof pattern), Then a unit test proves the
  fixture's route is present in the resulting route table and its `loadComponent`/`loadChildren` is
  lazy (not eagerly imported at module scope). Verified by `npm test`.
- AC-15: Given the convention, When documented, Then `frontend/README.md` gains a section naming it
  explicitly as a minimal extension point, referencing T-019's hook modes and D-10's "no HTTP surface
  yet" finding, so a later task building the real plugin-serving endpoint and/or module federation knows
  what this task did and did not build, and why.
- AC-16: **NOT VERIFIABLE in this task**: loading a real plugin's frontend bundle from a real plugin
  registry or a real remote origin. No such registry exists (T-019 D-10) and none is built here; any
  claim beyond "the registration convention exists and is unit-tested against a fixture" would be
  unproven infrastructure this team cannot stand up.

### D. CI job

- AC-17: Given `.github/workflows/ci.yml`, When a new `frontend` job is added (D-11: independent job,
  sibling to `quality`/`migrate-fresh`/`migrate-existing`, not folded into `quality`), Then it sets
  `working-directory: frontend`, uses `actions/setup-node@v4` (or the current conventional action) with
  a pinned Node major version matching this project's documented local version (24), and runs, as
  separate named steps: `npm ci`, `npm test` (the full headless/CI test run for all three projects),
  `npx ng build pos`, `npx ng build admin`.
- AC-18: Given the same job, When the two production builds complete, Then a further step runs the
  mock-exclusion check for both apps (`grep -l demo@flexipos.test dist/pos/browser/*.js` and the `admin`
  equivalent) and fails the job if either grep finds a match (i.e. the step's own exit code is inverted
  from grep's, since grep exits 0 on a match and this job must fail on a match).
- AC-19: Given the `frontend` job, When it runs on `push` or `pull_request` (T-024 D-7: no branch or
  path filter), Then it triggers on the same events the existing three jobs already trigger on (the
  workflow's shared top-level `on:` block, unchanged).
- AC-20: Given `modules/host/tests/Feature/CiWorkflowShapeTest.php` (T-024's static-verification idiom,
  D-12: extended, not duplicated, per D-12), When extended with cases for the new `frontend` job, Then
  the test statically parses the committed `ci.yml` and asserts: the job exists; its
  `working-directory` is `frontend`; it contains a Node setup step; its steps include, in order,
  `npm ci`, `npm test`, a `pos` build, an `admin` build, and the mock-exclusion check. This test runs in
  `composer check` (the existing backend gate), so a future edit to the frontend job's shape cannot
  silently drift. **This is the one piece of this task's CI work that a real GitHub Actions run would
  further confirm** (the workflow actually succeeding on a runner) — fenced **NOT VERIFIABLE** beyond
  the static shape check, same as T-024's own AC-9/AC-10/AC-13 to AC-15/AC-19, because this team cannot
  trigger a real Actions run.
- AC-21: Given the new job, When read against T-024's existing three jobs, Then nothing in `quality`,
  `migrate-fresh`, or `migrate-existing` is modified — this task is additive only to the workflow file,
  verified by a diff showing only new lines/a new job block.

## Out of scope

- Rewriting the real backend to supply a branch-selection endpoint, a "who am I" endpoint, or any other
  endpoint the frontend wants but does not have (D-5, D-6) — that is backend work for a later task.
- Offline database and sync (FP-237) — the PWA service worker caches the app shell/static assets only;
  no IndexedDB store, no sync queue, no conflict resolution.
- The shared tax and discount engine (FP-231), translations — both already named "not built yet" in the
  README and untouched by this task.
- Module federation, a served plugin manifest endpoint, or dynamic remote-bundle loading (D-10) — no
  real backend surface exists for any of these yet.
- A real GitHub Actions execution of the new `frontend` job (fenced NOT VERIFIABLE, AC-20).
- Any change to `quality`, `migrate-fresh`, or `migrate-existing` (AC-21).
- Rewriting non-auth frontend code (till/admin shell UI, routing shells) that does not touch the API
  contract, PWA, plugin, or CI surfaces.

## Non-functional requirements

- Security: the auth-service reconciliation (AC-2/AC-3) must not log or persist a raw refresh/access
  token anywhere beyond the existing `sessionStorage` mechanism already in place; parsing a real
  `{error:...}` body (AC-3) must not echo request credentials into any `ApiError` message.
- Compatibility: the mock backend (AC-6) continues to exclude itself from production bundles (AC-8) —
  the single most load-bearing existing guarantee in this workspace, and this task must not weaken it
  while changing the shapes it mocks.
- Operability: the new CI job (AC-17 to AC-20) must fail loudly and specifically (a named step per
  concern) rather than as one opaque script, matching T-024's own D-5 idiom.

## Assumptions

- T-009's authentication endpoints and T-021's envelope/error-catalogue are both DONE and will not
  change shape again before this task's `dev` step reconciles against them; if either changes before
  this task ships, the reconciliation is re-opened, not silently stale.
- "Real, running check" for the frontend means `npm test` (Karma/Jasmine or the project's configured
  runner) and `ng build`, run by `dev`/`qa` on this machine (Node 24.15/npm 11.12/Angular CLI 21.2 per
  `CLAUDE.md`); no headless browser or CI runner beyond what already backs the existing 21/21 suite is
  assumed to be unavailable.
- D-9's PWA-for-`pos`-only call and D-10's plugin-remote scoping are both reversible decisions that a
  later task can widen without this task's work needing to be undone.
