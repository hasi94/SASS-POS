<?php

use Illuminate\Support\Facades\Process;

/*
 * Proves the boundary rule is actually enforced, not merely configured: Deptrac
 * is run against an isolated fixture in which one stand-in service package
 * reaches into another one's namespace. The shipped deptrac.yaml never sees
 * these classes, so the repository itself stays violation free.
 */

it('exits non-zero and names both layers when a service depends on another service', function () {
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
        ->and($output)->toContain('Service_A')
        ->and($output)->toContain('Service_B')
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
