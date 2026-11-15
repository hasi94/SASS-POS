<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection every write and read this package makes goes through
 * (T-016 AC-32): the master connection, resolved by its configured name, the
 * same lookup `packages/auth` uses, never the literal `master`.
 */
final class MasterConnection
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function get(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));

        return $connection;
    }
}
