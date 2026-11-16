<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RequestApproval;

final class RequestApprovalResult
{
    public function __construct(
        public readonly string $approvalId,
    ) {}
}
