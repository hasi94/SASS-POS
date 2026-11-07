<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-016 step 12 - the sole-writer source scans (AC-15, qa-accountable)
|--------------------------------------------------------------------------
|
| Two structural rules, each with a planted-file control proving the scan
| can fail: the only file writing `tenants.plan_id` is `PlanMirror`, and the
| only file writing `tenants.status` is `TenantStatusSync`. Both scan every
| shared package's source and the host application.
|
| **Every control runs the real scanner function over a real directory
| holding the planted file** (reviewer round 1, W-1) - not a bare
| `str_contains()`/`preg_match()` against the planted file's own contents.
| A control that only proves the string-matching primitive works, without
| ever calling the scanner, cannot catch the scanner reading the wrong
| directory (as BUG-1/R-1 both were) or the wrong pattern.
|
| **Stated limit** (memory: a type-graph rule does not see a literal):
| this is a text scan over the shipped source, not a boundary check - it
| finds a quoted table/column pair in code, not a runtime write.
*/

/**
 * @return list<string>
 */
function qaPlatformSourceDirectories(): array
{
    return [
        dirname(__DIR__, 4).'/packages/access/src',
        dirname(__DIR__, 4).'/packages/auth/src',
        dirname(__DIR__, 4).'/packages/organization/src',
        dirname(__DIR__, 4).'/packages/platform/src',
        dirname(__DIR__).'/../app',
    ];
}

/**
 * {@see qaPlatformSourceDirectories()} with `packages/platform/src` removed
 * and `routes/` added - AC-23's own subject (T-016 reviewer round 1, R-1):
 * "no route, middleware or handler **outside** `packages/platform`" has to
 * exclude platform's own source, which is exactly where `FeatureFlags` is
 * declared and resolved, or the scan reports the resolver's own definition
 * as an offender.
 *
 * **Built from the one shared directory list, not a second local one.**
 * R-1 was a second `dirname(__DIR__, 3)` list inside the AC-23 test itself,
 * one level short of the repository root, never covered by the directory-
 * existence guard below because that guard only walks
 * {@see qaPlatformSourceDirectories()}. Deriving this list from the same
 * function means the existence guard covers it by construction.
 *
 * @return list<string>
 */
function qaPlatformAc23Directories(): array
{
    $withoutPlatform = array_values(array_filter(
        qaPlatformSourceDirectories(),
        static fn (string $directory): bool => ! str_ends_with(str_replace('\\', '/', $directory), 'packages/platform/src')
    ));

    return array_merge($withoutPlatform, [dirname(__DIR__).'/../routes']);
}

/**
 * Every first-party PHP file under the given directories, as [path => contents].
 *
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function qaPlatformSourceFiles(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $files[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
        }
    }

    return $files;
}

/**
 * A fresh, empty, uniquely-named directory under the system temp directory,
 * for a planted-file control to scan - never the system temp directory
 * itself, which may hold other processes' files the scanner has no business
 * reading (T-016 reviewer round 1, W-1).
 */
function qaPlatformFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_platform_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * The real, OS-normalised path of a planted `Planted.php` file inside a
 * fresh temp directory, for comparison against a scanner result. Finder
 * returns `realpath()`-shaped paths (backslashes on Windows); naive string
 * concatenation of `$directory.'/Planted.php'` does not match that shape.
 */
function qaPlatformPlantedPath(string $directory): string
{
    return (string) realpath($directory.'/Planted.php');
}

/**
 * Files, other than the one named, whose code (comments stripped) writes the
 * given table/column pair, matched two ways so the scan is not fooled by
 * which of the project's own two shapes a writer happens to use:
 *
 * - **the inline-literal shape**: `->update(['...', 'column' => ...])`, or a
 *   variable initialised with that literal, `$x = ['column' => ..., ...]`;
 * - **the built-up shape**: `$x['column'] = ...;` (an assignment, not a
 *   comparison or an array-literal key), anywhere in the same file.
 *
 * Both require `table('<table>')` to appear in the file, and `->update(`
 * to appear somewhere in it. **This widening is necessary**
 * (T-016 reviewer round 1, W-1): `TenantStatusSync`'s own `$updates` array is
 * built across several statements and handed to `update($updates)`, which
 * the inline-literal pattern alone would never match for a *second* file
 * copying that same shape - only this file's own exclusion by basename hid
 * that gap until now. Measured against every file in
 * {@see qaPlatformSourceDirectories()} to carry no false positive today: the
 * handlers that legitimately read `tenants.status` (`RefreshTokenHandler`,
 * `RefreshTerminalTokenHandler`, `SelectTenantHandler`) and the sweep that
 * reads it in a `where()` clause (`EnforceCommercialDeadlinesHandler`) use
 * `->where('status', ...)` / `->first([..., 'status', ...])`, never an
 * assignment into an array key, so the tightened (assignment-only) form of
 * the built-up pattern does not flag them.
 *
 * @param  string|list<string>  $exceptBasename  one basename, or several
 *                                               legitimate writers of this
 *                                               pair (T-026 D-7: `activate`
 *                                               is a second, intentional
 *                                               writer of `tenants.status`,
 *                                               alongside `TenantStatusSync`)
 * @param  list<string>|null  $directories  defaults to {@see qaPlatformSourceDirectories()}
 * @return list<string>
 */
