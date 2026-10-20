<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationSetRefused;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
 * T-008 step 8: the seam the runner's upgrade tests ride on.
 *
 * Mirrored here rather than kept beside the class, for the reason every kernel
 * test is: modules/host/phpunit.xml collects packages/ * /tests, so a *Test.php
 * under packages/kernel/src/ is collected by nothing and never runs while the
 * gate stays green.
 *
 * Nothing here opens a connection. A set is a statement about files.
 */

uses(TestCase::class);

afterEach(function () {
    File::deleteDirectory(storage_path('app/migration-sets'));
});

/**
 * A scratch directory holding one inert migration per given file name.
 *
 * Inert on purpose: a set is read, never applied, and a fixture that could
 * create a table is a fixture that could be applied to something by accident.
 *
 * @param  list<string>  $files
 */
function migrationSetScratch(string $name, array $files): string
{
    $directory = storage_path('app/migration-sets/'.$name);

    File::deleteDirectory($directory);
    File::ensureDirectoryExists($directory);

    foreach ($files as $file) {
        File::put(
            $directory.DIRECTORY_SEPARATOR.$file,
            "<?php\n\n/*\n * @phase expand\n */\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\n"
            ."return new class extends Migration\n{\n    public function up(): void\n    {\n        //\n    }\n};\n"
        );
    }

    return $directory;
}

/*
|--------------------------------------------------------------------------
| A version is a position in the set, in the order the directories are named
|--------------------------------------------------------------------------
*/

it('numbers the whole set from one, in directory order and never merged by name', function () {
    // The second directory's file sorts *before* the first directory's by name.
    // That is the input that makes the rule bind: a set built by merging and
    // sorting every path would number `0001_fx_later.php` as version 1 and
    // renumber everything the baseline already gave a tenant.
    $first = migrationSetScratch('first', ['9000_one.php', '9100_two.php']);
    $second = migrationSetScratch('second', ['0001_fx_later.php']);

    $set = MigrationSet::named('first', $first)->with('second', $second);

    expect($set->name)->toBe('first+second')
        ->and(array_map(
            static fn (MigrationCatalogue $entry): array => [$entry->version, $entry->migration],
            $set->catalogue()
        ))->toBe([
            [1, '9000_one'],
            [2, '9100_two'],
            [3, '0001_fx_later'],
        ])
        ->and($set->highestVersion())->toBe(3);
});

it('does not move an existing version when a directory is appended', function () {
    $first = migrationSetScratch('first', ['9000_one.php', '9100_two.php']);
    $second = migrationSetScratch('second', ['0001_fx_later.php']);

    $alone = MigrationSet::named('first', $first);
    $extended = $alone->with('second', $second);

    // The property the whole seam rests on: a tenant that carries version 2
    // carries the same migration before and after the FX directory is
    // supplied. Compared by row, so a changed checksum or phase would also be
    // reported.
    expect(array_map(
        static fn (MigrationCatalogue $entry): array => $entry->toRow(),
        array_slice($extended->catalogue(), 0, 2)
    ))->toBe(array_map(
        static fn (MigrationCatalogue $entry): array => $entry->toRow(),
        $alone->catalogue()
    ));

    expect($alone->highestVersion())->toBe(2)
        ->and($extended->highestVersion())->toBe(3);
});

it('answers the entry at a version and the version of a migration', function () {
    $set = MigrationSet::named('only', migrationSetScratch('only', ['9000_one.php', '9100_two.php']));

    expect($set->entryFor(2)?->migration)->toBe('9100_two')
        ->and($set->entryFor(3))->toBeNull()
        ->and($set->versionOf('9000_one'))->toBe(1)
        // The name is the file's base name, which is what Laravel's own
        // `migrations` table records - so a tenant's applied rows compare with
        // a set without a translation step.
        ->and($set->versionOf('9000_one.php'))->toBeNull()
        ->and($set->versionOf('never_written'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A set that cannot be the size it claims refuses
|--------------------------------------------------------------------------
*/

it('refuses a directory that is not on disk, rather than being quietly shorter', function () {
    $missing = storage_path('app/migration-sets/never-created');

    expect(fn () => MigrationSet::named('typo', $missing))
        ->toThrow(MigrationSetRefused::class);

    // The message names the set and the path, because the mistake this catches
    // is a mistyped fixture directory and the path is the whole of the news.
    try {
        MigrationSet::named('typo', $missing);
    } catch (MigrationSetRefused $refused) {
        expect($refused->getMessage())->toContain('typo')
            ->and($refused->getMessage())->toContain('never-created');
    }

    // The control: the same call against a directory that exists and holds
    // nothing is *not* refused and yields version 0. An empty directory and a
    // missing one say different things about whether anybody meant it.
    $empty = migrationSetScratch('empty', []);

    expect(MigrationSet::named('empty', $empty)->highestVersion())->toBe(0);
});

it('refuses the same directory twice, because every file in it would count as two versions', function () {
    $directory = migrationSetScratch('first', ['9000_one.php']);

    expect(fn () => MigrationSet::named('doubled', $directory, $directory))
        ->toThrow(MigrationSetRefused::class);

    // And through with(), which is how a set is actually built up.
    expect(fn () => MigrationSet::named('first', $directory)->with('again', $directory))
        ->toThrow(MigrationSetRefused::class);

    // Including when the second spelling differs only in separators or a
    // trailing slash, which is the form a caller joining paths produces.
    expect(fn () => MigrationSet::named('doubled', $directory, str_replace('\\', '/', $directory).'/'))
        ->toThrow(MigrationSetRefused::class);
});

it('refuses a set with no directory at all', function () {
    expect(fn () => MigrationSet::named('nothing'))->toThrow(MigrationSetRefused::class);
});

/*
|--------------------------------------------------------------------------
| The shipped baseline, as a set
|--------------------------------------------------------------------------
*/

it('produces the same catalogue for the baseline as reading the tree does', function () {
    // The directory comes from the caller, which read
    // config('schema.tenant_migration_path') - the one authority for it. A set
    // that knew the directory itself would be the second literal step 5
    // removed.
    $tree = base_path((string) config('schema.tenant_migration_path'));

    $set = MigrationSet::baseline($tree);

    expect($set->name)->toBe(MigrationSet::BASELINE)
        ->and(array_map(static fn (MigrationCatalogue $entry): array => $entry->toRow(), $set->catalogue()))
        ->toBe(array_map(
            static fn (MigrationCatalogue $entry): array => $entry->toRow(),
            MigrationCatalogue::forTree($tree)
        ))
        // The highest version is one per migration FILE. It was written as
        // count(tenantBaselineTables()) while T-008's one-table-per-file
        // identity held; T-010 ships nine files and eight tables and the
        // number is now derived from the tree (T-010 Ruling 2).
        ->and($set->highestVersion())->toBe(tenantMigrationFileCount());
});
