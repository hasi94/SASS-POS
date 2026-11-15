<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CreateSale;

/**
 * Open a draft sale at a terminal (T-030 AC-1).
 */
final class CreateSale
{
    /**
     * @param  array<string, mixed>  $fields  `terminal_id`, optional `customer_id` and `notes`; the branch, location, currency, business date and shift are the server's
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
