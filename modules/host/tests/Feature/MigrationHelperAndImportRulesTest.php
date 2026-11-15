<?php

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| The rules follow the statement into a shared helper
|--------------------------------------------------------------------------
|
| T-008 step 13a, checks (c), (d) and (e) - the three rule changes step 13 was
| not scoped to carry. They are one commit over one file's rules rather than a
| reopening of the ticked step 2.
|
| **Why all three, and why together.**
|
| (c) `SourceRules` reads migration *files*, so a drop inside a helper the
| migration calls is invisible to it, and what stood in for the rule was a
| sentence in each helper's docblock - *additive by construction*. True today,
| and a property of the implementation rather than of the type.
|
| (d) a scan over a *set of files* is only as good as the set. Without the
| import rule, (c) scans one directory and a helper placed in a second
| namespace is invisible again, so (d) is not a follow-up: it is what makes
| (c) a complete statement.
|
| (e) AC-35(a) as worded asked only for a precision, so `decimal('x', 18)`
| passed with the framework defaulting the scale. Ruling 12.3 tightened it to
| both arguments and said in the same breath that the rule is **stricter than
| its criterion**; so is (c)'s both-connections reading. Both are declared in
| `SourceRules`, where the next reader meets them.
|
| **Red first, because the real subjects are silent.** The two shipped helpers
| and both shipped trees report nothing today, so a green check over them
| proves nothing at all - the R-4 shape, in this very step. Every rule below
| is therefore asserted against a planted fixture first and only then against
| the real files, and each has a negative control: the same words in prose, and
| a clean helper.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * The directory check (c)'s subject is.
 *
 * The real one, read from the repository rather than from a list: a helper
 * added to it tomorrow is scanned on the day it lands, which is the whole
 * point of bounding the set by directory instead of by name.
 */
function helperRuleSupportDirectory(): string
{
    return dirname(base_path(), 2)
        .str_replace('/', DIRECTORY_SEPARATOR, '/packages/kernel/src/Schema/Support');
}

/**
 * Every file check (c) scans, as path => source.
 *
 * @return array<string, string>
 */
function helperRuleSupportSources(): array
{
    $sources = [];

    foreach (glob(helperRuleSupportDirectory().DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
        $sources[str_replace(DIRECTORY_SEPARATOR, '/', $path)] = (string) file_get_contents($path);
    }

    return $sources;
}

/**
 * One planted helper fixture's source.
 */
function helperRuleFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/SchemaSupportHelpers/'.$name.'.php'));
}

/**
 * One planted migration fixture's source.
 */
function helperRuleMigrationFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/MigrationSourceRules/'.$name.'.php'));
}

/**
 * The rule ids a scan reported, sorted, duplicates kept.
 *
 * @param  list<array{rule: string, found: string}>  $violations
 * @return list<string>
 */
function helperRuleIds(array $violations): array
{
    $rules = array_map(static fn (array $violation): string => $violation['rule'], $violations);

    sort($rules);

    return $rules;
}

/*
|--------------------------------------------------------------------------
| (c) red first, on planted helpers
|--------------------------------------------------------------------------
*/

it('reports a shared helper that drops or renames, with no phase that could license it', function () {
    // The file the whole check exists for: a migration calling it declares
    // `@phase expand` and passes AC-33, because the rule reads the migration
    // and the statement is in the helper.
    expect(helperRuleIds(SourceRules::inspectSupportHelper(helperRuleFixture('helper_that_drops'))))
        ->toBe(['AC-33', 'AC-33']);
});

it('reports a raw statement whose verb arrives in a variable', function () {
    // The extra tooth, and the real failure mode of a text scan: no rule can
    // read a verb it cannot see, so the rule is about the shape of the call.
    expect(helperRuleIds(SourceRules::inspectSupportHelper(helperRuleFixture('helper_with_a_variable_statement'))))
        ->toBe(['AC-33(c)', 'AC-33(c)']);
});

it('reports a shared helper naming another connection', function () {
    // Stricter than AC-37's wording on purpose: the criterion picks the
    // forbidden name from the tree a file is in, and a helper is in no tree.
    expect(helperRuleIds(SourceRules::inspectSupportHelper(helperRuleFixture('helper_naming_the_master_connection'))))
        ->toBe(['AC-37']);
});

it('reports nothing in a helper that only adds', function () {
    // The negative control. Without it every case above passes against a
    // scan that reports everything - and this fixture carries the shape both
    // shipped helpers have, including a raw statement that begins with a
    // literal.
    expect(SourceRules::inspectSupportHelper(helperRuleFixture('clean_helper')))->toBe([]);
});

