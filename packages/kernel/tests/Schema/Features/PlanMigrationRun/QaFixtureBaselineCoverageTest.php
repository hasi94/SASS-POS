<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-011: what the dev sweeps and the named file leave to trust
|--------------------------------------------------------------------------
|
| Three gaps found while grading AC-3, AC-4, AC-8 and AC-24 E4.
|
| 1. E4 is enforced by nothing. A command-level corpus file that forgets
|    runFixtureCommandsReadTheFixtureBaseline() is a second reader of the
|    shipped tree, and QaFixtureBaselineSweepsTest cannot see it: that sweep
|    searches for the configuration key, and the forgetful file never writes
|    it. This file enumerates the callers of the migrate command from the
|    directory and requires the helper in each, with a control.
|
| 2. AC-4's independence is proved only inside ShippedTenantTreeTest, which
|    reads the shipped tree to do it. Here the same independence is shown
|    without reading any tree: the configured path is pointed at a directory
|    that does not exist, and every composition still builds, identically.
|    A set that read the configured path would throw.
|
| 3. AC-2 says the baseline is six files on purpose (D-14: six literals in
|    older cases assume it). Every other test derives the number, so nothing
|    would notice the baseline changing size. This is the one place the
|    number is written, on purpose, and it fails with the reason.
|
| Every needle this file searches for is written split, so the sweeps that
| scan the kernel test tree do not report this file.
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

/**
 * The text that means "invokes a command that builds its migration set from
 * the configured tenant path": the migrate command, and the schema-status
 * command's drift option.
 *
 * @return list<string>
 */
function qaBaselineCoverageCommandNeedles(): array
{
    return ["'tenant:"."migrate'", '"tenant:'.'migrate"', '--dr'.'ift'];
}

/**
 * The only kernel file allowed to invoke those commands without pointing them
 * at the fixture baseline: its subject is the shipped tree.
 *
 * @return list<string>
 */
function qaBaselineCoverageExemptFiles(): array
{
    return ['Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php'];
}

/**
 * Every kernel test source, relative path => source, minus the support
 * directory (it defines the helper rather than using it).
 *
 * @return array<string, string>
 */
function qaBaselineCoverageSources(): array
{
    $root = str_replace('\\', '/', dirname(__DIR__, 3));
    $sources = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');

        if (! str_starts_with($relative, 'Support/')) {
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * The callers of those commands, relative path => whether the file points the
 * command at the fixture baseline.
 *
 * @param  array<string, string>  $sources
 * @return array<string, bool>
 */
function qaBaselineCoverageCallers(array $sources): array
{
    $callers = [];

    foreach ($sources as $relative => $source) {
        foreach (qaBaselineCoverageCommandNeedles() as $needle) {
            if (str_contains($source, $needle)) {
                $callers[$relative] = preg_match('/^\s*runFixtureCommandsReadTheFixtureBase'.'line\(\);/m', $source) === 1;

                break;
            }
        }
    }

    return $callers;
}

/**
 * The callers that read the shipped tree without being the exempt file.
 *
 * @param  array<string, bool>  $callers
 * @return list<string>
 */
function qaBaselineCoverageUnpointed(array $callers): array
{
    return array_values(array_diff(
        array_keys(array_filter($callers, static fn (bool $pointed): bool => ! $pointed)),
        qaBaselineCoverageExemptFiles()
    ));
}

it('points every kernel test that invokes the migrate command at the fixture baseline (AC-24 E4)', function () {
    $callers = qaBaselineCoverageCallers(qaBaselineCoverageSources());

    fwrite(STDOUT, PHP_EOL.'E4 sweep: '.count($callers).' kernel test files invoke a command that reads the configured tenant path'.PHP_EOL
        .'  pointed at the fixture baseline: '.implode(', ', array_keys(array_filter($callers))).PHP_EOL
        .'  exempt by name: '.implode(', ', array_intersect(array_keys($callers), qaBaselineCoverageExemptFiles())).PHP_EOL);

    expect(count($callers))->toBeGreaterThanOrEqual(5)
        ->and(array_keys($callers))->toContain('Schema/Features/RunTenantMigrations/QaPrintedInstructionRoundTripTest.php')
        ->and(array_keys($callers))->toContain('Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php')
        ->and(qaBaselineCoverageUnpointed($callers))->toBe([]);
});

it('reports a command-level test that forgot to point the command at the fixture baseline (E4 control)', function () {
    $forgetful = [
        'A/ForgetfulTest.php' => "<?php Artisan::call('tenant:"."migrate', ['--all' => true]);",
        'A/DriftTest.php' => "<?php Artisan::call('tenant:schema-status', ['--dr"."ift' => true]);",
        'A/PointedTest.php' => "<?php\n    runFixtureCommandsReadTheFixtureBase"."line();\nArtisan::call('tenant:"."migrate', []);",
        'A/CommentOnlyTest.php' => '<?php // runFixtureCommandsReadTheFixtureBase'."line() was meant here
Artisan::call('tenant:"."migrate', []);",
        'A/NoCommandTest.php' => '<?php runFixtureSet(1);',
        'Schema/Features/PlanMigrationRun/ShippedTenantTreeTest.php' => "<?php Artisan::call('tenant:"."migrate', []);",
    ];

    expect(qaBaselineCoverageUnpointed(qaBaselineCoverageCallers($forgetful)))->toBe(['A/ForgetfulTest.php', 'A/DriftTest.php', 'A/CommentOnlyTest.php']);
});

it('makes the helper the thing that moves the command onto the fixture baseline (E4 liveness)', function () {
    $key = 'schema.tenant_migration'.'_path';
    $before = config($key);

    expect($before)->not->toBe(runFixtureBaselinePath());

    runFixtureCommandsReadTheFixtureBaseline();

    expect(config($key))->toBe(runFixtureBaselinePath())
        ->and(base_path((string) config($key)))->toBe(runFixtureBaselineDirectory())
        ->and(is_dir(base_path((string) config($key))))->toBeTrue();
});

it('builds every runFixtureSet composition from a configured tenant path that does not exist (AC-4, no tree read)', function () {
    $compositions = [[], [1], [4], [1, 5], [4, 1], [1, 2, 3, 4, 5, 6]];
    $rows = static fn (array $fx): array => array_map(
        static fn ($entry): array => $entry->toRow(),
        runFixtureSet(...$fx)->catalogue()
    );

    $real = array_map($rows, $compositions);
    $baseline = runFixtureBaselineVersion();

    config()->set('schema.tenant_migration'.'_path', 'storage/app/qa-no-such-tenant-tree');

    expect(is_dir(base_path('storage/app/qa-no-such-tenant-tree')))->toBeFalse();

    foreach ($compositions as $index => $fx) {
        expect($rows($fx))->toBe($real[$index]);
    }

    // Liveness: the comparison above is over real catalogues, not empty ones.
    expect(count($real[0]))->toBe($baseline)
        ->and(count($real[5]))->toBe($baseline + 6);
});

it('keeps the fixture baseline at six files until the literals that assume six are derived (AC-2, D-14)', function () {
    // The single place the baseline's size is written. Several T-008-era cases
    // write 6 as "the top of the baseline" and 7 as "FX-1's version" (listed in
    // tests/Fixtures/TenantMigrations/README.md). Changing the size silently
    // changes what they test. When those literals are derived from
    // runFixtureBaselineVersion(), delete this case.
    expect(runFixtureBaselineVersion())->toBe(6)
        ->and(count(runFixtureBaselineTables()))->toBe(6);
});
