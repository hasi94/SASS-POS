<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-019 step 15 - plugin-specific source scans (AC-19, AC-36, AC-45),
| qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| Its own helpers, calling nothing from another Qa*SourceRulesTest file
| (FP-164): those functions are namespaced in that file, and cross-file
| reliance on load order is the defect this project already found once.
| Every rule below has a planted-file control proving the scan can fail.
*/

/**
 * @return list<string>
 */
function qaPluginScanDirectories(): array
{
    $root = dirname(__DIR__, 4);

    $directories = [$root.'/modules/host/app'];

    foreach (glob($root.'/packages/*', GLOB_ONLYDIR) ?: [] as $package) {
        if (is_dir($package.'/src')) {
            $directories[] = $package.'/src';
        }
    }

    foreach (glob($root.'/modules/*', GLOB_ONLYDIR) ?: [] as $module) {
        if (is_dir($module.'/src')) {
            $directories[] = $module.'/src';
        }
    }

    sort($directories);

    return $directories;
}

/**
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function qaPluginSourceFiles(array $directories): array
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

function qaPluginRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaPluginRepoRelativePath(string $path): string
{
    $root = str_replace('\\', '/', qaPluginRepoRoot()).'/';
    $normalised = str_replace('\\', '/', $path);

    return str_starts_with($normalised, $root) ? substr($normalised, strlen($root)) : $normalised;
}

function qaPluginFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_plugin_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * Every shipped migration file in either tree.
 *
 * @return list<string>
 */
function qaPluginShippedMigrationFiles(): array
{
    $root = qaPluginRepoRoot();

    return array_merge(
        glob($root.'/modules/host/database/migrations/*.php') ?: [],
        glob($root.'/modules/host/database/migrations-tenant/*.php') ?: []
    );
}

/**
 * Files, among the given ones, naming a `plg_` table literal.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaPluginPlgTableNameFiles(array $files): array
{
    $found = [];

    foreach ($files as $path => $contents) {
        if (preg_match('/[\'"]plg_\w+/', $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files under one directory tree naming a process-spawning call.
 *
 * @return list<string>
 */
