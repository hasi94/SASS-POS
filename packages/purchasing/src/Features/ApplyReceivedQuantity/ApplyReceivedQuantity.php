<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ApplyReceivedQuantity;

/**
 * Apply one receipt delta to an order line (T-034 AC-69 to AC-80, AC-96).
 *
 * An internal operation for the receiving slice's own transaction: there is no
 * permission check here (see {@see ApplyReceivedQuantityHandler}).
 */
final class ApplyReceivedQuantity
{
    /**
     * @param  array<string, mixed>  $fields  `application_id` (the caller's ULID), `line_id`, `received_base_delta`, `received_quantity_delta`
     * @param  string|null  $actorId  who is receiving, recorded on the application and the audit row; may be null for a system caller
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
