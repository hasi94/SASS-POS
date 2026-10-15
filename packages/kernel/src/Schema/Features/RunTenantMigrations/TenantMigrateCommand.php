<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RunTenantMigrations;

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Exceptions\RunnerLoopRefused;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * `php artisan tenant:migrate` - the runner's entry point.
 *
 * Like `schema:publish`, this is the one place the shipped tree's location is
 * read: `config('schema.tenant_migration_path')` is the authority and the set
 * is built here, so nothing in the kernel repeats the directory name.
 *
 * **`--all` is required for a bulk run and that is not ceremony.** A bulk run
 * touches every eligible tenant on the platform, and an invocation that did
 * that because an argument was forgotten is the kind of accident this project
 * has already paid for once. Naming neither `--tenant` nor `--all` refuses.
 *
 * The exit code is the run's, not this command's (AC-44): a run that ends
 * `completed_with_failures` exits non-zero and one where every tenant
 * succeeded exits 0. A **refusal** - the catalogue, the destructive guard, the
 * contract guard, a status - also exits non-zero, with the refusal's own
 * message, because the operator has to read it.
 */
final class TenantMigrateCommand extends Command
{
    protected $signature = 'tenant:migrate
        {--tenant=* : Migrate only these tenant ids}
        {--all : Migrate every eligible tenant}
        {--to= : Stop at this catalogue version instead of the highest}
        {--run= : Resume this run instead of planning a new one}
        {--retry-failed : With --run, make that run\'s failed jobs claimable again}
        {--concurrency=1 : Recorded on the run row; one process still executes serially}
        {--per-server= : Override how many tenants on one server may run at once}
        {--worker= : The worker identifier recorded on each claim}';

    protected $description = 'Apply the tenant migration tree to the tenant databases that are behind it';

    public function handle(RunTenantMigrationsHandler $handler, ConfigRepository $config): int
    {
        /** @var list<string> $tenants */
        $tenants = array_values(array_filter((array) $this->option('tenant')));

        if ($tenants === [] && ! $this->option('all')) {
            $this->components->error(
                'Name the tenants with --tenant, or ask for every eligible tenant with --all.'
                .' Nothing was planned.'
            );

            return self::FAILURE;
        }

        if ($this->option('per-server') !== null) {
            $config->set('schema.per_server_limit', (int) $this->option('per-server'));
        }

        $to = $this->option('to');

        $command = new RunTenantMigrations(
            set: MigrationSet::baseline($this->laravel->basePath((string) $config->get('schema.tenant_migration_path'))),
            tenantIds: $tenants === [] ? null : $tenants,
            targetVersion: $to === null ? null : (int) $to,
            runId: $this->option('run') === null ? null : (string) $this->option('run'),
            retryFailed: (bool) $this->option('retry-failed'),
            workerId: (string) ($this->option('worker') ?? 'cli-'.gethostname()),
            concurrency: max(1, (int) $this->option('concurrency')),
        );

        try {
            $result = $handler->handle($command);
        } catch (MigrationRunRefused|RunnerLoopRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        foreach ($result->jobs as $job) {
            $this->components->twoColumnDetail($job->tenantId, match (true) {
                $job->succeeded => 'done at version '.$job->schemaVersion,
                // AC-49: a locked tenant is reported as not run, which is
                // neither a success nor a failure, and the run carries on.
                $job->locked => 'not run: '.$job->errorMessage,
                default => 'failed: '.$job->errorMessage,
            });
        }

        $this->components->info(
            'Run '.$result->runId.' ended '.$result->status.' at target version '.$result->targetVersion.'.'
        );

        return $result->exitCode;
    }
}
