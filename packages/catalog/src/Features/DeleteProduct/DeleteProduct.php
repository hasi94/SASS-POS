<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteProduct;

/**
 * Soft-delete a product (T-027 AC-20, plan Ruling 8).
 */
final class DeleteProduct
{
    public function __construct(
        public readonly string $productId,
        public readonly ?string $actorId = null,
    ) {}
}
