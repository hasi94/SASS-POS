<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateUnitConversion;

final class CreateUnitConversionResult
{
    public function __construct(
        public readonly string $conversionId,
    ) {}
}
