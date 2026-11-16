<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SyncRequestApproval;

/**
 * Apply the workflow engine's decision to a purchase request (T-034 AC-28, D-52).
 */
final class SyncRequestApproval
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $actorId = null,
    ) {}
}
