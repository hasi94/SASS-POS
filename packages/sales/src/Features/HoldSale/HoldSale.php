<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\HoldSale;

/**
 * Park a sale (T-030 AC-17, AC-19).
 */
final class HoldSale
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id`, and the `terminal_id` it is parked at
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
