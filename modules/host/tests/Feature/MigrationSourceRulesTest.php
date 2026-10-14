<?php

use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| The static migration rules, red on fixtures before the real tree exists
|--------------------------------------------------------------------------
|
| T-008 step 2: AC-33, AC-34, AC-35, AC-37 and AC-24's source half.
|
| **This file ships before the first real migration on the branch**, which is
| the whole reason it can prove anything (plan section 6, AC-34's (branch)
| marker). Written after the trees, every rule here would pass against the tree
| it was written from and the proof would be circular; written before them, each
| rule is red on a fixture that exists only to be wrong, and the shipped tree
| then has to satisfy a rule it did not define.
|
| **The mutation is always the fixture, never the checker** (plan section 11.2
| rule 2). Nothing below weakens SourceRules to make an assertion fail: the
| violating fixtures are files under tests/Fixtures/MigrationSourceRules/, and
| the one control that cannot be a file - AC-35(d)'s banned collation, which is
| forbidden in first-party code *anywhere*, so a fixture file holding it would be
| a true positive - is assembled from fragments here instead.
|
| Nothing in this file touches a database. SourceRules is a pure function over
| text, so there is no engine in the loop at all: this is the step the other
| red-first steps are bent towards (plan section 11.5).
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Directory names that are not this project's code.
 *
 * Pruned during the walk rather than filtered afterwards, and kept as directory
 * *names* rather than paths: Symfony Finder's `exclude()` matches only at the
 * root it is given, so excluding the dependency directory at the repository root
 * still walks modules/host/vendor and the one inside every path package - tens
 * of thousands of third-party files, each a candidate hit. The failure mode is
 * not a crash but a false red whose obvious fix is to narrow the scan until it
 * is blind to our own code (measured on this branch, plan section 7).
 *
 * @return list<string>
 */
function migrationRulePrunedDirectories(): array
{
    return ['vendor', 'node_modules', 'storage', '.git', 'bin', 'obj', 'dist'];
}

/**
 * Every PHP file in the repository that this project owns.
 *
 * AC-35(d)'s second clause is about first-party code anywhere, not about the
 * migration trees, so the corpus is the branch.
 *
 * @return array<string, string> repository-relative path (forward slashes) => source
 */
function migrationRuleOwnedPhpSources(): array
{
    $root = str_replace(DIRECTORY_SEPARATOR, '/', dirname(base_path(), 2));
    $pruned = migrationRulePrunedDirectories();
    $sources = [];

    $walk = static function (string $directory) use (&$walk, $root, $pruned, &$sources): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                if (! in_array($entry, $pruned, true)) {
                    $walk($path);
                }

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $sources[ltrim(substr($path, strlen($root)), '/')] = (string) file_get_contents($path);
            }
        }
    };

    $walk($root);

    ksort($sources);

    return $sources;
}

/**
 * One rule fixture's source.
 */
function migrationRuleFixture(string $name): string
{
    return (string) file_get_contents(
        base_path('tests/Fixtures/MigrationSourceRules/'.$name.'.php')
    );
}

/**
 * Every migration file in a shipped tree, as path => source.
 *
 * @return array<string, string>
 */
function migrationRuleTreeSources(MigrationTree $tree): array
{
    $directory = base_path(
        'database/'.($tree === MigrationTree::Tenant ? 'migrations-tenant' : 'migrations')
    );

    $sources = [];

    foreach (glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
        $sources[str_replace(DIRECTORY_SEPARATOR, '/', $path)] = (string) file_get_contents($path);
    }

    return $sources;
}

/**
 * The rules a scan reported, as a sorted list with duplicates kept.
 *
 * @param  list<array{rule: string, found: string}>  $violations
 * @return list<string>
 */
function migrationRuleIds(array $violations): array
{
    $rules = array_map(static fn (array $violation): string => $violation['rule'], $violations);

    sort($rules);

    return $rules;
}

/*
|--------------------------------------------------------------------------
| Red first: every rule, on its own violating fixture
|--------------------------------------------------------------------------
*/

