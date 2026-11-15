<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateDiscount;

/**
 * Update a discount (T-029 AC-19).
 */
final class UpdateDiscount
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $discountId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
