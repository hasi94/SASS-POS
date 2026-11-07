<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Platform\Provisioning\OwnerPasswordReissue;

/**
 * The raw, unvalidated submission behind `tenant:provision` (T-026 AC-1,
 * D-28).
 *
 * **Everything here is exactly what the operator typed**, string or null -
 * no normalisation, no default, no lookup. {@see ProvisionTenantInput::validate()}
 * is the one place that turns this into something a run may be planned from.
 * Keeping the two separate is what lets "zero rows exist after a refusal"
 * stay true by construction: nothing below this class's constructor can
 * write anything.
 */
final class ProvisionTenant
{
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?string $name = null,
        public readonly ?string $country = null,
        public readonly ?string $timezone = null,
        public readonly ?string $currency = null,
        public readonly ?string $businessType = null,
        public readonly ?string $ownerEmail = null,
        public readonly ?string $ownerName = null,
        public readonly ?string $planId = null,
        public readonly bool $active = false,
        public readonly ?string $branchCode = null,
        public readonly ?string $branchName = null,
        public readonly ?string $serverCode = null,
        public readonly ?string $slug = null,
        public readonly ?string $actorUserId = null,
        public readonly ?string $resumeRunId = null,
        public readonly ?string $abandonRunId = null,
        /**
         * Review round 2 R-3: reissues the owner's one-time password for a
         * run whose `create_owner` step already succeeded, through
         * {@see OwnerPasswordReissue} -
         * works against a `completed` run too, since the precondition
         * (never signed in), not the run's own status, is what gates it.
         */
        public readonly ?string $reissueOwnerPasswordRunId = null,
    ) {}
}
