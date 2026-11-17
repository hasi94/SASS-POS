<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SyncOrderApproval;

/**
 * Apply the workflow engine's decision to a purchase order (T-034 AC-59, D-52).
 */
final class SyncOrderApproval
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $actorId = null,
    ) {}
}
