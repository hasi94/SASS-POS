<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-011: the fixture baseline stays a test fixture, and the shipped tree
| has one reader in the kernel corpus
|--------------------------------------------------------------------------
|
| AC-1: no product source, configuration or .env.example names the fixture
| baseline. AC-7: every corpus file that composes a set through
| runFixtureSet() is enumerated from the directory, and none of them takes a
| starting version from the product tree. AC-8: inside the kernel test tree,
| the only readers of the shipped tenant tree are the fixture-support file
| that defines the entry point and the one named shipped-tree file.
|
| Each sweep prints its domain and what it found, asserts the domain is not
| empty, and has an in-memory control the same function reports. Every needle
| this file searches for is written split, so the file does not report itself.
|
| Stated gap (AC-8): a shipped-tree reader added OUTSIDE the kernel test tree
| is not caught here. The readers out there today are printed, not excluded.
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

/**
 * The repository root.
 */
function fixtureSweepRoot(): string
{
    return dirname(base_path(), 2);
}

/**
 * A path relative to the repository root, with forward slashes.
 */
function fixtureSweepRelative(string $path): string
{
    $root = str_replace('\\', '/', fixtureSweepRoot());

    return ltrim(substr(str_replace('\\', '/', $path), strlen($root)), '/');
}

/**
 * Every file under a directory, recursively, as absolute paths.
 *
 * @return list<string>
 */
function fixtureSweepFilesUnder(string $directory, string $extension = ''): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && ($extension === '' || $file->getExtension() === $extension)) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/*
|--------------------------------------------------------------------------
| AC-1
|--------------------------------------------------------------------------
*/

/**
 * The product source AC-1 scans: the host's application directories and
 * .env.example, and every package's and other module's `src`.
 *
 * @return list<string>
 */
function fixtureSweepProductFiles(): array
{
    $files = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        $files = array_merge($files, fixtureSweepFilesUnder(base_path($directory)));
    }

    $files[] = base_path('.env.example');

    foreach (glob(fixtureSweepRoot().'/packages/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $files = array_merge($files, fixtureSweepFilesUnder($directory));
    }

    foreach (glob(fixtureSweepRoot().'/modules/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        if (str_replace('\\', '/', dirname($directory)) !== str_replace('\\', '/', base_path())) {
            $files = array_merge($files, fixtureSweepFilesUnder($directory));
        }
    }

    return $files;
}

/**
 * The fixture-baseline names one source mentions.
 *
 * @return list<string>
 */
function fixtureSweepBaselineNamesIn(string $source): array
{
    $found = [];

    foreach ([runFixtureBaselinePath(), 'fx'.'base'] as $needle) {
        if (str_contains($source, $needle)) {
            $found[] = $needle;
        }
    }

    return $found;
}

it('finds no product source that names the fixture baseline (AC-1)', function () {
    $files = fixtureSweepProductFiles();
    $naming = [];

    foreach ($files as $path) {
        if (fixtureSweepBaselineNamesIn((string) file_get_contents($path)) !== []) {
            $naming[] = fixtureSweepRelative($path);
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-1 sweep: '.count($files).' product files scanned, '.count($naming).' naming the fixture baseline'.PHP_EOL);

    expect(count($files))->toBeGreaterThan(100)
        ->and($files)->toContain(base_path('.env.example'))
        ->and($naming)->toBe([])
        ->and(str_starts_with(
            str_replace('\\', '/', runFixtureBaselineDirectory()),
            str_replace('\\', '/', base_path('tests')).'/'
        ))->toBeTrue();
});

it('reports a product source that names the fixture baseline (AC-1 control)', function () {
    expect(fixtureSweepBaselineNamesIn("'path' => '".runFixtureBaselinePath()."',"))->toBe([runFixtureBaselinePath()])
        ->and(fixtureSweepBaselineNamesIn("require '2026_10_21_000001_".'fx'."base_creates_one.php';"))->toBe(['fx'.'base'])
        ->and(fixtureSweepBaselineNamesIn("'path' => 'database/elsewhere',"))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| AC-8, and the reader set AC-7 reuses
|--------------------------------------------------------------------------
*/

/**
 * The needles that mean "reads the shipped tenant tree", split so this file
 * is not one of their matches.
 *
 * @return list<string>
 */
function fixtureSweepShippedTreeNeedles(): array
{
    return [
        'tenant_migration'.'_path',
        'tenantMigration'.'Path(',
        'migrationTree'.'Directory(',
        'tenantMigration'.'FileCount(',
        'runFixture'.'ShippedSet(',
        'migrations'.'-tenant',
    ];
}

/**
 * The scope's allowed readers, relative to the kernel test directory.
 *
 * @return list<string>
 */
function fixtureSweepAllowedReaders(): array
{
    return [
        'Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php',
        'Support/migration-run-fixture.php',
    ];
}

/**
 * The scope's one exclusion: its subject is MigrationSet over the real path.
 *
 * @return list<string>
 */
function fixtureSweepExcluded(): array
{
    return ['Schema/Support/MigrationSetTest.php'];
}

/**
 * Every file in the scope, as relative path => source.
 *
 * @return array<string, string>
 */
function fixtureSweepKernelTestSources(): array
{
    $directory = dirname(__DIR__, 3);
    $sources = [];

    foreach (fixtureSweepFilesUnder($directory, 'php') as $path) {
        $relative = ltrim(substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $directory))), '/');
        $sources[$relative] = (string) file_get_contents($path);
    }

    return $sources;
}

/**
 * The files of a source map that read the shipped tree, as relative path =>
 * the needles found.
 *
 * @param  array<string, string>  $sources
 * @return array<string, list<string>>
 */
function fixtureSweepReadersIn(array $sources): array
{
    $readers = [];

    foreach ($sources as $relative => $source) {
        $found = array_values(array_filter(
            fixtureSweepShippedTreeNeedles(),
            static fn (string $needle): bool => str_contains($source, $needle)
        ));

        if ($found !== []) {
            $readers[$relative] = $found;
        }
    }

    return $readers;
}

/**
 * AC-8's verdict over a source map: every reader that is neither allowed nor
 * excluded.
 *
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function fixtureSweepUnexpectedReaders(array $sources): array
{
    return array_values(array_diff(
        array_keys(fixtureSweepReadersIn($sources)),
        fixtureSweepAllowedReaders(),
        fixtureSweepExcluded()
    ));
}

it('finds one reader of the shipped tree in the kernel tests, through one entry point (AC-8)', function () {
    $sources = fixtureSweepKernelTestSources();
    $readers = fixtureSweepReadersIn($sources);

    $allowed = array_values(array_intersect(array_keys($readers), fixtureSweepAllowedReaders()));
    $excluded = array_values(array_intersect(array_keys($readers), fixtureSweepExcluded()));

    // Out of scope: printed, never excluded, so the boundary is visible.
    $outside = [];

    foreach (array_merge(
        glob(fixtureSweepRoot().'/modules/*/tests', GLOB_ONLYDIR) ?: [],
        array_filter(
            glob(fixtureSweepRoot().'/packages/*/tests', GLOB_ONLYDIR) ?: [],
            static fn (string $directory): bool => basename(dirname($directory)) !== 'kernel'
        ),
        glob(fixtureSweepRoot().'/packages/testing/src', GLOB_ONLYDIR) ?: [],
    ) as $directory) {
        foreach (fixtureSweepFilesUnder($directory, 'php') as $path) {
            if (fixtureSweepReadersIn([$path => (string) file_get_contents($path)]) !== []) {
                $outside[] = fixtureSweepRelative($path);
            }
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-8 sweep over the kernel test tree: '.count($sources).' files'.PHP_EOL
        .'  allowed readers found: '.implode(', ', $allowed).PHP_EOL
        .'  excluded by name: '.implode(', ', $excluded).PHP_EOL
        .'  out of scope, printed not excluded ('.count($outside).'): '.PHP_EOL.'    '.implode(PHP_EOL.'    ', $outside).PHP_EOL
        .'  stated gap: a reader added outside the kernel test tree is not caught by this sweep'.PHP_EOL);

    expect(count($sources))->toBeGreaterThan(50)
        ->and($allowed)->toContain('Support/migration-run-fixture.php')
        ->and($outside)->not->toBe([])
        ->and(fixtureSweepUnexpectedReaders($sources))->toBe([]);
});

