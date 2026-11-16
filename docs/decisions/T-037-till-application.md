# T-037 decisions — Till application (frontend, mock API)

Autonomous/team-mode decisions by `ba`. Each is the most conventional, safest, reversible choice, logged
rather than asked. Format: `D-n | Question | Decision | Reason | Reversible`. Spec:
`docs/specs/T-037-till-application.md`. Inputs: T-030 spec/decisions (rev 8), T-025 spec, T-027, T-029,
T-006, backlog rows FP-236/233/237/238/423/432/451/452.

D-1 | FP-236 is very large (whole till plus the Sales HTTP route layer T-030 deferred). Split? | **Yes.** T-037 = the Angular till against a mock API with typed contracts and checked-in fixtures, online-only; gate `cd frontend && npm test` plus `ng build pos`/`admin`. The backend Sales route layer, the till read API and the real-backend switch-over become separate proposed rows (spec section "Proposed follow-up rows"). | The frontend can be built and fully tested now; the route layer needs branch/permission design (W-5) and an owner ruling (FP-451) that should not gate the UI; matches the precedent of T-027/FP-423, T-029/FP-432 and T-025 (build against a mock, reconcile later). | Reversible: yes (rows are additive).

D-2 | Offline behaviour? | **Online-only.** On network loss the till shows a banner, keeps the cart readable, pauses every write and never queues; it resumes when a retry succeeds. | Offline (FP-237/238/241) needs a replica, sync and conflict rules that do not exist; a half offline queue risks lost or doubled money. | Reversible: yes.

D-3 | What does the till talk to? | The **mock API only**, in `@flexipos/shared`, development-only, speaking a typed contract with one fixture per call and per refusal. The same fixtures are the conformance suite for the real routes. | The backend routes and catalogue reads do not exist; a fixture-backed contract is the specification for the follow-up row and prevents drift. | Reversible: yes.

D-4 | Who enforces branch scope and permissions (T-030 review W-5/FP-452) and approval hardening (W-2/FP-451)? | **Not the till.** Both go to follow-up row 1 (route layer) with a test per slice; the till shows the server's refusals (`not_authorised`, `approval_*`). | T-030 offered either the handlers or this row's route layer; the route layer is not in T-037's scope, and a UI cannot be an authority. | Reversible: yes.

D-5 | How does the till reach the pricing engine (T-035/FP-432, specced in parallel) and the barcode parser (FP-423)? | Through two **ports**, `LinePricer` and `BarcodeResolver`. T-037 ships only server-backed adapters (the server prices at add-line; the server resolves a scan). No dependency on T-035 or FP-423; they plug in later with no component change (AC-7). | Online-only needs no client-side engine; coding to ports avoids a rewrite for offline and avoids waiting on two unfinished rows. | Reversible: yes.

D-6 | How is money handled? | **The till performs no money or quantity arithmetic.** All figures are server strings in branded decimal-string types; a single `decimal-string.ts` helper validates shape, compares strings with BigInt-scaled integers and formats losslessly. A source-rules spec with a canary bans `parseFloat`, `Number(`, `toFixed`, `Math.*`, locale formatters and operators on money types (AC-77). The mock does exact BigInt decimal arithmetic and is never imported by app code. | The requirement pins "never JS floats"; the same discipline as T-030 AC-42 (one file does arithmetic). | Reversible: yes.

D-7 | How is money displayed? | The server's digits exactly: integer part grouped in thousands, trailing zeros trimmed to a minimum of two decimals, never rounded, with the server's currency code. Quantities trim to a minimum of zero decimals. | Rounding is a policy the system does not have (T-030 D-42: `sales.rounding` is always 0); lossless trimming is string-only. | Reversible: yes (owner question Q-2).

D-8 | Tender contract: who computes the applied amount and the change? | The till sends `{id, payment_method_id, tendered, reference?}` and the server returns the tender (`amount`, `change_given`) and the sale (`paid_total`, `balance_due`, `change_due`). The till sends no `amount`/`change_given`. Flagged for follow-up row 1 and the architect: T-030's `AddTender` takes both `amount` and `tendered`, so the route layer must derive `amount` from `tendered` and `balance_due` (no change allowed: `exceeds_balance`). | The till may not do arithmetic (D-6); `min(tendered, balance)` is arithmetic. | Reversible: yes (a contract change in one file plus fixtures).

D-9 | When is a sale created and how is one resumed? | **Lazily on the first line** (or first customer attach); opening the till or "New sale" starts no draft. On load the till asks the server for this terminal's unfinished `draft`/`pending_payment` sale and resumes it. | Avoids orphan drafts that would block shift close (`shift_has_open_sales`); survives a browser refresh without client storage of sale data. | Reversible: yes.

D-10 | Fast scanner input while a call is pending? | **Queue, in arrival order, one call at a time; never drop.** A pending row shows within 100 ms. A refusal does not discard queued codes. | Wedge scanners send a code plus Enter in milliseconds; add-line is not idempotent so concurrency would reorder or duplicate. | Reversible: yes.

D-11 | A write whose response is lost (unknown outcome)? | Idempotent writes (tender, cash movement) are retried with the **same id**; before any retry or the next line action the till **reloads the sale** and shows the server's state. | Add-line has no idempotency key in T-030; reloading is the only safe reconcile. | Reversible: yes.

D-12 | Idempotency ids? | The till generates a client-side **ULID** for each tender and cash movement (T-030 AC-11/AC-33/AC-37 use caller-supplied ULIDs), keeps it for retries of the same submission and never reuses it for another one. A small tested in-repo utility; no new runtime dependency unless the architect justifies one. | Matches T-030's contract; a double Enter cannot create two tenders (AC-50). | Reversible: yes.

