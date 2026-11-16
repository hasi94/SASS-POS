<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SubmitPurchaseRequest;

final class SubmitPurchaseRequestResult
{
    /**
     * @param  bool  $changed  false when the request was already submitted (`pending_approval` or `approved`): nothing was asked of the workflow and nothing was written
     * @param  array<string, mixed>  $request  the stored header
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $status,
        public readonly ?string $workflowInstanceId,
        public readonly bool $changed,
        public readonly array $request,
    ) {}
}
