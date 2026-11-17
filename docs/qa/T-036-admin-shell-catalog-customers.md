# QA Report: T-036 Admin application shell, navigation and the catalog screens

Tip tested: `c9e0250` (Chunks 1, 2, 3 merged; Chunk 4 not yet merged). Spec AC-1 to AC-63, decisions D-1 to D-18.

## Verdict: FAIL

Reasons, in order of weight:
1. AC-61 (README) is not delivered: `frontend/README.md` is untouched since the plan commit (Chunk 4 outstanding). Also the "README documents the grep command" half of AC-27.
2. Three minor behavioural defects found by QA (BUG-3, BUG-4, BUG-5), each reproduced by a test that currently demonstrates the wrong behaviour.
3. One contract note (BUG-2) on the request body versus the real T-027 handler allow-list.

Everything else passes: 760 tests green, both builds green, mock exclusion proven with a positive control, 18/18 QA mutations killed.

## Acceptance criteria coverage

Test paths are under `frontend/projects/`. "Dev" = developer spec, "QA" = new QA spec (list at the end).

| AC | Test(s) | Result |
|----|---------|--------|
| AC-1 | `shared/.../catalog-permissions.spec.ts` (12 keys, shape, unique); QA grep `catalog\.[a-z]+\.(view\|manage)` over all `*.ts` outside `catalog-permissions.ts`: 0 hits (includes the 3 QA specs) | PASS |
| AC-2 | `shared/.../permission.service.spec.ts` (filled from reply, restored, string-only filter, has/hasAny) | PASS |
| AC-3 | `permission.service.spec.ts` (absent field, empty list, `hasAny([])`); QA `permission-matrix.spec.ts` (absent field, string/object/null/number instead of array: 22 routes each) | PASS |
| AC-4 | `admin-nav.spec.ts`, `admin-shell.spec.ts` (products.view only shows Products; manage-only shows no entry) | PASS |
| AC-5 | `admin-nav.spec.ts`, `admin-shell.spec.ts` (no Catalog group, Dashboard stays) | PASS |
| AC-6 | `app.routes.spec.ts` (forbidden page text, link to `/dashboard`, no request); QA `permission-matrix.spec.ts` leave-one-out over every route, `expectNone` on every forbidden outcome | PASS |
| AC-7 | `categories/brands/units/products/modifiers/unit-conversions` specs (no Create/Edit/Delete in DOM, form routes forbidden); QA matrix (every form route needs view+manage; barcode forms need products.view+barcodes.view+barcodes.manage) | PASS |
| AC-8 | per-area specs (403 on list, on form save, on delete; stays signed in); `describe-api-error.spec.ts`, `api-error-panel.spec.ts`; QA `catalog-edge-cases.spec.ts` (403 on delete keeps route and row). **Picker lookups are the exception, see BUG-3** | PASS with BUG-3 |
| AC-9 | `app.routes.spec.ts` (signed out to `/login`, no company to `/context`); QA matrix repeats both on all 22 catalog routes | PASS |
| AC-10 | `permission.service.spec.ts` (clearContext, logout, second person), `admin-shell.spec.ts` | PASS |
| AC-11 | `admin-shell.spec.ts` (links, `aria-label="Main"`, `role=group` labelled, one `aria-current=page`) | PASS |
| AC-12 | `admin-shell.spec.ts` (no branch/company/user; control with all present) | PASS |
| AC-13 | `shared/.../api-client-meta.spec.ts`; existing `api-client.spec.ts` unchanged (git: modified-file list contains no existing spec) | PASS |
| AC-14 | `list-view.spec.ts` (caption, `th scope=col`, rows, pager disabled at ends, "Page 2 of 3", empty state). **Out-of-range page after delete: BUG-5** | PASS with BUG-5 |
| AC-15 | `list-controller.spec.ts` (debounced page 1 + search, whitespace-only, unchanged value not re-sent) | PASS |
| AC-16 | `list-view.spec.ts`, `list-controller.spec.ts`, `api-error-panel.spec.ts` (role=status, message, request id, Retry re-sends identical params, newest request wins) | PASS |
| AC-17 | `form-errors.spec.ts`; per-form specs (field text via `aria-describedby`, summary for unknown/no field, values kept); QA `catalog-edge-cases.spec.ts` (markup in a field message stays text) | PASS |
| AC-18 | `submit-state.spec.ts`, `categories.spec.ts`; **QA `double-submit.spec.ts`: the other 7 create forms** (brand, unit, conversion, product, barcode, modifier group, modifier) plus resubmit after a refusal | PASS |
| AC-19 | `confirm-dialog.spec.ts`, `delete-flow.spec.ts`; QA edge cases (Escape on a real page returns focus to the trigger, dialog names only the clicked row, labelled, `aria-modal`, double click sends one DELETE). After a *confirmed* delete focus is lost: BUG-4 | PASS with BUG-4 |
| AC-20 | per-area specs (refused delete keeps row, shows message naming blocker); QA (refusal closes dialog, returns focus, markup stays text) | PASS |
| AC-21 | NOT VERIFIABLE beyond existing interceptor specs (refresh not wired, T-025), as the spec fences it | NOT VERIFIABLE |
| AC-22 | `catalog.contracts.spec.ts` (every type constructed, enums equal T-027 lists, verified by QA against `packages/catalog` Dimension and the T-027 spec lines 190, 257, 295, 300); `ng build admin` type-check | PASS (see BUG-2) |
| AC-23 | `catalog.contracts.spec.ts` (deferred column names absent on Product and Category, with a control that can fail) | PASS |
| AC-24 | `shared/.../mock-catalog.spec.ts` (list/get/create/update/delete, envelope, pagination, search, soft-deleted rows) | PASS |
| AC-25 | `mock-catalog.spec.ts` (every listed 422 with `field`; delete blockers for category and brand name the blocker) | PASS |
| AC-26 | `mock-catalog.spec.ts` (demo user all 12 keys, limited user products.view only, 403 `kernel.forbidden`, 401 with no bearer, end to end sign-in) | PASS |
| AC-27 | QA build + grep: production `admin` and `pos` bundles contain none of `demo@flexipos.test`, `fp-mock-catalog-a7f3`, `limited@flexipos.test`, `mock-tenant-access` (0 files each); development admin build contains all four (1 file each, positive control). **README half is in AC-61 (BUG-1)** | PASS (grep) |
| AC-28 | `categories.spec.ts` (code, name, parent name, depth, indent) | PASS |
| AC-29 | `categories.spec.ts` (blank code/name named, nothing sent; never `path`/`depth`) | PASS |
| AC-30 | `categories.spec.ts` (refusal shown on the named field; unknown field to summary) | PASS |
| AC-31 | `categories.spec.ts` (edit sends new `parent_id`, list reloads, self not offered) | PASS |
| AC-32 | `categories.spec.ts` (refused delete) | PASS |
| AC-33 | `brands.spec.ts` (list, create, edit, delete, duplicate code and name on the field, blocked delete) | PASS |
| AC-34 | `units.spec.ts` (list, form, default precision 4, dimension select, base unit + factor, edit, delete) | PASS |
| AC-35 | `units.spec.ts` (`it.each` precision values, limits 0 and 4, "go together" both directions, factor 0/-2/abc/0.0); QA mutation M08 (bound 4 to 5) killed by 19 tests | PASS |
| AC-36 | `units.spec.ts` (cross-dimension base unit is sent and the server refusal shown; picker narrows by dimension) | PASS |
| AC-37 | `unit-conversions.spec.ts` (rows, "All products", equal units blocked on to-unit, global and product-scoped sends, section gating); server refusals are shown on the field through the same mapper as every form | PASS |
| AC-38 | `products.spec.ts` (columns; debounced `search=`) | PASS |
| AC-39 | `product-form.spec.ts` (each missing field named, 51 vs 50 character SKU); mutation M13 killed | PASS |
| AC-40 | `product-form.spec.ts` (variant parent picker lists `variant_parent` only, no `parent_id` for the 5 other types even after a variant was chosen first, wrong-parent refusal on the field) | PASS |
| AC-41 | `product-form.spec.ts` (JSON checks), `products.spec.ts` (variants list, New variant link with parent preset) | PASS |
| AC-42 | `product-form.spec.ts` (read-only stock unit with the reason, never sent, refusal displayed) | PASS |
| AC-43 | `product-form.spec.ts`, `barcodes.spec.ts` (dimension filter, empty choice sent as null, server dimension refusal shown) | PASS |
| AC-44 | `product-form.spec.ts` (11 characters blocked, blank sent as absent, `null` clears, duplicate PLU on field); mutation M14 killed | PASS |
| AC-45 | `mock-catalog.spec.ts` (any combination accepted); form controls are independent in `product-form-page.ts` (no coupling code found on read) | PASS |
| AC-46 | `product-form.spec.ts` (version sent, stale refusal keeps typed values until Reload); mutation M06 (version 0) killed | PASS |
| AC-47 | `products.spec.ts` (delete flow), `mock-catalog.spec.ts` (variants / modifier link blockers) | PASS |
| AC-48 | `product-form.spec.ts`, `products.spec.ts` (Deleted badge, disabled fields, no Save, no Edit link) | PASS |
| AC-49 | `barcodes.spec.ts` (list columns, view-only, manage controls, deleted product) | PASS |
| AC-50 | `barcodes.spec.ts` (64 limit, quantity default 1 and positive, duplicate refusal naming the owner on the field); mutation M11 killed | PASS |
| AC-51 | `barcodes.spec.ts` (mark primary, then list reloads, client never clears the old flag) | PASS |
| AC-52 | `modifiers.spec.ts` (min default 0, empty max sent as null, min above max blocked on `max_select`, equal passes); mutation M12 killed | PASS |
| AC-53 | `modifiers.spec.ts` (group detail columns, defaults 0, name required) | PASS |
| AC-54 | `modifiers.spec.ts` (linked quantity and unit required with a product, omitted when cleared, cross-dimension refusal on the field); mutation M16 killed | PASS |
| AC-55 | `modifiers.spec.ts` (refused group delete) | PASS |
| AC-56 | `app.routes.spec.ts`, `catalog.routes.spec.ts` (lazy `loadChildren`, no eager component, `/customers` forbidden even with all keys, route data names F-2), `admin-shell.spec.ts` (no Customers entry); build output shows each screen as a lazy chunk | PASS |
| AC-57 | `dashboard-page.spec.ts` (renders, no catalog statistics) | PASS |
| AC-58 | `npm test`: 3 projects, 760 tests, 0 failed, 0 skipped, 0 todo; 3 `it.fails` QA cases are expected-fail, not skips (see below). `.only/.skip/xit/fit/todo` grep: 0 hits | PASS |
| AC-59 | `npx ng build admin` exit 0, `npx ng build pos` exit 0, no warnings in either log; `pos` bundle contains no catalog code (only an existing till placeholder sentence) | PASS |
| AC-60 | `git diff --name-only 1712c34 HEAD`: no file outside `frontend/`; `package.json`, `package-lock.json`, `angular.json`, `tsconfig.json`, all of `projects/pos` unchanged; no existing spec modified | PASS |
| AC-61 | `frontend/README.md` not modified by T-036 (grep for catalog, sentinel, provisional, B-1, permission finds only a pre-existing plugin line) | **FAIL (BUG-1, Chunk 4)** |
| AC-62 | Fenced by the spec (real backend, real enforcement, real pagination) | NOT VERIFIABLE |
| AC-63 | Fenced by the spec (real browsers, screen readers, layout, contrast; jsdom only). Attribute-level a11y is asserted under AC-11/14/17/19 | NOT VERIFIABLE |

