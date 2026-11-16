# T-037: Till application — product grid, scanning, cart, payment, refund (frontend, against a mock API)

Backlog: FP-236, "Till application: product grid, scanning, cart, payment, refund" (FE), widened by
T-006 (scan resolves through `barcode_rules`; weighable lines entered by weight). Decisions D-1 to D-30
are in `docs/decisions/T-037-till-application.md`. **No BLOCKING questions** (team mode: every open
question was decided and logged; the owner-visible ones, each with a built default, are in the last
section).

**This row was split (D-1).** FP-236 as written spans the whole till *and* the Sales HTTP route layer
that T-030 deferred to it. T-037 is the **Angular till, built against a mock API with typed contracts,
online-only**. The backend Sales route layer, the till's catalogue/scan read API, and the real-backend
switch-over are proposed as separate rows (see "Proposed follow-up rows"). Offline (FP-237/FP-238) and
the TypeScript calculation engine (T-035 / FP-432) are out of scope; the till reaches the engine and the
barcode parser through ports (interfaces) and ships only server-backed adapters.

Convention, following T-021 to T-025: prefer an acceptance criterion a real, running check can prove
(`cd frontend && npm test`, `ng build pos`, `ng build admin`, the mock-exclusion grep) over anything
needing live infrastructure. Anything that cannot be verified without a real backend or hardware is
fenced **NOT VERIFIABLE** with the reason.

Inputs read: `CLAUDE.md`; `docs/backlog.md` (FP-236, FP-233, FP-237, FP-238, FP-432, FP-423, FP-451,
FP-452, FP-440, FP-443); `docs/specs/T-030-sales.md` and `docs/decisions/T-030-sales.md` (rev 8: the sale
lifecycle, approvals, refund caps, cash shifts, refusal codes); `docs/specs/T-027-catalog.md` (weighable
flags, PLU, `price_mode`, barcodes); `docs/specs/T-006-unit-of-measure.md` (AC-59, `barcode_rules`);
`docs/specs/T-029-taxes-pricing.md` (engine, fixtures); `docs/specs/T-025-frontend-bootstrap.md` and the
shipped `frontend/projects/{shared,pos,admin}` (ApiClient, envelope, AuthService, mock backend, plugin
routes, `TillPage` shell).

## Problem / goal

A cashier needs a till screen on which to ring up a sale quickly and without a pointer: find products on
a grid or by scanning, see a cart whose prices and totals are exactly what the server computed, take one
or more tenders, hold and recall a sale, void or refund when something goes wrong with a supervisor's
approval, and open and close their cash drawer. Today `pos` has only a frame (`TillPage`: who is signed in
and two empty areas) and the backend Sales domain (T-030) has no HTTP routes, so there is nothing for a
till to call. This task builds the till so that it is complete and testable now against a mock that speaks
a typed, fixture-backed contract, and so that pointing it at the real routes later is a configuration
change plus a contract-conformance run, not a rewrite.

## Users affected

- **Cashiers** (primary): scan, key, pay, hold, recall, void their own sales; open and close their shift.
- **Supervisors/managers**: decide approvals (void of a completed sale, refund, price override, discount
  needing approval) by entering their employee code and PIN at the cashier's till.
- **Developers of the follow-up rows**: the typed contract and its fixtures are the specification the
  backend route layer is built and tested against.
- **Customers** (indirectly): a store-credit tender needs a customer attached; a refund pays back what
  they paid.
- Not served by this task: no real sale reaches a real database (mock only); no offline operation; no
  receipt printing; back-office users (shift reconciliation, reports).

## Current behaviour

- `frontend/projects/pos/src/app/till/till-page.{ts,html,scss}` is a shell: it shows the signed-in
  context and a "switch branch" and "sign out" button; its own comment says the product grid, scanning and
  cart "arrive with FP-236". Route `/till` is behind `contextGuard`.
- `@flexipos/shared` provides `ApiClient` (envelope-unwrapping `get/post/put/delete`, plus `postRaw` for
  the pre-envelope auth endpoints), `ApiError` (`errors[]` with `code`/`field`/`detail`, `request_id`),
  `AuthService`/guards/interceptor, `LoginPage`/`ContextPage`, plugin route registry, and a development-only
  `mockBackendInterceptor` that answers auth only and returns 404 `not_found` for every other `/api/v1/`
  path. It is excluded from production builds (checked by `grep -l demo@flexipos.test dist/<app>/browser/*.js`).
- No sales, catalogue-read or payment-method code exists in the frontend. No decimal/money utility exists.
- Backend (T-030): the Sales slices exist as handlers; **most have no HTTP routes** (deferred to this
  row). The branch-scope and permission checks inside each write slice (review W-5, FP-452) and approval
  hardening (review W-2, FP-451) are open. Catalogue has no Get/List HTTP surface (FP-240); the TypeScript
  barcode parser (FP-423) and engine (FP-432/T-035) are not built.

## User stories

- As a cashier, I want the barcode field focused and ready at all times and every sale action reachable by
  keyboard, so that I can ring up a queue without touching the mouse.
- As a cashier, I want scanning a barcode, a PLU or a scale label to add the right line with the right
  quantity, so that I never key a weighed item's quantity twice.
- As a cashier, I want to enter a weight for a weighable product that has no scale label, so that loose
  goods can still be sold.
- As a cashier, I want a product grid with category tabs and search, so that items without a barcode can
  be found and added.
- As a cashier, I want a cart that shows exactly the server's line prices, discounts, tax and totals, so
  that what I show the customer is what is charged.
