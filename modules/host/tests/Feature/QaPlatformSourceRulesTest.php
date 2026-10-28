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
| Two structural rules, each with a planted-string control proving the scan
| can fail: the only file writing `tenants.plan_id` is `PlanMirror`, and the
| only file writing `tenants.status` is `TenantStatusSync`. Both scan every
| shared package's source and the host application.
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
 * Files, other than the one named, whose code (comments stripped) writes a
 * table.column pair via `->table('table')->...->update([... 'column' => ...`
 * in a recognisable shape - matched loosely, by the presence of the table
 * name and the column name together in an `update(` call.
 *
 * @return list<string>
 */
function qaPlatformOtherWriters(string $table, string $column, string $exceptBasename): array
{
    $found = [];

    foreach (qaPlatformSourceFiles(qaPlatformSourceDirectories()) as $path => $contents) {
        if (basename($path) === $exceptBasename) {
            continue;
        }

        if (! str_contains($contents, "table('{$table}')")) {
            continue;
        }

        if (preg_match('/update\s*\(\s*\[[^\]]*[\'"]'.preg_quote($column, '/').'[\'"]\s*=>/s', $contents) === 1) {
            $found[] = $path;
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
    foreach (qaPlatformSourceDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }
});

it('the only file writing tenants.plan_id is PlanMirror, with a planted-string control', function () {
    expect(qaPlatformOtherWriters('tenants', 'plan_id', 'PlanMirror.php'))->toBe([]);

    $planted = sys_get_temp_dir().'/qa_platform_planted_'.bin2hex(random_bytes(6)).'.php';
    File::put($planted, "<?php\n\$x->table('tenants')->where('id', \$id)->update(['plan_id' => \$planId]);\n");

    try {
        $contents = (string) file_get_contents($planted);

        expect(str_contains($contents, "table('tenants')")
            && preg_match('/update\s*\(\s*\[[^\]]*[\'"]plan_id[\'"]\s*=>/s', $contents) === 1)->toBeTrue();
    } finally {
        File::delete($planted);
    }
});

it('the only file writing tenants.status is TenantStatusSync, with a planted-string control', function () {
    expect(qaPlatformOtherWriters('tenants', 'status', 'TenantStatusSync.php'))->toBe([]);

    $planted = sys_get_temp_dir().'/qa_platform_planted_'.bin2hex(random_bytes(6)).'.php';
    File::put($planted, "<?php\n\$x->table('tenants')->where('id', \$id)->update(['status' => \$status]);\n");

    try {
        $contents = (string) file_get_contents($planted);

        expect(str_contains($contents, "table('tenants')")
            && preg_match('/update\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s', $contents) === 1)->toBeTrue();
    } finally {
        File::delete($planted);
    }
});

/**
 * Files, other than the ones named, whose code (comments stripped) opens a
 * query against the given table via `->table('<table>')` - matched loosely,
 * same shape as {@see qaPlatformOtherWriters()} but without the column/update
 * requirement, since a read is as much "naming" the table as a write is.
 *
 * @param  list<string>  $exceptBasenames
 * @return list<string>
 */
function qaPlatformOtherNamers(string $table, array $exceptBasenames): array
{
    $found = [];

    foreach (qaPlatformSourceFiles(qaPlatformSourceDirectories()) as $path => $contents) {
        if (in_array(basename($path), $exceptBasenames, true)) {
            continue;
        }

        if (str_contains($contents, "table('{$table}')") || str_contains($contents, "table(\"{$table}\")")) {
            $found[] = $path;
        }
    }

    return $found;
}

it('AC-22: plan_features and tenant_feature_flags are named only by MasterEntitlementSource and SetTenantFeatureFlagHandler', function () {
    expect(qaPlatformOtherNamers('plan_features', ['MasterEntitlementSource.php']))->toBe([]);
    expect(qaPlatformOtherNamers('tenant_feature_flags', ['MasterEntitlementSource.php', 'SetTenantFeatureFlagHandler.php']))->toBe([]);

    $planted = sys_get_temp_dir().'/qa_platform_planted_'.bin2hex(random_bytes(6)).'.php';
    File::put($planted, "<?php\n\$x->table('plan_features')->get();\n");

    try {
        expect(str_contains((string) file_get_contents($planted), "table('plan_features')"))->toBeTrue();
    } finally {
        File::delete($planted);
    }
});

it('AC-22: the flag precedence is built only in FlagLadder', function () {
    $offenders = [];

    foreach (qaPlatformSourceFiles(qaPlatformSourceDirectories()) as $path => $contents) {
        if (basename($path) === 'FlagLadder.php') {
            continue;
        }

        // The ladder's own shape: a tenant-override check, an "else" to a
        // plan-row check, an "else" to a default - looked for as the two
        // boolean parameters FlagLadder::resolve() takes, which only its own
        // call site and its own definition may name.
        if (str_contains($contents, 'planHasRow') || str_contains($contents, 'tenantHasOverride')) {
            if (basename($path) !== 'ResolveFeatureFlagHandler.php') {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);

    $planted = sys_get_temp_dir().'/qa_platform_planted_'.bin2hex(random_bytes(6)).'.php';
    File::put($planted, "<?php\n\$planHasRow = true; \$tenantHasOverride = false;\n");

    try {
        $contents = (string) file_get_contents($planted);

        expect(str_contains($contents, 'planHasRow') || str_contains($contents, 'tenantHasOverride'))->toBeTrue();
    } finally {
        File::delete($planted);
    }
});

it('AC-23: no route, middleware or handler outside packages/platform references FeatureFlags', function () {
    $offenders = [];

    $directories = [
        dirname(__DIR__, 3).'/packages/access/src',
        dirname(__DIR__, 3).'/packages/auth/src',
        dirname(__DIR__, 3).'/packages/organization/src',
        dirname(__DIR__).'/../app',
        dirname(__DIR__).'/../routes',
    ];

    foreach (qaPlatformSourceFiles($directories) as $path => $contents) {
        if (str_contains($contents, 'FeatureFlags')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);

    // Positive control: the scan itself can detect the planted reference, so
    // an empty result above is "nothing found", not "nothing scanned".
    $planted = sys_get_temp_dir().'/qa_platform_planted_'.bin2hex(random_bytes(6)).'.php';
    File::put($planted, "<?php\n// uses FeatureFlags\n");

    try {
        expect(str_contains((string) file_get_contents($planted), 'FeatureFlags'))->toBeTrue();
    } finally {
        File::delete($planted);
    }
});

it('AC-4: tenant_usage_metrics is named nowhere in packages/*/src or modules/host/app', function () {
    $offenders = [];

    foreach (qaPlatformSourceFiles(qaPlatformSourceDirectories()) as $path => $contents) {
        if (str_contains($contents, 'tenant_usage_metrics')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);

    // Positive control: the scan itself can detect the string.
    $planted = sys_get_temp_dir().'/qa_platform_planted_'.bin2hex(random_bytes(6)).'.php';
    File::put($planted, "<?php\n// tenant_usage_metrics\n");

    try {
        expect(str_contains((string) file_get_contents($planted), 'tenant_usage_metrics'))->toBeTrue();
    } finally {
        File::delete($planted);
    }
});
