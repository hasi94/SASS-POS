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
     * A flat (root, depth 0) account for the receivable column's tests (T-032
     * AC-55, AC-56). It is a **test row written directly**: this package's
     * own source never names `accounts` and reaches it only through
     * `ChartOfAccounts::assignable()`; the fixture template carries the
     * shipped chart, so the codes used here must not be one of its twenty.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function account(string $code, string $type, ?string $subtype, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('accounts')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'deleted_at' => null,
            'code' => $code,
            'name' => 'Account '.$code,
            'type' => $type,
            'subtype' => $subtype,
            'parent_id' => null,
            'path' => $id,
            'depth' => 0,
            'normal_balance' => in_array($type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit',
            'is_postable' => true,
            'is_system' => false,
            'control_type' => 'none',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));

        return $id;
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
     * @param  array<string, mixed>  $overrides
     */
    public static function program(string $name = 'Base', array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('loyalty_programs')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'name' => $name,
            'earn_rule' => null,
            'redeem_rule' => null,
            'tier_rules' => null,
            'expiry_months' => null,
            'is_active' => true,
        ], $overrides));

        return $id;
    }

    /**
     * Directly a `loyalty_transactions` row, bypassing the writer, the same
     * backstop seam {@see self::ledgerEntry()} offers the credit ledger.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function loyaltyTransaction(string $customerId, string $programId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('loyalty_transactions')->insert(array_merge([
            'id' => $id,
            'origin' => 'online',
            'terminal_id' => null,
            'device_id' => null,
            'client_seq' => null,
            'client_created_at' => null,
            'sync_status' => 'synced',
            'synced_at' => null,
            'customer_id' => $customerId,
            'program_id' => $programId,
            'type' => 'earn',
            'points' => '0.0000',
            'value' => null,
            'balance_after' => '0.0000',
            'expires_at' => null,
            'employee_id' => null,
            'notes' => null,
            'created_at' => self::NOW,
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function address(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customer_addresses')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'customer_id' => $customerId,
            'type' => 'billing',
            'label' => null,
            'address' => null,
            'is_default' => false,
            'geo' => null,
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function note(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customer_notes')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'created_by' => null,
            'updated_by' => null,
            'customer_id' => $customerId,
            'note' => 'A note.',
            'pinned' => false,
            'follow_up_date' => null,
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function communication(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::ulid();

        self::connection()->table('customer_communications')->insert(array_merge([
            'id' => $id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
            'customer_id' => $customerId,
            'channel' => 'call',
            'direction' => 'out',
            'subject' => null,
            'body' => null,
            'campaign_ref' => null,
            'employee_id' => null,
            'occurred_at' => self::NOW,
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
