<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateUnit;

final class CreateUnitResult
{
    public function __construct(
        public readonly string $unitId,
    ) {}
}