it('reports an expand step that drops, and not the same drop in a contract step', function () {
    // AC-33. FX-6 and FX-4 hold the identical statement against the identical
    // table; only the declared phase differs, so this pair also proves the rule
    // reads the phase rather than the words.
    expect(migrationRuleIds(SourceRules::inspect(migrationRuleFixture('fx6_expand_step_that_drops'), MigrationTree::Tenant)))
        ->toBe(['AC-33'])
        ->and(SourceRules::inspect(migrationRuleFixture('fx4_contract_step_that_drops'), MigrationTree::Tenant))
        ->toBe([]);
});

it('reports a foreign key added to an existing table outside a contract step', function () {
    // AC-34, the rule D-5 rests on: the four columns that owe a key to
    // `branches` and `employees` can only get it in a contract step.
    expect(migrationRuleIds(SourceRules::inspect(migrationRuleFixture('expand_step_adds_foreign_key'), MigrationTree::Tenant)))
        ->toBe(['AC-34'])
        ->and(SourceRules::inspect(migrationRuleFixture('contract_step_adds_foreign_key'), MigrationTree::Tenant))
        ->toBe([]);
});

it('reports a decimal column with no precision', function () {
    // AC-35(a).
    expect(migrationRuleIds(SourceRules::inspect(migrationRuleFixture('decimal_without_precision'), MigrationTree::Tenant)))
        ->toBe(['AC-35(a)']);
});

it('reports a native enum column', function () {
    // AC-35(b).
    expect(migrationRuleIds(SourceRules::inspect(migrationRuleFixture('native_enum_column'), MigrationTree::Tenant)))
        ->toBe(['AC-35(b)']);
});

it('reports a timestamp column outside the eight system tables', function () {
    // AC-35(c). Two declarations in the fixture, so two reports: the rule counts
    // columns, not files.
    expect(migrationRuleIds(SourceRules::inspect(migrationRuleFixture('timestamp_column'), MigrationTree::Tenant)))
        ->toBe(['AC-35(c)', 'AC-35(c)']);
});

it('reports a charset, a collation and a storage engine named in a migration', function () {
    // AC-35(d), first clause.
    $violations = SourceRules::inspect(migrationRuleFixture('charset_collation_and_engine'), MigrationTree::Tenant);

    expect(array_unique(migrationRuleIds($violations)))->toBe(['AC-35(d)'])
        ->and(count($violations))->toBeGreaterThanOrEqual(4);
});

it('reports ascii_bin named outside the one exempt helper', function () {
    // AC-35(d)'s second control. The exemption is a single symbol, not a licence
    // for the two names: a migration spelling them itself starts a second
    // definition of what an identifier column is.
    expect(migrationRuleIds(SourceRules::inspect(migrationRuleFixture('ascii_bin_outside_the_helper'), MigrationTree::Tenant)))
        ->toContain('AC-35(d)');
});

it('reports a migration that names the other tree\'s connection', function () {
    // AC-37. Each fixture is scanned as the tree it belongs to, and then as the
    // other one: the rule is asymmetric, so naming `master` is a violation in a
    // tenant migration and ordinary in a master one.
    $tenantNamingMaster = migrationRuleFixture('tenant_migration_naming_master');
    $masterNamingTenant = migrationRuleFixture('master_migration_naming_tenant');

    expect(migrationRuleIds(SourceRules::inspect($tenantNamingMaster, MigrationTree::Tenant)))->toBe(['AC-37'])
        ->and(SourceRules::inspect($tenantNamingMaster, MigrationTree::Master))->toBe([])
        ->and(migrationRuleIds(SourceRules::inspect($masterNamingTenant, MigrationTree::Master)))->toBe(['AC-37'])
        ->and(SourceRules::inspect($masterNamingTenant, MigrationTree::Tenant))->toBe([]);
});

it('reports a schema-qualified table name in either tree', function () {
    // AC-37's other half. The criterion words it for the tenant tree; a master
    // migration naming `other_db.table` is the same statement, so it is reported
    // too (declared deviation, strictly stronger).
    $source = migrationRuleFixture('schema_qualified_table');

    expect(migrationRuleIds(SourceRules::inspect($source, MigrationTree::Tenant)))->toBe(['AC-37'])
        ->and(migrationRuleIds(SourceRules::inspect($source, MigrationTree::Master)))->toBe(['AC-37']);
});

