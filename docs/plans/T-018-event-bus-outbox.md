# Plan: T-018 Event bus and outbox

Spec: `docs/specs/T-018-event-bus-outbox.md` (AC-1 to AC-37). Decisions: `docs/decisions/T-018-event-bus-outbox.md` (D-1 to D-17). Backlog row FP-212. Branch `ai/T-018-event-bus-outbox`.

**Gate baseline: not measured by `architect`.** Step 0 takes it. The T-017 close-out recorded roughly 2174 passed, 11 to 12 failed (FP-316, pre-existing) and 11 skipped. FP-328 also records that the test step's headroom is already below 2x.

**Two engine conditions, kept apart (D-1).**

- `ENGINE_HOLD` is on. Nothing below needs 127.0.0.1:3306, and no criterion is a MySQL `[ENGINE]` criterion.
- No Redis exists here. Measured: `php -m` lists no `redis`, `vendor/predis` is absent, and there is no Compose file. AC-3, AC-20, AC-25 and AC-30's real read path stay NOT VERIFIED. The plan proves the same contract on the stand-in bus with a test dataset the Redis adapter joins automatically (Ruling 9).

**Locked by `ba`, not re-decided here:**

| Decisions | Locked content |
|---|---|
| D-3 | one writer, same transaction |
| D-4 | `status='pending'` ordered by `occurred_at`, one relay worker, no claim mark |
| D-6 | idempotency by event id; no inbox table |
| D-7 | relay-side `failed`; consumer-side dead-letter **stream** |
| D-8 | 5 attempts, exponential back-off |
| D-9 | console only |
| D-10 | one proof-of-pipeline example |
| D-11 | FP-313 untouched |
| D-12 | no new columns |
| D-13 | no retention job |
| D-14 | ordering within one stream only |
| D-15 | the bus is not the Laravel queues |
| D-16 | audit's actor vocabulary; `RequestId` for `correlation_id` |
| D-17 | no `tenant.mapping.changed` |

---

## 0. Rulings

Rulings marked **-> ba** read or narrow the spec and are informational. None changes a criterion's text. The others are technical choices the spec left open.

### Ruling 1: where the proof-of-pipeline consumer lives (D-10, AC-34) -> ba, informational

**Measured: `modules/demo` cannot host it.**

- `modules/host/tests/Feature/ModuleMakeDemoRegenerationTest.php` (T-002 AC-19) moves `modules/demo` aside, runs `module:make demo`, and asserts that the regenerated tree matches the committed one byte for byte (line-ending normalised).
- So adding a file under `modules/demo/src/Consumers/`, or editing `DemoServiceProvider`, turns that test red.
- The only way to keep it green would be to change `modules/host/stubs/module/*`. That would plant a demo consumer into **every** future generated module, which is new scope and wrong.

**Decision:** the example is a **test fixture** in the event bus package's own test tree:

- `Flexipos\Eventbus\Tests\Support\PingedConsumer`, declared by `Flexipos\Eventbus\Tests\Support\FixtureEventConsumerProvider`. This is the same shape as access's `FixtureAuditActionProvider::declaring(...)`.
- The event type is `fixture.pinged`. The `fixture.` prefix says on its face that it is not business scope (AC-35).
- The consumer imports only `Flexipos\Contracts\Events\*`. That shows a consumer needs nothing but the published contract. Step 10's sweep asserts it.

D-10 says the demo home is "natural, not mandated", so no decision is overridden. `modules/demo` stays byte-identical, and FP-303 is untouched.

### Ruling 2: what is a published contract, and what stays internal (AC-1, AC-2, AC-37) -> ba, informational

The spec's closing assumption ("the `EventBus` contract … in `packages/contracts`") is stated as not binding. It is refined as follows.

**`packages/contracts/src/Events/` (new folder, published).** These are the only symbols a module ever needs:

| Symbol | What it is |
|---|---|
| `Outbox` | interface: `record(NewEvent $event): string`, which returns the new row's id. **The one writer (AC-5)** |
| `NewEvent` | readonly DTO: `type`, `aggregateType`, `aggregateId`, `?array payload`, `int version = 1`, `?string branchId`, `?string causationId`, `?string correlationId` (an explicit override), `?EventActor actor` |
| `EventActor` | readonly: `ActorType type`, `?string id`, `?string name`. Named constructors: `employee`, `terminal`, `plugin`, `platformStaff`, `system` |
| `EventEnvelope` | readonly, what a consumer receives: `id`, `tenantId`, `type`, `version`, `aggregateType`, `aggregateId`, `branchId`, `payload`, `occurredAt`, `correlationId`, `causationId`, `actor` |
| `EventConsumer` | interface: `group(): string`, `eventTypes(): list<string>`, `handle(EventEnvelope): void`. The docblock says it **must be idempotent by `$event->id`** (AC-23) |
| `DeclaresEventConsumers` | provider interface: `eventConsumers(): list<class-string<EventConsumer>>`. Collected through `$app->getProviders()`, exactly as `AuditCatalogue` collects `DeclaresAuditActions` |
| `OutboxEventRefused` | extends `InvalidArgumentException`, with one named constructor per AC-13 reason |

Also in contracts: `packages/contracts/src/Audit/ActorType.php`, a backed enum with `employee, terminal, system, plugin, platform_staff` (Ruling 4).

**`packages/eventbus` (internal).**

- The transport port, `Flexipos\Eventbus\Contracts\EventBus`, and its two implementations.
- The writer, the relay, the consumer runner, dead-letter handling and the operator commands.

**Why the transport port is not published.** D-3 and AC-5 say modules publish only through the outbox. Putting `EventBus::publish()` in contracts would hand every module a way around the outbox that the boundary check could never flag, because contracts are allowed everywhere.

**Why this is not a kernel-owned port like `RecordsMigrationRuns`.** That port exists because the kernel may not depend on platform (a cycle). Here the callers are packages and modules, which already reach `Package_Contracts`. `AuditTrail` (contracts) implemented by `AuditWriter` (access) is the exact precedent.

### Ruling 3: the transport port and its semantics (AC-1, AC-21 to AC-25, AC-27)

`Flexipos\Eventbus\Contracts\EventBus`. Every method throws `EventBusUnreachable` when the transport cannot be reached.

| Method | Redis Streams meaning |
|---|---|
| `publish(string $stream, EventEnvelope $e): string` | `XADD stream MAXLEN ~ n * envelope <json> type <type> event_id <id>`; returns the message id |
| `ensureGroup(string $stream, string $group): void` | `XGROUP CREATE stream group $ MKSTREAM`; a `BUSYGROUP` reply is success. Idempotent |
| `read(stream, group, consumer, int $count): list<Delivery>` | `XREADGROUP GROUP g c COUNT n STREAMS stream >`. New messages only; each delivery count is 1 |
| `acknowledge(stream, group, string $messageId): void` | `XACK` |
| `pending(stream, group, int $count): list<PendingDelivery>` | `XPENDING stream group - + n`: id, consumer, idle ms, delivery count. **These counts are native** (AC-24) |
| `claim(stream, group, consumer, list<string> $ids, int $minIdleMs): list<Delivery>` | `XCLAIM`. Redis increments the count; the returned `Delivery` carries it |
| `deadLetter(string $deadStream, Delivery $d, string $group, string $reason): string` | `XADD` to the dead stream, then `XACK` of the original, in that order |
| `deadLetters(string $deadStream, int $count, bool $newestFirst): list<DeadLetter>` | `XRANGE` or `XREVRANGE` |
| `replay(string $deadStream, string $deadId): string` | `XADD` the stored envelope back onto its original stream, then `XDEL` the dead entry. A crash between the two leaves a harmless duplicate in the dead list, never a lost event |

Value classes in `packages/eventbus/src/Bus/`:

