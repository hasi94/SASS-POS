# FlexiPOS — System Architecture

| | |
|---|---|
| Status | **v0.4** — master database plus one database per tenant, modular Laravel monolith |
| Date | 2026-09-29 |
| Authoritative inputs | `Refference/ARCH.md` (product-owner decision, supersedes conflicting earlier guidance) · `Refference/ERP.md` (POS + ERP scope) · `Refference/saas_pos_master_spec.md` (original POS spec) · `docs/eventbus.md` (Redis Streams) · `docs/trade-off.md` (online sales never oversell) |
| Related | [database.md](database.md) · [deployment-vps.md](deployment-vps.md) · [backlog.md](backlog.md) · [gap-analysis-erp.md](gap-analysis-erp.md) |
| Change log | **v0.4:** database per tenant with a master database for identity and routing; fourteen services collapse to modules in one Laravel application; ERP domains in scope; per-tenant migration runner added as foundation work. v0.3: microservices with a schema per service. v0.2: Print Agent to C#/.NET. v0.1: initial modular monolith |

**v0.4 supersedes v0.3's database-per-service design.** That design was a faithful answer to a microservice requirement but a poor answer for an ERP: a profit and loss statement spans sales, purchases, expenses, payroll and inventory, and separated schemas cannot join them. It was also the most complex option for a small team. `Refference/ARCH.md` replaces it.

---

## 1. The core principle

| Database | Answers |
|---|---|
| **Master** | Who are you, which tenant do you belong to, what may you use, and where is your tenant database? |
| **Tenant** | What is happening inside this business? |
| **Analytics** (later) | What is happening across the whole platform? |

Rules that follow, and that everything below serves:

1. No tenant can reach another tenant's database.
2. The frontend never decides which database to connect to. It may *name* a tenant; the backend verifies the user's access and builds the connection from the master's mapping.
3. The master stays small. Business data never goes in it.

---

## 2. High-level architecture

```text
   Angular POS (PWA, offline)      Angular Admin/ERP            .NET Print Agent
            │                             │                   (on the till, loopback)
            └──────────────┬──────────────┘                            │
                           ▼                                           │
              Nginx  ─►  Laravel application  ◄──── agent heartbeat ────┘
                               │
             ┌─────────────────┼──────────────────┐
             ▼                 ▼                  ▼
        MASTER DB           Redis            Tenant Resolver
   identity · tenants    cache · queues            │
   subscriptions         streams · locks           │  mapping: tenant → host, port, db
   licensing · mapping                             │
                           ┌─────────────────┬─────┴───────────┬─────────────────┐
                           ▼                 ▼                 ▼                 ▼
                      tenant_001        tenant_002        tenant_003        tenant_NNN
                    (one shop's         …                 …                 …
                     whole business)
                           │
        POS · Inventory · Purchasing · Customers · Finance · HR · Payroll ·
        Commission · Expenses · Reports · Workflow · Plugin data · Audit
```

One Laravel application serves every tenant. It holds two connections open: `master`, always the same, and `tenant`, rebuilt per request from the resolved mapping.

---

## 3. Module structure

A modular monolith, not microservices. Modules are Composer path packages under `modules/`, booted by the host application from configuration, each with clear boundaries enforced by a static boundary check.

