# T-036: Admin application shell, navigation and the catalog screens

Backlog: FP-240, "Admin application shell, navigation and the catalog and customer screens."
**Split by D-1** (`docs/decisions/T-036-admin-shell-catalog-customers.md`): this task is the shell,
login-aware and permission-aware navigation, shared CRUD building blocks, and the **catalog** screens.
The **customer** screens are a proposed follow-up (F-2). FP-240 stays open until both are done.
**No BLOCKING questions** (autonomous mode; every question is a logged decision D-1 to D-18).

This task is **frontend only**, built against the **mock API** with typed contracts derived from
`docs/specs/T-027-catalog.md`. It builds no PHP. Its gate is `cd frontend && npm test` plus
`npx ng build admin` and the mock-exclusion grep. The missing backend Get/List surface and permission
keys are a separate proposed row (B-1), not built here.

## Problem / goal

The admin app has a sign-in, a company picker and a shell with three menu entries, two of which
(Catalog, Customers) are placeholder pages. A shop admin cannot yet see or edit anything. The goal is
a usable back-office frame (navigation that reflects who is signed in and what they may do) and working
screens for everything T-027 built in the catalog, ready to be pointed at the real backend once B-1 exists.

## Users affected

- **Shop admins and catalog managers** who maintain categories, brands, units, products and modifiers.
- **Staff with limited rights** who must not see menu entries or screens they cannot use.
- **Frontend and backend developers** of later tasks (F-2, F-3, B-1): the shell, guards, list/form
  building blocks and typed contracts are what they build on.
- Not served: customer screens (F-2), components/recipes, per-location and per-branch settings (F-3),
  the till (`pos`).

## Current behaviour

Re-derived from `frontend/` in this worktree:

- `projects/admin` has routes `login`, `context`, and a lazily loaded `AdminShell` guarded by
  `contextGuard`, with child routes `dashboard`, `catalog` and `customers`. `catalog` and `customers`
  render `PlaceholderPage` with text naming the rows that will fill them.
- `AdminShell` has a fixed three-entry side menu (Dashboard, Catalog, Customers), a top bar showing the
  company, the branch (mock-only, T-025 D-5) and the user name (mock-only), and "Switch" and "Sign out".
  Menu entries do not depend on permissions.
- `@flexipos/shared` provides `ApiClient` (envelope unwrapping; `get/post/put/delete`; `postRaw` for
  auth), `toApiError`, `AuthService` (platform and tenant token pairs held side by side), guards
  (`authGuard`, `contextGuard`, `guestGuard`), the mock backend interceptor (development only) and a
  plugin route registry. `ApiClient.get` returns only `data`, so the response `meta` (needed for page
  counts) is not reachable.
- No permission concept exists in the frontend. No real endpoint returns the signed-in person's
  permissions (T-025 found no "who am I" endpoint either).
- Backend: T-027 (DONE) built catalog tables and handlers but **no HTTP surface and no permission
  keys** (FP-240 note; T-027 D-2). T-021's envelope is
  `{success, data, message, errors[{code,field,detail}], meta{request_id}}`; list pagination
  conventions were left to the first list endpoint (T-021 out of scope).
- Test runner: Vitest through `ng test --watch=false`; `ng build admin` and the production-bundle
  grep for mock strings are existing checks (`frontend/README.md`).

## User stories

- As a shop admin, I want a navigation menu that shows only the areas I may use, so that I am not shown
  screens that will refuse me.
- As a shop admin, I want to be sent to a clear "not permitted" page if I type a URL I may not use, so
  that I understand why nothing loaded.
- As a catalog manager, I want to maintain the category tree and brands, so that products are organised
  and reports roll up correctly.
- As a catalog manager, I want to define units, their dimension and precision, and the conversions between
  them, so that receiving in cases and selling in singles is correct.
- As a catalog manager, I want to create products, including parent products with variants, with the
  stock, purchase and sales units, weighable flag and PLU, so that the shop can sell them.
- As a catalog manager, I want to manage barcodes per product and mark the primary one, so that scanning works.
- As a restaurant or deli admin, I want to maintain modifier groups and their modifiers, so that orders can be customised.
- As a catalog manager, I want the server's refusals (duplicate code, blocked delete, cycle) shown plainly
  next to the field or action that caused them, so that I can fix the problem without guessing.
