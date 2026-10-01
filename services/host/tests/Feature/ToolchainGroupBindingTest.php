<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/*
 * T-004 FP-155 (AC-3, AC-4) - layer 3 of the `toolchain` group's decay guard.
 *
 * Layers 1 and 2 live in ToolchainGroupCoverageTest.php and use no child
 * process, so they run in `composer test:fast` as well. This is the layer they
 * cannot cover: whether the pest()->group('toolchain')->in(...) declaration in
 * tests/Pest.php actually binds. Reading the registry and reading composer.json
 * both pass perfectly well against a declaration that silently does nothing, and
 * `test:fast` would then run everything while looking correct.
 *
 * Proving it needs Pest to answer for itself, so this file spawns - which is why
 * it is a member of the group it describes, and why layer 2 confirms that rather
 * than tripping over it.
 *
 * The children use --list-tests rather than a real run. Collection is exactly
 * what decides which cases a group selects, and executing the group from inside
 * the gate would add its whole cost to the gate a second time, which is the
 * opposite of what this task is for. Three collection-only children cost about
 * three seconds between them.
 */

/**
 * One Pest child process, in the host directory.
 *
 * @param  list<string>  $arguments
 */
function toolchainPest(array $arguments): ProcessResult
{
    return Process::path(base_path())
        ->run(array_merge([PHP_BINARY, base_path('vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pest'), '--colors=never'], $arguments));
}

/**
 * The distinct test classes behind a `pest --list-tests` run.
 *
 * Pest names a generated test class P\<namespace>\<Basename>. For a host test the
 * namespace is the PSR-4 root (P\Tests\Feature\X); for a package test outside any
 * root Pest folds the whole file path into it, so the class is the closest thing
 * to a file identity the listing offers.
 *
 * @return list<string>
 */
function toolchainListedClasses(string $output): array
{
    preg_match_all('/^ - (\S+)::/m', $output, $matches);

    $classes = array_values(array_unique($matches[1]));

    sort($classes);

    return $classes;
}

/**
 * The test-file basenames behind a `pest --list-tests` run.
 *
 * The trailing segment of each distinct class, which is the file it came from.
 * Basenames are only a faithful stand-in for paths while no two files share one,
 * and that is NOT free here: `svc:make` writes tests/Feature/PingRouteTest.php
 * into every package it generates and the committed services/demo ships that
 * name too, so a second package on disk during collection would silently make two
 * different files look like one. The case below asserts the mapping is injective
 * by comparing these against toolchainListedClasses() - counting this list
 * against its own array_unique() would compare a de-duplicated list with itself
 * and could never fail (QA BUG-2).
 *
 * @return list<string>
 */
function toolchainListedFiles(string $output): array
{
    $files = array_values(array_unique(array_map(
        static function (string $class): string {
            $segments = explode('\\', $class);

            return (string) end($segments);
        },
        toolchainListedClasses($output)
    )));

    sort($files);

    return $files;
}

/**
 * Registry entries as bare basenames, to compare with toolchainListedFiles().
 *
 * @return list<string>
 */
function toolchainRegistryBasenames(): array
{
    $names = array_map(
        static fn (string $path): string => basename($path, '.php'),
        toolchainGroupFiles()
    );

    sort($names);

    return $names;
}

it('binds the group to exactly the registry, and loses no test to the split', function () {
    $groups = toolchainPest(['--list-groups']);

    expect($groups->exitCode())->toBe(0)
        ->and($groups->output())->toContain('toolchain');

    $slow = toolchainPest(['--group=toolchain', '--list-tests']);
    $fast = toolchainPest(['--exclude-group=toolchain', '--list-tests']);
    $full = toolchainPest(['--list-tests']);

    foreach ([$slow, $fast, $full] as $result) {
        expect($result->exitCode())->toBe(0);
    }

    $slowFiles = toolchainListedFiles($slow->output());
    $fastFiles = toolchainListedFiles($fast->output());
    $fullFiles = toolchainListedFiles($full->output());

    // The declaration really binds: the group selects exactly the registry's
    // files and nothing else.
    expect($slowFiles)->toBe(toolchainRegistryBasenames());

    /*
     * QA BUG-2: basenames are only a faithful stand-in for paths while the
     * class -> basename mapping is injective, and the previous version compared a
     * de-duplicated list against its own array_unique(), which is equal by
     * construction and could never fail.
     *
     * Distinct classes against distinct basenames can fail, and the way it fails
     * is reachable: every package `svc:make` generates contains
     * tests/Feature/PingRouteTest.php and services/demo ships that name, so a
     * generated package left on disk by a crashed run - or a second committed one
     * - collapses two files into one basename here.
     */
    $fullClasses = toolchainListedClasses($full->output());

    $collisions = [];

    foreach ($fullClasses as $class) {
        $segments = explode('\\', $class);
        $collisions[(string) end($segments)][] = $class;
    }

    $shared = array_keys(array_filter($collisions, fn (array $classes): bool => count($classes) > 1));

    expect($shared)->toBe(
        [],
        'two or more collected test files share a basename, so comparing the toolchain group by basename would '.
        'conflate them: '.implode(', ', $shared).'. The usual cause is a generated fixture package left under '.
        'services/ by a crashed run - every one of them ships tests/Feature/PingRouteTest.php.'
    );

    expect(count($fullFiles))->toBe(count($fullClasses));

    // The split is exhaustive and disjoint, by name, inside the gate.
    $union = array_values(array_unique(array_merge($slowFiles, $fastFiles)));
    sort($union);

    expect($union)->toBe($fullFiles)
        ->and(array_values(array_intersect($slowFiles, $fastFiles)))->toBe([]);

    // The exclusion is real, not vacuous, in both directions.
    expect($slowFiles)->not->toBe([])
        ->and($fastFiles)->not->toBe([])
        ->and(count($fullFiles))->toBeGreaterThan(count($slowFiles));

    // The case counts add up too, not only the file sets.
    $slowCases = substr_count($slow->output(), "\n - ");
    $fastCases = substr_count($fast->output(), "\n - ");
    $fullCases = substr_count($full->output(), "\n - ");

    expect($slowCases + $fastCases)->toBe($fullCases)
        ->and($slowCases)->toBeGreaterThan(0);
});
