# Plan: T-025 Frontend bootstrap completion

Spec: `docs/specs/T-025-frontend-bootstrap.md` (AC-1 to AC-21). Decisions: `docs/decisions/T-025-frontend-bootstrap.md` (D-1 to D-12, honoured as written, not reopened).

## Approach summary

Four independent pieces, done in order of risk: (A) the API-contract reconciliation, then (B) PWA, (C) plugin routes, and (D) the CI job.

**(A) is a bug fix and is planned like one.** I re-read the shipped code and confirmed D-7. `toApiError()` only looks for structure inside an envelope. Every real auth refusal is a flat `{"error": "<token>"}` body, so the token is thrown away. I also found a second instance of the same bug class, on the **success** path, which the spec does not name. `ApiClient.unwrap()` throws "not an API envelope" for every 2xx body without `success`/`meta`, so every real auth **success** (a raw `LoginResult`/`SelectTenantResult`/`LogoutResult` array) would also fail.

The fix for both is additive and stays in `@flexipos/shared/api`:
- a legacy-refusal parse branch in `toApiError()`;
- an `ApiClient.postRaw()` for the pre-envelope endpoints (T-021 D-1, until FP-369). It skips the unwrap step but still maps errors through `toApiError()`.

The envelope path itself is unchanged (D-1).

`auth.models.ts`, `auth.service.ts` and `mock-backend.ts` are then rewritten to the real T-009 shapes (D-3, D-4, D-8). One design consequence the spec does not spell out: `POST auth/tenant` requires a **platform** token (`RequirePlatformToken` answers 403 `wrong_token_type` to a tenant token). So the session must keep the platform pair alongside the tenant pair. If it overwrote the platform pair, "Change company" would break against the real backend. Branches (D-5) and the user (D-6) become optional, documented mock-only fields.

(B) is `ng add @angular/pwa --project=pos` with no data groups (shell and static assets only). (C) is an `PluginRouteEntry` interface plus a tiny registry that emits lazy routes, mounted under `plugins/<pluginId>/` (D-10). (D) is a new `frontend` job and new cases in `CiWorkflowShapeTest.php` (D-11, D-12).

## Alternatives rejected

- Making `ApiClient.post()` accept a non-envelope 2xx body: this would silently weaken the envelope check for every future envelope-native endpoint. A separate, named `postRaw()` keeps the legacy surface explicit and easy to delete when FP-369 lands.
- Calling `HttpClient` directly from `AuthService`: this would duplicate the `API_BASE_URL` joining and the error mapping. `postRaw()` reuses both.
- Prefixing legacy codes (`auth.invalid_credentials`): the server never sends that string. `ApiError.hasCode()` should match what is on the wire. FP-369 will move auth onto the catalogue, and its codes then arrive through the envelope path unchanged.
- Separate mock-only endpoints for branches or the user: these would invent backend contract. Mock-only **extra fields** on real-shaped bodies are smaller and easy to strip.
- Module federation (D-10).
- Folding the frontend into `quality` (D-11).

## Files to change / create

Frontend (`frontend/`):
- `projects/shared/src/lib/api/legacy-refusal.ts` - **new**:
  - `LegacyRefusal` type `{ error: string; reason?: string; status?: string }`;
  - `isLegacyRefusal(body)`: true when `error` is a string and the body is not an envelope;
  - `legacyRefusalToApiError(status, body, requestId)`, which also holds the token-to-message map.
- `projects/shared/src/lib/api/api-client.ts` - add the `isLegacyRefusal` branch to `toApiError()`, after the envelope branch and before the `status === 0` and generic branches. Add `postRaw<T>(path, body)`. The docblock names T-021 D-1 and FP-369.
- `projects/shared/src/lib/api/api-client.spec.ts` - the D-7 red-first cases (one per real refusal shape) and the `postRaw` cases.
- `projects/shared/src/lib/auth/auth.models.ts` - rewritten to the real `LoginResult`, `LoginTenant`, `SelectTenantResult` and `LogoutResult`, plus `TokenPair` and the new `Session`. `AuthUser`, `Branch`, `TenantSummary.branches?` and `LoginResult.user?` are kept, each with a mock-only doc comment (D-5, D-6).
- `projects/shared/src/lib/auth/auth.service.ts` - rewritten:
  - `login`, `selectTenant(code)` and `logout` use `postRaw` against the real paths and keys;
  - new `platformToken` signal;
  - `restore()` drops a stored session in the old T-012 shape.
