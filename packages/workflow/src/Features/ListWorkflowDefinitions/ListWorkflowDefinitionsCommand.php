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

        foreach ($rows as $row) {
            $status = $row->is_active ? 'active' : 'inactive';

            $this->components->info(
                "{$row->key} ({$row->entity_type}, priority {$row->priority}, v{$row->version}): {$status}, {$row->steps} step(s)"
            );
        }

        return self::SUCCESS;
    }
}