it('reports nothing in a helper that only talks about dropping', function () {
    // The mechanical control: comments and docblocks are stripped, so the
    // same words in prose are not a violation. A scan over raw text would
    // report this file, and its obvious repair would be an exemption list.
    expect(SourceRules::inspectSupportHelper(helperRuleFixture('prose_about_dropping')))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| (c) green on the real Support directory, and looking at something
|--------------------------------------------------------------------------
*/

it('scans every file of the real Support directory and finds the helpers it is about', function () {
    $scanned = array_keys(helperRuleSupportSources());

    // Named, not counted: a count is also satisfied by a glob that matched a
    // different set of files. These two are the helpers ruling 12.1 is about.
    $names = array_map(static fn (string $path): string => basename($path), $scanned);

    expect($names)->toContain('IdentifierColumn.php')
        ->toContain('JsonValidity.php')
        ->and($scanned)->not->toBe([]);
});

it('reports nothing in the shared helpers as they are today', function () {
    $reported = [];

    foreach (helperRuleSupportSources() as $path => $source) {
        $violations = SourceRules::inspectSupportHelper($source);

        if ($violations !== []) {
            $reported[$path] = $violations;
        }
    }

    // A map, so a failure names the helper and the rule rather than a count.
    expect($reported)->toBe([]);
});

it('scans its own scanner', function () {
    // SourceRules lives in the directory it polices. A scanner that exempted
    // itself could be used to hide exactly what it looks for, which is the
    // precedent the containment checks set in step 13.
    expect(array_keys(helperRuleSupportSources()))
        ->toContain(str_replace(DIRECTORY_SEPARATOR, '/', helperRuleSupportDirectory().DIRECTORY_SEPARATOR.'SourceRules.php'));
});

/*
|--------------------------------------------------------------------------
| (d) the import rule, and the scope that keeps (c) complete
|--------------------------------------------------------------------------
*/

it('reports a migration importing from a third namespace', function () {
    // Red first: this is the hole (c) alone leaves. The statement that takes
    // the column away is in a namespace nothing scans, and the import is the
    // thing a scan can see.
    expect(SourceRules::importsOutsideAllowedNamespaces(
        helperRuleMigrationFixture('migration_importing_outside_the_namespaces')
    ))->toBe([['rule' => 'AC-37(d)', 'found' => 'Tests\Fixtures\TenantMigrations\FixtureMigrationLog']]);
});

it('reports nothing for a migration importing only the framework and the shared helpers', function () {
    // The negative control, from a fixture that imports both allowed
    // namespaces.
    expect(SourceRules::importsOutsideAllowedNamespaces(
        helperRuleMigrationFixture('clean_expand_step')
    ))->toBe([]);
});

it('applies the import rule to exactly the two configured trees, and to a non-empty set', function () {
    $scanned = [];
    $reported = [];

    foreach ([MigrationTree::Master, MigrationTree::Tenant] as $tree) {
        foreach (glob(migrationTreeDirectory($tree).DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $scanned[] = str_replace(DIRECTORY_SEPARATOR, '/', $path);

            $violations = SourceRules::importsOutsideAllowedNamespaces((string) file_get_contents($path));

            if ($violations !== []) {
                $reported[str_replace(DIRECTORY_SEPARATOR, '/', $path)] = $violations;
            }
        }
    }

    // The tooth that keeps a scope rule honest (section 14.2): the scanned
    // set is asserted **non-empty and equal to the two configured trees'
    // contents**, so a scope that silently became nothing is red rather than
    // green. The trees are read from config('schema.*') through the shared
    // helper, never from a literal.
    $expected = [];

    foreach ([MigrationTree::Master, MigrationTree::Tenant] as $tree) {
        foreach (glob(migrationTreeDirectory($tree).DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $expected[] = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        }
    }

    expect($scanned)->not->toBe([])
        ->and($scanned)->toBe($expected)
        ->and(count($scanned))->toBeGreaterThan(20)
        ->and($reported)->toBe([]);
});

it('leaves fixture migrations outside the import rule subject', function () {
    // Stated as a case rather than left implicit, because it is the reason
    // FX-3 may import FixtureMigrationLog and the reason this is a **scope**
    // and not an exemption: an exemption list would rot, and its first entry
    // is always for a file that obviously does not mean it (section 14.2).
    $fx3 = glob(base_path('tests/Fixtures/TenantMigrations/FX3').DIRECTORY_SEPARATOR.'*.php') ?: [];

    expect($fx3)->toHaveCount(1);

    // The rule *would* report it - so the scope is doing real work rather
    // than describing a file that happens to comply.
    expect(SourceRules::importsOutsideAllowedNamespaces((string) file_get_contents($fx3[0])))
        ->not->toBe([]);

    // And it is not under either shipped tree, which is what puts it outside
    // the subject. That path rule has its own check in step 13.
    foreach ([MigrationTree::Master, MigrationTree::Tenant] as $tree) {
        expect(dirname($fx3[0]))->not->toBe(migrationTreeDirectory($tree));
    }
});

/*
|--------------------------------------------------------------------------
| (e) decimal needs both of its arguments
|--------------------------------------------------------------------------
*/

it('reports a decimal column with a precision and no scale', function () {
    // Red first, because both shipped trees are green: every decimal column
    // in them is fully specified, so the fixture pair is the entire proof
    // that the tightening is a rule and not a no-op.
    expect(helperRuleIds(SourceRules::inspect(
        helperRuleMigrationFixture('decimal_with_precision_but_no_scale'),
        MigrationTree::Tenant
    )))->toBe(['AC-35(a)']);
});

it('reports nothing for a decimal column with both arguments', function () {
    expect(SourceRules::inspect(
        helperRuleMigrationFixture('decimal_with_precision_and_scale'),
        MigrationTree::Tenant
    ))->toBe([]);
});

it('still reports a decimal column with no arguments at all', function () {
    // The tightening did not replace the original rule; a bare decimal() is
    // still the worse case and still reported.
    expect(helperRuleIds(SourceRules::inspect(
        helperRuleMigrationFixture('decimal_without_precision'),
        MigrationTree::Tenant
    )))->toBe(['AC-35(a)']);
});
