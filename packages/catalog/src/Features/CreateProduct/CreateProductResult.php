<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProduct;

final class CreateProductResult
{
    public function __construct(
        public readonly string $productId,
    ) {}
}