- As a cashier, I want to take part cash, part card (as a reference) and part store credit on one sale,
  so that a customer is not forced into several transactions.
- As a cashier, I want to hold a sale and recall it later, possibly at another till, so that a queue is
  not blocked.
- As a cashier, I want to void my unfinished sale, and (with a supervisor) a completed sale on my open
  shift, so that mistakes leave the right trace.
- As a cashier, I want to refund a sale fully or in part, or give a goodwill refund, with a supervisor's
  approval, so that returns are handled as their own documents.
- As a supervisor, I want to approve or reject a request by typing my code and PIN at the till, so that
  sensitive corrections are never a cashier acting alone.
- As a cashier, I want to open my drawer with a counted float and close it with a counted figure, and to
  record cash in/out movements in between, so that variance is visible the same day.
- As a cashier, I want every refusal explained in plain words with the next step, so that I can recover
  without asking for help.
- As a developer, I want typed request/response contracts with checked-in fixtures, so that the backend
  route layer can be built and verified against the exact shapes the till uses.

## Acceptance criteria

Conventions for every AC: "the server" means the mock API in this task and the real route layer later;
"a money string" is the decimal string the server sent (for example `"1250.0000"`); "a quantity string"
likewise. Verification is by `npm test` (Vitest through `@angular/build:unit-test`, component specs
driven through the mock) unless an AC says otherwise.

### A. Typed contracts, mock API and architecture seams

- AC-1: Given the shared library, When it is built, Then it exports a typed contract for every call the
  till makes (request body, response body, query parameters) covering: shifts (open, current, close,
  cash movement), sales (create, get, list by status/number/shift, add/update/remove line, add/remove
  tender, complete, hold, recall, void), approvals (request, decide), refunds (including goodwill),
  payment methods list, till product list and search, scan resolution, applicable discounts list, and
  customer lookup. The contract types sit in one place, and no component declares an ad-hoc response
  shape.
- AC-2: Given the contract, Then every money and quantity field is a branded decimal-string type (not
  `number`), and the contract's field names are the T-030 column names (`unit_price`, `line_total`,
  `balance_due`, `change_due`, `paid_total`, `base_quantity`, `refunded_quantity`, `counted_cash`,
  `expected_cash`, `variance`, and so on), so that the backend follows the same names.
- AC-3: Given the contract, Then `SalesRefusalCode` is a closed string union containing exactly the
  refusal codes the Sales domain can return that the till can meet: `no_open_shift`, `not_found`,
  `unit_unresolvable`, `unresolvable_price`, `price_override_not_allowed`, `discount_with_override`,
  `approval_required`, `approval_not_pending`, `approval_already_used`, `approval_not_approved`,
  `approval_mismatch`, `self_approval_not_allowed`, `pin_invalid`, `not_authorised`, `sale_not_editable`,
  `customer_required`, `unsupported_method_type`, `balance_due`, `exceeds_balance`, `credit_refused`,
  `usage_limit_reached`, `numbering_refused`, `shift_closed`, `shift_already_open`, `shift_not_open`,
  `shift_has_open_sales`, `invalid_transition`, `over_refund`, `fully_refunded`, `inherited_unit_mismatch`,
  `not_refundable`, `product_not_sellable`, `replay_conflict`, `in_use`, `out_of_range`, `validation`; plus
  the engine reasons passed through (`unknown_product`, `no_effective_rate`, `not_applicable`,
  `inactive`, `unknown_discount`, `discount_not_allowed`).
- AC-4: Given a checked-in contract fixture set (JSON: one success and every refusal per call), When
  `npm test` runs, Then a contract test asserts the mock API's response to each fixture request equals
  the fixture byte-for-byte, and that each fixture parses against the TypeScript types. The same fixture
  files are the conformance suite the real route layer is later run against (follow-up row 1).
- AC-5: Given the mock API (development-only, in `@flexipos/shared`, installed through the existing
  mock interceptor), When the till runs in development, Then the mock keeps in-memory state and enforces
  the Sales rules the till depends on: the sale state machine (`draft`, `pending_payment`, `held`,
  `completed`, `voided`, refunds), `no_open_shift`, one open shift per terminal, `sale_not_editable`
  once `pending_payment` or held-from-`pending_payment`, `balance_due` unless tenders equal the total,
  `customer_required` for a credit tender, `unsupported_method_type`, `exceeds_balance`, approval
  binding (type, reference, branch) and one-time consumption, `self_approval_not_allowed`, `pin_invalid`
  (a fixed demo PIN), `over_refund`/`fully_refunded`, `shift_closed` for a void after the shift closed,
  `shift_has_open_sales`, and `replay_conflict` on a re-used tender id with a different payload.
- AC-6: Given the mock prices lines, When it computes any money value, Then it does so with exact
  integer-scaled decimal arithmetic (never JS floating point) and returns four-decimal strings; this
  arithmetic exists only under the mock and is never imported by application code.
- AC-7: Given the till's engine and barcode needs, Then the till depends only on two ports: `LinePricer`
  (price a line / preview) and `BarcodeResolver` (scan code to a resolved product, unit and optional
  quantity). This task ships one adapter each, which calls the server (add-line prices on the server;
  scan resolution is a server call). No UI component references an adapter, the mock, `PricingEngine`
  logic or barcode-rule parsing directly. Substituting a different adapter (the future T-035 engine,
  FP-423 parser) requires no change to any component (proved by a spec that swaps in a stub adapter).
- AC-8: Given a production build of `pos` or `admin`, When `grep -l demo@flexipos.test
  dist/<project>/browser/*.js` and the same grep for the sales mock's own sentinel string (documented in
  `frontend/README.md`) run, Then both print nothing; the sales mock is as excluded from production as the
  auth mock, and the CI mock-exclusion step greps both sentinels.

