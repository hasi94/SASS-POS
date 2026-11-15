<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateProductBarcode;

/**
 * Update a barcode's `unit_id`, `quantity` or `is_primary` (T-027 AC-29,
 * AC-30, plan Ruling 9). The barcode value itself is immutable: change it
 * by removing and re-adding.
 */
final class UpdateProductBarcode
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $barcodeId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
