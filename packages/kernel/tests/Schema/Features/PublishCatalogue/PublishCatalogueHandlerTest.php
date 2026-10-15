<?php

use Flexipos\Kernel\Schema\Exceptions\CataloguePublishRefused;
use Flexipos\Kernel\Schema\Features\PublishCatalogue\PublishCatalogue;
use Flexipos\Kernel\Schema\Features\PublishCatalogue\PublishCatalogueHandler;
use Flexipos\Kernel\Schema\Features\PublishCatalogue\PublishCatalogueResult;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
 * T-008 step 5, AC-31 and the publish half of AC-32.
 *
 * The slice's test is mirrored here rather than kept beside the handler in
 * packages/kernel/src/Schema/Features/PublishCatalogue/: modules/host/phpunit.xml
 * collects packages/* /tests, so a *Test.php under packages/kernel/src/ is
 * collected by nothing and never runs while the gate stays green.
 *
 * The only database involved is the suite's in-memory SQLite master, asserted to
 * be SQLite before anything migrates. Nothing here reaches the engine at
 * 127.0.0.1:3306, which is on hold.
 */

require_once __DIR__.'/../../../Support/schema-catalogue-fixture.php';

uses(TestCase::class);

afterAll(function () {
    schemaFixtureDeleteRoot();
});

/**
 * Publish one scratch tree.
 */
function publishScratchTree(string $directory): PublishCatalogueResult
{
    return app(PublishCatalogueHandler::class)->handle(new PublishCatalogue($directory));
}

/*
|--------------------------------------------------------------------------
| One row per file, numbered in file-name order
|--------------------------------------------------------------------------
*/

it('publishes one row per migration file, strictly increasing in file-name order', function () {
    schemaFixtureMigrateMaster();

    $expand = schemaFixtureMigrationSource();
    $offline = schemaFixtureMigrationSource('backfill', "DB::table('scratch_table')->update(['id' => '0']);", true);
    $dropping = schemaFixtureMigrationSource('contract', "Schema::table('settings', function (\$table) {\n            \$table->dropColumn('legacy');\n        });");

    // Deliberately written in an order that is not the file-name order, so the
    // catalogue's ordering is a property of the names rather than of the
    // directory's contents as the filesystem happened to return them.
    $directory = schemaFixtureTree('ordering', [
        '0002_scratch_backfill' => $offline,
        '0003_scratch_contract' => $dropping,
        '0001_scratch_expand' => $expand,
    ]);

    $result = publishScratchTree($directory);

    expect($result->inserted)->toBe([
        '0001_scratch_expand',
        '0002_scratch_backfill',
        '0003_scratch_contract',
    ]);

    // Exact rows, not a count: the version numbers, the phase read from the
    // comment, the checksum of the file and the destructive flag read from the
    // code are the whole of what the catalogue is for.
    expect(schemaFixturePublishedRows())->toBe([
        [
            'version' => 1,
            'migration' => '0001_scratch_expand',
            'phase' => 'expand',
            'checksum' => MigrationCatalogue::checksumOf($expand),
            'is_destructive' => false,
            'requires_offline' => false,
        ],
        [
            'version' => 2,
            'migration' => '0002_scratch_backfill',
            'phase' => 'backfill',
            'checksum' => MigrationCatalogue::checksumOf($offline),
            'is_destructive' => false,
            'requires_offline' => true,
        ],
        [
            'version' => 3,
            'migration' => '0003_scratch_contract',
            'phase' => 'contract',
            'checksum' => MigrationCatalogue::checksumOf($dropping),
            // Read from the statements, never declared: D-10's refusal is keyed
            // on this flag, and a file that *said* it was harmless would be the
            // one lie with a cost. The clean expand step above is the control
            // that proves the flag is not simply always true.
            'is_destructive' => true,
            'requires_offline' => false,
        ],
    ]);

    // Strictly increasing, asserted as its own claim: AC-31 is about the
    // sequence, and a catalogue that numbered every row 1 would satisfy a
    // "one row per file" count.
    $versions = array_column(schemaFixturePublishedRows(), 'version');

    expect($versions)->toBe([1, 2, 3])
        ->and($versions)->toBe(array_values(array_unique($versions)));
});

/*
|--------------------------------------------------------------------------
| The second run changes nothing
|--------------------------------------------------------------------------
*/

