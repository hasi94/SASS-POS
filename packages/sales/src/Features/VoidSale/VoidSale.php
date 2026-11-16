<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\VoidSale;

/**
 * Void a sale (T-030 AC-21, AC-22).
 */
final class VoidSale
{
    /**
     * @param  array<string, mixed>  $fields  `sale_id`, `reason`, and for a completed sale `approval_id`
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
