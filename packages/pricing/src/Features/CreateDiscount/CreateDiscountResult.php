<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateDiscount;

final class CreateDiscountResult
{
    public function __construct(
        public readonly string $discountId,
    ) {}
}
