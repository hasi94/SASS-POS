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
        {--queue : Dispatch one queue job per tenant instead of migrating in this process}
        {--per-server= : Override how many tenants on one server may run at once}
        {--worker= : The worker identifier recorded on each claim}';

    protected $description = 'Apply the tenant migration tree to the tenant databases that are behind it';

    public function handle(RunTenantMigrationsHandler $handler, ConfigRepository $config): int
    {
        /** @var list<string> $tenants */
        $tenants = array_values(array_filter((array) $this->option('tenant')));

        // A resumption names its work by naming the run, and it plans
        // nothing, so the accident this refusal exists for - a bulk run
        // started because an argument was forgotten - cannot happen with
        // --run. Without this clause the instruction step 13f prints,
        // `tenant:migrate --run=<id>`, would be refused by the very command
        // that printed it; so would dispatch()'s own closing line.
        if ($tenants === [] && ! $this->option('all') && $this->option('run') === null) {
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
            if ($this->option('queue')) {
                return $this->dispatchToWorkers($handler, $command, $config);
            }

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

        if ($result->outstanding > 0) {
            // The run is not over, so it is not reported as having ended
            // (plan section 19.2). The operator is told how many jobs are
            // still out and the one invocation that writes the final
            // counters once they are terminal.
            $this->components->warn(sprintf(
                'Run %s is still running: %d job(s) outstanding, so no final status was written.'
                .' Run tenant:migrate --run=%s again once they are done.',
                $result->runId,
                $result->outstanding,
                $result->runId
            ));

            return $result->exitCode;
        }

        $this->components->info(
            'Run '.$result->runId.' ended '.$result->status.' at target version '.$result->targetVersion.'.'
        );

        return $result->exitCode;
    }

    /**
     * `--queue`: plan the run and hand its jobs to the workers (AC-41).
     *
     * This is the one place the queue name is read, from the host's
     * `config('schema.queue')`, and handed to the kernel - the same rule the
     * tree directory follows.
     *
     * The exit code is 0 for a dispatched run and says nothing about whether
     * the migrations succeed: nothing has been migrated yet when this returns.
     * The closing line names the invocation that writes the run's final
     * counters once the queue has drained, because a queue worker cannot know
     * it ran the last job (see the handler's dispatch()).
     */
    private function dispatchToWorkers(
        RunTenantMigrationsHandler $handler,
        RunTenantMigrations $command,
        ConfigRepository $config,
    ): int {
        $dispatched = $handler->dispatch($command, (string) $config->get('schema.queue', 'migrations'));

        $this->components->info(sprintf(
            '%d job(s) dispatched on the [%s] queue at target version %d.',
            count($dispatched->jobIds),
            $dispatched->queue,
            $dispatched->targetVersion
        ));

        $this->components->info(sprintf(
            'Nothing has been migrated yet. Run tenant:migrate --run=%s once the queue has drained.',
            $dispatched->runId
        ));

        return self::SUCCESS;
    }
}
