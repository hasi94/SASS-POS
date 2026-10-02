<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

/*
 * QA regression guard for T-001 AC-9, AC-11, AC-13 and AC-16.
 *
 * phpstan.neon.dist, deptrac.yaml and phpunit.xml all list their paths and
 * layers literally - the architect's plan rejected globbing on purpose. That is
 * a defensible choice, but nothing currently notices when a package is added and
 * the lists are not updated: a service package outside phpstan's paths passes
 * `composer stan` with real level 6 errors in it, a service package outside
 * deptrac's layers can import another service without a violation, and a test
 * directory outside phpunit.xml never runs. All three were reproduced by hand
 * during QA of T-001.
 *
 * These tests turn that manual discipline into an enforced invariant: however
 * the lists are maintained, by hand or by a glob, every package on disk must be
 * covered by all three.
 */

/*
 * qaFixturePackageNames() - the exact names of the throwaway service packages
 * this suite generates while it runs (T-002 AC-18, decision D-23) - now lives in
 * tests/Helpers.php, which Pest loads before any test file. T-004 FP-164 moved
 * it there because filesSpawningChildProcesses() needs the same list; the
 * function, its name and its contents are unchanged and no call site in this
 * file changed.
 */

/**
 * Service packages committed under services/, excluding the host application and
 * the throwaway packages other tests generate while they run.
 *
 * @return array<string, string>
 */
function qaServicePackages(): array
{
    $packages = [];

    foreach (glob(servicesPath().DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
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
    $root = dirname(servicesPath()).DIRECTORY_SEPARATOR.'packages';
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

        $probe = qaProbeClassFor($directory);
        $owning = null;

        foreach ($deptrac['layers'] as $layer => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('{'.$pattern.'}', $probe) === 1) {
                    $owning = $layer;
                }
            }
        }

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
    expect(qaServicePackages())->toHaveKey('demo')
        ->and(array_keys(qaSharedPackages()))->toBe([
            'auth', 'contracts', 'eventbus', 'http-client', 'kernel', 'plugin-sdk', 'testing',
        ]);
});

it('analyses every service package with PHPStan', function () {
    expect(qaPhpstanGaps(qaServicePackages(), 'services'))->toBe([]);
});

it('analyses every shared package with PHPStan', function () {
    expect(qaPhpstanGaps(qaSharedPackages(), 'packages'))->toBe([]);
});

it('gives every service package its own Deptrac layer, path and ruleset entry', function () {
    expect(qaDeptracGaps(qaServicePackages(), 'services'))->toBe([]);
});

it('gives every shared package its own Deptrac layer, path and ruleset entry', function () {
    expect(qaDeptracGaps(qaSharedPackages(), 'packages'))->toBe([]);
});

it('collects tests from every package that ships them', function () {
    expect(qaPhpunitGaps(array_merge(qaServicePackages(), qaSharedPackages())))->toBe([]);
});

/*
 * T-002 AC-18. The skip rule above is the one thing standing between this guard
 * and a silently unchecked service, so it gets its own probe: a real package,
 * generated by the real generator, whose name contains "fixture" as a genuine
 * word. Under the old str_contains() rule it was exempt from all three
 * registries; under the exact-name rule it must be required to appear in every
 * one of them, and this guard must be red until it does.
 */

afterEach(function () {
    deleteFixtureDirectory(servicesPath('data-fixtures'));
});

it('does not skip a legitimately named service just because its name contains the word fixture', function () {
    $name = 'data-fixtures';

    expect($name)->not->toBeIn(qaFixturePackageNames())
        ->and(str_contains($name, 'fixture'))->toBeTrue();

    expect(Artisan::call('svc:make', ['name' => $name]))->toBe(0);

    $packages = qaServicePackages();

    // Not skipped: the guard sees it exactly as it sees demo.
    expect($packages)->toHaveKey($name);

    $probe = [$name => $packages[$name]];

    // And, because nobody has registered it anywhere, every one of the three
    // checks this file makes reports it. A guard that silently passed here is
    // the BUG-5 mitigation failing open.
    expect(qaPhpstanGaps($probe, 'services'))->toBe(["services/{$name}/src is not in phpstan.neon.dist paths"])
        ->and(qaDeptracGaps($probe, 'services'))->toBe([
            "services/{$name}/src is not in deptrac.yaml paths",
            'no deptrac.yaml layer collects Flexipos\Service\DataFixtures\QaProbe',
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
    expect(Artisan::call('svc:make', ['name' => 'qadiscoveryfixture']))->toBe(0);

    try {
        expect(qaServicePackages())->not->toHaveKey('qadiscoveryfixture')
            ->and(qaPhpstanGaps(qaServicePackages(), 'services'))->toBe([])
            ->and(qaDeptracGaps(qaServicePackages(), 'services'))->toBe([]);
    } finally {
        deleteFixtureDirectory(servicesPath('qadiscoveryfixture'));
    }
});

it('forbids every service layer from depending on any other service layer', function () {
    $deptrac = qaDeptracConfig();
    $serviceLayers = array_values(array_filter(
        array_keys($deptrac['layers']),
        fn (string $layer): bool => str_starts_with($layer, 'Service_')
    ));

    expect($serviceLayers)->not->toBe([]);

    $illegal = [];

    foreach ($serviceLayers as $layer) {
        foreach ((array) ($deptrac['ruleset'][$layer] ?? []) as $target) {
            if (! str_starts_with((string) $target, 'Package_')) {
                $illegal[] = "deptrac.yaml lets {$layer} depend on {$target}";
            }
        }
    }

    expect($illegal)->toBe([]);
});
