<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateBrand;

final class CreateBrandResult
{
    public function __construct(
        public readonly string $brandId,
    ) {}
}
