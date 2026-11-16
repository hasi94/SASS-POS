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
 * `unit_conversions`' own writers (plan Ruling 10): the only two files under
 * `packages/catalog/src` outside `Units/` legitimately naming that table in
 * code, because it is the very table each manages, not the resolution
 * ladder. Matched by **full path**, never basename - a future unrelated
 * file sharing one of these names, in another package or module, must not
 * silently inherit the exemption.
 *
 * @var list<string>
 */
const QA_CATALOG_UNIT_CONVERSIONS_TOKEN_EXEMPT = [
    'packages/catalog/src/Features/CreateUnitConversion/CreateUnitConversionHandler.php',
    'packages/catalog/src/Features/RemoveUnitConversion/RemoveUnitConversionHandler.php',
];

/**
 * `units.base_unit_id`'s own writers (plan Ruling 6): the only two files
 * under `packages/catalog/src` outside `Units/` legitimately naming that
 * column in code, because `base_unit_id` is a column on the very table
 * these two slices manage, including the cycle check each runs by walking
 * the chain it writes - a write-time structural check, not resolution.
 * Matched by full path, for the same reason as the list above.
 *
 * Measured directly (2026-11-08): neither this list nor the one above needs
 * the factor-multiplication exemption below - none of these four files
 * computes a resolution factor.
 *
 * @var list<string>
 */
const QA_CATALOG_BASE_UNIT_ID_TOKEN_EXEMPT = [
    'packages/catalog/src/Features/CreateUnit/CreateUnitHandler.php',
    'packages/catalog/src/Features/UpdateUnit/UpdateUnitHandler.php',
];

/**
 * The factor-multiplication rule's one sanctioned exception (T-029 plan
 * Ruling 10, AC-25, D-20): `PriceLadder` derives a price from the unit factor
 * the caller's `UnitSnapshot` already carries (`round(stock price x factor,
 * 4)`, `docs/database.md` section 6.4, D-13). That is the sanctioned price
 * derivation from a snapshot factor, never from a column, and it is named
 * here rather than worked around by renaming a variable. Matched by full
 * path, like the two lists above, and scoped to the factor rule only: it buys
 * no pass from either token scan.
 *
 * @var list<string>
 */
const QA_CATALOG_FACTOR_MULTIPLICATION_EXEMPT = [
    'packages/pricing/src/Engine/PriceLadder.php',
];

/**
 * Resolve each of the given repo-relative paths to its real, absolute path,
 * asserting every one exists - a rename or move of one of these files must
 * fail this assertion loudly, rather than silently widening the scan by
 * dropping the exemption out of the (then non-matching) list.
 *
 * @param  list<string>  $relativePaths
 * @return list<string>
 */
function qaCatalogResolveExemptPaths(array $relativePaths): array
{
    $root = qaCatalogRepoRoot();

    return array_map(static function (string $relativePath) use ($root): string {
        $full = $root.'/'.$relativePath;
        $real = realpath($full);

        if ($real === false) {
            throw new \RuntimeException("Expected an exempt file at [{$full}]; it does not exist.");
        }

        return $real;
    }, $relativePaths);
}

/**
 * Every file this scan reads, other than the ones under
 * `packages/catalog/src/Units` (the one place allowed to hold the ladder's
 * own lookup keys) and whichever full paths `$exemptPaths` names - each
 * caller passes only the exemption its own rule needs (W-3), never a blanket
 * list shared by every rule.
 *
 * `packages/catalog/src` is walked as one tree (so `CatalogServiceProvider.
 * php`, which sits directly in `src/`, is not silently skipped) and the
 * `Units/` subtree is excluded by its real path prefix rather than by a
 * directory-depth assumption.
 *
 * @param  list<string>  $exemptPaths  full, real paths - see {@see qaCatalogResolveExemptPaths()}
 * @return array<string, string>
 */