### B. Cash shift

- AC-9: Given the signed-in till has no open shift, When the till opens, Then the sale screen is read-only
  and a shift-open prompt is shown asking for the opening cash; the prompt is keyboard operable and its
  field is focused.
- AC-10: Given the opening-cash prompt, When the cashier submits a valid amount, Then an open-shift call
  is made and on success the shift number is shown in the header and the sale screen becomes active. An
  empty, non-numeric, negative or more-than-four-decimal amount is rejected in the form before any call
  is made, with a message naming the field.
- AC-11: Given the terminal already has an open shift (server `shift_already_open`), When the till opens,
  Then it loads and uses that shift instead of showing an error.
- AC-12: Given an open shift, When the cashier records a cash movement (`cash_in`, `cash_out`,
  `float_in`, `float_out`, `drop`, `petty_cash`, `bank_deposit`) with an amount and a reason, Then the
  call carries a client-generated id (D-12); a failed or timed-out submit retried by the cashier reuses
  that same id; the amount field is positive-only (the sign is the type's meaning, shown as an
  "adds to / takes from drawer" hint next to the type).
- AC-13: Given the cashier chooses Close shift, When the close form opens, Then it asks for the counted
  cash **without showing the expected cash** (blind count, D-16); it submits `counted_cash` and, on
  success, shows the server's `expected_cash`, `variance`, `sales_count`, `refunds_count`, `net`
  and `totals_by_method` as received.
- AC-14: Given close is refused `shift_has_open_sales`, Then the till lists the draft/pending-payment
  sales on the shift (from the server) and offers to open, complete, hold or void each; a held sale does
  not appear in that list.
- AC-15: Given the shift was closed, When the cashier returns to the sale screen, Then no sale can be
  started until a new shift is opened, and any in-flight call refused `no_open_shift` or `shift_not_open`
  routes to the shift-open prompt (AC-9) without losing the cart view.

### C. Product grid and search

- AC-16: Given an open shift, When the till loads, Then the product grid shows the first page of sellable
  products for the branch from the server's till product list, with category tabs (all, then each
  category) and each tile showing name and the server-supplied list price string, labelled as the list
  price (the cart price may differ).
- AC-17: Given a large catalogue, Then the grid is paginated from the first version (cursor/offset as the
  contract specifies) with a page size of 50, loads the next page on scroll or on a Load more control, and
  never requests the whole catalogue.
- AC-18: Given the search field (focus with `/` or `Ctrl+K`), When the cashier types name, SKU, PLU or
  barcode text, Then results come from the server (debounced 250 ms, previous in-flight request
  superseded), an empty result shows "No products found", and Enter on a highlighted result adds it.
- AC-19: Given the grid has focus, Then arrow keys move between tiles, Enter adds the focused product with
  quantity 1 (or opens the weight prompt for a weighable product, AC-26), tiles are at least 44 by 44 CSS
  pixels, and the focused tile has a visible focus indicator.
- AC-20: Given the catalogue request fails (network or server), Then the grid shows an error with a Retry
  control and the rest of the till (scan field, cart) stays usable.

### D. Scanning

- AC-21: Given the sale screen is shown, Then the scan field has focus on load, and focus returns to it
  after every add, dialog close, tender and cancel, unless the cashier deliberately moved focus into
  another text field in the same dialog.
- AC-22: Given a typed or scanned code followed by Enter (a hardware scanner sends characters then Enter),
  When it is submitted, Then the code is sent to scan resolution unchanged (leading/trailing whitespace
  trimmed, nothing else altered), and the field clears immediately so the next code can be entered.
- AC-23: Given scan resolution returns a product (an exact barcode hit, and a barcode carrying a pack
  `quantity`/`unit_id` yields that quantity and unit), When the till adds the line, Then the add-line
  call carries the product id, the resolved unit id and quantity as strings, and the new line shows the
  server's figures.
