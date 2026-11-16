<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\DeleteDiscount;

/**
 * Soft-delete a discount (T-029 AC-21).
 */
final class DeleteDiscount
{
    public function __construct(
        public readonly string $discountId,
        public readonly ?string $actorId = null,
    ) {}
}
