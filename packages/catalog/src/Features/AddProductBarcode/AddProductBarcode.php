<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AddProductBarcode;

/**
 * Add a barcode to a product (T-027 AC-28 to AC-30, plan Ruling 9).
 */
final class AddProductBarcode
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
