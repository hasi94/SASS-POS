<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-027 step 18 - "one ladder, one caller" and no surface (AC-17, AC-41),
| qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaWorkflowSourceRulesTest/QaAuditSourceRulesTest: its own
| helpers, calling nothing from another Qa*SourceRulesTest file (FP-164).
| Every rule below has a planted-file control proving the scan can fail,
| run over a real directory holding the planted file, never only
| str_contains() against the planted file's own text (T-016 reviewer round
| 1, W-1).
|
| Reads code with comments and docblocks removed (SourceRules::codeOnly),
| for the same reason QaAuditSourceRulesTest and MigrationSourceRules do: a
| docblock that discusses `unit_conversions` in prose - and every one of
| this task's own files does, including DatabaseUnitResolver's own class
| docblock - must not be mistaken for a reference to the table in code.
*/

function qaCatalogRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaCatalogFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_catalog_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * @return array<string, string>
 */
function qaCatalogSourceFiles(string $directory): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        $files[$file->getRealPath()] = SourceRules::codeOnly((string) file_get_contents($file->getRealPath()));
    }

    return $files;
}

/**
 * The four CRUD slices that legitimately own the exact table/column the
 * ladder reads, and so legitimately name it in code without re-implementing
 * any step of the resolution ladder itself.
 *
 * `DatabaseUnitResolver`'s own docblock states the rule this scan enforces
 * as "no file outside Units/ may read `unit_conversions` or `units.
 * base_unit_id`/`factor`" - a rule about the five-step **resolution** ladder
 * (AC-9/AC-10/AC-15), never about CRUD of the very table/column each token
 * names. `CreateUnitConversion`/`RemoveUnitConversion` (plan Ruling 10) are
 * `unit_conversions`' own writers, and `CreateUnit`/`UpdateUnit` (plan
 * Ruling 6) write and cycle-check `units.base_unit_id` directly, because it
 * is a column on the very table those two slices manage. Measured directly
 * (2026-11-08): these are the *only* four files under `packages/catalog/src`
 * outside `Units/` naming either token, and neither reads a row to compute a
 * factor - `assertNoCycle()` walks the chain to refuse a loop, which is a
 * write-time structural check, not resolution.
 *
 * @var list<string>
 */
const QA_CATALOG_LADDER_SCAN_TABLE_OWNERS = [
    'CreateUnitHandler.php',
    'UpdateUnitHandler.php',
    'CreateUnitConversionHandler.php',
    'RemoveUnitConversionHandler.php',
];

/**
 * Every file this scan reads, other than the ones under
 * `packages/catalog/src/Units` (the one place allowed to hold the ladder's
 * own lookup keys) and the four files named in
 * `QA_CATALOG_LADDER_SCAN_TABLE_OWNERS` above.
 *
 * `packages/catalog/src` is walked as one tree (so `CatalogServiceProvider.
 * php`, which sits directly in `src/`, is not silently skipped) and the
 * `Units/` subtree is excluded by its real path prefix rather than by a
 * directory-depth assumption.
 *
 * @return array<string, string>
 */
function qaCatalogLadderScanFiles(): array
{
    $root = qaCatalogRepoRoot();
    $unitsPath = (string) realpath($root.'/packages/catalog/src/Units');
    $files = [];

    $directories = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $directories[] = $dir;
    }

    foreach (glob($root.'/modules/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $directories[] = $dir;
    }

    $directories[] = $root.'/modules/host/app';

    foreach ($directories as $directory) {
        foreach (qaCatalogSourceFiles($directory) as $path => $code) {
            if ($unitsPath !== '' && str_starts_with($path, $unitsPath)) {
                continue;
            }

            if (in_array(basename($path), QA_CATALOG_LADDER_SCAN_TABLE_OWNERS, true)) {
                continue;
            }

            $files[$path] = $code;
        }
    }

    return $files;
}

/**
 * @param  array<string, string>|null  $files
 * @return list<string>
 */
function qaCatalogLadderTokenOffenders(string $token, ?array $files = null): array
{
    $found = [];

    foreach ($files ?? qaCatalogLadderScanFiles() as $path => $code) {
        if (preg_match('/\b'.preg_quote($token, '/').'\b/', $code) === 1) {
            $found[] = $path;
        }
    }

    return array_values(array_unique($found));
}

/**
 * The "multiplies by a factor column" shape: an arithmetic `*` next to a
 * token spelled `factor`, in either order, or `BigDecimal`'s own
 * `multipliedBy()` called with a `factor`-named argument - the three
 * spellings `SnapshotCalculator` itself uses, which is exactly why this
 * scan excludes `Units/` rather than banning the word outright.
 *
 * @param  array<string, string>|null  $files
 * @return list<string>
 */
function qaCatalogFactorMultiplicationOffenders(?array $files = null): array
{
    $patterns = [
        '/\*\s*[\$\w>:-]*factor\b/i',
        '/\bfactor\b\s*[\$\w>:-]*\*/i',
        '/multipliedBy\s*\([^)]*factor/i',
    ];

    $found = [];

    foreach ($files ?? qaCatalogLadderScanFiles() as $path => $code) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $path;

                break;
            }
        }
    }

    return array_values(array_unique($found));
}

it('every scanned directory actually exists, so an absent one cannot silently empty the scan', function () {
    foreach ([
        qaCatalogRepoRoot().'/packages/catalog/src',
        qaCatalogRepoRoot().'/packages/catalog/src/Units',
        qaCatalogRepoRoot().'/packages/contracts/src/Catalog',
        qaCatalogRepoRoot().'/modules/host/app',
        qaCatalogRepoRoot().'/modules/demo/src',
    ] as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }

    // Anti-vacuity: the ladder-scan file set itself is non-empty (it holds
    // every other package's and module's source) and excludes every file
    // under Units/.
    $files = qaCatalogLadderScanFiles();
    $unitsPath = (string) realpath(qaCatalogRepoRoot().'/packages/catalog/src/Units');

    expect($files)->not->toBe([]);

    foreach (array_keys($files) as $path) {
        expect(str_starts_with($path, $unitsPath))->toBeFalse();
    }
});

