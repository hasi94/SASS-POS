<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ReportSchemaStatus;

use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDrift;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDriftHandler;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\SchemaDrift;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * `php artisan tenant:schema-status` - the behind report (AC-57) and, on
 * request, the drift report (AC-55).
 *
 * It also reports the runs that are `running`, and whether each one is
 * waiting on work or waiting on somebody to write its final counters (plan
 * section 19.2, step 13f) - two states that read the same in the run table
 * and call for opposite responses.
 *
 * **The behind report is the default and the drift report is a flag**, which
 * is not a preference: the behind report is two master queries and the drift
 * report opens a connection to every tenant database on the platform. An
 * operator asking "where is the fleet" should not have to pay for that, and
 * a status command that connected everywhere by default would be the command
 * nobody dares run during business hours.
 *
 * It reads `tenancy.required_schema_version`, which is that key's one
 * reader's sibling: an **absent** floor is a misconfiguration here too, and
 * the command says so rather than reporting every tenant as being behind
 * version zero.
 */
final class SchemaStatusCommand extends Command
{
    protected $signature = 'tenant:schema-status
        {--drift : Also open every tenant database and compare it with its mapping}
        {--tenant=* : With --drift, only these tenants}';

    protected $description = 'Report how far behind the tenant databases are, and optionally whether their mappings still match them';

    public function handle(
        ReportSchemaStatusHandler $handler,
        DetectSchemaDriftHandler $drift,
        ConfigRepository $config,
    ): int {
        $configured = $config->get('tenancy.required_schema_version');

        if (! is_numeric($configured)) {
            $this->components->error(
                'APP_REQUIRED_SCHEMA_VERSION is not set, so there is no floor to report against.'
                .' An explicit 0 is the documented "no floor" setting; absent is a misconfiguration.'
            );

            return self::FAILURE;
        }

        $report = $handler->handle(new ReportSchemaStatus((int) $configured));

        $this->components->info('Required schema version: '.$report->requiredVersion);

        foreach ($report->byVersion as $version => $count) {
            $this->components->twoColumnDetail('version '.$version, $count.' tenants');
        }

        foreach ($report->byServer as $server => $versions) {
            foreach ($versions as $version => $count) {
                $this->components->twoColumnDetail($server.' at version '.$version, (string) $count);
            }
        }

        foreach ($report->behind as $row) {
            $this->components->twoColumnDetail($row['tenant_id'], 'behind at version '.$row['schema_version']);
        }

        // The runs section (plan section 19.2). `running` is the one run
        // status that does not say what is happening, so each one is
        // reported as either work in flight or a run waiting for its final
        // counters - and the second of those names the invocation that
        // writes them, because that is the operator's next action.
        foreach ($report->runs as $run) {
            $this->components->twoColumnDetail(
                'run '.$run['run_id'],
                $run['outstanding'] > 0
                    ? 'running, '.$run['outstanding'].' job(s) outstanding'
                    : 'running, every job terminal - awaiting tenant:migrate --run='.$run['run_id']
            );
        }

        if ($report->oldestLaggard === null) {
            $this->components->info('No tenant is below the required version.');
        } else {
            $this->components->warn('Oldest laggard: '.$report->oldestLaggard);
        }

        if (! $this->option('drift')) {
            return self::SUCCESS;
        }

        /** @var list<string> $tenants */
        $tenants = array_values(array_filter((array) $this->option('tenant')));

        $set = MigrationSet::baseline(
            $this->laravel->basePath((string) $config->get('schema.tenant_migration_path'))
        );

        $drifted = 0;

        foreach ($drift->handle(new DetectSchemaDrift($set, $tenants === [] ? null : $tenants)) as $answer) {
            if ($answer->direction === SchemaDrift::ALIGNED) {
                continue;
            }

            $drifted++;

            $this->components->twoColumnDetail(
                $answer->tenantId,
                $answer->direction.': mapping '.$answer->mappingVersion.', database '.$answer->appliedVersion
            );
        }

        // Non-zero when something drifted, so this is usable from a cron
        // without parsing the output.
        return $drifted === 0 ? self::SUCCESS : self::FAILURE;
    }
}
