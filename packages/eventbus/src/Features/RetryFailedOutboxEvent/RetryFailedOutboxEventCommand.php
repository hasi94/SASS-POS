<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RetryFailedOutboxEvent;

use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan eventbus:retry {id}` (T-018 Ruling 10, AC-31).
 *
 * `last_error` is deliberately kept on the row, as the evidence, until the
 * next failure overwrites it.
 */
final class RetryFailedOutboxEventCommand extends Command
{
    protected $signature = 'eventbus:retry {id} {--tenant=}';

    protected $description = 'Move a failed outbox_events row back to pending with attempts reset to 0';

    public function handle(RetryFailedOutboxEventHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = (string) $this->option('tenant');

        if ($tenantId === '') {
            $this->components->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $id = (string) $this->argument('id');

        $retried = $tenancy->run($tenantId, fn (): bool => $handler->handle(new RetryFailedOutboxEvent($id)));

        if (! $retried) {
            $this->components->error("No failed outbox_events row [{$id}] was found.");

            return self::FAILURE;
        }

        $this->components->info("Outbox row [{$id}] is pending again.");

        return self::SUCCESS;
    }
}