D-13 | Approval UX? | Request approval (type + binding), then a supervisor dialog at the same till: employee code + PIN, `pin_local` decide, then the original action is retried **once** with the approval id. PIN is transient (masked, memory only, cleared on submit/cancel/close/60 s idle). The till never distinguishes wrong PIN, unknown code or locked employee. A consumed/mismatched approval triggers a fresh request. | T-030 D-30/D-47 model; PIN hygiene and non-disclosure follow Access's own indistinguishable-failure rule (AC-39 of T-030). | Reversible: yes (the 60 s is a convention).

D-14 | `embedded_price` scale labels? | **Refused with a message**; nothing added. | T-030 writes `price_from_label = false` only (AC-9, D-8) and back-computing quantity from price is not built. | Reversible: yes (owner question Q-5).

D-15 | Weighable lines? | A weighable product added without a resolved quantity (grid, search, plain code) opens a weight prompt (positive decimal string, at most 4 decimals, in the product's weighing unit); an `embedded_weight` label takes quantity and unit from resolution with no prompt. No live-scale hardware (deferred to Phase 6 by the owner, per T-027). | Matches the FP-236 widening: "weighable lines are entered by weight"; the till does not derive quantity itself. | Reversible: yes.

D-16 | Blind cash count? | **Yes**: the close form does not show expected cash; after submit it shows the server's `expected_cash`, `variance`, counts and `totals_by_method`. | Conventional control against count-to-fit; trivially reversible. | Reversible: yes (Q-3).

D-17 | Reconcile and shift reports in the till? | **No**; `ReconcileCashShift` is a back-office/supervisor action (noted for FP-240). The till opens, records movements, and closes. | FP-236 names open/close only. | Reversible: yes.

D-18 | Customer attach? | Minimal: lookup by code, phone or name and attach; needed for the store-credit tender; no create/edit. | A credit tender requires a customer (`customer_required`); full customer screens belong to FP-240. | Reversible: yes (Q-6).

D-19 | Which refunds? | Referenced refunds against a sale found by number or from this shift's list, and goodwill refunds (T-030 AC-25, AC-29). Quantities in the original line's unit only; refund prices are never entered or computed by the till. | Matches T-030 AC-26/AC-27A (`inherited_unit_mismatch` for other units) and avoids price arithmetic. | Reversible: yes.

D-20 | Voiding a completed sale? | From a list of this shift's completed sales (by number or latest 50), with a void approval; `shift_closed` redirects to a refund. | T-030 AC-22 only allows a void while the sale's shift is open. | Reversible: yes.

D-21 | Error handling? | A single exhaustive catalogue keyed by the closed `SalesRefusalCode` union (message + next step, spec-enforced), a generic fallback showing status and `request_id`, and a network banner. Refusals show at least 4 s or until dismissed. | The requirement pins error states from the Sales refusal codes; exhaustiveness makes a new code fail the build. | Reversible: yes.

D-22 | Keymap? | `F2` Pay, `F3` Hold, `F4` Recall, `F6` Customer, `F7` Refund, `F8` Void, `F9` Shift menu, `/` or `Ctrl+K` search, `Delete` remove line, `Up`/`Down` select line, `Enter` primary action, `Esc` cancel and return to scan field, `?`/`F1` help, `N*` quantity multiplier. Single file, easy to change. Browser-reserved F5/F11/F12 deliberately unused. | Conventional POS function-key layout; keyboard-first requirement. | Reversible: yes (Q-7).

D-23 | Client-side permission gating? | None in v1: no endpoint supplies the cashier's permissions to the frontend (T-025 D-5 gap); the till shows the server's `not_authorised`. | Gating on guessed permissions would be invented business rules. | Reversible: yes.

D-24 | Printing/receipts? | Out of scope; completion shows an on-screen summary only. FP-239 will add the print hook. | Print agent integration is its own row. | Reversible: yes.

D-25 | Localisation and currency? | English only; the currency code comes from the sale/server string; no conversion. | i18n is "not built yet" (README); T-030 sales carry the branch currency. | Reversible: yes.

D-26 | Where does code live? | Contracts, fixtures, mock, decimal helper and ports in `@flexipos/shared`; UI, stores and keymap in `projects/pos/src/app/till/` (lazy). This is a recommendation; the architect owns the layout. | Matches the existing split (shared owns API and mock; pos owns screens). | Reversible: yes.

D-27 | Mock exclusion from production? | Extend the existing guarantee: the sales mock carries its own sentinel string; AC-8 and the CI mock-exclusion step grep both sentinels; the README names them. | The existing guarantee (`demo@flexipos.test`) is the "single most load-bearing" one (T-025). | Reversible: yes.

D-28 | Follow-up rows? | Proposed (numbers assigned by the lead): (1) BE Sales HTTP route layer incl. W-5, FP-451 ruling, tender contract D-8; (2) BE till read API (products/search/scan/payment methods/discounts/customer lookup/open-sale/held/shift reads); (3) FE till go-live against real routes with the fixture conformance suite; (4) FE terminal activation and cashier switching; plus notes on FP-237, FP-239, FP-240. | Each is a separable mechanism with its own owner decisions. | Reversible: yes.

D-29 | Which products does the grid show? | Server-marked sellable products only, `variant_parent` expanded to variants; no modifiers, kitchen routing, batch or serial UI (T-030 writes `modifiers` null and absent columns). | Anything else cannot be sent through the Sales contract. | Reversible: yes.

D-30 | Item discount and price override in the till? | In scope as line actions: the discount is picked from the server's applicable-discounts list; an override is a typed unit price; both use the approval flow when the server requires it. An overridden line cannot take a discount. Manual bill-level discount is out (T-030 D-35). | T-030 AC-7/AC-23a define them and the approval flow is explicitly part of the requirement. | Reversible: yes.
