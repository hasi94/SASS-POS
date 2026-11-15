# T-018: Event bus and outbox

Backlog: FP-212, "Redis Streams behind an interface, outbox in the tenant database, relay, consumers,
retries, dead letter" (dependency FP-204, done in T-008). Decisions D-1 to D-17 are in
`docs/decisions/T-018-event-bus-outbox.md`. **No BLOCKING questions** — this is autonomous/team-mode
work; every open business question below was decided and logged rather than left for the human.

Convention, following T-008/T-009/T-010/T-014/T-016/T-017: every criterion is provable on a
deterministic stand-in while a real engine is unreachable, unless marked **[ENGINE]**. Two separate
conditions now sit behind that one tag:

- **MySQL/MariaDB** criteria stay gated by `ENGINE_HOLD`/gate G-1, owner-controlled, unchanged from
  every prior task.
- **Redis** criteria are gated by a plainer, non-owner-gated fact: no Redis service, no Redis client
  dependency and no Docker Compose exist in this repository today (D-1) — measured, not declared. This
  condition lifts automatically the moment that infrastructure is added, with no sign-off required,
  because (unlike G-1) no incident and no data-loss risk sit behind it.

Every **[ENGINE]** criterion below says which of the two it is NOT VERIFIED under.

## Problem / goal

FP-212 is the first task to give module code a way to say "something happened, and something else —
not in this transaction, maybe not even today — should react to it" without either blocking the till
on that reaction or losing the event if a worker dies mid-reaction. T-008 (FP-204) already shipped the
tenant-database half of the answer, `outbox_events`, migrated and empty; nothing reads it, nothing
writes it, and no `EventBus` abstraction, Redis Streams adapter, relay, consumer, retry or dead-letter
mechanism exists anywhere in the repository. `docs/architecture.md` §9 and `docs/eventbus.md` already
state the intended shape (Redis Streams behind an interface, outbox-first, idempotent consumers,
back-off then dead-letter); this task builds exactly that shape, as infrastructure, and proves it with
one small, explicitly-labelled proof-of-pipeline example — not with any real business event, because no
module that would produce one (sales, catalog, plugins, …) exists yet.

## Users affected

- Developers of every later module (plugins/FP-213, workflow/FP-214, notifications, reporting, the
  print relay, …) who need a cross-module or external effect to survive a crash between "the business
  change committed" and "the side effect ran" — today they have nowhere to put that effect and no way
  to prove it survives a crash.
- Operators, who today have no way to see a stuck or permanently-failed event; this task gives them a
  console-only (no HTTP) view and a manual retry/replay action.
- Not affected directly: cashiers and tills — this task adds no POS-facing behaviour; the till's own
  write path stays synchronous and consistent (`docs/architecture.md` §11, unchanged).
- Downstream, explicitly not served by this task: FP-213's "hook gateway with five modes" is a
  different mechanism (in-process, synchronous, vetoable) layered on top of module contracts, not on
  the event bus — this task does not design it, only avoids making it awkward (D-5, D-15).

## Current behaviour

Re-derived from the files, not assumed:

