<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection the idempotency store writes through (T-021 Ruling 6,
 * D-7).
 *
 * The same four lines every package's own `Support/TenantConnection` holds
 * (`docs/patterns.md` §3): `config('tenancy.connection')` is the one
 * authority for the stanza's name, never the literal `tenant`. A copy
 * lives here, rather than a dependency on `packages/access`'s own class,
 * because the kernel may not depend on a package that depends on it.
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
