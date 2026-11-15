<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ListFailedOutboxEvents;

use Flexipos\Eventbus\Support\TenantConnection;
use Flexipos\Kernel\Audit\PageSize;

/**
 * AC-29: `status='failed'` rows for the current tenant, oldest or newest
 * first, with enough of each row for an operator to decide what to do next.
 */
final class ListFailedOutboxEventsHandler
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    /**
     * @var list<string>
     */
    public const COLUMNS = ['id', 'type', 'aggregate_type', 'aggregate_id', 'attempts', 'last_error', 'occurred_at'];

    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListFailedOutboxEvents $command): array
    {
        $limit = PageSize::clamp($command->limit, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        $rows = $this->tenant->get()->table('outbox_events')
            ->where('status', 'failed')
            ->orderBy('occurred_at', $command->newestFirst ? 'desc' : 'asc')
            ->limit($limit)
            ->get(self::COLUMNS);

        return $rows->map(static fn (object $row): array => (array) $row)->all();
    }
}
