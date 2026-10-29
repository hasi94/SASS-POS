<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The master connection, resolved by its configured name rather than the
 * literal `master` (T-019 plan section 2, -> lead FP-331 family: a fifth
 * copy of the small helper `packages/platform`, `packages/access` and
 * `packages/eventbus` each already have one of).
 *
 * The **tenant** connection is never resolved here: enabling, migrating
 * and uninstalling enter it explicitly through
 * `Flexipos\Kernel\Tenancy\Tenancy::run()`, under the migrate credential
 * (plan Ruling 6) - a purpose this class deliberately never names.
 */
final class Connections
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function master(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));

        return $connection;
    }

    public function tenantConnectionName(): string
    {
        return (string) $this->config->get('tenancy.connection', 'tenant');
    }
}
