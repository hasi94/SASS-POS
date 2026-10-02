# FlexiPOS — Backlog

| | |
|---|---|
| Status | **v0.4** — restructured for master plus tenant databases and the modular monolith |
| Date | 2026-09-29 |
| Design inputs | [architecture.md](architecture.md) v0.4 · [database.md](database.md) v0.4 · `Refference/ARCH.md` · `Refference/ERP.md` · [deployment-vps.md](deployment-vps.md) |
| Status flow | `TODO → SPEC → PLANNED → IN_DEV → IN_QA → IN_REVIEW → DONE` (or `BLOCKED`) |
| Branches | `ai/<ID>-<slug>` |
| Change log | v0.4: phases follow `Refference/ARCH.md` section 21; ERP is in scope; v0.3's service-per-schema rows are retired. v0.3: 16 phases for fourteen services |

---

## Owner decisions, 2026-10-02

| Decision | Effect |
|---|---|
| **Server work approved** | FP-221 unblocked. Pre-flight is read-only first; nothing that disturbs existing applications on that box. |
| **Continue in backlog order** | Phase 0 completes before anything is visible. The thin vertical slice was offered and declined. |
| **Merge each task when committed** | `main` fast-forwarded to `bcd7595`, bringing all four tasks. Future tasks merge on commit rather than stacking. |

---

## Active build

| ID | Title | Covers | Branch | Status |
|---|---|---|---|---|
| T-001 | Monorepo, backend toolchain and module host skeleton | old FP-003 to FP-005 | `ai/T-001-monorepo-service-host` | **DONE** — QA 32/32, review APPROVE, commit `741307c` |
| T-002 | Host hardening from the T-001 review | old FP-146 to FP-153 | `ai/T-002-service-host-hardening` | **DONE** — QA 19/19, review APPROVE, commit `ad7fb27` |
| T-003 | Convert the skeleton to master plus tenant connections | FP-201 | `ai/T-003-tenant-connections` (from T-002) | **DONE** — QA PASS 25/25, review APPROVE |
| T-004 | Rename services to modules | FP-202 | not started | TODO — after T-003 |

**Why FP-201 and FP-202 are split.** The rename is mechanical but touches every file, and burying a semantic change inside that much churn makes the diff unreviewable. FP-201 is what Phase 0 is blocked on; the rename blocks nothing, so it goes second and alone.

### What survives the v0.4 change

The host that boots only the modules named in configuration, the generator, the boundary check that stops one module reaching into another, and the whole quality gate all apply unchanged to a modular monolith. Two things do not: every generated module currently declares **its own database connection**, which is the model being replaced, and the vocabulary says "service" where the architecture now says "module".

---

## Carried-over technical debt

| ID | Title | Status |
|---|---|---|
| FP-143 | Migrate to the maintained boundary-check package | TODO |
| FP-154 | Validate a module's namespace target resolves inside its own directory | TODO — before the first non-first-party package loader |
| FP-155 | Keep the quality gate fast | **DONE (T-004)** — gate split into a fast inner loop and the full gate; see FP-165 for the cold measurement |
| FP-156 | Root line-ending rule, `* text=auto eol=lf` | **DONE (T-004)** — committed alone as `643e220` |
| FP-157 | Retire the remaining stock framework scaffolding | **DONE (T-004)**. The original wording claimed a closure route blocked route caching. That was measured false: route caching already succeeded, because the framework serialises closures, and there were four such routes rather than one. The scaffolding was removed as hygiene and the false claim deleted from the README and the host configuration. |
| FP-158 | Correct two defects in `docs/database.md` §4.4, found by the architect while planning T-003 | TODO — small, architect-owned |
| FP-159 | Declare the framework dependency in the shared package manifests | TODO — own commit |
| FP-160 | One construction choke point for the tenant connection | TODO — before FP-203 |
| FP-161 | Make the tenant marker unforgeable | TODO — before FP-203 |
| FP-162 | Purge every read and write variant on teardown | **DONE (T-003)** — reviewer R-2 |
| FP-163 | Document the two escape hatches the guard cannot cover | TODO — with FP-203 |
| FP-164 | Move shared test fixture helpers out of a test file | **DONE (T-004)** |
| FP-165 | Measure the gate cold once, and explain the unaccounted speed-up | TODO — before T-005 |
| FP-166 | Guard the shared tenancy fixture include, as the group registry is guarded | TODO — cheap |
| FP-167 | Give each generated fixture package a unique directory name | TODO — precondition for parallelism |
| FP-168 | Standing guard: no teardown may throw before it restores | TODO — reviewer R-1 family |
| FP-169 | Bring `modules/host/tests` into static analysis and the boundary check | TODO — before T-005 |
| FP-170 | Give the fast loop a time dimension | TODO — after FP-165 |
| FP-171 | A number written into prose carries its date, or becomes a ratio, or becomes an assertion | TODO — convention |

