<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CancelPurchaseRequest;

final class CancelPurchaseRequestResult
{
    /**
     * @param  bool  $changed  false when the request was already cancelled (an idempotent repeat: no audit row)
     */
    public function __construct(
        public readonly string $requestId,
        public readonly bool $changed,
    ) {}
}
