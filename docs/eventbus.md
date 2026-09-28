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

```php
interface EventBus
{
    public function publish(DomainEvent $event): void;
}
```
