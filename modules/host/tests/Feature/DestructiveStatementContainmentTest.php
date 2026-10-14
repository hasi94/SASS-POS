<?php

/*
|--------------------------------------------------------------------------
| The two teeth of the red-first constraint
|--------------------------------------------------------------------------
|
| T-008, plan section 11.2 (added after the incident of 2026-10-14, when a
| permissive stub of ReservedDatabaseName was handed to an engine-wide sweep and
| sixteen of the owner's databases were dropped, none of them backed up).
|
| The constraint itself is prose; these two checks are what make it enforceable
| rather than remembered:
|
| 1. **No file outside Flexipos\Testing\Engine\EngineDatabase issues a database
|    drop.** tools/clean-engine.php used to, behind a count ceiling; the ceiling
|    is a guess - a filter that wrongly returns twenty foreign names passes it -
|    so the capability was deleted instead of bounded. The only job it had is
|    already done by EngineDatabase::recreate(), which drops the one name it is
|    about to create.
| 2. **No stub, fake or double of ReservedDatabaseName exists anywhere in the
|    branch.** This is the one that would have prevented the incident: the guard
|    class was replaced by a permissive version to prove a test red, and that
|    object was then handed to something that could act. A guard is never
|    stubbed permissively; the permissive direction is proven by *inputs* handed
|    to the real guard (ReservedDatabaseNameGuardTest).
|
| Both checks scan code with comments and docblocks removed, because the files
| in question discuss these very shapes at length in their own prose - and on
| this project a literal search has already been defeated twice, once by a line
| wrap and once by a bold marker.
|
| **This file is scanned by its own checks.** That is deliberate: a scanner that
| exempts itself is a scanner that can be used to hide the thing it looks for.
| It is also why every positive control below builds its forbidden shape by
| string concatenation - the fixture handed to the detector contains the shape,
| the source of this file does not.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Directory names that are not this project's code.
 *
 * Pruned during the walk rather than filtered afterwards: a `vendor/` tree per
 * package is tens of thousands of files, and Symfony Finder's `exclude()`
 * matches only at the root it was given, so it would miss
 * modules/host/vendor entirely.
 *
 * @return list<string>
 */
function containmentPrunedDirectories(): array
{
    return ['vendor', 'node_modules', 'storage', '.git', 'bin', 'obj', 'dist'];
}

/**
 * Every PHP file in the repository that this project owns.
 *
 * Dependencies, caches and build output are excluded; everything else is in
 * scope, because the rule is about the branch and not about one directory.
 *
 * @return array<string, string> repository-relative path (forward slashes) => source
 */
function containmentOwnedPhpSources(): array
{
    $root = str_replace(DIRECTORY_SEPARATOR, '/', dirname(base_path(), 2));
    $pruned = containmentPrunedDirectories();
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
 * The file that owns the drop, as a repository-relative path.
 */
function containmentDropOwner(): string
{
    return 'packages/testing/src/Engine/EngineDatabase.php';
}

/**
 * The given PHP source with every comment and docblock removed.
 *
 * Tokenised rather than regexed: the rule is about code, so the assertions have
 * to be about code.
 */
function containmentCodeOnly(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? (string) $token[1] : (string) $token;
    }

    return $code;
}

/**
 * Occurrences of a database drop in code.
 *
 * Matches the statement's words rather than one punctuation shape, so
 * `$pdo->exec('drop database if exists ...')`, a heredoc and
 * `DROP   SCHEMA` are all found.
 *
 * @return list<string>
 */
function containmentDatabaseDropsInCode(string $source): array
{
    preg_match_all(
        '/\bdrop\s+(?:database|schema)\b.{0,40}/is',
        containmentCodeOnly($source),
        $matches
    );

    return array_map(static fn (string $found): string => trim($found), $matches[0]);
}

/**
 * Occurrences of a stub, fake, double or redeclaration of the name guard in
 * code.
 *
 * Three shapes, because a guard can be replaced three ways: a second class
 * whose name contains the guard's, a container or framework double built from
 * it, and an alias pointed at something else.
 *
 * @return list<string>
 */
function containmentGuardDoublesInCode(string $source): array
{
    $code = containmentCodeOnly($source);

    $patterns = [
        '/\b(?:class|interface|trait|enum)\s+\w*ReservedDatabaseName\w*/i',
        '/\b(?:mock|spy|partialMock|createMock|createStub|createPartialMock|getMockBuilder|'
            .'instance|swap|bind|singleton|alias)\s*\(\s*\\\\?[\w\\\\]*ReservedDatabaseName\b/i',
        '/\bextends\s+\w*ReservedDatabaseName\b/i',
        // class_alias names the replacement first and the name it steals
        // second, so the name can be anywhere in the call.
        '/\bclass_alias\s*\([^)]*ReservedDatabaseName\b/i',
    ];

    $found = [];

    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $code, $matches);

        foreach ($matches[0] as $match) {
            $found[] = trim((string) $match);
        }
    }

    return $found;
}

/**
 * The files allowed to declare a class whose name contains the guard's: the
 * guard itself and its exception, each in its own file.
 *
 * @return array<string, string>
 */
