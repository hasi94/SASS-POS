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
     * One row by id, as an array; the test fails when there is none.
     *
     * @return array<string, mixed>
     */
    public static function row(string $table, string $id): array
    {
        return PricingSeed::row($table, $id);
    }

    /**
     * Every audit row, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function audit(): array
    {
        return PricingSeed::audit();
    }

    /**
     * The one audit row an operation wrote, whole, with its JSON decoded.
     *
     * @return array<string, mixed>
     */
    public static function onlyAuditRow(): array
    {
        return PricingSeed::onlyAuditRow();
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
     * A supplier row written directly, active and undeleted unless overridden.
     * The code carries a counter so two seeds never collide.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function supplier(string $code = 'SUP', array $overrides = []): string
    {
        static $counter = 0;

        $counter++;

        $id = (string) Str::ulid();

        self::connection()->table('suppliers')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'code' => strtoupper($code).$counter,
            'name' => 'Supplier '.$code,
            'legal_name' => null,
            'contact_name' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'tax_no' => null,
            'currency' => null,
            'payment_terms_days' => 0,
            'credit_limit' => '0.0000',
            'bank_details' => null,
            'is_active' => true,
            'notes' => null,
        ], $overrides));

        return $id;
    }

    /**
     * A purchase request header written directly, in `draft` unless overridden.
     * The number carries a counter so two seeds never collide.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function request(string $branchId, string $locationId, string $requestedBy, array $overrides = []): string
    {
        static $counter = 0;

        $counter++;

        $id = (string) Str::ulid();

        self::connection()->table('purchase_requests')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'created_by' => $requestedBy,
            'updated_by' => $requestedBy,
            'number' => 'PRS'.str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'requested_by' => $requestedBy,
            'required_date' => null,
            'status' => 'draft',
            'workflow_instance_id' => null,
            'notes' => null,
        ], $overrides));

        return $id;
    }

    /**
     * A purchase request line written directly.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function requestItem(string $requestId, string $productId, string $unitId, int $lineNo = 1, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('purchase_request_items')->insert(array_merge([
            'id' => $id,
            'request_id' => $requestId,
            'line_no' => $lineNo,
            'product_id' => $productId,
            'unit_id' => $unitId,
            'quantity' => '1.0000',
            'unit_factor' => '1.000000',
            'base_quantity' => '1.0000',
            'estimated_cost' => null,
            'supplier_id' => null,
            'purchase_order_item_id' => null,
        ], $overrides));

        return $id;
    }

    /**
     * A purchase order header written directly, in `draft` and at zero unless
     * overridden. The number carries a counter so two seeds never collide.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function order(string $supplierId, string $branchId, string $locationId, array $overrides = []): string
    {
        static $counter = 0;

        $counter++;

        $id = (string) Str::ulid();

        self::connection()->table('purchase_orders')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'created_by' => null,
            'updated_by' => null,
            'number' => 'POS'.str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'supplier_id' => $supplierId,
            'supplier_name' => 'Supplier snapshot',
            'supplier_tax_no' => null,
            'payment_terms_days' => 0,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'request_id' => null,
            'order_date' => self::DATE,
            'expected_date' => null,
            'status' => 'draft',
            'workflow_instance_id' => null,
            'currency' => 'LKR',
            'exchange_rate' => '1.00000000',
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'shipping' => '0.0000',
            'total' => '0.0000',
            'received_value' => '0.0000',
            'terms' => null,
            'notes' => null,
        ], $overrides));

        return $id;
    }

    /**
     * A purchase order line written directly: one each at 1.0000, cost 10.0000,
     * under the tax group given, nothing received. The figures are the caller's to
     * override; nothing here prices anything.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function orderItem(string $orderId, string $productId, string $unitId, string $taxGroupId, int $lineNo = 1, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('purchase_order_items')->insert(array_merge([
            'id' => $id,
            'order_id' => $orderId,
            'line_no' => $lineNo,
            'product_id' => $productId,
            'description' => 'Line '.$lineNo,
            'unit_id' => $unitId,
            'quantity' => '1.0000',
            'unit_factor' => '1.000000',
            'base_quantity' => '1.0000',
            'unit_cost' => '10.0000',
            'subtotal' => '10.0000',
            'discount_percent' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_group_id' => $taxGroupId,
            'tax_amount' => '1.0000',
            'line_total' => '11.0000',
            'received_quantity' => '0.0000',
            'received_base_quantity' => '0.0000',
            'expected_date' => null,
        ], $overrides));

        return $id;
    }

    /**
     * A one-step workflow definition for a document type, written directly: it
     * matches an amount of at least `$minAmount`, is approved by any holder of
     * `$roleId` (never the requester), and on rejection does `$onReject`
     * (`stop` or `return_to_requester`).
     *
     * Direct inserts, because the test tree may not import the workflow package;
     * the real engine reads these rows exactly as it reads a registered definition.
     */
    public static function workflowDefinition(string $entityType, string $minAmount, string $onReject, string $roleId): string
    {
        static $counter = 0;

        $counter++;

        $id = (string) Str::ulid();

        self::connection()->table('workflow_definitions')->insert([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'key' => 'pu-'.str_replace('_', '-', $entityType).'-'.$counter,
            'name' => 'Purchasing '.$entityType.' approval',
            'entity_type' => $entityType,
            'conditions' => json_encode([['field' => 'amount', 'operator' => '>=', 'value' => $minAmount]], JSON_THROW_ON_ERROR),
            'branch_ids' => null,
            'priority' => 10,
            'on_reject' => $onReject,
            'escalation_hours' => null,
            'is_active' => true,
            'plugin_name' => null,
            'version' => 1,
        ]);

        self::connection()->table('workflow_steps')->insert([
            'id' => (string) Str::ulid(),
            'definition_id' => $id,
            'sequence' => 1,
            'name' => 'Approve',
            'approver_type' => 'role',
            'approver_ref' => json_encode(['roles' => [$roleId]], JSON_THROW_ON_ERROR),
            'quorum' => 'any',
            'can_edit' => false,
            'can_skip_if' => null,
            'sla_hours' => null,
            'escalate_to' => null,
        ]);

        return $id;
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
