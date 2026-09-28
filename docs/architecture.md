# FlexiPOS — System Architecture

| | |
|---|---|
| Status | **DRAFT v0.3** — microservice-oriented revision, awaiting product-owner approval |
| Date | 2026-09-28 |
| Sources | `Refference/saas_pos_master_spec.md` (master spec) · microservice requirements (product owner, 2026-09-28) · [eventbus.md](eventbus.md) (Redis Streams decision) · [trade-off.md](trade-off.md) (strong stock consistency for online sales) · VPS facts (Hostinger KVM 1, Ubuntu 24.04, CloudPanel) · local dev = Windows 11 + XAMPP |
| Related | [database.md](database.md) · [deployment-vps.md](deployment-vps.md) · [backlog.md](backlog.md) |
| Change log | v0.3: microservice boundaries, database-per-service, service host, gateway, JWT service auth, event contracts, sagas, Plugin Runtime, VPS deployment, scaling plan, XAMPP dev mode. v0.2: Print Agent → C#/.NET. v0.1: initial modular-monolith draft |

The ten artefacts requested before implementation map to sections as follows: 1 service diagram → §2 · 2 responsibility matrix → §3 · 3 database-per-service → §6 and [database.md](database.md) · 4 API contracts → §7 · 5 event-driven communication → §8, §9 · 6 authentication between services → §10 · 7 tenant isolation → §11 · 8 offline synchronisation → §12 · 9 existing-VPS deployment → §20 and [deployment-vps.md](deployment-vps.md) · 10 scaling strategy → §21.

---

## 1. Goals and hard constraints

| Constraint | Source | Satisfied in |
|---|---|---|
| Microservice-oriented: services developed, deployed, scaled independently; each service owns its data; no shared tables | microservice requirements | §2–§6, §13 |
| Pragmatic: no unnecessary services in Phase 0; extract when justified | microservice requirements + spec §12 | §4 service host |
| Laravel for backend services, Angular for POS, .NET for the Windows Print Agent, Docker for dev and isolation | technology constraint | §5, §14, §22 |
| Cloud-agnostic; first production on an existing shared VPS without disturbing other apps | VPS constraint | §20, deployment-vps.md |
| Online sale: stock validated and decremented strongly consistently, reject on failure; downstream may be eventual | trade-off.md | §9.1 |
| Redis Streams as the event bus behind an abstraction | eventbus.md | §8 |
| Spec §52 mandatory requirements (tenancy, isolation, offline, printing, plugins, upgrades) | master spec | §11–§15 |

Non-goals for 1.0: marketplace billing, kitchen display screens, delivery dispatch, multi-currency in one sale, general-ledger accounting.

---

## 2. Service architecture diagram

```text
                 POS Terminal (Angular PWA + IndexedDB)          Admin App (Angular)          .NET Print Agent
                          │  /api/v1/pos/*, /api/v1/sync/*              │                         │ heartbeat
                          └──────────────────────┬───────────────────────┘                         │
                                                 ▼                                                 │
   existing CloudPanel Nginx (TLS) ──► API GATEWAY (Traefik)  routing · rate limit · access log ◄──┘
                                                 │  path prefix → service
   ┌─────────────┬─────────────┬─────────────┬───┴─────────┬─────────────┬─────────────┬─────────────┐
   ▼             ▼             ▼             ▼             ▼             ▼             ▼             ▼
 identity      tenant       catalog       pricing      inventory       sales        payment      customer
 (auth, JWT)  (org, cfg)   (products)   (tax, price)  (stock, PO)   (POS txn)     (tenders)    (CRM, credit)
   │             │             │             │             ▲ sync: reserve/decrement (strong)       ▲ sync: credit check
   └─────────────┴─────────────┴─────────────┴─────────────┴─────────────┴─────────────┴─────────────┘
                                                 │  outbox → Redis Streams (evt.<service>) → consumer groups
                 ┌───────────────┬───────────────┼───────────────┬───────────────┬───────────────┐
                 ▼               ▼               ▼               ▼               ▼               ▼
               sync         reporting      notification        plugin           print         (audit read model
         (inbox, replica)  (read models)   (mail/SMS/app)  (runtime, hooks)  (printers, jobs)   in reporting)
                 │                                               ▲ sync: BEFORE/FILTER/OVERRIDE hooks from any service
                 ▼
          POS pull/push
```

Fourteen logical services (thirteen Laravel services plus the gateway) and one edge component (Print Agent). Every arrow between services is either a **contract call** (synchronous HTTP, `/internal/*`) or an **event** (asynchronous, Redis Streams). No service reads another service's database.

---

## 3. Service responsibility matrix