- As a frontend developer of the next task, I want shared list, form and error components and typed API
  contracts in one place, so that customer screens reuse them rather than copy them.

## Acceptance criteria

Convention (as T-025): prefer criteria a running check proves (`npm test`, `ng build`, the grep).
"Verified by test" means a Vitest spec in the workspace. Anything needing a real backend, real browser
or real permissions endpoint is fenced **NOT VERIFIABLE** and listed in section L.

### A. Permissions and navigation

- AC-1: Given the shared library, When built, Then one constants file lists every catalog permission key
  as `catalog.<resource>.view` and `catalog.<resource>.manage` for resource in `categories`, `brands`,
  `units`, `products`, `barcodes`, `modifiers` (D-4), and no other file contains a literal catalog
  permission string. Verified by test (a spec that greps source for stray literals is acceptable) or review.
- AC-2: Given a session, When it is created or restored, Then it carries a set of permission keys
  (empty when none supplied), and a `PermissionService` (or equivalent) answers `has(key)`, `hasAny(keys)`.
  Verified by test.
- AC-3: Given the permission set is empty or the field is absent, When any check runs, Then every check
  returns false (deny by default, D-6). Verified by test.
- AC-4: Given a signed-in person with `catalog.products.view` only, When the shell renders, Then the
  Catalog section shows only entries whose `view` key is held (Products) and not the others; **negative**:
  a person holding `manage` but not `view` for a resource sees no entry for it. Verified by test.
- AC-5: Given a person with no catalog `view` key at all, When the shell renders, Then no Catalog menu
  entry (group or child) is shown. Dashboard remains visible. Verified by test.
- AC-6: Given a person without `catalog.brands.view`, When they navigate directly to the brands URL,
  Then a route guard redirects to a forbidden page that states they lack permission and offers a link
  back to the dashboard; the brands screen's code is not rendered and no catalog API call is made.
  Verified by test.
- AC-7: Given a person with `view` but not `manage` for a resource, When the screen renders, Then
  Create, Edit and Delete controls are absent (not merely disabled) and the form routes
  (`.../new`, `.../:id/edit`) redirect to the forbidden page. Verified by test.
- AC-8: Given the server answers a catalog request with HTTP 403 (permissions changed since sign-in,
  or the client set was wrong), When the screen receives it, Then it shows the permission message in
  place of the content or as the action's error, and does not crash or log the person out. Verified by test.
- AC-9: Given a person who is not signed in or has not chosen a company, When any admin route other than
  `login` is requested, Then they are sent to `login` or `context` exactly as today (existing guards
  unchanged). Verified by existing specs still passing plus one new case for a catalog URL.
- AC-10: Given the person signs out or switches company, When the session clears, Then the permission set
  is cleared with it, so the next person never inherits menu entries. Verified by test.
- AC-11: Given the shell, When rendered at keyboard-only use, Then every menu entry is a focusable link,
  the active entry is marked with `aria-current="page"`, and the nav region keeps `aria-label`.
  Verified by test (attribute assertions).
- AC-12: Given the shell, When the top bar renders and the branch or user name is absent (the real
  backend today supplies neither), Then it renders without error and without empty placeholder text
  (D-18). Verified by test.

### B. Shared building blocks

- AC-13: Given `ApiClient`, When a list read is made, Then a method returns both `data` and `meta` (including
  `meta.pagination = {page, per_page, total}` when present, D-7) while existing `get/post/put/delete`
  behave exactly as before. Verified by test (new cases; existing ApiClient specs unchanged and green).
- AC-14: Given a reusable list component, When given a page of rows, columns, a total and a current page,
  Then it renders a table with a caption, column headers with `scope="col"`, one row per item, page
  controls (previous, next, current/total) disabled at the ends, and a visible empty state ("Nothing here yet")
  when there are zero rows. Verified by test.
- AC-15: Given the list component, When the user types in the search box and pauses, Then it requests page 1
  with `search=<text>`; **edge**: whitespace-only search is treated as empty; the request is not re-sent
  for an unchanged value. Verified by test.
