<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AddProductBarcode;

final class AddProductBarcodeResult
{
    public function __construct(
        public readonly string $barcodeId,
    ) {}
}
