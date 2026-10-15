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
 *
 * **`runs` is a fifth reading and a different subject** (plan section 19.2,
 * step 13f): the four above describe tenant *databases*, and this one
 * describes the runs that are moving them. It exists because a run that is
 * `running` has two very different meanings - work is in flight, or every
 * job is terminal and nobody has written the run's final counters yet - and
 * an operator who cannot tell them apart cannot tell a stuck platform from a
 * finished one.
 */
final readonly class SchemaStatusReport
{
    /**
     * @param  array<int, int>  $byVersion  schema version => how many counted tenants carry it
     * @param  array<string, array<int, int>>  $byServer  server id => version => count
     * @param  list<array{tenant_id: string, server_id: string, schema_version: int}>  $behind
     * @param  list<array{run_id: string, counts: array<string, int>, outstanding: int}>  $runs  every
     *                                                                                           `running` run, lowest id first
     */
    public function __construct(
        public int $requiredVersion,
        public array $byVersion,
        public array $byServer,
        public array $behind,
        public ?string $oldestLaggard,
        public array $runs,
    ) {}
}