- AC-16: Given a list request is in flight, When the screen renders, Then a loading indicator announced to
  assistive technology (`role="status"`) is shown; given it fails, Then an error panel shows the
  `ApiError` message, the request id (`meta.request_id`) when present, and a Retry action that re-sends
  the same request. Verified by test.
- AC-17: Given a form submission is refused with an envelope whose `errors[]` items carry `field`, When
  the form renders the refusal, Then each message appears next to the control named by `field`
  (programmatically associated, `aria-describedby`), items with no or unknown `field` appear in a summary
  at the top of the form, and the user's typed values are preserved. Verified by test.
- AC-18: Given a form is submitting, When the user activates submit again, Then no second request is sent and the
  control shows a busy state (D-15). Verified by test.
- AC-19: Given a delete action, When chosen, Then a confirmation dialog (focus moved into it, Escape cancels,
  focus returns to the trigger) names the row; confirming sends the delete once; cancelling sends nothing.
  Verified by test.
- AC-20: Given a delete refused by the server (for example a category with active children, T-027 AC-5), When
  the refusal arrives, Then the row stays in the list and the server's message, which names the blocking
  reference, is shown to the user. Verified by test.
- AC-21: Given any list or form, When the signed-in person's tenant token has expired and the server answers 401,
  Then behaviour is the existing interceptor/guard behaviour (no new handling is added by this task).
  **NOT VERIFIABLE** beyond existing specs, since refresh is not wired (T-025).

### C. Typed contracts and the mock API

- AC-22: Given `@flexipos/shared`, When built, Then typed contracts exist for the catalog entities of T-027
  in scope (D-9, D-10): category, brand, unit, unit conversion, product, product barcode, modifier group,
  modifier; plus the create/update request shapes and the list parameter and page shapes. Field names, types,
  nullability and enumerations match T-027's ACs (listed per entity in sections D to I). Verified by
  type-check (`ng build admin`) and by test files that construct each contract.
- AC-23: Given the contracts, When read, Then none contains a field for T-027's deferred columns (tax group,
  purchase tax group, income/cogs/inventory account, kitchen station, custom fields; D-9). Verified by test (type
  key assertions) or review.
- AC-24: Given the mock backend, When extended, Then it serves list, get-by-id, create, update and delete for every
  in-scope catalog entity in the contract shapes, inside the real envelope, with the pagination meta (D-7).
  Verified by test.
- AC-25: Given the mock, When a request violates one of these rules, Then it answers with an enveloped 422 whose
  `errors[]` carry `field`: blank or duplicate category code; duplicate brand code or name; duplicate product
  SKU; duplicate barcode; unit precision outside 0 to 4; `min_select > max_select`. And it answers 409 or 422 with a
  message naming the blocker for these deletes: category with active children or products, brand used by an
  active product, product that is a component or linked by a modifier (mock needs only: category and brand).
  Verified by test.
- AC-26: Given the mock answers permission-bearing sessions, When a test signs in as the demo user, Then the demo
  user holds all catalog keys, and a second demo user (or a test hook) holds a restricted set, so AC-4 to AC-8 are
  testable. The mock returns 403 for a catalog call whose key the restricted user lacks. Verified by test.
- AC-27: Given a production build of `admin` and of `pos`, When `grep -l` for the existing mock sentinel
  (`demo@flexipos.test`) **and** the new catalog-mock sentinel (D-14) is run on `dist/<project>/browser/*.js`,
  Then it prints nothing. Verified by the build and grep (also run by CI's existing step if updated to include
  the new sentinel; updating `ci.yml` is out of scope, see Out of scope, so the README documents the command).

### D. Categories (T-027 section A)

- AC-28: Given the Categories screen, When opened by a holder of `catalog.categories.view`, Then categories are
  listed with code, name, parent name, depth, and the tree is indicated (indentation by `depth` or a path column).
  Verified by test.
- AC-29: Given the create form, When opened, Then it asks for `code` (required), `name` (required) and an optional
  parent chosen from existing categories; `path` and `depth` are never editable or sent (T-027 AC-2). Blank
  code or name is blocked client-side naming the field; **negative**: no request is sent. Verified by test.
