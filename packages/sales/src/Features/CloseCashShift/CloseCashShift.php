<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CloseCashShift;

/**
 * Close a cash shift (T-030 AC-34, AC-35, AC-37).
 */
final class CloseCashShift
{
    /**
     * @param  array<string, mixed>  $fields  `shift_id`, `counted_cash`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
