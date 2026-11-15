<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ListFailedOutboxEvents;

/**
 * List the current tenant's `status='failed'` outbox_events rows (T-018
 * Ruling 10, AC-29).
 */
final readonly class ListFailedOutboxEvents
{
    public function __construct(
        public bool $newestFirst = false,
        public ?int $limit = null,
    ) {}
}