| Service | Owns (domain) | Sub-domains folded in for 1.0 | Publishes (main events) | Consumes | Sync calls it exposes (`/internal`) | Sync calls it makes |
|---|---|---|---|---|---|---|
| **gateway** (Traefik) | edge routing, rate limiting, access logs, request ids | — | — | — | — | — |
| **identity** | users, credentials, PINs, roles, permissions catalogue, JWT issuance, refresh tokens, terminal credentials, login history, platform admins | — | user.*, login.failed, terminal_credential.revoked | tenant.*, terminal.*, plugin.permissions_registered | jwks, service tokens, introspect | tenant (activation code check) |
| **tenant** | tenants, plans, subscriptions, feature flags, branches, terminals, devices, settings hierarchy, custom-field definitions | platform administration | tenant.*, branch.*, terminal.*, setting.changed, custom_field.changed | identity (user counts), plugin | tenant status, terminal activation | — |
| **catalog** | products, variants, barcodes, categories, brands, units, modifiers, product-branch availability, images | — | catalog.product.upserted/deleted, catalog.category.*, catalog.modifier_group.*, catalog.unit.*, catalog.brand.* | tenant.*, pricing.tax_group.* (projection), print.kitchen_station.* (projection) | product lookup | pricing (validate tax group) |
| **pricing** | taxes, tax groups, price lists, price-list assignments (branch, customer group), discounts, pricing/tax quote engine | — | pricing.tax.*, pricing.tax_group.*, pricing.price_list.*, pricing.discount.* | tenant.*, catalog.product.* (ids), customer.customer_group.* | quote (prices + taxes for lines) | — |
| **inventory** | stock levels, stock ledger, reservations/decrements, adjustments, transfers, conflicts, reorder levels | purchasing: suppliers, purchase orders, GRNs, returns, supplier payments | inventory.stock.updated, stock.low, stock.conflict_detected, grn.posted, purchase_order.*, transfer.*, adjustment.posted | tenant.*, catalog.product.* (projection), sales.sale.completed (offline origin), sales.sale.refunded, sales.sale.voided | reserve-and-decrement, release, levels | — |
| **sales** | sales, sale items, tender snapshot, hold/recall, void, refund, cash shifts, approvals, numbering | restaurant: floor areas, tables, orders, order items, KOT/BOT generation | sales.sale.completed/voided/refunded, shift.*, order.*, kot.created, approval.* | tenant.*, plugin.* (projection), payment.payment.recorded (status), print.print_job.* (status) | record offline sale/order (from sync) | inventory (reserve), customer (credit check), payment (authorize/capture/refund), plugin (BEFORE hooks), print (relay jobs) |
| **payment** | payment methods, payments lifecycle, gateway integrations, refunds at gateway, settlement | — | payment.recorded/failed/refunded, payment_method.changed | tenant.*, sales.sale.completed (records tenders), sales.sale.refunded | authorize, capture, refund, methods | gateways (external) |
| **customer** | customers, groups, credit ledger, credit limits, core loyalty, duplicate merge | — | customer.upserted/deleted/merged, ledger.entry_added, loyalty.points_changed, customer_group.changed | tenant.*, sales.sale.completed/refunded (ledger, loyalty), sync (offline customer upserts via internal call) | credit check, customer lookup, offline upsert | plugin (loyalty.calculateEarn hook) |
| **sync** | sync inbox, batch processing, POS replica (pull feed), terminal sync state, snapshot | — | sync.batch_processed, sync.item_rejected, sync.conflict_detected | every `*.upserted/deleted/changed` event that the POS needs (builds `pos_replica`) | push, pull, snapshot (public) | sales, customer (apply offline items) |
| **notification** | channels (mail, SMS, in-app, webhook), templates, delivery log | — | notification.sent/failed | tenant.*, inventory.stock.low, sync.item_rejected, print.print_job.failed, plugin.circuit_tripped, sales.* (e-receipt) | send | providers (external) |
| **reporting** | read models (sales facts, stock facts, purchase facts, daily summaries, audit facts), report registry, exports, dashboards | — | report.ready | all business events + audit.recorded | — | — |
| **plugin** | plugin registry, versions, lifecycle, tenant enablement, settings (encrypted), permissions declaration, **Plugin Runtime** (executes plugin backend code and hook handlers), workflows, approvals rules, execution logs, circuit breaker | extension kernel | plugin.installed/enabled/disabled/updated/uninstalled, plugin.setting_changed, plugin.permissions_registered, workflow.changed, plugin.circuit_tripped | tenant.*, all business events (AFTER hooks) | run hook (point, payload), enabled plugins per tenant | any service contract the plugin is permitted to call |
| **print** | printers, printer routes, kitchen stations, print templates, print job records, agent heartbeats, cloud print relay | — | print.printer.*, printer_route.changed, print_template.changed, kitchen_station.changed, print_job.created/printed/failed | tenant.*, sales.kot.created, sales.sale.completed (relay jobs when configured) | create job (relay) | — |
| **Print Agent** (.NET, on the terminal PC) | local printing, discovery, local queue | — | heartbeat to print service | pulls relay jobs when acting as branch print server | local loopback API for the POS | print service |

Extraction candidates that stay folded in 1.0 and their trigger: purchasing out of inventory (when purchasing gets its own team or heavy imports), restaurant out of sales (when kitchen display/KDS arrives), platform admin out of tenant (never needed for scale).

---

## 4. Physical deployment: the Service Host

Logical services are the unit of ownership and contracts. The unit of deployment is a **Service Host**: one Laravel application (`services/host`) that boots the service packages listed in its `SERVICES` environment variable. Each service is a Composer path package (`services/sales` → `flexipos/service-sales`) with its own service provider, routes, database connection, migrations, consumers and scheduled jobs.

```text
Phase 0 (this VPS, 1 vCPU / 4 GB):   host-all   SERVICES=identity,tenant,catalog,pricing,inventory,sales,payment,customer,sync,notification,reporting,plugin,print
Stage 2 (cloud):                     host-txn   SERVICES=sales,payment,sync            (write-heavy, autoscaled)
                                     host-data  SERVICES=catalog,pricing,customer,inventory
                                     host-core  SERVICES=identity,tenant,plugin,print,notification
                                     host-rpt   SERVICES=reporting                     (own read replica)
Stage 3:                             one host per service where load or ownership justifies it
```

Rules that make the split a configuration change, not a rewrite:

1. **Data**: each service has its own schema and its own database user with grants only on that schema (§6). Co-location never grants access to another schema.
2. **Calls**: services call each other only through client interfaces in `packages/contracts`. Two transports implement every interface: `Local` (in-process, used when the callee is booted in the same host) and `Http` (via the gateway, used otherwise). The transport is chosen per callee from config. Both map errors identically and both pass the same auth context, so behaviour differs only in latency and failure modes. In-process is mandatory when co-located: a PHP-FPM worker calling its own server over HTTP would exhaust the small worker pool on a 1 vCPU box.
3. **Events**: always Redis Streams, even between co-located services. No in-memory shortcuts.
4. **Tests**: every service has a contract test suite that runs against both transports.
5. **Migrations**: `php artisan svc:migrate <service>` runs only that service's migrations on that service's connection.

This satisfies "not a single monolithic application" (thirteen independently ownable, deployable services with private data) and "no unnecessary microservices in Phase 0" (one container set on the shared VPS).

---

## 5. Repository layout (monorepo)

```text
flexipos/
├── services/
│   ├── host/                     Laravel app: boots service packages from SERVICES env; nothing business-specific
│   ├── identity/  tenant/  catalog/  pricing/  inventory/  sales/  payment/  customer/
│   ├── sync/  notification/  reporting/  plugin/  print/
│   │   └── <service>/ src/ (Http, Models, Services, Consumers, Hooks), routes/, database/migrations/, tests/, composer.json
├── packages/                     shared Composer packages (versioned, no business logic)
│   ├── contracts/                DTOs, client interfaces per service, event classes + JSON Schemas, hook payload contracts, error codes
│   ├── kernel/                   TenantContext, tenant scopes, settings client, idempotency, audit writer, correlation ids
│   ├── eventbus/                 EventBus interface, Redis Streams adapter, outbox relay, inbox dedupe, consumer runner, DLQ
│   ├── auth/                     JWT verification (JWKS cache), service tokens, guards, permission resolver
│   ├── http-client/              typed clients, Local/Http transports, timeouts, retries, circuit breaker
│   ├── plugin-sdk/               ExtensionGateway (hook dispatch), plugin base classes, manifest validator
│   └── testing/                  two-tenant fixture, contract test base, isolation matrix generator
├── gateway/                      Traefik static + dynamic config, rate-limit and header middlewares
├── frontend/                     Angular workspace: projects/admin, projects/pos, projects/core-sdk, projects/plugin-*
├── print-agent/                  C# / .NET solution (Host, Core, Transports, Tray, Tests)
├── plugins/                      plugin source (plugin.json + backend/ + frontend/), built packages in object storage
├── infra/                        docker/ (images), compose/ (dev, vps, ci), cloudpanel/, github-actions/, scripts/ (xampp-dev.ps1)
└── docs/
```

