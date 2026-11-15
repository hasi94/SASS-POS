<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Units;

/**
 * Every `units` row a tenant has, in memory - about 20 per tenant (T-027
 * plan Ruling 3). `UnitLadder` is pure over this and the two candidate
 * `unit_conversions` rows; `DatabaseUnitResolver` is the one place that
 * builds this from the tenant connection.
 */
final class UnitCatalogue
{
    /**
     * @var array<string, array{code: string, dimension: Dimension, precision: int, baseUnitId: ?string, factor: ?string}>
     */
    private array $units = [];

    /**
     * @param  list<array{id: string, code: string, dimension: Dimension, precision: int, baseUnitId: ?string, factor: ?string}>  $rows
     */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $this->units[$row['id']] = [
                'code' => $row['code'],
                'dimension' => $row['dimension'],
                'precision' => $row['precision'],
                'baseUnitId' => $row['baseUnitId'],
                'factor' => $row['factor'],
            ];
        }
    }

    public function dimension(string $unitId): ?Dimension
    {
        return $this->units[$unitId]['dimension'] ?? null;
    }

    public function code(string $unitId): ?string
    {
        return $this->units[$unitId]['code'] ?? null;
    }

    public function precision(string $unitId): ?int
    {
        return $this->units[$unitId]['precision'] ?? null;
    }

    public function exists(string $unitId): bool
    {
        return array_key_exists($unitId, $this->units);
    }

    /**
     * The one base-chain step: this unit's base unit and the factor that
     * converts one of it into one of that base (`docs/database.md` §6.3's
     * own example: gram, base kilogram, factor 0.001). Null when the unit
     * is a chain root (no `base_unit_id`) or unknown.
     *
     * @return array{baseUnitId: string, factor: string}|null
     */
    public function baseOf(string $unitId): ?array
    {
        $unit = $this->units[$unitId] ?? null;

        if ($unit === null || $unit['baseUnitId'] === null || $unit['factor'] === null) {
            return null;
        }

        return ['baseUnitId' => $unit['baseUnitId'], 'factor' => $unit['factor']];
    }
}
