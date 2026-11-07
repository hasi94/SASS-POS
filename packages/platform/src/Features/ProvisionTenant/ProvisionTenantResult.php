<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

/**
 * What `tenant:provision` reports back (T-026 AC-2 to AC-4).
 */
final class ProvisionTenantResult
{
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
    ) {}
}
