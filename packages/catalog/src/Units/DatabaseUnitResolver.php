<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Units;

use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Catalog\ResolvedFactor;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Flexipos\Contracts\Catalog\UnitSnapshot;
use Illuminate\Database\Connection;

/**
 * Where `UnitLadder` and `SnapshotCalculator` - pure over data this class
 * loads - meet the real tenant database for the first time (T-027 plan
 * Ruling 3: three queries, never a fourth, and never a cache - plan
 * Ruling 3's closing note).
 *
 * **The one implementation of `UnitResolver`**, bound by
 * `CatalogServiceProvider`. No file outside `Units/` may read
 * `unit_conversions` or `units.base_unit_id`/`factor`
 * (`QaCatalogSourceRulesTest`, step 18) - this is the one place that does.
 */
final class DatabaseUnitResolver implements UnitResolver
{
    public function __construct(
        private readonly TenantConnection $tenant,
    ) {}

    public function resolve(string $productId, string $fromUnitId): ResolvedFactor
    {
        $connection = $this->tenant->get();

        $productId = strtoupper($productId);
        $fromUnitId = strtoupper($fromUnitId);

        $product = $connection->table('products')->where('id', $productId)->first(['stock_unit_id']);

        if ($product === null) {
            throw UnitResolutionRefused::unknownProduct("The product [{$productId}] does not exist.");
        }

        $stockUnitId = strtoupper((string) $product->stock_unit_id);

        $catalogue = $this->catalogue($connection);

        $productConversion = $this->conversion($connection, $productId, $fromUnitId, $stockUnitId);
        $globalConversion = $this->conversion($connection, null, $fromUnitId, $stockUnitId);

        return UnitLadder::resolve($catalogue, $productId, $fromUnitId, $stockUnitId, $productConversion, $globalConversion);
    }

    public function snapshot(string $productId, string $unitId, int|string $quantity): UnitSnapshot
    {
        $connection = $this->tenant->get();

        $factor = $this->resolve($productId, $unitId);

        $catalogue = $this->catalogue($connection);

        $lineUnitId = strtoupper($unitId);
        $lineUnitCode = $catalogue->code($lineUnitId);
        $lineUnitPrecision = $catalogue->precision($lineUnitId);
        $stockUnitPrecision = $catalogue->precision($factor->stockUnitId);

        if ($lineUnitCode === null || $lineUnitPrecision === null || $stockUnitPrecision === null) {
            throw UnitResolutionRefused::unknownUnit("The unit [{$lineUnitId}] does not exist.");
        }

        return SnapshotCalculator::compute($factor, $lineUnitId, $lineUnitCode, $lineUnitPrecision, $stockUnitPrecision, $quantity);
    }

    private function catalogue(Connection $connection): UnitCatalogue
    {
        $rows = $connection->table('units')->get()->map(static fn (object $row): array => [
            'id' => strtoupper((string) $row->id),
            'code' => (string) $row->code,
            'dimension' => Dimension::from((string) $row->dimension),
            'precision' => (int) $row->precision,
            'baseUnitId' => $row->base_unit_id === null ? null : strtoupper((string) $row->base_unit_id),
            'factor' => $row->factor === null ? null : (string) $row->factor,
        ])->all();

        return new UnitCatalogue($rows);
    }

    private function conversion(Connection $connection, ?string $productId, string $fromUnitId, string $toUnitId): ?ConversionRow
    {
        $query = $connection->table('unit_conversions')
            ->where('from_unit_id', $fromUnitId)
            ->where('to_unit_id', $toUnitId);

        if ($productId === null) {
            $query->whereNull('product_id');
        } else {
            $query->where('product_id', $productId);
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return new ConversionRow(
            strtoupper((string) $row->id),
            $row->product_id === null ? null : strtoupper((string) $row->product_id),
            strtoupper((string) $row->from_unit_id),
            strtoupper((string) $row->to_unit_id),
            (string) $row->factor,
        );
    }
}
