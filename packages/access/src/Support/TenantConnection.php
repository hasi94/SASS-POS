<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection this package's handlers write through.
 *
 * **It resolves the stanza by its configured name, never by the literal
 * `tenant`.** `config('tenancy.connection')` is the one authority for that
 * name and a handler writing the literal would be a second one - the shape
 * FP-184 collects. Two dozen slices are about to be written; a copy of this
 * four-line method in each of them is two dozen places to edit the day the
 * stanza is renamed, and twenty-three places for a typo to hide.
 *
 * **It builds nothing.** `Tenancy::enter()` configures the stanza and is the
 * only thing that may (T-009 AC-52); this class asks the manager for a
 * connection that is already configured. A handler reached before a tenant
 * is entered therefore fails on the placeholder stanza rather than quietly
 * writing somewhere.
 *
 * `SyncPermissionRegistryHandler` (step 6) carries its own private copy of
 * this lookup and is deliberately left alone: it is committed and green, and
 * rewriting it here would be a drive-by change to another step's file. It
 * reads the same configuration key, so the two cannot disagree about the
 * name - only about where the code lives.
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
