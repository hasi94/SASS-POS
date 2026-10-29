# Event Bus Architecture Decision

## Decision

Use Redis Streams as the initial Event Bus.

Redis Streams will be used for Phase 0 because Redis is already part of the platform infrastructure, reducing operational overhead and VPS resource consumption.

The Event Bus will be implemented behind an application-level abstraction so RabbitMQ can be introduced later without changing business logic.

## Redis Streams vs RabbitMQ

| Requirement                   | Redis Streams     | RabbitMQ                      |
| ----------------------------- | ----------------- | ----------------------------- |
| VPS resource usage            | Lower             | Higher                        |
| Laravel integration           | Excellent         | Excellent                     |
| Event-driven architecture     | Yes               | Yes                           |
| Consumer groups               | Yes               | Yes                           |
| Retry handling                | Application-level | Strong built-in patterns      |
| Dead-letter queues            | Application-level | Native                        |
| Operational complexity        | Lower             | Higher                        |
| Additional container          | Not necessarily   | Usually                       |
| Suitable for initial SaaS POS | Yes               | Yes, but potentially overkill |

## Why Redis Streams

The POS platform already requires Redis for:

- Caching
- Queue processing
- Distributed locks
- Session/state management
- Event streams

Using Redis Streams avoids introducing another infrastructure service solely for messaging.

## Architecture

Angular POS
|
v
Laravel API
|
+-- MySQL
|
+-- Redis
| +-- Cache
| +-- Queue
| +-- Locks
| +-- Event Streams
|
+-- Events
|
+-- OrderCreated
+-- PaymentCompleted
+-- StockUpdated
+-- InvoiceCreated
+-- PrinterJobCreated
|
v
.NET Print Agent

## Event Bus Abstraction

Business logic must NOT depend directly on Redis Streams.

Create an application-level Event Bus interface.

The snippet below is illustrative only and is **superseded by "As built
(T-018)"**: no module code ever calls a method named `publish`, and the
interface it named is not published.

```php
interface EventBus
{
    public function publish(DomainEvent $event): void;
}
```

## As built (T-018)

- **Modules write through `Flexipos\Contracts\Events\Outbox` and never
  publish directly.** `Outbox::record(NewEvent $event): string` validates
  before touching any connection and inserts on the caller's own tenant
  connection inside the caller's own transaction - never via a method
  named `publish`.
- **The transport port is internal.** `Flexipos\Eventbus\Contracts\EventBus`
  - the Redis Streams adapter, the deterministic in-memory stand-in, and
  the writer, relay and consumer runner built on it - lives entirely inside
  `packages/eventbus`. Publishing it in `packages/contracts` would hand
  every module a way around the outbox the boundary check could never
  flag; a module (and the one proof-of-pipeline consumer) may depend only
  on `Flexipos\Contracts\Events\*`.
- **Topology.** One event stream (`eventbus.stream`, default
  `flexipos:events`) shared by every tenant, and one dead-letter stream
  (`eventbus.dead_letter_stream`, default `flexipos:events:dead`). Each
  declared consumer is its own Redis Streams consumer group, so every
  group sees every event; a type a group does not declare is acknowledged
  as a no-op. Ordering only ever means append order within one stream - no
  per-aggregate or cross-stream ordering is built. The envelope carries the
  resolved tenant id, stamped by the relay, never by a caller.
- **The relay** (`eventbus:relay`, a long-running worker, not a scheduled
  job) claims `outbox_events` rows where `status='pending'`, ordered by
  `occurred_at`, in bounded batches - never a cursor on `id`. On success it
  marks the row `published`; on failure it counts attempts and backs off
  exponentially in process memory, marking the row `failed` after 5
  attempts.
- **The consumer runner** (`eventbus:consume`, also long-running) reclaims
  unacknowledged messages using the stream's own native idle time and
  delivery count - never a second, application-level dedup table - with
  the same exponential back-off. A consumer is idempotent by the event's
  own identifier, never by relying on the transport for exactly-once
  delivery (it is at-least-once). After 5 deliveries a message moves to
  the dead-letter stream with a `critical` log line.
- **Seven operator console commands, no HTTP route anywhere in this
  mechanism:** `eventbus:relay`, `eventbus:consume`, `eventbus:failed`,
  `eventbus:retry`, `eventbus:dead-letters`, `eventbus:replay`,
  `eventbus:outbox-age`.
- **Driver selection is configuration, never module code**
  (`eventbus.driver`: `redis`, the shipped default, or `memory`, refused
  outside a local environment). Any Redis failure becomes a named
  `EventBusUnreachable` - no silent fallback to the stand-in, no silently
  dropped event.