- AC-30: Given a refusal from the server for duplicate code (T-027 AC-1), a non-existent or deleted parent
  (AC-2), a move that creates a cycle (AC-4), or a depth over 17 (AC-3a), When shown, Then the message appears on
  the parent or code field as sent. Verified by test using the mock or injected refusals.
- AC-31: Given the edit form for a category, When the parent is changed and saved, Then the update is sent with the
  new `parent_id` and the list reloads from the server (descendant paths are the server's to recompute; the client
  never computes `path` or `depth`). Verified by test.
- AC-32: Given a category delete refused for children or products (T-027 AC-5), Then AC-20 applies. Verified by test.

### E. Brands (T-027 section B)

- AC-33: Given the Brands screen, When used, Then list, create, edit and delete work with `code` and `name`, both
  required client-side; duplicate code or name refusals appear on the named field (T-027 AC-7); a delete
  of a brand used by an active product is refused with the server's message (AC-8, AC-20). Verified by test.

### F. Units and unit conversions (T-027 section C)

- AC-34: Given the Units screen, When used, Then list, create, edit and delete work. The create form asks
  for `code`, `name`, `dimension` (select: `count`, `mass`, `volume`, `length`, `area`), `precision` (whole number 0 to
  4, defaulting to 4, T-027 AC-11), and optional `base_unit_id` with `factor`. Verified by test.
- AC-35: Given the unit form, When `precision` is outside 0 to 4 or not a whole number, Then submit is blocked
  naming the field and nothing is sent. When exactly one of `base_unit_id` and `factor` is filled, Then submit is
  blocked saying they go together; a `factor` of zero or less is blocked. Verified by test.
- AC-36: Given the unit form with a `base_unit_id` chosen, When the base unit's dimension differs from the
  form's dimension, Then the client does **not** decide; it sends and shows the server's refusal (T-027 AC-11, D-17).
  The base-unit picker may offer only units of the chosen dimension as a convenience. Verified by test.
- AC-37: Given the unit conversions section (a tab or sub-list on the Units screen, gated by `catalog.units.view`
  and `catalog.units.manage`), When used, Then rows show from unit, to unit, factor and product (or "all products"
  when global); the create form asks for from and to unit (picker, must differ, client-blocked when equal), a positive
  factor, and an optional product. Server refusals for cross-dimension, duplicate pair (naming the existing row),
  and product-scoped rows whose to-unit is not the product's stock unit (T-027 AC-13, AC-13a, AC-14) appear on the
  relevant field. Verified by test.

### G. Products and variants (T-027 sections D and E)

- AC-38: Given the Products screen, When opened by a holder of `catalog.products.view`, Then products are listed
  with SKU, name, type, category, brand, stock unit and active state, and the list can be searched by SKU or name
  (D-7). Verified by test.
- AC-39: Given the product create form, When opened, Then it requires `sku` (not blank, 50 characters at most),
  `name`, `category_id` (picker over categories), `stock_unit_id` (picker over units) and `type` (select of
  `simple`, `variant_parent`, `variant`, `service`, `bundle`, `composite`); optional `brand_id`, `purchase_unit_id`,
  `sales_unit_id`. Missing required fields and an over-long SKU block submit naming the field. Verified by test.
- AC-40: Given the form with `type = variant`, When shown, Then a required `parent_id` picker appears listing only
  products whose type is `variant_parent`; given any other type, Then the picker is absent and no `parent_id` is
  sent (T-027 AC-19). A server refusal for a parent of the wrong type still shows on the parent field. Verified by test.
- AC-41: Given the form for a `variant_parent`, When shown, Then the variant option and variant values inputs are
  free JSON text with only "must be valid JSON" checked client-side (T-027 AC-21), and the product detail page lists
  that parent's variants with a link to create a new variant with the parent preset. Verified by test.
- AC-42: Given the edit form for an existing product, When shown, Then `stock_unit_id` is displayed read-only with an
  explanation that it cannot change, and is never sent as a changed value (T-027 AC-22). **Negative**: a test that
  forces a different value into the request is not possible through the UI, and if the server refuses the update
  the refusal is displayed. Verified by test.