## Test run summary

- `cd frontend && npm test` (all three projects): pos 2/2, admin 594 passed + 3 expected-fail (597), shared 161/161. Total 757 passed, 3 expected-fail, 0 failed, 0 skipped. Baseline before QA specs: admin 290, total 453.
- `npx ng build admin`: exit 0, no warnings. `npx ng build pos`: exit 0, no warnings. `npx ng build admin --configuration development --output-path dist/admin-dev`: exit 0 (positive control only).
- Mock-exclusion grep over `dist/admin/browser` and `dist/pos/browser` for `demo@flexipos.test`, `fp-mock-catalog-a7f3`, `limited@flexipos.test`, `mock-tenant-access`: 0 files each. Same four strings in the development build: 1 file each (the check can fail). `frontend/dist` deleted afterwards.
- `npx prettier --check` on every T-036 file (`git diff --name-only 1712c34 HEAD` under `frontend/` plus the QA specs): clean except `api-client.ts`, `auth.service.ts`, `mock-backend.ts`, the three known failures at HEAD (not counted).
- AC-1 stray-literal grep: 0 hits. `.only/.skip/xit/fit/xdescribe/fdescribe/.todo` grep: 0 hits.
- No PHP, composer or pest was run.

### Mutation testing (QA, production files restored after each, `git diff` clean afterwards)

