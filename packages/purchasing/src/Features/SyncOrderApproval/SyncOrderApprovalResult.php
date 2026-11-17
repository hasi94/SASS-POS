<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SyncOrderApproval;

final class SyncOrderApprovalResult
{
    /**
     * @param  bool  $changed  false when nothing moved: the order was not `pending_approval`, or its instance is still pending
     * @param  array<string, mixed>  $order  the stored header
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly bool $changed,
        public readonly array $order,
    ) {}
}
