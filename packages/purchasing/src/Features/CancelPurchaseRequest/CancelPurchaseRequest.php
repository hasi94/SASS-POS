<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CancelPurchaseRequest;

/**
 * Cancel a purchase request that has not been converted (T-034 AC-29).
 */
final class CancelPurchaseRequest
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $actorId = null,
    ) {}
}
