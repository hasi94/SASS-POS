<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

/**
 * What a consuming write learns from the approval it has just used: which row,
 * who approved it (the employee a price override is attributed to, AC-7) and who
 * asked.
 */
final readonly class ConsumedApproval
{
    public function __construct(
        public string $approvalId,
        public string $decidedBy,
        public string $requestedBy,
    ) {}
}