- `Delivery`: `messageId`, `stream`, `EventEnvelope|null envelope` (null when the entry cannot be decoded), `rawFields`, `deliveryCount`.
- `PendingDelivery`.
- `DeadLetter`: `deadId`, `eventId`, `tenantId`, `type`, `originalStream`, `originalMessageId`, `group`, `deliveryCount`, `reason`, `deadAt`, `envelope`.

**Stream topology.**

- One event stream, `config('eventbus.stream')` (default `flexipos:events`), shared by all tenants.
- One dead-letter stream, `config('eventbus.dead_letter_stream')` (default `flexipos:events:dead`).
- Each consumer is its own **group**, so every group sees every event. A group acknowledges at once, as a no-op, any type it does not list in `eventTypes()`.
- Ordering is "append order within this one stream" and nothing more (D-14, AC-26).
- Rejected: one stream per type or per tenant. That multiplies keys and groups for a need no event has yet.

**Groups are created at `$`, and ensured by both sides.**

- The relay calls `ensureGroup()` for every declared consumer group once per process, before its first publish. So a group that exists in code never misses an event, even if its consumer process has never run.
- A consumer added in a later deploy starts from the moment of its creation, and does not replay the retained history.

**The envelope carries `tenant_id` (-> ba, informational).** One stream serves every shop. A consumer cannot know which tenant database to act in unless the message says so.

- The relay stamps the id from the **resolved** `TenantContext`, never from a caller, so an event cannot carry another shop's id.
- It is not a column, so D-12 is intact.

**Wire format.** `Flexipos\Eventbus\Support\EnvelopeCodec` encodes and decodes the envelope as one JSON field, so the payload round-trips exactly (AC-3). `type` and `event_id` are duplicated as top-level fields for inspection only.

**The stand-in, `Flexipos\Eventbus\Bus\InMemoryEventBus`.** Pure PHP arrays, no I/O and no sleep. It holds:

- streams as ordered entries with monotonic `<ms>-<seq>` ids, taken from the `RunnerClock` plus a counter;
- per group, a last-delivered id and a pending-entries list of id, consumer, delivery count and delivered-at.

Idle time is measured with the injected `RunnerClock`. It implements exactly the Redis rules in the table:

- a message is delivered by `read()` to one consumer of a group only (AC-21);
- `acknowledge()` removes it from the pending list for good (AC-22);
- `claim()` reassigns only entries idle for at least `minIdleMs`, and increments their count (AC-24);
- a group created after a publish does not see that publish.

It never trims. It is a singleton, so a relay pass and a consume pass in **one process** share it. It cannot carry messages between two processes, which is why it is refused outside local environments (Ruling 8).

### Ruling 4: the actor vocabulary is not in code today (AC-8, D-16) -> ba, spec citation correction

The spec says `AuditWriter` "already uses" `employee, terminal, system, plugin, platform_staff`. Measured:

- That five-value enum exists **only in `docs/database.md` §6.21** (`audit_logs.actor_type`).
- `AuditWriter` has one constant, `ACTOR_TYPE = 'employee'`.
- `PlatformAudit` writes `user` or `system`, a different vocabulary, on the master.

**Decision.**

- **One authority in code.** `Flexipos\Contracts\Audit\ActorType`, a backed enum with the five documented values. It lives in contracts because both access and eventbus need it and neither may import the other.
- **`AuditWriter` points at it.** `AuditWriter::ACTOR_TYPE = ActorType::Employee->value` (PHP 8.2 permits enum property fetches in constant expressions). The stored value is unchanged, so this is a one-line edit.
- **`PlatformAudit` is not touched.** The master's `user`/`system` is a different table with a different documented vocabulary.
- **The outbox `actor` JSON** is `{"type": <ActorType value>, "id": <id|null>, "name": <snapshot|null>}`. The three keys mirror `audit_logs`' three actor columns.
- **No actor given** means `{"type":"system","id":null,"name":null}`. The column is always populated, never null.

**`name` is supplied by the caller (-> ba, informational).** The writer stores it as given at write time and never re-joins it, which is the property D-16 asks for.

- The writer does **not** look names up. `employees` is access's table, no published contract returns a display name (`EmployeeDirectory` has `isLive()` only), and terminals and plugins have no name source at all.
- Adding a name-lookup contract is scope that no criterion asks for.

### Ruling 5: the writer's refusals (AC-13) and defaults (AC-7, AC-9 to AC-12)

`Flexipos\Eventbus\Support\OutboxWriter implements Outbox`, bound as a singleton.

**Validation runs before the connection is touched.** It throws `OutboxEventRefused` with the reason named:

| Field | Rule | Why |
|---|---|---|
| `type` | non-empty; at most 80 characters; matches `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$` (dotted lower case, as in `sale.completed`) | -> ba informational: the "a typo is caught loudly" convention applied to the one string a consumer subscribes by |
| `aggregateType` | non-empty after trim; at most 80 characters | |
| `aggregateId`, and `branchId`, `causationId`, `correlationId` when given | the 26-character upper-case Crockford identifier shape every `IdentifierColumn` holds | -> ba informational. A lower-case spelling is refused, the safe side of FP-309 |
| `version` | 1 to 65535 | `SMALLINT UNSIGNED` |
| `payload` | `json_encode(..., JSON_THROW_ON_ERROR)` must succeed; a failure (invalid UTF-8, `NAN`, `INF`) refuses | "Not valid JSON" for an array payload means "cannot be encoded"; it is never coerced. Partial-output flags are forbidden |

**Defaults.**

- `id` is `(string) Str::ulid()`, the form the access handlers already use.
- `occurred_at` is `RunnerClock::timestamp()`, set by the writer, never by a database default (AC-7).
- `status`, `attempts` and `published_at` are **omitted**, so the schema's own defaults apply (AC-7: one authority, the migration).
- `correlation_id` is the caller's override, else the kernel's `RequestId::current()` (AC-9).
- `causation_id` is the caller's value, else null. The writer never invents one (AC-10).
- `branch_id` is stored as given; there is no foreign key (AC-11, FP-313).

**The connection.** The writer uses the tenant connection named by `config('tenancy.connection')`, through `Flexipos\Eventbus\Support\TenantConnection`, and **never opens a transaction**. The caller's transaction carries the row, which is AC-6. With no tenant resolved, the kernel's `NoTenantResolved` fires, which is loud.

**`TenantConnection` is a third copy.** Access and organization each already have one. The three-use rule now says "promote to the kernel". This plan does **not** do that, because it would refactor two reviewed packages. -> lead: propose a follow-up row.

### Ruling 6: the relay and its back-off without a column (AC-14 to AC-19, D-4, D-8, D-12)

The `RelayOutbox` slice (`Features/RelayOutbox/`). Each call is **one bounded pass for the tenant currently entered**:

1. Select `* from outbox_events where status = 'pending' order by occurred_at, id limit :batch`.
   - `batch` is `eventbus.relay.batch_size`, default 100, clamped to at most 500.
   - `id` is only a deterministic tie-break inside one `ORDER BY`. It is **never a resume cursor**, so `docs/patterns.md` §5 holds (AC-14).