- AC-43: Given the purchase and sales unit pickers, When a stock unit is chosen, Then the pickers list only units of
  the same dimension as a convenience; an empty choice is valid and means "same as the stock unit" (T-027 AC-23);
  the server's dimension refusal still displays if it arrives. Verified by test.
- AC-44: Given the PLU input, When the text is longer than 10 characters, Then submit is blocked naming the field; a
  blank PLU is sent as absent, not as an empty string (T-027 AC-24); a server duplicate-PLU refusal shows on the field.
  Verified by test.
- AC-45: Given the weighable flag, price mode (`per_unit`, `per_weight`, `open_price`), track-stock flag and tracking mode
  (`none`, `quantity`, `batch`, `serial`), When used, Then each is an independent control with no client coupling
  between them (T-027 AC-25, AC-26). Verified by test (any combination can be submitted).
- AC-46: Given an edit of a product, When saved, Then the request carries the `version` loaded with the product (D-12);
  given a stale-version refusal, Then a message offers "Reload" and the typed values stay in the form until the person
  chooses to reload. Verified by test.
- AC-47: Given a product delete refused because variants exist, or because it is a component or a modifier link
  (T-027 AC-20), Then AC-20 applies. Verified by test.
- AC-48: Given a product that is soft-deleted and opened by id (D-11), When shown, Then it is read-only with a
  visible "Deleted" badge and no save or delete control. Verified by test.

### H. Barcodes (T-027 section F)

- AC-49: Given a product's detail page, When a holder of `catalog.barcodes.view` opens it, Then its barcodes are
  listed with barcode value, unit (or "stock unit" when none), quantity and a primary marker; a holder of
  `catalog.barcodes.manage` can add, edit and delete them. Verified by test.
- AC-50: Given the add-barcode form, When used, Then `barcode` is required and at most 64 characters, `quantity`
  defaults to 1 and must be a positive number, `unit` is optional with the dimension convenience filter of AC-43.
  A duplicate barcode refusal names the product that already owns it and is shown on the field (T-027 AC-28). Verified by test.
- AC-51: Given a product with a primary barcode, When another is marked primary, Then after the server answers the
  list reloads and exactly one row shows the primary marker (T-027 AC-30; the client does not clear the old flag itself).
  Verified by test.

### I. Modifier groups and modifiers (T-027 section H)

- AC-52: Given the Modifiers screen, When used, Then groups are listed and managed with `name` (required),
  `min_select` (whole number at least 0, default 0) and `max_select` (whole number or **empty meaning unlimited**,
  T-027 AC-36). When `max_select` is filled and `min_select` is greater, submit is blocked naming `max_select`. Verified by test.
- AC-53: Given a group is opened, When its modifiers are shown, Then each shows name, price change and cost change
  (default 0) and, when present, the linked product, quantity and unit. Create and edit require `name`.
  Verified by test.
- AC-54: Given the modifier form, When a linked product is chosen, Then linked quantity and linked unit become required;
  when no linked product is chosen, those two fields are cleared and not sent (T-027 AC-37). Server refusals for a
  cross-dimension linked unit (AC-38) appear on the field. Verified by test.
- AC-55: Given a group delete refused because it is attached to a live product (T-027 AC-36a), Then AC-20 applies.
  Verified by test.

### J. Dashboard and shell integration

- AC-56: Given the Catalog placeholder and its `arrivesWith` text, When the task is done, Then the placeholder route
  for Catalog is replaced by the real catalog routes (lazy-loaded; the screens are not in the initial bundle), and
  the Customers entry **remains a placeholder** whose text now says it arrives with the customer screens follow-up (F-2),
  the Customers menu entry is hidden for everyone, because no customer permission keys exist (D-4's scope
  is catalog), and its route shows the forbidden page. Verified by test.
- AC-57: Given the dashboard, When opened, Then it still renders (existing spec passes) and gains no catalog statistics.
  Verified by existing spec.

### K. Quality gates

- AC-58: Given the workspace, When `cd frontend && npm ci && npm test` is run, Then all tests pass with zero
  failures (count will grow from the current baseline; the criterion is zero failures, not a count) and no test
  is skipped or marked `todo` to achieve green. Verified by the command.
