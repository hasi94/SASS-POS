<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\ListWorkflowDefinitions;

use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan workflow:definitions {--tenant=}` (T-020 AC-23).
 */
final class ListWorkflowDefinitionsCommand extends Command
{
    protected $signature = 'workflow:definitions {--tenant= : The tenant id}';

    protected $description = 'List workflow definitions for a tenant';

    public function handle(ListWorkflowDefinitionsHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId === null) {
            $this->components->error('--tenant is required.');

            return self::FAILURE;
        }

        $rows = $tenancy->run((string) $tenantId, fn () => $handler->handle(new ListWorkflowDefinitions));

        $this->table(
            ['Key', 'Name', 'Entity type', 'Active', 'Priority', 'Version', 'Steps'],
            array_map(static fn (object $row): array => [
                $row->key, $row->name, $row->entity_type, $row->is_active ? 'yes' : 'no', $row->priority, $row->version, $row->steps,
            ], $rows)
        );

        return self::SUCCESS;
    }
}
