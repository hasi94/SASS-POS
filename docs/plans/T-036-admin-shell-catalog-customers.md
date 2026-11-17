# Plan: T-036 Admin application shell, navigation and the catalog screens

Spec: `docs/specs/T-036-admin-shell-catalog-customers.md` (AC-1 to AC-63). Decisions:
`docs/decisions/T-036-admin-shell-catalog-customers.md` (D-1 to D-18). The decisions are followed as
written. Where the code disagrees with a premise, see "Spec vs code" below and "Questions for the human".

Gate for this task: `cd frontend && npm test`, `npx ng build admin`, `npx ng build pos`, and the
mock-exclusion grep for both sentinels on both builds. The PHP gate does not apply. Nothing outside
`frontend/` changes. There are no new dependencies.

Baseline: `frontend/node_modules` is not installed in this worktree or in the main checkout, so I could
not run the baseline `npm test`. The lead installs it with `npm ci` before chunk 1 starts.

## Approach summary

1. **Permissions.** The session gains an optional `permissions: string[]`. It is filled from a
   mock-only extra field `permissions` on the `auth/tenant` reply (the same pattern as `branches`,
   T-025 D-5). It is emptied on login, on `clearContext()` and on `logout()`. A root
   `PermissionService` in `@flexipos/shared` answers `has` and `hasAny`. A missing or absent set means
   deny (D-6). A guard factory `requirePermissions(keys)` redirects to `/forbidden`. The keys are 12
   literal strings in one constants file (D-4).
2. **API.** `ApiClient` gains one additive method, `getWithMeta<T>()`, which returns `{data, meta}`.
   `meta.pagination` is `{page, per_page, total}` (D-7). The existing methods do not change.
3. **Contracts.** Typed catalog contracts go in `@flexipos/shared` under `lib/contracts/catalog/`
   (D-8): models, request shapes, endpoint paths and the permission constants. Decimals are strings,
   the house convention from `docs/database.md`.
4. **Mock.** A separate `mockCatalogInterceptor` sits beside the auth mock in
   `lib/mock/catalog/`. It is installed only by admin's `environment.development.ts`, before
   `mockBackendInterceptor`. It keeps lazy in-memory state, uses the real envelope and the T-021
   kernel codes, and stamps a distinctive sentinel (`fp-mock-catalog-a7f3`) into every `request_id`
   (D-14). The auth mock gains a second demo user, `limited@flexipos.test`, who holds only
   `catalog.products.view` (AC-26).
5. **Admin app.**
   - The CRUD building blocks live in `projects/admin/src/app/crud/` (list controller, list view,
     error panel, form-error mapping, submit state, confirm dialog, delete flow, entity picker). They
     are admin-only because only admin needs them, and keeping them there avoids conflicts with T-037.
   - The shell builds its menu from a pure nav model filtered by permissions.
   - `catalog` becomes a lazy `loadChildren`. Each area has its own `*.routes.ts` file, so two
     developers never edit the same routes file.
6. **Validation.** Client-side validation mirrors only self-contained rules (D-17). Every other rule
   is shown from the server's refusal.

## Alternatives rejected

- **A separate permission endpoint**, for example `GET access/permissions`. It invents a URL, forces
  async guards and adds a round trip on every restore. A mock-only extra field is smaller, and F-4
  changes one place if B-1 chooses an endpoint.
- **Folding the catalog mock into `mockBackendInterceptor`.** That makes one big shared file, which
  T-037 is also likely to touch, and it hides the catalog sentinel among the auth one.
- **CRUD building blocks in `@flexipos/shared`.** `pos` has no use for admin tables and forms. Putting
  them there would widen the shared diff that T-037 merges against. F-2 (customers) is in admin too.
  Promote them later if `pos` ever needs them.
- **A secondary entry point** (`@flexipos/shared/catalog`). It needs `tsconfig.json` path and
  `ng-package` changes for no gain over one appended export block.
- **`PATCH`.** `ApiClient` has no `patch`. Updates use `PUT` with the edited field set. This is
  provisional until B-1.
- **A spec that reads source files to find stray permission literals (AC-1).** The Angular unit-test
  builder bundles specs for a browser-like target, so `node:fs` is not reliably available. AC-1 is
  proved by review plus a documented grep. See Risks.

## Spec vs code (found while exploring; none blocks the plan)

1. **Missing T-027 handlers.** `packages/catalog/src/Features` has no `DeleteUnit` (and `units` has
   no `deleted_at`), no `UpdateModifierGroup`, no `UpdateModifier` and no `UpdateUnitConversion`. The
   spec asks for unit delete (AC-34) and for editing groups and modifiers (AC-52, AC-53). The plan
   builds them against the mock as specified. B-1 must add them. Question 1 asks `ba` to log this.
2. **Products already have tax group columns.** `tax_group_id` and `purchase_tax_group_id` now exist
   on products (T-029 migrations `..._002906` and `..._002912`, `UpdateProductHandler::FIELDS`), with
   a tenant-default fallback on create. D-9's reason ("columns do not exist") is stale for these two
   columns. The plan still omits them, as AC-23 requires: create falls back to the tenant default, and
   update never sends them. See Question 2.
3. **The server has no stale-version check.** `UpdateProductHandler` increments `version` but never
   compares it. AC-46's stale refusal therefore exists only in the mock (`catalog.stale_version`,
   409). See Question 3.
4. **Some fields cannot change on update.**
   - Unit `dimension` is not in `UpdateUnitHandler::FIELDS`.
   - The barcode value is not in `UpdateProductBarcodeHandler::FIELDS`.

   The plan shows both read-only on the edit forms and never sends them. This follows the server and
   conflicts with no AC.
5. **AC-57 names an "existing spec" for the dashboard.** None exists. Chunk 1 adds a minimal one.

## Files to change / create

All paths are under `frontend/`.

### Shared library: files modified (the complete list of shared files this task touches)

| File | Change |
|---|---|
| `projects/shared/src/public-api.ts` | Append one export block, marked `// T-036 catalog`. No existing line is edited. |
| `projects/shared/src/lib/api/api-client.ts` | Add `getWithMeta<T>(path, params)`. Factor envelope validation into a private `checkEnvelope()`. `unwrap()` keeps identical behaviour. |
| `projects/shared/src/lib/auth/auth.models.ts` | `Session.permissions?: string[]`. `SelectTenantResult.permissions?: string[]`, with a mock-only doc comment like `branches`. |
| `projects/shared/src/lib/auth/auth.service.ts` | Three one-line additions:<br>- `login()` commits `permissions: []`;<br>- `selectTenant()` commits `r.permissions ?? []`;<br>- `clearContext()` commits `permissions: []`.<br>`logout()` already commits `null`. |
| `projects/shared/src/lib/mock/mock-backend.ts` | Add `MOCK_LIMITED_USER` (`limited@flexipos.test`, same demo password) and a platform token per user. `auth/tenant` accepts either platform token, and the limited user's tenant token carries a `-limited` suffix. The success body gains `permissions`. Export `mockPermissionsForBearer(header)`. The demo user's existing tokens and every existing refusal stay byte-identical. |
| `README.md` (frontend) | AC-61 sections (chunk 4). |

