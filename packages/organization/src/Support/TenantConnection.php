<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The connection this package's handlers write through.
 *
 * **It resolves the stanza by its configured name, never by the literal
 * `tenant`**: `config('tenancy.connection')` is the one authority for that
 * name. It builds nothing - `Tenancy::enter()` configures the stanza and is
 * the only thing that may (T-009 AC-52) - so a handler reached before a
 * tenant is entered fails on the placeholder stanza instead of writing
 * somewhere.
 *
 * **A third home of one four-line method**, beside access's and the
 * kernel's own lookup. Package boundaries forbid importing either; the
 * follow-up row (plan section 10, item 3b) is to promote it to the kernel.
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
