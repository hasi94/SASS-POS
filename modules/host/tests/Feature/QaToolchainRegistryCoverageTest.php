<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/*
 * QA regression guard for T-001 AC-9, AC-11, AC-13 and AC-16.
 *
 * phpstan.neon.dist, deptrac.yaml and phpunit.xml all list their paths and
 * layers literally - the architect's plan rejected globbing on purpose. That is
 * a defensible choice, but nothing currently notices when a package is added and
 * the lists are not updated: a module outside phpstan's paths passes
 * `composer stan` with real level 6 errors in it, a module outside
 * deptrac's layers can import another module without a violation, and a test
 * directory outside phpunit.xml never runs. All three were reproduced by hand
 * during QA of T-001.
 *
 * These tests turn that manual discipline into an enforced invariant: however
 * the lists are maintained, by hand or by a glob, every package on disk must
 * appear in all three - and, where the registry is not the whole story, must
 * actually be analysed by the tool that names it.
 *
 * T-009 step 5b widened the first two checks from `src` to `src` **and**
 * `tests`. They had only ever looked at `src`, so a package whose test
 * directory was in neither registry was invisible to the guard whose own
 * header, above, says it exists so that nothing goes unnoticed when a list is
 * not updated - it could not notice half of one, and packages/auth/tests was
 * exactly that case for the length of T-009's first five steps. Both new
 * checks are guarded by is_dir(), the way qaPhpunitGaps() already is, so a
 * package shipping no tests is not failed for having none. The reason test
 * code must be in both registries is written out in each configuration file:
 * phpstan.neon.dist says a test file is as capable of a level 6 mistake as a
 * source file, and deptrac.yaml says test code is boundary-checked because a
 * layer is matched by namespace and not by directory.
 *
 * **W-2: for Deptrac, registration is not coverage.** PHPStan matches on
 * files; Deptrac matches on namespaces. Every package test tree on disk is
 * written as Pest files in the global namespace, so each registered `tests`
 * path contributes no collectable token and the three new paths step 5b
 * required are inert - a tree inside them reports zero violations because
 * nothing in it was collected, not because everything in it is clean. The only
 * test directory Deptrac really analyses is the host's own, and only because
 * composer.json autoload-dev gives it `Tests\` and deptrac.yaml gives that
 * namespace a layer.
 *
 * So qaDeptracGaps() now also reports a registered test tree that no layer
 * collects, and the two Deptrac expectations below are deliberately *not*
 * `toBe([])`: they equal qaRecordedDeptracTestTreeGaps(), the named list of the
 * trees this is currently true of. Asserted bare, the check would fail the gate
 * for three pre-existing trees at once; recorded, it states what is open and
 * still reports any tree that is not on the list. See
 * qaGlobalNamespaceTestTrees() for the list and for why emptying it is FP-303's
 * definition of done.
 */

/*
 * qaFixturePackageNames() - the exact names of the throwaway modules
 * this suite generates while it runs (T-002 AC-18, decision D-23) - now lives in
 * tests/Helpers.php, which Pest loads before any test file. T-004 FP-164 moved
 * it there because filesSpawningChildProcesses() needs the same list; the
 * function, its name and its contents are unchanged and no call site in this
 * file changed.
 */

/**
 * Modules committed under modules/, excluding the host application and
 * the throwaway packages other tests generate while they run.
 *
 * @return array<string, string>
 */
