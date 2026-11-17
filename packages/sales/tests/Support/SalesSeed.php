<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Pricing\Tests\Support\PricingSeed;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's sales tables, and the few tables Sales reads
 * without a key, written directly (T-030 plan section 3, the `CustomersSeed`
 * shape).
 *
 * **Direct inserts, deliberately, and not this package's own handlers.** A
 * handler test seeded through another handler would be answering two questions
 * at once. Foreign keys are enforced on the fixture connection
 * (`AccessTenantFixture::enter()`), so a seed naming a row that does not exist
 * fails here rather than producing a row production could not hold.
 *
 * Branches, employees, roles and permissions come from `AccessSeed`, and
 * products, units and tax groups from `PricingSeed` (R-7's grant). The
 * generated `open_terminal_key` is never written: the database computes it.
 */
final class SalesSeed
{
    public const NOW = '2026-11-12 09:00:00.000';

    public static function connection(): Connection
    {
        return AccessSeed::connection();
    }

    public static function branch(): string
    {
        return AccessSeed::branch();
    }

    public static function employee(string $code): string
    {
        return AccessSeed::employee($code);
    }

    public static function location(string $branchId, string $code = 'MAIN'): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('locations')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'code' => $code,
            'name' => 'Location '.$code,
            'type' => 'branch_store',
            'allow_negative_stock' => false,
            'is_sellable_from' => true,
            'is_purchasable_to' => true,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $id;
    }

    /**
     * A terminal with its credential, `active` unless asked otherwise (the pair
     * `TerminalAuthority::activeBranchOf()` reads).
     *
     * @return array{id: string, location: string}
     */
    public static function terminal(string $branchId, string $code = 'T1', string $status = 'active', string $credential = 'active'): array
    {
        $location = self::location($branchId, 'L'.$code);
        $id = (string) Str::ulid();

        self::connection()->table('terminals')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'location_id' => $location,
            'code' => $code,
            'name' => 'Terminal '.$code,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        self::connection()->table('terminal_credentials')->insert([
            'terminal_id' => $id,
            'status' => $credential,
            'activated_at' => self::NOW,
        ]);

        return ['id' => $id, 'location' => $location];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function paymentMethod(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('payment_methods')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'code' => $code,
            'name' => 'Method '.$code,
            'type' => 'cash',
            'is_active' => true,
            'opens_drawer' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'allows_refund' => true,
            'allows_partial' => true,
            'rounding_rule' => null,
            'surcharge_percent' => null,
            'sort_order' => 0,
            'version' => 1,
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function shift(string $terminalId, string $branchId, string $employeeId, string $number, array $overrides = []): string
    {
        $row = array_merge([
            'id' => (string) Str::ulid(),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'terminal_id' => $terminalId,
            'branch_id' => $branchId,
            'employee_id' => $employeeId,
            'number' => $number,
            'status' => 'open',
            'opened_at' => self::NOW,
            'closed_at' => null,
            'opening_cash' => '100.0000',
        ], $overrides);

        self::connection()->table('cash_shifts')->insert($row);

        return (string) $row['id'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function movement(string $shiftId, string $employeeId, string $type, string $amount, array $overrides = []): string
    {
        $row = array_merge([
            'id' => (string) Str::ulid(),
            'shift_id' => $shiftId,
            'type' => $type,
            'amount' => $amount,
            'reason' => 'seeded',
            'reference' => null,
            'employee_id' => $employeeId,
            'approved_by' => null,
            'created_at' => self::NOW,
        ], $overrides);

        self::connection()->table('cash_movements')->insert($row);

        return (string) $row['id'];
    }

    /**
     * A product, its unit and a tax group, enough to hang a sale line on.
     *
     * @return array{product: string, unit: string, taxGroup: string}
     */
    public static function catalogue(string $suffix = '1'): array
    {
        $unit = PricingSeed::unit('U'.$suffix);

        return [
            'product' => PricingSeed::product('SKU'.$suffix, $unit),
            'unit' => $unit,
            'taxGroup' => PricingSeed::group('TG'.$suffix),
        ];
    }

    /**
     * A `draft` sale at zero, ready for lines and tenders.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function sale(string $branchId, string $locationId, string $employeeId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('sales')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'shift_id' => null,
            'employee_id' => $employeeId,
            'number' => null,
            'type' => 'sale',
            'status' => 'draft',
            'business_date' => '2026-11-12',
            'business_date_source' => 'server',
            'currency' => 'LKR',
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'service_charge' => '0.0000',
            'rounding' => '0.0000',
            'total' => '0.0000',
            'paid_total' => '0.0000',
            'change_due' => '0.0000',
        ], $overrides));

        return $id;
    }

    /**
     * @param  array{product: string, unit: string, taxGroup: string}  $catalogue
     * @param  array<string, mixed>  $overrides
     */
    public static function line(string $saleId, array $catalogue, int $lineNo, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('sale_items')->insert(array_merge([
            'id' => $id,
            'sale_id' => $saleId,
            'line_no' => $lineNo,
            'product_id' => $catalogue['product'],
            'product_name' => 'Product',
            'unit_code' => 'U1',
            'unit_id' => $catalogue['unit'],
            'quantity' => '1.0000',
            'unit_factor' => '1.000000',
            'base_quantity' => '1.0000',
            'list_price' => '0.0000',
            'unit_price' => '0.0000',
            'subtotal' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_group_id' => $catalogue['taxGroup'],
            'tax_amount' => '0.0000',
            'tax_breakdown' => '[]',
            'line_total' => '0.0000',
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function tender(string $saleId, string $methodId, string $methodType, string $amount, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('sale_tenders')->insert(array_merge([
            'id' => $id,
            'sale_id' => $saleId,
            'payment_method_id' => $methodId,
            'method_code' => 'M-'.$methodType,
            'method_type' => $methodType,
            'amount' => $amount,
            'tendered' => null,
            'change_given' => '0.0000',
            'reference' => null,
            'payment_status' => 'recorded',
            'paid_at' => self::NOW,
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function approval(string $branchId, string $requestedBy, array $overrides = []): string
    {
        $row = array_merge([
            'id' => (string) Str::ulid(),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'branch_id' => $branchId,
            'type' => 'void',
            'reference_type' => 'sale',
            'reference_id' => null,
            'requested_by' => $requestedBy,
            'payload' => null,
            'status' => 'pending',
        ], $overrides);

        self::connection()->table('approval_requests')->insert($row);

        return (string) $row['id'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(string $table, string $id): array
    {
        $row = self::connection()->table($table)->where('id', $id)->first();

        Assert::assertNotNull($row, "No {$table} row has id {$id}.");

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
     * `changed_fields` decoded.
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