| Module | Owns |
|---|---|
| **Platform** | Master database: tenants, plans, subscriptions, licensing, feature flags, tenant-user access, the database mapping, platform admin and audit |
| **Identity** | Login against the master; tenant-local employees, roles, permissions, cashier PINs |
| **Organization** | Branches, warehouses, terminals, devices, settings hierarchy, number sequences |
| **Catalog** | Products, variants, barcodes, categories, brands, units, modifiers, price lists, taxes, discounts |
| **Inventory** | Stock levels, the stock ledger, batches and serials, adjustments, transfers, reorder alerts |
| **Purchasing** | Suppliers, purchase requests, purchase orders, goods receipts, supplier invoices, returns, supplier payments |
| **Sales** | The till: sales, items, tenders, hold and recall, refunds, voids, cash shifts, approvals |
| **Restaurant** | Tables, orders, kitchen and bar tickets, split and merge |
| **Customers** | Customers, groups, credit ledger, loyalty |
| **Finance** | Chart of accounts, journals, fiscal periods, cash and bank, receivables, payables, reconciliation, tax |
| **HR** | Employees, departments, attendance, leave, shifts, documents |
| **Payroll** | Salary structures, payroll periods, allowances, deductions, payslips |
| **Commission** | Rules, periods, entries, payouts |
| **Expenses** | Categories, expenses with attachments, approvals, payments |
| **Reports** | Report registry, report runs, dashboards, exports |
| **Workflow** | Configurable approval rules driving discounts, expenses, purchase orders, payroll and leave |
| **Plugin** | Plugin runtime, hook dispatch, tenant plugin settings and data |
| **Notification** | Email, SMS, in-app, webhooks, templates |
| **Audit** | Audit log and security events |
| **Sync** | Offline push and pull, the POS replica, terminal sync state |
| **Printing** | Printers, routes, kitchen stations, templates, print jobs, agent heartbeat |

Boundaries are enforced, not merely described: a module may depend on the kernel and on another module's published contracts, never on its internals. That rule is checked in the gate, which is what keeps later extraction possible.

Because everything lives in one database per tenant, **foreign keys across modules are normal and encouraged**. The projections, reference tables and snapshot endpoints v0.3 needed are deleted. Snapshot *columns* on financial documents stay, because reprinting an old receipt exactly is still a requirement.

---

## 4. Repository layout

```text
flexipos/
├── app/                      the Laravel host: bootstrap, tenant resolver, module loader
├── modules/<Module>/         one package per module above
│   ├── src/ (Http, Models, Services, Listeners, Hooks, Console)
│   ├── routes/  database/migrations/  config/  tests/
│   └── composer.json + ModuleServiceProvider
├── packages/
│   ├── kernel/               TenantContext, connection switching, settings, audit, idempotency
│   ├── contracts/            module contracts, DTOs, domain events, hook definitions
│   ├── plugin-sdk/           plugin base classes, manifest validation, hook registration
│   └── testing/              two-tenant fixture, isolation helpers
├── database/
│   ├── master/migrations/    the master schema
│   └── tenant/migrations/    the tenant schema, run against every tenant database
├── frontend/                 Angular workspace: pos, admin, shared library, plugin remotes
├── print-agent/              C# / .NET 8 Worker Service
├── plugins/                  plugin source
├── infra/                    docker, compose, nginx, CI
└── docs/
```

Two migration trees is the important detail. Master migrations run once. Tenant migrations run once **per tenant database**, tracked per tenant.

---

## 5. Login and tenant resolution

```text
email + password
      ▼
MASTER DB  ── validate credentials
           ── which tenants may this user access?
           ── one tenant → select it; several → the client asks the user to choose
           ── tenant active? subscription valid?
           ── read the mapping: host, port, database, credential reference
      ▼
issue JWT { user_id, tenant_id, branch_ids[], employee_id?, terminal_id?, token_type, perm_version }
      ▼
each later request: verify JWT ─► TenantContext ─► configure the `tenant` connection ─► module code
```

Hard rules:

- The tenant is taken from the **token**, never from the request body, and the token is signed by the platform.
- A tenant identifier arriving from the client is treated as a *request*, not a fact: the backend re-checks that the user has access, that the tenant is active, and that a mapping exists, then builds the connection itself from server-side configuration.
- Credentials for tenant databases live in the master as a reference to a secret, not as plain text.
- A suspended or unpaid tenant is refused at resolution, before any module code runs.

**One person, several shops.** The master records the relationship, so a user who owns three pharmacies signs in once and switches between them without separate accounts. Switching tenant means issuing a new token, not mutating the current one.

---

## 6. Identity, split deliberately across the two databases

| Lives in the master | Lives in the tenant database |
|---|---|
| Login identity: email, password hash, status | Employees: name, national identity number, department, designation, joining date, salary, bank details |
| Which tenants a user may access, and with what platform role | Roles, permissions, and their assignment per branch |
| Platform administrators and impersonation | Cashier PINs |