function qaModulePackages(): array
{
    $packages = [];

    foreach (glob(modulesPath().DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
        $name = basename($directory);

        if ($name === 'host' || in_array($name, qaFixturePackageNames(), true)) {
            continue;
        }

        $packages[$name] = $directory;
    }

    return $packages;
}

/**
 * The shared packages under packages/.
 *
 * @return array<string, string>
 */
function qaSharedPackages(): array
{
    $root = dirname(modulesPath()).DIRECTORY_SEPARATOR.'packages';
    $packages = [];

    foreach (glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
        $packages[basename($directory)] = $directory;
    }

    return $packages;
}

/**
 * A class name guaranteed to sit inside a package's own namespace, taken from
 * the package's own composer.json rather than from a naming convention.
 */
function qaProbeClassFor(string $packageDirectory): string
{
    /** @var array<string, mixed> $manifest */
    $manifest = (array) json_decode((string) file_get_contents($packageDirectory.DIRECTORY_SEPARATOR.'composer.json'), true);

    /** @var array<string, string> $psr4 */
    $psr4 = $manifest['autoload']['psr-4'] ?? [];

    return ((string) array_key_first($psr4)).'QaProbe';
}

/**
 * Every distinct namespace declared by the PHP files in a directory tree.
 *
 * Deptrac matches a layer by namespace, so this - and not the directory, and
 * not the registry - is what decides whether anything in a tree can be
 * collected. A file declaring no namespace contributes nothing.
 *
 * @return list<string>
 */
function qaDeclaredNamespacesIn(string $directory): array
{
    $namespaces = [];

    /** @var iterable<mixed> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entries as $entry) {
        if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($entry->getPathname());

        if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $matches) === 1) {
            $namespaces[$matches[1]] = true;
        }
    }

    return array_keys($namespaces);
}

/**
 * Test trees that are registered in deptrac.yaml and that no layer collects,
 * because every file in them declares no namespace at all.
 *
 * This list is the whole difference between this guard stating the true thing -
 * "registered, nothing collects it, and that is recorded" - and silently
 * passing. Registration alone buys nothing for Deptrac: PHPStan matches on
 * files, Deptrac matches on namespaces, so a path whose files are all in the
 * global namespace is inert however many registries name it. Each entry
 * therefore carries the row that will fix it.
 *
 * It is self-liquidating: emptying it is FP-303's definition of done. The guard
 * below asserts every entry is still genuinely global-namespaced, so the moment
 * FP-303 namespaces a tree the entry must be deleted or the gate goes red.
 *
 * Not an exemption from a boundary rule and not a baseline: the trees are
 * registered and will be collected the moment they are namespaced, which is
 * also why the packages/auth/tests path stays in deptrac.yaml rather than being
 * removed as unused - removing it would make FP-303 responsible for restoring
 * it.
 *
 * @return array<string, string> relative tests path => row that will empty it
 */
function qaGlobalNamespaceTestTrees(): array
{
    return [
        'modules/demo/tests' => 'FP-303',
        'packages/auth/tests' => 'FP-303',
        'packages/kernel/tests' => 'FP-303',
    ];
}

/**
 * The gaps qaDeptracGaps() is expected to report for the recorded trees under
 * one label, in the order it reports them.
 *
 * @return list<string>
 */
function qaRecordedDeptracTestTreeGaps(string $label): array
{
    $gaps = [];

    foreach (array_keys(qaGlobalNamespaceTestTrees()) as $tree) {
        if (str_starts_with($tree, $label.'/')) {
            $gaps[] = "no deptrac.yaml layer collects any namespace in {$tree}";
        }
    }

    return $gaps;
}

/**
 * The Deptrac layer that collects a class, or null if no layer does.
 *
 * @param  array<string, list<string>>  $layers
 */
function qaDeptracLayerFor(string $class, array $layers): ?string
{
    $owning = null;

    foreach ($layers as $layer => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match('{'.$pattern.'}', $class) === 1) {
                $owning = $layer;
            }
        }
    }

    return $owning;
}

/**
 * Whether any Deptrac layer collects anything declared in a test tree.
 *
 * @param  array<string, list<string>>  $layers
 */
function qaDeptracCollectsTestTree(string $directory, array $layers): bool
{
    foreach (qaDeclaredNamespacesIn($directory) as $namespace) {
        if (qaDeptracLayerFor($namespace.'\\QaProbe', $layers) !== null) {
            return true;
        }
    }

    return false;
}

/**
 * Absolute, resolved analysis paths from phpstan.neon.dist.
 *
 * @return list<string>
 */
