# Gap analysis — `Refference/ERP.md` against the current design

| | |
|---|---|
| Date | 2026-09-28 |
| Inputs | `Refference/ERP.md` (new), `Refference/saas_pos_master_spec.md`, microservice requirements (2026-09-28), [architecture.md](architecture.md) v0.3, [database.md](database.md) v0.3, [backlog.md](backlog.md) v0.3 |
| Status | Analysis only. No design document has been changed. Decisions needed from the product owner are in §6 |

`ERP.md` is written as advice, not as a specification. It is treated here as a statement of product direction: the target is a **modular POS + ERP platform** where POS is one module and Finance, HR, Inventory, CRM and Reporting sit around it, not a POS with a few extra features. That is a materially larger product than the master spec describes, and it changes two decisions already taken.

---

## 1. Two conflicts with decisions already made

### C-1 Modular monolith versus database-per-service

| Source | Position |
|---|---|
| Master spec §12 | "Start with a modular monolith where practical" |
| Microservice requirements (2026-09-28) | "Do not build the entire SaaS POS as a single monolithic Laravel application." Each service owns its data. "Avoid creating a shared database where all services directly access each other's tables" |
| `ERP.md` §32 | "I would not build everything as microservices from day one. That's the trap… Phase 1: Laravel Modular Monolith with strict module boundaries. Later, if necessary, extract services" |
| `ERP.md` §31 | Lists **one** database divided into logical domains, not one schema per service |

The v0.3 Service Host already reconciles most of this: logical services, co-located in one deployable, split later by configuration. That is what `ERP.md` §32 asks for. The part still in tension is **database-per-service**: v0.3 gives each service its own schema and its own database user, while `ERP.md` assumes one database with logical domains.

This matters most for accounting. Double-entry posting and the Profit and Loss, Balance Sheet and Trial Balance reports draw on sales, purchases, expenses, payroll and inventory together. With separate schemas the accounting module cannot join them; it must consume events and build its own ledger. That is a legitimate and common ERP pattern (subledgers post to a general ledger), and v0.3's event bus supports it, but it is more work than a join and it must be decided before the finance module is built, not after.

### C-2 Restaurant as core versus as a plugin

`ERP.md` §2: "don't build restaurant functionality into the retail core. Make it a module/plugin." v0.3 puts tables, orders, KOT and BOT **inside the sales service** as core, and the backlog builds it in Phase 10. Moving it to a plugin changes the extension points the plugin system must expose and adds work; leaving it in core contradicts the new direction. The same question applies to Pharmacy, Hotel, Gym and other verticals `ERP.md` lists as plugins.

---

## 2. Domains missing entirely

Five whole business domains in `ERP.md` have no counterpart in the current design.

| # | Domain | What `ERP.md` asks for | Current state |
|---|---|---|---|
| G-1 | **Accounting / Finance** | Chart of accounts, double-entry journal entries and lines, fiscal periods, general ledger, trial balance, Profit and Loss, Balance Sheet, Cash Flow. "This is what turns your POS into an ERP" | **Nothing.** v0.3 lists general-ledger accounting as an explicit non-goal for 1.0 |
| G-2 | **Expense management** | Expense categories, expenses with attachments, request → manager approval → finance approval → payment → accounting, 15 named categories | **Nothing** |
| G-3 | **HR** | Employee master (NIC, department, designation, joining date, bank details, employment type), departments, employee documents, attendance (clock in/out, late, overtime, breaks, shifts, biometric feed), leave types, requests, balances and approval | **Nothing.** v0.3 has `users` for system logins only |
| G-4 | **Payroll** | Salary structures, payroll periods with draft → processing → approved → paid and locking after approval, allowances, deductions (EPF, ETF, tax, no-pay), payslips, posting to accounting | **Nothing** |
| G-5 | **Commission engine** | Rules by percentage of sales or profit, fixed, tiered, product, category, employee, branch or target; commission periods, entries, payouts, feeding payroll | Only a **plugin example** (backlog FP-123). `ERP.md` treats it as a core module with a rules engine |
| G-6 | **Cash and bank** | Cash accounts, bank accounts, petty cash, credit cards, gateways as financial accounts; cash in/out, transfer, deposit, withdrawal, bank charges; later bank reconciliation against statements | v0.3 has `cash_shifts` and `cash_movements` for the POS drawer only |

---

## 3. Gaps inside domains that do exist

