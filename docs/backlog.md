# FlexiPOS — Backlog

| | |
|---|---|
| Status | **v0.4** — restructured for master plus tenant databases and the modular monolith |
| Date | 2026-09-29 |
| Design inputs | [architecture.md](architecture.md) v0.5 · [database.md](database.md) v0.5 · `Refference/ARCH.md` · `Refference/ERP.md` · [deployment-vps.md](deployment-vps.md) |
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
| T-004 | Gate speed and repository hygiene | FP-155, FP-156, FP-157, FP-164 | `ai/T-004-gate-speed-and-hygiene` | **DONE** — QA 20/20, review APPROVE, commits `643e220` and `bcd7595` |
| T-005 | Rename services to modules | FP-202, FP-169 | `ai/T-005-rename-modules` (from main) | **DONE** — QA PASS 26/26 (run 3), review **APPROVE** (round 2; round 1 was CHANGES REQUESTED on 3 Criticals). Gate exit 0, 314 passed / 1435 assertions. **Not yet merged**: the step-8 `CLAUDE.md` correction is still unacknowledged by the human, and per reviewer S-1 the merge and that edit belong in the same step |

| T-006 | The unit model: GRN unit is not the selling unit | FP-243, and amendments to FP-230/231/233/234/236/237/250/253/254/255 | `ai/T-006-unit-of-measure` (from the T-005 tip) | IN_DEV — documents first, no migration cost |

**Why FP-201 and FP-202 are split.** The rename is mechanical but touches every file, and burying a semantic change inside that much churn makes the diff unreviewable. FP-201 was what Phase 0 was blocked on; the rename blocks nothing, so it goes second and alone.

**T-005 scope.** The code and the forward-looking documents change. **The completed-task records do not** — they describe what was built and verified at the time, and rewriting them would falsify the record QA and the reviewer relied on. `Refference/` is the owner's source material and is never touched. FP-169 rides along because bringing the host's test directory into static analysis is what will catch a broken reference during a rename of this size.

The rule is stated **by folder, not by counting files**, so a record added later is covered without anyone updating a number: `docs/specs`, `docs/plans`, `docs/qa`, `docs/reviews`, `docs/decisions` and `docs/releases` are exempt, as is `CLAUDE.md`. The live instrument is `QaVocabularyRenameSweepTest`, which asserts the exemptions and allowances rather than restating them here; read its `qaVocabExemptPaths()` for the current list.

**Three counts in earlier versions of this paragraph were wrong, and are recorded rather than quietly replaced** (FP-171, FP-176). The original claimed "57 tracked files carry the old vocabulary"; the architect measured 78 while planning. It claimed "eighteen completed-task records", which was wrong in both directions.

**The correction was then wrong too, and it is the more instructive failure.** It gave a record count measured over `docs/specs`, `plans`, `qa` and `reviews` — four of the **six** folders the rule two paragraphs above had just named, omitting `docs/decisions` and `docs/releases`. That is FP-176's defect, a sentence whose scope is narrower than the rule it illustrates, committed inside the very paragraph correcting a measurement error. It was also stale within the hour, because committing T-005's own records changed it.

So the count is gone rather than refreshed: any absolute figure here is wrong again at T-006. The by-folder rule above and `qaVocabExemptPaths()` are the re-measurable form, which was FP-171's remedy all along. If a number is ever genuinely wanted, `git ls-files` over the six folders is one command and dates itself.

**This table was itself stale** until 2026-10-02: it still described T-004 as the rename after T-004 had shipped as the gate work. Recorded rather than quietly corrected, because it is the same failure the T-004 review named as this project's recurring one.

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
| FP-158 | Correct two defects in `docs/database.md` §4.4, found by the architect while planning T-003 | **DONE (T-006)** — folded into FP-243, same file and same owner |
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
| FP-169 | Bring `modules/host/tests` into static analysis and the boundary check | **DONE (T-005)** |
| FP-170 | Give the fast loop a time dimension | TODO — after FP-165 |
| FP-171 | A number written into prose carries its date, or becomes a ratio, or becomes an assertion | TODO — convention |
| FP-172 | Give the test command headroom against the process timeout | TODO — before CI |
| FP-173 | A teardown may delete a planted file, never its directory | TODO — reviewer W-3 (T-005) |
| FP-174 | Give `CLAUDE.md` a standing accuracy assertion | TODO — with the task that next edits it |
| FP-175 | Widen the probe-planter walk beyond `*Test.php` | TODO — when a planter first needs to live elsewhere |
| FP-176 | A scope word in a comment beside a mechanism is an assertion, or it is narrowed | TODO — convention, sibling to FP-171 |
| FP-177 | Audit the per-domain table counts in `docs/database.md` §6 | TODO — FP-171 family, it is the denominator of every capacity argument |
| FP-178 | Escape the remaining vertical bar inside a markdown cell, `docs/database.md` §8 step 12 | TODO — trivial, renders a three-column row as four |