function qaPhpstanPaths(): array
{
    $lines = file(base_path('phpstan.neon.dist'), FILE_IGNORE_NEW_LINES) ?: [];
    $paths = [];
    $inPathsBlock = false;

    foreach ($lines as $line) {
        if (preg_match('/^\s{4}paths:\s*$/', $line) === 1) {
            $inPathsBlock = true;

            continue;
        }

        if ($inPathsBlock && preg_match('/^\s{4}\S/', $line) === 1) {
            break;
        }

        if ($inPathsBlock && preg_match('/^\s*-\s*(\S+)\s*$/', $line, $matches) === 1) {
            $resolved = realpath(base_path($matches[1]));

            if (is_string($resolved)) {
                $paths[] = $resolved;
            }
        }
    }

    return $paths;
}

/**
 * Describe every way phpstan.neon.dist fails to analyse the given packages.
 *
 * @param  array<string, string>  $packages
 * @return list<string>
 */
function qaPhpstanGaps(array $packages, string $label): array
{
    $paths = qaPhpstanPaths();
    $gaps = [];

    foreach ($packages as $name => $directory) {
        if (! in_array(realpath($directory.DIRECTORY_SEPARATOR.'src'), $paths, true)) {
            $gaps[] = "{$label}/{$name}/src is not in phpstan.neon.dist paths";
        }

        $tests = $directory.DIRECTORY_SEPARATOR.'tests';

        if (is_dir($tests) && ! in_array(realpath($tests), $paths, true)) {
            $gaps[] = "{$label}/{$name}/tests is not in phpstan.neon.dist paths";
        }
    }

    return $gaps;
}

/**
 * Describe every package whose tests no phpunit.xml testsuite collects.
 *
 * @param  array<string, string>  $packages
 * @return list<string>
 */
function qaPhpunitGaps(array $packages): array
{
    $collected = qaPhpunitTestDirectories();
    $gaps = [];

    foreach ($packages as $name => $directory) {
        $tests = $directory.DIRECTORY_SEPARATOR.'tests';

        if (is_dir($tests) && ! in_array(realpath($tests), $collected, true)) {
            $gaps[] = "{$name}/tests is not collected by any phpunit.xml testsuite";
        }
    }

    return $gaps;
}

/**
 * @return array{paths: list<string>, layers: array<string, list<string>>, ruleset: array<string, mixed>}
 */
function qaDeptracConfig(): array
{
    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parseFile(base_path('deptrac.yaml'));

    /** @var array<string, mixed> $config */
    $config = $parsed['deptrac'];

    $paths = [];

    foreach ((array) $config['paths'] as $path) {
        $resolved = realpath(base_path((string) $path));

        if (is_string($resolved)) {
            $paths[] = $resolved;
        }
    }

    $layers = [];

    foreach ((array) $config['layers'] as $layer) {
        $patterns = [];

        foreach ((array) ($layer['collectors'] ?? []) as $collector) {
            if (($collector['type'] ?? null) === 'classLike') {
                $patterns[] = (string) $collector['value'];
            }
        }

        $layers[(string) $layer['name']] = $patterns;
    }

    return ['paths' => $paths, 'layers' => $layers, 'ruleset' => (array) $config['ruleset']];
}

/**
 * Describe every way deptrac.yaml fails to cover the given packages.
 *
 * @param  array<string, string>  $packages
 * @return list<string>
 */
function qaDeptracGaps(array $packages, string $label): array
{
    $deptrac = qaDeptracConfig();
    $gaps = [];

    foreach ($packages as $name => $directory) {
        if (! in_array(realpath($directory.DIRECTORY_SEPARATOR.'src'), $deptrac['paths'], true)) {
            $gaps[] = "{$label}/{$name}/src is not in deptrac.yaml paths";
        }

        $tests = $directory.DIRECTORY_SEPARATOR.'tests';

        if (is_dir($tests) && ! in_array(realpath($tests), $deptrac['paths'], true)) {
            $gaps[] = "{$label}/{$name}/tests is not in deptrac.yaml paths";
        }

        if (is_dir($tests) && ! qaDeptracCollectsTestTree($tests, $deptrac['layers'])) {
            $gaps[] = "no deptrac.yaml layer collects any namespace in {$label}/{$name}/tests";
        }

        $probe = qaProbeClassFor($directory);
        $owning = qaDeptracLayerFor($probe, $deptrac['layers']);

        if ($owning === null) {
            $gaps[] = "no deptrac.yaml layer collects {$probe}";

            continue;
        }

        if (! array_key_exists($owning, $deptrac['ruleset'])) {
            $gaps[] = "deptrac.yaml has no ruleset entry for layer {$owning}";
        }
    }

    return $gaps;
}