- `outbox_events` (T-008/FP-204, `modules/host/database/migrations-tenant/0001_01_01_000006_create_outbox_events_table.php`) is shipped, migrated into every tenant database, and holds these columns exactly: `id` (ULID PK), `type` VARCHAR(80), `version` SMALLINT default 1, `branch_id` CHAR(26) NULL **with no foreign key** (FP-313, deliberately deferred, unrelated to this task), `aggregate_type` VARCHAR(80), `aggregate_id` CHAR(26), `payload` JSON NULL, `occurred_at` DATETIME(3), `correlation_id` CHAR(26) NULL, `causation_id` CHAR(26) NULL, `actor` JSON NULL, `status` VARCHAR(30) default `'pending'` (legal values `pending, published, failed` — **no fourth value exists**), `published_at` DATETIME(3) NULL, `attempts` SMALLINT default 0, `last_error` TEXT NULL; indexed on `(status, occurred_at)` and `(aggregate_type, aggregate_id)`.
- That migration's own docblock already states the intended relay design in full: select `status='pending'` ordered by `occurred_at` (served by the shipped index), no claim-mark column, "when a mark is eventually needed — several relay workers — it is a claim mark... never a cursor [on `id`]". This task is the relay that comment anticipates.
- Nothing in `modules/` or `packages/` writes a row to `outbox_events`, reads one back, or references an `EventBus` concept in code. The only two repository hits for "outbox"/"EventBus" outside the migration and its own tests are the migration's docblock and `packages/kernel/src/Schema/Support/SourceRules.php` (the static rule that forced `branch_id` to be unguarded, unrelated to this task's behaviour).
- `packages/eventbus` exists only as a Composer path package scaffold: `composer.json` (description: "FlexiPOS event bus: EventBus interface, Redis Streams adapter, outbox relay and inbox dedupe") and an empty `src/.gitkeep`. No code.
- `docs/eventbus.md` is an architecture-decision document, not shipped code: it names Redis Streams as the bus, behind an application-level interface (`interface EventBus { public function publish(DomainEvent $event): void; } `), and a short, illustrative, non-binding event list (`OrderCreated`, `PaymentCompleted`, …) that names no module this task may treat as existing.
- No Redis client dependency (`predis/predis`, `ext-redis`) is required by `modules/host/composer.json` today; `modules/host/.env.example` defaults `QUEUE_CONNECTION=database` and `CACHE_STORE=database`, not `redis`; `infra/` holds only a placeholder (`infra/.gitkeep`) — no Docker Compose file exists anywhere in this repository (only inside two vendor packages' own unrelated fixtures). A real Redis connection is therefore not available in this dev/CI environment today (D-1).
- `docs/database.md` §6.21 states a 30-day retention policy for `outbox_events`; nothing builds the job that would enforce it (same gap T-017 found and deliberately left alone for `audit_logs`/`platform_audit_logs`, D-13).
- `docs/patterns.md` §5 already names the one hazard this task must not reintroduce: "checkpointing by id is unsafe... use the claim-mark pattern... not a cursor" — written specifically about `outbox_events.id`.
- `Flexipos\Access\Support\AuditWriter` (T-010/T-017) already solved, for `audit_logs`, the exact shape of two problems this task also has: a lazy, per-current-request, null-outside-a-request value (`request_id`, via `RequestId`, mirroring `ClientIp`) and a closed actor-type vocabulary (`actor_type enum(employee, terminal, system, plugin, platform_staff)`). `outbox_events` has no actor vocabulary of its own yet — only a bare `actor JSON` column.
- `modules/demo` is the project's standing, no-business-meaning scaffold module ("Replace it with real endpoints; the full API envelope is a later backlog row") and already has an empty `src/Consumers/` directory, a `ping` route and a feature test proving it boots — a pre-existing, zero-cost candidate host for this task's one proof-of-pipeline example (D-10). Separately, FP-303 already records that `modules/demo/tests` is not collected by the boundary check (Deptrac matches namespaces, and that tree is 0% namespaced) — a pre-existing gap this task does not fix, named here only so a reviewer does not expect the boundary check to see tests added there.
- No platform-admin authorization model or HTTP surface exists anywhere in the codebase for any master- or tenant-side operational command (T-016 D-21, T-017 D-7) — the same precedent this task's own operator-visibility commands (Group F) follow.

## User stories

- As a developer of a future module, I want to write a business event onto my own transaction's outbox
  with one call, so that an external effect (a notification, a webhook, a plugin hook, an analytics
  feed) is never silently lost if the worker that would have published it crashes immediately after my
  transaction commits.
- As a background worker, I want to relay unpublished outbox rows onto the event stream reliably and
  without racing myself, so that a completed business change always eventually reaches whatever
  consumer cares about it.
- As a consumer of an event, I want processing a message I have already fully handled to be a safe
  no-op, so that a redelivered message (I crashed before acknowledging it) never double-applies its
  effect.
- As an operator, I want a bounded number of retries before a stuck event stops silently retrying
  forever, so that one poison message cannot starve every later event behind it.
- As an operator, I want to see which outbox rows and which stream messages permanently failed, and to
  be able to retry or replay one deliberately, without reading the database or the Redis stream by
  hand.
- As a developer of the next infrastructure-consuming module (plugins/FP-213 among them), I want a
  stable `EventBus` abstraction already proven end-to-end against a deterministic stand-in, so that I
  can publish and consume events without caring, in my own tests, whether the backing transport is
  Redis or not.

## Acceptance criteria

### A. The event bus abstraction

- AC-1: An `EventBus` abstraction exists behind which at least two implementations live: a
  deterministic stand-in requiring no real I/O, network call or sleep (same shape as
  `InMemoryTenantFleet`), and a Redis Streams adapter. Which implementation serves a given process is
  chosen by configuration, never by module/business code — the same config-selected-contract shape
  `MappingSource` already uses for the tenant mapping (T-017 Ruling 6 precedent).
- AC-2: No module outside the event bus's own package (and the one proof-of-pipeline consumer, Group G)
  references Redis directly — no `Redis` facade call, no stream-name literal, no raw Redis command —
  anywhere else in the diff. Evidence: a boundary-rule/source-scan finds no such reference outside the
  adapter's own package, the same enforcement style the existing boundary check already applies to
  module internals.
- AC-3 [ENGINE — Redis]: The Redis Streams adapter, run against a real Redis connection, publishes an
  envelope that a real consumer group can read back with the identical payload. NOT VERIFIED in this
  environment (D-1); the stand-in bus proves the same contract deterministically in the meantime.
- AC-4: Attempting to use the Redis adapter when no Redis connection can be reached fails with a named,
  loud exception — it never silently falls back to the stand-in and never silently drops the event,
  mirroring the existing "it fails, it never skips" convention the MySQL engine harness already uses.

### B. Writing to the outbox

- AC-5: Exactly one outbox-writing capability exists. Any module wanting an event published calls it;
  no module's diff inserts into `outbox_events` directly outside that one writer. Evidence: a source
  scan for a second `->insert()`/`->table('outbox_events')` call site outside the one writer finds none
  — the same "one inserter" rule T-017 AC-19 already enforces for `audit_logs`.
- AC-6: Writing an outbox row and the business change that caused it commit in the same database
  transaction: a test that rolls back the triggering change observes no row in `outbox_events` either
  — the same convention T-014 AC-71 already proved for an audit row sharing a transaction with its
  trigger.
- AC-7: A new outbox row defaults exactly as the shipped migration already allows — `status='pending'`,
  `attempts=0`, `published_at=null` — and the writer itself sets `occurred_at` at write time (never left
  to a database default), so a caller supplying only the business columns (`type`, `aggregate_type`,
  `aggregate_id`, `payload`) still produces a publishable row.
- AC-8: The writer populates `actor` as JSON using the same closed actor-type vocabulary `AuditWriter`
  already uses (`employee, terminal, system, plugin, platform_staff`), with `actor_id`/`actor_name`
  snapshotted the same way (never re-joined later), so the tenant database does not grow a second "who
  did this" vocabulary (D-16).
- AC-9: `correlation_id` defaults via the same lazy, per-current-request, null-outside-a-request
  resolution T-017 built for audit's `request_id` (reused, not reinvented, D-16): two rows written while
  handling one request carry the same identifier; a console/queued-job write carries null unless the
  caller supplies one explicitly.
- AC-10: `causation_id` stays null unless the caller explicitly names the event/command that directly
  caused this one; the writer never invents one.
- AC-11: `branch_id` is accepted as a plain nullable value with no foreign-key enforcement — unchanged,
  owed to FP-313, not this task (D-11).
- AC-12: `version` defaults to `1` when the caller does not specify a schema version for the event
  type's payload shape, and is passed through unchanged into the published envelope.
- AC-13 (negative case): Writing a row whose `payload` is not valid JSON, or that omits a required
  business column (`type`, `aggregate_type`, `aggregate_id`), is refused by the writer **before** any
  database write — the same "a typo is caught loudly" convention T-016 AC-21/T-017 AC-4 already use —
  not left for the database's own JSON-validity constraint to discover at commit time, and never
  silently coerced.

### C. The relay

- AC-14: A relay capability selects rows where `status='pending'`, ordered by `occurred_at`, in bounded
  batches — never an unbounded "select all pending" statement, and never a cursor keyed on
  `outbox_events.id` (a ULID, which can skip a later-committed, smaller-id row — `docs/patterns.md` §5;
  D-4).
- AC-15: For each claimed row the relay publishes the envelope through the event bus; on a confirmed
  publish it marks the row `status='published'`, `published_at=<now>` in the same pass. A row already
  `published` is never selected or republished by a later pass.
- AC-16: On a publish failure the relay increments `attempts`, records `last_error` with the failure's
  message, and leaves the row `pending` for the next pass — up to a configured maximum (default 5,
  D-8). Exceeding the maximum sets `status='failed'`, after which AC-14's query no longer selects it.
- AC-17: Between attempts on the same row the relay waits with increasing back-off (exponential,
  configurable base and cap) rather than retrying in a tight loop — provable with an injectable clock,
  no real wait (same convention as T-009 AC-19/T-016 AC-11).
- AC-18: This task's relay assumes exactly one active relay worker per tenant at a time (D-4). Running
  two relay workers concurrently against one tenant's outbox is explicitly out of scope; a follow-up row
  adds the claim-mark column the shipped migration's own comment already anticipates for that case.
- AC-19 (no-op case): Running the relay against a tenant with no `pending` rows changes nothing and does
  not error.
- AC-20 [ENGINE — Redis]: The full publish step, run against a real Redis connection, is NOT VERIFIED in
  this environment (D-1); the stand-in bus proves the same claim/publish/mark/retry logic
  deterministically in the meantime.

### D. Consumers, idempotency and ordering

- AC-21: A consumer reads as a named member of a consumer group. Two consumer instances in the same
  group never both receive the same message under normal (non-redelivery) operation — provable on the
  stand-in bus, which implements the same at-most-one-active-delivery-per-message rule Redis Streams'
  consumer groups provide.
- AC-22: A consumer that successfully processes a message acknowledges it; an acknowledged message is
  never redelivered to that group again.
- AC-23: A consumer is idempotent by the event's own identifier (`outbox_events.id`, carried into the
  envelope): processing the same event identifier twice (simulated redelivery on the stand-in) produces
  the same end state as processing it once, because the consumer checks before acting — never because
  the transport is relied on for exactly-once delivery, which Redis Streams does not provide (it is
  at-least-once).
