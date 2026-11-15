<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\AbandonProvisioning;

/**
 * What `--abandon` reports back (T-026 AC-9).
 *
 * `$message` is the spec's own sentence verbatim, with the database name and
 * server code already filled in (or the "never reached create_database"
 * variant when there was no database to speak of) - the command prints this
 * and nothing else needs to reconstruct it.
 */
final class AbandonProvisioningResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly ?string $databaseName,
        public readonly ?string $serverCode,
        public readonly string $message,
    ) {}
}
