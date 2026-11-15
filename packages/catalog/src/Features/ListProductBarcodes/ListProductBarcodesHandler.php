<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\ListProductBarcodes;

use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;

/**
 * List a product's barcodes, primary first (T-027 AC-28 to AC-30, plan
 * Ruling 9).
 */
final class ListProductBarcodesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListProductBarcodes $query): array
    {
        $productId = Identifier::fold($query->productId, 'productId');

        return $this->tenant->get()->table('product_barcodes')
            ->where('product_id', $productId)
            ->orderByDesc('is_primary')
            ->orderBy('barcode')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