- AC-24: Given scan resolution identifies a scale label of kind `embedded_weight` (via `barcode_rules`),
  When the line is added, Then the quantity and unit are those the resolution returned, the cashier is not
  asked for a weight, and the till did not derive them itself (resolution order is the server's/the
  parser's: exact `product_barcodes` lookup first, then rules by priority, per `docs/database.md` §6.3).
- AC-25: Given scan resolution identifies a label of kind `embedded_price`, Then the till refuses it with
  the message "Price-embedded labels are not supported yet" and adds nothing (D-14: T-030 only writes
  `price_from_label = false`), and the scan field keeps focus.
- AC-26: Given a weighable product (`is_weighable`) added from the grid or search or by a code that gives
  no quantity, When it is chosen, Then a weight prompt opens, focused, showing the product name and the
  weighing unit, accepting a positive decimal string with at most four decimal places; Enter confirms,
  Esc cancels with nothing added. Zero, negative, empty, non-numeric and over-precision input are rejected
  in the prompt with a message and nothing is sent.
- AC-27: Given a scan the server cannot resolve, Then an unmissable but non-blocking message "Code not
  found: <code>" is shown with an audible-capable alert region (AC-84), nothing is added, and the field
  stays focused.
- AC-28: Given several scans arrive in quick succession while an add-line call is pending (for example
  three codes within 50 ms), Then the till queues them and issues the calls strictly in arrival order, one
  at a time; none is dropped or reordered, each shows an immediate "adding" row within 100 ms of entry
  (before the response), and a refusal on one does not discard the codes queued behind it.

### E. Cart

- AC-29: Given no sale exists yet, When the first line is added, Then the till first creates the sale
  (customer optional) and then adds the line; opening the till or pressing a key never creates a draft by
  itself (D-9).
- AC-30: Given a sale with lines, Then each line shows product name, quantity with its unit code, unit
  price, discount (when non-zero), tax, and line total, and the footer shows subtotal, discount total, tax
  total, total, and (for `pending_payment`) paid and balance due; every figure is the server's string,
  rendered per D-7, and none is computed in the till.
- AC-31: Given a line is selected (arrow keys or click), When the cashier changes its quantity or unit,
  Then an update-line call is made, the line is replaced by the server's response, and quantity zero or
  less, empty or invalid input is rejected in the form first (the server's `validation` is the backstop).
- AC-32: Given a line, When the cashier removes it (Delete key or Remove control, with a confirm on
  Enter), Then a remove-line call is made and the cart shows the server's recomputed totals.
- AC-33: Given the sale is `pending_payment` or recalled from `pending_payment`, Then line edit and remove
  controls are disabled with the reason "Payment has started: remove the tenders to edit lines"; if the
  server refuses `sale_not_editable` anyway the till shows that same message and reloads the sale.
- AC-34: Given a line action "Price override", When the cashier enters a unit price string, Then the till
  requests a `price_override` approval bound to `{product_id, unit_id, unit_price}` (AC-65) and, once
  approved, sends the update-line call carrying the approval id; `price_override_not_allowed` and
  `discount_with_override` are shown with their messages (AC-76) and change nothing.
- AC-35: Given a line action "Discount", When the cashier picks a discount from the server's applicable
  discounts list, Then the update-line call carries its `discount_id`; for a discount the server marks as
  requiring approval the till requests a `discount` approval (AC-65) first, and a refusal
  `approval_required` is handled the same way. A line with an override cannot be given a discount (the
  Discount action is disabled with the reason).
- AC-36: Given an add or update refused `unresolvable_price`, `unit_unresolvable`, `product_not_sellable`
  or an engine reason, Then nothing is added or changed, the cart shows the previous server state, and
  the refusal is shown per AC-76.
- AC-37: Given a line whose server price is `0.0000`, Then the line is shown with a visible "Zero price"
  marker (T-030 Q-2: unpriced products can ring up free); it is not blocked (default, owner question
  Q-4).
- AC-38: Given an empty cart, Then Pay and Hold are disabled and the cart shows "Scan or choose a
  product"; and given a sale with lines the cart is a labelled list region whose line count is announced
  to assistive technology when it changes.

### F. Customer on the sale

- AC-39: Given a sale or no sale yet, When the cashier opens Customer (key `F6`) and enters a code, phone
  or name, Then results come from the server's customer lookup; choosing one attaches it (creating the
  sale first when none exists, AC-29), and a customer unknown, deleted or merged (server `not_found`) is
  shown as "Customer not found".
- AC-40: Given no customer is attached, Then the store-credit payment method is shown but disabled with
  the reason "Attach a customer to use store credit"; and if the server refuses `customer_required` the
  same message is shown.

### G. Payment

- AC-41: Given a sale with lines, When the cashier presses Pay (`F2`), Then a payment dialog opens listing
  the server's active payment methods (the types cash, card, store credit are enabled; `loyalty` and
  `voucher` methods are not offered, matching `unsupported_method_type`), with the amount focus on the
  first method and the sale total and balance due shown.
- AC-42: Given a payment method is chosen, Then the tendered-amount field defaults to the server's
  `balance_due` string (the string is copied, not parsed), is editable, and Enter submits; a method with
  `requires_reference` shows a required reference field and refuses an empty reference in the form.
- AC-43: Given a tender submit, Then the call carries a client-generated tender id (D-12), the payment
  method id and the typed tendered string (and the reference where required); the till sends no `amount`
  or `change_given` of its own (D-8); the dialog then shows the server's tender row, `paid_total` and
  `balance_due`.
- AC-44: Given a cash tender above the balance on a method that allows change, Then the dialog shows the
  server's `change_due` prominently as "Change", and given a method that does not allow change the server's
  `exceeds_balance` is shown as "Amount is more than the balance".
- AC-45: Given a part payment, Then the sale shows `pending_payment` with the server's remaining
  `balance_due`, and the cashier can add another tender of a different method (split payment); the dialog
  stays open and focuses the amount field again.
- AC-46: Given a tender is wrong, When the cashier selects it and removes it while the sale is
  `pending_payment`, Then the remove-tender call is made and `paid_total`/`balance_due` update from the
  response; removing the last tender returns the sale to `draft` as the server reports.
- AC-47: Given the balance due is `0.0000` (or a zero-total sale with at least one line), When the
  cashier presses Complete (Enter on the Complete control), Then the complete call is made and, on
  success, the till shows a completion screen with the sale `number`, total, tenders and change due, and
  a "New sale" control (`Enter`) that clears the cart, resets focus to the scan field and starts no
  draft (D-9).
- AC-48: Given Complete is refused `balance_due`, Then the dialog stays open and shows the remaining
  amount from the server; given `credit_refused`, `usage_limit_reached` or `numbering_refused`, Then the
  message names the cause supplied with the refusal (AC-76), the sale stays `pending_payment`, and the
  cashier can fix the tenders and retry.
- AC-49: Given a tender submit whose response is lost (timeout or connection drop), When the cashier
  retries, Then the retry re-sends the **same** tender id and payload (so a duplicate is impossible), and
  before retrying the till reloads the sale and, if the tender is already there, shows it rather than
  sending again (D-11).
