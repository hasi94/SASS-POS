<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ListWorkflowDefinitions;

use Flexipos\Workflow\Support\TenantConnection;

/**
 * `workflow:definitions` (T-020 AC-23). Every non-deleted row, ordered by
 * (`entity_type`, `priority`, `key`).
 */
final class ListWorkflowDefinitionsHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<object>
     */
    public function handle(ListWorkflowDefinitions $query): array
    {
        return $this->tenant->get()->table('workflow_definitions')
            ->whereNull('deleted_at')
            ->orderBy('entity_type')
            ->orderBy('priority')
            ->orderBy('key')
            ->get(['key', 'name', 'entity_type', 'is_active', 'priority', 'version', 'id'])
            ->map(function (object $row): object {
                $row->steps = $this->tenant->get()->table('workflow_steps')->where('definition_id', $row->id)->count();

                return $row;
            })
            ->all();
    }
}
