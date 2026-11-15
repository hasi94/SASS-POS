<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ReconcileCashShift;

/**
 * Mark a closed cash shift reconciled (T-030 AC-36, AC-37).
 */
final class ReconcileCashShift
{
    /**
     * @param  array<string, mixed>  $fields  `shift_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
