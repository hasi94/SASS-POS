<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * Rows in a fixture tenant for the tables Purchasing reads without a key,
 * written directly (T-034 plan section 3, the `SalesSeed` shape).
 *
 * **Direct inserts, deliberately, and not this package's own handlers**: a
 * handler test seeded through another handler would be answering two questions at
 * once. Branches, employees and roles come from `AccessSeed`; units, products and
 * tax groups from `PricingSeed`. Foreign keys are enforced on the fixture
 * connection, so a seed naming a row that does not exist fails here rather than
 * producing a row production could not hold.
 */
final class PurchasingSeed
{
    public const NOW = '2026-11-16 09:00:00.000';

    /** The pricing date the seeded tax rates are in force on. */
    public const DATE = '2026-11-16';

    public static function connection(): Connection
    {
        return AccessSeed::connection();
    }

    public static function branch(bool $active = true): string
    {
        return AccessSeed::branch($active);
    }

    public static function employee(string $code): string
    {
        return AccessSeed::employee($code);
    }

    /**
     * A location, a purchasable and active store of the branch unless overridden.
     * A `branch_id` override of null is a central warehouse.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function location(?string $branchId, string $code = 'MAIN', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('locations')->insert(array_merge([
            'id' => $id,
            'branch_id' => $branchId,
            'code' => $code.'-'.substr($id, -6),
            'name' => 'Location '.$code,
            'type' => $branchId === null ? 'warehouse' : 'branch_store',
            'allow_negative_stock' => false,
            'is_sellable_from' => true,
            'is_purchasable_to' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));

        return $id;
    }

    /**
     * A stocked product with its own stock unit (each), purchasable and active
     * unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function product(string $sku, ?string $stockUnitId = null, array $overrides = []): string
    {
        return PricingSeed::product($sku, $stockUnitId, $overrides);
    }

    /**
     * A stock unit and a larger one, `factor` of the first (a dozen is twelve
     * each), on one dimension.
     *
     * @return array{base: string, large: string}
     */
    public static function unitPair(string $baseCode, string $largeCode, string $factor): array
    {
        $base = PricingSeed::unit($baseCode);
        $large = PricingSeed::unit($largeCode, 'count', ['base_unit_id' => $base, 'factor' => $factor]);

        return ['base' => $base, 'large' => $large];
    }

    /**
     * A tax group holding one percentage tax in force from before every date a
     * test uses.
     *
     * @param  array<string, mixed>  $group
     */
    public static function taxGroup(string $code, string $rate = '10.0000', bool $inclusive = false, string $appliesTo = 'both', array $group = []): string
    {
        $tax = PricingSeed::tax(PricingSeed::authority('PA'.$code), 'PUVAT'.$code, ['applies_to' => $appliesTo]);

        PricingSeed::rate($tax, $rate, '2020-01-01');

        $id = PricingSeed::group('PUG'.$code, array_merge(['is_inclusive' => $inclusive], $group));

        PricingSeed::member($id, $tax, 1);

        return $id;
    }
}
