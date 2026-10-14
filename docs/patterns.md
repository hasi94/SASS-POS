# FlexiPOS — the patterns this codebase is organised around

**Status:** decided · **Date:** 2026-10-14 · **Supersedes:** the unpinned CQRS directive of 2026-10-06

This document exists because "build it on CQRS" is not yet an architecture. CQRS spans three very
different commitments, and picking the wrong one here is expensive in a way that is hard to undo once
tenants hold data. This pins the level, scopes it, and names what we are deliberately *not* doing.

It is the architecture revision that was owed after the owner's CQRS directive.

---

## 0. What we are actually defending against

The patterns below are chosen against this project's real risks, not against a textbook. Measured
2026-10-14:

| Risk | Size |
|---|---|
| Features still to build | **94 of 106 backlog rows** |
| People building them | one owner, plus agents |
| Tables per tenant at full design | ~193 |
| Tenants per server (hard cap) | 300, soft cap 200 |
| Our own production code today | 3,303 lines |
| Design documents today | 17,428 lines |
| Business tables today | **0** |

**The dominant risk is volume against a very small team, not query throughput.** Any pattern that
costs more code per feature is a bad trade here, however fashionable. That single sentence decides
most of what follows.

### The competitive shape, and why it matters to the architecture

The established Sri Lankan supplier in this space ships **four separate products** — an Android/web
POS for small shops, a Windows POS, a Windows ERP, and a separate hospitality suite — across
supermarkets, textile, restaurants, bakeries, stationery and pharmacies, advertising 60+ reports.

Two consequences for us:

1. **Their structural weakness is product divergence.** A customer growing from one till to a chain
   changes product, and features drift apart across four codebases. **Our advantage is one platform
   that flexes by module.** That advantage is entirely dependent on module boundaries holding, which
   makes boundary enforcement a commercial concern and not a tidiness concern.
2. **Six industries, one core.** Pharmacy needs strip-and-tablet units and expiry; textile needs
   size-and-colour variants; restaurants need tables and kitchen tickets; supermarkets need weighable
   goods and scale labels. These must arrive as **modules and plugins, never as conditionals in the
   core.** The first `if ($industry === 'pharmacy')` in a shared service is the beginning of the
   four-product problem.

---

## 1. The decision, in one table

| Layer | Pattern | Scope |
|---|---|---|
| **Primary organisation** | **Modular monolith, boundaries enforced by a static check** | Everywhere. Non-negotiable. |
| **Inside a module** | **Vertical slices** — one feature, one folder | Everywhere, for all new code |
| **Application boundary** | **Command/Query separation** (CQRS level 1) | Everywhere. Free. No eventual consistency. |
| **Read models fed by projections** | **CQRS level 2** | **Only** reporting, dashboards and the till replica |
| **Reliability** | **Transactional outbox** | Every cross-module or external effect |
| **Financial truth** | **Double-entry ledger, one posting service** | All money |
| **Event sourcing** | **Rejected** | — |

**In one sentence:** a modular monolith of vertical slices, with command/query separation everywhere
and projections only where a measurement says they pay.

---

## 2. Why not CQRS everywhere

The owner's directive was CQRS, and the chosen reach is "projections where they pay, query boundary
everywhere". That is narrower than blanket CQRS, deliberately:

- **We have one database per tenant, so we can join.** The projections, reference tables and snapshot
  endpoints the v0.3 design needed existed to work around an *inability* to join across services. The
  v0.4 database-per-tenant decision removed that inability. Reintroducing projections everywhere
  would be paying the cost of a constraint we do not have.
- **Eventual consistency is a real cost at a till.** A cashier who voids a line and does not see stock
  move has found a bug, not a trade-off. The POS write path must stay synchronous and strongly
  consistent, which Decision 8 already requires.
- **Every projection is a second write path to maintain, plus a rebuild story.** At 94 remaining
  features and one developer, doubling the write surface is the most expensive possible choice.
- **Where projections genuinely pay, we already know:** 60+ reports against a normalised ~193-table
  schema, dashboards, and the offline replica — which is a projection whether or not we call it one.

### Why not event sourcing

The event log as system of record would change `docs/database.md` fundamentally and is **hard to
reverse once tenants hold data**. It buys auditability we already get from the audit framework and
the append-only stock ledger, and temporal queries nobody has asked for. The one place its logic
genuinely applies — "what did this cost at the time" — is already solved by snapshotting the factor
and the rate on the document line (Decision 11). Rejected.

---

## 3. Vertical slices — the pattern that actually decides maintainability

This is the least discussed and most important item on the list.

**Organise by feature, not by layer.** Not `Controllers/`, `Services/`, `Repositories/` with a feature
smeared across all three — instead one folder per feature holding its command, its handler, its
query, its validation and its tests.