- AC-24: A message delivered but never acknowledged (the consumer crashed, or never called ack) becomes
  reclaimable by another consumer in the same group after a configured claim timeout, with the stream's
  own delivery-count tracked natively — never a second, application-level outbox dedup table (D-6).
- AC-25 [ENGINE — Redis]: Delivery-count tracking and reclaim of an unacknowledged message on a real
  Redis Streams connection is NOT VERIFIED in this environment (D-1); the stand-in bus proves the same
  redelivery-and-reclaim contract deterministically in the meantime.
- AC-26: No cross-aggregate or cross-stream ordering guarantee is asserted or built; ordering only ever
  means "append order within one stream" (D-14) — stated so a reviewer does not look for a per-aggregate
  sequencing mechanism this task does not build.

### E. Dead letter

- AC-27: A message a consumer group has failed to acknowledge after a configured maximum number of
  delivery attempts (default 5, mirroring D-8) is moved to a dedicated dead-letter stream, and the move
  is logged at a severity an operator would notice — matching `docs/architecture.md` §9's own wording
  ("move to a dead-letter stream with an alert"). It is never written into a new tenant-database table
  (D-7).
- AC-28: An outbox row that exhausts its own publish attempts (AC-16) stays `status='failed'` in
  `outbox_events` — a different failure (the relay could never reach the bus at all) from AC-27's (the
  bus delivered it, but no consumer ever finished it). The two are distinct, nameable states, never
  collapsed into one undifferentiated "it broke" bucket.