**FP-168 detail.** Three readers have each found one instance of the same family: a teardown where something that can throw runs before something that restores, sometimes split across two functions so no proximity search can see it. QA closed the family with a one-off sweep by property rather than mechanism, scanning 74 teardown blocks with a negative control, and found exactly the one the reviewer predicted. A standing guard needs one level of call-graph reasoning plus a named exemption list for fixture builders, which is why it is a row rather than something added mid-commit.

**FP-171 detail.** The reviewer's observation after four tasks: the recurring defect in this project is no longer false reasoning, it is absolute measurements written into prose with no date and no mechanism to re-measure them. Three of the four stale statements it found in T-004 were introduced by T-004 itself.
| ~~FP-144~~ | ~~Decide ERP scope questions~~ | **RESOLVED** by `Refference/ARCH.md`: database per tenant, ERP in scope, modular monolith |
| ~~FP-145~~ | ~~Reserve ERP extension points~~ | **VOID** — the ERP domains are now designed directly rather than reserved for later |

**FP-158 detail.** The proposed cache key includes the credential version, which nothing knows until after the mapping row has been read, so it cannot serve as a lookup key; the version belongs in the value. And one bullet says the secret is cached for the process lifetime while a bullet two lines later describes in-place credential rotation, which that caching would break. Both defects sit in the same six-line block, so this is one small row rather than two, and it is a document edit owned by the architect rather than a code task.

**FP-159 detail.** The shared packages use the framework without declaring it. Deliberately skipped during T-003 because adding it rewrites the lock file, which has no business happening inside a task about connection plumbing.

**FP-160 detail.** The guard currently attaches from two different hooks with a weak map to prevent double-attachment, and which hook catches which route is dictated by framework internals. Both construction routes ultimately pass through the connection factory binding, so decorating that binding would guard every route with no event dependency, no weak map, and no hole when a test fakes the event dispatcher. Reviewer's assessment: coherent design, mildly accreted attachment, worth consolidating before real modules depend on it.

**FP-161 detail.** All guard levels check a marker that is a public constant, so code inside one tenant's scope could write a stanza carrying the right marker and another tenant's database. Inherent to the approved design and strictly better than the defect it replaced, but closable cheaply: generate a random token per scope entry, hold it on the scoped object, and compare that instead. Forging then needs access to the live scoped object rather than a published constant.

**FP-163 detail.** Two things no connection-level guard can cover: a raw handle obtained from the connection is never re-checked, and a lazily evaluated result opened under one tenant keeps yielding that tenant's rows after a switch, because the per-query check fires once when the statement executes. Neither is a defect; both need writing down where a module author will look.

**Carried into FP-203.** The real mapping reader must keep returning a credential *reference* and never a password, so the boundary where a secret becomes a usable password stays inside one audited place. Also needed there: a caching layer that never caches the resolved secret, and a re-read-and-retry path for a credential rotation that moved the reference.

---

## Phase 0 — Platform foundation

The master database, tenant provisioning and everything that must exist before a single business table is written.

