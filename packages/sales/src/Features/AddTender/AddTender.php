<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\AddTender;

/**
 * Take a payment against a sale (T-030 AC-11, AC-12, AC-37, AC-56).
 */
final class AddTender
{
    /**
     * @param  array<string, mixed>  $fields  `id` (the caller's ULID, which is the row id and the idempotency key), `sale_id`, `payment_method_id`, `offered` (the money put on the counter), optional `reference`, and `terminal_id` (required only when the sale is held from `pending_payment`, to say where it is being picked up). `amount`, `tendered` and `change_given` are computed, never sent
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