it('writes nothing on a second run and leaves every row as it was', function () {
    schemaFixtureMigrateMaster();

    $directory = schemaFixtureTree('idempotence', [
        '0001_scratch_one' => schemaFixtureMigrationSource(),
        '0002_scratch_two' => schemaFixtureMigrationSource(),
    ]);

    expect(publishScratchTree($directory)->inserted)->toBe(['0001_scratch_one', '0002_scratch_two']);

    $afterFirstRun = schemaFixturePublishedRows();

    $second = publishScratchTree($directory);

    // The write list, not just the row count: "changes nothing" is a claim
    // about writes, and a count cannot tell a no-op from a delete followed by
    // an identical insert.
    expect($second->inserted)->toBe([])
        ->and(schemaFixturePublishedRows())->toBe($afterFirstRun);
});

/*
|--------------------------------------------------------------------------
| The out-of-order refusal, and that nothing is written
|--------------------------------------------------------------------------
*/

it('refuses a migration whose name sorts before an already-published one, and writes nothing', function () {
    schemaFixtureMigrateMaster();

    $directory = schemaFixtureTree('out-of-order', [
        '0002_scratch_published' => schemaFixtureMigrationSource(),
        '0003_scratch_published' => schemaFixtureMigrationSource(),
    ]);

    publishScratchTree($directory);

    $published = schemaFixturePublishedRows();

    expect($published)->toHaveCount(2);

    // The file that sorts before both of them. Publishing it would renumber
    // `0002_scratch_published` from 1 to 2 - and tenants already record 1 as
    // their schema version, so the renumbering would move every tenant's
    // position without touching a single tenant database.
    schemaFixtureAddMigration('out-of-order', '0001_scratch_late_arrival', schemaFixtureMigrationSource());

    expect(fn () => publishScratchTree($directory))
        ->toThrow(CataloguePublishRefused::class, '0002_scratch_published');

    // Nothing was written: exact equality with the rows taken before the
    // attempt, so a refusal that wrote the new row first and threw afterwards
    // is reported here.
    expect(schemaFixturePublishedRows())->toBe($published);
});

/*
|--------------------------------------------------------------------------
| A published migration is never rewritten and never removed
|--------------------------------------------------------------------------
*/

it('refuses a published migration whose content changed, and writes nothing', function () {
    schemaFixtureMigrateMaster();

    $directory = schemaFixtureTree('rewritten', [
        '0001_scratch_released' => schemaFixtureMigrationSource(),
    ]);

    publishScratchTree($directory);

    $published = schemaFixturePublishedRows();

    schemaFixtureAddMigration(
        'rewritten',
        '0001_scratch_released',
        schemaFixtureMigrationSource('expand', "Schema::create('a_second_table', function (\$table) {\n            \$table->string('id', 26)->primary();\n        });")
    );

    expect(fn () => publishScratchTree($directory))
        ->toThrow(CataloguePublishRefused::class, '0001_scratch_released');

    expect(schemaFixturePublishedRows())->toBe($published);
});

it('refuses when a published migration is no longer in the tree, and writes nothing', function () {
    schemaFixtureMigrateMaster();

    $directory = schemaFixtureTree('removed', [
        '0001_scratch_kept' => schemaFixtureMigrationSource(),
        '0002_scratch_removed' => schemaFixtureMigrationSource(),
    ]);

    publishScratchTree($directory);

    $published = schemaFixturePublishedRows();

    File::delete($directory.DIRECTORY_SEPARATOR.'0002_scratch_removed.php');

    expect(fn () => publishScratchTree($directory))
        ->toThrow(CataloguePublishRefused::class, '0002_scratch_removed');

    expect(schemaFixturePublishedRows())->toBe($published);
});

/*
|--------------------------------------------------------------------------
| The control for every refusal above: an unchanged tree publishes
|--------------------------------------------------------------------------
*/

it('publishes a tree that grows at the end, which is what expand and contract looks like', function () {
    schemaFixtureMigrateMaster();

    $directory = schemaFixtureTree('growing', [
        '0001_scratch_one' => schemaFixtureMigrationSource(),
    ]);

    publishScratchTree($directory);

    schemaFixtureAddMigration('growing', '0002_scratch_two', schemaFixtureMigrationSource());

    // The positive control the three refusals above need: a handler that
    // refused every second publish would pass all of them.
    expect(publishScratchTree($directory)->inserted)->toBe(['0002_scratch_two'])
        ->and(array_column(schemaFixturePublishedRows(), 'version'))->toBe([1, 2]);
});

it('refuses an empty tree rather than publishing an empty catalogue', function () {
    schemaFixtureMigrateMaster();

    $directory = schemaFixtureTree('empty', []);

    expect(fn () => publishScratchTree($directory))
        ->toThrow(CataloguePublishRefused::class, $directory);

    expect(schemaFixturePublishedRows())->toBe([]);
});