| ID | Task | Stream | Deliverable | Depends | Status |
|---|---|---|---|---|---|
| FP-201 | Two-connection model | BE | `master` and `tenant` connections; `TenantContext`; the resolver that builds the tenant connection from the mapping; strip per-module connection config from the generator and the demo module | T-002 | DONE (T-003) |
| FP-202 | Rename services to modules | BE | the top-level directory becomes `modules/`, the generator becomes `module:make`, boundary layers and documentation follow; vocabulary matches the architecture | FP-201 | TODO |
| FP-203 | Master schema | BE | tenants, users, tenant_users, plans, subscriptions, licences, feature flags, platform settings, platform audit, **tenant_databases** mapping with host, port, name, credential reference, status and schema version | FP-201 | TODO |
| FP-204 | Tenant schema baseline | BE | The tenant migration tree and its runner; settings, number sequences, audit, outbox | FP-203 | TODO |
| FP-205 | **Per-tenant migration runner** | BE | Queued per tenant, resumable, isolates failures to one tenant, records schema version, reports who is behind, refuses to serve a tenant older than the code expects, expand-and-contract enforced | FP-204 | TODO |
| FP-206 | Tenant provisioning | BE | Create database, record mapping, run migrations, seed defaults, activate; one resumable job so a half-provisioned tenant can be completed | FP-205 | TODO |
| FP-207 | Authentication | BE | Login against the master, credential validation, tenant access resolution, tenant switching, token issue and refresh, login history, lockout | FP-203 | TODO |
| FP-208 | Employees, roles and permissions | BE | Tenant-local employees, roles, branch-scoped permissions, cashier PINs, the permission resolver | FP-207 | TODO |
| FP-209 | Branches, warehouses, terminals | BE | Organisation structure, terminal activation and revocation, devices, settings hierarchy | FP-208 | TODO |
| FP-210 | Subscriptions, plans and licensing | BE | Plan limits actually enforced, subscription status gating access, feature flags per tenant | FP-203 | TODO |
| FP-211 | Audit framework | BE | Who changed what, before and after, in the tenant database; platform audit in the master | FP-204 | TODO |
| FP-212 | Event bus and outbox | BE | Redis Streams behind an interface, outbox in the tenant database, relay, consumers, retries, dead letter | FP-204 | TODO |
| FP-213 | Plugin framework | BE | Manifest, registry in the master, licensing and per-tenant enablement, hook gateway with the five modes, runtime, circuit breaker | FP-212 | TODO |
| FP-214 | Workflow engine | BE | Configurable trigger, condition and steps; approval records; drives discounts, expenses, purchase orders, payroll and leave | FP-213 | TODO |
| FP-215 | API foundation | BE | Envelope, error codes, idempotency, rate limits, OpenAPI, health endpoints | FP-201 | TODO |
| FP-216 | Cross-tenant isolation suite | QA | Generated attempts across routes, jobs, cache keys and plugin hooks; must pass to merge | FP-209 | TODO |
| FP-217 | Docker development environment | OPS | Application, MySQL, Redis, mail catcher; XAMPP path documented as the alternative | FP-201 | TODO |
| FP-218 | CI pipeline | OPS | Format, static analysis, boundaries, tests, migration tests on a fresh and an existing database | FP-217 | TODO |
| FP-219 | Frontend bootstrap | FE | Angular workspace: till application, admin application, shared library | — | TODO |
| FP-220 | Print agent bootstrap | PA | .NET solution, loopback service, packaging | — | TODO |
| FP-221 | VPS pre-flight and walking skeleton | OPS | Owner APPROVED 2026-10-02. Read-only pre-flight first, then Docker Engine, /opt/flexipos, compose with limits, CloudPanel reverse-proxy site. Must not disturb existing applications. | FP-217 | TODO |

## Phase 1 — Point of sale

Products, cart, sales, payments, returns, discounts, customers, receipts, offline operation and printing.

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-230 | Catalog: products, variants, barcodes, categories, brands, units, modifiers | BE | TODO |
| FP-231 | Taxes, price lists and discounts, with the shared calculation engine in PHP and TypeScript | BE, FE | TODO |
| FP-232 | Customers, groups, credit ledger and loyalty | BE | TODO |
| FP-233 | Sales: cart, tenders, hold and recall, refunds, voids, cash shifts | BE | TODO |
| FP-234 | Stock validated and decremented inside the sale transaction, rejecting insufficient stock | BE | TODO |
| FP-235 | Terminal-prefixed invoice numbering, final at print time | BE | TODO |
| FP-236 | Till application: product grid, scanning, cart, payment, refund | FE | TODO |
| FP-237 | Offline: local database, replica, sync push and pull, conflict rules | BE, FE | TODO |
| FP-238 | Offline cashier sign-in with cached PIN and permissions | BE, FE | TODO |
| FP-239 | Printing: printers, routes, templates, and the agent integration | BE, PA, FE | TODO |
| FP-240 | Admin application shell, navigation and the catalog and customer screens | FE | TODO |
| FP-241 | Suspension must reach an offline till (terminal token and replica expiry) | BE, FE | TODO — **before the first paying customer** |

