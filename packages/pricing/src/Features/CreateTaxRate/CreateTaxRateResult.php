<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxRate;

final class CreateTaxRateResult
{
    public function __construct(
        public readonly string $taxRateId,
    ) {}
}