- AC-29: A console command lists `outbox_events` rows with `status='failed'` for the current tenant,
  oldest or newest first, showing enough of each row (`id`, `type`, `aggregate_type`, `aggregate_id`,
  `attempts`, `last_error`, `occurred_at`) for an operator to decide what to do next.
- AC-30: A console command lists the dead-letter stream's current entries (event identifier, original
  stream, failure reason, delivery count) for operator inspection. The real-Redis read path is
  **[ENGINE — Redis]**, NOT VERIFIED in this environment (D-1); the command's listing/formatting
  behaviour is provable against the stand-in bus.
- AC-31: A console command can move a chosen `failed` outbox row, or a chosen dead-letter stream entry,
  back to a retryable state (`pending` with `attempts` reset to 0, or re-published onto its original
  stream) — a deliberate operator action, never automatic.
- AC-32: A console command reports the age of the oldest `pending` outbox row for the current tenant —
  the "outbox age" signal `docs/architecture.md` §18 names as something to alert on. This task produces
  the number a later alerting mechanism would read; it does not build that alerting mechanism.

### F. No HTTP surface

- AC-33: This task ships no HTTP route and no admin UI for any outbox/event-bus operation (writing,
  relaying, listing failed rows, listing dead letters, retrying) — console commands and internal
  handlers only, the same precedent T-016 D-21/T-017 D-7 already established (no platform-admin
  authorization model or HTTP surface exists anywhere in this codebase yet).