it('reports a tenant_id column in a tenant migration and allows one in master', function () {
    // AC-24's source half. The master is where a tenant id belongs, so the same
    // declaration must be silent there - otherwise the rule would forbid
    // `tenant_databases.tenant_id` and be narrowed back out again.
    $source = migrationRuleFixture('tenant_id_column');

    expect(migrationRuleIds(SourceRules::inspect($source, MigrationTree::Tenant)))->toBe(['AC-24'])
        ->and(SourceRules::inspect($source, MigrationTree::Master))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The control that carries the rest: a correct migration is silent
|--------------------------------------------------------------------------
*/

it('reports nothing against a correct expand step', function () {
    // Without this, every assertion above would also pass if the detectors
    // reported everything. The fixture creates a table with a foreign key, a
    // decimal with precision, DATETIME(3) columns and identifier columns from
    // the helper - the shape the shipped trees use.
    expect(SourceRules::inspect(migrationRuleFixture('clean_expand_step'), MigrationTree::Tenant))->toBe([])
        ->and(SourceRules::inspect(migrationRuleFixture('clean_expand_step'), MigrationTree::Master))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The shipped trees
|--------------------------------------------------------------------------
|
| Deliberately absent from this step, and this comment is the record of why.
|
| Step 2's bullet reads "each rule is asserted red against its own fixture first
| and only THEN green against the tree", and at this commit there is no tree to
| be green against: the tenant directory does not exist, and the master
| directory holds the three stock Laravel files, whose `users` table carries
| `timestamps()` and `timestamp('email_verified_at')` - a true AC-35(c) violation
| that step 3 removes when it replaces that table (AC-2, D-18). Asserting
| tree-silence here would therefore be red for a reason the rule is right about,
| and the only ways to make it green at this commit are to do step 3's work in
| step 2's commit or to narrow the rule. Both are worse than waiting one step.
|
| The sweep over both trees arrives with the master tree in step 3 and grows to
| the tenant tree in step 4 (tests/Feature/MigrationTreeDisciplineTest.php).
| What this step does assert about the trees is that it can find them at all,
| below: a rule pointed at a directory that has moved is the failure mode that
| made this step's order load bearing in the first place.
|
*/

it('finds the migration trees it will be swept over', function () {
    // The master tree exists today and holds the stock files; the tenant tree
    // does not exist until step 4, so it is asserted as a classification rather
    // than as a count - and every file either tree yields has to come back as
    // the tree it was asked for, which is what a renamed directory breaks.
    expect(migrationRuleTreeSources(MigrationTree::Master))->not->toBeEmpty();

    foreach ([MigrationTree::Master, MigrationTree::Tenant] as $tree) {
        foreach (array_keys(migrationRuleTreeSources($tree)) as $path) {
            expect(MigrationTree::forPath($path))->toBe($tree);
        }
    }
});

/*
|--------------------------------------------------------------------------
| The one exemption, and the proof that it is narrow
|--------------------------------------------------------------------------
*/

it('does not report the identifier-column helper, and does report anything else it might name', function () {
    $helper = migrationRuleOwnedPhpSources()[SourceRules::IDENTIFIER_HELPER_PATH];

    // The exemption: the helper may name `ascii` and `ascii_bin`.
    expect(SourceRules::inspectIdentifierHelper($helper))->toBe([]);

    // And it is an exemption from one rule, not a pass: the same file under the
    // ordinary migration rule is reported, which is what makes the narrow rule
    // above the only thing standing between it and a violation.
    expect(migrationRuleIds(SourceRules::inspect($helper, MigrationTree::Master)))->toContain('AC-35(d)');

    // The mutation, assembled here so no file on disk carries it: the helper
    // naming a third name is reported by its own narrow rule.
    $widened = str_replace(
        "'ascii_bin'",
        "'utf8mb4'.'_unicode_ci'",
        $helper
    );

    expect(migrationRuleIds(SourceRules::inspectIdentifierHelper($widened)))->toContain('AC-35(d)');
});

it('exempts exactly one path, which exists', function () {
    // An exemption for a path that has moved is an exemption nobody notices has
    // stopped applying - and the obvious fix for the red that follows is a
    // second entry, which is how an exemption list begins.
    expect(array_keys(migrationRuleOwnedPhpSources()))->toContain(SourceRules::IDENTIFIER_HELPER_PATH);
});

/*
|--------------------------------------------------------------------------
| AC-35(d), second clause: a collation this engine does not have
|--------------------------------------------------------------------------
*/

it('names no collation MariaDB lacks, anywhere in first-party code', function () {
    $offenders = [];

    foreach (migrationRuleOwnedPhpSources() as $path => $source) {
        $found = SourceRules::bannedCollations($source);

        if ($found !== []) {
            $offenders[$path] = $found;
        }
    }

    expect($offenders)->toBe([]);
});

it('reports both banned collations and leaves the configured one alone', function () {
    // Positive controls, assembled from fragments: the literals are forbidden in
    // first-party code anywhere, and this file is first-party code. A fixture
    // file carrying them would be a true positive of the check above - so the
    // control is built, not stored. (The same reason the containment check
    // concatenates its forbidden shapes.)
    $mysqlEight = '<?php $collation = \'utf8mb4'.'_0900'.'_ai_ci\';';
    $oldDefault = '<?php $collation = \'utf8mb4'.'_general'.'_ci\';';

    expect(SourceRules::bannedCollations($mysqlEight))->toHaveCount(1)
        ->and(SourceRules::bannedCollations($oldDefault))->toHaveCount(1);

    // The negative control, and the one that matters: the collation this project
    // actually configures must not be reported, or the rule would be red on
    // config/database.php and would be deleted within the hour.
    $configured = '<?php $collation = \'utf8mb4'.'_unicode_ci\';';

    expect(SourceRules::bannedCollations($configured))->toBe([]);

    // And prose is not code: config/database.php explains in a comment which
    // collation it is avoiding and why, which must stay sayable.
    $prose = '<?php'."\n".'// utf8mb4'.'_0900'.'_ai_ci is MySQL 8 only.'."\n".'$x = 1;';

    expect(SourceRules::bannedCollations($prose))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The phase a file declares
|--------------------------------------------------------------------------
*/

it('defaults an undeclared phase to the strictest one', function () {
    // Fail-closed: forgetting the tag refuses the risky statement instead of
    // licensing it, and a misspelled `contract` is not a licence either.
    expect(MigrationPhase::fromSource('<?php $x = 1;'))->toBe(MigrationPhase::Expand)
        ->and(MigrationPhase::fromSource('<?php /* @phase contarct */ $x = 1;'))->toBe(MigrationPhase::Expand)
        ->and(MigrationPhase::fromSource('<?php /* @phase contract */ $x = 1;'))->toBe(MigrationPhase::Contract)
        ->and(MigrationPhase::fromSource('<?php /* @phase backfill */ $x = 1;'))->toBe(MigrationPhase::Backfill);

    // The tag is read from a comment, which is the one place in this task where
    // a comment is load bearing - so a `@phase contract` in a *string* must not
    // widen anything.
    expect(MigrationPhase::fromSource('<?php $sql = \'@phase contract\';'))->toBe(MigrationPhase::Expand);

    // And the first tag wins, so a second one further down cannot widen it.
    expect(MigrationPhase::fromSource('<?php /* @phase expand */ /* @phase contract */ $x = 1;'))
        ->toBe(MigrationPhase::Expand);
});

it('classifies a path into the tree it belongs to', function () {
    // The tenant directory's name contains the master directory's name as a
    // prefix, so the obvious order of these two tests classifies every tenant
    // migration as a master one.
    expect(MigrationTree::forPath('modules/host/database/migrations-tenant/0001_x.php'))
        ->toBe(MigrationTree::Tenant)
        ->and(MigrationTree::forPath('modules/host/database/migrations/0001_x.php'))
        ->toBe(MigrationTree::Master)
        ->and(MigrationTree::forPath('modules\\host\\database\\migrations-tenant\\0001_x.php'))
        ->toBe(MigrationTree::Tenant);
});
