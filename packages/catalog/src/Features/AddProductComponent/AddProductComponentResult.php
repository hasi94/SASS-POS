<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AddProductComponent;

final class AddProductComponentResult
{
    public function __construct(
        public readonly string $componentId,
    ) {}
}