## Phase 2 — Inventory and purchasing

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-250 | Stock levels and the append-only stock ledger | BE | TODO |
| FP-251 | Warehouses, and stock held per location rather than per branch | BE | TODO |
| FP-252 | Batches, serial numbers and expiry tracking | BE | TODO |
| FP-253 | Adjustments, transfers, opening stock, reorder alerts, conflicts | BE | TODO |
| FP-254 | Suppliers, purchase requests and purchase orders | BE | TODO |
| FP-255 | Goods receipts, supplier invoices, returns and supplier payments | BE | TODO |
| FP-256 | Inventory and purchasing screens | FE | TODO |

## Phase 3 — Finance

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-260 | Chart of accounts and fiscal periods, with period closing | BE | TODO |
| FP-261 | Journal entries and lines, posted through one service | BE | TODO |
| FP-262 | Posting rules: sales, goods receipts, payments, adjustments | BE | TODO |
| FP-263 | Cash and bank accounts, transfers, petty cash | BE | TODO |
| FP-264 | Accounts receivable: allocation, ageing, statements | BE | TODO |
| FP-265 | Accounts payable: supplier ageing and outstanding invoices | BE | TODO |
| FP-266 | Expenses: categories, attachments, approval, payment, posting | BE | TODO |
| FP-267 | Tax configuration with authorities, codes and effective dates | BE | TODO |
| FP-268 | Profit and loss, balance sheet, trial balance, cash flow, general ledger | BE, FE | TODO |
| FP-269 | Finance screens | FE | TODO |

## Phase 4 — Human resources

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-270 | Employees, departments, designations, documents | BE | TODO |
| FP-271 | Attendance: clock in and out, overtime, breaks, shifts | BE | TODO |
| FP-272 | Leave types, requests, balances and approval | BE | TODO |
| FP-273 | Human resources screens | FE | TODO |

## Phase 5 — Payroll and commission

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-280 | Salary structures, allowances and deductions | BE | TODO |
| FP-281 | Payroll periods with draft, processing, approved and paid, locked after approval | BE | TODO |
| FP-282 | Payslips, and posting payroll to the ledger | BE | TODO |
| FP-283 | Commission rules: percentage, fixed, tiered, by product, category, employee or target | BE | TODO |
| FP-284 | Commission periods, entries, payouts and payroll integration | BE | TODO |
| FP-285 | Payroll and commission screens | FE | TODO |

## Phase 6 — Advanced ERP

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-290 | Bank reconciliation against statements | BE | TODO |
| FP-291 | Budgeting | BE | TODO |
| FP-292 | Asset management | BE | TODO |
| FP-293 | Restaurant: tables, orders, kitchen and bar tickets, split and merge | BE, FE | TODO |
| FP-294 | Reporting and analytics layer, and dashboards | BE, FE | TODO |
| FP-295 | Notification channels and templates | BE | TODO |
| FP-296 | Reference plugins proving the extension points | BE, FE | TODO |

## Phase 7 — Ecosystem

Plugin marketplace, public API with keys and webhooks, mobile applications for employee, manager and owner, e-commerce and marketplace integration, analytics warehouse, third-party integrations.

---

## Milestones

| Milestone | Phases | Outcome |
|---|---|---|
| M0 | 0 | A tenant can be created, provisioned and signed into; migrations run across tenants; the plugin and workflow frameworks exist |
| M1 | 1 | A shop sells, offline and online, and prints |
| M2 | 2 | Stock and purchasing are real |
| M3 | 3 | The books balance: double-entry, profit and loss, balance sheet |
| M4 | 4, 5 | Staff, payroll and commission |
| M5 | 6 | The ERP rounds out and restaurants are supported |
| M6 | 7 | Platform ecosystem |

## Dependency shape

```text
T-003 (two connections, rename)
   └─► Phase 0 foundation ─► Phase 1 POS ─► Phase 2 Inventory ─► Phase 3 Finance
                                  │                                   │
                                  └─► offline, printing               └─► Phase 4 HR ─► Phase 5 Payroll
                                                                                            │
                                                                        Phase 6 Advanced ◄──┘
```

Finance depends on inventory for cost of goods, and payroll depends on human resources for attendance. That ordering is why the phases run in this sequence rather than in parallel.
