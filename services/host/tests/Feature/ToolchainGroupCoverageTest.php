<?php

use Illuminate\Support\Facades\File;

/*
 * T-004 FP-155 (AC-3, AC-4, AC-5).
 *
 * Layers 1 and 2 of the `toolchain` group's decay guard, each with a negative
 * control so that "nothing found" is evidence rather than the absence of it.
 * Layer 3 - the proof that the declaration in tests/Pest.php actually binds -
 * needs child processes, so it lives in ToolchainGroupBindingTest.php, which is
 * in the group it describes. Nothing in *this* file spawns anything, which is
 * deliberate: it must run inside `composer test:fast` too.
 *
 * The risk this file exists for is NOT that the gate loses a test - layer 1
 * proves the gate cannot, structurally, because no command it runs carries a
 * group flag. It is the opposite one: somebody adds another test that spawns a
 * child process, forgets the registry in tests/Helpers.php, and the fast command
 * silently stops being fast. Layer 2 catches that by sweeping the repository and
 * comparing what it finds with the registry, the same shape as the
 * qaFixturePackageNames() precedent: an exact list plus a sweep that fails loudly
 * when reality and the list disagree. Because it is in the fast command, the
 * developer who forgot sees it in the inner loop rather than at the end of the
 * gate.
 */

/**
 * Where the throwaway probe file lives. Under storage/app, which is git-ignored.
 */
function toolchainProbePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'toolchain-probe'.DIRECTORY_SEPARATOR.'ProbeTest.php');
}

afterEach(function () {
    deleteFixtureDirectory(dirname(toolchainProbePath()));
});

/*
 * Layer 1 - structural.
 */

it('keeps every group flag out of the gate and only in the fast command', function () {
    /** @var array{scripts?: array<string, mixed>} $manifest */
    $manifest = (array) json_decode((string) File::get(base_path('composer.json')), true);

    /** @var array<string, mixed> $scripts */
    $scripts = (array) ($manifest['scripts'] ?? []);

    foreach (['test', 'check'] as $name) {
        $script = (string) json_encode($scripts[$name] ?? null);

        expect($script)->not->toBe('null');

        foreach (['--group', '--exclude-group'] as $flag) {
            expect(str_contains($script, $flag))->toBeFalse(
                'the '.$name.' script carries '.$flag.', so a tag can now subtract from the gate'
            );
        }
    }

    // The negative control: the same matcher, applied to the one script that is
    // supposed to filter, must report a hit. Without this, "no flag found" would
    // be indistinguishable from a matcher that never matches anything.
    $fast = (string) json_encode($scripts['test:fast'] ?? null);

    expect(str_contains($fast, '--exclude-group=toolchain'))->toBeTrue()
        ->and(str_contains((string) json_encode($scripts['test:slow'] ?? null), '--group=toolchain'))->toBeTrue();

    // phpunit.xml must not declare a group filter either: that would apply to
    // every command, including the gate, with nothing on any command line to
    // show it.
    $configuration = (string) File::get(base_path('phpunit.xml'));

    // Positive control first: an empty or unreadable file would make both
    // absence checks below pass while proving nothing.
    expect(str_contains($configuration, '<testsuites'))->toBeTrue();

    expect(str_contains($configuration, '<groups'))->toBeFalse()
        ->and(str_contains($configuration, '<group'))->toBeFalse();
});

/*
 * Layer 2 - the registry lint.
 */

it('keeps the registry equal to the set of test files that really spawn a child process', function () {
    $spawners = filesSpawningChildProcesses();

    // The sweep looked at something, and found the files it should.
    expect($spawners)->not->toBe([]);

    $expected = toolchainExpectedGroupFiles($spawners);
    $registry = toolchainGroupFiles();

    sort($registry);

    expect($registry)->not->toBe([]);

    $unregistered = array_values(array_diff($expected, $registry));
    $stale = array_values(array_diff($registry, $expected));

    expect($unregistered)->toBe(
        [],
        'these test files spawn a child process but are not in toolchainGroupFiles(), so composer test:fast '.
        'still pays for them: '.implode(', ', $unregistered)
    );

    expect($stale)->toBe(
        [],
        'these files are in toolchainGroupFiles() but no longer spawn a child process, so they are excluded '.
        'from composer test:fast for no reason: '.implode(', ', $stale)
    );

    // A registry entry naming a deleted file is a group that silently shrinks;
    // a stale exemption is a hole the sweep above would not see.
    expect(toolchainMissingPaths($registry))->toBe([]);
    expect(toolchainMissingPaths(toolchainGroupExemptions()))->toBe([]);
});

it('reports a file that spawns a child process and is in no list', function () {
    // The negative control for layer 2: a file nobody registered, written now,
    // read by the same detector, deleted again. If the detector reported
    // nothing, the set equality above would pass on an empty sweep.
    $probe = toolchainProbePath();
    $relative = str_replace('\\', '/', substr($probe, strlen(toolchainRepositoryRoot()) + 1));

    File::ensureDirectoryExists(dirname($probe));

    try {
        foreach (array_keys(toolchainSpawnTokens()) as $token) {
            File::put($probe, '<?php '.$token.'run(["echo", "hi"]);');

            expect(toolchainSourceSpawns((string) File::get($probe)))->toBeTrue(
                'the detector did not report a file using '.$token
            );
        }

        /*
         * And the whole layer-2 comparison, run over a spawner set that includes
         * the probe: it has to come out as the one unregistered file, naming its
         * exact path. This is the code path that catches a forgotten registry
         * entry, exercised end to end on a file planted for the purpose.
         *
         * It replaces an assertion that the registry does not contain the probe's
         * path, which was true by construction and could never have failed - the
         * same shape as QA BUG-2.
         */
        $unregistered = array_values(array_diff(
            toolchainExpectedGroupFiles(array_merge(filesSpawningChildProcesses(), [$relative])),
            toolchainGroupFiles()
        ));

        expect($unregistered)->toBe([$relative]);

        // Without the planted file the same comparison is empty, so the line
        // above is evidence about the probe and not a permanently red guard.
        expect(array_values(array_diff(
            toolchainExpectedGroupFiles(filesSpawningChildProcesses()),
            toolchainGroupFiles()
        )))->toBe([]);
    } finally {
        deleteFixtureDirectory(dirname($probe));
    }

    expect(File::exists($probe))->toBeFalse();
});

it('keeps every credential-leak proof out of the group', function () {
    // AC-5, asserted by name rather than trusted. These two run under the fast
    // command and under the gate, whatever else changes.
    foreach ([
        'packages/kernel/tests/Feature/TenancyCredentialLeakTest.php',
        'packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php',
    ] as $path) {
        expect(is_file(toolchainRepositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path)))
            ->toBeTrue($path.' has moved or been renamed; AC-5 names it explicitly, so update this list deliberately');

        expect(toolchainGroupFiles())->not->toContain($path);
    }

    // And the one that spawns is exempt on purpose, with its reason written down
    // in tests/Helpers.php, not merely absent by luck.
    expect(toolchainGroupExemptions())->toContain('packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php');
});
