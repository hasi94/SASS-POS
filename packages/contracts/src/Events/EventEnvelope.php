<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

/**
 * What a consumer receives for one event (T-018 Ruling 2 and 3).
 *
 * Built by the relay from an `outbox_events` row, stamped with the
 * **resolved** `Flexipos\Kernel\Tenancy\TenantContext`'s tenant id - never
 * from a caller - because one event stream serves every shop and a consumer
 * cannot know which tenant database to act in unless the message says so.
 *
 * `id` is `outbox_events.id`, carried through unchanged: a consumer is
 * idempotent by this identifier (AC-23), never by relying on the transport
 * for exactly-once delivery.
 */
final readonly class EventEnvelope
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $type,
        public int $version,
        public string $aggregateType,
        public string $aggregateId,
        public ?string $branchId,
        public ?array $payload,
        public string $occurredAt,
        public ?string $correlationId,
        public ?string $causationId,
        public EventActor $actor,
    ) {}
}