function qaPlatformOtherWriters(string $table, string $column, string|array $exceptBasename, ?array $directories = null): array
{
    $found = [];
    $quotedColumn = preg_quote($column, '/');
    $exceptBasenames = is_array($exceptBasename) ? $exceptBasename : [$exceptBasename];

    $inlineLiteral = '/(?:update|=)\s*\(?\s*\[[^\]]*[\'"]'.$quotedColumn.'[\'"]\s*=>/s';
    $builtUpAssignment = '/\$\w+\s*\[\s*[\'"]'.$quotedColumn.'[\'"]\s*\]\s*=(?!=)/';

    foreach (qaPlatformSourceFiles($directories ?? qaPlatformSourceDirectories()) as $path => $contents) {
        if (in_array(basename($path), $exceptBasenames, true)) {
            continue;
        }

        if (! str_contains($contents, "table('{$table}')")) {
            continue;
        }

        if (! preg_match('/->\s*update\s*\(/', $contents)) {
            continue;
        }

        if (preg_match($inlineLiteral, $contents) === 1 || preg_match($builtUpAssignment, $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files, other than the ones named, whose code (comments stripped) opens a
 * query against the given table via `->table('<table>')` - matched loosely,
 * same shape as {@see qaPlatformOtherWriters()} but without the column/update
 * requirement, since a read is as much "naming" the table as a write is.
 *
 * @param  list<string>  $exceptBasenames
 * @param  list<string>|null  $directories  defaults to {@see qaPlatformSourceDirectories()}
 * @return list<string>
 */
function qaPlatformOtherNamers(string $table, array $exceptBasenames, ?array $directories = null): array
{
    $found = [];

    foreach (qaPlatformSourceFiles($directories ?? qaPlatformSourceDirectories()) as $path => $contents) {
        if (in_array(basename($path), $exceptBasenames, true)) {
            continue;
        }

        if (str_contains($contents, "table('{$table}')") || str_contains($contents, "table(\"{$table}\")")) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files, other than the ones named, whose code (comments stripped) contains
 * any of the given literal fragments - the shared engine behind the AC-22
 * ladder-only scan, the AC-23 reference scan and the AC-4 absence scan.
 *
 * @param  list<string>  $literals
 * @param  list<string>  $exceptBasenames
 * @param  list<string>  $directories
 * @return list<string>
 */
function qaPlatformOtherNamersOfLiteral(array $literals, array $exceptBasenames, array $directories): array
{
    $found = [];

    foreach (qaPlatformSourceFiles($directories) as $path => $contents) {
        if (in_array(basename($path), $exceptBasenames, true)) {
            continue;
        }

        foreach ($literals as $literal) {
            if (str_contains($contents, $literal)) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

it('every scanned directory actually exists, so an absent one cannot silently empty the scan', function () {
    // QA-1 (measured on this branch): qaPlatformSourceDirectories() once
    // pointed one level too high, so every packages/*/src entry resolved to
    // a non-existent path and qaPlatformSourceFiles()'s own is_dir() guard
    // skipped each one without complaint - every scan below was reading only
    // modules/host/app and reporting a false "nothing found" regardless of
    // what the packages actually contained. This guard turns that silent
    // skip into a hard failure.
    //
    // R-1 (reviewer round 1): the AC-23 test built its own, second directory
    // list with the same off-by-one, which this guard never saw because it
    // was not routed through qaPlatformSourceDirectories(). Fixed by
    // deriving qaPlatformAc23Directories() from this same list, so the guard
    // below now covers it too.
    foreach (qaPlatformSourceDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }

    foreach (qaPlatformAc23Directories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }
});

it('the only file writing tenants.plan_id is PlanMirror, with a planted-file control', function () {
    expect(qaPlatformOtherWriters('tenants', 'plan_id', 'PlanMirror.php'))->toBe([]);

    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('tenants')->where('id', \$id)->update(['plan_id' => \$planId]);\n");

    try {
        expect(qaPlatformOtherWriters('tenants', 'plan_id', 'PlanMirror.php', [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('the only file writing tenants.plan_id is PlanMirror: the built-up-array shape is caught too', function () {
    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$updates = ['updated_at' => \$now];\n\$updates['plan_id'] = \$planId;\n\$x->table('tenants')->where('id', \$id)->update(\$updates);\n");

    try {
        expect(qaPlatformOtherWriters('tenants', 'plan_id', 'PlanMirror.php', [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('the only files writing tenants.status are TenantStatusSync and activate, with a planted-file control', function () {
    // T-026 D-7: `activate` is the one explicit `provisioning -> trial|active`
    // transition `TenantStatusSync` itself declines to make for a protected
    // status (`TenantCommercialStatus::PROTECTED`) - a second, intentional
    // writer, not a regression of this rule.
    expect(qaPlatformOtherWriters('tenants', 'status', ['TenantStatusSync.php', 'Activate.php']))->toBe([]);

    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('tenants')->where('id', \$id)->update(['status' => \$status]);\n");

    try {
        expect(qaPlatformOtherWriters('tenants', 'status', 'TenantStatusSync.php', [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('the only file writing tenants.status is TenantStatusSync: the built-up-array shape (its own actual shape) is caught too', function () {
    // The exact shape TenantStatusSync itself uses: an array built across
    // several statements, handed to update() by variable. Proves the
    // widened scan would catch a *second* file copying that shape, not only
    // the inline-literal one (T-016 reviewer round 1, W-1).
    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', <<<'PHP'
<?php
$updates = ['updated_at' => $now];
$updates['status'] = $target['status'];
$master->table('tenants')->where('id', $tenantId)->update($updates);
PHP);

    try {
        expect(qaPlatformOtherWriters('tenants', 'status', 'TenantStatusSync.php', [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('the widened sole-writer scan does not flag a file that only reads tenants.status', function () {
    // Negative control for the widening itself: a file that queries
    // tenants.status (where()/first()) but never assigns into an update
    // array must not be reported, matching the real
    // RefreshTokenHandler/SelectTenantHandler/EnforceCommercialDeadlinesHandler
    // shape measured on this branch.
    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$tenant = \$x->table('tenants')->where('id', \$id)->first(['status', 'deleted_at']);\n\$x->table('tenants')->where('id', \$id)->update(['last_seen_at' => \$now]);\n");

    try {
        expect(qaPlatformOtherWriters('tenants', 'status', 'TenantStatusSync.php', [$directory]))->toBe([]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-22: plan_features and tenant_feature_flags are named only by MasterEntitlementSource and SetTenantFeatureFlagHandler', function () {
    expect(qaPlatformOtherNamers('plan_features', ['MasterEntitlementSource.php']))->toBe([]);
    expect(qaPlatformOtherNamers('tenant_feature_flags', ['MasterEntitlementSource.php', 'SetTenantFeatureFlagHandler.php']))->toBe([]);

    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('plan_features')->get();\n");

    try {
        expect(qaPlatformOtherNamers('plan_features', ['MasterEntitlementSource.php'], [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-22: the flag precedence is built only in FlagLadder', function () {
    $directories = qaPlatformSourceDirectories();

    expect(qaPlatformOtherNamersOfLiteral(['planHasRow', 'tenantHasOverride'], ['FlagLadder.php', 'ResolveFeatureFlagHandler.php'], $directories))->toBe([]);

    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$planHasRow = true; \$tenantHasOverride = false;\n");

    try {
        expect(qaPlatformOtherNamersOfLiteral(['planHasRow', 'tenantHasOverride'], ['FlagLadder.php', 'ResolveFeatureFlagHandler.php'], [$directory]))
            ->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-23: no route, middleware or handler outside packages/platform references FeatureFlags', function () {
    $directories = qaPlatformAc23Directories();

    expect(qaPlatformOtherNamersOfLiteral(['FeatureFlags'], [], $directories))->toBe([]);

    // Positive control: the scan itself, run over a fresh directory holding
    // the planted reference, finds it - so the empty result above is
    // "nothing found", not "nothing scanned" (T-016 reviewer round 1, W-1).
    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n// uses FeatureFlags\n");

    try {
        expect(qaPlatformOtherNamersOfLiteral(['FeatureFlags'], [], [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-4: tenant_usage_metrics is named nowhere in packages/*/src or modules/host/app', function () {
    $directories = qaPlatformSourceDirectories();

    expect(qaPlatformOtherNamersOfLiteral(['tenant_usage_metrics'], [], $directories))->toBe([]);

    // Positive control: the scan itself, run over a fresh directory holding
    // the planted literal, finds it.
    $directory = qaPlatformFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n// tenant_usage_metrics\n");

    try {
        expect(qaPlatformOtherNamersOfLiteral(['tenant_usage_metrics'], [], [$directory]))->toBe([qaPlatformPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});
