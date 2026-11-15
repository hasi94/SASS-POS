<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use InvalidArgumentException;

/**
 * The product facts the discount check reads (AC-20.4, AC-20.5): the product's
 * own id, its category and brand, and whether the product allows a discount at
 * all (`products.allow_discount`).
 */
final readonly class ProductFacts
{
    public function __construct(
        public string $productId,
        public ?string $categoryId,
        public ?string $brandId,
        public bool $allowDiscount,
    ) {
        if ($productId === '') {
            throw new InvalidArgumentException('[productId] must not be blank.');
        }
    }
}
