<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CompleteSale;

/**
 * Complete a sale (T-030 AC-14, AC-14A, AC-15).
 */
final class CompleteSale
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