**FP-172 detail.** The package manager kills a script at 300 seconds by default. The gate runs 79-137 seconds warm on the development machine (measured 2026-10-02, five runs across the lead, `qa` and the reviewer, warm analysis cache), so it looks safe, but a cold analysis cache alone pushed a run past 300 during T-005. The kill is the dangerous part rather than the delay: a killed process runs no cleanup, so a deliberately broken test probe survived and turned the gate red afterwards with a failure that named a file looking like real code, and leftover generated packages produced seven further unrelated failures. Raise the timeout, or make the gate resilient to being killed, before continuous integration meets a cold machine.

**FP-168 detail.** Three readers have each found one instance of the same family: a teardown where something that can throw runs before something that restores, sometimes split across two functions so no proximity search can see it. QA closed the family with a one-off sweep by property rather than mechanism, scanning 74 teardown blocks with a negative control, and found exactly the one the reviewer predicted. A standing guard needs one level of call-graph reasoning plus a named exemption list for fixture builders, which is why it is a row rather than something added mid-commit.

**FP-171 detail.** The reviewer's observation after four tasks: the recurring defect in this project is no longer false reasoning, it is absolute measurements written into prose with no date and no mechanism to re-measure them. Three of the four stale statements it found in T-004 were introduced by T-004 itself.

**FP-173 detail.** `HostTestSuiteCollectionTest.php:38` runs `File::deleteDirectory(dirname(hostSuiteProbePath()))` in an `afterEach`, which removes `modules/host/tests/Integration` entirely after every case in the file rather than removing the one planted probe. Harmless while that directory holds nothing else, but FP-169 has just brought it into static analysis and the boundary check, so it now has more reason to hold real files — and the day a committed file lands there, a test run deletes it. The fix is `File::delete()` on the probe path plus a guarded `rmdir`. Left out of T-005 deliberately: it is pre-existing and the reviewer asked for a row rather than another change inside a rename.

**FP-177 detail.** The §6 domain index does not agree with the tables §6 defines: the index sums to **192** after T-006 while a literal count of the rows gives **196**. The architect measured the gap while doing FP-243 and reported it rather than silently re-denominating, which was right. Nothing downstream was adjusted, also deliberately: §10's capacity arithmetic models a rounded 200 tables per tenant and its 20,000 / 40,000 / 60,000 figures would all shift if the denominator moved, so this is an audit done once rather than a number nudged in passing.

**The first explanation of the gap was wrong, and the correction is the useful part.** It was recorded here as "four index rows are each one low because a row naming two tables together is counted once". `ba` then checked it against Catalog and found that explanation does not fit: **no Catalog row names two tables.** The real cause there is simpler and closer to home — T-006 added `barcode_rules` to §6.3 and raised the Inventory index row for its two new tables, but never raised Catalog for its one. **So T-006 itself opened part of the gap it reported.** Whatever else the audit finds, it starts with a Catalog row that is one low because of this task. Same family as `architecture.md`'s "roughly 150 tables per tenant", which T-006 corrected: a measurement in prose with no mechanism to re-measure it — and now also an *explanation* in prose that nobody checked against the thing it explained, which is FP-176's defect one level up.

**The CQRS direction is not recorded anywhere in this repository.** The owner set it on 2026-10-06 and it was planned, but the plan lives outside the repository, so an agent working inside the repository cannot see it. The architect hit exactly this during FP-243: asked to cite the dependency, it searched, found nothing, and correctly refused to cite a plan it could not source — writing the dependency as a property of any read model instead, attributed to the owner and dated. That is the right behaviour and it is also the signal: **a direction that exists only in a plan file is not a decision the team can act on.** Recording it is owed, and it belongs in the architecture document rather than here.

