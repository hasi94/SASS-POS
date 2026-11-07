# FlexiPOS frontend

Angular 21 workspace: two applications over one shared library (docs/architecture.md §15).

| Project | What it is | Dev command |
|---|---|---|
| `projects/pos` | Till application | `npm start -- pos` |
| `projects/admin` | Admin and ERP application | `npm start -- admin` |
| `projects/shared` | `@flexipos/shared`: API client, auth, tenant and branch context, sign-in screens | tests only |

```bash
npm ci
npm test                 # all three projects, once
npx ng build pos         # production build
npx ng build admin
```

`@flexipos/shared` is mapped to the library source in `tsconfig.json`, so no separate library
build is needed while developing.

## Working before the backend exists

A development build runs the **mock backend** (`projects/shared/src/lib/mock/mock-backend.ts`).
It answers the auth endpoints below in their real, non-enveloped shapes. Demo sign-in:
`demo@flexipos.test` / `demo1234`; three companies — `abc-pharmacy` (two branches, default),
`xyz-pharmacy` (one branch), `old-shop` (suspended, not selectable, to exercise
`tenant_not_servable`).

It is wired through `src/environments/`: only `environment.development.ts` imports it, so a
production build does not contain it. Check this after changing either file:

```bash
npx ng build pos && grep -l "demo@flexipos.test" dist/pos/browser/*.js   # must print nothing
```

When a real endpoint exists, delete its branch from the mock and nothing else changes.

## API contract (reconciled against T-009/T-021, T-025)

`envelope.ts`'s `ApiEnvelope<T>`/`isEnvelope()` match T-021's real, shipped envelope exactly
(same five keys, same nesting) — no change was needed there (D-1). But T-021 D-1 deliberately
exempts the existing auth endpoints from that envelope (until FP-369): every real auth
request/response body below is a **flat, non-enveloped** JSON body, on both the success and the
failure path. `ApiClient.postRaw()` is the client for these endpoints — it skips the envelope
`unwrap()` step but still maps a failure through `toApiError()`. The ordinary `api.post()` and
the envelope path are unchanged and remain correct for every endpoint that already sends a real
envelope.

| Call | Middleware | Body | 200 body |
|---|---|---|---|
| `POST /api/v1/auth/login` | none | `{email, password}` | `{access_token, refresh_token, tenants:[{id,code,name,status,selectable,is_default}], default_tenant_id, must_change_password}` |
| `POST /api/v1/auth/tenant` | `RequirePlatformToken` | `{code}` | `{access_token, refresh_token, tenant_id, tenant_code}` |
| `POST /api/v1/auth/logout` | none | `{refresh_token}` | `{status:"signed_out"}`, always |

`auth/tenant` requires the **platform** token (from `login()`), not the tenant token a previous
`selectTenant()` returned — presenting a tenant token there is refused with `wrong_token_type`.
`AuthService` keeps both token pairs side by side for this reason (D-7a): `session.platform` and
`session.tenantTokens`, never collapsed into one slot.

### Real auth refusal bodies

None of these is an envelope; `toApiError()` parses them via a dedicated legacy-refusal branch
(`projects/shared/src/lib/api/legacy-refusal.ts`), ahead of the generic "The request failed."
fallback:

| Status | Body | Returned by |
|---|---|---|
| 401 | `{"error":"invalid_credentials"}` | Login; also `auth/tenant`'s middleware when the bearer is missing, expired or malformed |
| 403 | `{"error":"wrong_token_type"}` | `auth/tenant`'s middleware, on a tenant token |
| 403 | `{"error":"no_access"}` | `auth/tenant` |
| 403 | `{"error":"tenant_not_servable","status":"<tenant status>"}` | `auth/tenant` |
| 403 | `{"error":"employee_unavailable"}` | `auth/tenant`; token refresh |
| 422 | `{"error":"invalid_request","reason":"<text>"}` | Login; `auth/tenant` |
| 503 | `{"error":"signing_unavailable"}` | Login; `auth/tenant`; token refresh |

The client-side message for each token never echoes anything from the request — only the
server-supplied `reason`/`status`.

### Known gaps

- **Branch selection is mock-only (D-5).** No real endpoint lets a signed-in person choose a
  branch; T-014 ties branch context to terminal activation, a device-level process, not a
  per-employee-session choice. `AuthService.selectBranch()`/`hasContext`/`branch` keep working
  against the mock's extra `tenants[].branches` field, but with the real backend `hasContext`
  never becomes true — a logged, accepted gap, not a regression, since nothing talks to a real
  backend yet.
- **`AuthUser`/`session.user` is mock-only (D-6).** `LoginResult` carries no user object at all
  (the subject lives in the access token's `sub` claim); nothing in production code reads
  `session.user.name`/`.email` as if the real backend supplied it — every template guards on
  `@if (auth.user(); as u)` first.
- `mustChangePassword` is stored from `LoginResult.must_change_password` but nothing acts on it
  yet; no UI asks for it.
- `auth/refresh` is not wired into `AuthService` in this task.

## Plugin routes

A minimal, documented extension point for a future plugin's frontend routes (D-10) —
`PluginRouteEntry`/`PluginRouteRegistry` in `projects/shared/src/lib/plugins/plugin-routes.ts`.
T-019 (DONE) ships a backend hook gateway with five modes (add/before/after/filter/override) but
**no HTTP surface and no plugin registry of any kind** (`docs/decisions/T-019-plugin-framework.md`
D-10). This is why the frontend mechanism here is a plain TypeScript convention, not module
federation or a remote-bundle fetch: there is nothing real on the other end to call yet.

A registered entry contributes one lazy Angular route, mounted at `plugins/<pluginId>/<path>` —
that prefix is a convention decision so a plugin can never shadow a host route such as
`/catalog`. `admin` consumes an (empty) registry in this task (`projects/admin/src/app/plugins.ts`);
`pos` would adopt the same pattern by spreading `somePlugins.toRoutes()` into its own route table.

**NOT VERIFIABLE in this task**: loading a real plugin's frontend bundle from a real registry or a
real remote origin. No such registry exists (T-019 D-10) and none is built here — only the
registration convention, proved against one fixture entry (`plugin-routes.spec.ts`).

## PWA

`pos` (the till) is installable and offline-tolerant for its shell and static assets, added via
`ng add @angular/pwa --project=pos` (D-9): `ngsw-worker.js`, `manifest.webmanifest`,
`ngsw-config.json` ship with every production build of `pos`. No `dataGroups` are configured, so
no `/api` response is ever cached — offline *data* and sync (FP-237) stay out of scope; this is
shell-and-assets caching only. The service worker is registered as
`provideServiceWorker('ngsw-worker.js', { enabled: !isDevMode(), registrationStrategy: 'registerWhenStable:30000' })`,
so it is never active in a `ng serve`/development build.

`admin` receives no PWA treatment in this task (D-9): it is a back-office app, used at a desk on
a stable connection, with no offline-data story yet. Revisit if/when one is identified.

## Not built yet

Offline database and sync (FP-237), the shared tax and discount engine (FP-231), translations.
