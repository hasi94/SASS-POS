<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ArchiveAuditLogs;

/**
 * What one tenant's archival move did (T-017 AC-8).
 */
final readonly class ArchiveAuditLogsResult
{
    public function __construct(
        public int $moved,
        public int $chunks,
    ) {}
}
