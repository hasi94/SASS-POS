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

## Admin shell and the catalog area (T-036)

The admin application has a shell (menu, forbidden page, dashboard) and the catalog screens:
categories, brands, units and unit conversions, products with variants and barcodes, and
modifier groups with their modifiers. Customers are a separate follow-up (F-2); `/customers`
exists only as a placeholder that always sends the person to `/forbidden`.

### Where things live

| Path (under `projects/`)                                           | What                                                                                                                                                                               |
| ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `shared/src/lib/contracts/catalog/`                                | Catalog contracts: `catalog-permissions.ts` (the 12 permission keys), `catalog.models.ts`, `catalog.requests.ts`, `catalog.endpoints.ts`                                           |
| `shared/src/lib/auth/permission.service.ts`, `permission.guard.ts` | The signed-in person's permission set, and `requirePermissions` / `requireAnyPermission` (used as `canActivate` and `canMatch`)                                                    |
| `shared/src/lib/mock/catalog/`                                     | The catalog mock interceptor and its in-memory store (development only)                                                                                                            |
| `admin/src/app/shell/`                                             | `AdminShell`, the permission-filtered menu (`admin-nav.ts`), forbidden, dashboard and placeholder pages                                                                            |
| `admin/src/app/crud/`                                              | Reusable blocks: list controller and list view, API error panel, `FormErrors` and server-error mapping, `SubmitState`, confirm dialog, `DeleteFlow`, entity picker, decimal checks |
| `admin/src/app/catalog/`                                           | `catalog.routes.ts` (lazy area per folder) and one folder per area: `categories`, `brands`, `units`, `products`, `modifiers`                                                       |

Every area is a lazy `loadChildren`, guarded by its `view` key in both `canMatch` and
`canActivate`, so a person without the key neither sees the screen nor downloads its chunk.
Every form and nested route also needs the `manage` key (and, for barcodes, the barcode keys).

### Permission keys

All keys are constants in `catalog-permissions.ts` (`CATALOG_PERMISSIONS.<area>.view|manage`,
six areas, 12 keys); code and specs use the constants, never a literal. This command must
print nothing:

```bash
grep -rnE "catalog\.(categories|brands|units|products|barcodes|modifiers)\.(view|manage)" projects --include=*.ts | grep -v catalog-permissions.ts
```

The client check only decides what to show. The server decides what is allowed (a 403 is shown
as the permission message and never signs anyone out).

### How the mock grants keys

In a development build only (`environment.development.ts` installs `mockCatalogInterceptor`
before `mockBackendInterceptor`):

| Sign-in                 | Password   | Keys                                                                                   |
| ----------------------- | ---------- | -------------------------------------------------------------------------------------- |
| `demo@flexipos.test`    | `demo1234` | all 12 catalog keys                                                                    |
| `limited@flexipos.test` | `demo1234` | `products.view` only (Products is the only menu entry; other catalog calls answer 403) |

The keys arrive on the `auth/tenant` reply as `permissions`, are kept in the session and are
cleared on logout and when the company is changed. The mock answers the catalog endpoints in
the real envelope and the T-021 kernel codes, keeps its data in memory (a page reload resets
it) and implements only the rules needed to exercise the screens' display paths.

### Contracts are provisional pending B-1

Every catalog contract, endpoint path and permission key is a **frontend proposal**, written
against T-027's models. They are reconciled in F-4, after B-1 builds the HTTP surface. Each
lives in one file, so reconciling edits one place. Provisional on purpose:

- the paging `meta.pagination` on list replies, and `search` / `page` / `per_page` on lists;
- the read-only display fields (`*_name`, `*_code`) on rows;
- `PUT` for updates (`ApiClient` has no `PATCH`), and the edited field set per resource;
- `permissions` on the `auth/tenant` reply (a mock-only field, see below).

### Known gaps (what the real backend does not do yet)

These are assumptions the screens and the mock make that T-027 does not satisfy. B-1 must add
them or F-4 must change the client.

- **Stale-version check.** The client always sends `version` on a product update (D-12). The
  server's `UpdateProductHandler::FIELDS` has no `version`, and `Fields::only()` refuses
  unknown fields, so a real update carrying it would be refused ("These fields are not accepted
  here: version."). B-1 must either strip it before the command or add it with a real
  `catalog.stale_version` (409) check.
- **Refusals carry no field.** `CatalogRefused` has no `field`, so placing a server message on
  the form field it names works against the mock only. Against the real server every refusal
  would land in the form's summary until B-1 adds the field to the error item.
- **Missing handlers.** T-027 has no `DeleteUnit` (and units have no `deleted_at`), no
  `UpdateModifierGroup`, no `UpdateModifier` and no `GetBarcode`. The screens for them are built
  against the mock.
- **Modifier unlink.** Editing a modifier sends the three linked keys as explicit `null` to
  unlink; an absent key means "unchanged", as everywhere else in T-027.
- **Permissions source (W-6).** `SelectTenantResult.permissions` is a mock-only field. Against
  today's real backend the set is always empty, so every catalog menu entry is hidden. The keys
  are also restored from `sessionStorage` on reload without being checked again, so a revoked
  key stays until the company is chosen again. B-1's permission source must be refreshable on
  restore.
- Layout in real browsers and screen-reader behaviour are not verified (jsdom only); the specs
  assert attributes (`role`, `aria-*`, focus).

### Keeping the mock out of production builds

The catalog mock stamps `fp-mock-catalog-a7f3` into every `request_id`, and the auth mock holds
`demo@flexipos.test`, `limited@flexipos.test` and `mock-tenant-access`. After changing
`environment*.ts`, build both apps and check that none of them reaches a production bundle
(the command must print nothing):

```bash
npx ng build admin && npx ng build pos
grep -lE "demo@flexipos.test|fp-mock-catalog-a7f3|limited@flexipos.test|mock-tenant-access|flexipos-sales-mock" dist/admin/browser/*.js dist/pos/browser/*.js
```

Delete `frontend/dist` afterwards. The same command on a development build
(`npx ng build admin --configuration development`) must find the strings; that is the check that
the grep can fail.

## Not built yet

Customer screens (F-2), the real catalog and permission endpoints (B-1) and the reconciliation
of the contracts (F-4), offline database and sync (FP-237), the shared tax and discount engine
(FP-231), translations.
