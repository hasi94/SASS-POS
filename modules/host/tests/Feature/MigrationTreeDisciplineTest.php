<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The shipped trees, against the rules that landed before them
|--------------------------------------------------------------------------
|
| T-008 steps 3 and 4. Two things are asserted here and nowhere else:
|
| 1. **Every rule of step 2 is silent on both shipped trees.** This is the
|    "and only then green against the tree" half of step 2, and it could not be
|    written in step 2's commit: the tenant tree did not exist, and the stock
|    `users` table still carried `timestamps()`.
| 2. **The trees create exactly the tables AC-1 and AC-23 name, and nothing
|    else** - read from the source, because the live information_schema half of
|    both criteria is [E] and waits behind the engine gate (ENGINE_HOLD, G-1).
|    A source list is not a substitute for the engine's answer and is not
|    recorded as one; it is the strongest statement available under the hold,
|    and it is the one that catches a table added to a tree without a decision.
|
| Nothing here opens a connection: both assertions read files.
|
| The three table lists the criteria name live in tests/Helpers.php: three files
| need them, and a bare function declared in whichever file was collected first
| makes a single-file run fail and a full run pass (FP-164).
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Every migration file of one tree, keyed by path in file-name order.
 *
 * @return array<string, string>
 */
function migrationTreeSources(MigrationTree $tree): array
{
    $directory = migrationTreeDirectory($tree);

    $paths = glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [];

    sort($paths);

    $sources = [];

    foreach ($paths as $path) {
        $sources[str_replace(DIRECTORY_SEPARATOR, '/', $path)] = (string) file_get_contents($path);
    }

    return $sources;
}

/**
 * Every table a tree creates.
 *
 * @return list<string>
 */
function migrationTreeTables(MigrationTree $tree): array
{
    $tables = [];

    foreach (migrationTreeSources($tree) as $source) {
        foreach (SourceRules::tablesCreatedIn($source) as $table) {
            $tables[] = $table;
        }
    }

    sort($tables);

    return $tables;
}

/*
|--------------------------------------------------------------------------
| The rules of step 2, against the trees of steps 3 and 4
|--------------------------------------------------------------------------
*/

