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

## Till (T-037)

`pos` at `/till` is the sale screen: shift, product grid and search, scanning, cart, customer,
payment, hold and recall, void, refund and supervisor approvals, all from the keyboard. **The
till is online-only and is not connected to a real backend.** In development it talks to an
in-memory mock of the Sales API; there is no Sales route layer yet, so a production build of the
till cannot sell anything (a follow-up row wires the real routes). When the network is lost it
shows a banner, pauses every write and queues nothing.

### Architecture seams

- **Ports and adapters (D-5, P-10).** The till needs exactly two things it does not own: pricing
  a line and resolving a scanned code. They are the injection tokens `LINE_PRICER` (`LinePricer`)
  and `BARCODE_RESOLVER` (`BarcodeResolver`) in `projects/shared/src/lib/sales/ports.ts`. Their
  default factories return the server-backed adapters (`ServerLinePricer`,
  `ServerBarcodeResolver`), which delegate to `SalesApi`. No component or store names an adapter,
  the mock or the mock's arithmetic; `money-rules.spec.ts` fails the build if one does. To swap
  one, provide another implementation: `{ provide: LINE_PRICER, useValue: myPricer }` (a spec, or
  the future pricing engine and barcode parser, with no component change).
- **Money is a string (D-6).** Every money and quantity value is a branded decimal string
  (`Money`, `Quantity`) exactly as the server sent it. The till does no arithmetic on them: the
  only helper is `projects/shared/src/lib/sales/decimal-string.ts` (validate, compare, format).
  `money-rules.spec.ts` scans the till and the sales library for float parsing, `Number(`,
  `toFixed`, `Math.round`, locale formatters and `+` next to a money field, and a canary file
  proves the scan can fail.
- **The mock is development only.** `salesMockInterceptor` is installed by
  `projects/pos/src/environments/environment.development.ts` in front of the auth mock; `admin`
  never gets it. It keeps its state in memory per tenant and branch, enforces the Sales rules the
  till depends on, does its arithmetic in exact integers (`sales-mock/mock-decimal.ts`), and
  derives the terminal from the branch (`TRM-<branchId>`), so the wire carries no branch,
  terminal or business date.

### The contract and its fixtures

- Types, the route table (`calls.ts`), the closed refusal union and its HTTP statuses
  (`refusals.ts`): `projects/shared/src/lib/sales/contract/`. `calls.ts` also holds the coverage
  table: the case names each call must have a fixture for.
- Fixtures: `projects/shared/src/lib/sales/contract/fixtures/<call-id>/<case>.json`, with case
  names `success`, `success.<variant>` and `refused.<code>[.<variant>]`. A fixture is
  `{ call, case, given, request: {method, path, body}, response: {status, body} }`; `given` is a
  list of named mock steps (`sales-mock/scenarios.ts`, for example `shift_open`,
  `draft_sale_with_line`) applied to a fresh mock before the request is sent.
- `contract-fixtures.spec.ts` replays every fixture through the real `HttpClient` stack and
  requires the answer to equal the fixture: the same status and the same JSON text (key order and
  every character of every value count, file whitespace does not), and each success body to parse
  against the TypeScript types. The same files are the conformance suite the real route layer is
  run against later (it will normalise `meta.request_id`, server-generated ids and timestamps).
- **Proposed calls (P-7).** These have no T-030 slice yet and are marked `proposed` in
  `calls.ts`: attach a customer to a draft (`PUT sales/{id}/customer`), the read-only refund quote
  (`POST refunds/quote`), a client `id` on `POST refunds` as its idempotency key, the
  server-derived `refundable_quantity` on a sale line, and the short `reference` of an unnumbered
  sale.
- **The tender rule (P-1).** The till sends `{ id, payment_method_id, tendered, reference? }` and
  never an `amount` or `change_given` (the request type has no such key). The server applies
  `tendered` up to `balance_due` and, on a method that allows change, hands the rest back as
  `change_given`; without `allows_change` an over-tender is refused `exceeds_balance`. The
  fixtures `sales.add-tender/success.cash-over-tender.json` and
  `sales.add-tender/refused.exceeds_balance.card-over-tender.json` pin the rule for whoever builds
  the real route.

### Keyboard

One file, `projects/pos/src/app/till/keyboard/keymap.ts`; `?` or `F1` shows the list in the till.
`F5`, `F11` and `F12` are left to the browser.

| Key | Action |
|---|---|
| `F2` | Pay |
| `F3` | Hold the sale |
| `F4` | Recall a held sale |
| `F6` | Customer |
| `F7` | Refund |
| `F8` | Void a sale |
| `F9` | Shift menu |
| `/` (empty scan field) or `Ctrl+K` | Search products |
| `Up` / `Down` | Select the previous / next cart line |
| `Delete` | Remove the selected line |
| `Enter` | Confirm the primary action |
| `Esc` | Cancel and return to the scan field |
| `?` or `F1` | Show the shortcut list |
| `N*` then a code (for example `3*`) | Scan that many (1 to 999) |

### Demonstration data of the sales mock

Everything is fictional; the employee codes and PINs are made up for the mock.

- **Cashier:** the existing demo sign-in (`demo@flexipos.test` / `demo1234`, Demo Owner), whose
  employee code is `D001`, PIN `1111`. Entering it in the supervisor dialog is the
  self-approval case.