function containmentGuardDeclarationHomes(): array
{
    // The declaration strings are split mid-name for the reason given at the
    // top of this file: the expectations are scanned by their own check, and a
    // whole name written out here would be reported as a redeclaration.
    return [
        'packages/testing/src/Engine/ReservedDatabaseName.php' => 'class Reserved'.'DatabaseName',
        'packages/testing/src/Engine/ReservedDatabaseNameViolation.php' => 'class Reserved'.'DatabaseNameViolation',
    ];
}

/*
|--------------------------------------------------------------------------
| The scan itself has to be looking at something
|--------------------------------------------------------------------------
*/

it('scans the files the two checks are about', function () {
    $sources = containmentOwnedPhpSources();

    expect(array_keys($sources))
        ->toContain(containmentDropOwner())
        ->toContain('modules/host/tools/clean-engine.php')
        ->toContain('modules/host/tests/Feature/DestructiveStatementContainmentTest.php')
        ->and(count($sources))->toBeGreaterThan(50);
});

/*
|--------------------------------------------------------------------------
| Check 1 - the drop lives in exactly one class
|--------------------------------------------------------------------------
*/

it('issues a database drop in no file but the class that owns it', function () {
    $offenders = [];

    foreach (containmentOwnedPhpSources() as $path => $source) {
        if ($path === containmentDropOwner()) {
            continue;
        }

        $drops = containmentDatabaseDropsInCode($source);

        if ($drops !== []) {
            $offenders[$path] = $drops;
        }
    }

    // Compared as a map, so a failure names the file and the statement it found
    // rather than only a count.
    expect($offenders)->toBe([]);
});

it('still finds the one drop the owning class is allowed to issue', function () {
    // Without this, the check above would also pass if the detector never
    // matched anything at all - which is how a containment test becomes
    // decorative. The owner must keep exactly the drop it is there for.
    $source = containmentOwnedPhpSources()[containmentDropOwner()];

    expect(containmentDatabaseDropsInCode($source))->toHaveCount(1);
});

it('reports a database drop in code and ignores one in prose', function () {
    // The positive control, built by concatenation so this file's own source
    // does not carry the shape its own check forbids.
    $forbidden = '<?php $pdo->exec(\'drop'.' database if exists `flexipos_t8test_a`\');';

    expect(containmentDatabaseDropsInCode($forbidden))->toHaveCount(1)
        ->and(containmentDatabaseDropsInCode($forbidden)[0])->toStartWith('drop');

    // The negative control: the same words in a comment and a docblock, which
    // is how every file in packages/testing/src/Engine discusses the rule.
    $prose = '<?php'."\n"
        .'// the sweep used to drop'.' database after database'."\n"
        .'/** never issue a drop'.' database here */'."\n"
        .'$harmless = 1;';

    expect(containmentDatabaseDropsInCode($prose))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Check 2 - the name guard has no double, anywhere
|--------------------------------------------------------------------------
*/

it('holds no stub, fake or double of the reserved name guard anywhere in the branch', function () {
    $found = [];

    foreach (containmentOwnedPhpSources() as $path => $source) {
        $doubles = containmentGuardDoublesInCode($source);

        if ($doubles !== []) {
            $found[$path] = $doubles;
        }
    }

    // The expected map is exact, not a subset: the only declarations of this
    // name in the repository are the guard itself and its exception, each once
    // and each in its own file. Anything else - a second class, a mock, an
    // alias, a subclass - is a double, and this is the check whose absence cost
    // the owner sixteen databases.
    $expected = array_map(
        static fn (string $declaration): array => [$declaration],
        containmentGuardDeclarationHomes()
    );

    ksort($expected);

    expect($found)->toBe($expected);
});

it('reports every shape of guard double, and ignores the real class being used', function () {
    // Positive controls, concatenated for the same reason as above. All three
    // are shapes that were available on 2026-10-14; the first one is what ran.
    $stubClass = '<?php final class StubReserved'.'DatabaseName { public static function assert(string $n): void {} }';
    $redeclared = '<?php final class Reserved'.'DatabaseName { }';
    $mocked = '<?php $guard = mock(Reserved'.'DatabaseName::class);';
    $aliased = '<?php class_alias(Permissive::class, \'Reserved'.'DatabaseName\');';
    $extended = '<?php final class Loose extends Reserved'.'DatabaseName { }';

    expect(containmentGuardDoublesInCode($stubClass))->toHaveCount(1)
        ->and(containmentGuardDoublesInCode($redeclared))->toHaveCount(1)
        ->and(containmentGuardDoublesInCode($mocked))->toHaveCount(1)
        ->and(containmentGuardDoublesInCode($aliased))->toHaveCount(1)
        ->and(containmentGuardDoublesInCode($extended))->toHaveCount(1);

    // The negative control, and the one that matters: calling the real guard,
    // naming its constant, catching its exception and mocking an unrelated
    // class are all normal and none of them is a double.
    $legitimate = '<?php'."\n"
        .'use Flexipos\Testing\Engine\ReservedDatabaseName;'."\n"
        .'ReservedDatabaseName::assert($name);'."\n"
        .'$prefix = ReservedDatabaseName::PREFIX;'."\n"
        .'expect($f)->toThrow(ReservedDatabaseNameViolation::class);'."\n"
        .'$other = mock(SomethingElse::class);';

    expect(containmentGuardDoublesInCode($legitimate))->toBe([]);
});
