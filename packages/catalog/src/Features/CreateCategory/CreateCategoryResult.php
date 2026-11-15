<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateCategory;

final class CreateCategoryResult
{
    public function __construct(
        public readonly string $categoryId,
    ) {}
}
