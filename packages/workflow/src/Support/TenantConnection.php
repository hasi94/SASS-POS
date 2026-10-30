<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection this package's writers and handlers read and write
 * through (T-020 Ruling 13.6, the FP-331 family).
 *
 * The same four lines as `Flexipos\Access\Support\TenantConnection` and its
 * siblings in organization, eventbus and plugin-sdk: it resolves the
 * stanza by its configured name, never by the literal `tenant`, and it
 * builds nothing - `Flexipos\Kernel\Tenancy\Tenancy::enter()` is the only
 * thing that may.
 *
 * **This is now a sixth copy, and that is accepted debt, not an
 * oversight** (plan Ruling 13.6): promoting it to the kernel under the
 * three-use rule would refactor five already-reviewed packages, which this
 * task's economy-of-change scope does not do. Flagged to the lead as a
 * follow-up row.
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
