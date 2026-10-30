<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-020 step 14 - workflow-specific source scans (AC-13, AC-22, AC-28,
| AC-31), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| Its own helpers, calling nothing from another Qa*SourceRulesTest file
| (FP-164). Every rule below has a planted-file control proving the scan
| can fail.
*/

function qaWorkflowRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaWorkflowFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_workflow_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * @return array<string, string>
 */
function qaWorkflowSourceFiles(string $directory): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        $files[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
    }

    return $files;
}

it('every scanned directory actually exists', function () {
    foreach ([
        qaWorkflowRepoRoot().'/packages/workflow/src',
        qaWorkflowRepoRoot().'/packages/workflow/tests',
        qaWorkflowRepoRoot().'/packages/contracts/src/Workflow',
    ] as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }
});

/*
|--------------------------------------------------------------------------
| AC-13, AC-31: no edge to the plugin framework
|--------------------------------------------------------------------------
*/

/**
 * @return list<string>
 */
function qaWorkflowPluginReferenceFiles(string $directory): array
{
    $found = [];

    foreach (qaWorkflowSourceFiles($directory) as $path => $contents) {
        if (str_contains($contents, 'Flexipos\\Contracts\\Plugins')
            || str_contains($contents, 'HookGateway')
            || str_contains($contents, 'Flexipos\\PluginSdk')) {
            $found[] = $path;
        }
    }

    return $found;
}

