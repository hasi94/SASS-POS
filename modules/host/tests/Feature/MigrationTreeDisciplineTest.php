<?php

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;

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
    $directory = base_path(
        'database/'.($tree === MigrationTree::Tenant ? 'migrations-tenant' : 'migrations')
    );

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
        ->and(masterApplicationTables())->toHaveCount(17)
        ->and(masterSystemTables())->toHaveCount(7);
});

it('creates exactly the six tenant baseline tables AC-23 names', function () {
    // Six files, one table each, so that every table gets its own catalogue
    // version and AC-31's "strictly increasing in file-name order" has something
    // to be true of. No projection-state table and no claim-mark column (D-1):
    // `docs/patterns.md` section 5 wants a measurement first, and there is none.
    expect(migrationTreeTables(MigrationTree::Tenant))->toBe(tenantBaselineTables())
        ->and(tenantBaselineTables())->toHaveCount(6)
        ->and(migrationTreeSources(MigrationTree::Tenant))->toHaveCount(6);
});

it('creates no tenant table in the master tree and no master table in the tenant tree', function () {
    // AC-22's source half. The live half - migrate the master, look for a tenant
    // table in it - is [E] and gated; this is the part that can be true of a
    // branch rather than of a database, and it is also the part that stays true
    // when nobody has an engine.
    $master = migrationTreeTables(MigrationTree::Master);
    $tenant = migrationTreeTables(MigrationTree::Tenant);

    expect(array_values(array_intersect($master, tenantBaselineTables())))->toBe([])
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
