<?php

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * T-021 AC-26: the idempotency_keys migration, applied to a SQLite
 * stand-in (the T-020 WorkflowTenantSchemaTest shape).
 *
 * Lives under modules/host/tests, not packages/kernel/tests: the kernel's
 * own test corpus restricts reading the shipped tenant tree path to one
 * entry point (QaFixtureBaselineSweepsTest's AC-8), and this is a product
 * source proving a product migration, the same place
 * FixtureMigrationSetsTest and TenantBaselineBuildsTest already live.
 */

function idemSchemaConnection(): string
{
    return 'idem_schema_stand_in';
}

function idemApplyTenantTree(): void
{
    config()->set('database.connections.'.idemSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(idemSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => idemSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

it('creates idempotency_keys with exactly the documented columns, sorted', function () {
    idemApplyTenantTree();

    $names = array_column(Schema::connection(idemSchemaConnection())->getColumns('idempotency_keys'), 'name');
    sort($names);

    expect($names)->toBe(['created_at', 'key', 'request_hash', 'response_body', 'response_code']);
});

it('has response_code and response_body nullable, and key as the primary key', function () {
    idemApplyTenantTree();

    $columns = Schema::connection(idemSchemaConnection())->getColumns('idempotency_keys');
    $byName = [];

    foreach ($columns as $column) {
        $byName[$column['name']] = $column;
    }

    expect($byName['response_code']['nullable'])->toBeTrue()
        ->and($byName['response_body']['nullable'])->toBeTrue()
        ->and($byName['key']['nullable'])->toBeFalse();

    $indexes = Schema::connection(idemSchemaConnection())->getIndexes('idempotency_keys');
    $primary = array_values(array_filter($indexes, fn (array $i) => $i['primary']))[0] ?? null;

    expect($primary)->not->toBeNull()
        ->and($primary['columns'])->toBe(['key']);

    $createdAtIndex = array_values(array_filter(
        $indexes,
        fn (array $i) => ! $i['primary'] && $i['columns'] === ['created_at']
    ));

    expect($createdAtIndex)->not->toBe([]);
});

it('runs a second migrate with no error', function () {
    idemApplyTenantTree();

    expect(Artisan::call('migrate', [
        '--database' => idemSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
});

it('has no down() and passes SourceRules::inspect()', function () {
    $file = base_path('database/migrations-tenant/2026_10_30_001001_create_idempotency_keys_table.php');
    $source = (string) file_get_contents($file);

    expect($source)->not->toContain('public function down')
        ->and(SourceRules::inspect($source, MigrationTree::Tenant))->toBe([]);
});

it('is the catalogue top entry, phase expand, version 43', function () {
    // T-029 step 3 republished the catalogue with its own twelve migrations
    // on top (51 becomes 63), the last of which is a contract step (the two
    // product tax group keys), so the phase asserted below is no longer
    // expand either. This test's name is now two tasks stale - stated here
    // rather than renamed, for the reason the T-028 note below gives.
    // T-028 step 3 republished the catalogue with its own eight migrations
    // on top (43 becomes 51), the way T-027 did the same to this very test
    // when it landed on top of this file's own idempotency_keys migration.
    // This test's own name is now one task stale - stated here rather than
    // renamed, so the "whoever ships next updates this" pattern stays
    // visible in the diff instead of being silently absorbed.
    $catalogue = json_decode(
        (string) file_get_contents(base_path('database/catalogue/tenant_schema_versions.json')),
        true
    );

    // T-030 steps 6 and 7 republished the catalogue with its own ten
    // migrations on top (63 becomes 73), the last of which is a contract step
    // (the one loyalty_transactions.sale_id key). Stale by name again, left
    // alone for the reason the notes above give.
    // T-034 step 4 does it again: its six purchasing tables put the top at 79,
    // an expand step. This is the one place a T-034 file is pinned as the top.
    $top = end($catalogue);

    expect($top['version'])->toBe(79)
        ->and($top['migration'])->toBe('2026_11_16_003406_create_purchase_order_receipt_applications_table')
        ->and($top['phase'])->toBe('expand');
});