### G. Proof-of-pipeline example

- AC-34: Exactly one example event proves the pipeline end-to-end, and is explicitly a proof-of-pipeline
  fixture, not new business scope (D-10): writing it through the one outbox writer (AC-5), relaying it
  (AC-14 to AC-16), delivering it to a consumer group member (AC-21), the consumer processing it
  idempotently (AC-23) and acknowledging it (AC-22) — provable in one feature test end-to-end against
  the stand-in bus, with no real Redis connection required.
- AC-35: No other, business-meaningful event (a sale, a subscription change, a stock movement, a plugin
  hook, …) is designed, named or given a producer by this task. Each becomes real the moment the module
  that owns that business fact ships it — the same "no hooks for modules that don't exist" convention
  T-017 D-15 already used. Stated so `qa` does not look for a producer nothing in this branch builds.

### H. Framework integrity and the gate

- AC-36: `composer check` (lint, static analysis, boundary rules, tests) exits 0 on the branch.
- AC-37: No module outside the event bus's own package (and whichever module hosts the proof-of-pipeline
  consumer, Group G) gains a dependency on the event bus's internals — only on its published contract —
  enforced by the existing boundary check with no new exemption added.

## Out of scope

- **Any business-meaningful event beyond the one proof-of-pipeline example** (D-10, AC-35) — `sale.*`,
  `subscription.*`, `stock.*`, a plugin hook event, or anything else naming a module that does not exist
  yet. Each rides in with its owning module.
- **`outbox_events.branch_id`'s missing foreign key** (FP-313, D-11) — already assigned elsewhere,
  unchanged by this task.
- **Multiple concurrent relay workers, and the claim-mark column that case would need** (D-4, AC-18) — a
  follow-up row once a second worker is actually needed; the shipped migration's own comment already
  anticipates it.
- **A generic shared "inbox dedupe" cache or table** beyond consumer-level idempotency-by-identifier
  (D-6) — the phrase in `packages/eventbus/composer.json`'s own scaffold description is not read as a
  requirement; `docs/database.md` explicitly deleted the v0.3 `inbox_events` table, and a shared dedup
  helper is added later, under the three-use rule, if a second consumer genuinely needs one.
- **Retention/archival for `outbox_events`** (D-13) — the existing 30-day policy in `docs/database.md`
  §6.21 stays a stated policy, not a built job, the same caution T-017 D-12 applied to the audit tables.