- AC-50: Given a submit is in flight, Then the submit control is disabled and the key binding is ignored
  until the response arrives, so a double Enter cannot send two tenders under different ids.

### H. Hold and recall

- AC-51: Given a draft or pending-payment sale, When the cashier presses Hold (`F3`), Then the hold call
  is made, the cart clears, focus returns to the scan field, and a confirmation shows the held sale's
  short reference.
- AC-52: Given the cashier presses Recall (`F4`), Then the till lists held sales for the whole branch
  from the server (paginated; not just this terminal's), each row showing reference or number, time held,
  held by, customer where present and total; Up/Down selects and Enter recalls.
- AC-53: Given a held sale is recalled, Then it appears in the cart in the status the server reports (a
  sale held from `pending_payment` returns to `pending_payment`, and its line controls are disabled per
  AC-33); recalling needs an open shift on this terminal and `no_open_shift` is handled per AC-15.
- AC-54: Given a sale is already in the cart when the cashier recalls another, Then the till asks to hold
  or keep the current sale first (an empty cart needs no question), and never discards a sale silently.

### I. Void

- AC-55: Given a draft, held or pending-payment sale, When the cashier chooses Void (`F8`) and gives a
  reason, Then the till calls void with no approval, shows the voided result, and clears the cart; an
  empty reason is rejected in the form.
- AC-56: Given the cashier chooses Find sale and opens the list of this shift's completed sales (by
  number or the latest 50), When a completed sale is chosen and Void is pressed, Then the till requests a
  `void` approval bound to that sale (AC-65), and on approval calls void with the approval id, showing the
  server's result.
- AC-57: Given a void of a completed sale is refused `shift_closed`, Then the till explains that the sale's
  shift is closed and offers to start a refund for it instead (opening the refund flow with that sale).
- AC-58: Given a void is refused `self_approval_not_allowed`, `pin_invalid`, `not_authorised`,
  `approval_*` or `invalid_transition`, Then each is shown per AC-76 and the sale is unchanged.

### J. Refund

- AC-59: Given the cashier chooses Refund (`F7`), When they enter a receipt/sale number or pick from the
  shift's completed sales, Then the till loads that sale from the server and lists its lines with sold
  quantity, `refunded_quantity` and remaining refundable quantity as the server gave them; a sale with no
  remaining quantity shows "Fully refunded" and cannot be selected, and a number that is unknown or
  at another branch is shown as "Sale not found at this branch" (`not_found`/`not_refundable`).