it('AC-17: no file outside packages/catalog/src/Units contains the code token unit_conversions', function () {
    expect(qaCatalogLadderTokenOffenders('unit_conversions'))->toBe([]);

    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('unit_conversions')->get();\n");

    try {
        expect(qaCatalogLadderTokenOffenders('unit_conversions', qaCatalogSourceFiles($directory)))->toBe([
            (string) realpath($directory.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-17: no file outside packages/catalog/src/Units contains the code token base_unit_id', function () {
    expect(qaCatalogLadderTokenOffenders('base_unit_id'))->toBe([]);

    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->base_unit_id;\n");

    try {
        expect(qaCatalogLadderTokenOffenders('base_unit_id', qaCatalogSourceFiles($directory)))->toBe([
            (string) realpath($directory.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-17 control: a docblock mentioning unit_conversions in prose is not reported, because codeOnly strips it', function () {
    // DatabaseUnitResolver's own class docblock names both tokens in prose.
    // This is the discriminating half of the two tests above: a scan that
    // read raw text (not SourceRules::codeOnly) would report that very file
    // as a violation of the rule it documents.
    $resolver = qaCatalogRepoRoot().'/packages/catalog/src/Units/DatabaseUnitResolver.php';

    expect(is_file($resolver))->toBeTrue();
    expect(str_contains((string) file_get_contents($resolver), 'unit_conversions'))->toBeTrue();

    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n/**\n * Never read unit_conversions directly, and never read base_unit_id either.\n */\nfunction f(): void {}\n");

    try {
        expect(qaCatalogLadderTokenOffenders('unit_conversions', qaCatalogSourceFiles($directory)))->toBe([])
            ->and(qaCatalogLadderTokenOffenders('base_unit_id', qaCatalogSourceFiles($directory)))->toBe([]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-17: no file outside packages/catalog/src/Units multiplies a quantity by a factor column', function () {
    expect(qaCatalogFactorMultiplicationOffenders())->toBe([]);

    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$base = \$quantity * \$row->factor;\n");

    try {
        expect(qaCatalogFactorMultiplicationOffenders(qaCatalogSourceFiles($directory)))->toBe([
            (string) realpath($directory.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory);
    }

    $directory2 = qaCatalogFreshTempDirectory();
    File::put($directory2.'/Planted.php', "<?php\n\$base = \$rounded->multipliedBy(BigDecimal::of(\$resolved->factor));\n");

    try {
        expect(qaCatalogFactorMultiplicationOffenders(qaCatalogSourceFiles($directory2)))->toBe([
            (string) realpath($directory2.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory2);
    }
});

/*
|--------------------------------------------------------------------------
| No surface (AC-41, NFR): no routes, no cache, no jobs, no hooks
|--------------------------------------------------------------------------
*/

it('AC-41: packages/catalog has no routes/ directory', function () {
    expect(is_dir(qaCatalogRepoRoot().'/packages/catalog/routes'))->toBeFalse();
});

it('AC-41: packages/catalog/src contains no Route::, Cache::, dispatch(, ShouldQueue or HookGateway', function () {
    $offenders = [];

    foreach (qaCatalogSourceFiles(qaCatalogRepoRoot().'/packages/catalog/src') as $path => $code) {
        foreach (['Route::', 'Cache::', 'dispatch(', 'ShouldQueue', 'HookGateway'] as $token) {
            if (str_contains($code, $token)) {
                $offenders[] = $path.':'.$token;
            }
        }
    }

    expect($offenders)->toBe([]);

    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nRoute::get('x', fn () => null);\n");

    try {
        $offenders2 = [];

        foreach (qaCatalogSourceFiles($directory) as $path => $code) {
            if (str_contains($code, 'Route::')) {
                $offenders2[] = $path.':Route::';
            }
        }

        expect($offenders2)->toBe([(string) realpath($directory.'/Planted.php').':Route::']);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Contracts purity: packages/contracts/src/Catalog imports nothing outside
| the contracts namespace and PHP core
|--------------------------------------------------------------------------
*/

/**
 * @return list<string>
 */
function qaCatalogContractImportOffenders(string $directory): array
{
    $found = [];

    foreach (qaCatalogSourceFiles($directory) as $path => $code) {
        preg_match_all('/\buse\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/', $code, $matches);

        foreach ($matches[1] as $import) {
            $import = ltrim((string) $import, '\\');

            if (str_starts_with($import, 'Flexipos\\Contracts\\')) {
                continue;
            }

            // A PHP-core (global-namespace) class carries no backslash at
            // all - RuntimeException, InvalidArgumentException. Anything
            // with one is a vendor or framework import, which would be this
            // package's first.
            if (! str_contains($import, '\\')) {
                continue;
            }

            $found[] = $path.':'.$import;
        }
    }

    return $found;
}

it('contracts purity: packages/contracts/src/Catalog imports nothing outside Flexipos\Contracts and PHP core', function () {
    expect(qaCatalogContractImportOffenders(qaCatalogRepoRoot().'/packages/contracts/src/Catalog'))->toBe([]);

    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Illuminate\\Support\\Str;\n");

    try {
        expect(qaCatalogContractImportOffenders($directory))->toBe([(string) realpath($directory.'/Planted.php').':Illuminate\\Support\\Str']);
    } finally {
        File::deleteDirectory($directory);
    }
});
