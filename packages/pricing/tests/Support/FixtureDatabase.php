<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes the rows a checked-in fixture stands for into the entered tenant
 * database, with the fixture's own ids (T-029 step 16), so the database adapter
 * can be shown to return exactly what the fixture's `expect` says. The fixture
 * file stays the single source of the expected answer: nothing here computes.
 *
 * Understands the `line` kind (the whole input), the `price` kind (the ladder
 * input, given a product with an empty tax group) and the `bill` kind (the
 * discount rules). Foreign keys are enforced on the fixture connection, so a
 * row naming a missing parent fails here.
 *
 * A fixture's `selling_price` of null cannot be stored (`products.selling_price`
 * is NOT NULL, default 0), so it is seeded as `0.0000`: use only a fixture whose
 * price a list resolves.
 */
final class FixtureDatabase
{
    public const PRODUCT = '01PRODPRICEFIXTURE00000000';

    public const CATEGORY = '01CATPRICEFIXTURE000000000';

    public const STOCK_UNIT = '01STOCKUNITFIXTURE00000000';

    public const EMPTY_GROUP = 'EMPTYTAXGROUP000000000000A';

    /**
     * @param  array<string, mixed>  $input  a `line` fixture's input, or a `price` fixture's input
     */
    public static function seedLine(array $input, bool $isPrice = false): void
    {
        $db = PricingSeed::connection();

        /** @var array<string, mixed> $ladder */
        $ladder = $isPrice ? $input : (array) $input['ladder'];
        /** @var array<string, mixed> $product */
        $product = $isPrice ? ['id' => self::PRODUCT, 'category_id' => self::CATEGORY, 'brand_id' => null, 'allow_discount' => true] : (array) $input['product'];
        /** @var array<string, mixed> $group */
        $group = $isPrice ? ['id' => self::EMPTY_GROUP, 'is_inclusive' => false, 'members' => []] : (array) $input['tax_group'];

        self::units($db, $ladder);
        self::taxGroup($db, $group);
        self::category($db, (string) $product['category_id']);

        if ($product['brand_id'] !== null) {
            $db->table('brands')->insert([
                'id' => $product['brand_id'], 'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
                'code' => $product['brand_id'], 'name' => $product['brand_id'], 'is_active' => true,
            ]);
        }

        PricingSeed::product('FX', self::STOCK_UNIT, [
            'id' => $product['id'],
            'category_id' => $product['category_id'],
            'brand_id' => $product['brand_id'],
            'allow_discount' => $product['allow_discount'],
            'selling_price' => $ladder['selling_price'] ?? '0.0000',
            'tax_group_id' => $group['id'],
        ]);

        if ($ladder['branch_id'] !== null) {
            $db->table('branches')->insert([
                'id' => $ladder['branch_id'], 'code' => 'BR', 'name' => 'Branch', 'timezone' => 'Asia/Colombo',
                'currency' => 'USD', 'is_active' => true, 'sort_order' => 0,
                'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
            ]);

            if ($ladder['branch_selling_price'] !== null) {
                $db->table('product_branches')->insert([
                    'product_id' => $product['id'], 'branch_id' => $ladder['branch_id'], 'is_available' => true,
                    'selling_price' => $ladder['branch_selling_price'],
                    'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
                ]);
            }
        }

        foreach ((array) $ladder['lists'] as $list) {
            self::priceList($db, (array) $list, (string) $product['id']);
        }

        if (! $isPrice) {
            self::discounts($db, (array) ($input['discounts'] ?? []));
        }
    }

    /**
     * @param  array<string, mixed>  $input  a `bill` fixture's input
     */
    public static function seedBill(array $input): void
    {
        self::discounts(PricingSeed::connection(), (array) $input['discounts']);
    }

    /**
     * @param  array<string, mixed>  $ladder
     */
    private static function units(Connection $db, array $ladder): void
    {
        $ids = [self::STOCK_UNIT => true, (string) $ladder['unit_id'] => true];

        foreach ((array) $ladder['lists'] as $list) {
            foreach ((array) ((array) $list)['items'] as $item) {
                $unit = ((array) $item)['unit_id'];

                if ($unit !== null) {
                    $ids[(string) $unit] = true;
                }
            }
        }

        foreach (array_keys($ids) as $id) {
            $db->table('units')->insert([
                'id' => (string) $id, 'code' => (string) $id, 'name' => 'Unit '.$id, 'dimension' => 'count',
                'precision' => 4, 'base_unit_id' => null, 'factor' => null, 'is_active' => true,
                'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
            ]);
        }
    }

