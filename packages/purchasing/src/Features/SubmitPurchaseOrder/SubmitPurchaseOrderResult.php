<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SubmitPurchaseOrder;

final class SubmitPurchaseOrderResult
{
    /**
     * @param  bool  $changed  false when the order was already submitted (`pending_approval` or `approved`): nothing was asked of the workflow and nothing was written
     * @param  array<string, mixed>  $order  the stored header
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly ?string $workflowInstanceId,
        public readonly bool $changed,
        public readonly array $order,
    ) {}
}