### Shared library: new files

| File | Contents |
|---|---|
| `lib/auth/permission.service.ts` | `PermissionService`, providedIn root:<br>- `keys` is a computed `ReadonlySet<string>` built from `auth.session()?.permissions`, keeping strings only and treating a non-array as empty;<br>- `has(key)`;<br>- `hasAny(keys)`, which is false for an empty list;<br>- `hasAll(keys)`, which is false for an empty list. |
| `lib/auth/permission.guard.ts` | `requirePermissions(keys: readonly string[], redirect = '/forbidden'): CanActivateFn` uses `hasAll`. `requireAnyPermission(keys, redirect)` uses `hasAny`. |
| `lib/auth/permission.service.spec.ts` | AC-2, AC-3, AC-10. |
| `lib/auth/permission.guard.spec.ts` | Guard true or UrlTree cases. |
| `lib/api/api-list.ts` | `ApiPagination {page; per_page; total}`, `ApiMeta {request_id; pagination?}`, `ApiResult<T> {data; meta}`, `ListParams {page; per_page; search?; [filter]}` and `DEFAULT_PER_PAGE = 25`. |
| `lib/api/api-client-meta.spec.ts` | AC-13. This is a new file so that `api-client.spec.ts` stays untouched. |
| `lib/contracts/catalog/catalog-permissions.ts` | `CATALOG_PERMISSIONS`: `{categories: {view, manage}, ...}` for six resources, written as 12 literal strings. Also `ALL_CATALOG_PERMISSIONS` and `CatalogPermission`. |
| `lib/contracts/catalog/catalog.models.ts` | Read types and enums (see Data / API). |
| `lib/contracts/catalog/catalog.requests.ts` | Create and update request shapes. |
| `lib/contracts/catalog/catalog.endpoints.ts` | `CATALOG_ENDPOINTS`, the path builders used by both the admin API and the mock. |
| `lib/contracts/catalog/catalog-permissions.spec.ts` | AC-1 shape. |
| `lib/contracts/catalog/catalog.contracts.spec.ts` | AC-22 and AC-23. |
| `lib/mock/catalog/mock-catalog.store.ts` | `MOCK_CATALOG_SENTINEL`, lazy state (`let state` plus `catalogState()`), seed builder functions, `resetMockCatalog()` as a test hook, and the envelope helpers `okEnvelope`, `refuseEnvelope` and `pageOf`. **No top-level function calls** (see Risks). |
| `lib/mock/catalog/mock-catalog.interceptor.ts` | `mockCatalogInterceptor`:<br>- matches `/api/v1/catalog/`;<br>- returns 401 with no bearer;<br>- maps the path area and verb to a required key and returns 403 `kernel.forbidden` when that key is missing (AC-26);<br>- dispatches to the area handlers;<br>- passes anything else on with `next(req)`. |
| `lib/mock/catalog/mock-catalog.categories-brands.ts` | Category and brand handlers and rules. |
| `lib/mock/catalog/mock-catalog.units.ts` | Unit and unit-conversion handlers and rules. |
| `lib/mock/catalog/mock-catalog.products.ts` | Product, variant and barcode handlers and rules. |
| `lib/mock/catalog/mock-catalog.modifiers.ts` | Modifier group and modifier handlers and rules. |
| `lib/mock/catalog/mock-catalog.spec.ts` | AC-24, AC-25, AC-26. |

### Admin app: modified files

| File | Change |
|---|---|
| `projects/admin/src/app/app.routes.ts` | The `catalog` child becomes `loadChildren: () => import('./catalog/catalog.routes')`. Add a `forbidden` child. `customers` keeps `PlaceholderPage` with `arrivesWith: 'the customer screens follow-up (F-2)'` and gains `canActivate: [requireAnyPermission([])]`, which always goes to `/forbidden` (AC-56). |
| `projects/admin/src/app/shell/admin-shell.ts` | The menu comes from `visibleNav(permissions)`. Groups render as `role="group"` with `aria-labelledby`, and each child is a `routerLink` with `ariaCurrentWhenActive="page"`. The top bar renders branch and user only inside `@if` (AC-12). The Customers entry is removed. |
| `projects/admin/src/environments/environment.development.ts` | `devInterceptors = [mockCatalogInterceptor, mockBackendInterceptor]`. `environment.ts` is **not** touched. |
| `projects/admin/src/styles.scss` | `@use 'crud';` |

### Admin app: new files

**Shell** (`projects/admin/src/app/shell/`)

| File | Contents |
|---|---|
| `admin-nav.ts` | `NavLink {label, path, requires?}`, `NavGroup {label, children}`, `ADMIN_NAV` and the pure `visibleNav(has: (k) => boolean)`, which drops children without their key and groups with no visible child. Catalog children: Categories, Brands, Units, Products, Modifiers. |
| `admin-nav.spec.ts` | AC-4, AC-5. |
| `forbidden-page.ts` | Heading "Not permitted", the sentence "You do not have permission to open this page.", and a `routerLink` to `/dashboard`. |
| `admin-shell.spec.ts` | AC-4, AC-5, AC-10 (UI), AC-11, AC-12, AC-56 (menu). |
| `dashboard-page.spec.ts` | AC-57. |

**App-level spec** (`projects/admin/src/app/`)

| File | Contents |
|---|---|
| `app.routes.spec.ts` | AC-6, AC-9, AC-56 (routing), using `RouterTestingHarness` and `HttpTestingController`. |

**CRUD building blocks** (`projects/admin/src/app/crud/`)