- AC-60: Given a loaded sale, When the cashier selects lines and enters quantities (in the original
  line's unit; a different unit is not offered, matching `inherited_unit_mismatch`), Then entries of zero,
  negative, empty or more-than-four-decimal quantity are rejected in the form, and a quantity above the
  remaining refundable quantity is rejected in the form using a decimal-string comparison helper that
  does not parse to float (D-6), with the server's `over_refund` as the backstop.
- AC-61: Given selected lines, When the cashier submits the refund, Then the till requests a `refund`
  approval bound to the original sale (AC-65), and on approval calls refund with the original sale id,
  `refund_of_item_id` per line, quantities, the approval id and the refund tenders; the refund lines'
  prices are never entered or computed by the till (they are the server's).
- AC-62: Given the refund is accepted, Then the till shows the server's refund `number`, the refunded
  line figures and total, and the original sale's new status (`partially_refunded` or `refunded`) as
  returned.
- AC-63: Given the cashier chooses Goodwill refund (a refund with no original sale), When they pick
  products from the grid/search with quantities, Then the approval is requested as the goodwill type
  (reference type `goodwill_refund`, no reference id), and every other step is as AC-61/AC-62; each
  goodwill line is quoted by the server.
- AC-64: Given the refund tenders, Then only methods the server lists as allowing refund are offered
  (`allows_refund`), the payout amount defaults to the server's refund total string, a payout above what
  is owed is refused by the server as `exceeds_balance` and shown as "More than is owed back" (no change
  is given on a refund), a refund of `0.0000` needs no tender, and a refund tender retry reuses its id
  (AC-49).

### K. Approvals

- AC-65: Given an action needs approval (void of a completed sale, refund, price override, discount
  needing approval — or the server answered `approval_required`), When the cashier proceeds, Then the till
  sends a request-approval call naming the type and the binding (type, reference, branch, and the payload
  the server requires for override and discount), shows a supervisor dialog with the action summary, and
  waits.
- AC-66: Given the supervisor dialog, When the supervisor enters their employee code and PIN and confirms,
  Then the till calls decide with `pin_local` and the till then retries the original action with the
  approval id once; the PIN field is a masked password-type input, is never stored in component state
  beyond the submit call, never written to the URL, a log, `sessionStorage` or `localStorage`, and is
  cleared on submit, cancel, dialog close and a 60-second idle timeout.
- AC-67: Given the decision is refused `pin_invalid`, Then the dialog stays open with "Code or PIN not
  recognised" (the same message for a wrong PIN, unknown code or locked employee: the till does not
  distinguish them), clears the PIN, and allows another attempt; the till imposes no lockout of its own
  (the server owns it).
- AC-68: Given decide is refused `self_approval_not_allowed` or `not_authorised`, Then the dialog shows
  "This person cannot approve this action" naming which of the two, and the cashier can hand over to
  another supervisor without leaving the dialog.
- AC-69: Given a supervisor rejects (a Reject control), Then the till records the rejection through
  decide and returns to the sale with "Approval rejected" and no change to the sale.
- AC-70: Given the retried action is refused `approval_already_used`, `approval_not_approved`,
  `approval_not_pending` or `approval_mismatch`, Then the till discards its stored approval id, says a
  new approval is needed, and starts at AC-65 again; an approval id is held only in memory for one retry
  and is never reused for a different action or sale by the till.
- AC-71: Given the cashier cancels the supervisor dialog (Esc), Then nothing is consumed and the sale is
  as before.

### L. Errors and refusals

- AC-72: Given any API call fails with an `ApiError` carrying one or more `errors[].code`, Then the till
  shows a message from a single exhaustive catalogue keyed by `SalesRefusalCode`, plus the refusal's
  cause where the server supplied one (for example `credit_refused` with `currency_mismatch`); a spec
  asserts the catalogue has a non-empty plain-English message and a next step for every member of the
  union (AC-3), so adding a code without a message fails the build.
- AC-73: Given an unknown code or a failure with no `errors` (a 5xx or unreadable body), Then the till
  shows "Something went wrong" with the HTTP status and the `request_id` when present, so a cashier can
  quote it to support, and never shows a raw stack trace, a server `detail` it cannot interpret verbatim
  as a heading, or an empty message.
- AC-74: Given the network is unreachable (`ApiError` status 0), Then the till shows a persistent banner
  "Cannot reach the server: sale actions are paused", disables every action that would write, keeps the
  cart readable, and re-enables itself the moment a retry succeeds; it never queues writes for later
  (D-2).
- AC-75: Given an add-line, update-line or remove-line call whose response was lost (status 0 after a
  request was sent), When connectivity returns, Then the till reloads the sale from the server and shows
  that state before accepting another line action, so the cart never shows a line the server does not
  have or hides one it does (D-11).
- AC-76: Given each refusal below, Then the message and the next action are as follows (each is a
  component or catalogue spec case):

  | Code | The cashier sees | Next action offered |
  |---|---|---|
  | `no_open_shift` | "Open a shift first" | opens the shift prompt (AC-9) |
  | `shift_closed` | "That sale's shift is closed" | start a refund (AC-57) |
  | `shift_not_open` | "The shift is not open" | shift prompt |
  | `balance_due` | "Balance remaining: <server amount>" | stay in payment |
  | `exceeds_balance` | "More than the balance" | edit the amount |
  | `customer_required` | "Attach a customer first" | open Customer (AC-39) |
  | `sale_not_editable` | "Payment has started: remove the tenders to edit lines" | reload the sale |
  | `approval_required` | "A supervisor must approve this" | supervisor dialog (AC-65) |
  | `pin_invalid` | "Code or PIN not recognised" | retry (AC-67) |
  | `over_refund` | "More than is left to refund" naming the server's remaining figure | edit quantity |
  | `fully_refunded` | "Already fully refunded" | none |
  | `product_not_sellable` | "This product cannot be sold" with the failed fact | none |
  | `unresolvable_price` | "No price for this product" | none |
  | `unit_unresolvable` | "This unit does not apply to the product" | choose another unit |
  | `usage_limit_reached` | "That discount has been used up" | remove the discount and retry |
  | `credit_refused` | "Store credit refused" plus the cause | change method |
  | `replay_conflict` | "This entry conflicts with an earlier one" | reload the sale |
  | `invalid_transition` | "That sale cannot be changed that way now" naming its status | reload the sale |
  | `in_use` | "Payment method in use" | none |
  | `validation` | the failing `field` named, with the server's `detail` | fix the field |

  The remaining codes of AC-3 follow the catalogue rule of AC-72.

### M. Money display, keyboard and accessibility

- AC-77: Given the till source (everything under `projects/pos/src/app/till/**` and the shared sales
  contract and adapters, excluding the mock), When a source-rules spec scans it, Then it reports any use
  of `parseFloat`, `parseInt` on a money or quantity string, `Number(`, unary `+` on a money or quantity
  string, `.toFixed`, `Math.round|floor|ceil`, `toLocaleString`, `Intl.NumberFormat`, or arithmetic
  operators on money-typed values; the only permitted money handling is the single `decimal-string.ts`
  helper (validate shape, compare two decimal strings with BigInt-scaled integers, lossless display
  formatting) and a canary file under test fixtures proves the scan fails when a violation is planted.
- AC-78: Given any money string from the server, When it is displayed (D-7), Then the digits are exactly
  the server's (integer part grouped in thousands, trailing zeros trimmed down to a minimum of two
  decimals, no rounding), prefixed or suffixed with the sale's `currency` code as the server supplied it;
  the spec table includes `"0.0000"` to `0.00`, `"1250.5000"` to `1,250.50`, `"0.0125"` unchanged to
  `0.0125`, `"1234567.8900"` to `1,234,567.89`, and a negative `"-5.0000"` to `-5.00`.
- AC-79: Given any quantity string, Then it is displayed with the server's digits (trailing zeros trimmed
  to a minimum of zero decimals, so `"2.0000"` shows `2` and `"0.7500"` shows `0.75`) followed by the unit
  code snapshot; nothing is rounded.
- AC-80: Given the keyboard map (D-22), Then every action of the till is reachable without a pointer:
  `F2` Pay, `F3` Hold, `F4` Recall, `F6` Customer, `F7` Refund, `F8` Void, `F9` Shift menu, `/` or
  `Ctrl+K` product search, `Delete` remove the selected line, `Up`/`Down` select a line, `Enter` confirm
  the primary action of the focused dialog, `Esc` cancel the dialog and return focus to the scan field,
  and `?` (or `F1`) shows the shortcut list; a spec drives each binding through key events and asserts
  its effect, and a binding that is currently unavailable (empty cart for `F2`) does nothing and says why.