Version pins (fixed in Phase 0): PHP 8.2+ (8.3 in Docker; XAMPP's PHP must be ≥ 8.2), current Laravel major, Angular 21, Node 22/24, .NET 8 LTS target (builds with the installed .NET 9 SDK; move to .NET 10 LTS when adopted), MySQL 8.4 LTS in Docker/VPS, MariaDB 10.4 only in XAMPP mode (§22), Redis 7, Traefik 3.

---

## 6. Database-per-service strategy

- **One schema per service, one MySQL user per service, grants limited to its schema.** In Phase 0 all schemas live on one MySQL 8.4 instance (`pos_identity`, `pos_tenant`, `pos_catalog`, `pos_pricing`, `pos_inventory`, `pos_sales`, `pos_payment`, `pos_customer`, `pos_sync`, `pos_notification`, `pos_reporting`, `pos_plugin`, `pos_print`). Moving a schema to its own instance is a dump/restore plus a connection string change.
- **No foreign keys across services.** Cross-service references are plain indexed ULID columns. Within a service, foreign keys are used normally.
- **Snapshots over joins.** Financial rows carry the data they need to be reproduced (product name, price, tax breakdown on `sale_items`).
- **Projections over lookups.** A service that repeatedly needs another service's reference data keeps a small read-only projection fed by events (`inventory.product_refs`, every service's `tenant_refs`/`branch_refs`). Projections are rebuildable from a snapshot endpoint of the owner.
- **Multi-tenant inside each schema.** Every table carries `tenant_id`; each service can shard its schema by tenant independently (`shard_map` per service, one shard in Phase 0).
- **Standard tables in every service schema**: `outbox_events`, `inbox_events`, `idempotency_keys`, `tenant_refs`, `branch_refs`, `audit_logs`.
- Full table ownership map, ERDs and column definitions: [database.md](database.md).

---

## 7. API contract strategy

### 7.1 Public API (through the gateway)

| Prefix | Service | Prefix | Service |
|---|---|---|---|
| `/api/v1/auth/*` | identity | `/api/v1/pos/sales|shifts|orders|approvals` | sales |
| `/api/v1/tenants|branches|terminals|settings|custom-fields|plans` | tenant | `/api/v1/payments|payment-methods` | payment |
| `/api/v1/products|categories|brands|units|modifiers` | catalog | `/api/v1/customers|customer-groups` | customer |
| `/api/v1/taxes|tax-groups|price-lists|discounts|pricing/quote` | pricing | `/api/v1/sync/push|pull|snapshot` | sync |
| `/api/v1/inventory/*|suppliers|purchase-orders|grns|purchase-returns` | inventory | `/api/v1/reports/*|dashboards/*` | reporting |
| `/api/v1/notifications/*` | notification | `/api/v1/plugins/*|workflows/*` | plugin |
| `/api/v1/printing/*|agent/*` | print | `/api/v1/platform/*` | tenant (platform admins) |

- Envelope on every response: `{ success, data, message, errors[{code, field, detail}], meta{request_id, pagination} }`. HTTP status codes: 401/403/404/409/422/429/503 as in v0.1.
- **Error codes are namespaced by service** (`SALES_STOCK_INSUFFICIENT`, `INVENTORY_UNAVAILABLE`, `AUTH_TOKEN_EXPIRED`) and catalogued in `packages/contracts`. A service that relays a downstream error keeps the downstream code and adds its own `source`.
- **Versioning**: URL major version per public API. Additive changes only within a version; breaking changes add `/api/v2/<resource>` for that resource only, and both versions are served for at least one release. Clients send `X-Client-Version`; the gateway returns `X-Min-Client-Version` from the tenant service's release table.
- **Idempotency**: all state-changing POSTs accept `Idempotency-Key` (the client ULID for POS documents). Keys are stored per service in `idempotency_keys` with the response for 24 h.
- **Pagination**: cursor for large/sync collections, page for admin grids. Filtering through a per-resource whitelist.
- **OpenAPI 3.1 per service**, generated from code, merged by CI into one public document; response contract tests validate every feature test's response against the schema.

### 7.2 Internal contracts (`/internal/*`, service-to-service only)

- Defined as PHP interfaces + DTOs in `packages/contracts` (`InventoryClient::reserveAndDecrement(ReserveRequest): ReserveResult`). The HTTP route and the JSON shape are generated from the DTOs so both transports stay identical.
- Reachable only with a service token (§10); the gateway never routes `/internal/*` from the outside.
- **Consumer-driven contract tests**: each caller ships a contract fixture (request + expected response shape) that the callee's CI runs. A callee cannot merge a change that breaks a consumer fixture.
- Timeouts and retry policy are part of the contract (§17). Reads: 2 s; writes: 5 s; retries only for idempotent operations.

Initial internal contracts: identity (jwks, service token, introspect) · tenant (tenant status, terminal activation, effective settings) · catalog (product lookup, product snapshot) · pricing (quote) · inventory (reserve-and-decrement, release, levels) · customer (credit check, lookup, offline upsert) · sales (record offline sale/order) · payment (authorize, capture, refund, methods) · plugin (run hook, enabled plugins, workflows) · print (create relay job) · notification (send).

---

## 8. Event-driven communication

### 8.1 Bus and topology (decision: Redis Streams, see eventbus.md)

- `packages/eventbus` exposes `EventBus::publish(DomainEvent)` and `Consumer` runners; business code never touches Redis APIs.
- **Streams**: one per producer service, `evt.<service>` (`evt.sales`, `evt.catalog`, …). Ordering is guaranteed per stream; the envelope carries `tenant_id` and `aggregate_id` so consumers can serialise per aggregate if needed.
- **Consumer groups**: one per consuming service per stream (`cg.inventory` on `evt.sales`). Each host runs `svc:consume <service>` workers for the services it boots.
- **Durability**: the outbox table is the source of truth. Streams are capped (`MAXLEN ~ 2 000 000`); anything older is replayable from `outbox_events` (90-day retention, archived after).
- **Delivery**: at-least-once. Consumers are idempotent through `inbox_events(event_id)` unique per service.
- **Retries and DLQ**: failed handling leaves the entry pending; the runner reclaims it with exponential back-off (1 s, 5 s, 30 s, 2 min, 10 min); after 5 attempts it is moved to `evt.dlq.<consumer>` and `notification` alerts. A DLQ replay command exists.
- **Backpressure**: consumer lag (`XPENDING` and `XINFO`) is a first-class metric with alerts; lag beyond a threshold triggers horizontal worker scale-out (cloud) or a capacity warning (VPS).
- **Migration path**: the adapter interface (`publish`, `subscribe`, `ack`, `nack`, `claim`) maps 1:1 onto RabbitMQ or Kafka clients; switching is a package change plus a dual-write period.

### 8.2 Event envelope (contract)

