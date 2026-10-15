<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ReportSchemaStatus;

/**
 * Where the fleet is, against one version (AC-57).
 *
 * Four readings, and each is a separate field because each answers a
 * different operational question: how many shops are at each version, how
 * that splits per server (which is the unit a migration window is planned
 * in), which shops are below the floor, and which single shop is furthest
 * behind.
 *
 * `oldestLaggard` is null when nothing is behind. Not zero and not an empty
 * string: "nobody is behind" and "the oldest laggard is the tenant with the
 * empty id" are different facts.
 */
final readonly class SchemaStatusReport
{
    /**
     * @param  array<int, int>  $byVersion  schema version => how many counted tenants carry it
     * @param  array<string, array<int, int>>  $byServer  server id => version => count
     * @param  list<array{tenant_id: string, server_id: string, schema_version: int}>  $behind
     */
    public function __construct(
        public int $requiredVersion,
        public array $byVersion,
        public array $byServer,
        public array $behind,
        public ?string $oldestLaggard,
    ) {}
}
