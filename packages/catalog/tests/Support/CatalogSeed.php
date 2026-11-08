<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Support;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's catalog tables, written directly (T-027 plan
 * section 3, the `AccessSeed`/`OrganizationSeed` shape).
 *
 * **Direct inserts, deliberately, and not this task's own handlers.** A
 * handler test seeded through another handler would be answering two
 * questions at once. Foreign keys are enforced on the fixture connection
 * ({@see AccessTenantFixture::enter()}), so a
 * seed naming a row that does not exist fails here rather than producing a
 * row production could not hold.
 */
final class CatalogSeed
{
    public const NOW = '2026-11-08 09:00:00.000';

    public static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        return $connection;
    }

    /**
     * A unit of measure, with every column defaulted the way `units`
     * itself defaults it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function unit(string $code, string $dimension = 'count', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('units')->insert(array_merge([
            'id' => $id,
            'code' => $code,
            'name' => 'Unit '.$code,
            'dimension' => $dimension,
            'precision' => 4,
            'base_unit_id' => null,
            'factor' => null,
            'is_active' => true,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $overrides));

        return $id;
    }

    /**
     * A category, with its `path`/`depth` computed the same way
     * `CategoryPath` computes them, so a seeded tree is indistinguishable
     * from one `CreateCategory` built.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function category(string $code, ?string $parentId = null, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        $path = $id;
        $depth = 0;

        if ($parentId !== null) {
            $parent = self::connection()->table('categories')->where('id', $parentId)->first();
            $path = $parent->path.'/'.$id;
            $depth = $parent->depth + 1;
        }

        self::connection()->table('categories')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => 'Category '.$code,
            'path' => $path,
            'depth' => $depth,
            'image_path' => null,
            'colour' => null,
            'sort_order' => 0,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function brand(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('brands')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'code' => $code,
            'name' => 'Brand '.$code,
            'logo_path' => null,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    /**
     * A minimal, valid product: `simple`, active, sellable, stocked in
     * `$stockUnitId`.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function product(string $sku, string $categoryId, string $stockUnitId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('products')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'type' => 'simple',
            'parent_id' => null,
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'short_name' => null,
            'description' => null,
            'category_id' => $categoryId,
            'brand_id' => null,
            'stock_unit_id' => $stockUnitId,
            'purchase_unit_id' => null,
            'sales_unit_id' => null,
            'cost_price' => '0.0000',
            'selling_price' => '0.0000',
            'min_selling_price' => null,
            'msrp' => null,
            'track_stock' => true,
            'tracking_mode' => 'quantity',
            'is_weighable' => false,
            'price_mode' => 'per_unit',
            'plu_code' => null,
            'shelf_life_days' => null,
            'is_sellable' => true,
            'is_purchasable' => true,
            'is_active' => true,
            'allow_price_override' => false,
            'allow_discount' => true,
            'weight' => null,
            'dimensions' => null,
            'variant_options' => null,
            'variant_values' => null,
            'image_path' => null,
            'sort_order' => 0,
            'version' => 1,
        ], $overrides));

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function audit(): array
    {
        return self::connection()->table('audit_logs')->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * The one audit row an operation wrote, without the three columns that
     * differ run to run (`id`, `created_at`, `ip`), with `before`, `after`
     * and `changed_fields` decoded: compared as a whole row, so a column
     * that quietly goes missing or null fails (T-010 Ruling 11).
     *
     * @return array<string, mixed>
     */
    public static function onlyAuditRow(): array
    {
        $rows = self::audit();

        Assert::assertCount(1, $rows, 'Exactly one audit row was expected.');

        $row = $rows[0];

        unset($row['id'], $row['created_at'], $row['ip']);

        foreach (['before', 'after', 'changed_fields'] as $json) {
            $row[$json] = $row[$json] === null ? null : json_decode((string) $row[$json], true);
        }

        return $row;
    }
}
