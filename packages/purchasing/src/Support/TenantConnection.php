<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection this package's handlers write through (the tenth copy of this
 * helper, FP-331).
 *
 * It resolves the stanza by its configured name, never by the literal
 * `tenant`: `config('tenancy.connection')` is the one authority for that name.
 * It builds nothing - `Tenancy::enter()` configures the stanza - so a handler
 * reached before a tenant is entered fails on the placeholder stanza instead of
 * writing somewhere.
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
