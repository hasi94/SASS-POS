<?php

declare(strict_types=1);

namespace Flexipos\Customers\Tests\Support;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Rows in a fixture tenant's customers tables, written directly (T-028 plan
 * section 3, the `CatalogSeed`/`OrganizationSeed`/`AccessSeed` shape).
 *
 * **Direct inserts, deliberately, and not this task's own handlers.** A
 * handler test seeded through another handler would be answering two
 * questions at once. Foreign keys are enforced on the fixture connection
 * ({@see AccessTenantFixture::enter()}), so
 * a seed naming a row that does not exist fails here rather than producing
 * a row production could not hold.
 */
final class CustomersSeed
{
    public const NOW = '2026-11-10 09:00:00.000';

    public static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection((string) config('tenancy.connection', 'tenant'));

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function group(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customer_groups')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'code' => $code,
            'name' => 'Group '.$code,
            'discount_percent' => null,
            'credit_allowed' => false,
            'default_credit_limit' => null,
            'default_payment_terms_days' => null,
            'is_default' => false,
            'loyalty_multiplier' => null,
            'version' => 1,
        ], $overrides));

        return $id;
    }

    /**
     * A minimal, valid customer with a currency set (so it can take a
     * ledger entry without a further update).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function customer(string $name = 'Jane Doe', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customers')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'created_by' => null,
            'updated_by' => null,
            'origin' => 'online',
            'terminal_id' => null,
            'device_id' => null,
            'client_seq' => null,
            'client_created_at' => null,
            'sync_status' => 'synced',
            'synced_at' => null,
            'group_id' => null,
            'code' => null,
            'name' => $name,
            'phone' => null,
            'email' => null,
            'address' => null,
            'dob' => null,
            'gender' => null,
            'tax_no' => null,
            'company_name' => null,
            'currency' => 'USD',
            'credit_allowed' => true,
            'credit_limit' => '1000.0000',
            'credit_balance' => '0.0000',
            'payment_terms_days' => null,
            'loyalty_points' => '0.0000',
            'loyalty_tier' => null,
            'opening_balance' => '0.0000',
            'status' => 'active',
            'blocked_reason' => null,
            'possible_duplicate_of' => null,
            'merged_into_id' => null,
            'first_sale_at' => null,
            'last_sale_at' => null,
            'lifetime_value' => '0.0000',
            'notes' => null,
            'client_updated_at' => null,
            'version' => 1,
        ], $overrides));

        return $id;
    }

    /**
     * Directly a `customer_ledger` row, bypassing the writer, as the
     * backstop seam the plan's step 10 names (AC-21's "the backstop": seed
     * the row with a raw insert, so the pre-check is bypassed in a test
     * seam).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function ledgerEntry(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customer_ledger')->insert(array_merge([
            'id' => $id,
            'customer_id' => $customerId,
            'branch_id' => null,
            'type' => 'opening',
            'reference_type' => 'customer',
            'reference_id' => $customerId,
            'debit' => '0.0000',
            'credit' => '0.0000',
            'balance_after' => '0.0000',
            'currency' => 'USD',
            'employee_id' => null,
            'notes' => null,
            'created_at' => self::NOW,
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
     * and `changed_fields` decoded: compared as a whole row.
     *
     * @return array<string, mixed>
     */
    public static function onlyAuditRow(): array
    {
        $rows = self::audit();

        Assert::assertCount(1, $rows, 'Exactly one audit row was expected.');

        return self::decodeAuditRow($rows[0]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function decodeAuditRow(array $row): array
    {
        unset($row['id'], $row['created_at'], $row['ip']);

        foreach (['before', 'after', 'changed_fields'] as $json) {
            $row[$json] = $row[$json] === null ? null : json_decode((string) $row[$json], true);
        }

        return $row;
    }
}
