<?php

namespace Flexipos\Customers\Tests\Schema;

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The eight customer tables, applied to a SQLite stand-in (T-028 plan
| step 3, the T-027 shape)
|--------------------------------------------------------------------------
|
| AC-2, AC-6, AC-17, AC-21 and AC-40's schema halves; AC-15, AC-25, AC-33
| and AC-36 shown by the exact absence of the four D-6 columns, and of
| custom_fields and sale_id.
|
| No lengths or precisions are asserted here: SQLite reports bare types,
| and TenantDecimalPrecisionTest (step 4) handles those statically.
*/

function customersSchemaConnection(): string
{
    return 'customers_schema_stand_in';
}

function customersApplyTenantTree(): void
{
    config()->set('database.connections.'.customersSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(customersSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => customersSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function customersColumns(string $table): array
{
    $names = array_column(Schema::connection(customersSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function customersNullableColumns(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(customersSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

function customersNow(): string
{
    return '2026-11-10 00:00:00.000';
}

/**
 * Minimal seed rows this file's inserts build on: a branch, an employee, a
 * customer group and a live customer.
 *
 * @return array{branch: string, employee: string, group: string, customer: string}
 */
function customersSeedMinimum(): array
{
    $connection = DB::connection(customersSchemaConnection());
    $now = customersNow();

    $branch = str_pad('br1', 26, 'x');
    $connection->table('branches')->insert([
        'id' => $branch, 'code' => 'br1', 'name' => 'Branch 1', 'timezone' => 'UTC',
        'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $employee = str_pad('emp1', 26, 'x');
    $connection->table('employees')->insert([
        'id' => $employee, 'code' => 'e1', 'first_name' => 'Jo', 'display_name' => 'Jo',
        'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $group = str_pad('grp1', 26, 'x');
    $connection->table('customer_groups')->insert([
        'id' => $group, 'created_at' => $now, 'updated_at' => $now, 'code' => 'g1', 'name' => 'Group 1',
    ]);

    $customer = str_pad('cust1', 26, 'x');
    $connection->table('customers')->insert([
        'id' => $customer, 'created_at' => $now, 'updated_at' => $now,
        'name' => 'Customer 1', 'group_id' => $group,
    ]);

    return ['branch' => $branch, 'employee' => $employee, 'group' => $group, 'customer' => $customer];
}

it('creates customer_groups with exactly the documented columns', function () {
    customersApplyTenantTree();

    expect(customersColumns('customer_groups'))->toBe([
        'code', 'created_at', 'credit_allowed', 'default_credit_limit', 'default_marker',
        'default_payment_terms_days', 'deleted_at', 'discount_percent', 'id', 'is_default',
        'loyalty_multiplier', 'name', 'updated_at', 'version',
    ])->and(customersNullableColumns('customer_groups'))->toBe([
        'default_credit_limit', 'default_marker', 'default_payment_terms_days', 'deleted_at', 'discount_percent', 'loyalty_multiplier',
    ]);
});

it('creates customers with exactly the documented columns, no custom_fields and no deferred FK column (AC-15)', function () {
    customersApplyTenantTree();

    expect(customersColumns('customers'))->toBe([
        'address', 'blocked_reason', 'client_created_at', 'client_seq', 'client_updated_at', 'code',
        'company_name', 'created_at', 'created_by', 'credit_allowed', 'credit_balance', 'credit_limit',
        'currency', 'deleted_at', 'device_id', 'dob', 'email', 'first_sale_at', 'gender', 'group_id', 'id',
        'last_sale_at', 'lifetime_value', 'loyalty_points', 'loyalty_tier', 'merged_into_id', 'name',
        'notes', 'opening_balance', 'origin', 'payment_terms_days', 'phone', 'possible_duplicate_of',
        'status', 'sync_status', 'synced_at', 'tax_no', 'terminal_id', 'updated_at', 'updated_by',
        'version',
    ])->and(customersColumns('customers'))->not->toContain('custom_fields')
        ->and(customersColumns('customers'))->not->toContain('receivable_account_id');
});

it('creates customer_addresses with exactly the documented columns, no [SOFT] (AC-18, D-16)', function () {
    customersApplyTenantTree();

    expect(customersColumns('customer_addresses'))->toBe([
        'address', 'created_at', 'customer_id', 'default_customer_id', 'geo', 'id', 'is_default',
        'label', 'type', 'updated_at',
    ])->and(customersColumns('customer_addresses'))->not->toContain('deleted_at');
});

it('creates customer_ledger with exactly the documented columns, no ar_document_id (AC-25)', function () {
    customersApplyTenantTree();

    expect(customersColumns('customer_ledger'))->toBe([
        'balance_after', 'branch_id', 'created_at', 'credit', 'currency', 'customer_id', 'debit',
        'employee_id', 'id', 'notes', 'reference_id', 'reference_type', 'type',
    ])->and(customersColumns('customer_ledger'))->not->toContain('ar_document_id')
        ->and(customersColumns('customer_ledger'))->not->toContain('updated_at');
});

it('creates loyalty_programs with exactly the documented columns, no [SOFT] (D-23, F-6)', function () {
    customersApplyTenantTree();

    expect(customersColumns('loyalty_programs'))->toBe([
        'created_at', 'earn_rule', 'expiry_months', 'id', 'is_active', 'name', 'redeem_rule',
        'tier_rules', 'updated_at',
    ])->and(customersColumns('loyalty_programs'))->not->toContain('deleted_at');
});

// T-030 AC-45 INVERTS T-028's AC-33 absence assertion: `sale_id` (nullable, with its
// unique (sale_id, type) key and, from the contract migration, its key onto `sales`) is
// built now that `sales` exists. The rest of the column list is unchanged.
it('creates loyalty_transactions with exactly the documented columns, sale_id included since T-030 (AC-33, inverted by T-030 AC-45)', function () {
    customersApplyTenantTree();

    expect(customersColumns('loyalty_transactions'))->toBe([
        'balance_after', 'client_created_at', 'client_seq', 'created_at', 'customer_id', 'device_id',
        'employee_id', 'expires_at', 'id', 'notes', 'origin', 'points', 'program_id', 'sale_id', 'sync_status',
        'synced_at', 'terminal_id', 'type', 'value',
    ])->and(customersColumns('loyalty_transactions'))->toContain('sale_id')
        ->and(customersColumns('loyalty_transactions'))->not->toContain('updated_at');
});

it('creates customer_notes with exactly the documented columns', function () {
    customersApplyTenantTree();

    expect(customersColumns('customer_notes'))->toBe([
        'created_at', 'created_by', 'customer_id', 'follow_up_date', 'id', 'note', 'pinned',
        'updated_at', 'updated_by',
    ]);
});

it('creates customer_communications with exactly the documented columns, no delivery_id (AC-36)', function () {
    customersApplyTenantTree();

    expect(customersColumns('customer_communications'))->toBe([
        'body', 'campaign_ref', 'channel', 'created_at', 'customer_id', 'direction', 'employee_id', 'id',
        'occurred_at', 'subject', 'updated_at',
    ])->and(customersColumns('customer_communications'))->not->toContain('delivery_id');
});

it('holds the customer_groups.code unique key and the default_marker backstop (AC-1, AC-2)', function () {
    customersApplyTenantTree();
    $connection = DB::connection(customersSchemaConnection());
    $now = customersNow();

    $connection->table('customer_groups')->insert([
        'id' => str_pad('g1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'wholesale', 'name' => 'Wholesale',
    ]);

    expect(fn () => $connection->table('customer_groups')->insert([
        'id' => str_pad('g2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'wholesale', 'name' => 'Wholesale again',
    ]))->toThrow(QueryException::class);

    // default_marker backstop: a second default group refuses, two non-default groups coexist.
    $connection->table('customer_groups')->insert([
        'id' => str_pad('g3', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'default-a', 'name' => 'Default A', 'is_default' => true,
    ]);

    expect(fn () => $connection->table('customer_groups')->insert([
        'id' => str_pad('g4', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'default-b', 'name' => 'Default B', 'is_default' => true,
    ]))->toThrow(QueryException::class);

    $connection->table('customer_groups')->insert([
        'id' => str_pad('g5', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'plain-a', 'name' => 'Plain A',
    ]);
    $connection->table('customer_groups')->insert([
        'id' => str_pad('g6', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'code' => 'plain-b', 'name' => 'Plain B',
    ]);

    expect($connection->table('customer_groups')->where('is_default', true)->count())->toBe(1);
});

it('holds customers.code unique, with two NULL codes accepted (AC-6)', function () {
    customersApplyTenantTree();
    $connection = DB::connection(customersSchemaConnection());
    $now = customersNow();

    $connection->table('customers')->insert([
        'id' => str_pad('c1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'name' => 'C1', 'code' => 'c1',
    ]);

    expect(fn () => $connection->table('customers')->insert([
        'id' => str_pad('c2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'name' => 'C2 again', 'code' => 'c1',
    ]))->toThrow(QueryException::class);

    // Two NULL codes coexist.
    $connection->table('customers')->insert([
        'id' => str_pad('c3', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'name' => 'C3',
    ]);
    $connection->table('customers')->insert([
        'id' => str_pad('c4', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'name' => 'C4',
    ]);

    expect($connection->table('customers')->whereNull('code')->count())->toBe(2);
});

it('holds the customer_ledger (reference_type, reference_id, type) unique key (AC-21)', function () {
    customersApplyTenantTree();
    $seed = customersSeedMinimum();
    $connection = DB::connection(customersSchemaConnection());
    $now = customersNow();

    $connection->table('customer_ledger')->insert([
        'id' => str_pad('led1', 26, 'x'), 'customer_id' => $seed['customer'], 'type' => 'payment',
        'reference_type' => 'sale', 'reference_id' => str_pad('ref1', 26, 'x'),
        'debit' => 0, 'credit' => 10, 'balance_after' => -10, 'currency' => 'USD', 'created_at' => $now,
    ]);

    expect(fn () => $connection->table('customer_ledger')->insert([
        'id' => str_pad('led2', 26, 'x'), 'customer_id' => $seed['customer'], 'type' => 'payment',
        'reference_type' => 'sale', 'reference_id' => str_pad('ref1', 26, 'x'),
        'debit' => 0, 'credit' => 20, 'balance_after' => -30, 'currency' => 'USD', 'created_at' => $now,
    ]))->toThrow(QueryException::class);

    // A different type for the same reference pair is a different key.
    $connection->table('customer_ledger')->insert([
        'id' => str_pad('led3', 26, 'x'), 'customer_id' => $seed['customer'], 'type' => 'adjustment',
        'reference_type' => 'sale', 'reference_id' => str_pad('ref1', 26, 'x'),
        'debit' => 0, 'credit' => 5, 'balance_after' => -35, 'currency' => 'USD', 'created_at' => $now,
    ]);

    expect($connection->table('customer_ledger')->count())->toBe(2);
});

it('holds exactly one default billing address and one default delivery address per customer (AC-17)', function () {
    customersApplyTenantTree();
    $seed = customersSeedMinimum();
    $connection = DB::connection(customersSchemaConnection());
    $now = customersNow();

    $connection->table('customer_addresses')->insert([
        'id' => str_pad('addr1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'customer_id' => $seed['customer'], 'type' => 'billing', 'is_default' => true,
    ]);

    // A second default billing address for the SAME customer refuses.
    expect(fn () => $connection->table('customer_addresses')->insert([
        'id' => str_pad('addr2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'customer_id' => $seed['customer'], 'type' => 'billing', 'is_default' => true,
    ]))->toThrow(QueryException::class);

    // A default delivery address for the same customer coexists.
    $connection->table('customer_addresses')->insert([
        'id' => str_pad('addr3', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'customer_id' => $seed['customer'], 'type' => 'delivery', 'is_default' => true,
    ]);

    // Another customer's default billing address is unaffected.
    $connection->table('customers')->insert([
        'id' => str_pad('cust2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'name' => 'Customer 2',
    ]);
    $connection->table('customer_addresses')->insert([
        'id' => str_pad('addr4', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'customer_id' => str_pad('cust2', 26, 'x'), 'type' => 'billing', 'is_default' => true,
    ]);

    expect($connection->table('customer_addresses')->where('is_default', true)->count())->toBe(3);
});

it('holds exactly the 18 documented foreign keys (17 from T-028, 1 from T-030)', function () {
    customersApplyTenantTree();

    $tables = [
        'customer_addresses', 'customer_communications', 'customer_groups', 'customer_ledger',
        'customer_notes', 'customers', 'loyalty_programs', 'loyalty_transactions',
    ];

    $keys = [];

    foreach ($tables as $table) {
        foreach (DB::connection(customersSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'];
        }
    }

    sort($keys);

    expect($keys)->toBe([
        'customer_addresses.customer_id -> customers.id',
        'customer_communications.customer_id -> customers.id',
        'customer_communications.employee_id -> employees.id',
        'customer_ledger.branch_id -> branches.id',
        'customer_ledger.customer_id -> customers.id',
        'customer_ledger.employee_id -> employees.id',
        'customer_notes.created_by -> employees.id',
        'customer_notes.customer_id -> customers.id',
        'customer_notes.updated_by -> employees.id',
        'customers.created_by -> employees.id',
        'customers.group_id -> customer_groups.id',
        'customers.merged_into_id -> customers.id',
        'customers.possible_duplicate_of -> customers.id',
        'customers.updated_by -> employees.id',
        'loyalty_transactions.customer_id -> customers.id',
        'loyalty_transactions.employee_id -> employees.id',
        'loyalty_transactions.program_id -> loyalty_programs.id',
        'loyalty_transactions.sale_id -> sales.id',
    ]);
});

it('cascades both documented deletes: customer_addresses and customer_notes on their customer', function () {
    customersApplyTenantTree();
    $seed = customersSeedMinimum();
    $connection = DB::connection(customersSchemaConnection());
    $now = customersNow();

    $connection->table('customer_addresses')->insert([
        'id' => str_pad('addrc1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'customer_id' => $seed['customer'], 'type' => 'other',
    ]);
    $connection->table('customer_notes')->insert([
        'id' => str_pad('notec1', 26, 'x'), 'created_at' => $now, 'updated_at' => $now,
        'customer_id' => $seed['customer'], 'note' => 'a note',
    ]);

    $connection->table('customers')->where('id', $seed['customer'])->delete();

    expect($connection->table('customer_addresses')->where('id', str_pad('addrc1', 26, 'x'))->exists())->toBeFalse()
        ->and($connection->table('customer_notes')->where('id', str_pad('notec1', 26, 'x'))->exists())->toBeFalse();
});

it('runs cleanly twice as a no-op, and no customers file declares a down()', function () {
    customersApplyTenantTree();

    $before = Schema::connection(customersSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => customersSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(customersSchemaConnection())->getTableListing())->toBe($before);

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0028*.php') as $file) {
        expect(file_get_contents($file))->not->toContain('function down(');
    }
});

it('reports no SourceRules violation across the eight customer migrations', function () {
    $violations = [];

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_10_0028*.php') as $file) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents($file), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});