- AC-59: Given the workspace, When `npx ng build admin` and `npx ng build pos` are run, Then both exit 0 with no new
  warnings about budget or unused imports, and the `pos` output is unchanged in behaviour (no catalog code is
  pulled into `pos` by shared contracts beyond types). Verified by the commands.
- AC-60: Given the changes, When the diff is read, Then no file outside `frontend/` (and the allowed docs) is changed, no
  PHP is changed, and no new entry appears under `dependencies` or `devDependencies` in `frontend/package.json`
  (D-13). Verified by review of the diff.
- AC-61: Given `frontend/README.md`, When updated, Then it documents: the catalog area and where its screens live; the
  permission key constants file and how the mock grants keys; the proposed contracts as **provisional** pending B-1;
  the new mock-exclusion sentinel and the grep command; and the known gaps listed in Out of scope. Verified by review.

### L. Not verifiable here

- AC-62: **NOT VERIFIABLE** in this task: any behaviour against a real backend, real permission enforcement, a real
  permission-set endpoint, real pagination, and real database-enforced rules (uniqueness, cycles, depth, delete
  guards). The mock implements a subset (AC-25) only to exercise the UI's display paths. Reconciliation is F-4 after B-1.
- AC-63: **NOT VERIFIABLE** in this task: layout in real browsers and screen-reader behaviour beyond the attribute
  assertions of AC-11, AC-14, AC-17, AC-19 (the workspace has jsdom only).

## Out of scope

- Customer screens: customers, groups, credit ledger view, loyalty view, notes, communications, merge (F-2, D-1).
- Building the catalog Get/List endpoints, write endpoints, permission keys, the permission-set endpoint, or any
  PHP (B-1). Customer HTTP surface (B-2).
- Product components (bundle/kit/recipe lines), per-location and per-branch product settings, modifier-group
  attachment to products (F-3, D-10). `barcode_rules` (FP-423).
- Tax groups, accounts, kitchen stations, custom fields (columns do not exist, D-9).
- Offline, PWA or sync for `admin` (T-025 D-9). Translations (D-16). New dependencies (D-13).
- Editing `.github/workflows/ci.yml` (T-025's frontend job already runs `npm test`, both builds, and a grep; adding the
  new sentinel to its grep is a one-line follow-up the lead may fold into any CI change; the README documents the command).
- Idempotency keys on create (D-15), token refresh wiring, "who am I" for user name and branch.
- Bulk import or export, images for products, price lists, stock levels.
- Deciding FP-428/FP-429 (backend); the UI only follows D-11.

## Non-functional requirements

- Security: the client permission check is a convenience, never the authority (D-6). No token or secret is written
  to the page, the console or `localStorage` by new code. Server error text is rendered as text, never as HTML. No
  catalog call is made for a route the person is not permitted to open (AC-6).
- Accessibility: every form control has a programmatically associated label; errors are associated with their
  control; tables have a caption and scoped headers; dialogs trap and return focus; loading and error panels
  use `role="status"`/`role="alert"` as appropriate. (Asserted at attribute level only, AC-63.)
- Performance: catalog screens are lazy-loaded (AC-56); lists request one page at a time (default `per_page` 25);
  pickers over categories, units and products must not load unbounded lists: they use a search-as-you-type request of
  at most 25 rows.
- Maintainability: permission keys in one file; contracts in one place; list, form-error and confirm building blocks
  are not copied per screen.

## Open questions

None blocking. Decisions D-1 to D-18 are in `docs/decisions/T-036-admin-shell-catalog-customers.md`, which also lists
the owner-visible questions with their defaults and the proposed follow-up rows B-1, B-2, F-2, F-3, F-4.

## Assumptions

- The mock may carry whatever permission set the tests need; the real set arrives with B-1 (D-5).
- T-027's field names are the contract's field names (snake_case on the wire); the client may use camelCase
  internally only if conversion is in one place and tested. Default: keep snake_case on the wire types.
- Dimension filtering of unit pickers uses data already loaded for that form, not an extra request per keystroke.
- A single page size (25) is acceptable for all lists in this task.
- Existing T-025 specs stay unchanged and green; the existing mock behaviours (login, tenants) are untouched.
