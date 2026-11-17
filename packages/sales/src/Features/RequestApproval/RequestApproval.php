<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RequestApproval;

/**
 * Ask for a manager's approval of a void, a refund, a price override or a
 * discount (T-030 AC-23, D-13).
 */
final class RequestApproval
{
    /**
     * @param  array<string, mixed>  $fields  `type`, `branch_id`, `reference_type`, `reference_id`, and optionally `terminal_id` and `payload`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
