<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ShowWorkflowHistory;

use Flexipos\Workflow\Engine\InstanceReader;
use Flexipos\Workflow\Support\TenantConnection;

/**
 * `workflow:history` (T-020 Ruling 12): the header, then every event in
 * `id` order.
 */
final class ShowWorkflowHistoryHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly InstanceReader $reader,
    ) {}

    public function handle(ShowWorkflowHistory $query): ShowWorkflowHistoryResult
    {
        $state = $this->reader->read($query->instanceId);

        $events = $this->tenant->get()->table('workflow_events')
            ->where('instance_id', $query->instanceId)
            ->orderBy('id')
            ->get()
            ->all();

        return new ShowWorkflowHistoryResult($state, $events);
    }
}