This split is deliberate and load-bearing. A cashier or waiter often has no login at all. Payroll and commission attach to an **employee**, not to a login. And a till must verify a PIN while the internet is down, which is only possible if the PIN is in data the till can hold locally.

Authorization is evaluated against the tenant database, so a permission change takes effect without touching the master.

---

## 7. Tenant provisioning and migrations

The single largest operational cost of this architecture, and therefore foundation work rather than something to discover at two hundred customers.

**Provisioning a new tenant:** create the database on the chosen server, record the mapping, run the full tenant migration set, seed defaults (chart of accounts, roles, payment methods, tax groups, print templates, number sequences), then mark the tenant active. The whole sequence is one resumable job, because a half-provisioned tenant must be completable rather than abandoned.

**Migrating every tenant:** every schema change runs across every tenant database. The runner must:

- record a **schema version per tenant** in the mapping table, so the system always knows who is behind;
- run as a queued job per tenant, so one failure isolates to one tenant instead of stopping the batch;
- be resumable and safely re-runnable;
- report which tenants are behind, and refuse to let the application serve a tenant whose schema is older than the code expects;
- follow **expand and contract**: add in one release, backfill, switch, remove in a later one, so old and new code can both run during a rollout.

**Server placement.** Tables, not disk, are the real ceiling. At roughly 150 tables per tenant, a thousand tenants is 150,000 tables on one server, and table-cache and metadata pressure bite well before storage does. Plan for a few hundred tenants per server. The mapping stores host and port precisely so tenants spread across servers, and a large customer can be moved to dedicated infrastructure by moving data and updating one row.

---

## 8. API architecture

- Versioned REST under `/api/v1`, documented with OpenAPI generated from code.
- One response envelope everywhere: `{ success, data, message, errors[{code, field, detail}], meta{request_id} }`, with correct status codes.
- Error codes namespaced by module and catalogued centrally, so the frontend maps codes to translated messages.
- Idempotency keys on every state-changing request that creates a financial record; for offline documents the client identifier is the key.
- Rate limits per tenant, per user and per terminal.
- Route groups: `platform/*` for platform administrators against the master, `tenant/*` for back office, `pos/*` for tills, `sync/*`, `plugins/*`, `agent/*`.

---

## 9. Events, queues and background work

Redis Streams remains the bus, behind an interface so business code never touches Redis directly.

Inside one application, modules mostly call each other through contracts. Events carry the cases where a caller should not wait or should not know who listens: posting to the ledger, loyalty, notifications, analytics, plugin hooks that run after the fact.

- Every event is written to an **outbox in the tenant database inside the same transaction** as the change that caused it, then relayed. A completed sale is never lost because a worker died.
- The envelope carries tenant, branch, actor, correlation and causation identifiers.
- Consumers are idempotent by event identifier; failures retry with back-off and then move to a dead-letter stream with an alert.
- Queues: default, sync, print, reports, notifications, plugins, imports, migrations.

Events are emitted from day one even though the analytics warehouse comes later, because history not captured cannot be recovered.

---

## 10. Accounting as the financial backbone

What makes this an ERP rather than a till: business operations generate journal entries.

```text
Cash sale of 10,000 with cost of goods 6,000
   Debit  Cash      10,000        Credit Sales      10,000
   Debit  COGS       6,000        Credit Inventory   6,000
```

Sales, goods receipts, supplier payments, expenses, payroll runs and stock adjustments each post to the ledger through one posting service, never by writing journal rows directly. Fiscal periods can be closed, after which posting into them is refused. Profit and loss, balance sheet, trial balance and cash flow are then reports over the ledger rather than bespoke queries, which is the whole point of doing it properly.

Posting happens asynchronously so the till is never blocked by accounting, but through the outbox so it cannot be lost.

---

## 11. Online sales are strongly consistent on stock

From `docs/trade-off.md`, unchanged and still correct.

An online sale validates and decrements stock inside a database transaction and is rejected if stock is insufficient. Because everything now lives in one tenant database, this is a row lock and a transaction rather than a distributed saga, which removes a large amount of machinery v0.3 required.

Offline sales are facts: they are accepted on arrival, may drive stock negative, and open a conflict record for a manager to resolve.