/**
 * Every directory phpunit.xml actually collects tests from, globs expanded.
 *
 * @return list<string>
 */
function qaPhpunitTestDirectories(): array
{
    $xml = simplexml_load_file(base_path('phpunit.xml'));

    if ($xml === false) {
        return [];
    }

    $directories = [];

    foreach ($xml->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $pattern = str_replace('\\', '/', base_path((string) $directory));

            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $match) {
                $resolved = realpath($match);

                if (is_string($resolved)) {
                    $directories[] = $resolved;
                }
            }
        }
    }

    return $directories;
}

it('finds the packages this guard is supposed to protect', function () {
    expect(qaModulePackages())->toHaveKey('demo')
        ->and(array_keys(qaSharedPackages()))->toBe([
            // 'access' is T-010's. The literal is this guard's published
            // domain - it is how a reader tells a clean sweep from an empty
            // one - so a new package necessarily edits it, the way a new
            // master table edits masterApplicationTables()'s counts.
            // 'organization' is T-014's, for the same reason.
            'access', 'auth', 'contracts', 'eventbus', 'http-client', 'kernel', 'organization', 'plugin-sdk', 'testing',
        ]);
});

it('analyses every module with PHPStan', function () {
    expect(qaPhpstanGaps(qaModulePackages(), 'modules'))->toBe([]);
});

it('analyses every shared package with PHPStan', function () {
    expect(qaPhpstanGaps(qaSharedPackages(), 'packages'))->toBe([]);
});

it('gives every module its own Deptrac layer, path and ruleset entry', function () {
    expect(qaDeptracGaps(qaModulePackages(), 'modules'))->toBe(qaRecordedDeptracTestTreeGaps('modules'));
});

it('gives every shared package its own Deptrac layer, path and ruleset entry', function () {
    expect(qaDeptracGaps(qaSharedPackages(), 'packages'))->toBe(qaRecordedDeptracTestTreeGaps('packages'));
});

/*
 * W-2. The two expectations above are not `toBe([])`, and that is the point:
 * three registered test trees are collected by no Deptrac layer, so this guard
 * says so by name instead of reporting a clean sweep it cannot deliver. The
 * header's claim - every package on disk covered by all three - holds for
 * PHPStan and phpunit; for Deptrac it holds for src and is recorded as open for
 * these three trees.
 *
 * This test is the other half: the recorded list may only contain trees that
 * are really uncollectable. It reads the trees, not the registry, so it goes
 * red the moment FP-303 namespaces one - at which point the entry must be
 * deleted, and the expectations above tighten by themselves.
 */
it('records only test trees that genuinely declare no namespace, each against its fixing row', function () {
    $recorded = qaGlobalNamespaceTestTrees();

    expect($recorded)->not->toBe([])
        ->and(array_values(array_unique(array_values($recorded))))->toBe(['FP-303']);

    // R2-W2. The check above constrains the label, not the membership: a tree
    // that becomes global-namespaced tomorrow could be silenced by one more
    // line reading 'FP-303', and both Deptrac guards would stay green. This
    // bounds the set at T-009's three. It shrinks freely - FP-303 deleting an
    // entry is the work this list exists to invite and must not redden the
    // gate - but it cannot grow without someone editing the literal below and
    // being seen doing it.
    expect(array_values(array_diff(array_keys($recorded), [
        'modules/demo/tests',
        'packages/auth/tests',
        'packages/kernel/tests',
    ])))->toBe([]);

    $root = dirname(modulesPath());

    foreach (array_keys($recorded) as $tree) {
        $directory = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $tree);

        expect(is_dir($directory))->toBeTrue("{$tree} is recorded but does not exist");

        // Not "no layer matches it" - that is what the guard above measures,
        // and it would stay satisfied if a tree were namespaced under a
        // namespace nobody collects. The claim being recorded is narrower and
        // is the one FP-303 closes: there is no namespace in here at all.
        expect(qaDeclaredNamespacesIn($directory))->toBe([], "{$tree} declares a namespace and is no longer global");
    }
});

