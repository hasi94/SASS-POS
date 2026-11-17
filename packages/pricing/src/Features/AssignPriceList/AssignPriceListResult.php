<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AssignPriceList;

final class AssignPriceListResult
{
    public function __construct(
        public readonly string $priceListAssignmentId,
    ) {}
}
