<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\ListProductBarcodes;

/**
 * List a product's barcodes, primary first (T-027 AC-28 to AC-30, plan
 * Ruling 9).
 */
final class ListProductBarcodes
{
    public function __construct(
        public readonly string $productId,
    ) {}
}