it('collects tests from every package that ships them', function () {
    expect(qaPhpunitGaps(array_merge(qaModulePackages(), qaSharedPackages())))->toBe([]);
});

/*
 * T-002 AC-18. The skip rule above is the one thing standing between this guard
 * and a silently unchecked module, so it gets its own probe: a real package,
 * generated by the real generator, whose name contains "fixture" as a genuine
 * word. Under the old str_contains() rule it was exempt from all three
 * registries; under the exact-name rule it must be required to appear in every
 * one of them, and this guard must be red until it does.
 */

afterEach(function () {
    deleteFixtureDirectory(modulesPath('data-fixtures'));
});

it('does not skip a legitimately named module just because its name contains the word fixture', function () {
    $name = 'data-fixtures';

    expect($name)->not->toBeIn(qaFixturePackageNames())
        ->and(str_contains($name, 'fixture'))->toBeTrue();

    expect(Artisan::call('module:make', ['name' => $name]))->toBe(0);

    $packages = qaModulePackages();

    // Not skipped: the guard sees it exactly as it sees demo.
    expect($packages)->toHaveKey($name);

    $probe = [$name => $packages[$name]];

    // And, because nobody has registered it anywhere, every one of the three
    // checks this file makes reports it. A guard that silently passed here is
    // the BUG-5 mitigation failing open.
    expect(qaPhpstanGaps($probe, 'modules'))->toBe([
        "modules/{$name}/src is not in phpstan.neon.dist paths",
        "modules/{$name}/tests is not in phpstan.neon.dist paths",
    ])
        ->and(qaDeptracGaps($probe, 'modules'))->toBe([
            "modules/{$name}/src is not in deptrac.yaml paths",
            "modules/{$name}/tests is not in deptrac.yaml paths",
            // W-2's liveness control: a brand-new test tree in the global
            // namespace, which is not on the recorded list, must be reported
            // here. Without this line the extended check could be deleted and
            // the recorded list would absorb the loss in silence.
            "no deptrac.yaml layer collects any namespace in modules/{$name}/tests",
            'no deptrac.yaml layer collects Flexipos\Module\DataFixtures\QaProbe',
        ])
        ->and(qaPhpunitGaps($probe))->toBe([]);

    // phpunit.xml is globbed, so it is the one registry that needs no edit -
    // asserted rather than assumed, because it is why the guard exists at all.
    expect(qaPhpunitTestDirectories())->toContain(realpath($packages[$name].DIRECTORY_SEPARATOR.'tests'));
});

it('still skips every throwaway package the suite generates, by exact name only', function () {
    // The other half of AC-18: narrowing the rule must not start failing the
    // gate mid-run because a fixture package happens to exist while this test
    // is executing.
    expect(Artisan::call('module:make', ['name' => 'qadiscoveryfixture']))->toBe(0);

    try {
        expect(qaModulePackages())->not->toHaveKey('qadiscoveryfixture')
            ->and(qaPhpstanGaps(qaModulePackages(), 'modules'))->toBe([])
            ->and(qaDeptracGaps(qaModulePackages(), 'modules'))->toBe(qaRecordedDeptracTestTreeGaps('modules'));
    } finally {
        deleteFixtureDirectory(modulesPath('qadiscoveryfixture'));
    }
});

it('forbids every module layer from depending on any other module layer', function () {
    $deptrac = qaDeptracConfig();
    $moduleLayers = array_values(array_filter(
        array_keys($deptrac['layers']),
        fn (string $layer): bool => str_starts_with($layer, 'Module_')
    ));

    expect($moduleLayers)->not->toBe([]);

    $illegal = [];

    foreach ($moduleLayers as $layer) {
        foreach ((array) ($deptrac['ruleset'][$layer] ?? []) as $target) {
            if (! str_starts_with((string) $target, 'Package_')) {
                $illegal[] = "deptrac.yaml lets {$layer} depend on {$target}";
            }
        }
    }

    expect($illegal)->toBe([]);
});
