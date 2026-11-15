<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RetryFailedOutboxEvent;

/**
 * Move a chosen `failed` outbox_events row back to `pending`, attempts
 * reset to 0 (T-018 Ruling 10, AC-31) - a deliberate operator action, never
 * automatic.
 */
final readonly class RetryFailedOutboxEvent
{
    public function __construct(public string $id) {}
}
