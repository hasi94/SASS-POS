<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Platform\Provisioning\OwnerCredentialAnnouncement;

/**
 * What `tenant:provision` reports back (T-026 AC-2 to AC-4).
 *
 * `$ownerMode`/`$ownerPassword` are `create_owner`'s own announcement
 * (D-26, {@see OwnerCredentialAnnouncement}),
 * carried here only because this result is itself an in-memory value the
 * command reads once and discards - never persisted, logged or journalled.
 * Both are null when `create_owner` did not run this attempt (every step
 * already `done` on a resume that only needed to finish `verify`/`activate`).
 */
final class ProvisionTenantResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly ?string $ownerMode = null,
        public readonly ?string $ownerPassword = null,
    ) {}
}