---

## 12. Offline POS and synchronisation

Unchanged in substance from v0.3 and still the hardest part of the product.

- A completed, paid sale is a fact. The server records it; it never rejects it for business reasons.
- Master data is server-authoritative. The till reads products, prices and taxes offline but never edits them, which removes most conflict cases.
- Every offline record carries a client-generated identifier and a per-terminal sequence, so replays are idempotent.
- The till holds a replica: products, prices, taxes, discounts, customers, payment methods, settings, printers, templates, and the cashier PIN and permission set that lets someone sign in with no internet.
- Push is a batch of items acknowledged individually, so a partial failure is resumable. Pull is a delta by version, with a full snapshot for a new or recovered terminal.
- Conflict rules are explicit: duplicates by identifier, oversell accepted and flagged, stale price kept as sold and reported, customer edits merged field by field with the discarded values audited, orders merged as an append-only stream.
- Invoice numbers are allocated per terminal from a reserved prefix so a receipt is final at the moment it prints.

**Suspension does not currently reach an offline till**, which matters when a tenant is suspended for non-payment. Terminal tokens and the replica need an expiry the till enforces locally. This is tracked as a defect to fix before the first paying customer.

---

## 13. Plugins

Master and tenant split cleanly, which this architecture makes easier than the previous one.

| Master decides | Tenant holds |
|---|---|
| Is the plugin licensed, and to which tenants | Plugin settings for this shop |
| Which version a tenant may run | Plugin business data, in its own prefixed tables |
| Whether a tenant may use it at all | Records the plugin writes through the hooks |

Plugin code runs in-process behind a hook gateway with named extension points, each declaring its mode: add, before which may veto, after which is queued, filter which transforms, and override which is permitted only on points explicitly marked overridable. A plugin failure is caught, timed, logged and circuit-broken per tenant, so one badly behaved plugin cannot take down a till.

Plugins may not modify core tables. They extend through custom fields or their own tables keyed by core identifiers.

---

## 14. Workflow engine

Approvals are configuration, not code. A rule names a trigger point, a condition and a sequence of steps, and the engine runs as a hook handler at that point.

```text
discount above 20%        → manager approval
expense above 100,000     → manager, then finance
purchase order above 500,000 → manager, then director
```

One engine serves discounts, expenses, purchase orders, payroll and leave, rather than each module growing its own approval logic.

---

## 15. Frontend

Two Angular applications in one workspace over a shared library holding the API client, authentication, tenant and branch context, the plugin interface and the extension-point directive.

The till is an offline-first progressive web application with its local database and sync engine. The admin and ERP application is lazily loaded per area. Plugins ship as separately built remotes the shell loads at runtime and the service worker caches, so a plugin's user interface works offline too.

The tax, discount and rounding engine exists twice, in PHP and TypeScript, driven by shared fixtures so a receipt printed offline matches the server total exactly.

---

## 16. Printing

Unchanged from v0.2. A C# .NET 8 Worker Service runs on the till, exposes a loopback API paired to the browser by a one-time code, holds its own queue so a sale completes even when the printer is jammed, and drives receipt, kitchen, bar and label printers over the Windows spooler, USB or the network. Routing is resolved from the local replica so it works offline, and the browser print dialog is the fallback when no agent is present.

---

## 17. Security

Physical tenant separation removes the largest category of risk, but not all of it.

- Tenant credentials referenced as secrets, never stored in plain text; the resolver is the only code that builds a tenant connection.
- Tokens signed with rotating keys; short lifetimes for people, revocable long-lived tokens for tills bound to a device.
- Authorization evaluated per branch, with least privilege and no bypass flag.
- Validation at the edge of every module; bound queries only; output encoding by default.
- Audit of who changed what, before and after, with the request identifier, kept in the tenant database, plus a separate platform audit in the master.
- An automated test suite that attempts cross-tenant access through routes, jobs, cache keys and plugin hooks and requires refusal.
- Plugin permissions declared in the manifest and granted by a tenant administrator, never implicit.

---

## 18. Observability

