<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetPurchaseRequest;

/**
 * One purchase request with its lines (T-034 AC-34).
 */
final class GetPurchaseRequest
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $actorId = null,
    ) {}
}