| File | Contents |
|---|---|
| `describe-api-error.ts` | 403 gives "You do not have permission to see or change this."; 0 and others give `err.message`. Also returns the request id. |
| `list-controller.ts` | `createListController<T>(fetch: (p: ListParams) => Observable<ApiResult<T[]>>)`. Signals: `rows`, `total`, `page`, `search`, `loading`, `error`. Methods:<br>- `load()` and `retry()`, which re-sends the last params;<br>- `setSearch(text)`, which trims, ignores an unchanged value and resets to page 1;<br>- `goTo(page)`. |
| `list-view.ts` | `<adm-list-view>`, presentational:<br>- `<table>` with `<caption>` and `th scope="col"`;<br>- an actions column through a `contentChild` `ng-template` marked with the `admRowActions` directive;<br>- a search `<input type="search">` with a label, debounced 300 ms in-component (`debounceTime` + `distinctUntilChanged`);<br>- pager with Previous/Next and "Page x of y", buttons disabled at the ends;<br>- empty state "Nothing here yet";<br>- loading `role="status"`;<br>- an embedded error panel. |
| `api-error-panel.ts` | `role="alert"`, the message (as text, never `innerHTML`), "Request id: ..." when present, and a Retry button that emits `retry`. |
| `form-errors.ts` | `mapServerErrors(err: ApiError, knownFields: readonly string[]) → {fields: Record<string,string>; summary: string[]}`. An item with a known field goes to `fields`; anything else goes to `summary`, and `err.message` goes to `summary` when it has no items. Also `clientErrorFor(control, label)`, which gives "<Label> is required." and the like. |
| `field-error.ts` | `<adm-field-error [id] [message]>` renders `<p class="fp-error" [id]>`. The control sets `[attr.aria-describedby]` to the same id when a message exists. |
| `form-error-summary.ts` | `role="alert"` list at the top of the form. |
| `submit-state.ts` | `SubmitState`, with `busy` as a signal. `run(obs$, {next, error})` returns immediately when busy (D-15, AC-18). |
| `confirm-dialog.ts` | `<adm-confirm-dialog>`: a `div` with `role="dialog"`, `aria-modal="true"` and `aria-labelledby`, plus text "Delete <name>?", Cancel and Delete buttons. On open it records `document.activeElement` and focuses Cancel. Tab cycles inside. Escape cancels. On close it returns focus to the recorded trigger. |
| `delete-flow.ts` | `DeleteFlow<T>`:<br>- `ask(row)` and `pending`;<br>- `confirm()` sends the delete once (busy-guarded); on success it calls `reload()`;<br>- on refusal it sets `error` (`describeApiError`) and keeps the row;<br>- `cancel()` sends nothing. |
| `entity-picker.ts` | `<adm-entity-picker>`, a `ControlValueAccessor` whose value is `string \| null`:<br>- a labelled search input, debounced, calling `search(term)` with `per_page` 25, plus a labelled `<select>` of results;<br>- an optional `filter(row)` predicate for the dimension convenience (AC-36, AC-43);<br>- `initialLabel` for the edit form's current value;<br>- an optional "none" option with configurable text ("Same as stock unit", "No parent"). |
| `crud.scss` | Table, pager, dialog, badge and form-grid styles. Shared, so no component style nears the 4 kB budget. Lives at `projects/admin/src/crud.scss`. |
| `testing/crud-testing.ts` | Spec-only helpers, never imported by production code:<br>- `seedSession(permissions, opts)` writes `flexipos.admin.session`, or the default key in tests;<br>- `envelope(data, meta)`;<br>- `refusal(status, code, field?, detail?)`. |
| One `*.spec.ts` per building block | See Test strategy. |

**Catalog shared parts** (`projects/admin/src/app/catalog/`, owned by chunk 1)

| File | Contents |
|---|---|
| `catalog.routes.ts` | `''` uses `catalogLandingGuard`, which redirects to the first area whose view key is held, otherwise `/forbidden`. `categories`, `brands`, `units`, `products` and `modifiers` each use `loadChildren` of their area routes file with `canActivate: [requirePermissions([X.view])]`. |
| `catalog.routes.spec.ts` | Lazy loading and the view guards per area. |
| `catalog-api.ts` | `CatalogApi`, providedIn root. A small `resource(pathFn)` helper gives `list(params)`, which calls `getWithMeta` and returns `ApiResult<T[]>`, plus `get(id)`, `create(body)`, `update(id, body)` and `remove(id)` for each entity. Nested resources (barcodes, modifiers) take the parent id. Paths come from `CATALOG_ENDPOINTS`. |
| `catalog-api.spec.ts` | Paths, verbs, query params and bodies against `HttpTestingController`. |
| `{categories,brands,units,products,modifiers}/<area>.routes.ts` | **Stubs** in chunk 1: `[{ path: '', loadComponent: PlaceholderPage }]`. Each is rewritten by its area's chunk. |

**Catalog screens** (`projects/admin/src/app/catalog/<area>/`; chunk 2 owns `categories/`, `brands/`, `units/` and `modifiers/`, chunk 3 owns `products/`)