Structured logs carrying request, tenant, branch, terminal, user and plugin identifiers. Metrics for request latency, queue depth, stream lag, dead-letter growth, sync outcomes, print failures, offline terminals, plugin timings, and slow queries. Health endpoints outside session middleware so a liveness check never depends on the database. Alerts on dead-letter growth, outbox age, tenants behind on migrations, terminals offline too long, and plugin circuit trips.

---

## 19. Deployment

See [deployment-vps.md](deployment-vps.md). Unchanged in shape: the existing shared VPS hosts a pilot behind the panel's own reverse proxy, binding one loopback port, on isolated Docker networks with per-container limits, without disturbing what already runs there. The application, workers and scheduler run from one image. Nothing touches that server without the owner's explicit approval.

---

## 20. Scaling

| Stage | Shape | Capacity |
|---|---|---|
| Pilot | Shared VPS, one application container, one MySQL | Tens of tenants |
| Growth | Dedicated server, replicas behind a load balancer, MySQL tuned | A few hundred tenants on one database server |
| Scale | Several database servers, tenants placed by the mapping; read replica for reports | Thousands of tenants |
| Large | Dedicated infrastructure per enterprise tenant; analytics warehouse fed by events | Whatever a single customer needs |

The mapping table is what makes every step a data move plus a row update rather than a rewrite. Platform-wide analytics never queries tenant databases in a loop; it reads the warehouse built from events.

---

## 21. Testing

Unit tests for the calculation engine, posting rules, conflict rules and the permission resolver. Feature tests per module against a two-tenant fixture. A generated cross-tenant suite that must pass to merge. Sync scenarios covering duplicates, ordering, dependencies and conflicts. Plugin lifecycle and isolation. Migration tests that run the tenant set against a fresh database and an existing one. Frontend unit and end-to-end tests including the offline sale. Print agent tests against a mock transport with golden output. Load tests for checkout latency and sync bursts.

The gate runs formatting, static analysis, boundary rules and tests. It must stay fast enough that people keep running it.

---

## 22. Decisions

| # | Decision | Why | Rejected alternative |
|---|---|---|---|
| 1 | Master database plus one database per tenant | Physical isolation, trivial per-shop backup and restore, and ERP reporting stays a normal join | Shared tables with a tenant column: harder per-tenant restore, one missing filter leaks data |
| 2 | Modular monolith, boundaries enforced by a check | One team, one deploy, transactions across modules; extraction stays possible | Microservices: made accounting and reporting genuinely hard for no present benefit |
| 3 | Login in the master, employees in the tenant | A cashier may have no login; payroll and commission attach to an employee; PINs must work offline | One user table: forces every cashier to be a platform account |
| 4 | Per-tenant migration runner as foundation work | Every schema change multiplies by tenant count; discovering this late is the classic failure of this architecture | Ad-hoc migration scripts |
| 5 | Redis Streams behind an interface | Already needed for cache, queues and locks; no new infrastructure on a small VPS | RabbitMQ or Kafka now |
| 6 | Outbox in the tenant database | A completed sale must never be lost when a worker dies | Fire-and-forget events |
| 7 | Accounting posts from one service, asynchronously | Makes it a real ERP without slowing the till | Writing journal rows from each module |
| 8 | Online sales strongly consistent on stock; offline sales are facts | Never oversell when connected; never reject a paid sale | Eventual stock everywhere |
| 9 | Plugins in-process behind a hook gateway, licensed in the master | Full extension power with per-tenant licensing and crash isolation | Webhook-only plugins |
| 10 | Table count, not disk, sets tenants per server | Metadata pressure bites first; the mapping already supports many servers | Assuming thousands of databases on one server |

---

## 23. Open questions

| # | Question | Default if unanswered |
|---|---|---|
| 1 | Restaurant features in the core or as a plugin? `Refference/ERP.md` says plugin; this document currently has a module | Keep as a module, behind a feature flag |
| 2 | Launch country tax regime and receipt numbering law | Percentage value-added tax, terminal-prefixed numbering |
| 3 | Payment gateways at launch | None live; cash, card as reference, and credit |
| 4 | Languages for the interface and receipts | English first, Sinhala and Tamil after |
| 5 | Pilot domain name on the VPS | Pending |
