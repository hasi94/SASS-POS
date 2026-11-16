# Code review: T-036 (FP-240, split) Admin shell, navigation and the catalog screens

Branch `ai/T-036-admin-shell-catalog-customers`. Reviewer: `reviewer36`, read-only, ran no tests or builds. Saved by the lead from the reviewer's report.

## Round 1 - tip `c9e0250` (Chunks 1 to 3, 91 files) - CHANGES REQUESTED, one Critical

Chunk 4 (README, the 4.1 integration specs, the 4.3 gate) was not in HEAD and was not reviewed.

### Critical

| ID | Finding | Disposition |
|---|---|---|
| R-1 | `product-detail-page.ts:283-298` with `product-barcodes-section.ts:417-419`: the detail page goes stale when you navigate from one product to another (the "Details" link on a variant row goes from `/catalog/products/<parent>` to `/catalog/products/<variant>`; same route config, so the component is reused and `ngOnInit` does not run again). The user sees the parent's fields, variants and barcodes; the "Edit product" link opens the parent's edit form; Delete and "Make primary" send `catalog/products/<variant>/barcodes/<parent-barcode-id>` | fix round: load whenever the id changes (effect or `switchMap` on the id, resetting `product` and `loadError`); recreate or reload the barcode section per product; add a spec that opens a parent, clicks a variant's "Details" and asserts the variant's SKU, a `GET catalog/products/<variant>` and an Edit href ending `/<variant>/edit` |

### Warnings

| ID | Finding | Disposition |
|---|---|---|
| W-1 | shared blocks copied into every screen: the delete-refusal panel pasted into 8 pages, the form-error plumbing repeated in 8 form pages, `POSITIVE_DECIMAL` copied in 3 files | fix round: use `<adm-api-error-panel [retryable]="false">` (hold the raw error in `DeleteFlow`), add a `FormErrors` class in `crud/`, move the decimal regexes into `crud/` |
| W-2 | unlinking a modifier on edit omits the three linked keys and relies on the mock treating "absent" as "clear"; the T-027 house pattern is "absent = unchanged" | fix round: send explicit `null` for the three linked keys, widen `ModifierWrite`, make the mock clear on explicit nulls |
| W-3 | `EntityPicker` swallows failures (`error: () => this.rows.set([])`): a user without the lookup keys gets silent empty pickers, against AC-8 | fix round: keep an `error` signal, render the message under the select, add a spec with a limited key set |
| W-4 | barcode quantity check uses `Number(quantity)` (accepts `0x10`, `1e3`, `0b1`) | fix round: reuse the shared decimal regex |
| W-5 | contracts and mock assume backend behaviour T-027 does not have: a real update carrying `version` would be refused (`version` is not in `FIELDS`); `CatalogRefused` carries no `field`, so field placement works only against the mock; `DeleteUnit`, `UpdateModifierGroup`, `UpdateModifier`, `GetBarcode` do not exist | document in the README and in the backend follow-up row (B-1) |
| W-6 | permissions: `SelectTenantResult.permissions?` is a mock-only field; against today's real backend the set is always empty, so every catalog entry is hidden; keys are restored from `sessionStorage` without re-checking (revoked keys stay until the company is chosen again) | document both in the README (chunk 4.2); B-1's permission source must be refreshable on restore |
| W-7 | a guard does not stop a lazy chunk from downloading: the area routes use `canActivate` with `loadChildren`, so a forbidden user still downloads the area's routes chunk (nothing leaks: no component, no HTTP) | fix round: add `canMatch: [requirePermissions(...)]` next to `canActivate` for the five areas, returning the same `/forbidden` UrlTree; AC-6's wording follows |
| W-8 | screen-reader live regions are inserted already holding text rather than updated; when client validation blocks submit, focus does not move | fix round: one always-rendered empty `role="status"` element per list/form; focus the summary or first `[aria-invalid="true"]` control |

### Suggestions
S-1 `track row` in `list-view.ts` recreates every row on reload and drops focus after a delete (track by id, fallback focus target); S-2 after deleting the last row of the last page step back a page; S-3 tie `SubmitState.run` and the `load()` subscriptions to `DestroyRef` (a late success handler pulls the user back); S-4 when editing a variant fetch the parent by id instead of only the first 25 matches; S-5 hide the empty "Actions" column when the user lacks `manage`; S-6 T-037 keeps its own catalog id shapes next to T-036's `CatalogId`; record for F-4.

### Merge overlap with sibling branches (read-only check)
No exported-name collisions with T-035 (`Pricing*`) or T-037 (141 names under `lib/sales/**` and `lib/ids/**`). Conflicts to expect: `frontend/projects/shared/src/public-api.ts` (all three append at the end; T-035 also edits the header comment) and `frontend/README.md` (T-035 now, T-036 in chunk 4). `mock-backend.ts` only T-036; `environment.development.ts` admin (T-036) versus pos (T-037); `auth/*`, `api/*`, `angular.json`, `package.json`, `tsconfig.json` untouched by the siblings. URL spaces: `till/products` (T-037) against `catalog/*` (T-036).

### Done well
Default-deny `PermissionService`, cleared on login, clearContext and logout; `view` and `manage` required on every form and nested route; the AC-1 grep clean; the mock lazy with no top-level calls and `sideEffects: false`; the production environment imports nothing; the contracts match T-027's `FIELDS` (read-only edit fields never sent); `getWithMeta` leaves `unwrap` unchanged; the specs assert real behaviour.

### NOT VERIFIED
AC-27 (sentinel absent from production bundles), AC-58 and AC-59 (green suite, budgets), whether any spec passes, the chunk split for AC-56, AC-61 (README) and the Chunk 4 specs, real-browser or screen-reader behaviour (AC-63).
