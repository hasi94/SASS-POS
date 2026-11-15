<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ListFailedOutboxEvents;

use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan eventbus:failed` (T-018 Ruling 10, AC-29, D-9: console only).
 */
final class ListFailedOutboxEventsCommand extends Command
{
    protected $signature = 'eventbus:failed {--tenant=} {--newest} {--limit=}';

    protected $description = "List this tenant's failed outbox_events rows";

    public function handle(ListFailedOutboxEventsHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = (string) $this->option('tenant');

        if ($tenantId === '') {
            $this->components->error('The --tenant option is required.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');

        /** @var list<array<string, mixed>> $rows */
        $rows = $tenancy->run($tenantId, fn (): array => $handler->handle(new ListFailedOutboxEvents(
            newestFirst: (bool) $this->option('newest'),
            limit: $limitOption !== null ? (int) $limitOption : null,
        )));

        if ($rows === []) {
            $this->components->info('No failed outbox_events rows.');

            return self::SUCCESS;
        }

        $this->table(ListFailedOutboxEventsHandler::COLUMNS, $rows);

        return self::SUCCESS;
    }
}