- **Per-aggregate or cross-stream ordering, or any stream-partitioning scheme** (D-14, AC-26) — no
  business event in scope needs one yet.
- **A metrics/alerting system or dashboard** (`docs/architecture.md` §18) — this task produces the raw
  signals (outbox age, dead-letter listing) a later alerting mechanism would read, not the mechanism
  itself (AC-32).
- **Standing up real Redis infrastructure** (a Docker Compose service, a production deployment) — a
  devops/deployment concern; this task ships code behind the interface regardless of whether that
  infrastructure exists yet (consistent with D-1's own framing).
- **The master-side `tenant.mapping.changed` cache-invalidation event** named in `docs/database.md`
  §4.4 and deferred by T-003 D-32 pending this task's existence — still not built here (D-17): it is a
  master-connection concern, a different domain from the tenant-database outbox this task builds.
  Wiring it is a follow-up for whoever next revisits that cache.
- **Any real consumer for a future module** — the plugin hook gateway (FP-213), the workflow engine
  (FP-214), notifications, or reporting. This task provides the mechanism only; each becomes a real
  consumer when its owning module ships.
- **An HTTP route or admin UI for any part of this mechanism** (AC-33).
- **`modules/demo/tests` not being collected by the boundary check** (FP-303) — a pre-existing,
  differently-scoped gap, unaffected by and not fixed by this task, even if the proof-of-pipeline
  consumer lands in that module.

## Non-functional requirements

- **At-least-once delivery, never exactly-once.** Every consumer this task builds or that a future
  module adds on top of this bus must be idempotent by event identifier (AC-23); the transport itself
  never guarantees single delivery.
- **Ordering is scoped to one stream only** (AC-26) — no claim of global or per-aggregate ordering is
  made anywhere in this task's code or tests.
- **Every batch read against `outbox_events` is bounded** (AC-14) — never a single unbounded statement,
  consistent with the existing chunking convention `docs/database.md` §9 already states for
  `sync_inbox`, `outbox_events` and `tenant_backups`.
- **A real external dependency that cannot be reached fails loudly, never silently** (AC-4) — the same
  "it fails, it never skips" principle the MySQL engine harness already applies, now extended to Redis
  by this task's own adapter.
- **No new exposure of secret or sensitive data.** The writer stores only what its caller supplies in
  `payload`/`actor`; this task adds no encryption, redaction or new data-classification layer of its
  own, and introduces no new way for a secret to reach `outbox_events` that `docs/database.md`'s
  existing columns did not already allow.

## Assumptions

- The proof-of-pipeline example event's name (`demo.pinged` or equivalent) and which module hosts its
  consumer are this spec's proposal for concreteness (D-10); exact naming and file placement are
  `dev`'s/`architect`'s technical choice, following the T-003 D-33 precedent that `ba` states behaviour,
  not file layout.
- Default attempt counts and back-off parameters (D-8) are this task's own reversible defaults,
  configurable later, in the absence of any stated business rule.
- "A real Redis connection is unavailable in this environment" (D-1) is a measured fact about this
  repository today, re-checked by this spec's own research (no service, no client dependency, defaults
  elsewhere) — it is not an owner-declared hold like `ENGINE_HOLD`/G-1, and lifts automatically the
  moment the infrastructure is actually added, with no sign-off required.
- The `EventBus` contract itself is assumed to belong in `packages/contracts` (a new domain folder,
  alongside `Access`, `Audit`, `Organization`, `Platform`), with its stand-in and Redis implementations
  in `packages/eventbus` — matching the existing contracts/implementation split (e.g.
  `Flexipos\Contracts\Audit\AuditTrail` vs. `Flexipos\Access\Support\AuditWriter`). Not a rule this spec
  imposes; stated only so a reader understands why no path is mandated in the criteria above.

## Open questions

None. Every question this task's own scoping raised was decided and logged in
`docs/decisions/T-018-event-bus-outbox.md` (D-1 to D-17), per the autonomous team protocol.
