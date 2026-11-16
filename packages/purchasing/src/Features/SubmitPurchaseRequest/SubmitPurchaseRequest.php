<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SubmitPurchaseRequest;

/**
 * Submit a draft purchase request for approval (T-034 AC-26, AC-27, AC-37).
 */
final class SubmitPurchaseRequest
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $actorId = null,
    ) {}
}