```
modules/sales/src/Features/RefundSale/
    RefundSale.php              <- the command (data)
    RefundSaleHandler.php       <- the write path
    RefundSaleTest.php          <- its own test
modules/sales/src/Features/ListSalesForShift/
    ListSalesForShift.php       <- the query
    ListSalesForShiftHandler.php
```

**Why this and not layers:** a layered codebase makes you open five folders to change one behaviour,
and makes it impossible to see what a feature touches. For one developer against 94 features, "I can
change this feature by opening one folder, and delete it by deleting one folder" is worth more than
any other structural choice. It is also what makes agent-assisted work tractable — a slice is a unit
small enough to brief and review.

**Shared code earns its place by being used twice**, and moves to the module's own `Support/` only
then. Three-use rule for promotion to the kernel.

---

## 4. The boundary rules, restated as checks

These already exist or are designed. They are listed here because they are what the commercial
argument in §0 rests on.

1. **A module may use the kernel and another module's published contracts — never its internals.**
   Enforced by the boundary check in the gate, not by convention.
2. **No `if (industry)` or `if (tenant)` in shared code.** Industry behaviour is a module, a plugin, or
   configuration. If a core service needs to know the industry, the design is wrong.
3. **All modules share the tenant connection.** Foreign keys across modules inside a tenant database
   are normal and expected. There is no connection per module.
4. **Conversion happens once, at the document line, and nowhere else.** Below the document layer there
   are no units — the stock ledger, levels, batches and journal lines are base-only by rule and by
   static test.
5. **One posting service writes journal rows.** No module writes to the ledger directly.
6. **Every schema change runs against every tenant database**, through the runner, expand-and-contract.
   Never assume one database.

---

## 5. Where projections are allowed, and the rule for adding one

Allowed today, because each has a stated reason:

| Read model | Why it earns a projection |
|---|---|
| `account_balances` | A trial balance that sums every journal line since inception gets slower every month |
| `daily_summaries` | Dashboards and the 60+ report surface; must aggregate **base** quantity, never document quantity |
| The till replica | Already a projection. Must work offline, so it cannot be a query. |

**The rule for any new one:** a projection is added when a **measured** query is too slow or too
coupled, with the measurement in the task's QA report. Not because a read model felt cleaner.

**Two constraints on every projection:**

- **It must be rebuildable from source**, and a rebuild must produce the same answer. This is why the
  unit factor and the exchange rate are snapshotted on the line — resolve them at read time and a
  rebuild silently re-quantifies history.
- **Checkpointing by id is unsafe.** `outbox_events.id` is a ULID, so a reader resuming from the last
  id it saw can miss an event committed later with a smaller id. Use the claim-mark pattern — a
  nullable mark column on the source row — not a cursor.

---

## 6. What this means in practice, as a checklist for each new feature

1. Which module owns it? If the answer is "several", the boundary is wrong.
2. One folder per feature. Command or query, handler, test.
3. Does it write money? Then it goes through the posting service, never to the ledger directly.
4. Does it have a cross-module or external effect? Then through the outbox.
5. Does it need a new table? Then it is a per-tenant migration, expand-and-contract, and it runs
   against every tenant.
6. Does it need a projection? Only with a measurement that says so.
7. Does it differ by industry? Then it is a module or a plugin, not a conditional.

---

## 7. How we will know this is working

Maintainability claims are untestable unless they name an observation. These are the ones:

- **A new feature touches one folder** under a module's `Features/`, plus a migration. If a feature
  routinely touches three modules, the boundaries are wrong and should be redrawn.
- **The boundary check stays green without exemptions being added.** Each new exemption is a boundary
  being eroded; the count should not drift upward.
- **A module can be deleted.** Removing a module's folder and its entry in the modules list should
  break only that module's tests. If it breaks the core, something reached inside.
- **Reports do not appear in write-path tests.** If a change to a report breaks a sale, the read side
  has leaked into the write side.
- **The projection count stays small and each one has a measurement.** A growing list of projections
  with no measurements behind them means §5's rule has stopped being applied.

---

## 8. What is still owed

- **`docs/architecture.md` §3 still says the projections v0.3 needed are deleted.** That sentence is
  true of blanket projections and false of the three in §5. It needs the narrowing this document gives
  it.
- **`Refference/ARCH.md` never mentions CQRS, projections or an outbox**, and its reporting section
  runs reports against the tenant database. That is in tension with §5, and the owner's document
  outranks ours — so either §5's three read models are an agreed exception, or §5 narrows further.
  **This is the one open question in this document.**
- **Nothing here is enforced by a test yet.** The boundary check exists; the vertical-slice layout,
  the no-conditional-by-industry rule and the projection rule are conventions until a static test
  makes them real. Those tests belong with the first module that has features to slice.