```json
{ "event_id": "01J…", "type": "sales.sale.completed", "version": 1,
  "occurred_at": "2026-09-28T10:15:03.120Z", "producer": "sales",
  "tenant_id": "01J…", "branch_id": "01J…", "aggregate_type": "sale", "aggregate_id": "01J…",
  "correlation_id": "req-01J…", "causation_id": "01J… (parent event or request)",
  "actor": { "type": "user|terminal|service|plugin", "id": "…" },
  "payload": { } }
```

- Types are `service.entity.action`; payload schemas live in `packages/contracts/events/<type>.v<version>.json` (JSON Schema) and generate the PHP event classes.
- **Compatibility rules**: adding optional fields keeps the version; removing or retyping a field is a new version; producers publish both versions for one release; consumers declare the versions they accept; CI fails on an undeclared version.
- Payloads are self-contained (a consumer must never need to call the producer to act), but they exclude secrets and full custom-field blobs beyond 8 KB (a `snapshot_url` is provided instead).

### 8.3 Event catalogue (initial)

| Event | Payload highlights | Consumers |
|---|---|---|
| tenant.created / updated / suspended / reactivated | tenant, plan, timezone, currency, status | every service (`tenant_refs`), identity, plugin, reporting |
| tenant.branch.upserted, tenant.terminal.upserted/revoked, tenant.setting.changed, tenant.custom_field.changed | entity snapshot | every service (`branch_refs`, settings cache, custom-field projection), sync (replica) |
| identity.user.upserted / deactivated | user, roles per branch, PIN hash (only in `sync`-targeted variant) | sync (POS users), reporting |
| catalog.product.upserted / deleted, catalog.category.*, modifier_group.*, unit.*, brand.* | full POS projection of the entity | inventory (`product_refs`), pricing (ids), sync (replica), reporting |
| pricing.tax.*, tax_group.*, price_list.*, price_list_item.*, discount.* | entity snapshot | catalog (`tax_group_refs`), sync, reporting |
| inventory.stock.updated | branch, product, movement, balance_after | sync (stock snapshot), reporting, notification (low stock rule) |
| inventory.stock.low, inventory.stock.conflict_detected | product, branch, quantities | notification, reporting |
| inventory.grn.posted, purchase_order.*, transfer.*, adjustment.posted | document snapshot | reporting |
| sales.sale.completed | sale + items + tenders + `origin`, `stock_applied` flag | inventory (offline origin only), payment, customer, reporting, notification, plugin, print (relay), sync (ack state) |
| sales.sale.voided / refunded | sale, reversal lines, refund tenders | inventory, payment, customer, reporting, plugin |
| sales.shift.opened / closed | shift totals | reporting |
| sales.order.created / updated, sales.kot.created | order, items, station | print (KOT routing for relay), reporting |
| sales.approval.requested / decided | request | notification (remote approval), plugin |
| payment.recorded / failed / refunded, payment.payment_method.changed | payment | sales (status), reporting, sync |
| customer.upserted / deleted / merged, customer.customer_group.changed | customer projection | pricing (group ids), sync, reporting |
| customer.ledger.entry_added, customer.loyalty.points_changed | entry | reporting, sync (balances for POS) |
| sync.batch_processed / item_rejected / conflict_detected | terminal, counts, item | notification, reporting, tenant (terminal sync state summary) |
| plugin.installed / enabled / disabled / updated / uninstalled (per tenant), plugin.setting_changed, plugin.permissions_registered, plugin.workflow.changed, plugin.circuit_tripped | plugin, tenant, version | sales/customer/… (hook registry projection), identity (permissions), sync (enabled plugins for POS), notification |
| print.printer.*, printer_route.changed, print_template.changed, kitchen_station.changed, print_job.created/printed/failed | entity | sync (replica), catalog (`kitchen_station_refs`), notification, reporting |
| audit.recorded (every service) | actor, action, subject, before/after | reporting (audit read model) |

---

## 9. Synchronous vs asynchronous communication and distributed transactions

### 9.1 Decision matrix

| Interaction | Mode | Why |
|---|---|---|
| Login, token refresh, terminal activation | sync (gateway → identity, identity → tenant) | user waits |
| **Online sale: stock validation + decrement** | **sync sales → inventory, strongly consistent** | trade-off.md: never oversell online |
| Online credit sale: credit-limit check | sync sales → customer | must block the sale |
| Card/gateway tender | sync sales → payment → gateway | authorisation result decides the sale |
| Recording cash/card tenders after a completed sale | async (payment consumes `sale.completed`) | bookkeeping, already tendered |
| Customer ledger, loyalty, reporting, notifications, print relay, plugin AFTER hooks | async | not on the checkout path |
| Plugin BEFORE / FILTER / OVERRIDE hooks | sync service → plugin runtime (only when a tenant has a registration for that point) | can veto or transform |
| Admin CRUD | sync to the owning service | direct |
| POS pull feed | sync read from sync service, fed async by events | POS must not call ten services |
| Offline batch apply | sync sync → sales/customer per item; downstream async | see §12 |

Rule of thumb: a call is synchronous only when the caller cannot correctly proceed without the answer. Everything else is an event.

### 9.2 Sagas (distributed transactions)

**S1 Online sale** (orchestrated by sales, compensation on failure):

```text
POST /api/v1/pos/sales  (Idempotency-Key = sale ULID)
 1. sales: idempotency check, validate envelope, recompute totals with the shared engine (tolerance = rounding unit)
 2. sales → customer.creditCheck            only if balance_due > 0        fail → 422 CUSTOMER_CREDIT_EXCEEDED
 3. sales → inventory.reserveAndDecrement   lines with track_stock=true    409 → 422 SALES_STOCK_INSUFFICIENT (+lines)
        (inventory posts `sale` stock_movements atomically per branch/product with row locks; idempotent by sale_id)
 4. sales → payment.authorize               only for gateway tenders       fail → inventory.release(sale_id) → 402
 5. sales: BEGIN; insert sale, sale_items, sale_tenders; insert outbox sales.sale.completed{origin: online, stock_applied: true}; COMMIT
        fail → inventory.release(sale_id) and payment.void(auth) via retrying compensation job → 500
 6. 201 to POS; async: payment.capture + record, customer ledger/loyalty, reporting, notification, print relay, plugin AFTER hooks
```

Inventory unavailable or timing out at step 3 → `503 INVENTORY_UNAVAILABLE`. The POS then behaves per tenant setting `pos.degrade_to_offline_when_inventory_down` (default `true`): it queues the sale as an **offline** sale (a fact, no stock check) and shows a warning; with `false` the cashier must retry. This is documented degradation, not silent bypass.

**S2 Refund**: sales validates against the original sale → for gateway tenders `payment.refund` (sync) → sales writes the refund sale + outbox `sale.refunded` → inventory restocks (event) → customer ledger, reporting (events). Cash refunds need no sync call.

**S3 Offline batch** (§12): no compensation; every item is applied idempotently and anomalies are flagged (`stock_conflicts`, `possible_duplicate_of`).

