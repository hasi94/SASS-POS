<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * QA test for T-001 AC-26 (and the services/* half of AC-16).
 *
 * AC-26: "Given the package generated in AC-25, when running `composer test`
 * without any further edits, then the generated test passes out of the box."
 *
 * The generated test itself is fine - run directly it passes. What is missing is
 * discovery: phpunit.xml lists every service test directory literally, so a newly
 * generated package contributes zero tests and the run still goes green. A green
 * run that silently skipped the new service's only test is worse than a red one,
 * which is why this is asserted rather than left to a manual check.
 *
 * Currently RED. See docs/qa/T-001-monorepo-service-host.md, BUG-1.
 */

afterEach(function () {
    File::deleteDirectory(servicesPath('qadiscoveryfixture'));
});

it('runs a freshly generated service package own test with no further edits', function () {
    expect(Artisan::call('svc:make', ['name' => 'qadiscoveryfixture']))->toBe(0)
        ->and(File::exists(servicesPath('qadiscoveryfixture').'/tests/Feature/PingRouteTest.php'))->toBeTrue();

    // Filtered so the child run can never re-enter this test file.
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/pest'),
        '--filter=qadiscoveryfixture',
        '--colors=never',
    ]);

    $output = $result->output().$result->errorOutput();

    expect($output)->not->toContain('No tests found')
        ->and($output)->toMatch('/Tests:\s+\d+ passed/')
        ->and($result->exitCode())->toBe(0);
});
