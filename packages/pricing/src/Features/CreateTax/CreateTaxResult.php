<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTax;

final class CreateTaxResult
{
    public function __construct(
        public readonly string $taxId,
    ) {}
}