    private static function category(Connection $db, string $id): void
    {
        $db->table('categories')->insert([
            'id' => $id, 'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW, 'deleted_at' => null,
            'parent_id' => null, 'code' => $id, 'name' => 'Category '.$id, 'path' => $id, 'depth' => 0,
            'image_path' => null, 'colour' => null, 'sort_order' => 0, 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function taxGroup(Connection $db, array $group): void
    {
        $authority = PricingSeed::authority('FXA');

        $db->table('tax_groups')->insert([
            'id' => $group['id'], 'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
            'code' => 'G'.$group['id'], 'name' => 'Group', 'is_inclusive' => $group['is_inclusive'],
            'is_default' => false, 'is_active' => true,
        ]);

        foreach ((array) $group['members'] as $member) {
            $member = (array) $member;

            $db->table('taxes')->insert([
                'id' => $member['tax_id'], 'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
                'deleted_at' => $member['is_deleted'] ? PricingSeed::NOW : null,
                'authority_id' => $authority, 'code' => $member['code'], 'name' => 'Tax '.$member['code'],
                'tax_code' => $member['tax_code'], 'type' => $member['type'], 'is_compound' => $member['is_compound'],
                'applies_to' => $member['applies_to'], 'is_recoverable' => false, 'is_withholding' => false,
                'is_active' => $member['is_active'],
            ]);

            foreach ((array) $member['rates'] as $rate) {
                $rate = (array) $rate;

                PricingSeed::rate((string) $member['tax_id'], (string) $rate['rate'], (string) $rate['effective_from'], $rate['effective_to'] === null ? null : (string) $rate['effective_to']);
            }

            PricingSeed::member((string) $group['id'], (string) $member['tax_id'], (int) $member['sequence']);
        }
    }

    /**
     * @param  array<string, mixed>  $list
     */
    private static function priceList(Connection $db, array $list, string $productId): void
    {
        $db->table('price_lists')->insert([
            'id' => $list['list_id'], 'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
            'deleted_at' => $list['is_deleted'] ? PricingSeed::NOW : null,
            'code' => $list['code'], 'name' => 'List '.$list['code'], 'type' => $list['type'],
            'currency' => $list['currency'], 'priority' => (int) $list['priority'],
            'starts_at' => self::datetime($list['starts_at']), 'ends_at' => self::datetime($list['ends_at']),
            'is_active' => $list['is_active'],
        ]);

        foreach ((array) $list['assignments'] as $assignment) {
            $assignment = (array) $assignment;

            PricingSeed::assignment((string) $list['list_id'], (string) $assignment['scope_type'], (string) $assignment['scope_id'], (int) $assignment['priority']);
        }

        foreach ((array) $list['items'] as $item) {
            $item = (array) $item;

            $db->table('price_list_items')->insert([
                'id' => (string) Str::ulid(), 'price_list_id' => $list['list_id'], 'product_id' => $productId,
                'unit_id' => $item['unit_id'], 'min_qty' => $item['min_qty'], 'price' => $item['price'],
                'discount_percent' => $item['discount_percent'],
            ]);
        }
    }

    /**
     * @param  array<int|string, mixed>  $discounts
     */
    private static function discounts(Connection $db, array $discounts): void
    {
        foreach ($discounts as $discount) {
            $discount = (array) $discount;

            $db->table('discounts')->insert([
                'id' => $discount['id'], 'created_at' => PricingSeed::NOW, 'updated_at' => PricingSeed::NOW,
                'deleted_at' => null, 'created_by' => null, 'updated_by' => null,
                'code' => 'D'.$discount['id'], 'name' => 'Discount', 'type' => $discount['type'],
                'value' => $discount['value'], 'scope' => $discount['scope'],
                'applies_to' => json_encode($discount['applies_to']),
                'min_amount' => $discount['min_amount'], 'max_discount' => $discount['max_discount'],
                'requires_approval' => false, 'approval_threshold' => null, 'workflow_definition_id' => null,
                'starts_at' => self::datetime($discount['starts_at']), 'ends_at' => self::datetime($discount['ends_at']),
                'usage_limit' => $discount['usage_limit'] === null ? null : (int) $discount['usage_limit'],
                'used_count' => (int) $discount['used_count'], 'is_active' => $discount['is_active'],
            ]);
        }
    }

    /**
     * `YYYY-MM-DDTHH:MM:SS.mmmZ` to the `DATETIME(3)` text the column holds.
     */
    public static function datetime(mixed $instant): ?string
    {
        if ($instant === null) {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2}\.\d{3})Z$/D', (string) $instant, $parts) !== 1) {
            throw new RuntimeException("Not a fixture instant: [{$instant}].");
        }

        return $parts[1].' '.$parts[2];
    }
}