it('breaks none of the static rules in either shipped tree', function () {
    $offenders = [];

    foreach ([MigrationTree::Master, MigrationTree::Tenant] as $tree) {
        foreach (migrationTreeSources($tree) as $path => $source) {
            $violations = SourceRules::inspect($source, $tree);

            if ($violations !== []) {
                $offenders[$path] = $violations;
            }
        }
    }

    // Compared as a map, so a failure names the file, the rule and the statement
    // instead of only a count.
    expect($offenders)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The table lists
|--------------------------------------------------------------------------
*/

it('creates exactly the master tables AC-1 names', function () {
    $expected = array_merge(masterApplicationTables(), masterSystemTables());

    sort($expected);

    // Exact equality, not toContain: "and nothing else" is half of AC-1, and it
    // is the half an extra table slips through.
    expect(migrationTreeTables(MigrationTree::Master))->toBe($expected)
        // Seventeen from T-008 plus T-009 step 3's four (AC-50), plus
        // T-019 step 3's five plugin-registry tables (AC-7).
        ->and(masterApplicationTables())->toHaveCount(28)
        ->and(masterSystemTables())->toHaveCount(7);
});

it('creates exactly the tenant tables the shipped tree declares', function () {
    // T-008 wrote this as "exactly the six baseline tables" and read the
    // *directory* to say it, so it went red the moment T-010's eighth table
    // landed. T-010 Ruling 2 splits the two concepts rather than widening the
    // baseline helper, whose name would then be false: tenantBaselineTables()
    // keeps its six and means exactly that, tenantTreeTables() carries the
    // whole tree, and this site - a whole-tree site - moved to the second.
    //
    // The file count is a different number from the table count, and since
    // T-011 they differ: the settings.updated_by key is a file that creates no
    // table. So the file count is derived (T-011 AC-24 E5): one file per
    // table created, plus one per file that creates none, and the tableless
    // set is read from the files rather than written here. T-014 adds five
    // tables in five files (14 becomes 19), and T-017 adds one more in one
    // file (19 becomes 20); the identity below still holds because each new
    // file creates exactly one table.
    $tableless = array_keys(array_filter(
        migrationTreeSources(MigrationTree::Tenant),
        static fn (string $source): bool => SourceRules::tablesCreatedIn($source) === []
    ));

    expect(migrationTreeTables(MigrationTree::Tenant))->toBe(tenantTreeTables())
        ->and(tenantBaselineTables())->toHaveCount(6)
        // T-019 step 4 adds the three tenant-side plugin data tables.
        // T-020 step 3 adds the five workflow-engine tables (23 becomes 28).
        // T-027 step 3 adds the twelve catalog tables (29 becomes 41).
        // T-028 step 3 adds the eight customer tables (41 becomes 49).
        // T-029 step 2 adds the nine pricing and tax tables (49 becomes 58).
        // T-030 step 6 adds the eight Sales tables (58 becomes 66).
        ->and(tenantTreeTables())->toHaveCount(73)
        // T-032 step 2 adds the three finance tables (66 becomes 69).
        // T-038 step 2 adds the four HR tables (69 becomes 73).
        ->and(migrationTreeSources(MigrationTree::Tenant))->toHaveCount(tenantMigrationFileCount())
        ->and($tableless)->not->toBe([])
        ->and(tenantMigrationFileCount())->toBe(count(tenantTreeTables()) + count($tableless));
});

it('creates no tenant table in the master tree and no master table in the tenant tree', function () {
    // AC-22's source half. The live half - migrate the master, look for a tenant
    // table in it - is [E] and gated; this is the part that can be true of a
    // branch rather than of a database, and it is also the part that stays true
    // when nobody has an engine.
    $master = migrationTreeTables(MigrationTree::Master);
    $tenant = migrationTreeTables(MigrationTree::Tenant);

    // Whole-tree, so tenantTreeTables() (T-010 Ruling 2): the intersection has
    // to be taken over the real tenant set, or a master table added under one
    // of T-010's eight names would not be reported.
    expect(array_values(array_intersect($master, tenantTreeTables())))->toBe([])
        ->and(array_values(array_intersect($tenant, masterApplicationTables())))->toBe([]);

    // And the controls, because two empty intersections are also what two empty
    // trees produce: each tree holds the table whose absence would make the
    // architecture unreachable from its own side.
    expect($master)->toContain('tenant_databases')
        ->and($tenant)->toContain('outbox_events');
});

it('reads a table list that only a non-empty tree can produce', function () {
    // The detector proven on a fixture, so "exactly these tables" above cannot
    // pass by finding nothing: the clean fixture creates one table and one
    // table is what comes back.
    $fixture = (string) file_get_contents(
        base_path('tests/Fixtures/MigrationSourceRules/clean_expand_step.php')
    );

    expect(SourceRules::tablesCreatedIn($fixture))->toBe(['fixture_widgets']);

    // And a table named only in prose is not a table the file creates - which is
    // what lets the baseline name `branches` and `employees` in the docblock
    // AC-30 requires of it without appearing to create them.
    $prose = '<?php /* creates nothing: Schema::create(\'branches\') is not ours */ $x = 1;';

    expect(SourceRules::tablesCreatedIn($prose))->toBe([]);
});

/**
 * The ULID columns a migration declares with `$table->ulid()` itself rather
 * than through `IdentifierColumn`, comments excluded.
 *
 * @return list<string>
 */
function rawUlidColumnsIn(string $source): array
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    preg_match_all('/->\s*ulid\(\s*[\'"]([^\'"]+)[\'"]/', $code, $matches);

    return $matches[1];
}

it('declares every ULID column through IdentifierColumn, in both trees (AC-4, D-41)', function () {
    $raw = [];
    $files = 0;

    foreach ([MigrationTree::Master, MigrationTree::Tenant] as $tree) {
        foreach (migrationTreeSources($tree) as $path => $source) {
            $files++;

            foreach (rawUlidColumnsIn($source) as $column) {
                $raw[] = basename($path).': '.$column;
            }
        }
    }

    fwrite(STDOUT, "\nULID columns declared outside IdentifierColumn, over {$files} files: ".($raw === [] ? 'none' : implode(', ', $raw))."\n");

    // The domain: both trees were read.
    expect($files)->toBeGreaterThan(tenantMigrationFileCount())
        ->and($raw)->toBe([]);

    // The control: the declaration this rule was written for (review S-6) is
    // reported, and the same column through the helper, or named in a comment,
    // is not.
    $before = "<?php \$table->ulid('default_employee_id')->nullable()->storedAs('x');";
    $after = "<?php /* \$table->ulid('in_prose') */ IdentifierColumn::nullable(\$table, 'default_employee_id')->storedAs('x');";

    expect(rawUlidColumnsIn($before))->toBe(['default_employee_id'])
        ->and(rawUlidColumnsIn($after))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| No fixture migration under the shipped tenant tree
|--------------------------------------------------------------------------
|
| T-008 step 13, buildable only now that step 8's fixtures exist. The FX files
| are migrations in every sense - a Migration subclass with an up() - and the
| shipped tenant tree is applied to **every tenant database there will ever
| be**. One of them copied or moved into that directory would be published
| into the catalogue by `schema:publish`, numbered, and then applied to real
| shops; FX-5 drops a column, and FX-2 fails on purpose.
|
| Matched two ways, because a copy is renamed more often than it is rewritten:
| by LF-normalised checksum, which survives any rename, and by the FX naming
| convention, which survives an edit. Either one reports the file.
|
*/

/**
 * Every FX fixture migration, as checksum => file name.
 *
 * @return array<string, string>
 */
function fixtureMigrationFingerprints(): array
{
    $fingerprints = [];

    foreach (range(1, 6) as $number) {
        $directory = base_path('tests/Fixtures/TenantMigrations/FX'.$number);

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $fingerprints[MigrationCatalogue::checksumOf((string) file_get_contents($path))]
                = pathinfo($path, PATHINFO_FILENAME);
        }
    }

    return $fingerprints;
}

/**
 * The files in one directory that are fixture migrations.
 *
 * @return list<string>
 */
function fixtureMigrationsUnder(string $directory): array
{
    $fingerprints = fixtureMigrationFingerprints();
    $found = [];

    foreach (glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $checksum = MigrationCatalogue::checksumOf((string) file_get_contents($path));

        // By content, so a renamed copy is still reported, or by name, so an
        // edited one is.
        if (array_key_exists($checksum, $fingerprints) || preg_match('/(^|_)fx\d+(_|$)/i', $name) === 1) {
            $found[] = $name;
        }
    }

    sort($found);

    return $found;
}

it('holds no fixture migration under the shipped tenant tree', function () {
    expect(fixtureMigrationsUnder(migrationTreeDirectory(MigrationTree::Tenant)))->toBe([])
        // The master tree too: the runner does not apply it, but `php artisan
        // migrate` does, and a fixture there would reach the master database.
        ->and(fixtureMigrationsUnder(migrationTreeDirectory(MigrationTree::Master)))->toBe([]);
});

it('reports a fixture migration copied into a tree, under either name', function () {
    // Without this the check above also passes against a detector that matches
    // nothing - which is how a hygiene check becomes decorative.
    $scratch = storage_path('app'.DIRECTORY_SEPARATOR.'fixture-migration-scan');

    File::deleteDirectory($scratch);
    File::ensureDirectoryExists($scratch);

    try {
        $fx5 = glob(base_path('tests/Fixtures/TenantMigrations/FX5').DIRECTORY_SEPARATOR.'*.php')[0];

        // Copied and **renamed**, so the name rule cannot be what catches it:
        // this one is caught by its checksum.
        File::copy($fx5, $scratch.DIRECTORY_SEPARATOR.'0007_create_something_harmless.php');

        // Edited and left named like a fixture, so the checksum cannot be what
        // catches it: this one is caught by its name.
        File::put(
            $scratch.DIRECTORY_SEPARATOR.'0008_fx9_renamed_but_edited.php',
            (string) file_get_contents($fx5)."\n// edited, so the checksum moved\n"
        );

        // The negative control, in the same directory: an ordinary migration is
        // not reported, or the check would forbid the tree from holding
        // anything at all.
        File::copy(
            glob(migrationTreeDirectory(MigrationTree::Tenant).DIRECTORY_SEPARATOR.'*.php')[0],
            $scratch.DIRECTORY_SEPARATOR.'0009_an_ordinary_baseline_file.php'
        );

        expect(fixtureMigrationsUnder($scratch))->toBe([
            '0007_create_something_harmless',
            '0008_fx9_renamed_but_edited',
        ]);
    } finally {
        File::deleteDirectory($scratch);
    }
});

it('fingerprints the six fixtures it is scanning for', function () {
    // The scan is only as good as the set it scans for: six directories, one
    // migration each, and six distinct checksums.
    expect(fixtureMigrationFingerprints())->toHaveCount(6);
});
