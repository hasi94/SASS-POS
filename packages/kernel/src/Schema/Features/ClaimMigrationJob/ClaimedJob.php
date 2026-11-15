<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ClaimMigrationJob;

/**
 * A job this worker now holds.
 *
 * It carries identifiers, versions and the lease, and **no credential and no
 * credential reference** (AC-41): the executing step resolves the tenant
 * mapping itself, through the one connection-construction path, so nothing a
 * queue serialises can carry a secret.
 */
final readonly class ClaimedJob
{
    public function __construct(
        public string $jobId,
        public string $runId,
        public string $tenantId,
        public string $serverId,
        public ?int $fromVersion,
        public int $toVersion,
        public int $attempts,
        public string $lockExpiresAt,
    ) {}
}