**T-006 / FP-243 detail — why a rename task is followed by a unit task.** The product owner asked on 2026-10-06 whether the design had considered that the receiving unit and the selling unit differ, and that this varies by industry. It had, partly, and that is the problem: the catalog carries a per-product conversion table whose own note describes buying in cases and selling in units, a product has a purchase unit distinct from its other unit, and barcodes carry a pack quantity. **But the unit stops at the document edge.** Only four line tables carry a unit at all; the ledger, levels, batches, reservations, transfers, adjustments, counts, returns, supplier invoices, price history, quotations, sales orders and restaurant order items all hold a bare quantity, and **no sentence anywhere in `docs/database.md` states what unit any of them is in.**

The fix is to do for quantity exactly what the design already does for money: declare one base, snapshot the rate on the document line, and store the converted value beside the original. `journal_lines` already carries a currency, an exchange rate and a base amount; quantities had a catalog table and nothing else. That asymmetry is the whole of FP-243.

**It also closes a latent defect on the owner's own example.** The stock ledger's replay key is scoped to the document, so a receipt of two cases **plus** three loose singles of one product — two lines — produces two movements with an identical key and violates the unique index. Separately, a nullable column in that key means the database treats the rows as distinct, so replay protection did not work at all for products tracked by quantity only, which is most of them. Both are fixed by keying the **line** instead of the document, with generated non-null columns for the batch and serial parts.

**Why now rather than later:** nothing is implemented. FP-230 and FP-250 to FP-256 are TODO and the only migrations in the repository are the framework's own. The same change after launch is a column sweep across sixteen tables, a unique-index rebuild on a live append-only ledger holding roughly 1.5 million rows per tenant per year, and a full resync of every terminal in the field because the price table is replicated. Three new tables, 190 to 193 per tenant, which moves the server ceiling by about four tenants.

**FP-176 detail.** FP-171's sibling on a different axis, and the reviewer argued for two rows rather than one because the trigger and the check differ. **FP-171 is the time axis:** the sentence was true when written and decayed; the trigger is a number; the check is "re-measure it"; the remedy is a date, a ratio or an assertion. **FP-176 is the extent axis:** the sentence was false the moment it was written, because the mechanism never did what it said; the trigger is a **scope word** — *every, any, all, each*, a glob, a directory, an ordinal; the check is "which line of code makes this true, and what input makes it false" — then run that input; the remedy is to narrow the sentence or execute the claim. Collapsed into one row it becomes "prose can be wrong", which is unactionable.

**Why it is a project convention and not one role's discipline.** T-005 produced **seven** instances across **four** roles, and **not one was found by its author**: `dev`'s `beforeEach` said to close the gate hole (BUG-1), its registry said complete (R-1/R-2), its walk said wider than it reads (BUG-4) and its gate-step ordinal off by one (round-2 W-3); the lead's record count taken over four of the six folders it had just named (round-2 W-1); `qa`'s "a seventh folder cannot be forgotten the same way", which the pin cannot see (round-2 W-2); and the reviewer's own "four renamed test class names" when there are five (W-1, found by `qa`). The defect is invisible to its author by construction, so it is a **cross-reading obligation**, not something a single role's memory note can fix. Cheapest standing check named so far: in review, grep the diff's added comment lines for `every|any|all|each|only` and resolve each against a line of code — that found three of the seven in about ten minutes.

The shared remedy sentence for both rows: *in the same commit as a mechanism, every claim about that mechanism is either executed or narrowed.*

**FP-175 detail.** The probe-planter discovery that closed reviewer R-2 walks only files named `*Test.php`, so a planter declared in a `Helpers.php`, `Pest.php` or fixtures class is not discovered and could leave a probe unregistered (QA BUG-4). Both QA and `dev` reproduced the hole with a scratch planter in a non-`*Test.php` file and the guard stayed green. Deliberately **not** fixed in T-005: there is no planter outside a `*Test.php` file anywhere today, so a widened walk's first run would be green — and the rule this project adopted in the same round is that a guard whose first run is red is worth adding now, while one whose first run is green is the thing you defer. Pick this up when a planter first needs to live outside a test file; until then the residual is written into the comment beside the mechanism rather than left to be rediscovered.

