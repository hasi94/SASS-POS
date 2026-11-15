<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RemoveSaleLine;

/**
 * Remove a line from a draft sale (T-030 AC-6, AC-8).
 */
final class RemoveSaleLine
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id` and `line_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
