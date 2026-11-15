<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RepairSchemaVersion;

use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * Correct a mapping that disagrees with the database it points at (AC-54).
 *
 * The condition is a crash between two writes: the tenant's own `migrations`
 * table shows the target applied and `tenant_databases.schema_version` still
 * shows the older number. The repair is a mirror write and **nothing else** -
 * no migration is applied, which is the half of the criterion a repair
 * indistinguishable from an ordinary run would fail.
 */
final readonly class RepairSchemaVersion
{
    /**
     * @param  list<string>|null  $tenantIds  null for every mapped tenant
     */
    public function __construct(
        public MigrationSet $set,
        public ?array $tenantIds = null,
    ) {}
}
