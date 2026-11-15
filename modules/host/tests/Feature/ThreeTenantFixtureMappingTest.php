<?php

use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Testing\Engine\ReservedDatabaseName;
use Flexipos\Testing\Engine\ReservedDatabaseNameViolation;
use Flexipos\Testing\Engine\ThreeTenantFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The three-tenant fixture's mapping half, which needs no engine
|--------------------------------------------------------------------------
|
| T-008 step 8. ThreeTenantFixture has two halves on purpose: the databases,
| which are engine work and wait behind the hold, and the **mapping**, which is
| three inserts into the master - in the suite, in-memory SQLite. This file is
| the second half, and it is the half every planning criterion in steps 9 to 12
| reads from: a tenant at version 0 and two at the baseline's version, on one
| server, with distinct ascending sizes, is the dataset AC-40's selection and
| ordering are about.
|
| It is **not** evidence for any [E] criterion. Nothing here creates a database,
| nothing here connects to 127.0.0.1:3306, and the engine half is recorded NOT
| VERIFIED under ENGINE_HOLD, waiting on gate G-1.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

beforeEach(function () {
    // The master is the suite's stand-in, asserted before anything migrates:
    // an ambient DB_MASTER_DRIVER beats phpunit.xml's <env>, so without this a
    // developer's shell could point these inserts at the shared engine.
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
});

it('maps three tenants onto one server, one of them empty', function () {
    $baseline = MigrationSet::baseline(base_path((string) config('schema.tenant_migration_path')));

    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    $fixture->mapInto(DB::connection('master'), $baseline->highestVersion());

    $rows = DB::connection('master')->table('tenant_databases')->orderBy('size_bytes')->get();

    expect($rows)->toHaveCount(3)
        // One server row, which is what makes AC-50's per-server cap a
        // statement about this fixture at all.
        ->and(DB::connection('master')->table('database_servers')->count())->toBe(1)
        ->and(array_unique(array_map(static fn ($row): string => (string) $row->server_id, $rows->all())))
        ->toHaveCount(1);

    // Tenant A is the one a run has something to do to; the other two already
    // carry the baseline. A dataset where every tenant was behind would let a
    // selector that ignored the version pass.
    $versions = [];

    foreach ($rows as $row) {
        $versions[(string) $row->tenant_id] = (int) $row->schema_version;
    }

    expect($versions[ThreeTenantFixture::EMPTY_TENANT])->toBe(0)
        ->and($versions[ThreeTenantFixture::FAILING_TENANT])->toBe($baseline->highestVersion())
        ->and($baseline->highestVersion())->toBeGreaterThan(0);

    // Ascending and distinct, because AC-40 orders the jobs of a run by it and
    // equal sizes would make any order pass.
    $sizes = array_map(static fn ($row): int => (int) $row->size_bytes, $rows->all());

    expect($sizes)->toBe(array_values(array_unique($sizes)));
});

it('names only reserved databases, and a different one per tenant', function () {
    $fixture = new ThreeTenantFixture(base_path((string) config('schema.tenant_migration_path')));

    $names = $fixture->databaseNames();

    expect($names)->toHaveCount(3)
        ->and(array_unique($names))->toHaveCount(3);

    foreach ($names as $name) {
        // The guard itself, not a prefix comparison written here: a name this
        // fixture could produce but the guard would refuse is a name that
        // cannot be created and cannot be dropped, which is a fixture that
        // leaves databases behind.
        ReservedDatabaseName::assert($name);

        expect(ReservedDatabaseName::isReserved($name))->toBeTrue();
    }

    // And a tenant it does not own has no database name at all, rather than
    // one built out of whatever string it was handed.
    expect(fn () => $fixture->databaseNameFor('01J0000000000000000000000Z'))
        ->toThrow(ReservedDatabaseNameViolation::class);
});