| # | Mutation | Result |
|---|----------|--------|
| M01 | `PermissionService.hasAll` uses `some` | killed (QA matrix) |
| M02 | barcode form route drops `barcodes.manage` | killed (QA matrix) |
| M03 | list search not trimmed | killed |
| M04 | `SubmitState` busy guard removed | killed |
| M05 | server field names always treated as known | killed |
| M06 | product update sends `version: 0` | killed |
| M07 | confirm dialog does not return focus | killed |
| M08 | unit precision upper bound 5 | killed |
| M09 | menu also shows entries for a `manage`-only key | killed |
| M10 | refused delete also reloads the list | killed |
| M11 | barcode quantity 0 allowed | killed |
| M12 | `min == max` blocked | killed |
| M13 | SKU limit 51 | killed |
| M14 | PLU limit 11 | killed |
| M15 | Next not disabled on the last page | killed |
| M16 | modifier linked unit not required | killed |
| M17 | 403 shows the server text instead of the permission text | killed |
| M18 | products view guard removed from the catalog routes | killed |
| M19 | modifier form bypasses `SubmitState` (fresh instance per call) | killed (QA `double-submit.spec.ts`; the developers' specs would have missed it) |

Survivors: none. Note: the QA matrix cascades (one real failure turns many later cases red at 0 ms), so kill counts for M01, M02 and M18 are large; read them as "killed", not as a count of broken behaviours.

## Defects

### BUG-1 (major, open until Chunk 4) | AC-61 (and the README half of AC-27)
- Failing check: review of `frontend/README.md` (not touched by T-036).
- Expected: documents the catalog area and where its screens live; the permission key constants file and how the mock grants keys; the contracts as provisional pending B-1; the new mock-exclusion sentinels and the grep command; the Out-of-scope gaps.
- Actual: none of it present.
- Suspected location: Chunk 4 (plan step list), not yet merged.

### BUG-2 (minor, contract note) | AC-22
- Check: `packages/catalog` `UpdateProductHandler::FIELDS` and `Fields::only()` versus `ProductUpdate` in `contracts/catalog/catalog.requests.ts`.
- Expected: request field names match what the T-027 handlers accept.
- Actual: `UpdateProductHandler::FIELDS` has no `version`, and `Fields::only()` refuses unknown fields ("These fields are not accepted here: version."). The client always sends `version` on a product update (AC-46, D-12). The server has no stale check at all (plan "Spec vs code" 3). All other request shapes match the handler allow-lists (create and update of category, brand, unit, conversion, barcode, group, modifier; `stock_unit_id` and unit `dimension` correctly absent on update; no deferred columns). Not a defect against the mock-based AC, but B-1 must either strip `version` before the command or add it to the allow-list plus a real check. Recorded so F-4 does not rediscover it.
- Suspected location: `ProductUpdate` (contract), `UpdateProductHandler` (backend, B-1).

### BUG-3 (minor) | AC-8
- failing test: `projects/admin/src/app/catalog/catalog-edge-cases.spec.ts` > "BUG-3: tells the person when a picker lookup is refused with 403" (`it.fails`; remove `.fails` after the fix)
- expected: a lookup refused with 403 (a product manager who holds the products keys but not the categories keys) shows the permission message.
- actual: `EntityPicker.load()` swallows every error (`error: () => this.rows.set([])`); the select stays on "Select…", the required Category cannot be chosen, nothing explains why.
- Suspected location: `projects/admin/src/app/crud/entity-picker.ts` (`load`, error branch).

### BUG-4 (minor) | AC-19
- failing test: `catalog-edge-cases.spec.ts` > "BUG-4: keeps focus inside the page after a confirmed delete" (`it.fails`)
- expected: after a confirmed delete, focus lands somewhere sensible (the dialog promises to return focus to the trigger).
- actual: the list reloads, the trigger button is replaced, `ConfirmDialog.ngOnDestroy` skips focus because `trigger.isConnected` is false, and focus falls to `<body>`. Cancel, Escape and a refused delete are correct (tested).
- Suspected location: `crud/confirm-dialog.ts` (`ngOnDestroy`), `crud/delete-flow.ts` (no fallback target such as the table caption or search box).

### BUG-5 (minor) | AC-14
- failing test: `catalog-edge-cases.spec.ts` > "BUG-5: goes back to the last page that exists after deleting the last row of a page" (`it.fails`)
- expected: after deleting the only row of the last page, the list shows a page that exists.
- actual: `DeleteFlow` reloads page 2 unchanged, the server answers an empty page with `total: 25`, and the list shows "Nothing here yet" with a pager reading "Page 2 of 1" although 25 rows exist on page 1.
- Suspected location: `crud/list-controller.ts` (`send`/`load` does not clamp `page` to the returned total).

## Regression risks / notes

- The three `it.fails` cases are green today by design; the first fix that lands turns one red, which is the cue to drop `.fails`. They are not skips. If the lead prefers a strictly plain suite, delete the three cases and keep the bugs in this report.
- Contract provisional by design (D-3): pagination meta, `*_name`/`*_code` display fields, `permissions` on the tenant reply, PUT for updates, product `version`. F-4 must reconcile each.
- Client check is a convenience: permissions live in `sessionStorage`; a person can edit them to see menu entries, but every call is still server-checked (D-6), and the mock returns 403.
- Number parsing: barcode quantity, factor and modifier amounts use `Number()` in places, so `1e2` and `0x10` pass the client check and are sent as typed; the real server decides. Low risk.
- Dimension-narrowed pickers filter the first 25 rows client side, so in a tenant with more than 25 units the correct-dimension unit may need the search box. Convenience only (AC-36, AC-43), not a defect.
- A `manage`-only holder is correctly sent to `/forbidden` everywhere (QA matrix), but their lookups would also 403 (BUG-3).
- NOT VERIFIABLE: AC-21 (refresh not wired), AC-62 (real backend), AC-63 (real browsers, screen readers, layout, contrast). Nothing else is unverifiable.
- Environment: runs under load from another agent's concurrent builds twice failed with "Timeout waiting for worker to respond"; reruns passed. Not a product defect.

## QA-owned files created (all under `frontend/projects/admin/src/app/catalog/`)

- `permission-matrix.spec.ts` (282 tests): every one of 22 catalog routes against exactly-required keys, all keys, each single missing key, empty, absent, non-array stored values, the limited user, signed out, no company; every forbidden outcome asserts no HTTP request.
- `catalog-edge-cases.spec.ts` (11 tests, 3 `it.fails`): dialog focus and Escape on a real page, double-click delete, markup-as-text, 403 on delete, BUG-3/4/5 demonstrations.
- `double-submit.spec.ts` (14 tests): AC-18 on 7 forms plus resubmit after refusal.

History: Run 1: FAIL (BUG-1 pending Chunk 4; BUG-3, BUG-4, BUG-5 minor; BUG-2 note).
