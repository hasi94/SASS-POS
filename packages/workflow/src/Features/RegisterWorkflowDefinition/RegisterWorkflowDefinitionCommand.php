<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Features\RegisterWorkflowDefinition;

use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Workflow\Exceptions\WorkflowDefinitionRefused;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan workflow:define {file} {--tenant=} {--as=}` (T-020 Ruling
 * 10, Ruling 12).
 */
final class RegisterWorkflowDefinitionCommand extends Command
{
    protected $signature = 'workflow:define {file : Path to the JSON definition file}
        {--tenant= : The tenant id}
        {--as= : The registering employee id}';

    protected $description = 'Register one workflow definition';

    public function handle(RegisterWorkflowDefinitionHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId === null) {
            $this->components->error('--tenant is required.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        if (! File::exists($path)) {
            $this->components->error("No such file [{$path}].");

            return self::FAILURE;
        }

        $json = (string) File::get($path);

        try {
            $result = $tenancy->run((string) $tenantId, fn () => $handler->handle(new RegisterWorkflowDefinition(
                json: $json,
                registeredBy: $this->option('as') === null ? null : (string) $this->option('as'),
            )));
        } catch (WorkflowDefinitionRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Registered [{$result->key}] ({$result->id}), {$result->stepCount} step(s).");

        return self::SUCCESS;
    }
}