function qaCatalogLadderScanFiles(array $exemptPaths = []): array
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

            if (in_array($path, $exemptPaths, true)) {
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

    // W-3: every exempt path named below resolves to a real file, so a
    // rename of one fails this assertion loudly rather than silently
    // widening the scan (the then-stale entry would match nothing, and the
    // renamed file would fall straight back into the scanned set - this
    // just makes sure that is never mistaken for "still exempt").
    qaCatalogResolveExemptPaths(QA_CATALOG_UNIT_CONVERSIONS_TOKEN_EXEMPT);
    qaCatalogResolveExemptPaths(QA_CATALOG_BASE_UNIT_ID_TOKEN_EXEMPT);
    qaCatalogResolveExemptPaths(QA_CATALOG_FACTOR_MULTIPLICATION_EXEMPT);

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
    $files = qaCatalogLadderScanFiles(qaCatalogResolveExemptPaths(QA_CATALOG_UNIT_CONVERSIONS_TOKEN_EXEMPT));

    expect(qaCatalogLadderTokenOffenders('unit_conversions', $files))->toBe([]);

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
    $files = qaCatalogLadderScanFiles(qaCatalogResolveExemptPaths(QA_CATALOG_BASE_UNIT_ID_TOKEN_EXEMPT));

    expect(qaCatalogLadderTokenOffenders('base_unit_id', $files))->toBe([]);

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

it('AC-17: each exemption list is scoped to only its own rule - the other rule still scans an exempt file', function () {
    // W-3: QA_CATALOG_UNIT_CONVERSIONS_TOKEN_EXEMPT buys a pass from the
    // unit_conversions scan only, never from the base_unit_id scan (and
    // vice versa for QA_CATALOG_BASE_UNIT_ID_TOKEN_EXEMPT) - proven here by
    // not removing the other rule's exemption, so if either file ever grew
    // a bare `base_unit_id`/`unit_conversions` token it does not need, this
    // scan would still see it.
    $unitConversionsExempt = qaCatalogResolveExemptPaths(QA_CATALOG_UNIT_CONVERSIONS_TOKEN_EXEMPT);
    $baseUnitIdExempt = qaCatalogResolveExemptPaths(QA_CATALOG_BASE_UNIT_ID_TOKEN_EXEMPT);

    // Scanning with only the unit_conversions exemption still reports
    // CreateUnitHandler/UpdateUnitHandler for base_unit_id - that exemption
    // buys them no pass from this rule. Symmetrically for the other pair.
    expect(qaCatalogLadderTokenOffenders('base_unit_id', qaCatalogLadderScanFiles($unitConversionsExempt)))->toEqualCanonicalizing($baseUnitIdExempt)
        ->and(qaCatalogLadderTokenOffenders('unit_conversions', qaCatalogLadderScanFiles($baseUnitIdExempt)))->toEqualCanonicalizing($unitConversionsExempt);

    // Each list exempts only the files it names - scanning with no
    // exemption at all still reports exactly those files for the token
    // each actually contains, confirming the exemption (not some broader
    // rule) is what currently keeps the suite green.
    expect(qaCatalogLadderTokenOffenders('unit_conversions', qaCatalogLadderScanFiles()))->toEqualCanonicalizing($unitConversionsExempt)
        ->and(qaCatalogLadderTokenOffenders('base_unit_id', qaCatalogLadderScanFiles()))->toEqualCanonicalizing($baseUnitIdExempt);
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
    $exempt = qaCatalogResolveExemptPaths(QA_CATALOG_FACTOR_MULTIPLICATION_EXEMPT);

    expect(qaCatalogFactorMultiplicationOffenders(qaCatalogLadderScanFiles($exempt)))->toBe([]);

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

it('T-029 Ruling 10: the factor exemption is exactly PriceLadder, is the only reason the scan is green, and buys nothing else', function () {
    $exempt = qaCatalogResolveExemptPaths(QA_CATALOG_FACTOR_MULTIPLICATION_EXEMPT);

    // Without the exemption, exactly the exempt file is reported: it is the
    // exemption, and no broader rule, that keeps the suite green.
    expect(qaCatalogFactorMultiplicationOffenders(qaCatalogLadderScanFiles()))->toEqualCanonicalizing($exempt);

    // A factor multiplication anywhere else is still reported, scanned
    // alongside the real tree with the exemption in force.
    $directory = qaCatalogFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$price = \$stockPrice * \$unitFactor;\n");

    try {
        $files = qaCatalogLadderScanFiles($exempt) + qaCatalogSourceFiles($directory);

        expect(qaCatalogFactorMultiplicationOffenders($files))->toBe([(string) realpath($directory.'/Planted.php')]);
    } finally {
        File::deleteDirectory($directory);
    }

    // The exemption is for the factor rule only: the exempt file names neither
    // table nor column the two token scans guard.
    $exemptFiles = array_intersect_key(qaCatalogLadderScanFiles(), array_flip($exempt));

    expect($exemptFiles)->toHaveCount(1)
        ->and(qaCatalogLadderTokenOffenders('unit_conversions', $exemptFiles))->toBe([])
        ->and(qaCatalogLadderTokenOffenders('base_unit_id', $exemptFiles))->toBe([]);
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
