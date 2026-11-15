<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * The master database, probed through its own short-lived connection, never
 * `database.default` and never the live `master` connection itself (T-021
 * Ruling 7, AC-42, AC-43).
 *
 * **Built directly through `ConnectionFactory::make()`, never through
 * `config('database.connections.*')`.** `QaTenantIsolationProofTest`
 * (T-003 AC-15) enforces exactly one production writer of that key -
 * `Flexipos\Kernel\Tenancy\Tenancy` - so a config-array clone the plan
 * first described would be a second one. Building the `Connection` object
 * directly, from an array that is never registered anywhere, reaches the
 * same bound, disposable probe without writing the guarded key at all.
 */
final class MasterDatabaseCheck implements ReadinessCheck
{
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly ConfigRepository $config,
    ) {}

    public function name(): string
    {
        return 'master_database';
    }

    public function check(): CheckStatus
    {
        $timeout = (int) $this->config->get('api.health.timeout_seconds', 2);

        /** @var array<string, mixed> $stanza */
        $stanza = (array) $this->config->get('database.connections.master', []);

        $driver = $stanza['driver'] ?? null;

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $stanza['options'] = (array) ($stanza['options'] ?? []) + [PDO::ATTR_TIMEOUT => $timeout];
        }

        $connection = null;

        try {
            /** @var Connection $connection */
            $connection = $this->factory->make($stanza, 'health_probe_master');
            $connection->select('select 1');

            return CheckStatus::Ok;
        } catch (Throwable $exception) {
            Log::warning('Readiness: master database check failed.', [
                'exception' => $exception::class,
            ]);

            return CheckStatus::Failed;
        } finally {
            $connection?->disconnect();
        }
    }
}