**FP-174 detail.** Both READMEs have a standing accuracy assertion; `CLAUDE.md`, the most-read forward-looking document in the repository and the first thing every agent is told to read, has none. That asymmetry is what let the guide keep nine command rows pointing into the former top-level directory, and a "Vocabulary note" paragraph asserting the rename had not happened, while the tree said otherwise. (Those rows are described rather than quoted here on purpose: this file is deliberately not exempt from the vocabulary sweep, so quoting the old name turns the gate red — which is exactly what the first draft of this paragraph did.) The assertion cannot be added by a task that must leave the file byte-identical — T-005's AC-25 and an accuracy assertion are contradictory by construction — so it belongs to whichever task next edits the guide, which is also when the pending step-8 correction lands. Until then the guide's command rows are known-wrong and the hazard is that an agent reads them and re-derives the old names.
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
| FP-202 | Rename services to modules | BE | the top-level directory becomes `modules/`, the generator becomes `module:make`, boundary layers and documentation follow; vocabulary matches the architecture | FP-201 | **DONE (T-005)** |
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
| FP-230 | Catalog: products, variants, barcodes, categories, brands, units, modifiers | BE | TODO — **widened by T-006**: unit dimension, the resolver and its snapshot contract, the stock/purchase/sales unit split, weighable flags and PLU, and `barcode_rules` with a parser in both engines. Depends FP-243 |
| FP-231 | Taxes, price lists and discounts, with the shared calculation engine in PHP and TypeScript | BE, FE | TODO — **widened by T-006**: prices carry a unit, so a case can price at other than a clean multiple of the single. Unit resolution joins the shared engine under the same fixtures |
| FP-232 | Customers, groups, credit ledger and loyalty | BE | TODO |
| FP-233 | Sales: cart, tenders, hold and recall, refunds, voids, cash shifts | BE | TODO — **widened by T-006**: sale lines carry the unit quad |
| FP-234 | Stock validated and decremented inside the sale transaction, rejecting insufficient stock | BE | TODO — **widened by T-006**: conversion resolves *before* the row lock, and the ledger is written in base units |
| FP-235 | Terminal-prefixed invoice numbering, final at print time | BE | TODO |
| FP-236 | Till application: product grid, scanning, cart, payment, refund | FE | TODO — **widened by T-006**: scan resolves through `barcode_rules`, and weighable lines are entered by weight |
| FP-237 | Offline: local database, replica, sync push and pull, conflict rules | BE, FE | TODO — **widened by T-006**: the replica must carry unit conversions and barcode rules, or an offline till computes a different quantity than the server |
| FP-238 | Offline cashier sign-in with cached PIN and permissions | BE, FE | TODO |
| FP-239 | Printing: printers, routes, templates, and the agent integration | BE, PA, FE | TODO |
| FP-240 | Admin application shell, navigation and the catalog and customer screens | FE | TODO |
| FP-241 | Suspension must reach an offline till (terminal token and replica expiry) | BE, FE | TODO — **before the first paying customer** |
| FP-243 | The unit model: base unit, the conversion boundary, the valuation formula | BE (architect, documents only) | IN_DEV (T-006) — **gates FP-230** |

## Phase 2 — Inventory and purchasing

| ID | Task | Stream | Status |
|---|---|---|---|
| FP-250 | Stock levels and the append-only stock ledger | BE | TODO — **widened by T-006**: the new ledger idempotency key, the base-only boundary, the weighted-average formula, and three nightly quantity invariants |
| FP-251 | Warehouses, and stock held per location rather than per branch | BE | TODO |
| FP-252 | Batches, serial numbers and expiry tracking | BE | TODO |
| FP-253 | Adjustments, transfers, opening stock, reorder alerts, conflicts | BE | TODO — **widened by T-006**: item lines carry the unit quad; a stock count is entered in the unit the human counted |
| FP-254 | Suppliers, purchase requests and purchase orders | BE | TODO — **widened by T-006**: line unit quads, and the received rollup compares base to base |
| FP-255 | Goods receipts, supplier invoices, returns and supplier payments | BE | TODO — **widened by T-006**: receive in a different unit from the order, and price variance normalised per base unit |
| FP-256 | Inventory and purchasing screens | FE | TODO |
| FP-257 | Repack and break-bulk: the production document, apportionment and labels | BE | TODO (T-006) — **last in Phase 2**, it is the only row that reads a maintained average cost |

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
| FP-258 | Live-weight scale through the print agent | PA, BE | TODO (T-006, deferred here deliberately) |

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