function qaPluginProcessSpawnFiles(string $directory): array
{
    $pattern = '/\b(proc_open|exec|shell_exec|pcntl_fork|popen)\s*\(|\bnew\s+(\\\\?Symfony\\\\Component\\\\Process\\\\)?Process\s*\(/';

    $found = [];

    foreach (qaPluginSourceFiles([$directory]) as $path => $contents) {
        if (preg_match($pattern, $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files, other than the one named, writing one of the breaker's own
 * columns - an inline `->update([...'failure_count'...])` array, or
 * `->increment('failure_count'|'tripped_at')`. Never a plain array
 * literal with the same key: that also matches a result row being
 * *read* and shaped for a report, which is not a write at all.
 *
 * @return list<string>
 */
function qaPluginBreakerColumnWriters(string $directory, string $exceptBasename): array
{
    $found = [];

    $updatePattern = '/->\s*update\s*\(\s*\[[^\]]*[\'"](?:failure_count|tripped_at)[\'"]\s*=>/s';
    $incrementPattern = '/->\s*increment\s*\(\s*[\'"](?:failure_count|tripped_at)[\'"]/';

    foreach (qaPluginSourceFiles([$directory]) as $path => $contents) {
        if (basename($path) === $exceptBasename) {
            continue;
        }

        if (preg_match($updatePattern, $contents) === 1 || preg_match($incrementPattern, $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files, other than the one named, inserting into `plugin_execution_logs`.
 *
 * @return list<string>
 */
function qaPluginExecutionLogWriters(string $directory, string $exceptBasename): array
{
    $found = [];

    foreach (qaPluginSourceFiles([$directory]) as $path => $contents) {
        if (basename($path) === $exceptBasename) {
            continue;
        }

        if (preg_match('/table\(\s*[\'"]plugin_execution_logs[\'"]\s*\)\s*->\s*insert/', $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files, other than the two named, mentioning `TenantConnectionPurpose`.
 *
 * @param  list<string>  $exceptBasenames
 * @return list<string>
 */
function qaPluginTenantConnectionPurposeFiles(string $directory, array $exceptBasenames): array
{
    $found = [];

    foreach (qaPluginSourceFiles([$directory]) as $path => $contents) {
        if (in_array(basename($path), $exceptBasenames, true)) {
            continue;
        }

        if (str_contains($contents, 'TenantConnectionPurpose')) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Fixture handler files importing something under the internal
 * `Flexipos\PluginSdk\` namespace.
 *
 * @return list<string>
 */
function qaPluginFixtureInternalImports(string $directory): array
{
    $found = [];

    foreach (qaPluginSourceFiles([$directory]) as $path => $contents) {
        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

        foreach ($matches[1] as $import) {
            if (str_starts_with(trim($import), 'Flexipos\\PluginSdk\\')) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

it('every scanned directory actually exists, so an absent one cannot silently empty the scan', function () {
    foreach (qaPluginScanDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }
});

it('AC-19: no file in either shipped migration tree names a plg_ table', function () {
    $files = [];

    foreach (qaPluginShippedMigrationFiles() as $file) {
        $files[$file] = (string) file_get_contents($file);
    }

    expect(qaPluginPlgTableNameFiles($files))->toBe([]);

    $directory = qaPluginFreshTempDirectory();
    File::put($directory.'/planted.php', "<?php\nSchema::create('plg_loyalty_accounts', function () {});\n");

    try {
        $planted = [$directory.'/planted.php' => (string) file_get_contents($directory.'/planted.php')];
        expect(qaPluginPlgTableNameFiles($planted))->toBe([$directory.'/planted.php']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-36: no process-spawning call exists under packages/plugin-sdk/src', function () {
    $pluginSdkSrc = qaPluginRepoRoot().'/packages/plugin-sdk/src';

    expect(qaPluginProcessSpawnFiles($pluginSdkSrc))->toBe([]);

    $directory = qaPluginFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nproc_open('ls', [], \$pipes);\n");

    try {
        expect(qaPluginProcessSpawnFiles($directory))->toBe([qaPluginFreshTempDirectoryPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

function qaPluginFreshTempDirectoryPlantedPath(string $directory): string
{
    return (string) realpath($directory.'/Planted.php');
}

it('the only file writing the breaker columns is CircuitBreaker.php', function () {
    $pluginSdkSrc = qaPluginRepoRoot().'/packages/plugin-sdk/src';

    expect(qaPluginBreakerColumnWriters($pluginSdkSrc, 'CircuitBreaker.php'))->toBe([]);

    $directory = qaPluginFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->update(['failure_count' => 1]);\n");

    try {
        expect(qaPluginBreakerColumnWriters($directory, 'CircuitBreaker.php'))->toBe([qaPluginFreshTempDirectoryPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('the only file inserting into plugin_execution_logs is ExecutionLog.php', function () {
    $pluginSdkSrc = qaPluginRepoRoot().'/packages/plugin-sdk/src';

    expect(qaPluginExecutionLogWriters($pluginSdkSrc, 'ExecutionLog.php'))->toBe([]);

    $directory = qaPluginFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('plugin_execution_logs')->insert(['a' => 1]);\n");

    try {
        expect(qaPluginExecutionLogWriters($directory, 'ExecutionLog.php'))->toBe([qaPluginFreshTempDirectoryPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('TenantConnectionPurpose appears in plugin-sdk/src only in its two allowed files', function () {
    $pluginSdkSrc = qaPluginRepoRoot().'/packages/plugin-sdk/src';
    $allowed = ['MigratePluginHandler.php', 'UninstallPluginHandler.php'];

    expect(qaPluginTenantConnectionPurposeFiles($pluginSdkSrc, $allowed))->toBe([]);

    $directory = qaPluginFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Flexipos\\Kernel\\Tenancy\\TenantConnectionPurpose;\n");

    try {
        expect(qaPluginTenantConnectionPurposeFiles($directory, $allowed))->toBe([qaPluginFreshTempDirectoryPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('Ruling 1: the FixtureEcho handler files import nothing under Flexipos\PluginSdk', function () {
    $fixtureDir = qaPluginRepoRoot().'/packages/plugin-sdk/tests/Fixtures/FixtureEcho';

    expect(qaPluginFixtureInternalImports($fixtureDir))->toBe([]);

    $directory = qaPluginFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Flexipos\\PluginSdk\\Gateway\\Gateway;\n");

    try {
        expect(qaPluginFixtureInternalImports($directory))->toBe([qaPluginFreshTempDirectoryPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-45: FixtureEcho/plugin.json is the only plugin.json in the repository', function () {
    $root = qaPluginRepoRoot();

    $files = (new Finder)->files()->in($root)->name('plugin.json')
        ->exclude(['vendor', 'node_modules'])
        ->notPath('vendor');

    $paths = [];

    foreach ($files as $file) {
        $paths[] = qaPluginRepoRelativePath($file->getRealPath());
    }

    sort($paths);

    expect($paths)->toBe(['packages/plugin-sdk/tests/Fixtures/FixtureEcho/plugin.json']);
});

it('AC-45: every manifest name literal in packages/plugin-sdk/tests starts with fixture-', function () {
    $testsDir = qaPluginRepoRoot().'/packages/plugin-sdk/tests';

    $names = [];

    foreach ((new Finder)->files()->in($testsDir)->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        preg_match_all('/[\'"]name[\'"]\s*=>\s*[\'"]([a-z0-9-]+)[\'"]/', $contents, $matches);

        foreach ($matches[1] as $name) {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));

    $offenders = array_values(array_filter($names, static fn (string $name): bool => ! str_starts_with($name, 'fixture-')));

    expect($offenders)->toBe([])
        ->and($names)->not->toBe([]);
});

it('AC-45: packages/plugin-sdk/src names no plugin at all', function () {
    $pluginSdkSrc = qaPluginRepoRoot().'/packages/plugin-sdk/src';

    $found = [];

    foreach (qaPluginSourceFiles([$pluginSdkSrc]) as $path => $contents) {
        if (preg_match('/[\'"]fixture-echo[\'"]/', $contents) === 1) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});
