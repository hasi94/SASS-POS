<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ReportSchemaStatus;

/**
 * Ask where the fleet is against a version (AC-57).
 *
 * A **query**, and a master-only one: everything it reports is in
 * `tenant_databases` and `database_servers`, so it opens no tenant connection
 * at all. That is why it is the one part of step 12 that is fully provable
 * while the engine is on hold.
 *
 * `requiredVersion` is passed in rather than read here, because the floor is
 * `tenancy.required_schema_version` and that key has exactly one reader
 * (step 7a, ruling 13.1). The command that runs this is where it is read.
 */
final readonly class ReportSchemaStatus
{
    public function __construct(
        public int $requiredVersion,
    ) {}
}
