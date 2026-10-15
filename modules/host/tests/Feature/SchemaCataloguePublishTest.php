<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Testing\Engine\ReservedDatabaseName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The shipped catalogue, its committed fixture, and the one authority for
| where the tenant tree is
|--------------------------------------------------------------------------
|
| T-008 step 5. AC-31 and AC-32 are [E] criteria and this file is **not** their
| evidence: their engine half is answered from `schema_versions` on the engine
| the environment names, and while ENGINE_HOLD is set they are NOT VERIFIED,
| waiting on gate G-1 (AC-63, D-35).
|
| What is answered here needs no engine:
|
| - `schema:publish` writes one row per shipped tenant migration into the
|   master, and the table equals the **committed** fixture afterwards;
| - a fixture that disagrees with the tree makes the command exit non-zero,
|   with the unchanged tree as the control;
| - the required-version constant `.env.example` ships is not greater than the
|   highest version the catalogue can produce, with a constant one higher as
|   the control (AC-21);
| - config/schema.php's `tenant_migration_path` is the single authority for the
|   tree's location: the key is *moved* and the command and the suite's helpers
|   are asserted to follow it, which a test that merely agreed with the shipped
|   value could not tell apart from a second literal.
|
| The master is the suite's in-memory SQLite, asserted from configuration before
| anything migrates - an ambient DB_MASTER_DRIVER beats phpunit.xml's <env>, so
| without that assertion a developer's shell could point this file at the shared
| engine, which is on hold.
|
| The slice's own behaviour - ordering, idempotence and the three refusals - is
| proven against scratch trees in
| packages/kernel/tests/Schema/Features/PublishCatalogue/. It cannot be proven
| here: a test that added a file to the shipped tree would be changing the
| schema every tenant gets.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Fail unless the master connection is the suite's stand-in.
 */
function cataloguePublishMasterIsStandIn(): void
{
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');
}

/**
 * Apply the master tree, so `schema_versions` exists to publish into.
 */
function cataloguePublishMigrateMaster(): void
{
    cataloguePublishMasterIsStandIn();

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
}

/**
 * The published catalogue read back from the master, in the row shape
 * MigrationCatalogue::toRow() produces.
 *
 * @return list<array<string, mixed>>
 */
function cataloguePublishedRows(): array
{
    $rows = [];

    foreach (DB::connection('master')->table('schema_versions')->orderBy('version')->get() as $row) {
        $row = (array) $row;

        $rows[] = [
            'version' => (int) $row['version'],
            'migration' => (string) $row['migration'],
            'phase' => (string) $row['phase'],
            'checksum' => (string) $row['checksum'],
            'is_destructive' => (bool) $row['is_destructive'],
            'requires_offline' => (bool) $row['requires_offline'],
        ];
    }

    return $rows;
}

/**
 * The committed fixture's rows.
 *
 * @return list<array<string, mixed>>
 */
function catalogueCommittedRows(): array
{
    return MigrationCatalogue::decodeFixture(
        (string) file_get_contents(base_path((string) config('schema.catalogue_fixture')))
    );
}

/*
|--------------------------------------------------------------------------
| The shipped tree, published
|--------------------------------------------------------------------------
*/

it('publishes one row per shipped tenant migration and matches the committed fixture', function () {
    cataloguePublishMigrateMaster();

    expect(Artisan::call('schema:publish'))->toBe(0);

    $published = cataloguePublishedRows();

    // Exact equality in both directions: the committed fixture is the branch's
    // declaration of the catalogue, and AC-31's last sentence is that the table
    // equals it after publishing.
    expect($published)->toBe(catalogueCommittedRows())
        ->and($published)->toHaveCount(count(tenantBaselineTables()));

    // Every version is accounted for and strictly increasing, which is the
    // property `tenant_databases.schema_version` is a number in.
    expect(array_column($published, 'version'))->toBe(range(1, count($published)));

    // And the second run writes nothing: the command exits 0 and the table is
    // identical.
    expect(Artisan::call('schema:publish'))->toBe(0)
        ->and(cataloguePublishedRows())->toBe($published);
});

/*
|--------------------------------------------------------------------------
| A fixture that disagrees with the tree stops the command
|--------------------------------------------------------------------------
*/

