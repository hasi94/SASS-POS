<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RetryFailedOutboxEvent;

use Flexipos\Eventbus\Support\TenantConnection;

/**
 * AC-31: a `pending`/`published` id or an unknown id refuses - zero rows
 * affected, reported to the caller rather than thrown, so the command can
 * decide the exit code.
 */
final class RetryFailedOutboxEventHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(RetryFailedOutboxEvent $command): bool
    {
        $affected = $this->tenant->get()->table('outbox_events')
            ->where('id', $command->id)
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0]);

        return $affected > 0;
    }
}