**S4 Tenant provisioning**: tenant publishes `tenant.created` → each service seeds its defaults and publishes `tenant.provisioned{service}` → tenant marks `ready` when all thirteen have reported (timeout 5 min → alert, retry command).

**S5 Plugin install/enable**: plugin service validates, runs plugin migrations in `pos_plugin`, publishes `plugin.enabled{tenant}` → services refresh hook registries, identity registers permissions, sync adds remotes to the POS replica.

**S6 Stock transfer / GRN / adjustment**: single service, local transaction, events after commit.

---

## 10. Authentication between services

| Concern | Design |
|---|---|
| Token format | RS256 JWTs issued by **identity**. Public keys at `/.well-known/jwks.json`, cached by every service (`packages/auth`), rotated with overlapping `kid`s. |
| End-user token | `sub` (user id), `tenant_id`, `branch_ids[]`, `terminal_id` (POS session), `token_type: user|pos|terminal|platform`, `perm_hash` (permission snapshot version), `exp` 15 min (users) / 8 h (POS session) / 30 d (terminal, revocable). Refresh tokens stored in identity, rotated on use. |
| Terminal token | issued at activation; bound to device fingerprint; revocation via short expiry + Redis denylist checked by the gateway and services. |
| Cashier on terminal | PIN login on top of the terminal token → `pos` token. Offline: PIN hashes and permission snapshot in the POS replica (from `identity.user.upserted`, sync-targeted variant). |
| Platform admin | `token_type: platform`, no `tenant_id`; impersonation mints a tenant-scoped token with `act.impersonated_by`, audited. |
| Service identity | client-credentials tokens from identity: `sub: service:sales`, `aud: inventory`, `exp` 10 min, cached. Required on every `/internal/*` route. The callee checks `aud` and an allow-list of caller services per route. |
| Context propagation | caller forwards the end-user JWT unchanged as `X-User-Token` (tenant/branch/user context) and its service token as `Authorization`. Background jobs without a user present a service token with an explicit `act.tenant_id` claim, which only service tokens may carry. |
| Gateway | Traefik validates nothing cryptographically in Phase 0 (services verify every token); it enforces rate limits, blocks `/internal/*`, and injects `X-Request-Id` if absent. ForwardAuth to identity can be enabled later for edge rejection of bad tokens. |
| Internal network | Docker network `pos_backend` is internal-only; only the gateway is reachable from the host loopback. mTLS between hosts is added when services leave one machine. |
| Permission evaluation | services resolve permissions locally from a cached snapshot keyed by `perm_hash` (fetched from identity on miss). Plugin permissions are registered into identity through `plugin.permissions_registered`. |

---

## 11. Tenant isolation strategy

1. **Tenant of record is `tenant` service**; every other service keeps `tenant_refs` (id, status, timezone, currency, locale) updated by events and rejects requests for `suspended` tenants locally, without a sync call.
2. **Context**: `TenantContext{tenant, branch, user, terminal, device, requestId}` is built by `packages/kernel` from the verified JWT, never from the request body. Queue jobs and consumers restore it from the event envelope or job payload.
3. **Data**: every table has `tenant_id`; `TenantModel` applies the global scope and fills it on create; composite unique keys and indexes lead with `tenant_id`; a service can shard its schema by tenant (`shard_map`).
4. **Branch**: `X-Branch-Id` must be in the token's `branch_ids`; terminal tokens fix the branch.
5. **Cache**: keys prefixed `t:{tenant_id}:` via the kernel cache repository; global cache is a different store.
6. **Events**: consumers set context from `tenant_id` in the envelope; a consumer never processes an event for a tenant it cannot find in `tenant_refs` (parked and retried, alert after 1 h).
7. **Plugins**: the Plugin Runtime receives tenant context per hook call and can only call contracts with a service token scoped to that tenant; plugin data is in `pos_plugin` with `tenant_id`.
8. **Tests**: the isolation matrix runs per service (all routes, Tenant A with Tenant B ids → 403/404), plus event consumers, cache keys, and plugin hook calls.
9. **Physical isolation** for a VIP tenant: dedicated schemas per service on a separate instance through the per-service shard map.

---

## 12. Offline synchronisation architecture

```text
POS (IndexedDB)  ── push batches ─► sync.push ─► inbox (idempotent by item ULID, ordered by client_seq)
                                                 ├─ sale/order/shift/payment → sales.recordOffline*   (Local/Http contract)
                                                 ├─ customer upsert            → customer.offlineUpsert
                                                 └─ per-item ack: accepted | duplicate | rejected | conflict | waiting_dependency
POS  ◄── pull (since=version) ──── sync.pull ◄── pos_replica (read model built from catalog/pricing/customer/tenant/identity/print/plugin events)
POS  ◄── snapshot ────────────── sync.snapshot (full replica for new terminals or recovery)
```

- **Principles unchanged from v0.1**: sales are facts; master data is server-authoritative and never edited offline; customers and restaurant orders are the only offline-editable shared entities; client ULIDs and per-terminal `client_seq`; the same sales domain service records online and offline sales.
- **Difference from online**: an offline sale skips S1 steps 2–4. `sales.sale.completed{origin: offline, stock_applied: false}` makes inventory post the movement on consumption; a negative result opens `stock_conflicts` and fires `inventory.stock.conflict_detected`. Credit sales offline are allowed up to the cached limit; the ledger entry is created on consumption.
- **POS replica**: `sync` stores one document per POS entity (`tenant_id, entity_type, entity_id, version, payload, deleted`) with a per-tenant monotonic version; pull returns documents since a version; compaction keeps the latest per entity. Entities: products, categories, modifiers, units, taxes, tax groups, price lists, discounts, customers (projection with balances), payment methods, settings (effective per branch/terminal), printers, routes, templates, kitchen stations, POS users (PIN hashes, permissions), enabled plugins (remote manifests).
- **Conflict rules** (v0.1 §10.4 unchanged): duplicates by ULID; oversell → accept + conflict record; stale price → snapshot kept, report; product changed on server → server wins; customer duplicate → `possible_duplicate_of`; customer edited both sides → field merge, server wins on newer `updated_at`, discarded values audited; orders → append-only union with monotonic item status; payment/refund before parent → `waiting_dependency`; clock skew → clamped business date with audit flag. Plugins extend rules through the `sync.conflict` FILTER hook.
- **Invoice numbering**: terminal-prefixed (`{branch}{terminal}-{seq:06}`) allocated locally, mirrored and reuse-rejected by sales. `numbering.generate` is an overridable hook for jurisdictions with other rules.
- **Terminal status**: heartbeat every 5 min while online (piggybacked on pull) → tenant service updates `last_seen_at`; sync service keeps `terminal_sync_state`.

---

## 13. Printer architecture (Print Management service + .NET Print Agent)

Unchanged from v0.2 in substance; ownership is now explicit.