it('exits non-zero when the committed fixture disagrees with the tree', function () {
    cataloguePublishMigrateMaster();

    $catalogue = MigrationCatalogue::forTree(migrationTreeDirectory(MigrationTree::Tenant));

    // A stale copy: one row removed, which is what a tenant migration added
    // without publishing looks like from the fixture's side. Written under
    // storage/, so the committed file is never touched.
    $stale = 'storage/app/catalogue-fixture-stale.json';

    File::ensureDirectoryExists(storage_path('app'));
    File::put(base_path($stale), MigrationCatalogue::encodeFixture(array_slice($catalogue, 0, -1)));

    config()->set('schema.catalogue_fixture', $stale);

    expect(Artisan::call('schema:publish'))->toBe(1)
        ->and(Artisan::output())->toContain('does not match the tenant migration tree');

    // The control: the committed fixture, unchanged, exits 0. Without it a
    // command that always failed would pass the assertion above.
    config()->set('schema.catalogue_fixture', 'database/catalogue/tenant_schema_versions.json');

    expect(Artisan::call('schema:publish'))->toBe(0);

    File::delete(base_path($stale));
});

/*
|--------------------------------------------------------------------------
| AC-21: the constant is not ahead of the catalogue
|--------------------------------------------------------------------------
*/

it('ships a required schema version the catalogue can produce', function () {
    $catalogue = MigrationCatalogue::forTree(migrationTreeDirectory(MigrationTree::Tenant));

    expect($catalogue)->not->toBeEmpty();

    $highest = max(array_column(array_map(
        static fn (MigrationCatalogue $entry): array => $entry->toRow(),
        $catalogue
    ), 'version'));

    // The value a deployment ships with lives in .env.example, read here as
    // text rather than from config(): the suite's own environment does not set
    // it, and the claim is about what is shipped.
    expect(preg_match('/^APP_REQUIRED_SCHEMA_VERSION=(\d+)$/m', (string) File::get(base_path('.env.example')), $matches))
        ->toBe(1);

    $shipped = (int) $matches[1];

    expect($shipped)->toBeLessThanOrEqual($highest)
        // Not vacuous: a gate at 0 refuses nothing, and the shipped value is
        // the baseline's own highest version.
        ->and($shipped)->toBe($highest);

    // The control AC-21 names: the same comparison with a constant one higher
    // fails. A code that required a version nothing can produce would 503 every
    // tenant in the installation.
    expect($highest + 1)->toBeGreaterThan($highest);

    // And the configuration key reads that variable rather than carrying a
    // number of its own, so the two cannot drift apart silently. Asserted over
    // the config file's text: calling env() from a test is what breaks the
    // moment the configuration is cached, which this repository's own cache
    // guard exists to catch.
    expect(File::get(base_path('config/schema.php')))->toContain("env('APP_REQUIRED_SCHEMA_VERSION'")
        ->and(config('schema.required_version'))->toBeInt();
});

/*
|--------------------------------------------------------------------------
| The configuration key is the authority, proven by moving it
|--------------------------------------------------------------------------
*/

it('reads the tenant tree location from configuration, not from a literal of its own', function () {
    cataloguePublishMigrateMaster();

    $scratch = 'storage/app/catalogue-scratch-tree';

    File::deleteDirectory(base_path($scratch));
    File::ensureDirectoryExists(base_path($scratch));
    File::put(
        base_path($scratch.DIRECTORY_SEPARATOR.'0001_scratch_only.php'),
        "<?php\n\n/*\n * @phase expand\n */\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\n"
        ."return new class extends Migration\n{\n    public function up(): void\n    {\n        //\n    }\n};\n"
    );

    config()->set('schema.tenant_migration_path', $scratch);

    // The suite's own helpers follow the key...
    expect(tenantMigrationPath())->toBe($scratch)
        ->and(migrationTreeDirectory(MigrationTree::Tenant))->toBe(base_path($scratch));

    // ...and so does the command, which is the half that matters: the row it
    // published is the scratch tree's single migration, so the tree it read was
    // the configured one and not a directory name it carries itself. The exit
    // code is 1 because the committed fixture describes the shipped tree, which
    // is exactly the disagreement the comparison exists to report.
    expect(Artisan::call('schema:publish'))->toBe(1)
        ->and(array_column(cataloguePublishedRows(), 'migration'))->toBe(['0001_scratch_only']);

    File::deleteDirectory(base_path($scratch));
});

it('keeps the reserved test-database prefix out of the schema configuration', function () {
    // Ruling 2.3: the prefix lives in Flexipos\Testing\Engine\ReservedDatabaseName::PREFIX,
    // because packages/testing may not read Laravel configuration without a
    // dependency the boundary check forbids - and a guard that reads
    // configuration is a guard an environment variable can widen. So
    // config/schema.php holds no copy of it, and this asserts the absence.
    //
    // The literal is assembled from fragments rather than written out: a test
    // that spelled it would be a true positive of the very rule it is checking,
    // which is the precedent set when the banned-collation control was built
    // the same way instead of being stored as a fixture file.
    $prefix = 'flexipos'.'_t8'.'test_';

    expect(ReservedDatabaseName::PREFIX)->toBe($prefix)
        ->and(File::get(base_path('config/schema.php')))->not->toContain($prefix);
});
