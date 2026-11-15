<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateProduct;

/**
 * Update a product (T-027 AC-18 to AC-27, AC-27a, plan Ruling 8).
 */
final class UpdateProduct
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $productId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
