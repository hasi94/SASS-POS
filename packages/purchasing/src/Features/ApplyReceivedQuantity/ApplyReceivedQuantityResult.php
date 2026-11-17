<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ApplyReceivedQuantity;

final class ApplyReceivedQuantityResult
{
    /**
     * @param  bool  $replayed  true when the application id had already been applied with the same line and deltas: nothing moved, nothing was audited, no event was written (AC-76)
     * @param  string  $status  the order's status after the call (for a replay, as it stands now)
     * @param  array<string, mixed>  $line  the stored line
     * @param  array<string, mixed>  $order  the stored header
     */
    public function __construct(
        public readonly string $applicationId,
        public readonly string $orderId,
        public readonly string $lineId,
        public readonly string $status,
        public readonly bool $replayed,
        public readonly array $line,
        public readonly array $order,
    ) {}
}