it('reports a kernel test that composes a set on the shipped tree (AC-8 control)', function () {
    $planted = [
        'Schema/Features/PlanMigrationRun/PlantedTest.php' => '<?php $set = runFixture'.'ShippedSet()->with(\'fx1\', $dir);',
        'Schema/Features/PlanMigrationRun/PlantedKeyTest.php' => '<?php MigrationSet::baseline(base_path(config(\'schema.tenant_migration'.'_path\')));',
        'Schema/Features/PlanMigrationRun/CleanTest.php' => '<?php $set = runFixtureSet(1);',
    ];

    expect(fixtureSweepUnexpectedReaders($planted))->toBe([
        'Schema/Features/PlanMigrationRun/PlantedTest.php',
        'Schema/Features/PlanMigrationRun/PlantedKeyTest.php',
    ]);
});

/*
|--------------------------------------------------------------------------
| AC-7
|--------------------------------------------------------------------------
*/

/**
 * The corpus files that compose a set through runFixtureSet(), minus the file
 * that defines it and AC-8's allowed readers, as relative path => the
 * product-tree needles each holds.
 *
 * @param  array<string, string>  $sources
 * @return array<string, list<string>>
 */
function fixtureSweepCorpus(array $sources): array
{
    $corpus = [];

    foreach ($sources as $relative => $source) {
        if (! str_contains($source, 'runFixture'.'Set(')
            || str_contains($source, 'function runFixture'.'Set(')
            || in_array($relative, fixtureSweepAllowedReaders(), true)) {
            continue;
        }

        $corpus[$relative] = array_values(array_filter(
            ['tenantMigration'.'FileCount(', 'tenant_migration'.'_path'],
            static fn (string $needle): bool => str_contains($source, $needle)
        ));
    }

    ksort($corpus);

    return $corpus;
}

it('enumerates the corpus that composes through runFixtureSet, and none of it reads the product file count (AC-7)', function () {
    $corpus = fixtureSweepCorpus(fixtureSweepKernelTestSources());

    fwrite(STDOUT, PHP_EOL.'AC-7 corpus: '.count($corpus).' files calling runFixtureSet()'.PHP_EOL.'  '.implode(PHP_EOL.'  ', array_keys($corpus)).PHP_EOL);

    expect(count($corpus))->toBeGreaterThan(5)
        ->and($corpus)->toHaveKey('Schema/Features/PlanMigrationRun/PlanMigrationRunHandlerTest.php')
        ->and(array_filter($corpus))->toBe([]);
});

it('reports a corpus file that sets a starting version from the product file count (AC-7 control)', function () {
    $planted = [
        'A/PlantedTest.php' => '<?php runFixtureSet(1); runFixtureTenant(\'t\', $s, \'active\', tenantMigration'.'FileCount(), 1);',
        'A/CleanTest.php' => '<?php runFixtureSet(1); runFixtureTenant(\'t\', $s, \'active\', runFixtureBaselineVersion(), 1);',
    ];

    expect(array_filter(fixtureSweepCorpus($planted)))->toBe(['A/PlantedTest.php' => ['tenantMigration'.'FileCount(']]);
});
