<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * T-008 step 5: the scratch migration trees the catalogue tests publish from.
 *
 * A plain file, required by each test that needs it, for the reason
 * packages/kernel/tests/Support/tenancy-fixture.php exists: a bare function
 * declared in whichever test file happened to be collected first makes a
 * single-file run fail and a full run pass (FP-164).
 *
 * The trees here are **scratch** trees, not the shipped one. That is the whole
 * point of them: ordering, renumbering and the out-of-order refusal cannot be
 * proven against a tree whose files are fixed and committed, and a test that
 * added a file to the shipped tree would be changing the schema every tenant
 * gets. The shipped tree is asserted separately, in the host's own
 * SchemaCataloguePublishTest.
 *
 * Nothing here opens a connection to any engine: the only database involved is
 * the suite's in-memory SQLite master, which every helper below asserts before
 * it migrates (the engine at 127.0.0.1:3306 is on hold after sixteen of the
 * owner's databases were dropped on 2026-10-14).
 */

/**
 * Where the scratch trees live: under storage/, which is git-ignored, excluded
 * from Pint and never analysed.
 *
 * Memoised, because the afterAll hook that removes the root runs outside the
 * application lifecycle, where storage_path() is unavailable.
 */
function schemaFixtureRoot(): string
{
    static $root = null;

    if ($root === null) {
        $root = storage_path('app'.DIRECTORY_SEPARATOR.'schema-catalogue-fixture');
    }

    return $root;
}

/**
 * The source of one scratch migration file.
 *
 * It is a real migration - `up()` and a `Schema` call - so that the phase tag
 * is read out of a comment while the statements are read out of code, exactly
 * as the shipped tree is. `$statements` is the body of `up()`, so a test can
 * make one file destructive without any other difference.
 */
function schemaFixtureMigrationSource(string $phase = 'expand', string $statements = '', bool $requiresOffline = false): string
{
    $tags = ' * @phase '.$phase."\n";

    if ($requiresOffline) {
        $tags .= ' * '.MigrationCatalogue::OFFLINE_TAG."\n";
    }

    return "<?php\n\n/*\n * A scratch migration, built by the catalogue fixture.\n *\n"
        .$tags
        ." */\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\n\n"
        ."return new class extends Migration\n{\n    public function up(): void\n    {\n"
        .'        '.($statements === '' ? "Schema::create('scratch_table', function (\$table) {\n            \$table->string('id', 26)->primary();\n        });" : $statements)
        ."\n    }\n};\n";
}

/**
 * Build a scratch tree and return its absolute directory.
 *
 * The directory is emptied first, so a tree is exactly the files the test
 * named and a leftover from an earlier case cannot become an extra catalogue
 * row.
 *
 * @param  array<string, string>  $files  base name (without .php) => source
 */
function schemaFixtureTree(string $name, array $files): string
{
    $directory = schemaFixtureRoot().DIRECTORY_SEPARATOR.$name;

    File::deleteDirectory($directory);
    File::ensureDirectoryExists($directory);

    foreach ($files as $migration => $source) {
        File::put($directory.DIRECTORY_SEPARATOR.$migration.'.php', $source);
    }

    return $directory;
}

/**
 * Add one file to an existing scratch tree, leaving the others as they are.
 */
function schemaFixtureAddMigration(string $name, string $migration, string $source): string
{
    $directory = schemaFixtureRoot().DIRECTORY_SEPARATOR.$name;

    File::ensureDirectoryExists($directory);
    File::put($directory.DIRECTORY_SEPARATOR.$migration.'.php', $source);

    return $directory;
}

/**
 * Apply the master tree to the suite's in-memory SQLite master, so
 * `schema_versions` exists to publish into.
 *
 * The driver is asserted from configuration before `migrate` is called, not
 * after: reading configuration cannot open a socket, and an ambient
 * DB_MASTER_DRIVER beats phpunit.xml's <env> (it writes putenv() and $_ENV,
 * never $_SERVER, which Laravel's Env repository reads first). Without this
 * assertion a developer's shell could point these tests at the shared engine.
 */
function schemaFixtureMigrateMaster(): void
{
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
}

/**
 * The published catalogue, read back from the master in version order, in the
 * same row shape MigrationCatalogue::toRow() produces.
 *
 * The booleans are cast here because SQLite answers 0 and 1 where MariaDB
 * answers the same integers: comparing a read row with a built row would
 * otherwise fail on the storage type rather than on the catalogue.
 *
 * @return list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}>
 */
function schemaFixturePublishedRows(): array
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
 * Remove the scratch root, from an afterAll hook.
 *
 * Native calls only: Pest runs afterAll outside the application lifecycle, so
 * the File facade would resolve against no container.
 */
function schemaFixtureDeleteRoot(): void
{
    $root = schemaFixtureRoot();

    foreach (glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        clearstatcache();

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    clearstatcache();

    if (is_dir($root)) {
        rmdir($root);
    }
}
