<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\DecideApproval;

final class DecideApprovalResult
{
    public function __construct(
        public readonly string $approvalId,
        public readonly string $status,
        public readonly string $deciderId,
    ) {}
}
