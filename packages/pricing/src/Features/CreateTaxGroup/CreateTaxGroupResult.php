<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxGroup;

final class CreateTaxGroupResult
{
    public function __construct(
        public readonly string $taxGroupId,
    ) {}
}
