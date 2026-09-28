# FlexiPOS — Backlog (start to end)

| | |
|---|---|
| Status | **DRAFT v0.3** — restructured for the service architecture; nothing started. v0.1 task IDs are superseded |
| Date | 2026-09-28 |
| Design inputs | [architecture.md](architecture.md) · [database.md](database.md) · [deployment-vps.md](deployment-vps.md) · [eventbus.md](eventbus.md) · [trade-off.md](trade-off.md) |
| Status flow | `TODO → SPEC → PLANNED → IN_DEV → IN_QA → IN_REVIEW → DONE` (or `BLOCKED`) |
| Branch naming | `ai/FP-<ID>-<slug>` |

Streams: **BE** backend services, **FE** frontend, **PA** print agent (C#/.NET), **OPS** devops, **QA** tests, **PO** product owner. Effort is indicative for 2 BE, 2 FE, 1 shared OPS/QA; streams overlap.

---

## Milestones

| Milestone | Phases | Outcome | Indicative |
|---|---|---|---|
| M0 Foundations | 0 | Design approved; monorepo, service host, shared packages, gateway, compose, CI, dev modes; empty stack healthy locally | Weeks 1–3 |
| M1 Platform core | 1 | identity + tenant services live, JWT auth, isolation tests, event bus end to end, walking skeleton on the VPS | Weeks 4–8 |
| M2 Data services + admin | 2–4, 6 | catalog, pricing, inventory (+purchasing), customer services and admin UI | Weeks 8–16 |
| M3 POS online | 5, 7 | sales + payment services with the online sale saga; complete sale end to end from the POS | Weeks 14–20 |
| M4 Offline + printing | 8–9 | sync service, POS replica, offline sale → sync; print service + .NET agent | Weeks 19–26 |
| M5 Restaurant | 10 | tables, orders, KOT/BOT, split/merge | Weeks 25–29 |
| M6 Plugin platform | 11–12 | plugin service + runtime, hook gateway, federation, reference plugins | Weeks 27–35 |
| M7 Reports + hardening | 13–14 | reporting and notification services, observability, contract/perf tests, security | Weeks 34–39 |
| M8 GA 1.0 | 15 | VPS production, CI/CD, docs, pilot tenant, release | Weeks 38–42 |

Gate rule (spec §51): the product owner approves Phase 0 deliverables before Phase 1, and reviews each milestone demo before the next begins.

---

## Phase 0 — Approval and foundations (M0)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-001 | Approve architecture v0.3, database v0.3, VPS deployment plan and this backlog | PO | Written approval or change list in docs/decisions | — | TODO |
| FP-002 | Close open questions (architecture §25) | PO | docs/decisions/0001-launch-scope.md | FP-001 | TODO |
| FP-003 | Monorepo skeleton | OPS | Folders per architecture §5, root README, project CLAUDE.md (commands, stack, conventions), .editorconfig, conventional commits, `git init` | FP-001 | TODO |
| FP-004 | Backend toolchain | BE | Composer workspace, PHP 8.2+ (XAMPP) and 8.3 (Docker) verified, Pint, PHPStan level 6, Deptrac boundary rules (service ↔ packages only), Pest | FP-003 | TODO |
| FP-005 | Service host and service package template | BE | `services/host` Laravel app booting packages from `SERVICES`; `php artisan svc:make <name>` generates a service package (provider, routes prefix, connection, migrations dir, consumers dir, tests) | FP-004 | TODO |
| FP-006 | `packages/kernel` | BE | TenantContext, TenantModel + scope, tenant/branch refs consumers, settings client, correlation ids, audit writer, idempotency middleware | FP-005 | TODO |
| FP-007 | `packages/contracts` | BE | DTO base, client interface conventions, error-code catalogue, event envelope + JSON Schema loader, hook payload contracts, code generation for HTTP routes from interfaces | FP-005 | TODO |
| FP-008 | `packages/eventbus` | BE | `EventBus` interface, Redis Streams adapter (XADD, consumer groups, claim, ack), outbox relay command, inbox dedupe, consumer runner `svc:consume`, retry schedule, DLQ + replay command, lag metrics | FP-007 | TODO |
| FP-009 | `packages/auth` | BE | RS256 JWT verification with JWKS cache, guards for user/pos/terminal/platform/service tokens, `aud` and caller allow-lists for `/internal`, permission resolver with `perm_hash` snapshot | FP-007 | TODO |
| FP-010 | `packages/http-client` | BE | Typed clients from contracts, Local and Http transports, timeouts, idempotent retries, circuit breaker, error mapping, traceparent propagation | FP-007 | TODO |
| FP-011 | `packages/testing` | QA | Two-tenant fixture across schemas, `actingAs*` helpers, isolation matrix generator, contract test base (both transports), saga scenario base | FP-006, FP-010 | TODO |
| FP-012 | Database bootstrap | BE | `platform:init-databases` (13 schemas + users + grants), `svc:migrate`, schema dump/diff, standard tables migration template (outbox, inbox, idempotency, refs, audit) | FP-005 | TODO |
| FP-013 | API foundation | BE | Envelope, exception handler, error codes, OpenAPI per service (Scramble) merged by CI, `X-Request-Id`, `X-Client-Version` handling, health endpoints per booted service | FP-005 | TODO |
| FP-014 | Gateway config | OPS | Traefik 3 static/dynamic config: path routing, rate limits, `/internal` block, access log, health; local TLS off (behind Nginx) | FP-003 | TODO |
| FP-015 | Docker dev environment | OPS | `infra/compose/dev.yml`: gateway, host-all, worker, MySQL 8.4, redis-data, redis-cache, mailpit; `redis-only` and `db-only` profiles for hybrid XAMPP use; `make up/down/migrate/seed` | FP-012, FP-014 | TODO |
| FP-016 | XAMPP dev mode | OPS | `infra/scripts/xampp-dev.ps1`: Apache vhost, 13 MariaDB schemas/users (collation substitution), `.env.xampp`, instructions in README; verified on this machine (PHP 8.2.12, MariaDB 10.4.32, Composer 2.8.4) | FP-012 | TODO |
| FP-017 | Frontend bootstrap | FE | Angular 21 workspace: `admin`, `pos`, `core-sdk`; ESLint/Prettier; Jest; PWA scaffold on `pos`; proxy to gateway | FP-003 | TODO |
| FP-018 | Print Agent bootstrap (.NET) | PA | `print-agent/` solution (Host, Core, Transports, Tray, Tests) targeting net8.0 with the installed SDK 9; analyzers, `dotnet format`, xUnit, single-file publish profile | FP-003 | TODO |
| FP-019 | CI skeleton | OPS | GitHub Actions with path filters: backend (Pint, PHPStan, Deptrac, Pest on MySQL 8.4 + MariaDB job), frontend (lint, Jest), print-agent (`dotnet test`), compose `ci.yml` smoke (`/health/ready`) | FP-015, FP-017, FP-018 | TODO |
| FP-020 | VPS pre-flight and walking skeleton | OPS | Read-only pre-flight recorded; with explicit go-ahead: Docker Engine install, `/opt/flexipos`, `compose/vps.yml` with limits, CloudPanel reverse-proxy site, TLS, `/health/ready` public; resource usage before/after documented | FP-015, FP-014 | TODO |
| FP-021 | Environment config and secrets | OPS | `.env.*.example` per mode (dev, xampp, vps), JWT key generation command, secrets policy, git-secrets hook | FP-012 | TODO |
| FP-022 | Event and contract catalogue v1 | BE | JSON Schemas for the §8.3 events and `/internal` contracts in `packages/contracts`, published docs page, CI check for undeclared versions | FP-007 | TODO |

## Phase 1 — identity, tenant, kernel proof (M1)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-023 | identity service: users, roles, permissions | BE | Tables §4.1, permission catalogue registration, system roles seed, branch-scoped roles, `perm_version` | FP-012 | TODO |
| FP-024 | identity: authentication | BE | Login (email + tenant code/subdomain), Argon2id, lockout, refresh-token rotation, RS256 signing keys + JWKS, login_history, platform guard, impersonation with audit | FP-023, FP-009 | TODO |
| FP-025 | identity: terminal and POS tokens, service tokens | BE | Activation (verifies code via tenant contract), terminal credentials, device binding, revoke + denylist, PIN login → `pos` token, client-credentials service tokens with `aud` | FP-024 | TODO |
| FP-026 | tenant service: tenants, plans, subscriptions, feature flags | BE | Tables §4.2, platform admin API, tenant status events | FP-012 | TODO |
| FP-027 | tenant: branches, terminals, devices | BE | CRUD, activation codes, heartbeat updates, `terminal.*` events | FP-026 | TODO |
| FP-028 | tenant: settings hierarchy and registry | BE | settings + settings_registry, effective-settings endpoint, `setting.changed` events, kernel settings client cache | FP-026 | TODO |
| FP-029 | tenant: custom-field definitions | BE | CRUD + validation package used by owning services, `custom_field.changed` events | FP-028 | TODO |
| FP-030 | Tenant provisioning saga (S4) | BE | `tenant.created` → each booted service seeds defaults and reports; `provisioning_status`; timeout alert; `tenant:provision-status` | FP-026, FP-008 | TODO |
| FP-031 | Refs projections in every service | BE | `tenant_refs`, `branch_refs`, `terminal_refs` consumers in the kernel; rebuild from tenant snapshot endpoint | FP-030 | TODO |
| FP-032 | Audit framework | BE | `audit_logs` per service + `audit.recorded` events; before/after capture helpers | FP-006 | TODO |
| FP-033 | Hook gateway kernel (`packages/plugin-sdk` v1) | BE | `ExtensionGateway::run`, extension point registry (modes, overridable, failure policy, timeout), `hook_registry` projection, circuit breaker, no-op when no handlers | FP-010 | TODO |
| FP-034 | Redis conventions and rate limits | BE, OPS | Tenant-prefixed cache repository, rate-limit middleware per tenant/user/terminal, Redis Streams sizing (`MAXLEN`), key inventory doc | FP-006 | TODO |
| FP-035 | Isolation and contract suites v1 | QA | Route matrix for identity + tenant, consumer isolation, cache prefix test, contract tests for identity/tenant contracts on both transports | FP-011, FP-025, FP-027 | TODO |
| FP-036 | M1 demo and VPS walking skeleton with identity + tenant | OPS, PO | Login on the VPS pilot URL, create tenant/branch/terminal, provisioning green; resource report | FP-020, FP-035 | TODO |

## Phase 2 — catalog and pricing services (M2)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-037 | catalog: categories, brands, units | BE | CRUD, hierarchy, conversions, events | FP-031 | TODO |
| FP-038 | catalog: products, variants, barcodes, branch availability, images | BE | Tables §4.3, FULLTEXT search, object storage for images, `product.*` hooks, `catalog.product.upserted/deleted` with full POS projection | FP-037, FP-033 | TODO |
| FP-039 | catalog: modifiers | BE | Modifier groups and links | FP-038 | TODO |
| FP-040 | catalog: projections and validation | BE | `tax_group_refs`, `kitchen_station_refs`, custom-field validation for products | FP-038 | TODO |
| FP-041 | pricing: taxes and tax groups + calculation engine (PHP) | BE | Inclusive/exclusive, compound; fixture tests shared with the TypeScript engine | FP-031 | TODO |
| FP-042 | pricing: price lists, assignments, discounts | BE | Tables §4.4, resolution order, `discount.beforeApply/afterApply` hooks, `pricing.*` events | FP-041 | TODO |
| FP-043 | pricing: quote contract | BE | `pricing.quote` internal contract + public endpoint for admin sales; contract tests | FP-042 | TODO |
| FP-044 | Product import/export | BE | CSV import queued with validation report; export | FP-038 | TODO |

## Phase 3 — inventory service with purchasing (M2)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-045 | inventory: product refs projection | BE | `product_refs` from catalog events, rebuild from snapshot | FP-038 | TODO |
| FP-046 | inventory: stock levels and ledger | BE | Tables §4.5, `InventoryPosting` (row locks, transaction-safe), `stock.before/afterUpdate` hooks, `inventory.stock.updated` | FP-045 | TODO |
| FP-047 | **inventory: reserve-and-decrement contract** | BE | `reserveAndDecrement(sale_id, lines)` atomic, idempotent by reference; `release(sale_id)`; 409 with insufficient lines; contract tests both transports; load test on row locks | FP-046 | TODO |
| FP-048 | inventory: offline sale consumption and conflicts | BE | Consume `sale.completed{offline}`, `sale.refunded`, `sale.voided`; `stock_conflicts`; `stock.conflict_detected` | FP-046 | TODO |
| FP-049 | inventory: opening stock, adjustments, transfers, reorder alerts | BE | Adjustments, transfers, reorder levels, `stock.low` scheduled check | FP-046 | TODO |
| FP-050 | inventory: purchasing (suppliers, PO, GRN, returns, supplier payments) | BE | Lifecycle, numbering, posting to ledger, events | FP-046 | TODO |

## Phase 4 — customer service (M2)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-051 | customer: customers and groups | BE | Tables §4.8, duplicate detection, merge, `customer.*` hooks and events | FP-031 | TODO |
| FP-052 | customer: credit ledger and credit-check contract | BE | Ledger from `sale.completed/refunded` (idempotent), limits, `creditCheck` contract, settlement via payment events | FP-051 | TODO |
| FP-053 | customer: core loyalty | BE | Earn/redeem from events, `loyalty.calculateEarn` hook, balances into replica | FP-051, FP-033 | TODO |
| FP-054 | customer: offline upsert contract | BE | Field-level merge rules, `possible_duplicate_of`, audit of discarded values | FP-051 | TODO |

## Phase 5 — sales and payment services (M3)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-055 | payment: methods and payment recording | BE | Tables §4.7, `payment_method.changed`, consume `sale.completed` to record tenders (idempotent by tender ULID), `payment.recorded` | FP-031 | TODO |
| FP-056 | payment: gateway abstraction | BE | authorize/capture/void/refund contracts, `gateway_transactions`, encrypted configs, sandbox driver | FP-055 | TODO |
| FP-057 | sales: domain model and calculation parity | BE, FE | Tables §4.6, `SalesService` DTOs shared by online and offline paths, PHP/TS engine parity fixtures | FP-041 | TODO |
| FP-058 | **sales: online sale saga S1** | BE | Idempotency, recompute totals, credit check, reserve-and-decrement, gateway authorize, atomic write + outbox, compensation via `saga_steps`, error codes (`SALES_STOCK_INSUFFICIENT`, `INVENTORY_UNAVAILABLE`), degraded-mode setting | FP-057, FP-047, FP-052, FP-056 | TODO |
| FP-059 | sales: hold/recall, void, refund saga S2 | BE | Refund validation, gateway refund first, reversal events | FP-058 | TODO |
| FP-060 | sales: numbering | BE | Terminal-prefixed numbers mirror + reuse rejection, `number_sequences`, `numbering.generate` overridable | FP-057 | TODO |
| FP-061 | sales: cash shifts and X/Z data | BE | Shifts, movements, expected vs counted, tender totals from `sale_tenders` | FP-057 | TODO |
| FP-062 | sales: approvals and `sale.beforeComplete` gate | BE | `approval_requests`, PIN/remote decision, hook gateway wiring | FP-057, FP-033 | TODO |
| FP-063 | sales: API, OpenAPI, isolation and saga scenario tests | BE, QA | `/pos/sales` endpoints; scenarios: stock insufficient, inventory down (degrade), gateway failure (compensation), duplicate idempotency key | FP-058, FP-059 | TODO |

## Phase 6 — Angular foundation and admin app (M2/M3)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-064 | core-sdk foundation | FE | API client (envelope, codes, retry), JWT auth store + refresh, tenant/branch context, interceptors, guards, i18n, theming | FP-017, FP-013 | TODO |
| FP-065 | Admin shell | FE | Layout, navigation registry (`admin.menu`), dashboard widget slot, permission-aware menus | FP-064 | TODO |
| FP-066 | Auth screens | FE | Login (tenant code/subdomain), reset, profile | FP-064, FP-024 | TODO |
| FP-067 | Tenant administration UI | FE | Branches, users, roles + permission matrix, terminals/devices, settings editor, custom-field designer | FP-065, FP-029 | TODO |
| FP-068 | Catalog and pricing UI | FE | Categories, brands, units, products (variants, barcodes, images), modifiers, taxes, price lists, assignments, discounts | FP-065, FP-042 | TODO |
| FP-069 | Inventory and purchasing UI | FE | Stock, movements, adjustments, transfers, conflicts, low stock, suppliers, POs, GRNs, returns, supplier payments | FP-065, FP-050 | TODO |
| FP-070 | Customers UI | FE | List, profile (history, ledger, loyalty), groups, duplicate merge | FP-065, FP-053 | TODO |
| FP-071 | Platform admin UI | FE | Tenants, plans, subscriptions, feature flags, provisioning status, impersonation | FP-065, FP-026 | TODO |

## Phase 7 — POS app, online (M3)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-072 | POS shell and terminal flow | FE | Activation, PIN login, shift open/close, context | FP-064, FP-025, FP-061 | TODO |
| FP-073 | Product grid, search, barcode | FE | Tiles, search, keyboard-wedge scanning, variants, modifiers | FP-072 | TODO |
| FP-074 | Cart and calculation engine (TS) | FE | Signals store, quantities, price override, discounts, taxes via shared engine, notes, customer attach | FP-073, FP-057 | TODO |
| FP-075 | Hold and recall | FE | Local held bills; optional cross-terminal recall online | FP-074 | TODO |
| FP-076 | Payment screen and online completion | FE | Multi-tender, change, credit; handles `SALES_STOCK_INSUFFICIENT` and degraded mode UX | FP-074, FP-058 | TODO |
| FP-077 | Refund and void with approvals | FE | Flows + approval dialog | FP-076, FP-062 | TODO |
| FP-078 | POS extension points and PluginApi v1 | FE | `flexExtensionPoint`, PluginRegistry, HookBus, `pos.*`/`checkout.*` points, error boundary | FP-074 | TODO |
| FP-079 | Receipt preview and browser print fallback | FE | Handlebars → HTML receipt (80/58 mm) | FP-076 | TODO |
| FP-080 | Terminal reports (X/Z) | FE | Shift summary | FP-076 | TODO |
| FP-081 | POS e2e tests (online) | QA | Playwright: login → sale → payment → refund; stock-insufficient path | FP-077 | TODO |

## Phase 8 — sync service and offline (M4)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-082 | sync: POS replica builder | BE | Consume catalog/pricing/customer/tenant/identity/print/plugin events into `pos_replica`, per-tenant versions, compaction, rebuild from snapshots | FP-038, FP-042, FP-051 | TODO |
| FP-083 | sync: pull and snapshot API | BE | Delta by version, branch/terminal scoping, snapshot streaming, `terminal_sync_state` | FP-082 | TODO |
| FP-084 | sync: push and inbox | BE | Idempotent inbox, ordering, `waiting_dependency`, dispatch to sales/customer contracts, per-item acks, `sync.*` events | FP-057, FP-054 | TODO |
| FP-085 | Conflict rules | BE | All rules of architecture §12 across sales/inventory/customer; `sync.conflict` FILTER hook | FP-084, FP-048 | TODO |
| FP-086 | IndexedDB layer | FE | Dexie schema and repositories for the replica and transactional stores; versioned migrations | FP-064 | TODO |
| FP-087 | PWA / service worker | FE | App shell + assets + plugin remotes cached; update prompt from `X-Min-Client-Version` | FP-086 | TODO |
| FP-088 | Offline routing and degraded mode | FE | Online detector, repository strategy switch, degrade-to-offline on `INVENTORY_UNAVAILABLE` per setting | FP-086, FP-076 | TODO |
| FP-089 | Sync engine | FE | Outbox, push worker (batches, back-off, acks), pull worker, full resync, corrupted-state recovery, status UI | FP-088, FP-083, FP-084 | TODO |
| FP-090 | Offline cashier login and numbering | FE, BE | PIN hashes + permissions from replica; terminal counters; mirror check | FP-089, FP-060 | TODO |
| FP-091 | Sync monitoring UI | FE | Terminal status, last sync, offline duration, rejected/conflict items with payload | FP-067, FP-084 | TODO |
| FP-092 | Offline and sync tests | QA | Pest scenarios (idempotent, duplicate, out-of-order, dependency wait, stock conflict, price snapshot, customer merge, resume, replica compaction); Playwright offline sale → reconnect → synced | FP-089, FP-085 | TODO |

## Phase 9 — print service and .NET Print Agent (M4)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-093 | print service: printers, routes, kitchen stations, templates | BE | Tables §4.13, events into the replica, admin API | FP-031 | TODO |
| FP-094 | Print Agent service core | PA | .NET 8 Worker Service + Kestrel loopback REST/WS, pairing code, SQLite queue, retry, job log, Windows Service (WiX MSI) + systemd | FP-018 | TODO |
| FP-095 | Printer discovery and registration | PA, BE | Windows spooler RAW, WinUSB/libusb, TCP 9100 + mDNS; register printers; heartbeats to print service | FP-094, FP-093 | TODO |
| FP-096 | Rendering | PA | Handlebars.Net → ESC/POS (ESCPOS_NET) and ZPL; default templates; xUnit golden files | FP-094 | TODO |
| FP-097 | POS ↔ agent integration | FE | Print on complete, reprint, drawer kick, queued-when-down warning, fallback | FP-076, FP-094 | TODO |
| FP-098 | Templates admin and `invoice.render` override | BE, FE | Template CRUD with preview, tenant/branch overrides, plugin-registered templates | FP-093, FP-096 | TODO |
| FP-099 | Cloud print relay (branch print server mode) | BE, PA | `print_jobs` relay from `sale.completed`/`kot.created`, agent polling mode | FP-093, FP-095 | TODO |
| FP-100 | Print failure tests | QA | Printer unavailable → sale saved, job queued, retried; routing tests | FP-097 | TODO |

## Phase 10 — restaurant (in sales) (M5)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-101 | Floor areas and tables | BE, FE | Models, table map editor, status, replica | FP-057, FP-067 | TODO |
| FP-102 | Orders and order items | BE | Append-only stream, courses, seats, waiter, `order.*` hooks and events | FP-057 | TODO |
| FP-103 | KOT/BOT | BE, PA | Ticket generation per station, `kot.created` → routing/relay, reprint, void | FP-102, FP-093 | TODO |
| FP-104 | Billing from orders | BE | Just Bill → sale saga, split bills, merge, move table | FP-102, FP-058 | TODO |
| FP-105 | Restaurant POS UI | FE | Table map, order screen, send to kitchen, bill/split/merge | FP-104, FP-074 | TODO |
| FP-106 | Restaurant offline | FE, BE | Orders/KOTs local-first, union merge on sync, table status reconciliation | FP-105, FP-089 | TODO |

## Phase 11 — plugin service and runtime (M6)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-107 | Manifest and package format | BE | plugin.json schema, validator, zip layout, checksum + signature | FP-033 | TODO |
| FP-108 | Plugin registry and lifecycle | BE | Tables §4.12, install/enable/disable/update/uninstall state machine, migrations in `pos_plugin`, no implicit data deletion, `plugin.*` events (S5) | FP-107 | TODO |
| FP-109 | Plugin Runtime | BE | Loads enabled plugin backends, `runHook` contract (modes, priority, timeouts), settings (encrypted), permissions registration to identity, execution logs, circuit breaker + `plugin.circuit_tripped` | FP-108, FP-025 | TODO |
| FP-110 | Hook gateway wiring in services | BE | sales, customer, catalog, pricing, inventory, sync points call `ExtensionGateway`; `hook_registry` projections; failure policies | FP-109, FP-058 | TODO |
| FP-111 | Workflows and approvals | BE | `workflow_definitions`, runner as hook handler, approval steps through the sales contract | FP-109, FP-062 | TODO |
| FP-112 | Compatibility gate | BE, OPS | Semver checks, `plugins:check-compat --core=` in the release pipeline | FP-108 | TODO |
| FP-113 | Plugin lifecycle and isolation tests | QA | Fixture plugins; lifecycle; permission refusal; override allow-list; circuit breaker; Tenant A plugin cannot read Tenant B | FP-110 | TODO |

## Phase 12 — plugin frontend, admin and reference plugins (M6)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-114 | Native Federation setup | FE | Shell + remote build, `register(api)` contract, PluginApi versioning, remote publishing to storage | FP-078 | TODO |
| FP-115 | Client hook modes and slots | FE | HookBus BEFORE/AFTER/FILTER/OVERRIDE with allow-list, all slots, priorities | FP-114 | TODO |
| FP-116 | Runtime loading per tenant | FE | `enabled_plugin` docs from the replica → load remotes, checksum, offline cache, failure badge | FP-115, FP-108 | TODO |
| FP-117 | Plugin admin UI | FE | Available/installed, lifecycle actions, permissions and dependencies, logs and health, tenant assignment | FP-116, FP-109 | TODO |
| FP-118 | Plugin developer kit | BE, FE | `plugin:make`, template repo, static rules, developer guide with extension registry | FP-116 | TODO |
| FP-119 | discount-approval plugin | BE, FE | `discount.beforeApply` gate → approval → POS dialog; enabled tenants only | FP-118, FP-111 | TODO |
| FP-120 | membership-button plugin | FE | `pos.toolbar` button + panel | FP-118 | TODO |
| FP-121 | checkout-extra-info plugin | FE, BE | `checkout.payment` form → custom fields | FP-118, FP-029 | TODO |
| FP-122 | custom-invoice-template plugin | BE, PA | Registered template + `invoice.render` override | FP-118, FP-098 | TODO |
| FP-123 | sales-commission plugin | BE | `sale.completed` consumer, `plg_commission_*`, report registered via `report.register` | FP-118, FP-126 | TODO |
| FP-124 | advanced-loyalty plugin | BE, FE | `plg_loyalty_*`, secret settings, `loyalty.calculateEarn` override, customer panel slot | FP-118, FP-053 | TODO |

## Phase 13 — reporting and notification services (M7)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-125 | reporting: read models and dimensions | BE | Facts and dims of §4.11 from events, rebuild jobs from owners' snapshots, idempotent by event id | FP-058, FP-046, FP-050 | TODO |
| FP-126 | reporting: report registry and core reports | BE, FE | Daily sales, product sales, profit, inventory, purchases, cashier, branch, tenant, tax; `reports.list` slot; `report.register` for plugins | FP-125 | TODO |
| FP-127 | reporting: daily summaries, exports, scheduled reports | BE | Incremental + nightly rebuild, CSV/XLSX/PDF exports, e-mail schedules | FP-126 | TODO |
| FP-128 | reporting: audit read model | BE, FE | `audit_facts` from `audit.recorded`, admin audit viewer | FP-125, FP-032 | TODO |
| FP-129 | notification service | BE | Channels (mail, SMS provider abstraction, in-app, webhook), templates, deliveries, alert rules (low stock, sync rejected, printer failed, plugin tripped, DLQ growth) | FP-031, FP-008 | TODO |
| FP-130 | Dashboards | FE | Admin widgets (sales today, by branch, low stock, offline terminals, sync health); platform dashboard | FP-126 | TODO |

## Phase 14 — hardening, observability, performance (M7)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-131 | Observability | OPS, BE | JSON logs with trace/tenant fields, OpenTelemetry tracing across gateway/HTTP/events/Local transport, Prometheus metrics (lag, DLQ, outbox age, hook timings), Sentry everywhere, alert rules | FP-008 | TODO |
| FP-132 | Security hardening | BE, FE, OPS | CSP/CORS/headers, JWT key rotation drill, `/internal` exposure test, per-service DB grants test, encryption at rest for secrets, dependency audits, ZAP baseline, ASVS L2 checklist | FP-109 | TODO |
| FP-133 | Isolation and contract suites v2 | QA | All services, consumers, plugin hooks, sync, reporting, print relay; consumer-driven contract fixtures for every `/internal` contract and event version | FP-113, FP-092 | TODO |
| FP-134 | Performance and data volume | BE, OPS | k6 on `ci.yml` and staging: checkout p95 < 300 ms, 5 000-item sync burst, pull delta, consumer lag; index review; partitions; VPS saturation profile | FP-092, FP-126 | TODO |
| FP-135 | Backup, restore, tenant export | OPS | Per-schema dumps + binlog PITR, restore drill, `tenant:export`, VPS backup cron | FP-020 | TODO |

## Phase 15 — production on the VPS, CI/CD, release (M8)

| ID | Task | Stream | Deliverable / acceptance | Depends on | Status |
|---|---|---|---|---|---|
| FP-136 | Production images | OPS | `flexipos/host` (php-fpm + nginx), `pos-web`, worker/scheduler variants, Trivy scan, SBOM, pinned tags | FP-015 | TODO |
| FP-137 | Full CI pipeline | OPS | Lint → static analysis → unit → feature → contract → isolation → plugin → build → compose smoke → e2e nightly; required checks | FP-133 | TODO |
| FP-138 | VPS production deployment | OPS | `compose/vps.yml` final limits, deploy script (pull, migrate, up, health), rollback, monitoring hooks, runbook in deployment-vps.md verified on the box | FP-136, FP-020 | TODO |
| FP-139 | Release process | OPS, BE | Semver for core, services and plugins; changelog; `core_releases`; plugin compat gate; Print Agent MSI publishing | FP-112, FP-138 | TODO |
| FP-140 | Documentation | BE, FE | Admin guide, POS user guide, plugin developer guide, API reference, service catalogue (contracts and events), VPS runbook | FP-118 | TODO |
| FP-141 | Pilot tenant and UAT on the VPS | PO, QA | Create tenant → configure → plugins → branches/users/terminals → two weeks of live sales; defects triaged; resource report | FP-138, FP-124 | TODO |
| FP-142 | GA release 1.0 | OPS, PO | Tagged release, release notes in docs/releases, dashboards live, support runbook, stage-1 move-out criteria agreed | FP-141 | TODO |

---

## Post-1.0 candidates (not scheduled)

Extraction of purchasing and restaurant into their own services, per-service instances and sharding, RabbitMQ/Kafka if stream lag persists, marketplace UI and billing, third-party plugin review workflow, kitchen display system, delivery and e-commerce integrations, multi-currency sales, accounting export plugin, TOTP 2FA and SSO, custom domains per tenant, data warehouse feed, move to Azure/AWS (stage 2).

---

## Dependency overview

```text
Phase 0 (host, packages, gateway, compose, CI) ─► Phase 1 identity + tenant + kernel proof (+ VPS walking skeleton)
      ├─► Phase 2 catalog + pricing ─► Phase 3 inventory (+purchasing)
      ├─► Phase 4 customer
      ├─► Phase 6 Angular foundation + admin (parallel with 2–4)
      └─► Phase 5 sales + payment (needs 2, 3, 4) ─► Phase 7 POS online
                                                       ├─► Phase 8 sync + offline
                                                       ├─► Phase 9 print service + .NET agent (agent core can start after Phase 0)
                                                       └─► Phase 10 restaurant
Phase 1 hook kernel ─► Phase 11 plugin service + runtime ─► Phase 12 plugin frontend + reference plugins
Phases 5–12 ─► Phase 13 reporting + notification ─► Phase 14 hardening ─► Phase 15 VPS production + release
```
