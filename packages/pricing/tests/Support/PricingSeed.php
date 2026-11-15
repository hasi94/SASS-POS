<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's pricing tables and the few tables pricing reads
 * without a key, written directly (the `CatalogSeed` shape, T-029 plan K-7).
 *
 * **Direct inserts, deliberately, and not this package's own handlers.** A
 * handler test seeded through another handler would be answering two questions
 * at once. Foreign keys are enforced on the fixture connection, so a seed naming
 * a row that does not exist fails here rather than producing a row production
 * could not hold.
 */
final class PricingSeed
{
    public const NOW = '2026-11-08 09:00:00.000';

    public static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        return $connection;
    }

    /**
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

    public static function category(string $code): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('categories')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'parent_id' => null,
            'code' => $code,
            'name' => 'Category '.$code,
            'path' => $id,
            'depth' => 0,
            'image_path' => null,
            'colour' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        return $id;
    }

    /**
     * A minimal, valid product with its own unit and category.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function product(string $sku, ?string $stockUnitId = null, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        $unit = $stockUnitId ?? self::unit('U'.$sku);
        $category = self::category('C'.$sku);

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
            'category_id' => $category,
            'brand_id' => null,
            'stock_unit_id' => $unit,
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

    public static function branch(): string
    {
        return AccessSeed::branch();
    }

    public static function customerGroup(string $code): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customer_groups')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'code' => $code,
            'name' => 'Group '.$code,
        ]);

        return $id;
    }

    public static function customer(string $name): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customers')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'name' => $name,
        ]);

        return $id;
    }

    public static function workflowDefinition(string $key): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('workflow_definitions')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'key' => $key,
            'name' => 'Workflow '.$key,
            'entity_type' => 'discount',
            'conditions' => '[]',
            'priority' => 0,
            'on_reject' => 'block',
        ]);

        return $id;
    }

    public static function authority(string $code = 'TA'): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('tax_authorities')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'code' => $code,
            'name' => 'Authority '.$code,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function tax(string $authorityId, string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('taxes')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'authority_id' => $authorityId,
            'code' => $code,
            'name' => 'Tax '.$code,
            'tax_code' => null,
            'type' => 'percentage',
            'is_compound' => false,
            'applies_to' => 'both',
            'is_recoverable' => false,
            'is_withholding' => false,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    public static function rate(string $taxId, string $rate, string $from, ?string $to = null): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('tax_rates')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'tax_id' => $taxId,
            'rate' => $rate,
            'effective_from' => $from,
            'effective_to' => $to,
            'note' => null,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function group(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('tax_groups')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'code' => $code,
            'name' => 'Group '.$code,
            'is_inclusive' => false,
            'is_default' => false,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    public static function member(string $groupId, string $taxId, int $sequence): void
    {
        self::connection()->table('tax_group_taxes')->insert(['tax_group_id' => $groupId, 'tax_id' => $taxId, 'sequence' => $sequence]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function priceList(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('price_lists')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'code' => $code,
            'name' => 'List '.$code,
            'type' => 'standard',
            'currency' => 'USD',
            'priority' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    public static function item(string $listId, string $productId, ?string $unitId, string $minQty, string $price): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('price_list_items')->insert([
            'id' => $id,
            'price_list_id' => $listId,
            'product_id' => $productId,
            'unit_id' => $unitId,
            'min_qty' => $minQty,
            'price' => $price,
            'discount_percent' => null,
        ]);

        return $id;
    }

    public static function assignment(string $listId, string $scopeType, string $scopeId, int $priority = 0): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('price_list_assignments')->insert([
            'id' => $id,
            'price_list_id' => $listId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'priority' => $priority,
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function discount(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('discounts')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'code' => $code,
            'name' => 'Discount '.$code,
            'type' => 'percentage',
            'value' => '10.0000',
            'scope' => 'item',
            'applies_to' => null,
            'min_amount' => null,
            'max_discount' => null,
            'requires_approval' => false,
            'approval_threshold' => null,
            'workflow_definition_id' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'used_count' => 0,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(string $table, string $id): array
    {
        $row = self::connection()->table($table)->where('id', $id)->first();

        Assert::assertNotNull($row, "No {$table} row has id [{$id}].");

        return (array) $row;
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
     * differ run to run (`id`, `created_at`, `ip`), with `before`, `after` and
     * `changed_fields` decoded: compared as a whole row, so a column that quietly
     * goes missing or null fails (T-010 Ruling 11).
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
