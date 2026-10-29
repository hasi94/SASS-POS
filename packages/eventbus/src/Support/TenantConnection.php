<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection this package's writer and handlers read and write through.
 *
 * **The same four lines as `Flexipos\Access\Support\TenantConnection` and
 * `Flexipos\Organization\Support\TenantConnection` (T-018 Ruling 5).** It
 * resolves the stanza by its configured name, `config('tenancy.connection')`,
 * never by the literal `tenant`; it builds nothing, and
 * `Flexipos\Kernel\Tenancy\Tenancy::enter()` is the only thing that may.
 *
 * **This is now a third copy, and that is accepted debt, not an oversight**
 * (risk 8): promoting it to the kernel under the three-use rule would
 * refactor two already-reviewed packages, which this task's economy-of-change
 * scope does not do. Flagged to the lead as a follow-up row.
 */
final class TenantConnection
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function get(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.connection', 'tenant'));

        return $connection;
    }
}
