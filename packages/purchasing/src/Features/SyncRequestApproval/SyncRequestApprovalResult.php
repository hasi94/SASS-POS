<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SyncRequestApproval;

final class SyncRequestApprovalResult
{
    /**
     * @param  bool  $changed  false when nothing moved: the request was not `pending_approval`, or its instance is still pending
     * @param  array<string, mixed>  $request  the stored header
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $status,
        public readonly bool $changed,
        public readonly array $request,
    ) {}
}
