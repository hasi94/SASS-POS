<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RecallSale;

/**
 * Pick a parked sale up, at any terminal of its branch (T-030 AC-18, AC-19).
 */
final class RecallSale
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id`, and the `terminal_id` it is recalled at
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