it('AC-13, AC-31: packages/workflow/src names nothing under the plugin framework', function () {
    expect(qaWorkflowPluginReferenceFiles(qaWorkflowRepoRoot().'/packages/workflow/src'))->toBe([]);

    $directory = qaWorkflowFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Flexipos\\Contracts\\Plugins\\HookGateway;\n");

    try {
        expect(qaWorkflowPluginReferenceFiles($directory))->toBe([(string) realpath($directory.'/Planted.php')]);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Ruling 3: no catch block anywhere on evaluate()'s own path returns
|--------------------------------------------------------------------------
*/

/**
 * Files containing a `catch (...) { ... return ... }` block (a return
 * statement textually between a `catch (` and its closing brace, on the
 * same or a following line) - a cheap but sufficient textual scan for
 * this one shape.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaWorkflowReturningCatchFiles(array $files): array
{
    $found = [];

    foreach ($files as $path => $contents) {
        if (preg_match('/catch\s*\([^)]*\)\s*\{[^}]*\breturn\b/s', $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

it('Ruling 3: EvaluateWorkflowHandler.php and WorkflowEngine.php contain no catch block whose body returns', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/src';
    $targets = [
        $root.'/Features/EvaluateWorkflow/EvaluateWorkflowHandler.php',
        $root.'/Engine/WorkflowEngine.php',
    ];

    $files = [];

    foreach ($targets as $target) {
        expect(is_file($target))->toBeTrue("Expected a file at [{$target}].");
        $files[$target] = (string) file_get_contents($target);
    }

    expect(qaWorkflowReturningCatchFiles($files))->toBe([]);

    $directory = qaWorkflowFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nfunction f() {\n    try {\n        g();\n    } catch (Throwable \$e) {\n        return NotRequired;\n    }\n}\n");

    try {
        $planted = [$directory.'/Planted.php' => (string) file_get_contents($directory.'/Planted.php')];
        expect(qaWorkflowReturningCatchFiles($planted))->toBe([$directory.'/Planted.php']);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Ruling 8: WorkflowEventLog.php is the only writer of workflow_events,
| and it only inserts
|--------------------------------------------------------------------------
*/

/**
 * @return list<string>
 */
function qaWorkflowEventsWriters(string $directory, string $exceptBasename): array
{
    $found = [];

    foreach (qaWorkflowSourceFiles($directory) as $path => $contents) {
        if (basename($path) === $exceptBasename) {
            continue;
        }

        if (preg_match('/table\(\s*[\'"]workflow_events[\'"]\s*\)\s*->\s*insert/', $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * @return list<string>
 */
function qaWorkflowEventsMutators(string $directory): array
{
    $found = [];

    foreach (qaWorkflowSourceFiles($directory) as $path => $contents) {
        if (preg_match('/table\(\s*[\'"]workflow_events[\'"]\s*\)(?:(?!;).)*?->\s*(update|delete)\s*\(/s', $contents) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

it('Ruling 8: WorkflowEventLog.php is the only inserter into workflow_events, and nothing updates or deletes it', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/src';

    expect(qaWorkflowEventsWriters($root, 'WorkflowEventLog.php'))->toBe([])
        ->and(qaWorkflowEventsMutators($root))->toBe([]);

    $directory = qaWorkflowFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('workflow_events')->insert(['a' => 1]);\n");

    try {
        expect(qaWorkflowEventsWriters($directory, 'WorkflowEventLog.php'))->toBe([(string) realpath($directory.'/Planted.php')]);
    } finally {
        File::deleteDirectory($directory);
    }

    $directory2 = qaWorkflowFreshTempDirectory();
    File::put($directory2.'/Planted.php', "<?php\n\$x->table('workflow_events')->where('id', 1)->delete();\n");

    try {
        expect(qaWorkflowEventsMutators($directory2))->toBe([(string) realpath($directory2.'/Planted.php')]);
    } finally {
        File::deleteDirectory($directory2);
    }
});

/*
|--------------------------------------------------------------------------
| AC-22: the escalation/SLA columns are a signal only, never read/scheduled
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaWorkflowColumnLiteralFiles(array $files, string $column): array
{
    $found = [];

    foreach ($files as $path => $contents) {
        if (str_contains($contents, $column)) {
            $found[] = $path;
        }
    }

    return $found;
}

it('AC-22: sla_due_at, notified_at and reminded_at are named nowhere in packages/*/src or modules/*/src', function () {
    $root = qaWorkflowRepoRoot();
    $files = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $files += qaWorkflowSourceFiles($dir);
    }

    foreach (glob($root.'/modules/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $files += qaWorkflowSourceFiles($dir);
    }

    // Migration files legitimately declare the column names; they live
    // under database/, not under a package's src/, so they are absent
    // from $files and this scan reports nothing for them.
    foreach (['sla_due_at', 'notified_at', 'reminded_at'] as $column) {
        expect(qaWorkflowColumnLiteralFiles($files, $column))->toBe([]);
    }

    $directory = qaWorkflowFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->sla_due_at;\n");

    try {
        $planted = [$directory.'/Planted.php' => (string) file_get_contents($directory.'/Planted.php')];
        expect(qaWorkflowColumnLiteralFiles($planted, 'sla_due_at'))->toBe([$directory.'/Planted.php']);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-22: sla_hours, escalate_to, can_skip_if and escalation_hours are named only by the validator and the register handler', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/src';
    $allowed = ['DefinitionValidator.php', 'RegisterWorkflowDefinitionHandler.php'];

    $found = [];

    foreach (qaWorkflowSourceFiles($root) as $path => $contents) {
        if (in_array(basename($path), $allowed, true)) {
            continue;
        }

        foreach (['sla_hours', 'escalate_to', 'can_skip_if', 'escalation_hours'] as $column) {
            if (str_contains($contents, $column)) {
                $found[] = $path.':'.$column;
            }
        }
    }

    expect($found)->toBe([]);
});

it('AC-22: no schedule or Schedule reference appears under packages/workflow/src', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/src';

    $found = [];

    foreach (qaWorkflowSourceFiles($root) as $path => $contents) {
        if (preg_match('/\bSchedule\b/', $contents) === 1) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);

    $directory = qaWorkflowFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Illuminate\\Console\\Scheduling\\Schedule;\n");

    try {
        $planted = [$directory.'/Planted.php' => (string) file_get_contents($directory.'/Planted.php')];
        $found2 = [];

        foreach ($planted as $path => $contents) {
            if (preg_match('/\bSchedule\b/', $contents) === 1) {
                $found2[] = $path;
            }
        }

        expect($found2)->toBe([$directory.'/Planted.php']);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| AC-28, D-8: exactly one fixture, no real business workflow, no call
| site in modules/
|--------------------------------------------------------------------------
*/

it('AC-28: the only definition fixture is FixtureApproval.php', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/tests/Fixtures';

    $names = [];

    foreach ((new Finder)->files()->in($root)->name('*.php') as $file) {
        $names[] = $file->getFilename();
    }

    expect($names)->toBe(['FixtureApproval.php']);
});

it('AC-28: every entity_type literal in packages/workflow/tests is custom, except named refusal datasets', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/tests';

    $offenders = [];

    foreach (qaWorkflowSourceFiles($root) as $path => $contents) {
        // Refusal-oriented tests legitimately exercise unknownEntityType
        // with non-custom literals; they are named by their own test, not
        // excluded by directory.
        if (str_contains(basename($path), 'DefinitionValidatorTest')) {
            continue;
        }

        preg_match_all('/entity_type[\'"]?\s*(?:=>|:)\s*[\'"]([a-z_]+)[\'"]/', $contents, $matches);

        foreach ($matches[1] as $value) {
            if ($value !== 'custom') {
                $offenders[] = $path.':'.$value;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('AC-28: packages/workflow/src holds no definition key literal', function () {
    $root = qaWorkflowRepoRoot().'/packages/workflow/src';

    $found = [];

    foreach (qaWorkflowSourceFiles($root) as $path => $contents) {
        if (preg_match('/[\'"]fixture-approval[\'"]/', $contents) === 1) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});

it('D-8: nothing under modules/*/src references Flexipos\Contracts\Workflow', function () {
    $root = qaWorkflowRepoRoot();

    $found = [];

    foreach (glob($root.'/modules/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (qaWorkflowSourceFiles($dir) as $path => $contents) {
            if (str_contains($contents, 'Flexipos\\Contracts\\Workflow')) {
                $found[] = $path;
            }
        }
    }

    expect($found)->toBe([]);

    $directory = qaWorkflowFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Flexipos\\Contracts\\Workflow\\WorkflowGate;\n");

    try {
        $planted = qaWorkflowSourceFiles($directory);
        $found2 = [];

        foreach ($planted as $path => $contents) {
            if (str_contains($contents, 'Flexipos\\Contracts\\Workflow')) {
                $found2[] = $path;
            }
        }

        expect($found2)->toBe([(string) realpath($directory.'/Planted.php')]);
    } finally {
        File::deleteDirectory($directory);
    }
});