- AC-81: Given a multiplier entry `N*` typed in the scan field before a code (for example `3*` then a
  barcode), Then the next resolved line is added with quantity `N` (a positive integer string up to the
  field's limit), shown as a pending multiplier chip until used or cleared with `Esc`; `N` is sent as
  typed, not multiplied by the till, and a weighable product ignores it and prompts for weight (AC-26).
- AC-82: Given a dialog is open, Then keyboard focus is trapped inside it, the first control is focused,
  Tab order follows reading order, and closing it returns focus to the control that opened it or to the
  scan field (AC-21).
- AC-83: Given the till, Then controls have accessible names, all text meets WCAG 2.2 AA contrast, state
  is never conveyed by colour alone, and the layout works from a 1024 by 768 viewport (the minimum till
  display) to a wide display without horizontal scrolling of the cart or footer.
- AC-84: Given a scan result or refusal, Then it is announced through a polite or assertive live region
  (success "Added <name>", refusal as an alert) so that a cashier relying on a screen reader or an audible
  alert hears it, and a refusal never disappears before it has been shown for at least 4 seconds or
  dismissed with `Esc`.

### N. Scope boundaries inside the till, and gate

- AC-85: Given a product with modifiers, kitchen routing, batch or serial tracking, or `variant_parent`
  type, Then the grid and search show only products the server marks sellable and a `variant_parent` is
  expanded to its variants; the till offers no modifier or kitchen-station UI (T-030 writes modifiers as
  `null`).
- AC-86: Given the session context, Then the till takes the terminal, branch and employee from the
  existing session context (`AuthService`/a till context port) and never sends a branch id, terminal id,
  location id or business date in a sale request body (T-030 AC-1: server-resolved).
- AC-87: Given the user changes branch or signs out while a sale is in the cart, Then the till warns that
  the sale will remain as a draft/held on the server and proceeds only on confirmation; no sale or
  approval data persists in `localStorage` or `sessionStorage` after sign-out.
- AC-88: Given the till is opened with an unfinished `draft` or `pending_payment` sale for this terminal
  on the server (for example after a browser refresh), Then the till loads and resumes it (D-9) rather
  than starting empty.
- AC-89: Given `cd frontend && npm test` is run, Then it passes with zero failures, including the
  existing suites (the count changes; the criterion is zero failures and no skipped or `fit` tests), and
  `ng build pos` and `ng build admin` complete with exit code 0.
- AC-90: Given the existing `LoginPage`/`ContextPage`/guards, Then they are unchanged in behaviour (their
  specs still pass without edits other than imports), and the `/till` route stays behind `contextGuard`.
- AC-91: Given `frontend/README.md`, Then it documents the till's architecture seams (ports and
  adapters, D-5), the contract and fixtures location (AC-1, AC-4), the keymap (AC-80), the mock's
  demonstration data (a demo supervisor code and PIN, products incl. a weighable one and a scale label, a
  customer with store credit, payment methods), and the two mock-exclusion sentinels (AC-8), and states
  plainly that the till is online-only and not connected to a real backend.
- AC-92: **NOT VERIFIABLE in this task:** behaviour against the real Sales routes, a real barcode scanner
  or scale, a real cash drawer, or a physical touch display; fenced because none exists to test against.
  The nearest proof is the contract fixtures (AC-4) and keyboard-event specs standing in for a wedge
  scanner (characters followed by Enter).

## Proposed follow-up rows

Numbers are the lead's to assign; the next free `FP-` number in `docs/backlog.md` at the time of writing
is above FP-458.

1. **BE: Sales HTTP route layer.** Thin routes over the T-030 slices, conforming to T-037's contract and
   passing its fixture conformance suite. Includes branch-scope and permission checks per slice (T-030
   review W-5, FP-452, with a test per slice), new permission keys, and the approval hardening ruling
   (FP-451) before the first real till. Owns the tender contract gap in D-8. Depends on T-030 DONE.