- **Supervisor:** code `S100`, PIN `2468` (Supervisor Silva). **Not an approver:** `C200`, PIN
  `1357`. Any other code or PIN is "Code or PIN not recognised".
- **Products** (`sales-mock/mock-seed.ts`): Rice 1kg (barcode `4790000000011`, PLU `101`), Mineral
  Water (barcode `4790000000028`; a box of six with its own barcode `4790000000035`, which carries
  the unit and quantity), Loose Tomatoes (weighable, PLU `201`; the scale label `2100201012504`
  carries 1.250 kg, and the price-embedded label `2200201012504` is refused as unsupported),
  Sample Sachet (zero priced, PLU `301`), Custom Poster (price override allowed, PLU `401`),
  Laundry Soap, a Tee Shirt with size variants, products that are inactive or not sellable, and 60
  bulk items so the grid has a second page.
- **Customers:** `CUS-0001` Nimal Perera with store credit, `CUS-0002` Kamala Silva without.
- **Payment methods:** cash (gives change), card (needs a reference), store credit (needs a
  customer), loyalty points and voucher (listed, refused `unsupported_method_type` by the server).
- **Discounts:** `SAVE10` (10%), `MGR20` (needs a supervisor), `HOME5` (household only), `USED0`
  (already used up: completing a sale that carries it is refused `usage_limit_reached`), `OLD10`
  (inactive).
- **Sales:** the mock starts with none. A shift is opened at the till, and sales, a held sale and
  completed sales on the open shift appear as you make them. A held sale from another terminal, a
  completed sale on an earlier, closed shift and the like exist only as named steps for specs
  (`held_sale_other_terminal`, `completed_sale_closed_shift`, ...).

### Known limits and follow-ups

- **A void whose answer is lost** shows "Cannot reach the server: sale actions are paused" and
  leaves the dialog open. Unlike a line write or a tender, the till does not reload the sale by
  itself when the network returns, so the cashier presses Void again (the server refuses a second
  void with `invalid_transition`, which the dialog shows). Follow-up: reload the sale after a
  lost void, as a lost line write does.
- **Two wordings of `exceeds_balance`.** The refusal catalogue keeps the AC-76 text, "More than
  the balance". The payment dialog shows the AC-44 text, "Amount is more than the balance", for
  an over-tender on a method that gives no change, and the refund dialog words it as owed back.
  Each is pinned by its own spec (`refusal-catalogue.spec.ts`, `payment-dialog.spec.ts`,
  `refund-store.spec.ts`).
- **A draft the till made but never used** can be left on the server: a sale is created on the
  first scan or tile, so closing the till straight after (or a lost answer to `createSale`)
  leaves an empty draft that shows in the shift's open sales. Follow-up: have the sale store
  discard or reuse such an orphan draft.
- **No sales in the mock seed** (see above): every sale a spec or a demo needs is made at the
  till or by a named `given` step.
- **While offline** the banner shows and the write controls are disabled; a void, refund,
  supervisor or shift dialog already open keeps its text but its submit button is disabled until
  the till is back (it re-probes every 5 seconds; "Retry now" asks at once).
- **A timeout counts as a lost answer.** Status 0 also covers a client timeout, so the first
  request may still be running on the server when the cashier presses "Send again". Sending again
  with the same ids is safe (the server replays), which is why the dialog only ever replays.
- **An approval approved after the cashier cancelled** stays on the server, single-use and bound
  to its sale, or to goodwill refunds, and is simply never used.
- **A lost refund answer freezes the refund.** When a refund gets no answer the dialog shows only
  the frozen refund (lines, total, method) with "Send again" and "Close". Nothing can be edited,
  no mode or sale can be chosen, and the till never reads the sale back to decide. "Send again"
  re-sends the exact request with the same refund and tender ids, without checking the form again,
  using the held approval if it is unspent and asking a supervisor afresh if it went stale; it
  stays waiting until a definite answer (a refusal returns to the editable form). A cancelled
  supervisor dialog keeps it waiting. Closing the dialog forgets a lost refund (a refund made
  again afterwards gets new ids), so the cashier checks the sales list first.
- **A late answer after close is ignored.** An answer that arrives after its dialog was closed
  and another opened does not change the new dialog; the refund it carried is on the server, so
  the sales list is where to look.
- **Backend follow-up: replay before validation.** The till relies on `POST /refunds` answering a
  replay (same refund id) with the stored refund before it validates the approval or the
  quantities, because the approval is spent and the sale's refundable quantities have moved by then.
  The mock does this; the real backend must check replay first. Not verified against the real
  backend.
- **Not checkable in jsdom:** the 44 by 44 px targets, the focus ring, WCAG contrast and the
  1024 by 768 layout. They are reviewed in the SCSS (`min-height: 44px`, tokens from
  `base.scss`) and need a browser check.

### Keeping the mock out of production

The sales mock carries its own sentinel next to the auth mock's. Every answer it gives has
`meta.request_id` starting `flexipos-sales-mock-`, in code the interceptor always runs, so the
string is in a bundle exactly when the mock is. The two sentinels are `demo@flexipos.test` (auth
mock) and `flexipos-sales-mock` (sales mock). After a production build both must be absent:

```bash
npx ng build pos && npx ng build admin
grep -l -e "demo@flexipos.test" -e "flexipos-sales-mock" dist/pos/browser/*.js dist/admin/browser/*.js   # must print nothing
```

The CI step "Mock backend absent from both production bundles" runs the same check.