- `projects/shared/src/lib/auth/auth.interceptor.ts` - sends the platform token to `/auth/tenant` and `auth.token()` everywhere else.
- `projects/shared/src/lib/auth/auth.service.spec.ts` - new contract specs using `HttpTestingController`. The existing flow specs are updated to the new mock.
- `projects/shared/src/lib/auth/auth.guards.spec.ts` - `seed()` updated to the new `Session` shape.
- `projects/shared/src/lib/mock/mock-backend.ts` - rewritten to raw real-shaped bodies and real refusal bodies. Logout moves ahead of the Authorization check (the real route has no middleware).
- `projects/shared/src/lib/ui/context-page.ts` - picks by `t.code`; greeting guarded on `auth.user()`; empty-branch message.
- `projects/shared/src/lib/ui/login-page.spec.ts` - only if a message string changes (the plan keeps "Email or password is wrong.", so probably no change).
- `projects/pos/src/app/till/till-page.html`, `projects/admin/src/app/shell/admin-shell.ts` - the `auth.user()?.name` read becomes `@if (auth.user(); as u)` (AC-4).
- `projects/shared/src/lib/plugins/plugin-routes.ts` - **new**: `PluginRouteEntry`, `PluginRouteRegistry`, `createPluginRouteRegistry()`.
- `projects/shared/src/lib/plugins/plugin-routes.spec.ts` - **new** (AC-14).
- `projects/shared/src/lib/plugins/testing/fixture-plugin-page.ts` - **new**: the fixture component, imported only by the spec and never exported from `public-api.ts`.
- `projects/shared/src/public-api.ts` - export `plugin-routes` (and `legacy-refusal`'s type guard).
- `projects/admin/src/app/plugins.ts` - **new**: `export const adminPlugins = createPluginRouteRegistry();` (empty).
- `projects/admin/src/app/app.routes.ts` - spread `...adminPlugins.toRoutes()` into the context-guarded shell `children`, before the `''` redirect.
- Generated by `ng add @angular/pwa --project=pos`, then edited by hand where noted:
  - `projects/pos/ngsw-config.json`;
  - `projects/pos/public/manifest.webmanifest` (name "FlexiPOS Till", short_name "Till", a theme colour);
  - `projects/pos/public/icons/*.png`;
  - `projects/pos/src/index.html` (manifest link and `theme-color` meta);
  - `projects/pos/src/app/app.config.ts` (`provideServiceWorker`);
  - `angular.json` (`serviceWorker` on `pos` only);
  - `package.json` and `package-lock.json` (`@angular/service-worker`).
- `README.md` - the "Assumed API contract" section becomes the "API contract (reconciled against T-009/T-021)" section. New PWA and plugin-routes sections. The "Not built yet" list is trimmed.

CI / backend:
- `.github/workflows/ci.yml` - a new `frontend` job, appended. Existing lines are untouched (AC-21).
- `modules/host/tests/Feature/CiWorkflowShapeTest.php` - new helpers and cases for the `frontend` job. The header docblock gets a T-025 paragraph.

## Data / API changes

No backend, schema, migration or endpoint change. Frontend contract only.

### Real auth contract, verified by me against source

Sources: `packages/auth/routes/api.php`, `modules/host/app/Http/Controllers/Auth/*.php`, `App\Http\Responses\AuthRefusals`, `LoginResult`, `SelectTenantResult`, `LogoutResult`, `GenericRefusal`, `NoTenantAccess`, `TenantNotServable`, `EmployeeUnavailable`.

| Call | Middleware | Body | 200 body |
|---|---|---|---|
| `POST api/v1/auth/login` | none | `{email, password}` | `{access_token, refresh_token, tenants:[{id,code,name,status,selectable,is_default}], default_tenant_id: string\|null, must_change_password: bool}` |
| `POST api/v1/auth/tenant` | `RequirePlatformToken` | `{code}` | `{access_token, refresh_token, tenant_id, tenant_code}` |
| `POST api/v1/auth/logout` | none | `{refresh_token}` | `{status:"signed_out"}`, always |
| `POST api/v1/auth/refresh` | none | `{refresh_token}` | out of scope; not wired in this task |

### Refusal bodies `toApiError()` must parse

None of these is an envelope:

| Status | Body | Where from |
|---|---|---|
| 401 | `{"error":"invalid_credentials"}` | Login. Also the middleware on `/tenant` when the token is missing, expired or bad. |
| 403 | `{"error":"wrong_token_type"}` | The middleware on `/tenant` when it receives a tenant token. |
| 403 | `{"error":"no_access"}` | `/tenant` |
| 403 | `{"error":"tenant_not_servable","status":"<tenant status>"}` | `/tenant` |
| 403 | `{"error":"employee_unavailable"}` | `/tenant`, refresh. **Missing from the spec's AC-3 list.** |
| 422 | `{"error":"invalid_request","reason":"<text>"}` | Login, `/tenant` |
| 503 | `{"error":"signing_unavailable"}` | Login, `/tenant`, refresh |

### Mapping into `ApiError`

`ApiError(status, message, [{ code: <error token>, field: null, detail: reason ?? status ?? undefined }], requestId)`:
- `requestId` is the `X-Request-Id` response header if present (`AssignRequestId` sets it), otherwise `null`.
- `message` comes from a fixed client-side map, because the legacy bodies carry no human text:

| Token | Message |
|---|---|
| `invalid_credentials` | "Email or password is wrong." (deliberately the mock's current string, so `login-page.spec.ts` stays meaningful) |
| `no_access` | "You do not have access to that company." |
| `tenant_not_servable` | "That company cannot be opened while its status is `<status>`." |
| `employee_unavailable` | "Your employee account in that company is not active." |
| `wrong_token_type` | "Sign in again to change company." |
| `signing_unavailable` | "Sign-in is temporarily unavailable. Try again later." |
| `invalid_request` | `reason` if present, otherwise "The request could not be read." |
| any other token | "The request was refused (`<token>`)." |

The message never includes anything from the request (NFR security). `reason` and `status` come from the server, and the server composes them about shape or tenant status, never about credentials.

### New `Session`, stored under the existing per-app `sessionStorage` key

```
TokenPair { accessToken: string; refreshToken: string }
Session {
  platform: TokenPair;            // from login; reused for every auth/tenant call
  tenantTokens: TokenPair | null; // from auth/tenant
  tenants: TenantSummary[];       // LoginTenant & { branches?: Branch[] /* mock-only, D-5 */ }
  defaultTenantId: string | null;
  mustChangePassword: boolean;    // stored, not acted on (no UI asked for)
  tenantId: string | null;        // from SelectTenantResult.tenant_id
  branchId: string | null;        // mock-only, D-5
  user?: AuthUser;                // mock-only, D-6: never sourced from a real field
}
```

AuthService signals:
- `token` = `tenantTokens?.accessToken ?? platform.accessToken`;
- new `platformToken`;
- `tenant` = lookup by `tenantId`;
- `branch` and `hasContext` unchanged in meaning (D-5). With the real backend, `hasContext` stays false because there are no branches. That is the logged D-5 gap and is written down in the README.

`logout()` stays `void` for its callers:
1. it snapshots every non-null refresh token (tenant, then platform);
2. it clears state and storage synchronously;
3. it fires one `postRaw('auth/logout', {refresh_token})` per token, swallowing errors (the real endpoint always answers 200).

`clearContext()` drops `tenantTokens`, `tenantId` and `branchId` locally and keeps `platform`. Revoking the tenant family on switch is not asked for and is left out.

The mock's login body is the real `LoginResult` plus two mock-only extras: `tenants[].branches` and a top-level `user`. Both are named in one comment block in `mock-backend.ts` and in the README. The models declare them optional.

### Plugin routes

```ts
export interface PluginRouteEntry {
  pluginId: string;          // the backend plugin's identifier (T-019)
  path: string;              // relative; mounted at plugins/<pluginId>/<path>
  loadComponent?: () => Promise<Type<unknown>>;
  loadChildren?: () => Promise<Routes>;
  data?: Data;
}
export interface PluginRouteRegistry {
  register(entry: PluginRouteEntry): void; // throws on: empty pluginId, not exactly one loader, duplicate mounted path
  entries(): readonly PluginRouteEntry[];
  toRoutes(): Routes;                     // [{ path: `plugins/${pluginId}/${path}`, loadComponent|loadChildren, data: { ...data, pluginId } }]
}
export function createPluginRouteRegistry(): PluginRouteRegistry;
```

The `plugins/<pluginId>/` prefix is my convention decision: a plugin can never shadow a host route such as `catalog`. It is a plain object, not DI, because Angular route tables are built before the injector exists. Only `admin` consumes it in this task, with an empty registry. The README explains how `pos` would do the same.

### CI config

A new `frontend` job; details in Step 12.

### New dependency

`@angular/service-worker` (`^21.2`, same major and minor as the rest of `@angular/*`), added by `ng add @angular/pwa`. Justified by AC-9/AC-10: it is the conventional Angular service worker, and the spec names it. If `ng add` leaves `@angular/pwa` itself in `package.json`, remove it. It is a schematic-only package with no runtime use.

## Implementation steps

Run every `npm`/`npx` command from `frontend/`. The backend gate is `cd modules/host && composer check`.

- [ ] **Step 1: Red-first proof of D-7 (covers AC-3).** Change no production code yet. Add to `api-client.spec.ts`:
  - a `describe('toApiError with a pre-envelope auth refusal')` table with one `it` per row of the refusal table above (7 shapes). Each builds `new HttpErrorResponse({ status, error: body, headers: new HttpHeaders({'X-Request-Id': 'req-legacy'}) })` with **no** `statusText`, calls `toApiError()`, and asserts:
    - `hasCode(<token>)` is true;
    - `message` is not "The request failed.";
    - `status` is preserved;
    - `fieldDetail`-style `detail` carries `reason`/`status` where present;
    - `requestId` is `'req-legacy'`.
  - one end-to-end case through `api.post('auth/login', ...)` flushed with `{error:'invalid_credentials'}` and status 401, asserting `hasCode('invalid_credentials')`.
  - one control case: an envelope error body still takes the envelope branch and an envelope with an `error` key is not misread. This is the existing behaviour, so it must already pass.

  Run `npm test`. **Expected: the 8 new D-7 cases fail and every existing case passes.** The cases fail on `hasCode(...)` being false, and the message is the generic one. Paste the failure summary (counts, plus one assertion message showing "The request failed.") into the Step 2 commit body. That is the record that the shipped bug reproduces. Do not commit the red state on its own.

- [ ] **Step 2: Fix D-7 and add `postRaw` (covers AC-3, AC-2 groundwork).**
  - Create `legacy-refusal.ts` as specified above.
  - In `toApiError()`, insert `if (isLegacyRefusal(err.error)) return legacyRefusalToApiError(err.status, err.error, err.headers?.get('X-Request-Id') ?? null);` after the envelope branch.
  - Add `postRaw<T>(path, body?)`: it does `http.post<T>(url, body ?? {})` with `catchError(err => throwError(() => toApiError(err)))` and no unwrap.
  - Add specs:
    - `postRaw` returns a raw 2xx body as-is;
    - `postRaw` maps a 401 legacy body through the new branch;
    - `post` still rejects a raw 2xx body (the existing "rejects a body that is not an envelope" case stays).

  `npm test`: all green. **Mutation check, run and record it:** comment out the new `isLegacyRefusal` line, re-run `npm test`, confirm exactly the Step 1 cases fail again, then restore it. Commit.

- [ ] **Step 3: Rewrite `auth.models.ts` to the real contract (covers AC-4, AC-5 types).** Use the shapes in "Data / API changes". Every mock-only member carries a `/** Mock-only (D-5) ... */` or `/** Mock-only (D-6) ... */` comment saying that no real response field supplies it. `npx tsc` errors in consumers are expected here and are fixed in Steps 4 to 6. Do not commit until Step 6 is green, or commit Steps 3 to 6 together.

- [ ] **Step 4: Contract specs red, then rewrite `AuthService` and the interceptor (covers AC-2).**
  - First add a `describe('AuthService against the real T-009 contract')` block to `auth.service.spec.ts`. It uses `provideHttpClient(withInterceptors([authInterceptor]))` and `provideHttpClientTesting()`, with no mock. It asserts, for each call, the exact method, URL, body and fields read:
    - login: `POST /api/v1/auth/login`, body `{email,password}`. The flushed real body sets `token()` to the platform access token, `tenants()` to the 6-key items, and stores `refresh_token`, `default_tenant_id` and `must_change_password`. A body without `user` leaves `user()` null.
    - selectTenant: `POST /api/v1/auth/tenant`, body exactly `{code:'abc'}`, with `Authorization: Bearer <platform access>`. The flushed `{access_token,refresh_token,tenant_id,tenant_code}` sets `token()` to the tenant access token and `tenant()?.id` to `tenant_id`.
    - **Second selection after `clearContext()`:** the request still carries the **platform** bearer, not the stale tenant one. This is the `wrong_token_type` trap.
    - logout: two `POST /api/v1/auth/logout` requests, with bodies `{refresh_token:<tenant refresh>}` then `{refresh_token:<platform refresh>}`. The session is cleared before they are flushed. Flushing `{status:'signed_out'}` changes nothing.
    - a stored session in the old T-012 shape (`{token, user, tenants, ...}` with no `platform`) is discarded on restore.

    Run `npm test`; these fail against the current service, which is the natural red.
  - Then rewrite `auth.service.ts`. The interceptor picks `auth.platformToken()` when `req.url.endsWith('/auth/tenant')`, otherwise `auth.token()`. Its 401 handling is unchanged.

- [ ] **Step 5: Rewrite the mock backend to the real shapes (covers AC-6, AC-8).** All auth answers are raw bodies, built with small `raw(url, status, body)` / `refuse(url, status, body)` helpers. The `envelope` helper is kept only for the unknown-path 404.
  - Tokens: `mock-platform-access` / `mock-platform-refresh` and `mock-tenant-access-<id>` / `mock-tenant-refresh-<id>`.
  - `auth/login`:
    - a missing or non-string field returns 422 `{error:'invalid_request',reason:'email and password are required'}`;
    - a wrong pair returns 401 `{error:'invalid_credentials'}`;
    - otherwise 200 with the `LoginResult` plus the mock-only `user` and `tenants[].branches`.
    - Tenants: TEN001 `abc-pharmacy` (selectable, `is_default` true, two branches), TEN002 `xyz-pharmacy` (selectable, one branch), and TEN003 `old-shop` with `status:'suspended'` and `selectable:false`. TEN003 exercises `tenant_not_servable` in the UI.
  - `auth/logout`: handled **before** any Authorization check, always 200 `{status:'signed_out'}`.
  - `auth/tenant`:
    - no bearer returns 401 `invalid_credentials`;
    - a `mock-tenant-access-*` bearer returns 403 `wrong_token_type`;
    - a missing or non-string `code` returns 422 `invalid_request`;
    - an unknown code returns 403 `no_access`;
    - a non-selectable tenant returns 403 `{error:'tenant_not_servable',status}`;
    - otherwise 200 `SelectTenantResult`.
  - Update the mock-backed flow specs in `auth.service.spec.ts`: `selectTenant('abc-pharmacy')`; refusal cases by `hasCode('no_access')` / `hasCode('tenant_not_servable')`, not just status. Update `auth.guards.spec.ts` `seed()`. `login-page.spec.ts` should pass unchanged; the message text is kept on purpose.
  - Keep the `demo@flexipos.test` literal in the mock. It is the AC-8 sentinel.

- [ ] **Step 6: UI consumers (covers AC-4, AC-5).**
  - `context-page.ts`:
    - `pickTenant(t.code)`;
    - heading `Welcome` with `@if (auth.user(); as u) {, {{ u.name }}}`;
    - branch list `@for (b of auth.tenant()?.branches ?? []; ...)` with `@empty` "No branch can be chosen for this company yet." (D-5);
    - auto-pick when there is exactly one branch is unchanged.
  - Till and admin shell headers: render the user name only inside `@if (auth.user(); as u)`.
  - Run `npm test`, `npx ng build pos`, `npx ng build admin`: all must pass. Commit Steps 3 to 6.

- [ ] **Step 7: README API-contract section and the mock-exclusion re-check (covers AC-1, AC-4, AC-5, AC-7, AC-8).**
  - Replace "Assumed API contract" with the real-contract table and refusal table above. Name `postRaw` and FP-369.
  - State that `envelope.ts` matches T-021 AC-1/AC-3/AC-4 unchanged (AC-1, D-1).
  - Add a "Known gaps" subsection: branch selection is mock-only (D-5; the follow-up row is suggested in D-5); `user` is mock-only (D-6); `must_change_password` is stored but not acted on; `auth/refresh` is not wired.
  - Run `npx ng build pos && npx ng build admin`, then `grep -l "demo@flexipos.test" dist/pos/browser/*.js dist/admin/browser/*.js`. It must print nothing, with exit code 1.
  - **Positive control** (proves the grep can find it): `npx ng build pos --configuration development` and the same grep on `dist/pos/browser/*.js` must list a file. Then rebuild production.
  - Run `composer check` (it sweeps the working tree, including the README and every new frontend file). Commit.

- [ ] **Step 8: PWA for `pos` (covers AC-9, AC-10, AC-12).**
  - Run `npx ng add @angular/pwa --project=pos --skip-confirmation`.
  - Check what it wrote, then:
    - set the manifest `name` to "FlexiPOS Till" and `short_name` to "Till", and keep the generated `theme_color`, `background_color` and icons;
    - confirm `ngsw-config.json` has the default `app` (prefetch) and `assets` (lazy) asset groups and **no `dataGroups`**, so no `/api` response is ever cached (NFR; FP-237 is out of scope);
    - confirm `app.config.ts` has `provideServiceWorker('ngsw-worker.js', { enabled: !isDevMode(), registrationStrategy: 'registerWhenStable:30000' })`;
    - confirm `angular.json` sets `serviceWorker` for `pos` only.
  - If `ng add` cannot reach the registry: `npm install @angular/service-worker@~21.2` and hand-write the same four artefacts in the `ng add` shape. Record which path was taken.
  - Run `npx ng build pos`. Confirm that `dist/pos/browser/` holds `ngsw-worker.js`, `ngsw.json` and `manifest.webmanifest`, and that `ngsw.json`'s `assetGroups[name=app].urls` include `/index.html` (or `/index.csr.html`), `/manifest.webmanifest` and the hashed `main-*.js` / `styles-*.css` (AC-12's substitute check).
  - Run `npm test`: the same count as after Step 7, 0 failures.
  - Run `composer check`. The new PNG icons and `package-lock.json` are swept by `QaVocabularyRenameSweepTest`; if an icon trips it, regenerate it rather than adding an exemption. Commit.

- [ ] **Step 9: Confirm admin is untouched by PWA (covers AC-11).** Run `npx ng build admin`. `dist/admin/browser/` must hold no `ngsw-worker.js`, `ngsw.json` or `manifest.webmanifest`, and `git diff` must show no change under `projects/admin/src/index.html` or `projects/admin/src/app/app.config.ts`. Add a "PWA" README section: what was added for `pos`, that there are no data groups and why, and the D-9 reason `admin` has none. No commit on its own; it goes with Step 11.

- [ ] **Step 10: Plugin route registry (covers AC-13, AC-14).**
  - Write `plugin-routes.spec.ts` first, then implement.
  - The fixture component file sets `(globalThis as any).__fpFixturePluginLoaded = true` at module scope. The spec asserts:
    - (a) after `register()` of `{pluginId:'fixture', path:'hello', loadComponent: () => import('./testing/fixture-plugin-page').then(m => m.FixturePluginPage)}`, `toRoutes()` contains exactly one route with path `plugins/fixture/hello` and `data.pluginId === 'fixture'`;
    - (b) the flag is still undefined after registration and route building, which proves laziness;
    - (c) awaiting the route's `loadComponent()` resolves to the fixture class and sets the flag;
    - (d) `RouterTestingHarness` navigating to `/plugins/fixture/hello` renders the fixture text;
    - (e) `register()` throws on a duplicate mounted path, on both loaders present and on no loader.
  - Make sure no spec or file imports the fixture statically (check by grep). Without that, (b) proves nothing.
  - Export from `public-api.ts`. Add `admin/src/app/plugins.ts` and the spread into the shell `children`. Run `npm test`, then both builds. Commit.

- [ ] **Step 11: README plugin-routes section and close-out (covers AC-15, AC-16, AC-11).**
  - Add a "Plugin routes" section:
    - it is a minimal extension point;
    - the `plugins/<pluginId>/` mount rule;
    - how `pos` would adopt it;
    - T-019's five hook modes are backend-only;
    - T-019 D-10: there is no plugin HTTP surface yet, so there is no remote loading or federation (AC-16 NOT VERIFIABLE).
  - Trim "Not built yet" to what remains: offline data and sync (FP-237), tax and discount (FP-231), translations.
  - Run `composer check`. Commit Steps 9 and 11.

- [ ] **Step 12: CI shape test red, then the `frontend` job (covers AC-17 to AC-21).**
  - First extend `CiWorkflowShapeTest.php` in the file's existing idiom: Pest `it()`, `ciWorkflowJobs()`, `ciJobStepRuns()`, `ciIndexOfRunContaining()`.
  - Add helpers:
    - `ciFrontendJob()` returns `ciWorkflowJobs()['frontend']` and throws if it is absent;
    - `ciJobStepUses(array $job): list<string>`.
  - New cases:
    1. The job exists, is the only job with an `npm ci` run step, has no `if:` key, has no services key (use `ciServicesKey()`, never the quoted word) and has no `composer` in any run step.
    2. `defaults.run.working-directory === 'frontend'` and `defaults.run.shell === 'bash'`.
    3. One `uses` starts with `actions/setup-node@`, and that step's `with.node-version` equals `'24'`.
    4. `npm ci` < `npm test` < `ng build pos` < `ng build admin` < the step containing `demo@flexipos.test`. All five are distinct indices.
    5. The mock-check run step names both `dist/pos/browser` and `dist/admin/browser` and contains `exit 1`.
    6. The quality job's run steps contain no `npm`. The existing "exactly one job runs composer lint" case is untouched and must stay green.
  - Do **not** write `test:fast`, `secrets.`, `docker compose`, a `_PASSWORD`, `_HOST` or `_PORT` env key, or `continue-on-error` into the new job. Existing whole-file cases would fail.
  - Run `composer test -- --filter=CiWorkflowShapeTest` (or the Pest equivalent `vendor/bin/pest tests/Feature/CiWorkflowShapeTest.php`). The new cases fail and the existing ones pass. That is the red.
  - Then append the job to `ci.yml`, after `migrate-existing`, with a comment header naming T-025 and D-11:
    ```yaml
      frontend:
        runs-on: ubuntu-24.04
        timeout-minutes: 30
        defaults:
          run:
            working-directory: frontend
            shell: bash
        steps:
          - uses: actions/checkout@v4
          - uses: actions/setup-node@v4
            with:
              node-version: '24'
              cache: npm
              cache-dependency-path: frontend/package-lock.json
          - name: Install dependencies
            run: npm ci
          - name: Unit tests (pos, admin, shared)
            run: npm test
          - name: Production build (pos)
            run: npx ng build pos
          - name: Production build (admin)
            run: npx ng build admin
          - name: Mock backend absent from both production bundles
            run: |
              for app in pos admin; do
                compgen -G "dist/${app}/browser/*.js" > /dev/null || { echo "no ${app} bundles to check" >&2; exit 1; }
                set +e
                grep -l "demo@flexipos.test" dist/"${app}"/browser/*.js
                rc=$?
                set -e
                case "$rc" in
                  1) echo "${app}: mock backend absent" ;;
                  0) echo "${app}: mock backend found in a production bundle" >&2; exit 1 ;;
                  *) echo "${app}: grep failed (exit ${rc})" >&2; exit 1 ;;
                esac
              done
    ```
    The explicit `rc` handling is deliberate. A bare `! grep` also "passes" on exit code 2, a missing or unreadable file, so it would go green on a job whose build produced nothing.
  - Re-run the test file: all green. Run `git diff main -- .github/workflows/ci.yml` and confirm it is only `+` lines (AC-21). Commit.

- [ ] **Step 13: Full verification (covers AC-7, AC-8, AC-12, AC-20).** Run from clean:
  - `cd frontend && npm ci && npm test` (0 failures; report the per-project counts);
  - `npx ng build pos`, `npx ng build admin`, the Step 7 grep and its positive control, and the Step 8/9 artefact checks;
  - `cd modules/host && composer check`, the gate, with the real result reported.

  Run the D-7 mutation check from Step 2 once more on the final tree. Commit nothing new unless something failed.

## Test strategy

- **Unit (Vitest through `@angular/build:unit-test`, which is the configured runner; the spec's "Karma/Jasmine" is a loose description):**
  - `api-client.spec.ts`: the D-7 table (7 real refusal shapes, plus one end-to-end and one control) and `postRaw`.
  - `auth.service.spec.ts`: the contract block (exact path, body, bearer and fields read against `HttpTestingController`), plus the mock-backed flow block.
  - `auth.guards.spec.ts`: the new session seed.
  - `plugin-routes.spec.ts`: registration, laziness, router resolution and validation.
- **Static:** new `CiWorkflowShapeTest.php` cases, run inside `composer check`.
- **Build-level:** both `ng build` targets; the mock-exclusion grep with a positive control; the PWA artefacts in `dist/pos/browser`, their absence in `dist/admin/browser`, and the `ngsw.json` asset-group inspection.
- **Red-first or mutation proofs:**
  - D-7: Step 1 red, Step 2 green, and the line removed to go red again (Steps 2 and 13);
  - the contract rewrite: Step 4 red against the old service;
  - CI shape: Step 12 red before the job exists;
  - the grep: the Step 7 positive control.

| AC | Proved by |
|---|---|
| AC-1 | Inspection recorded in the README (D-1); the existing envelope specs unchanged and green |
| AC-2 | `auth.service.spec.ts` contract block; both builds |
| AC-3 | The `api-client.spec.ts` D-7 table, with red/green/mutation recorded |
| AC-4 | `auth.models.ts` doc comments; the contract spec "no `user` in body leaves `user()` null"; the guarded templates; the README |
| AC-5 | The README "Known gaps"; the mock-backed `selectBranch`/`hasContext` specs still green |
| AC-6 | The mock-backed `AuthService`, `LoginPage` and guards specs green against the new mock |
| AC-7 | Step 13 `npm ci && npm test`, both builds |
| AC-8 | Step 7/13 grep, empty, plus the positive control |
| AC-9 | `dist/pos/browser` artefact listing and manifest fields |
| AC-10 | `app.config.ts` `provideServiceWorker(..., { enabled: !isDevMode() })`; the build |
| AC-11 | `dist/admin/browser` listing; admin files unchanged; the README |
| AC-12 | `npm test` count unchanged across Step 8; `ngsw.json` asset groups inspected; offline behaviour fenced NOT VERIFIABLE |
| AC-13 | `plugin-routes.ts` surface; no federation config and no fetch (check by grep) |
| AC-14 | `plugin-routes.spec.ts` (a) to (d) |
| AC-15 | README section |
| AC-16 | NOT VERIFIABLE, as stated in the README |
| AC-17, AC-18 | `CiWorkflowShapeTest` cases 1 to 5 |
| AC-19 | Existing trigger case unchanged; new-job case asserts no job-level `if:` |
| AC-20 | The new `CiWorkflowShapeTest` cases running in `composer check`; a real Actions run is NOT VERIFIABLE |
| AC-21 | `git diff main -- .github/workflows/ci.yml` shows only additions; the existing quality, migration and whole-file cases still green |

## Risks & rollback

- **Gate sweep over new files.** `QaVocabularyRenameSweepTest` reads every tracked or untracked file, including binary PNG icons and `package-lock.json`. It has a case-insensitive three-letter token, so a binary could in principle trip it. Mitigation: `composer check` after Steps 7, 8 and 11. If an icon trips it, regenerate the icon rather than adding an exemption.
- **`ng add` needs network and may write more than expected,** for example the `serviceWorker` key in shared build options or a changed `@angular/*` version. Mitigation: review the `git diff` after Step 8, keep the versions at `~21.2`, and use the hand-written fallback if needed.
- **The CI job is only shape-checked.** A runner-only failure is possible, such as the `npm ci` lockfile/npm-version check, a Node 24 vs `packageManager: npm@11.12.1` mismatch, or the Angular CLI analytics prompt (suppressed when `CI=true`). This is fenced NOT VERIFIABLE per AC-20, and the first real run will tell.
- **Job-level `defaults.run` merging with the workflow-level `shell: bash`** is restated at job level so nothing depends on merge semantics.
- **Real-backend UX after reconciliation:** with no branches, `hasContext` never becomes true, so `/till` and the admin shell are unreachable against a real backend. This is the accepted D-5 gap, not a regression, because nothing talks to a real backend yet. It is documented.
- **FP-369 will change the auth bodies.** The legacy branch and `postRaw` are then deleted, and the envelope path takes over with no caller change except `AuthService` switching `postRaw` back to `post`.
- **Rollback:** every step is its own commit on the branch. The PWA commit is self-contained, so reverting it removes the dependency and the artefacts. The CI job is one additive YAML block plus test cases, and reverting that commit restores T-024's file byte for byte.

## Questions for the human

None. Spec corrections are listed below for `ba`; none blocks `dev`, and this plan already accounts for each.

### Spec corrections for `ba` (non-blocking; the plan already follows the corrected reading)

1. **AC-3's list of shapes is incomplete.** `{"error":"employee_unavailable"}` (403, from `SelectTenantController` and `RefreshController` via `AuthRefusals::employeeUnavailable()`) is missing. The `tenant_not_servable` body is precisely `{"error":"tenant_not_servable","status":"<tenant status>"}`; the spec writes `...`. D-2/D-7's "five body shapes" is really seven tokens across the login and tenant paths, plus `activation_unavailable` on the terminal routes, which this frontend does not call. Suggest adding `employee_unavailable` to AC-3.
2. **D-1/D-7 miss the success-path twin of the bug.** `ApiClient.unwrap()` throws "not an API envelope" on every raw 2xx auth body, so AC-2's real shapes cannot be reached through `api.post()` at all. The plan adds `postRaw()`. Suggest that AC-2 or D-7 name it, so QA checks it on purpose.
3. **AC-2 is silent on token kinds.** `/auth/tenant` requires a platform token, and the tenant token it returns is refused there with `wrong_token_type`. Overwriting the session token, as the current code does, would break "Change company" against the real backend. The plan keeps both pairs. Suggest that AC-2 says so.
4. **The "Current behaviour" section says the frontend posts `auth/logout` with no body.** In fact the shipped `AuthService.logout()` makes no HTTP call at all; only the mock answers that path. This is minor and factual.
5. **The Assumptions section says "Karma/Jasmine".** The workspace runs Vitest through `@angular/build:unit-test`. Wording only.
6. **Baseline.** I ran `npm test` on this tip: shared is 4 files, 17 tests, all passing. With `pos` 2 and `admin` 2 that makes the spec's 21, which is consistent. The cold run took over 3 minutes on this machine.