2. **BE: Till read API.** Branch-scoped sellable-product list/search with categories, scan resolution
   (exact barcode then `barcode_rules`, via FP-423's PHP half), payment-methods list, applicable-discounts
   list, customer lookup, and the "my terminal's open sale", "held sales for the branch" and "this shift's
   completed sales" reads. Related to FP-240 (admin catalogue Get/List), lighter and till-shaped; folds
   into FP-240 only if the lead prefers one read surface.
3. **FE: Till go-live.** Replace the mock with the real routes in `pos`, run the fixture conformance suite
   against a test tenant, and reconcile any contract drift. Depends on rows 1 and 2.
4. **FE: Terminal activation and cashier switching** (relates to T-014 and FP-238): the till today takes
   the terminal from session context and the mock; a real per-terminal, per-cashier sign-in flow is its own
   row.
5. **Notes onto existing rows (no new row):** FP-237 gains "swap the till's `BarcodeResolver` and
   `LinePricer` adapters to the client-side FP-423 parser and T-035/FP-432 engine, behind the ports T-037
   ships"; FP-239 gains the till's receipt/print hook; FP-240 notes the shift-reconcile screen the till
   deliberately lacks.

## Out of scope

- Any real backend: the Sales route layer, catalogue read API, branch/permission enforcement, approval
  hardening (follow-up rows 1 and 2).
- Offline operation of any kind: local database, replica, sync queue, offline PIN sign-in (FP-237,
  FP-238, FP-241). The till is online-only and pauses sale actions when the network drops (D-2).
- The TypeScript calculation engine (T-035 / FP-432) and the TypeScript barcode parser (FP-423): consumed
  only through ports; no tax, price-list, discount or barcode-rule arithmetic or parsing in the till.
- Printing and receipts (FP-239), cash-drawer pulse, scale and scanner hardware integration beyond
  keyboard-wedge scanners.
- Bill-level discounts, loyalty and voucher tenders, gateway/card-terminal payments, exchanges, quotations,
  sales orders, restaurant/KOT flows, modifiers, serial/batch selection (all out of T-030 too).
- Embedded-price scale labels (`embedded_price`, `price_from_label`): refused with a message (D-14).
- Shift reconcile and shift reporting screens (back office), customer create/edit, product admin.
- Translations (English only), currency conversion, multi-currency sales, rounding policy.
- Client-side permission gating (D-23): the server is the authority and the till shows its refusals.
- PWA/service-worker behaviour changes beyond what T-025 shipped.

## Non-functional requirements

- **Money safety:** no floating-point money or quantity anywhere in the till (AC-6, AC-77, AC-78).
- **Performance:** the till acknowledges any key, scan or tap within 100 ms with a pending state,
  independent of network time (AC-28); grid pages of 50, never the whole catalogue (AC-17); search
  debounce 250 ms with superseding (AC-18); the cart renders 200 lines without dropped input
  (a spec with 200 mock lines asserts no input is lost; no frame-rate claim is made).
- **Security:** PIN handling per AC-66; no token, PIN, approval id or sale data in URLs, logs,
  `localStorage` or console output; every call goes through the existing interceptor; no new
  third-party runtime dependency unless the architect justifies it (a small ULID utility is written
  in-repo, tested).
- **Accessibility:** AC-80 to AC-84, WCAG 2.2 AA, 44 CSS pixel targets for the grid and payment buttons.
- **Compatibility:** the existing mock exclusion guarantee, `ng build pos`/`admin` and the 21+ existing
  specs are not weakened (AC-8, AC-89, AC-90).
- **Maintainability:** standalone components, signals and `OnPush` as in the existing code; the till
  feature is lazily loaded as `TillPage` already is.

## Open questions

No BLOCKING questions. Everything decided is in `docs/decisions/T-037-till-application.md` (D-1 to
D-30). The owner-visible questions, each with a built default, are in the next section.

## Owner-visible questions (each has a built default; none blocks)

1. **Q-1 Approval policy (FP-451, T-030 review W-2).** Should an approval expire, be tied to the
   requesting terminal/cashier, and have an amount ceiling (especially goodwill refunds)? *Default built:*
   one approval, requested and decided at the same till, used once by the action it names, held in
   memory only; the till applies no expiry and no ceiling (the server owns any rule). Needed before the
   first real till (follow-up row 1).
2. **Q-2 Money display.** Show money to the currency's usual decimals with rounding, or exactly the
   server's digits? *Default built:* exactly the server's digits, at least two decimals, never rounded
   (D-7); the till has no rounding policy because T-030 has none (`sales.rounding` is always 0).
3. **Q-3 Blind cash count.** May a cashier see the expected cash before counting at close? *Default
   built:* no, blind count; expected cash and variance are shown after the close (AC-13).
4. **Q-4 Zero-priced lines (T-030 Q-2).** Warn or block a line the server prices at `0.0000`? *Default
   built:* allow, with a "Zero price" marker (AC-37).
5. **Q-5 Scale price labels.** Are price-embedded labels needed at launch? *Default built:* refused with
   a message; needs a T-030 extension (`price_from_label`) first (D-14).
6. **Q-6 Customer attach.** Is minimal customer lookup (code, phone, name; attach only) enough for the
   store-credit tender at launch? *Default built:* yes; no customer creation at the till (D-18).
7. **Q-7 Keymap.** Are the proposed function-key defaults acceptable for the shop's keyboards? *Default
   built:* AC-80's map (D-22); it lives in one file so it is cheap to change.
8. **Q-8 Refund scope.** May any cashier start a refund (with approval), at any time after sale and from
   any shift, for the same branch? *Default built:* yes, same branch only, approval required each time
   (T-030 AC-29, AC-54).

## Assumptions

- The architect owns file layout, store design and component breakdown. This spec fixes behaviour and the
  seams (ports, contract in `@flexipos/shared`, till UI in `pos`), not structure (D-26).
- The T-030 domain rules are as written in `docs/specs/T-030-sales.md` revision 8; where the HTTP route
  layer later differs in field naming, the contract file is the reconciling document and the fixtures are
  updated in the same change (follow-up row 1).
- The tender contract (D-8) is a proposal for the route layer: the till sends `tendered` and the method
  and the server derives `amount` and `change_given`. T-030's handler takes both; the architect and the
  follow-up row 1 reconcile this, and the till will not compute either.
- The refund-tender contract sends positive payout amounts and the server stores them negative (T-030
  AC-30). Likewise for the refund line contract (quantities positive).
- The session context can supply terminal, branch and employee for the till. No real endpoint supplies
  them to a signed-in person today (T-025 D-5); the mock does. Follow-up row 4 replaces this.
- The mock may extend the existing `mockBackendInterceptor` or sit beside it, installed in the same
  development-only way; production exclusion is proven by AC-8.
- The demo supervisor code and PIN in the mock are fictional values documented in the README and
  never real credentials.
- Dependencies: T-025 (frontend bootstrap, DONE in this tree); T-030 (semantics only, not code);
  T-029 and T-027 (fixtures and catalogue facts the mock imitates). T-035/FP-432, FP-423 (TS) and
  FP-237 are not dependencies; T-037 does not wait on any of them.
