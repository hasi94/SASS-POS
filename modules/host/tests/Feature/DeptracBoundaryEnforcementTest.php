<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * Proves the boundary rule is actually enforced, not merely configured: Deptrac
 * is run against an isolated fixture in which one stand-in module
 * reaches into another one's namespace. The shipped deptrac.yaml never sees
 * these classes, so the repository itself stays violation free.
 */

it('exits non-zero and names both layers when a module depends on another module', function () {
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/deptrac'),
        'analyse',
        '--config-file='.base_path('tests/Fixtures/DeptracViolation/deptrac.yaml'),
        '--cache-file='.base_path('storage/framework/cache/.deptrac-fixture.cache'),
        '--no-progress',
        '--no-ansi',
    ]);

    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->not->toBe(0)
        ->and($output)->toContain('Module_A')
        ->and($output)->toContain('Module_B')
        ->and($output)->toContain('must not depend on');
});

it('exits zero on the shipped configuration', function () {
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/deptrac'),
        'analyse',
        '--config-file='.base_path('deptrac.yaml'),
        '--cache-file='.base_path('storage/framework/cache/.deptrac-shipped.cache'),
        '--no-progress',
        '--no-ansi',
    ]);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('Violations           0');
});

/*
 * T-002 FP-152 / AC-17.
 *
 * Until deptrac.yaml listed modules/*\/tests in its paths, a module's test
 * file could import another module - or the host - with no violation at all,
 * so the one place code deliberately reaches across a boundary was the one
 * place nothing checked. Layers match by namespace rather than by directory, so
 * this needs no new layer: a class declared under Flexipos\Module\Demo\ is in
 * the Module_Demo layer wherever its file lives.
 *
 * Unlike the fixture above, this probe runs against the real, shipped
 * deptrac.yaml, which is the only way to prove the shipped configuration - not
 * a stand-in - now covers test code.
 */

function deptracProbePath(): string
{
    return modulesPath('demo').DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'DeptracProbeTest.php';
}

function runShippedDeptrac(): ProcessResult
{
    return Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/deptrac'),
        'analyse',
        '--config-file='.base_path('deptrac.yaml'),
        '--cache-file='.base_path('storage/framework/cache/.deptrac-testcode.cache'),
        '--no-progress',
        '--no-ansi',
    ]);
}

beforeEach(function () {
    // Symmetry with the host probes, and for the same narrow reason: this
    // protects the next *test run* from a leftover and nothing more. What
    // protects the gate is `composer probes:clean`, the first entry of the check
    // script, because a surviving copy of this probe - a module test class
    // importing the host's ModuleRegistry - aborts `composer check` at
    // @deptrac, the third of five steps, before any hook here could run. It
    // cannot interfere with the plant-and-delete cycle below either: that
    // script runs once, before Pest starts.
    File::delete(deptracProbePath());
});

afterEach(function () {
    File::delete(deptracProbePath());
});

it('exits non-zero when a module test file reaches into another layer', function () {
    File::put(deptracProbePath(), <<<'PHP'
        <?php

        namespace Flexipos\Module\Demo\Tests;

        use App\Support\ModuleHost\ModuleRegistry;

        class Probe
        {
            public function borrow(ModuleRegistry $registry): ModuleRegistry
            {
                return $registry;
            }
        }

        PHP);

    try {
        $result = runShippedDeptrac();
        $output = $result->output().$result->errorOutput();

        expect($result->exitCode())->not->toBe(0)
            ->and($output)->toContain('Module_Demo')
            ->and($output)->toContain('HostApp')
            ->and($output)->toContain('must not depend on');
    } finally {
        File::delete(deptracProbePath());
    }

    // The probe is gone, so the shipped tree is violation free again: this test
    // never leaves modules/demo dirty for the tests that follow it.
    $clean = runShippedDeptrac();

    expect(File::exists(deptracProbePath()))->toBeFalse()
        ->and($clean->exitCode())->toBe(0)
        ->and($clean->output())->toContain('Violations           0');
});