- **print service** owns printers, routes, kitchen stations, templates and the server-side `print_jobs` record; it consumes `sales.kot.created` and `sales.sale.completed` to create relay jobs when a branch uses a branch print server; it receives agent heartbeats and printer status.
- **Print Agent** (C#/.NET 8 LTS Worker Service, Kestrel loopback API on `127.0.0.1:7788`, pairing code, SQLite queue, Windows Service via WiX MSI, Linux systemd build): discovery through Windows spooler RAW (`winspool`), WinUSB/libusb, TCP 9100 + mDNS; rendering with Handlebars.Net to ESC/POS (ESCPOS_NET) and ZPL; retry with back-off; job log; auto-update from the signed MSI announced by heartbeat; optional WinForms tray.
- Routing is resolved on the POS from the replica (`printer_routes`, `kitchen_stations`), so it works offline; fallback is the browser print dialog with the same Handlebars template rendered to HTML.
- Shared printers across terminals go through the cloud relay (agent in branch-print-server mode polls `print.jobs`), because an HTTPS PWA cannot call a LAN IP directly.

---

## 14. Plugin architecture across services (Plugin Runtime)

Plugins must hook core operations in several services without turning every service into a plugin host. v0.3 therefore runs **all plugin backend code in the plugin service** ("Plugin Runtime") and gives every other service a thin, contract-based hook gateway.

```text
sales.completeSale()
   └─ ExtensionGateway::run('sale.beforeComplete', $payload)   (packages/plugin-sdk)
         ├─ registry projection says: tenant has no handler for this point → return immediately (no call)
         └─ has handler → plugin.runHook (Local/Http contract, timeout per point, circuit breaker)
                 └─ Plugin Runtime loads the tenant's enabled plugins, runs BEFORE/FILTER/OVERRIDE handlers in priority order,
                    returns { decision: continue|veto, payload', override_result?, reason, code }
AFTER hooks = the Plugin Runtime consuming the business event (no call from the core service)
```

| Aspect | Design |
|---|---|
| Package | `plugin.json` manifest (spec §23 + `entry.backend`, `entry.frontend`, `extensionPoints`, `settingsSchema`, `database.tables`, `visibility`, `signature`); `backend/` is a Laravel package loaded only by the Plugin Runtime; `frontend/` is a Native Federation remote |
| Registry and lifecycle | plugin service: upload → validate (manifest, signature, `coreVersion`, dependencies) → install (migrations in `pos_plugin`, tables prefixed `plg_<name>_`, always with `tenant_id`) → enable per tenant → update → disable → uninstall (data deleted only on explicit admin confirmation) |
| Extension registry | declared in `packages/contracts/hooks/` with payload types, allowed modes, `overridable`, `failurePolicy`, timeout. Backend points as in v0.1 §13.3 plus `loyalty.calculateEarn [filter, overridable]`; client points unchanged (`pos.toolbar`, `checkout.complete [overridable]`, …) |
| Modes | ADD (UI/registries), BEFORE (may veto with a typed reason), AFTER (event-driven), FILTER (transform payload), OVERRIDE (only on `overridable` points, one active override per tenant per point) |
| Failure isolation | the hook gateway wraps every call: timeout (default 2 s), circuit breaker per point per tenant, `failurePolicy` per point (financial BEFORE points fail closed; others fail open); the runtime logs `plugin_execution_logs` and trips `plugin.circuit_tripped` after N failures; a plugin crash can never take down sales |
| Plugin data | `pos_plugin` schema only; a plugin extends core entities through custom fields or its own tables keyed by core ids |
| Plugin calls to core | through the same contracts, with a service token scoped to the tenant and limited to the permissions the manifest declares; routes registered by a plugin live under `/api/v1/plugins/<name>/*` and require a declared permission |
| Frontend | unchanged: `core-sdk` PluginApi, `flexExtensionPoint` slots, HookBus, Native Federation remotes listed by `plugin.enabledPlugins`, cached by the service worker |
| Workflows and approvals | `workflow_definitions` in plugin service, executed by the runtime as hook handlers; approvals are recorded in sales (`approval_requests`) through the sales contract |
| Compatibility | semver `coreVersion` checked on install/enable/update; `plugins:check-compat --core=<next>` in the release gate |
| Security | signing, permissions, static analysis in the plugin CLI, encrypted settings, per-plugin execution metrics; third-party visibility disabled in 1.0 |

Trade-off: a BEFORE hook costs one contract call. Co-located (Phase 0) it is an in-process call; split, it is one HTTP call on the backend network (a few milliseconds). Registrations are cached, so points without handlers cost nothing.

---

## 15. Configuration and custom fields

- **Settings hierarchy** platform → tenant → branch → terminal → user lives in the tenant service; a typed registry (each service and plugin declares keys, types, defaults, `syncToPos`); effective settings are cached per scope and published by `tenant.setting.changed`; services read through the kernel settings client (cache first).
- **Custom fields**: definitions in the tenant service (`custom_field_definitions`); each owning service validates the `custom_fields` JSON column of its entities against a projection refreshed by `tenant.custom_field.changed`; indexed fields become generated columns in the owning schema through a controlled migration job.
- **Config before plugin**: labels, taxes, templates, routing, permissions, custom fields, price lists, simple workflows and feature toggles are configuration; new logic, integrations, UI and controlled core changes are plugins.

---

## 16. Angular architecture

Unchanged from v0.1 §15: `core-sdk` (API client, auth store, context, interceptors, guards, i18n, PluginApi, ExtensionPoint, HookBus), `pos` (offline-first PWA, Signals stores, Dexie), `admin` (lazy feature areas), `plugin-*` remotes. The frontend talks only to the gateway; it never knows the service topology. The calculation engine (tax, discount, rounding) is a pure TypeScript package with fixture parity tests against the PHP engine in `pricing` and `sales`.

---

## 17. Error handling, retries, idempotency, resilience

| Concern | Rule |
|---|---|
| Error envelope | identical across services; codes namespaced; downstream errors relayed with `source` |
| Timeouts | internal reads 2 s, writes 5 s, plugin hooks per point (default 2 s), gateway upstream 30 s |
| Retries | only idempotent calls (GET, or POST with Idempotency-Key), max 2, jittered back-off, never on 4xx |
| Circuit breaker | per callee in `packages/http-client` (open after 50 % failures over 20 calls, half-open after 30 s); fallback behaviour is defined per call: inventory down → `INVENTORY_UNAVAILABLE` (POS may degrade to offline), customer down → credit sales rejected with `CUSTOMER_UNAVAILABLE`, plugin runtime down → fail policy of the point |
| Idempotency | `idempotency_keys` per service for public POSTs; item ULIDs in sync inbox; `inbox_events` for consumers; `reserveAndDecrement` idempotent by sale id |
| Compensation | sagas S1/S2 define compensating calls executed by a retrying job (`saga_steps` table in sales) until success or manual intervention |
| Event failures | retry schedule then DLQ with alert and replay command |
| Validation | Form Requests at the edge of every service; DTO validation in contracts; schema validation of events on publish and consume |
| Poison data | rejected sync items and DLQ entries are never deleted automatically; they appear in admin review screens |

---

## 18. Logging, monitoring, tracing

- **Structured JSON logs** with `request_id`, `trace_id`, `span_id`, `tenant_id`, `branch_id`, `terminal_id`, `user_id`, `service`, `plugin_id`, `event_id`. On the VPS, logs go to Docker json-file with rotation; in the cloud, to the provider's log service or Loki.
- **Distributed tracing** with OpenTelemetry: the gateway starts or propagates `traceparent`; HTTP transports and the event envelope carry it; Local transport creates child spans. Exporter: OTLP to a collector (optional on the VPS, on by default in the cloud).
- **Metrics** (Prometheus exposition per host): request latency by route and service, internal call latency and error rate by callee, stream lag by consumer group, DLQ size, outbox pending age, sync batch outcomes, print job failures from heartbeats, offline terminals, plugin hook duration and trips, MySQL slow queries, PHP-FPM pool saturation.
- **Errors**: Sentry in every unit (services tagged, Angular apps, Print Agent).
- **Health**: `/health/live` and `/health/ready` per host (per booted service: DB connection, Redis, consumer heartbeat); the gateway routes health for external checks.
- **Alerts**: DLQ growth, consumer lag, outbox age > 60 s, gateway 5xx rate, terminals offline > 4 h, plugin circuit trips, VPS RAM > 85 %.

---

## 19. Security model

Unchanged from v0.1 §17 with these additions: JWT keys rotated quarterly (JWKS overlap); service tokens short-lived with `aud` and caller allow-lists; `/internal/*` unreachable from the gateway; internal Docker network; per-service database credentials with schema-only grants; plugin runtime as the only place that executes third-party PHP; secrets in `.env` on the VPS with file permissions 600 (secrets manager in the cloud); OWASP ASVS L2 checklist and ZAP baseline before GA; automated isolation and contract suites required to merge.

---

## 20. Deployment architecture on the existing VPS

Full detail, resource budget, ports and runbook: [deployment-vps.md](deployment-vps.md). Summary:

```text
Internet ─► CloudPanel Nginx (existing, TLS via Let's Encrypt, new "reverse proxy" site pos.<domain>)
                │  proxy_pass http://127.0.0.1:8088   (the ONLY host port the stack binds, loopback only)
                ▼
        pos-gateway (Traefik)  ── pos_edge network ──► pos-web (Angular static, nginx)  and  pos-api (host-all)
                                                          pos_backend network (internal): pos-api, pos-worker, pos-scheduler,
                                                          pos-mysql (8.4, 13 schemas), pos-redis-data (streams/queues, noeviction),
                                                          pos-redis-cache (LRU)
```

- No host networking, no published ports except `127.0.0.1:8088`, named volumes under Docker, memory and CPU limits on every container, `restart: unless-stopped`, log rotation.
- Budget ≈ 1.5 GB RAM for the whole stack against ≈ 2.6 GB free today; the VPS is a **pilot and staging** environment, not the capacity target.
- Deploy = `docker compose pull && docker compose up -d` with an expand-only migration job first; rollback = previous image tags.
- Moving to a dedicated VPS or cloud = same compose (or its Kubernetes translation) with the schemas dumped/restored and DNS switched.

---

## 21. Scaling strategy: 500 tenants, 3 000 terminals, 300 000 sales/day

**Load model.** 300 000 sales/day ≈ 3.5/s average; retail peaks are 8–10× → plan for **35 sales/s**. Each online sale ≈ 1 gateway request, 1–2 internal calls, 6–8 events, ~12 row inserts. Offline pushes add batches of ≤ 50 items with the same per-item cost. Pull: 3 000 terminals every 5 min ≈ 10 req/s. Rows/year at target: sales ≈ 110 M, sale_items ≈ 400 M, stock_movements ≈ 400 M, events ≈ 2.5 M/day.

| Stage | Infrastructure | Capacity (indicative) | What changes |
|---|---|---|---|
| 0 Pilot (now) | shared Hostinger KVM 1: 1 vCPU, 4 GB, one host-all | ≤ 20 tenants, ≤ 100 terminals, ≈ 3 sales/s burst | nothing; measure |
| 1 Dedicated VPS | 4 vCPU / 16 GB (or 2 VPS: app + DB) | ≤ 150 tenants, ≤ 1 000 terminals, 10–15 sales/s | separate worker containers per queue/stream, MySQL tuned (buffer pool 8 GB), nightly partitions, optional read replica for reporting |
| 2 Cloud (Azure/AWS) | containers with autoscaling (Container Apps / ECS / AKS / EKS), managed MySQL with replica, managed Redis, object storage, CDN | **500 tenants, 3 000 terminals, 35 sales/s peak** | split hosts: `host-txn` (sales, payment, sync) 3–6 replicas; `host-data` 2–4; `host-core` 2; `host-rpt` on the replica; consumers scaled by lag; monthly partitions on ledgers; event bus to RabbitMQ/Kafka only if stream lag persists |
| 3 Beyond | per-service instances, per-service tenant sharding, multi-region | thousands of tenants | shard maps per service; regional gateways; reporting to a warehouse |

Capacity levers, in order: (1) more host replicas behind the gateway (stateless), (2) more consumer workers, (3) MySQL vertical then read replica, (4) split hosts by service group, (5) shard schemas by tenant, (6) extract a service to its own database instance. Performance budget: checkout p95 < 300 ms online at stage 2; sync batch of 50 items < 2 s; pull delta < 200 ms.

---

## 22. Development environment (Windows 11 + XAMPP + Docker Desktop)

Detected on this machine: Node 24, Angular CLI 21, .NET SDK 9, Docker Desktop 28 (WSL2 present), Git 2.45, XAMPP at `E:\xampp` with MariaDB 10.4.32; PHP and Composer are not on PATH (see backlog FP-004).

| Mode | Use | Notes |
|---|---|---|
| **Docker (primary)** | `infra/compose/dev.yml`: gateway, host-all, worker, MySQL 8.4, two Redis, mailpit; frontend via `ng serve`; agent via `dotnet run` | identical images to the VPS; required for sync, streams and contract tests |
| **XAMPP mode (supported)** | Apache vhost `pos-api.test` → `services/host/public`; `infra/scripts/xampp-dev.ps1` creates the 13 schemas and users on XAMPP MariaDB; Redis from Docker Desktop (`redis-only` profile) or Memurai; consumers with `php artisan svc:consume` in terminals | MariaDB 10.4 lacks some MySQL 8 features (functional indexes, some JSON functions); CI runs MySQL 8.4 so divergence is caught. XAMPP PHP must be ≥ 8.2 with `pdo_mysql`, `mbstring`, `openssl`, `intl`, `bcmath`, `gd`, `zip`, `fileinfo`; Composer installed separately |
| Hybrid (recommended for you) | PHP from XAMPP, MySQL 8.4 and Redis from Docker Desktop | keeps the database identical to production while using the familiar XAMPP PHP |

---

## 23. Testing architecture

| Layer | Tooling | Covers |
|---|---|---|
| Unit per service | Pest | domain services, calculation engine, conflict rules, hook gateway, permission resolver |
| Feature per service | Pest + service factories + two-tenant fixture | public routes, envelope, error codes, OpenAPI contract |
| **Contract** | consumer-driven fixtures in `packages/contracts`, run in callee CI against both transports | every `/internal` contract and every event schema/version |
| Isolation | generated matrix per service + consumers + cache + plugin hooks | Tenant A never touches Tenant B |
| Saga / integration | `infra/compose/ci.yml` boots host-all + MySQL + Redis; scenario tests | S1 with stock insufficient, inventory down, gateway failure, compensation; S3 offline batch; S4 provisioning |
| Sync | Pest scenarios | idempotent push, duplicates, out-of-order, dependency wait, conflicts, replica compaction, snapshot |
| Plugins | fixture plugins in the runtime | lifecycle, permission refusal, override allow-list, circuit breaker, compat gate |
| Frontend | Jest unit, Playwright e2e (offline scenarios), calculation parity fixtures | POS flows online/offline, plugin slot visibility per tenant |
| Print Agent | xUnit, mock transports, golden ESC/POS/ZPL files | queue, retry, routing, printer-down |
| Performance | k6 against `ci.yml` and staging | checkout p95, sync bursts, pull delta, consumer lag |
| Security | ZAP baseline, dependency audits, isolation suite | before every release |

---

## 24. Architecture decision records

| ADR | Decision | Why | Alternatives | Trade-offs (advantages / disadvantages, scale, cost, maintenance, security, complexity) |
|---|---|---|---|---|
| 001 (revised) | Logical microservices deployed through a configurable Service Host; co-located in Phase 0 | honours "no single monolith" (ownership, private data, independent deployability) and "no unnecessary services" on a 1 vCPU VPS | one container per service from day one (≈ 3 GB RAM, 13 FPM pools on 1 vCPU); plain modular monolith with shared DB (violates data ownership) | + one image, split by config, cheap now; − discipline needed (Deptrac rules, grants); scale by adding hosts; low cost; medium maintenance; security unchanged; medium complexity |
| 002 | Multi-tenant schemas per service with per-service shard maps | thousands of tenants; VIP physical isolation | DB per tenant per service (explodes); shared everything | as v0.1 ADR-002, applied per service |
| 003 | ULID primary keys | offline id generation, index locality | UUIDv4, UUIDv7 binary, bigint + client uuid | as v0.1 |
| 004 (revised) | RS256 JWTs from identity; refresh tokens; short-lived service tokens with `aud` | services must verify tokens without calling identity; service identity needed for `/internal` | Sanctum opaque tokens (needs introspection call per request per service); mTLS only (no user context) | + stateless verification, standard; − key management, revocation via denylist; scales; free; low maintenance; strong; medium complexity |
| 005 (revised) | Online sale strongly consistent on stock (reserve-and-decrement, reject on failure); offline sales are facts with conflict records | trade-off.md | eventual stock for online too (oversells); reject offline sales (loses money) | + no online oversell, offline still works; − one sync call on checkout, degraded mode when inventory is down; scales (row locks per branch/product); low cost; medium complexity |
| 006 | Terminal-prefixed invoice numbering, overridable | offline-final numbers | renumber on sync; reserved blocks | as v0.1 |
| 007 | Print Agent in C#/.NET 8 with SQLite queue | product-owner decision; Windows hardware | Node, Go, Electron | as v0.2 |
| 008 (revised) | Plugin Runtime: plugin backend code runs in the plugin service; core services dispatch hooks through a contract | database-per-service and failure isolation forbid loading third-party code in every service | plugin parts loaded in each service (13 loaders, shared plugin DB); webhooks only (no UI, no veto) | + single loader, crash isolation, one plugin schema; − one call per BEFORE hook (in-process when co-located); scales with the plugin host; low cost; medium maintenance; better security; high complexity (core of the product) |
| 009 | Custom fields: definitions in tenant, JSON column in owning services | no schema changes per client | EAV, real columns | as v0.1 |
| 010 | Transactional outbox per service + inbox dedupe | never lose `sale.completed`; at-least-once needs idempotent consumers | fire-and-forget events; broker-first | as v0.1, plus inbox table |
| 011 | Settings hierarchy as first customisation tool | spec §40 | config files, plugin per difference | as v0.1 |
| 012 | Redis Streams as the event bus behind `EventBus` | eventbus.md: Redis already present, lowest VPS footprint | RabbitMQ (native DLQ, extra container), Kafka (heavy) | + no new infra, consumer groups, replay from outbox; − app-level retry/DLQ, capped streams; scales to stage 2, migrate if lag; low cost; medium maintenance |
| 013 | Database-per-service as schemas + dedicated users on one MySQL instance in Phase 0 | ownership enforced by grants without 13 instances | 13 MySQL containers (RAM), one shared schema (forbidden) | + cheap, enforceable, movable; − one instance is a shared failure domain until stage 2; low complexity |
| 014 | Traefik gateway behind the existing CloudPanel Nginx, loopback-only port | requirement: reuse existing reverse proxy, isolate ports | Kong (heavier), Nginx-only (no dynamic routing), Laravel BFF (extra hop) | + path routing, rate limits, later ForwardAuth and TLS; − one more container (≈ 64 MB); low complexity |
| 015 | Dev: Docker Desktop primary, XAMPP mode supported, hybrid recommended | developer uses XAMPP; requirement says Docker | Docker only (blocks current workflow); XAMPP only (MariaDB 10.4 drift) | + flexibility; − two paths to maintain (scripts), CI on MySQL 8.4 catches drift |
| 016 | Reporting as event-built read models (CQRS) | no cross-schema joins allowed | read replica with joins (forbidden), federated queries (slow) | + reporting isolated from OLTP, scalable; − eventual freshness (seconds), rebuild tooling needed; medium complexity |

---

## 25. Open questions (remaining)

| # | Question | Default assumed |
|---|---|---|
| Q1 | Launch vertical: retail, restaurant, both? | retail first, restaurant in Phase 10 |
| Q2 | Tax regime and receipt-numbering law for the launch country | percentage VAT (inclusive/exclusive), terminal-prefixed numbering |
| Q3 | Gateways in 1.0 | none live; card as reference |
| Q4 | Languages | English first |
| Q5 | Domain name for the pilot on the VPS (`pos.<domain>`) and whether the existing MariaDB may be used as a fallback if RAM runs short | separate `pos-mysql` container; fallback documented |
| Q6 | Cloud provider for stage 2 | decided when stage 1 limits are reached |
| Q7 | Who approves third-party plugins | third-party disabled in 1.0 |