2. For each row:
   - skip it if `RelayPacing` says its next attempt is not due (see the back-off below);
   - otherwise build the envelope (`EnvelopeCodec`, stamped with the context's tenant id, `version` passed through unchanged, AC-12) and `publish()` it.
3. **On success:** `update … set status='published', published_at=<clock> where id=? and status='pending'`, in the same pass (AC-15). The pacing entry is forgotten.
4. **On any `Throwable` from building or publishing** (`EventBusUnreachable` included):
   - `attempts = attempts + 1` and `last_error` = the message, cut to 1000 characters;
   - if the new `attempts >= eventbus.relay.max_attempts` (default 5), set `status='failed'`, forget the pacing entry, and log at `error` with the tenant, row id and type (AC-16, AC-28);
   - otherwise the row stays `pending`, and pacing records `due = now + delay(attempts)`.
5. Return a `RelayOutboxResult`: published, retried, exhausted and skipped counts.

**The back-off.** `delay(n) = min(base × 2^(n-1), cap)`. Defaults are `backoff_base_seconds = 30` and `backoff_cap_seconds = 600`, so a row is `failed` after about 7.5 minutes of an unreachable bus (risk 3). The formula lives in `Flexipos\Eventbus\Support\Backoff::delaySeconds(int $n, int $base, int $cap)`, shared with the consumer side (Ruling 7).

**Why pacing is in-process.** D-12 forbids a `next_attempt_at` column.

- `Flexipos\Eventbus\Support\RelayPacing` is a singleton map, keyed by tenant id and row id, of due instants from the injected `RunnerClock` (AC-17, no real wait).
- `attempts` stays the durable truth, so the maximum is honoured across restarts. A restart only forgets the pacing, which gives at most one early retry per row.
- Rejected: deriving "due" from `occurred_at`. An old row would retry with no delay.
- Rejected: pacing in the cache. That adds a hidden state store whose backing connection varies, for a pacing-only concern.

**`RunnerClock` gains `advanceSeconds(int)`** (an additive kernel edit), beside `advanceMinutes()`. The back-off is in seconds.

**Single worker (AC-18).** The docblock states D-4 and points at the migration's own claim-mark note. Nothing enforces exclusivity, and that is deliberate.

**The command.** `eventbus:relay {--tenant=*} {--once} {--max-passes=0} {--sleep=1}`.

- It visits `TenantFleet::servingTenantIds()`, or the named tenants, through `Tenancy::run($id, …)`. It names no `TenantConnectionPurpose` (the deptrac rule withheld from packages, as in `audit:archive`).
- One tenant's failure is logged and the loop continues; `--once` exits 1 if any tenant failed or any row failed.
- It resolves the handler **once** and loops, sleeping between passes with `Illuminate\Support\Sleep`. Tests use `Sleep::fake()`, so nothing really waits.
- It calls `ensureGroup()` for every declared consumer group once, before the first pass.
- **No schedule entry.** Like `queue:work`, it is a long-running worker (`docs/deployment-vps.md`, pos-worker). -> lead: nothing runs it in production yet, the same family as FP-319.
- Tenants that are not serving (`read_only`, `suspended`) are not relayed until they are active again (T-017 Ruling 6's reason).

### Ruling 7: consumers, redelivery and dead letter (AC-21 to AC-27)

The `ConsumeEvents` slice. `eventbus:consume {--group=*} {--consumer=} {--once} {--max-passes=0} {--sleep=1}`.

- No `--group` means every declared group, round-robin.
- `--consumer` defaults to `<hostname>-<pid>`.

`Flexipos\Eventbus\Support\ConsumerRegistry` collects `DeclaresEventConsumers` providers. It refuses, with a `LogicException`:

- a duplicate group name;
- a group name outside `^[a-z0-9][a-z0-9._-]*$`;
- a class that does not implement `EventConsumer`.

**One pass for one group:**

1. `ensureGroup()`.
2. **Reclaim.** Read `pending()` and claim the entries whose idle time is at least `delay(deliveryCount)`, using `Backoff` with `claim_timeout_seconds` (default 60) as the base and `backoff_cap_seconds` (default 900) as the cap. The claim timeout is the floor, so a slow but live consumer is not robbed of its message. The back-off is built from Redis's own idle time and delivery count, with no extra state.
3. **Read new messages:** `read(count = consumer.batch_size, default 10)`.
4. **For each delivery:**
   - **Undecodable envelope:** dead-letter at once, reason `malformed envelope` (a poison message never succeeds).
   - **`deliveryCount > max_deliveries`** (default 5, D-8): dead-letter with the reason "not acknowledged after N deliveries". The handler is not called. This covers consumers that crashed without throwing.
   - **Type not in `eventTypes()`:** `acknowledge()` (a no-op for this group).
   - **Otherwise:** `Tenancy::run($envelope->tenantId, fn () => $consumer->handle($envelope))`, then `acknowledge()` (AC-22).
     - **On a `Throwable`:** if `deliveryCount >= max_deliveries`, dead-letter with the exception's class and message as the reason. Otherwise leave it unacknowledged and log at `warning`. It is reclaimed after its back-off (AC-24).
5. **Every dead-letter move** is logged at **`critical`** with the event id, tenant, group, stream, delivery count and reason (AC-27, "with an alert"). Nothing is written to a tenant table (D-7).

**Idempotency (AC-23)** is the consumer's own job, by `$envelope->id`. The fixture consumer shows the recommended shape: inside **one tenant transaction**, check for a marker row keyed by the event id; if it is absent, apply the effect and insert the marker. Because the effect and the marker commit together, there is no crash window.

The fixture's `fixture_pings` table is created by the test on the throwaway fixture tenant. **No migration is shipped**: it is not schema, it is a test's own table.

### Ruling 8: driver selection, and failing loudly (AC-1, AC-4)

`config('eventbus.driver')`: `redis` (shipped default) or `memory`. `Flexipos\Eventbus\Bus\EventBusDriver::resolve()` is built like `MappingSource`:

- **An unknown value** throws `EventBusDriverNotPermitted::unknown()`.
- **`memory` outside `EnvSecretResolver::LOCAL_ENVIRONMENTS`** throws `EventBusDriverNotPermitted::standInOutsideLocal()`. A process-local bus in production would silently lose every event, which is exactly what AC-4 forbids.
- **Binding is lazy.** `EventbusServiceProvider` binds `EventBus` as a singleton closure. Booting the application never connects; only first use does.
- **`RedisStreamsEventBus`** takes `Illuminate\Contracts\Redis\Factory` and the connection name `eventbus.redis.connection` (default `default`).
  - Every Redis interaction runs through one private `raw(array $args)`. It calls `rawCommand` (phpredis) or `executeRaw` (predis), chosen with `method_exists` on the client object, so PHPStan needs neither class.
  - **Any `Throwable`** from resolving the connection or running a command becomes `EventBusUnreachable` (named, carries the connection name, keeps the previous exception). There is no catch-and-continue and no fallback (AC-4).
  - The one non-error it swallows is `BUSYGROUP` in `ensureGroup()`.
- **phpunit.xml sets `EVENTBUS_DRIVER=memory`,** so no test can reach Redis by accident.

### Ruling 9: the Redis criteria, ready to lift (AC-3, AC-20, AC-25, AC-30)

`packages/eventbus/tests/Bus/EventBusContractTest.php` is **one dataset of contract cases run against both buses**:

- the `memory` row always runs;
- the `redis` row runs only when `EVENTBUS_REDIS_TEST_CONNECTION` names a configured Redis connection. Otherwise it is skipped with the reason `REDIS ENGINE (T-018 D-1): no Redis infrastructure or client in this repository; set EVENTBUS_REDIS_TEST_CONNECTION to run`;
- **when the variable is set and Redis is unreachable, the cases fail**: the "it fails, it never skips" convention.

So the day infrastructure lands, the same cases prove the adapter with no spec change (D-2). **Lifting also needs a Redis client** (`ext-redis` or `predis/predis`). None is added here, so the plan has no new dependency.

The skip count rises by exactly the number of contract cases (about 10). `qa` should expect that.

### Ruling 10: operator commands (AC-29 to AC-32, D-9)

Each is a slice with a command. There are no routes (AC-33).

| Command | Slice | Behaviour |
|---|---|---|
| `eventbus:failed --tenant= [--newest] [--limit=]` | `ListFailedOutboxEvents` | `status='failed'`, oldest first by `occurred_at` (newest with the flag). Shows `id`, `type`, `aggregate_type`, `aggregate_id`, `attempts`, `last_error`, `occurred_at`. The limit uses the kernel's `PageSize::clamp($asked, 50, 500)`, reused from T-017 |
| `eventbus:retry --tenant= {id}` | `RetryFailedOutboxEvent` | `update … set status='pending', attempts=0 where id=? and status='failed'`. Zero rows affected (unknown id, or `pending`/`published`) means a named refusal and exit 1. `last_error` is **kept** as the evidence until the next failure overwrites it (-> ba informational) |
| `eventbus:dead-letters [--newest] [--limit=]` | `ListDeadLetters` | Shows event id, tenant, type, original stream, group, delivery count, reason and dead-at. It is fleet-wide because the stream is shared |
| `eventbus:replay {dead-id}` | `ReplayDeadLetter` | `EventBus::replay()`. An unknown id refuses with exit 1. **The event re-enters the shared stream, so every group sees it again.** Groups that already handled it no-op by AC-23 (-> ba informational: Redis cannot target one group) |
| `eventbus:outbox-age [--tenant=*]` | `ReportOutboxAge` | Per tenant, defaulting to the fleet: `min(occurred_at) where status='pending'`, printed as seconds before now from `RunnerClock`, or `none`. This is the raw signal of `docs/architecture.md` §18; no alerting is built |

### Ruling 11: configuration

New file `modules/host/config/eventbus.php`, numbers and names only, in `config/audit.php`'s header style. Every number is validated where it is used: a non-positive value throws a `LogicException`, and batch sizes are clamped to at most 500.

| Key | Default |
|---|---|
| `driver` | `env('EVENTBUS_DRIVER', 'redis')` |
| `stream` | `env('EVENTBUS_STREAM', 'flexipos:events')` |
| `dead_letter_stream` | `env('EVENTBUS_DEAD_LETTER_STREAM', 'flexipos:events:dead')` |
| `max_length` | 100000 (approximate `MAXLEN ~`, `docs/deployment-vps.md`; risk 4) |
| `redis.connection` | `env('EVENTBUS_REDIS_CONNECTION', 'default')` |
| `relay.batch_size` / `max_attempts` / `backoff_base_seconds` / `backoff_cap_seconds` / `poll_seconds` | 100 / 5 / 30 / 600 / 1 |
| `consumer.batch_size` / `max_deliveries` / `claim_timeout_seconds` / `backoff_cap_seconds` / `poll_seconds` | 10 / 5 / 60 / 900 / 1 |

`.env.example` gains `EVENTBUS_DRIVER=redis`, with a one-line comment that a local run needs Redis, because the memory bus is single-process.

---

## 1. Approach summary

1. **Contracts first.** Modules see only `Outbox`, `NewEvent`, `EventActor`, `EventEnvelope`, `EventConsumer`, `DeclaresEventConsumers`, `OutboxEventRefused` and `ActorType`. The transport port is internal to `packages/eventbus` (Ruling 2).
2. **One writer.** `OutboxWriter` validates first (AC-13), then inserts on the caller's tenant connection inside the caller's transaction. It snapshots the actor, fills the correlation id lazily from the request, and leaves the status defaults to the schema.
3. **The relay** is a bounded `pending`-by-`occurred_at` pass per serving tenant. It publishes, marks rows published, and on failure counts attempts and backs off in-process, with `failed` after 5 attempts (Ruling 6).
4. **Consumers** are consumer groups declared by providers, run by `eventbus:consume`. Redelivery and exponential reclaim use Redis's native idle time and delivery count. After 5 deliveries a message moves to a dead-letter stream with a `critical` log (Ruling 7).
5. **Two buses behind one port,** chosen by configuration: a deterministic in-memory stand-in and a Redis Streams adapter that fails loudly. One contract dataset proves both, and the Redis row is skipped until D-1 lifts (Rulings 8 and 9).
6. **Operator commands only** (Ruling 10). The proof-of-pipeline is a test fixture, `fixture.pinged`, proven end-to-end in one test (Ruling 1).

## 2. Alternatives rejected

- **The consumer in `modules/demo`.** It breaks `ModuleMakeDemoRegenerationTest`, or forces a demo consumer into every generated module (Ruling 1).
- **`EventBus::publish` as a published contract.** It invites bypassing the outbox, and the boundary check cannot see that (Ruling 2).
- **A kernel port for the writer.** No cycle exists to break here (Ruling 2).
- **A `next_attempt_at` column, or pacing in the cache.** The first is forbidden by D-12; the second is a hidden second state store (Ruling 6).
- **A stream per type or per tenant.** No measured need (Ruling 3).
- **A Redis client dependency now.** D-1 and the infrastructure are out of scope, and nothing could run it (Ruling 9).
- **A scheduled relay.** A once-a-minute pass cannot give increasing back-off, and FP-319 means no scheduler runs anyway (Ruling 6).
- **A declared event-type catalogue** like T-017's audit actions. No read-side filter needs one yet; the first real producer can add it. The `type` pattern in Ruling 5 catches the common typo.
- **Promoting `TenantConnection` to the kernel in this task.** It refactors two reviewed packages (Ruling 5).

---

## 3. Files to change or create

`dev` writes every file. Files named `Qa*` are `qa`-accountable.

### `packages/contracts` (new)

| Path | What |
|---|---|
| `src/Events/{Outbox,NewEvent,EventActor,EventEnvelope,EventConsumer,DeclaresEventConsumers,OutboxEventRefused}.php` | Ruling 2. Pure PHP, with no framework or kernel import (`Package_Contracts: ~`) |
| `src/Audit/ActorType.php` | Ruling 4 |

### `packages/eventbus` (all new, except the existing scaffold)

| Path | What |
|---|---|
| `src/EventbusServiceProvider.php` | Bindings: `EventBus` via `EventBusDriver`, lazy; `Outbox` to `OutboxWriter`; singletons `RelayPacing`, `ConsumerRegistry`, `InMemoryEventBus`. Registers the 7 commands in `runningInConsole()`. **No schedule** |
| `src/Contracts/EventBus.php` | Ruling 3 |
| `src/Bus/{InMemoryEventBus,RedisStreamsEventBus,EventBusDriver,Delivery,PendingDelivery,DeadLetter}.php` | Rulings 3 and 8 |
| `src/Exceptions/{EventBusUnreachable,EventBusDriverNotPermitted}.php` | Ruling 8 |
| `src/Support/{OutboxWriter,TenantConnection,EnvelopeCodec,Backoff,RelayPacing,ConsumerRegistry}.php` | Rulings 3 to 7 |
| `src/Features/RelayOutbox/{RelayOutbox,RelayOutboxHandler,RelayOutboxResult,RelayOutboxCommand}.php` | Ruling 6 |
| `src/Features/ConsumeEvents/{ConsumeEvents,ConsumeEventsHandler,ConsumeEventsResult,ConsumeEventsCommand}.php` | Ruling 7 |
| `src/Features/{ListFailedOutboxEvents,RetryFailedOutboxEvent,ListDeadLetters,ReplayDeadLetter,ReportOutboxAge}/{<Name>,<Name>Handler,<Name>Command}.php` | Ruling 10. Each slice satisfies `VerticalSliceLayoutTest`: `<Name>.php`, `<Name>Handler.php`, and a mirrored `tests/Features/<Name>/*Test.php` |
| `tests/Support/{FixtureEventConsumerProvider,PingedConsumer,FailingEventBus,RecordingLogger?}.php` | Ruling 1. `FailingEventBus` decorates the stand-in and throws on the next N publishes or always. Use `Log::spy()` instead of `RecordingLogger` if it suffices |
| `tests/Support/eventbus-fixture.php` | file-scope helpers: `eventbusEnterTenant()` (wraps `AccessTenantFixture::boot()` and `enter()`), `eventbusClockAt()`, `eventbusCreatePingsTable()` |
| `tests/Contracts/{NewEventTest,EventActorTest,ActorTypeTest}.php` | step 2 |
| `tests/Bus/{EventBusContractTest,EventBusDriverTest,RedisStreamsEventBusUnreachableTest,EnvelopeCodecTest}.php` | steps 3 and 4 |
| `tests/Support/{OutboxWriterTest,OutboxTransactionTest,BackoffTest,RelayPacingTest,ConsumerRegistryTest}.php` | steps 5 to 7 |
| `tests/Features/<each slice>/…Test.php` | steps 6 to 8 |
| `tests/Pipeline/ProofOfPipelineTest.php` | step 9 (AC-34) |

### Host, `modules/host`

| Path | What |
|---|---|
| `config/eventbus.php` *(new)* | Ruling 11 |
| `tests/Feature/OutboxCorrelationHttpTest.php` *(new)* | AC-9's HTTP half (step 5) |
| `tests/Feature/QaEventbusSourceRulesTest.php`, `QaEventbusNoHttpSurfaceTest.php` *(new)* | step 10 |

---

## 4. Data and API changes

- **Tenant schema: no change.** No migration, the catalogue is not republished, and `APP_REQUIRED_SCHEMA_VERSION` does not move (D-12, D-7).
- **Master schema:** no change.

**Writes to `outbox_events`:**

| Operation | Done by |
|---|---|
| insert | `OutboxWriter` only (AC-5) |
| `pending` to `published`, `attempts`, `last_error`, `failed` | `RelayOutboxHandler` |
| `failed` to `pending` | `RetryFailedOutboxEventHandler` |
| delete | nobody (D-13) |

**Redis (when present):** the event stream, the dead-letter stream, one consumer group per declared consumer, and `MAXLEN ~ 100000` on the event stream.

**Console:** the 7 commands of Rulings 6, 7 and 10.

**HTTP:** none.

**New environment keys** (all optional): `EVENTBUS_DRIVER`, `EVENTBUS_STREAM`, `EVENTBUS_DEAD_LETTER_STREAM`, `EVENTBUS_REDIS_CONNECTION`, and the test-only `EVENTBUS_REDIS_TEST_CONNECTION`.

**Dependencies:** none added. The Redis code compiles against `illuminate/redis`, which is already in `laravel/framework`.

## 5. Boundary (deptrac and PHPStan) impact

All in `modules/host/deptrac.yaml`. These are **edge changes, not exemptions**.

- **Add the path** `../../packages/eventbus/tests`.
- **Split the layer** the way platform's is split:
  - `Package_Eventbus` is `^Flexipos\\Eventbus\\(?!Tests\\).*`;
  - `Package_Eventbus_Tests` is `^Flexipos\\Eventbus\\Tests\\.*`.
- **`Package_Eventbus: ~` becomes `[Package_Kernel, Package_Contracts]`.** This is a widened allowed edge, the same kind T-009 added for auth. `Package_Kernel_TenancyStatus` is withheld, as it is for access and platform.
- **`Package_Eventbus_Tests` reaches** `Package_Eventbus`, `Package_Access` (for `AccessTenantFixture` only, the organization-tests precedent), `Package_Contracts` and `Package_Kernel`.
- **`Module_Demo` loses `Package_Eventbus`.** This is a narrowing, and it is **AC-37's instrument**: a module may reach the event bus only through `Flexipos\Contracts\Events`.
  - Measured: nothing in `modules/demo` references `Flexipos\Eventbus` today.
  - **Negative control (step 1):** a scratch class in `modules/demo/src` importing `Flexipos\Eventbus\Support\OutboxWriter` must fail `composer deptrac`. Importing `Flexipos\Contracts\Events\Outbox` must pass. Delete both afterwards; the memory note on probes applies.
- **`HostApp` and `HostTests`** keep `Package_Eventbus`. The host is the composition root.

**PHPStan:** add `../../packages/eventbus/tests` to `phpstan.neon.dist`.

**Composer:** `modules/host/composer.json` `autoload-dev` gains `"Flexipos\\Eventbus\\Tests\\": "../../packages/eventbus/tests/"`, then run `composer dump-autoload`.

- `autoload-dev` is not in the lock's content hash, so `composer.lock` should not move. Step 1 confirms that with `composer validate --no-check-publish`.
- `packages/eventbus/composer.json` is **left unchanged**, including its "inbox dedupe" description (D-6 reads it as aspirational), to avoid lock churn. -> lead: optional cosmetic follow-up.

**Stated limit (memory).** Deptrac proves type references only. The "no Redis outside the package" and "one inserter" rules are carried by step 10's text scans.

---

## 6. Implementation steps

Each step ends with `composer check` green from `modules/host`, with a zero-delta-or-explained comparison against the step's start. **Before each gate run, `dev` lists the foreign `php.exe` processes.**

**Red-list rule.** After steps 1, 2 and 5, record the red list **before** fixing anything, and compare it with section 9. A red file section 9 does not predict is a **stop and report to `architect`**.

**Red-first rule.** Show red by omission or by a fixture. Never stub a refusal to allow.

**No child processes.** None of these tests may spawn a process: use `Artisan::call` in-process. Otherwise they join the `toolchain` group (`ToolchainGroupBindingTest`).

**Vocabulary.** The whole working tree is swept by `QaVocabularyRenameSweepTest`. New files, comments and `.env.example` lines must not use the retired pre-T-005 vocabulary for modules (memory: describe, don't quote).

- [ ] **Step 0: baseline.**
  - Run `composer check` and record the passed, failed and skipped counts, the test-step seconds, the FP-328 headroom line and the process list in the commit message.
  - *(No AC.)*

- [ ] **Step 1: package plumbing.**
  - The deptrac, PHPStan and composer edits from section 5, and `dump-autoload`.
  - Create `config/eventbus.php`, and an `EventbusServiceProvider` with no bindings yet.
  - Register the provider in `bootstrap/providers.php` after `PlatformServiceProvider`.
  - Add `EVENTBUS_DRIVER=memory` to `phpunit.xml` and `EVENTBUS_DRIVER=redis` to `.env.example`.
  - **One test, `tests/Support/EventbusProviderTest.php`:**
    - the provider is loaded;
    - `config('eventbus.relay.max_attempts')` is 5 and `consumer.max_deliveries` is 5;
    - booting connects to nothing.
  - Run section 5's demo negative control.
  - **Predicted red:** none.
    - `QaToolchainRegistryCoverageTest` already lists `eventbus`. Its tests tree is namespaced, so it is collected and needs no `qaGlobalNamespaceTestTrees()` entry.
    - A red `ConfigCacheGuardTest` or `QaVocabularyRenameSweepTest` is a stop.
  - *(AC-37 instrument.)*

- [ ] **Step 2: contracts.**
  - Create the Ruling 2 files, `ActorType`, and the `AuditWriter::ACTOR_TYPE` edit.
  - **Tests:**
    - `ActorTypeTest`: exactly the five values, in the `docs/database.md` order.
    - `EventActorTest`: each named constructor's type; `system()` has null id and null name; the JSON shape is `{type,id,name}`.
    - `NewEventTest`: the defaults are `version` 1 and nulls.
  - **Predicted red:** none. `AuditWriterTest` still sees `employee`; a red one is a stop.
  - *(AC-8 vocabulary half.)*

- [ ] **Step 3: the port and the stand-in.**
  - Create `EventBus`, `InMemoryEventBus`, `Delivery`, `PendingDelivery`, `DeadLetter`, `EnvelopeCodec`, `EventBusDriver` and `EventBusDriverNotPermitted`, plus the binding.
  - **`EventBusContractTest`, `memory` row.** Fixed clock throughout. The cases:
    - publish, then read: the identical envelope (payload compared with `toBe` after decode) (AC-3 on the stand-in);
    - two consumers in one group with 3 messages: delivered sets are disjoint and their union is all 3 (AC-21);
    - after an ack, a reclaim far in the future gets nothing (AC-22);
    - an unacknowledged message is not claimable before `minIdle` and is claimable at `minIdle`, by another consumer, with `deliveryCount` 2 (AC-24, AC-25 on the stand-in);
    - a group created after a publish does not see it, while a group created before does;
    - `ensureGroup` twice is a no-op;
    - a dead-letter is in the dead stream, acknowledged in the original, and listed oldest and newest first (AC-30 on the stand-in);
    - replay re-appends with the identical envelope and removes the dead entry; an unknown id refuses.
  - **`EventBusDriverTest`:**
    - `memory` resolves the stand-in under `testing`;
    - `memory` under `production` throws `EventBusDriverNotPermitted`;
    - `kafka` throws the unknown refusal.
  - **`EnvelopeCodecTest`:** round trip; unicode and nested payloads survive; a corrupt field decodes to null, never a throw.
  - *(AC-1, AC-21, AC-22, AC-24, AC-26 by construction.)*

- [ ] **Step 4: the Redis adapter.**
  - Create `RedisStreamsEventBus` and `EventBusUnreachable`, and add the `redis` row to the contract dataset (Ruling 9).
  - **`RedisStreamsEventBusUnreachableTest` (AC-4):**
    - a fake `Redis\Factory` whose `connection()` throws makes `publish` throw `EventBusUnreachable`. The message names the connection and `getPrevious()` is the original;
    - the **real** `RedisManager` configured at `127.0.0.1:1` with a 0.5 s timeout throws `EventBusUnreachable` on publish. This is deterministic whether the cause is a missing client class or a refused port;
    - **no fallback:** after either case, the container's `InMemoryEventBus` holds zero messages.
  - **Measure** that the `redis` row reports skipped with the D-1 reason. Record the skip delta.
  - *(AC-4; AC-3, AC-20, AC-25 and AC-30 stay NOT VERIFIED, and are coded.)*

- [ ] **Step 5: the writer.**
  - Create `OutboxWriter` and `TenantConnection`, and the `Outbox` binding.
  - **`OutboxWriterTest`,** on `AccessTenantFixture` tenant-a with a fixed clock:
    - **AC-7:** writing only `type`, `aggregate_type`, `aggregate_id` and `payload` gives a whole-row comparison with `status` `pending`, `attempts` 0, `published_at` null, `version` 1 and `occurred_at` equal to the clock.
    - **AC-8:** the actor JSON for each of `employee` and `system`, and the default `system`. **Snapshot:** a stored name is unchanged after the caller's source changes. The test uses a literal name and asserts the writer never queries `employees` (the query log).
    - **AC-9:**
      - `RequestId::fixed('01J…')` gives that id;
      - `RequestId::none()` gives null;
      - an explicit override beats both;
      - laziness: rebinding between two writes changes the answer.
    - **AC-10:** null unless given; given is stored.
    - **AC-11:** a well-formed `branch_id` naming no branch is stored.
    - **AC-12:** `version` 3 is stored.
    - **AC-13:** each refusal is named:
      - an empty `type`, `Sale.Done`, an 81-character type;
      - an empty `aggregate_type`;
      - a 25-character `aggregate_id`, a lower-case `aggregate_id`;
      - `version` 0;
      - a payload holding invalid UTF-8, a payload holding `NAN`.

      `outbox_events` is still empty after them. **Control for "before any write":** the same invalid event with **no tenant resolved** throws `OutboxEventRefused`, not `NoTenantResolved`.
  - **`OutboxTransactionTest` (AC-6):**
    - inside a tenant transaction, insert a `marker` row and record an event, then throw: zero outbox rows and the marker unchanged;
    - **control:** the committed twin leaves both rows.
  - **Host `OutboxCorrelationHttpTest` (AC-9, HTTP half),** in T-017's `AuditRequestIdHttpTest` shape:
    - a scratch `api` route inside the fixture tenant records two events: both carry the response's `X-Request-Id`;
    - a second request gives a different id;
    - an `Artisan::call` command that writes stores null.
  - *Done when:* green. *(AC-6 to AC-13.)*

- [ ] **Step 6: the relay.**
  - Add `RunnerClock::advanceSeconds()`, and create `Backoff`, `RelayPacing` and the `RelayOutbox` slice and command.
  - **`BackoffTest`:** `delay(1..6)` with base 30 and cap 600 is `30, 60, 120, 240, 480, 600`, and the arguments move it (no literals).
  - **`RelayOutboxTest`,** with tenant-a, a fixed clock and the `memory` bus:
    - **AC-14:** with `batch_size = 2` and 5 rows written out of `occurred_at` order, pass 1 publishes the 2 oldest by `occurred_at`. The query log shows `where "status" = ? … order by "occurred_at"` with `limit 2`, and **no `"id" >`** predicate.
    - **AC-15:**
      - published rows have `status` `published` and `published_at` equal to the clock, and the bus holds their envelopes;
      - a second pass republishes nothing (the bus count is unchanged);
      - envelope `tenant_id` is `tenant-a`, and `version` is passed through (AC-12).
    - **AC-16 and AC-17,** with `FailingEventBus` always failing:
      - pass 1 gives `attempts` 1 and the message in `last_error`;
      - an immediate pass 2 skips the row, so `attempts` is still 1;
      - `advanceSeconds(29)` still skips; `advanceSeconds(1)` gives `attempts` 2;
      - continuing, the 5th failure gives `status` `failed`, and a further pass does not select it;
      - the `error` log is written once.
    - **Recovery:** two failures, then the bus heals and the next due pass publishes the row.
    - **AC-19:** no pending rows gives a result of zeros and nothing changed.
    - **AC-4 through the relay:** `EventBusUnreachable` counts as an attempt, and the row stays `pending`.
  - **`RelayOutboxCommandTest`:**
    - `--once` with no tenant visits the fleet;
    - `--tenant=` limits the run;
    - a failing tenant is logged, the others are relayed, and the exit code is 1;
    - `Sleep::fake()` shows the loop sleeps `poll_seconds` between passes with `--max-passes=2`, and never with `--once`;
    - groups are ensured before the first publish.
  - *(AC-12, AC-14 to AC-19.)*

- [ ] **Step 7: consumers and dead letter.**
  - Create `ConsumerRegistry`, the `ConsumeEvents` slice and command, and the fixtures `FixtureEventConsumerProvider` and `PingedConsumer`. `PingedConsumer` has a configurable "throw N times" and a counter of effects applied.
  - **`ConsumerRegistryTest`:**
    - a fixture provider's consumer is seen with no memo;
    - a duplicate group refuses, a bad name refuses, and a non-consumer class refuses.
  - **`ConsumeEventsTest`:**
    - **Delivered and acknowledged:** `handle` runs inside tenant-a (it reads `marker`), and the pending list is empty afterwards (AC-22).
    - **A type it does not list:** acknowledged, and the handler is not called.
    - **AC-23 redelivery:** consumer `c1` handles the message, then the "crash before ack" fixture flag skips the ack. Time passes the claim timeout and `c2` reclaims and handles it. `fixture_pings` holds **one** row and effects applied is 1.
      - **Control:** the same flow with a fixture variant that does not check gives 2. The test can discriminate.
    - **AC-24 back-off:** a message that keeps throwing is reclaimed at 60, then 120, then 240 seconds of idle, and not before. Delivery counts 2, 3, 4 come from the bus.
    - **AC-27:**
      - at delivery 5 the throw moves the message to the dead stream, with the reason holding the exception class and message, and logs one `critical`. No tenant table gained a row: the tenant table list is unchanged and only `fixture_pings` is touched;
      - **the crash path:** a message never acknowledged and never throwing (the consumer process "dies") is dead-lettered on claim when its count exceeds 5, and its handler is not called a sixth time;
      - **a malformed envelope** goes to the dead letter at once.
    - **AC-28 distinctness:** in one test, one outbox row is `failed` (relay side) and one message is dead-lettered (consumer side). `eventbus:failed` lists only the first, and `eventbus:dead-letters` only the second.
  - **`ConsumeEventsCommandTest`:** `--group=` limits the run; `--once`; and `Sleep::fake()` as in step 6.
  - *(AC-21 to AC-24 and AC-27 end-to-end on the stand-in, and AC-28.)*

- [ ] **Step 8: operator commands.**
  - The 5 slices of Ruling 10.
  - **`ListFailedOutboxEvents`** (AC-29):
    - with mixed statuses, only `failed` rows are shown;
    - oldest first by default and newest first with the flag;
    - every listed column is present;
    - `--limit=1000` is clamped to 500;
    - an empty list is not an error.
  - **`RetryFailedOutboxEvent`** (AC-31):
    - a `failed` row becomes `pending` with `attempts` 0, and the next relay pass publishes it;
    - a `pending` id, a `published` id and an unknown id each refuse with exit 1, and nothing changes.
  - **`ListDeadLetters`** (AC-30, stand-in): fields and order.
  - **`ReplayDeadLetter`** (AC-31):
    - the replayed message is handled by the fixture group;
    - a **second fixture group** that already handled it applies nothing again, which is Ruling 10's note proved;
    - an unknown id gives exit 1.
  - **`ReportOutboxAge`** (AC-32):
    - with a row 90 seconds old by the fixed clock, the output is `90`;
    - with none pending, `none`;
    - with no option, the fleet.
  - *(AC-29 to AC-32.)*

- [ ] **Step 9: proof of pipeline.**
  - **`tests/Pipeline/ProofOfPipelineTest.php` (AC-34).** One test, on the stand-in:
    1. register `FixtureEventConsumerProvider`;
    2. in one tenant transaction, a `marker` update plus `Outbox::record(fixture.pinged)`;
    3. `Artisan::call('eventbus:relay', ['--once' => true, '--tenant' => ['tenant-a']])`, then the row is `published`;
    4. `Artisan::call('eventbus:consume', ['--group' => ['fixture.pinged'], '--once' => true])`, then one `fixture_pings` row carrying the event id, and the pending list is empty;
    5. a simulated redelivery of the same envelope leaves one row.
  - A header comment states that this is a fixture, not business scope (D-10, AC-35).
  - *(AC-34.)*

- [ ] **Step 10: close-out sweeps (`qa`-accountable files, written by `dev`).**
  - **`QaEventbusSourceRulesTest`.** It has its own helpers and calls nothing from `QaAuditSourceRulesTest`: those functions are namespaced in that file, and cross-file reliance is the FP-164 load-order defect.
    - **Scan set:** `packages/*/src`, `modules/*/src` and `modules/host/app`. It asserts every scanned directory exists. **Test trees are excluded on purpose:** `TenantBaselineBuildsTest` and `QaTenantBaselineStandInShapeTest` insert into `outbox_events` as T-008 schema fixtures.
    - **AC-5:** the only file inserting into `outbox_events` (chained `->insert*` after `table('outbox_events')`, or raw `insert into`) is `OutboxWriter.php`.
      - **Control:** a planted file in a temporary directory is reported, and a planted insert into a differently named table is not.
    - **AC-2:** outside `packages/eventbus/src`, no file contains:
      - `Facades\Redis`, `Redis::`, `Illuminate\Redis`, `Contracts\Redis`;
      - a caseless `\bX(ADD|READGROUP|ACK|CLAIM|AUTOCLAIM|PENDING|GROUP|RANGE|REVRANGE|DEL)\b`;
      - the configured stream or dead-letter stream name.

      `config/` is outside the scan set: configuration is where the name belongs. **Control:** a planted `Redis::command('XADD', …)` is reported.
    - **Ruling 1:** `PingedConsumer.php` imports nothing under `Flexipos\Eventbus\`.
  - **`QaEventbusNoHttpSurfaceTest` (AC-33):**
    - no registered route's URI or action matches `/outbox|eventbus|dead.?letter/i`;
    - **control:** a mounted scratch `GET api/v1/outbox` is reported.
  - **AC-37:** a recorded, re-run copy of step 1's demo negative control (deptrac red on the internal import, green on the contract import) goes in the `qa` report, not a committed test. Committing a deptrac probe test would join the `toolchain` group for one assertion.
  - *(AC-2, AC-5, AC-33, AC-37.)*

- [ ] **Step 11: docs.** Describe; do not quote retired wording.
  - **`docs/database.md` §6.21, the `outbox_events` row.** Add:
    - the actor JSON shape `{type,id,name}` over the `audit_logs` vocabulary;
    - that `failed` means relay-exhausted;
    - that consumer-side dead letters are a Redis stream, not a table (T-018 D-7).
  - **`docs/eventbus.md`.** Add an "As built (T-018)" section:
    - modules write through `Flexipos\Contracts\Events\Outbox` and never publish directly;
    - the transport port is internal;
    - the stream, group and dead-letter topology;
    - the seven commands.

    The illustrative `publish(DomainEvent)` snippet is marked superseded.
  - *(No AC; keeps the authoritative documents true.)*

- [ ] **Step 12: close-out, `qa`.**
  - **Mandatory mutations,** each shown red and then reverted:
    1. The relay query drops `where status='pending'`: AC-15's "a second pass republishes nothing" goes red.
    2. `order by id` replaces `occurred_at`: AC-14 goes red.
    3. The pacing check is removed: AC-17's "immediate pass 2 skips" goes red.
    4. `>=` becomes `>` on `max_attempts`: AC-16's fifth-failure case goes red.
    5. The writer validates after inserting: AC-13's "no tenant resolved" control goes red.
    6. The writer opens its own transaction (`DB::transaction` around the insert on a fresh connection name): AC-6 goes red.
    7. The consumer runner acks before `handle`: AC-23's crash-redelivery case goes red.
    8. The stand-in's `read()` delivers to every consumer: AC-21 goes red.
    9. `deadLetter()` skips the `XACK` half in the stand-in: AC-27's "acknowledged in the original" goes red.
    10. `EventBusDriver` falls back to `memory` when Redis fails: AC-4's no-fallback case goes red.
    11. `PingedConsumer` drops its check: the AC-23 end-state assertion goes red.
  - **Then:** section 9's edit-list diff check, and the final `composer check` with its process list and skip delta.
  - *(AC-36.)*

---

## 7. Test strategy

- **Unit, pure:** `ActorType`, `EventActor`, `NewEvent`, `EnvelopeCodec`, `Backoff`, `EventBusDriver`, `ConsumerRegistry`.
- **Contract dataset:** `EventBusContractTest` runs on the stand-in now, and on Redis when D-1 lifts.
- **Slice tests:** package tests on `AccessTenantFixture` with a fixed clock and `Sleep::fake()`. There is no real wait and no network.
- **Host:** `OutboxCorrelationHttpTest` and the two `Qa*` sweeps.
- **Cost:** about 100 cases. Most use the tenant fixture's file copy, not a migration. FP-328's headroom is already below 2x, so step 0 and step 12 both record the test-step seconds, and `dev` reports any step adding more than 15 s.

| Criteria | Proved in |
|---|---|
| AC-1 | steps 3 and 4 (`EventBusDriverTest`, the contract dataset) |
| AC-2 | step 10 (`QaEventbusSourceRulesTest`) |
| AC-3 | **NOT VERIFIED** (Redis, D-1); the stand-in half is step 3 |
| AC-4 | step 4; step 6's relay case |
| AC-5 | step 10 |
| AC-6 | step 5 (`OutboxTransactionTest`) |
| AC-7 to AC-13 | step 5 (`OutboxWriterTest`, `OutboxCorrelationHttpTest`) |
| AC-14 to AC-17, AC-19 | step 6 |
| AC-18 | a statement (D-4); the relay docblock; no test |
| AC-20 | **NOT VERIFIED** (Redis); the stand-in half is step 6 |
| AC-21, AC-22, AC-24 | steps 3 and 7 |
| AC-23 | step 7, with its discriminating control, and step 9 |
| AC-25 | **NOT VERIFIED** (Redis); the stand-in half is steps 3 and 7 |
| AC-26 | by construction: one stream, no sequencing code (Ruling 3) |
| AC-27, AC-28 | step 7 |
| AC-29, AC-31, AC-32 | step 8 |
| AC-30 | step 8 on the stand-in; the real read path is **NOT VERIFIED** (Redis) |
| AC-33 | step 10 |
| AC-34 | step 9 |
| AC-35 | a statement; step 10's sweeps show no other producer (`OutboxWriter` has no caller in `src`) |
| AC-36 | every step; step 12 |
| AC-37 | step 1's deptrac control and the `Module_Demo` narrowing; re-run in step 10 |

## 8. Risks and rollback

| # | Risk | Size | Mitigation |
|---|---|---|---|
| 1 | **The Redis adapter is unexercised** until D-1 lifts. Raw-reply parsing differs between phpredis and predis | medium, latent | One contract dataset (Ruling 9). The adapter is small and every call goes through `raw()`. Lifting D-1 must also add a client |
| 2 | **Gate headroom** (FP-328) | medium | Fixture file copies, not migrations; the step 0 and step 12 timings; a per-step 15 s flag |
| 3 | **A Redis outage longer than about 7.5 minutes** marks every pending row `failed`, needing `eventbus:retry` | medium, operational | Generous defaults (Ruling 6) and the retry command. A bus-wide circuit breaker is a follow-up if it is ever measured |
| 4 | **`MAXLEN ~` trimming** can drop a message that is still pending in a slow group | small at 100k | Configurable; documented in step 11. The stand-in never trims |
| 5 | **Nothing runs the relay or consumers in production** (no supervisor config, no Redis) | high, pre-existing class (FP-319) | Infrastructure is out of scope (D-1). -> lead: a follow-up row with the infrastructure task |
| 6 | **Relay pacing is in-process,** so a restart allows one early retry per row | small | `attempts` is durable; the docblock |
| 7 | **The gate sweeps the working tree** (memory) | small | Describe, do not quote; the vocabulary rule in section 6 |
| 8 | **`TenantConnection` is now three copies** | small, debt | -> lead: a follow-up row to promote it under the three-use rule |

**Rollback.** Everything is unpushed on the branch, and there is no schema change. Reverting the branch removes the package code, the contracts, the config and the provider line, and restores `Module_Demo`'s edge. No tenant data is involved.

## 9. Pre-existing files legitimately edited

Each edit is stated here so `qa` reports it as intended, not as a regression. **No assertion is weakened.**

| Step | File (origin) | Edit and cause |
|---|---|---|
| 1 | `modules/host/deptrac.yaml` (T-001…T-017) | path `packages/eventbus/tests`; the layer split; `Package_Eventbus` edges; `Package_Eventbus_Tests` ruleset; `Module_Demo` loses `Package_Eventbus` (section 5) |
| 1 | `modules/host/phpstan.neon.dist` | path `packages/eventbus/tests` |
| 1 | `modules/host/composer.json` | `autoload-dev` gains `Flexipos\Eventbus\Tests\` |
| 1 | `modules/host/bootstrap/providers.php` | `EventbusServiceProvider` |
| 1 | `modules/host/phpunit.xml` | `<env name="EVENTBUS_DRIVER" value="memory"/>`, with a comment citing Ruling 8 |
| 1 | `modules/host/.env.example` | `EVENTBUS_DRIVER=redis` and its one-line comment |
| 2 | `packages/access/src/Support/AuditWriter.php` (T-010/T-017) | `ACTOR_TYPE = ActorType::Employee->value`, plus a docblock line naming the shared vocabulary |
| 6 | `packages/kernel/src/Schema/Support/RunnerClock.php` (T-008) | `advanceSeconds(int)`, additive |
| 11 | `docs/database.md` §6.21, the `outbox_events` row | step 11 |
| 11 | `docs/eventbus.md` | step 11 |

**Count:** 10 pre-existing files. **No pre-existing test file is predicted to change.** `modules/host/composer.lock` is predicted unchanged; confirm in step 1. `packages/eventbus/src/.gitkeep` may stay, since the kernel keeps its own.

**Expected unchanged,** listed so that a red one is investigated, not patched:

| Files | Why each is unaffected |
|---|---|
| `ModuleMakeDemoRegenerationTest` | `modules/demo` is not touched (Ruling 1) |
| `QaToolchainRegistryCoverageTest` | `eventbus` is already in its package list; the namespaced tests tree is collected |
| `ModulesEnvParsingTest`, `QaManifestTrustAttackTest`, `QaModuleNameValidationTest` | they filter loaded providers by `Flexipos\Module\` |
| `DeptracBoundaryEnforcementTest` | the demo-to-host probe is unaffected by the narrowing |
| `TenantBaselineBuildsTest`, `QaTenantBaselineStandInShapeTest`, `MigrationTreeDisciplineTest`, `QaSchemaSignatureInstrumentTest`, `QaSecretSettingSinkTest`, `BranchForeignKeysMigrationTest` | no schema change; their direct `outbox_events` inserts are test fixtures outside the AC-5 scan |
| `SchemaCataloguePublishTest` | no migration |
| `AuditWriterTest`, `AuditTransactionTest`, every T-010/T-014 audit assertion | `employee` is unchanged |
| `VerticalSliceLayoutTest` | it covers the 7 new slices automatically and must pass on them |
| `QaAuditSourceRulesTest`, `QaPlatformSourceRulesTest`, `QaAuditNoHttpSurfaceTest` | unrelated to this task |
| `ConfigCacheGuardTest`, `QaVocabularyRenameSweepTest`, `QaHealthProbeResilienceTest` | they read `.env.example`; only additive lines are added |

## 10. Messages

**`architect` → lead.**

1. **Rulings for `ba`, all informational, none changing a criterion's text:**
   - Ruling 1: the fixture home, forced by `ModuleMakeDemoRegenerationTest`;
   - Ruling 2: the transport port stays internal;
   - Ruling 3: `tenant_id` rides in the envelope;
   - Ruling 4: the actor name is supplied by the caller;
   - Ruling 5: the type pattern and the identifier shape;
   - Ruling 10: retry keeps `last_error`, and replay is seen by every group.
2. **A spec citation correction,** which goes into no criterion. The five-value actor vocabulary is declared only in `docs/database.md` §6.21; `AuditWriter` writes `employee` alone, and `PlatformAudit` uses `user`/`system`. Ruling 4 makes the documented vocabulary a code enum.
3. **Follow-up rows proposed:**
   - promote `TenantConnection` to the kernel (risk 8);
   - run the relay and consumers in production, with the Redis infrastructure and client that also lift D-1 (risk 5);
   - optionally, correct `packages/eventbus/composer.json`'s "inbox dedupe" description.
4. **The skip count rises** by the number of contract cases (Ruling 9). This is expected and not a regression.

**`architect` → `dev`.**

- Validation strictly before the connection (step 5's control depends on it).
- The relay never opens a transaction across `publish()`.
- The consumer runner acks only after `handle()` returns.
- Stop on any red file that section 9 does not name.

## Questions for the human

None. Nothing is irreversible or external. No dependency is added and no schema changes. The one production-safety choice, refusing the in-memory bus outside local environments, takes the conservative side.
