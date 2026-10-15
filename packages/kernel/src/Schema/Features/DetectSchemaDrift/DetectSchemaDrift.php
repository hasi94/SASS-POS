<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\DetectSchemaDrift;

use Flexipos\Kernel\Schema\Support\MigrationSet;

/**
 * Ask whether each tenant's mapping still agrees with its own database
 * (AC-55).
 *
 * A **query**: it opens tenant connections to read their `migrations` tables
 * and writes nothing at all. Correcting what it finds is
 * `RepairSchemaVersion`'s, and keeping the two apart is what lets the report
 * be run on a platform nobody wants changed.
 *
 * The set is carried because a version is a position in a set; comparing a
 * mapping's number against a tenant's migration names needs the set that
 * numbers them.
 */
final readonly class DetectSchemaDrift
{
    /**
     * @param  list<string>|null  $tenantIds  null for every mapped tenant
     */
    public function __construct(
        public MigrationSet $set,
        public ?array $tenantIds = null,
    ) {}
}
