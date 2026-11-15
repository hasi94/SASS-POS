<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

/**
 * One request to write an event onto the caller's own transaction's outbox
 * (T-018 Ruling 2, AC-5 to AC-13).
 *
 * Carries exactly the business columns a caller may set; `occurred_at`,
 * `status`, `attempts` and `published_at` are the writer's and the schema's
 * to decide (AC-7). `correlationId` is an explicit override - when null the
 * writer falls back to the current request's id (AC-9); `causationId` is
 * never invented (AC-10).
 */
final readonly class NewEvent
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public string $type,
        public string $aggregateType,
        public string $aggregateId,
        public ?array $payload = null,
        public int $version = 1,
        public ?string $branchId = null,
        public ?string $causationId = null,
        public ?string $correlationId = null,
        public ?EventActor $actor = null,
    ) {}
}