| # | Area | Gap | Severity |
|---|---|---|---|
| G-7 | Inventory | **Warehouses** are a separate entity in `ERP.md` (Purchase → Goods Received → Warehouse Stock → Branch Transfer). v0.3 keys all stock by `branch_id` only | High — changes the stock key |
| G-8 | Inventory | **Batch numbers, serial numbers and expiry dates** as first-class tracked stock. v0.3 records `batch_no` and `expiry_date` on GRN lines only, never on stock or movements, and has no serial tracking | High for pharmacy, grocery, electronics |
| G-9 | Purchasing | **Purchase requests** before the purchase order; **supplier invoices** as an entity distinct from the GRN, feeding accounts payable | Medium |
| G-10 | Sales | **Quotations** and **sales orders** as documents; **exchanges** as a flow distinct from refunds | Medium |
| G-11 | Receivables | Payment allocation across invoices, aging reports, customer statements, overdue tracking. v0.3 has a customer ledger and credit limits but none of the reporting or allocation | Medium |
| G-12 | Payables | Supplier aging, outstanding invoice reporting, purchase price history and price-change reports | Medium |
| G-13 | Products | Bundles, composite products, kits, **recipes and ingredients** (needed for restaurant stock depletion). v0.3 has only a `combo_items` JSON field | Medium |
| G-14 | Tax | Tax authority, tax code and effective date, so rules can change over time and differ by country. v0.3 has rates and groups with no temporal dimension | Medium |
| G-15 | Workflow | v0.3's approval engine is scoped to POS-style triggers inside the plugin service. `ERP.md` wants one engine driving expenses, purchase orders, payroll, leave and discounts | Medium — generalize rather than rebuild |
| G-16 | Reporting | Finance reports (P&L, Balance Sheet, Cash Flow, Trial Balance, General Ledger, AR, AP, tax) and HR reports (attendance, leave, payroll, overtime, salary cost, commission, performance). Inventory adds dead stock, fast and slow movers, expiring stock | Follows G-1 to G-5 |
| G-17 | SaaS platform | **Billing and licensing of tenants themselves** — invoicing subscribers, not just recording a plan | Medium |
| G-18 | Notifications | Adds invoice overdue, expense approved or rejected, leave approved, payroll completed, large discount, large refund, purchase approved | Low — configuration |

---

## 4. Explicitly future in `ERP.md`, absent by design today

Mobile applications for employee, manager and owner; e-commerce and marketplace integration; a public API platform with OAuth, API keys and webhooks; business intelligence and AI analytics; a plugin marketplace; asset management and budgeting. None is a gap for 1.0. One principle from §28 does bind now: physical store, website and marketplace must share **one** product, inventory, customer, order, payment and accounting engine. Nothing in the current design prevents that.

---

## 5. What the current design already satisfies

Worth stating so the gap list is not read as a verdict on the whole design. Already covered: multi-tenancy with branches and terminals; roles and permissions; product, category, brand, unit, variant, barcode and price-list modelling; **ledger-based stock movements** exactly as `ERP.md` §3 demands, with no overwriting of a stock column; purchase orders, GRN and returns; customers, groups, credit and loyalty; offline POS with sync; the print agent; the plugin architecture with extension points and controlled overrides, which `ERP.md` §25 and §26 describe in the same terms; audit logging of who, what, when, before and after; the notification service; and the event bus that the accounting module will need.

---

## 6. Decisions needed before the design is updated

| # | Decision | Options | Recommendation |
|---|---|---|---|
| Q-1 | Is ERP scope in release 1.0, or a second product phase? | (a) 1.0 stays POS and inventory, ERP follows; (b) 1.0 includes finance and HR | **(a)**. `ERP.md`'s own roadmap §33 puts Finance at phase 3 and HR at phase 4, after POS and inventory. Expanding 1.0 delays revenue |
| Q-2 | Database-per-service, or one database with module boundaries? | (a) keep 13 schemas; (b) one schema, strict module boundaries; (c) hybrid | Decide **now**, before the finance module. (a) keeps the earlier microservice instruction and forces the accounting module to build its ledger from events; (b) matches `ERP.md` §31 and makes financial reporting far simpler |
| Q-3 | Restaurant: core or plugin? | (a) core in sales as planned; (b) plugin | Affects backlog Phase 10 and the extension points the plugin system must expose |
| Q-4 | Warehouses separate from branches? | (a) branch-only stock; (b) location model covering both | Cheap now, expensive later — the stock key changes |
| Q-5 | Batch, serial and expiry tracking in 1.0? | (a) defer; (b) include | Depends on the target vertical. Pharmacy and grocery make it mandatory |
| Q-6 | Employees separate from users? | (a) reuse `users`; (b) separate employee master linked optionally to a login | **(b)**. A waiter or salesperson may have no system login, and payroll and commission both key off employee, not user |

### Five things that are cheap now and expensive later

Even if ERP is deferred (Q-1a), these should be settled while the schema is still empty:

1. **Employee attribution on sales.** Commission and payroll need the employee who made the sale, at line level for category-based rules. Adding `employee_id` to `sales` and `sale_items` now costs nothing; backfilling it across millions of rows later is painful.
2. **Accounting-ready events.** Every financial transaction should emit an event carrying what a journal posting needs: amount, tax, cost of goods, account hints, branch, date. Designing the payload now means the general ledger can be built later without replaying history.
3. **Location instead of branch on stock.** If warehouses are coming, key stock by location now.
4. **Batch and serial columns on stock movements.** Nullable now, impossible to reconstruct later.
5. **A tax model with effective dates.** Rates change; without a temporal dimension, reprinting an old invoice produces the wrong tax.

---

## 7. Effect on work in progress

None. T-001, the monorepo and service host skeleton now in QA, is unaffected by every question above. The service host boots modules from configuration and the generator scaffolds a module with its own routes and connection name. That mechanism serves a modular monolith and separated services equally well, and a shared database is a connection-configuration change, not a redesign. Work can continue while Q-1 to Q-6 are settled.
