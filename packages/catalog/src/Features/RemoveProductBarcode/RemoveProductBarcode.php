<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductBarcode;

/**
 * Remove a product barcode (T-027 AC-28, AC-31, plan Ruling 9). This is
 * also how a barcode's value itself is "changed": remove, then add.
 */
final class RemoveProductBarcode
{
    public function __construct(
        public readonly string $barcodeId,
        public readonly ?string $actorId = null,
    ) {}
}
