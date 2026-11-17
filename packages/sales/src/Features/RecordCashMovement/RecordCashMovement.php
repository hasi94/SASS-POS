<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RecordCashMovement;

/**
 * Record cash going into or out of an open shift's drawer (T-030 AC-33, AC-37).
 */
final class RecordCashMovement
{
    /**
     * @param  array<string, mixed>  $fields  `id` (the caller's ULID, which is the idempotency key), `shift_id`, `type`, `amount`, `reason`, and optionally `reference`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