| Area | Files |
|---|---|
| `categories/` | `categories.routes.ts`, `categories-page.ts`, `category-form-page.ts`, `categories.spec.ts` |
| `brands/` | `brands.routes.ts`, `brands-page.ts`, `brand-form-page.ts`, `brands.spec.ts` |
| `units/` | `units.routes.ts`, `units-page.ts` (two sections: Units and Conversions), `unit-form-page.ts`, `unit-conversions-section.ts`, `unit-conversion-form-page.ts`, `units.spec.ts`, `unit-conversions.spec.ts` |
| `modifiers/` | `modifiers.routes.ts`, `modifier-groups-page.ts`, `modifier-group-form-page.ts`, `modifier-group-detail-page.ts` (lists the group's modifiers), `modifier-form-page.ts`, `modifiers.spec.ts` |
| `products/` | `products.routes.ts`, `products-page.ts`, `product-form-page.ts`, `product-detail-page.ts` (variants list and barcodes section), `product-barcodes-section.ts`, `barcode-form-page.ts`, `products.spec.ts`, `product-form.spec.ts`, `barcodes.spec.ts` |

### Not touched

`angular.json`, `package.json`, `package-lock.json`, `tsconfig*.json`, everything in `projects/pos/`, the
existing `*.spec.ts` files, `environment.ts` (both apps) and `.github/workflows/ci.yml`.

## Data / API changes

No backend, schema or migration change. These are frontend contracts only, all **provisional pending B-1**,
with reconciliation in F-4.

### Session

`Session.permissions?: string[]` is optional, so stored sessions and the existing `auth.guards.spec.ts`
seed stay valid. The mock-only field `SelectTenantResult.permissions?: string[]` comes from `auth/tenant`.
The real backend sends nothing, so the set is empty and the client denies (D-5, D-6).

### List convention (D-7)

The query is `?page=1&per_page=25&search=...`, plus per-resource filters:

- `products`: `type`, `parent_id`;
- `unit-conversions`: `product_id`.

The reply is an envelope with `data: T[]` and `meta: {request_id, pagination: {page, per_page, total}}`.
Lists exclude soft-deleted rows (D-11). Get-by-id returns soft-deleted rows with `deleted_at` set.

### Endpoints (`CATALOG_ENDPOINTS`, prefix `catalog/`)

| Resource | List / create | Get / update / delete |
|---|---|---|
| categories | `catalog/categories` | `catalog/categories/:id` |
| brands | `catalog/brands` | `catalog/brands/:id` |
| units | `catalog/units` | `catalog/units/:id` |
| unit conversions | `catalog/unit-conversions` | `catalog/unit-conversions/:id` (get, delete) |
| products | `catalog/products` | `catalog/products/:id` |
| barcodes | `catalog/products/:productId/barcodes` | `.../barcodes/:id` (update, delete) |
| modifier groups | `catalog/modifier-groups` | `catalog/modifier-groups/:id` |
| modifiers | `catalog/modifier-groups/:groupId/modifiers` | `.../modifiers/:id` |

The verbs are GET, POST (which answers 201), PUT and DELETE. A DELETE answers 200 with `data: null`.

**Key per call** (mock enforcement and client gating): GET needs `view` and the other verbs need `manage`.

| Paths | Resource key |
|---|---|
| `unit-conversions` | `units` |
| `products/:id/barcodes` | `barcodes` |
| `modifier-groups` and their modifiers | `modifiers` |
| everything else | the resource of the same name |

### Types (`catalog.models.ts`)

`CatalogId = string` and `CatalogDecimal = string`, a decimal string at full scale. The names carry a
catalog prefix to avoid an export clash with T-037's decimal type.

**Enumerations**, each a const array plus a union type:

| Name | Values |
|---|---|
| `UNIT_DIMENSIONS` | `count`, `mass`, `volume`, `length`, `area` |
| `PRODUCT_TYPES` | `simple`, `variant_parent`, `variant`, `service`, `bundle`, `composite` |
| `PRICE_MODES` | `per_unit`, `per_weight`, `open_price` |
| `TRACKING_MODES` | `none`, `quantity`, `batch`, `serial` |

**Read types**

| Type | Fields |
|---|---|
| `Category` | `id`, `code`, `name`, `parent_id: string\|null`, `parent_name: string\|null`, `path`, `depth: number`, `is_active`, `deleted_at: string\|null` |
| `Brand` | `id`, `code`, `name`, `is_active`, `deleted_at` |
| `Unit` | `id`, `code`, `name`, `dimension`, `precision: number`, `base_unit_id\|null`, `base_unit_code\|null`, `factor: CatalogDecimal\|null`, `is_active`. There is no `deleted_at`, because the table has none. |
| `UnitConversion` | `id`, `product_id\|null`, `product_name\|null`, `from_unit_id`, `from_unit_code`, `to_unit_id`, `to_unit_code`, `factor` |
| `Product` | `id`, `type`, `parent_id\|null`, `sku`, `name`, `category_id`, `category_name`, `brand_id\|null`, `brand_name\|null`, `stock_unit_id`, `stock_unit_code`, `purchase_unit_id\|null`, `sales_unit_id\|null`, `is_weighable`, `price_mode`, `plu_code\|null`, `track_stock`, `tracking_mode`, `is_active`, `variant_options: unknown\|null`, `variant_values: unknown\|null`, `version: number`, `deleted_at` |
| `ProductBarcode` | `id`, `product_id`, `barcode`, `unit_id\|null`, `unit_code\|null`, `quantity: CatalogDecimal`, `is_primary` |
| `ModifierGroup` | `id`, `name`, `min_select: number`, `max_select: number\|null`, `deleted_at` |
| `Modifier` | `id`, `group_id`, `name`, `price_delta`, `cost_delta`, `linked_product_id\|null`, `linked_product_name\|null`, `linked_quantity\|null`, `linked_unit_id\|null`, `linked_unit_code\|null` |

The `*_name` and `*_code` fields are read-only display fields this plan proposes for B-1's read models.

**Omitted on purpose (AC-23 and D-9):** `tax_group_id`, `purchase_tax_group_id`, `income_account_id`,
`cogs_account_id`, `inventory_account_id`, `kitchen_station_id` and `custom_fields`.

**Also omitted**, because no AC needs them: prices, `short_name`, `description`, images, sort orders,
`is_required` on groups and `kitchen_note`. See Question 4.

### Requests (`catalog.requests.ts`)

| Request | Fields |
|---|---|
| `CategoryWrite` | `{code, name, parent_id: string\|null}`. `path` and `depth` never appear. |
| `BrandWrite` | `{code, name}` |
| `UnitCreate` | `{code, name, dimension, precision, base_unit_id: string\|null, factor: string\|null}` |
| `UnitUpdate` | `UnitCreate` without `dimension`, which cannot change |
| `UnitConversionCreate` | `{from_unit_id, to_unit_id, factor, product_id: string\|null}` |
| `ProductCreate` | `{type, parent_id?, sku, name, category_id, brand_id\|null, stock_unit_id, purchase_unit_id\|null, sales_unit_id\|null, is_weighable, price_mode, plu_code?, track_stock, tracking_mode, variant_options?, variant_values?}` |
| `ProductUpdate` | `Omit<ProductCreate, 'stock_unit_id'\|'plu_code'> & {plu_code?: string\|null; version: number}`. `stock_unit_id` is excluded by the type itself (AC-42). |
| `BarcodeCreate` | `{barcode, unit_id\|null, quantity, is_primary}` |
| `BarcodeUpdate` | `{unit_id\|null, quantity, is_primary}` |
| `ModifierGroupWrite` | `{name, min_select, max_select: number\|null}` |
| `ModifierWrite` | `{name, price_delta, cost_delta, linked_product_id?, linked_quantity?, linked_unit_id?}`. The three linked fields are all present or all absent. |

How optional values are sent:

- **`plu_code` on update (AC-44).** On create the key is omitted when blank. On update it is omitted
  when unchanged, and sent as `null` only when the loaded product had one and the field is now blank,
  so a PLU can be removed. An empty string is never sent.
- **`parent_id` on products (AC-40).** It is sent only when `type === 'variant'`.

### Mock refusals

Status and code mapping:

| Status | Code | When |
|---|---|---|
| 422 | `kernel.validation_failed` | A rule on one field; the error item carries `field` and `detail` |
| 422 | `catalog.duplicate` | A unique value is already used; carries `field`, and `detail` names the existing row or owner |
| 422 | `catalog.cycle`, `catalog.too_deep`, `catalog.dimension_mismatch`, `catalog.immutable` | Carries `field` |
| 409 | `catalog.in_use` | A delete is blocked; carries no field, and the message names the blocker |
| 409 | `catalog.stale_version` | A product update with an old `version`; carries no field |
| 403 | `kernel.forbidden` | The caller lacks the key |
| 404 | `kernel.not_found` | No such row |
| 401 | `kernel.unauthenticated` | No bearer |

Rules the mock implements:

- **AC-25 in full.**
- **Categories:** non-existent parent, cycle, and depth over 17. Delete is blocked by an active child
  or an active product.
- **Brands:** delete is blocked by an active product.
- **Units:** `base_unit_id` and `factor` must come together, and `factor` must be greater than 0.
  `base_unit_id` must share the unit's dimension. Delete is blocked when the unit is referenced.
- **Unit conversions:** from and to must differ. A cross-dimension pair is refused. A duplicate pair
  is refused and the refusal names the existing row. On a product-scoped row the to-unit must be the
  product's stock unit.
- **Products:**
  - SKU must be non-blank and at most 50 characters;
  - required fields;
  - a variant's parent must be a `variant_parent`;
  - `stock_unit_id` cannot change, and the refusal is `catalog.immutable`;
  - a duplicate PLU is refused;
  - purchase and sales units must share the stock unit's dimension;
  - `version` must match;
  - delete is blocked by variants or by a modifier link.
- **Barcodes:** a duplicate barcode is refused and the refusal names the owning product. Setting
  `is_primary` clears the old primary. The unit must share the stock unit's dimension.
- **Modifier groups:** `min_select > max_select` is refused on `max_select`. Delete is blocked when
  the group is attached to a live product; the seed carries an internal attachment.
- **Modifiers:** the three linked fields must all be present or all absent. The linked unit must share
  the linked product's dimension.

Rules that are **not** in the mock (AC-62): real uniqueness under concurrency and anything
engine-level.

## Implementation steps

Chunks run in this order: **1 → (2 ∥ 3) → 4**. Chunk 1 is one developer working sequentially; it is
the shared first chunk. After chunk 1 is committed, **chunks 2 and 3 are parallel-safe**: their file
sets are disjoint and neither edits a file owned by chunk 1, except that each may fix its own mock
handler file listed below. Chunk 4 is one developer after both finish.

Ground rules for every chunk:

- Run `npx prettier --check` on touched files. `npm test` must stay green at the end of every step.
- **Tests never use a literal permission string.** Use `CATALOG_PERMISSIONS.x.y`, so the AC-1 grep
  stays clean.
- Debounce tests use `vi.useFakeTimers()` and `vi.advanceTimersByTime(300)`. The workspace is zoneless
  (no `zone.js`), so `fakeAsync` is not available. HTTP in component tests goes through
  `HttpTestingController`, so request counts are exact. Only `mock-catalog.spec.ts` and two
  integration cases use the real mock.

### Chunk 1: Foundation (shared library + shell + CRUD blocks)

Closes AC-1 to AC-19 (building blocks), AC-22 to AC-26, AC-56 (routing and menu) and AC-57. AC-20 is
closed at block level; chunks 2 and 3 prove it per screen.

- [ ] **1.1 Contracts and permission constants (AC-1, AC-22, AC-23).**
  - Create `contracts/catalog/catalog-permissions.ts`, `catalog.models.ts`, `catalog.requests.ts` and
    `catalog.endpoints.ts`.
  - Add the export block to `public-api.ts`.
  - Tests:
    - `catalog-permissions.spec.ts`: 12 keys, each matching `/^catalog\.(categories|brands|units|products|barcodes|modifiers)\.(view|manage)$/`, unique, built from parts with `join('.')`.
    - `catalog.contracts.spec.ts`: one constructed object per type; enum arrays equal T-027's lists. `Object.keys` of a fully populated `Product` and `Category` must not include any AC-23 column, and the omitted names are listed in the spec as plain strings, which is allowed because they are not permission keys.
- [ ] **1.2 `ApiClient.getWithMeta` (AC-13).**
  - Create `api/api-list.ts`.
  - Add the method and the private `checkEnvelope()` to `api-client.ts`.
  - Tests (`api-client-meta.spec.ts`):
    - returns `{data, meta}` with pagination;
    - returns meta without pagination;
    - a `success: false` envelope gives an `ApiError` with `requestId`;
    - an HTTP 422 envelope gives an `ApiError` with field items;
    - query params are passed through.
  - `api-client.spec.ts` is untouched and still green.
- [ ] **1.3 Permissions in the session (AC-2, AC-3, AC-10).**
  - Edit `auth.models.ts` and `auth.service.ts`.
  - Create `permission.service.ts` and `permission.guard.ts`.
  - Tests:
    - `permission.service.spec.ts` against `HttpTestingController`:
      - a `selectTenant` reply with `permissions` fills the set, and `has` and `hasAny` are true;
      - a reply without it gives an empty set and every check is false;
      - a restored session without the field denies;
      - a non-array value denies;
      - `hasAny([])` is false;
      - `clearContext()` empties the set;
      - `logout()` empties the set;
      - a second person logging in after the first does not inherit the first person's keys.
    - `permission.guard.spec.ts`: true when all keys are held, otherwise a `/forbidden` UrlTree.
  - The existing `auth.service.spec.ts` and `auth.guards.spec.ts` are untouched and still green.
- [ ] **1.4 The auth mock's second user (AC-26, first half).**
  - Edit `mock-backend.ts`.
  - Tests go in the new `mock-catalog.spec.ts` "auth" block, because the existing spec stays
    untouched:
    - the demo user's `selectTenant` gives `ALL_CATALOG_PERMISSIONS`;
    - the limited user gives exactly `[CATALOG_PERMISSIONS.products.view]`;
    - the limited user's platform token still selects a tenant;
    - a tenant token is still refused with `wrong_token_type`.
- [ ] **1.5 The catalog mock: store, interceptor and area handlers (AC-24, AC-25, AC-26).**
  - Create the six `mock/catalog/*` files.
  - Export `mockCatalogInterceptor`, `resetMockCatalog` and `MOCK_CATALOG_SENTINEL`.
  - Seed data:
    - units: `EA` count p0; `CASE` count based on EA with factor 24; `KG` mass p3; `G`; `L`; `ML`;
    - categories: a root, a child and a second root;
    - brands: two, one of them used by a product;
    - products: a simple product, a `variant_parent` with two variants, a weighable product, and a
      soft-deleted product;
    - one primary barcode;
    - one modifier group attached to a product, with two modifiers, one linked;
    - one global conversion.
  - Tests (`mock-catalog.spec.ts`, using `resetMockCatalog()` in `beforeEach`), at least one case for
    each of the following:
    - list, get, create, update and delete per entity, with the envelope and pagination;
    - search;
    - soft-deleted rows are excluded from lists but returned by get;
    - every AC-25 refusal, asserting status, `field` and the message naming the blocker;
    - a stale version gives 409;
    - the limited user gets 403 `kernel.forbidden` on `GET catalog/brands` and 200 on
      `GET catalog/products`;
    - no bearer gives 401;
    - a non-catalog path falls through to the auth mock.
- [ ] **1.6 CRUD building blocks (AC-8, AC-14 to AC-20).**
  - Create every file in `crud/`, plus `crud.scss` and the `styles.scss` `@use`.
  - Tests, one spec per block:
    - `list-view.spec.ts`:
      - a caption is present;
      - every `th` has `scope="col"`;
      - there is one `tr` per row;
      - Previous is disabled on page 1 and Next on the last page;
      - "Page 2 of 3" is shown;
      - with 0 rows, "Nothing here yet" is shown;
      - while loading, `role="status"` is present.
    - `list-controller.spec.ts`:
      - a debounced search requests `page=1&search=abc`;
      - `"   "` is treated as empty;
      - the same value is not sent again;
      - an error sets `error`;
      - `retry()` sends identical params once.
    - `api-error-panel.spec.ts`:
      - the message and request id are shown;
      - Retry emits;
      - a 403 shows the permission text;
      - `<b>` in a message renders as literal text.
    - `form-errors.spec.ts`: known fields map to `fields`; an unknown field, a null field and an item
      with no items go to `summary`.
    - `submit-state.spec.ts`: a second `run` while busy does not subscribe, and `busy` is reset after
      an error.
    - `confirm-dialog.spec.ts`:
      - opening focuses inside the dialog;
      - Escape emits cancel;
      - focus returns to the trigger;
      - the dialog has `role="dialog"` and `aria-modal`;
      - the row name is shown.
    - `delete-flow.spec.ts`:
      - confirm sends exactly one DELETE, even when clicked twice;
      - cancel sends none;
      - a 409 keeps the row and shows the server message;
      - success reloads.
    - `entity-picker.spec.ts`:
      - typing requests `search=` with `per_page=25`;
      - `filter` hides rows of another dimension;
      - the "none" option gives `null`;
      - the label is associated with the control.
- [ ] **1.7 Catalog API service and routing skeleton (AC-6, AC-7 routing half, AC-9, AC-56).**
  - Create `catalog/catalog-api.ts`, `catalog/catalog.routes.ts`, the five stub area routes files and
    `shell/forbidden-page.ts`.
  - Edit `app.routes.ts`.
  - Tests:
    - `catalog-api.spec.ts`: one case per method family, asserting the URL, verb, params and body.
    - `catalog.routes.spec.ts`:
      - every area uses `loadChildren` and no eager `component`;
      - the view guard is present per area;
      - the landing redirect goes to the first permitted area, or to `/forbidden` when none is
        permitted.
    - `app.routes.spec.ts`, using `RouterTestingHarness` with the real routes and
      `HttpTestingController`:
      - a person without `brands.view` who opens `/catalog/brands` ends at `/forbidden`, the
        forbidden text and dashboard link are present, and `http.expectNone(() => true)` holds
        (AC-6);
      - a signed-out person who opens `/catalog/products` ends at `/login`, and a person with no
        context ends at `/context` (AC-9);
      - `/customers` ends at `/forbidden`, and the route data says F-2 (AC-56).
- [ ] **1.8 Shell menu (AC-4, AC-5, AC-10 UI, AC-11, AC-12, AC-56 menu, AC-57).**
  - Create `shell/admin-nav.ts`.
  - Edit `admin-shell.ts`.
  - Edit admin's `environment.development.ts`.
  - Tests:
    - `admin-nav.spec.ts`:
      - with only `products.view`, the Catalog group shows only Products;
      - `brands.manage` without `brands.view` shows no Brands entry;
      - with no catalog view key, there is no Catalog group;
      - Dashboard is always shown;
      - Customers is never shown.
    - `admin-shell.spec.ts`, which renders the shell with a seeded session:
      - every nav entry is an `<a href>`;
      - the active entry has `aria-current="page"` after navigation;
      - the `nav` has `aria-label="Main"`;
      - with no branch and no user, there is no empty `.fp-muted` span and no error;
      - after a sign-out, a re-seed with no keys hides Catalog (AC-10).
    - `dashboard-page.spec.ts`: the page renders its heading, and the text contains no "catalog"
      (AC-57).
  - Gate checkpoint: `npm test`, `npx ng build admin` and `npx ng build pos`, then the two-sentinel
    grep on both builds.

### Chunk 2: Categories, brands, units and conversions, modifiers (parallel with chunk 3)

Owns `catalog/{categories,brands,units,modifiers}/**` and may edit
`mock/catalog/mock-catalog.{categories-brands,units,modifiers}.ts` if a gap is found. Closes AC-7 for
its areas, AC-20 for its areas, AC-28 to AC-37 and AC-52 to AC-55.

- [ ] **2.1 Categories (AC-7, AC-8, AC-28 to AC-32).**
  - Routes, with `canActivate` using the view and manage keys on `new` and `:id/edit`.
  - The list page shows code, name, parent name and depth, with the name indented by `depth`.
  - The form has code, name and a parent picker.
  - Edit loads the row by id. A soft-deleted category opens read-only.
  - After saving, the page navigates to the list, which reloads.
  - Tests:
    - the columns are shown, and the indentation follows depth;
    - with view only, there is no Create, Edit or Delete in the DOM, and `/catalog/categories/new`
      goes to `/forbidden`;
    - blank code or name gives a named message and `expectNone` holds;
    - the body never has `path` or `depth`;
    - injected refusals: duplicate code shows on the code field; non-existent parent, cycle and
      too-deep show on the parent field, linked by `aria-describedby`; typed values are kept;
    - after an edit that changes the parent, the PUT carries the new `parent_id` and then a fresh
      list GET follows;
    - a delete refused with 409 keeps the row and shows the message;
    - a 403 on the list shows the permission message, and the session stays signed in.
- [ ] **2.2 Brands (AC-33, AC-7).**
  - List, form and delete, with code and name required.
  - Tests:
    - required fields are blocked;
    - duplicate code shows on code and duplicate name shows on name;
    - the delete refusal shows the message;
    - controls are absent with view only.
- [ ] **2.3 Units (AC-34 to AC-36).**
  - The form has a dimension select, `precision` with default 4, and a base-unit picker filtered to
    the chosen dimension, with `factor`.
  - On edit, dimension is read-only and not sent.
  - Tests:
    - precision of 5, -1 and 1.5 is each blocked on the precision field with nothing sent;
    - base unit without factor and factor without base unit are each blocked with the "go together"
      message;
    - a factor of 0 is blocked;
    - a cross-dimension base unit forced into the request is sent, and the server's refusal shows on
      `base_unit_id`;
    - the picker hides other dimensions;
    - delete with confirm, and a refused delete.
- [ ] **2.4 Unit conversions (AC-37).**
  - The Conversions section on the Units page is gated by the units view key, and Create by the
    units manage key.
  - Columns: from, to, factor, and product or "All products".
  - The form has from, to, factor and an optional product picker.
  - Tests:
    - the same from and to is blocked client-side;
    - a factor of 0 or less is blocked;
    - injected refusals for cross-dimension, duplicate pair (naming the row) and stock-unit mismatch
      show on `to_unit_id`;
    - "All products" is shown for a global row.
- [ ] **2.5 Modifier groups and modifiers (AC-52 to AC-55).**
  - The group form has name, `min_select` with default 0, and `max_select`, where empty means
    unlimited and is sent as `null`.
  - The group detail page lists the group's modifiers.
  - The modifier form has name, price change and cost change (default "0"), and a linked-product
    picker. When a product is picked, linked quantity and a linked-unit picker appear, filtered by the
    product's stock unit dimension.
  - Tests:
    - `min_select > max_select` is blocked on `max_select`;
    - an empty `max_select` sends `null`;
    - the modifier list shows name, price change, cost change and the linked product, quantity and
      unit;
    - with a linked product, quantity and unit are required;
    - clearing the product removes both from the body (the keys are absent);
    - a server dimension refusal shows on `linked_unit_id`;
    - a group delete refusal keeps the row (AC-55).

### Chunk 3: Products, variants and barcodes (parallel with chunk 2)

Owns `catalog/products/**` and may edit `mock/catalog/mock-catalog.products.ts`. Closes AC-7 for
products and barcodes, AC-20 for products, and AC-38 to AC-51.

- [ ] **3.1 Products list (AC-38, AC-7).**
  - Columns: SKU, name, type, category, brand, stock unit, and Active.
  - Search is passed through.
  - Tests:
    - the columns are shown;
    - search sends `search=`;
    - with view only, there are no manage controls;
    - `new` and `:id/edit` go to `/forbidden` without the manage key.
- [ ] **3.2 Product form, create (AC-39, AC-40, AC-41 JSON half, AC-43, AC-44, AC-45).**
  - Tests:
    - each required field is blocked by name, and a 51-character SKU is blocked;
    - `type=variant` shows a parent picker requesting `type=variant_parent`;
    - for every other type the picker is absent and the body has no `parent_id`;
    - a wrong-parent refusal shows on `parent_id`;
    - variant JSON that is not valid JSON is blocked, and valid JSON is sent parsed;
    - the purchase and sales pickers are filtered to the stock unit's dimension, and an empty choice
      sends `null`;
    - a server dimension refusal is shown;
    - an 11-character PLU is blocked;
    - a blank PLU means the key is absent;
    - a duplicate-PLU refusal shows on `plu_code`;
    - a table of combinations of weighable, price mode, track stock and tracking mode all submit
      unchanged.
- [ ] **3.3 Product form, edit (AC-42, AC-46, AC-48).**
  - Tests:
    - the stock unit is shown read-only with the explanation, and the PUT body has no `stock_unit_id`
      key;
    - an injected immutable refusal is displayed;
    - the PUT body carries the loaded `version`;
    - a 409 `catalog.stale_version` shows "changed by someone else" with a Reload button, and the
      typed values are kept until Reload is pressed;
    - pressing Reload issues a GET and resets the form to the server values;
    - clearing an existing PLU sends `plu_code: null`;
    - a soft-deleted product shows the "Deleted" badge and has no Save or Delete control.
- [ ] **3.4 Product detail and variants (AC-41 detail half, AC-47).**
  - The detail page shows the fields.
  - For a `variant_parent` it lists the variants (`parent_id=` query) and shows a "New variant" link
    to `products/new?type=variant&parent_id=<id>`. The form reads these query params as presets.
  - Tests:
    - the variants are listed;
    - the link carries the presets, and the form opens with the type and parent preset;
    - a delete refused for variants or a modifier link keeps the product and shows the message.
- [ ] **3.5 Barcodes (AC-49 to AC-51).**
  - The section on the detail page is gated by the barcodes view key, and add, edit and delete by the
    barcodes manage key.
  - The barcode value is read-only on edit.
  - Tests:
    - the columns are barcode, unit or "Stock unit", quantity and primary marker;
    - the section is absent without the view key, and the controls are absent without the manage key;
    - a blank barcode or one over 64 characters is blocked;
    - quantity defaults to 1, and 0 or a negative number is blocked;
    - the unit picker is filtered by dimension;
    - a duplicate refusal naming the owner shows on `barcode`;
    - marking another barcode primary sends the PUT and then reloads, and exactly one primary marker
      comes from the server reply (the client never clears the old flag itself).

### Chunk 4: Integration, docs and gate (after chunks 2 and 3)

Closes AC-27, AC-58 to AC-61 and AC-63 (documented), and re-checks AC-1.

- [x] **4.1 Integration specs.** Add two cases in `app.routes.spec.ts`:
  - demo user end to end against the real mock interceptors: sign in, select a tenant, select a
    branch, open `/catalog/categories`, and see the seeded rows;
  - limited user: the menu shows only Products, `/catalog/brands` goes to `/forbidden`, and an
    injected 403 on products shows the permission message (AC-8 end to end).
- [x] **4.2 README (AC-61).** Add a catalog area section covering:
  - the folder map;
  - the permission constants file;
  - how the mock grants keys (demo has all of them; limited has the products view key only);
  - the contracts as **provisional pending B-1**;
  - the list convention;
  - the new sentinel and the two-sentinel grep command;
  - the AC-1 stray-literal grep: `grep -rnE "catalog\.(categories|brands|units|products|barcodes|modifiers)\.(view|manage)" projects --include=*.ts | grep -v catalog-permissions.ts` must print nothing;
  - known gaps: the out-of-scope list and Spec vs code items 1 to 4.

  Update the "Not built yet" list.
- [x] **4.3 Full gate (AC-27, AC-58 to AC-60).**
  - Run `npm test` (zero failures, and no `skip` or `todo`; check with
    `grep -rn "\.skip\|\.todo\|xit(" projects`).
  - Run `npx ng build admin` and `npx ng build pos` and check for no budget warnings.
  - Run the grep for both sentinels on `dist/admin/browser/*.js` and `dist/pos/browser/*.js`.
  - Run the AC-1 grep.
  - Run `git diff --stat main -- . ':!frontend' ':!docs'`, which must be empty.
  - Run `git diff main -- frontend/package.json`, which must be empty.

## Test strategy

- **Unit (Vitest through `ng test --watch=false`, jsdom):**
  - pure functions: `visibleNav`, `mapServerErrors`, `describeApiError` and the list controller;
  - services: `PermissionService`, `ApiClient.getWithMeta` and `CatalogApi`, through
    `HttpTestingController`;
  - components: TestBed with `provideRouter`, `provideHttpClient` and `provideHttpClientTesting`.
  - Assertions are at DOM attribute level (AC-63).
- **Mock spec:** a black-box test through `ApiClient`, with `authInterceptor`, `mockCatalogInterceptor`
  and `mockBackendInterceptor`, and real sign-in for both demo users.
- **Routing:** `RouterTestingHarness` with the real admin `routes` (AC-6, AC-7, AC-9, AC-56).
- **Build checks:** `ng build admin` and `ng build pos`, the two-sentinel grep, and the AC-1 grep.

### Which tests prove which ACs

| AC | Proved by |
|---|---|
| AC-1 | `catalog-permissions.spec.ts` (shape) + README grep (no strays, by review) |
| AC-2, AC-3, AC-10 | `permission.service.spec.ts`; `admin-shell.spec.ts` (AC-10 UI) |
| AC-4, AC-5 | `admin-nav.spec.ts`, `admin-shell.spec.ts`, 4.1 |
| AC-6, AC-9 | `app.routes.spec.ts` |
| AC-7 | `catalog.routes.spec.ts` + each area spec (controls absent, form routes forbidden) |
| AC-8 | `api-error-panel.spec.ts` + one 403 case per area spec + 4.1 |
| AC-11, AC-12 | `admin-shell.spec.ts` |
| AC-13 | `api-client-meta.spec.ts` |
| AC-14 to AC-16 | `list-view.spec.ts`, `list-controller.spec.ts`, `api-error-panel.spec.ts` |
| AC-17 | `form-errors.spec.ts` + field-placement cases in each area spec |
| AC-18 | `submit-state.spec.ts` + one double-submit case in `categories.spec.ts` |
| AC-19 | `confirm-dialog.spec.ts`, `delete-flow.spec.ts` |
| AC-20, AC-32, AC-47, AC-55 | `delete-flow.spec.ts` + area specs |
| AC-21 | existing `auth.service.spec.ts` (unchanged); NOT VERIFIABLE beyond that |
| AC-22, AC-23 | `catalog.contracts.spec.ts` + `ng build admin` type-check |
| AC-24 to AC-26 | `mock-catalog.spec.ts` |
| AC-27 | step 4.3 grep |
| AC-28 to AC-37 | `categories.spec.ts`, `brands.spec.ts`, `units.spec.ts`, `unit-conversions.spec.ts` |
| AC-38 to AC-51 | `products.spec.ts`, `product-form.spec.ts`, `barcodes.spec.ts` |
| AC-52 to AC-54 | `modifiers.spec.ts` |
| AC-56 | `catalog.routes.spec.ts`, `app.routes.spec.ts`, `admin-nav.spec.ts` |
| AC-57 | `dashboard-page.spec.ts` |
| AC-58 to AC-60 | step 4.3 commands |
| AC-61 | review of the README |
| AC-62, AC-63 | stated as NOT VERIFIABLE in the README |

## Risks & rollback

- **The mock leaks into a production bundle.** The guarantee rests on tree-shaking: the mock is
  exported from `public-api.ts` but imported only by `environment.development.ts`.
  - A top-level side effect in a mock file, such as `const store = seed()`, would survive
    tree-shaking. The mock files therefore hold only declarations, and state is built lazily inside a
    function.
  - The sentinel sits in `request_id` strings, so a leak is visible to the grep.
  - Step 1.8's checkpoint runs the grep early, not only at the end.
- **The AC-1 "no stray literal" proof is review plus a grep, not a running spec.** It is mitigated by
  the README command and the QA run. If the dev finds that `node:fs` imports work in the Vitest
  builder, a spec may replace the grep; this is optional.
- **Merge conflicts with T-037.** T-037 is likely to touch the same shared files:
  - `public-api.ts`;
  - `mock-backend.ts`, if T-037 extends it rather than sitting beside it;
  - possibly `api-client.ts`, if it also needs `meta`;
  - `README.md`.

  My edits are append-only blocks, and the new files are under `contracts/catalog/` and
  `mock/catalog/`. Exported names carry a catalog prefix (`CatalogDecimal`, `CATALOG_*`) or are
  distinctive (`getWithMeta`, `ApiResult`, `PermissionService`).
- **Merge conflicts with T-035.** T-035 adds a new library and should touch only `angular.json` and
  `tsconfig.json`, which this task does not touch.
- **Debounce tests are flaky.** Fake timers only, with no real waits on the mock in component specs.
- **Contract drift from B-1.** This is expected (D-3). Contracts, endpoints and keys each live in one
  file, and reconciliation is F-4.
- **Rollback.** Revert the branch. The only behavioural change to existing flows is that
  `selectTenant` stores a permissions array and the shell hides Customers, both of which are
  additive.

## Questions for the human

None block this plan; under the team protocol each one has a recommended answer and the plan proceeds
on it. These are for `ba` to log in `docs/decisions/T-036-...`.

1. **Unit delete, group update and modifier update have no T-027 handler** (and `units` has no
   `deleted_at`). *Recommend:* build them against the mock as specified, and add `DeleteUnit` (a hard
   delete, refused while referenced), `UpdateModifierGroup` and `UpdateModifier` to B-1's scope
   explicitly.
2. **D-9's premise is stale for the product tax groups** (T-029 added both columns, with a
   tenant-default fallback). *Recommend:* keep them omitted in T-036 as AC-23 says, and add a tax-group
   picker to F-3 or F-4. Update D-9's reason.
3. **The real `UpdateProduct` has no stale-version check.** *Recommend:* AC-46 stays mock-proven, and
   B-1 must compare the `version` sent (409 `catalog.stale_version`).
4. **No AC puts `selling_price` or `cost_price` on the product form**, so created products price at 0
   until T-029 price lists apply. *Recommend:* leave them out (scope), and list it as a known gap in
   the README. Otherwise add an AC for optional non-negative decimal prices.
5. **The read-only fields on edit** (unit `dimension`, barcode value) follow the server's update field
   lists. *Recommend:* accept as planned.
6. **The wire types for small integers** (`depth`, `precision`, `min_select`, `max_select`, `version`)
   are JSON numbers here, and decimals are strings. *Recommend:* accept; B-1 confirms in F-4.
7. **The permission source is a mock-only `permissions` field on the `auth/tenant` reply** (D-5 left
   the contract to us). *Recommend:* accept; B-1 may instead choose a separate endpoint, which is a
   one-place change in `AuthService.selectTenant`.

Also, the lead should note that **T-037 should sit beside `mockBackendInterceptor`** rather than edit
it, to keep the merge clean.
