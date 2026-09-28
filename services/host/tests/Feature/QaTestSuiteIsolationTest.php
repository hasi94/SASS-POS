<?php

use Illuminate\Support\Facades\Process;

/*
 * QA test for the trustworthiness of the gate itself (spec "Users affected":
 * qa and reviewer depend on composer lint|stan|deptrac|test being trustworthy).
 *
 * phpunit.xml sets <env name="SERVICES" value="demo" force="true"/> so the suite
 * is meant to be independent of the machine it runs on. PHPUnit's force writes
 * putenv() and $_ENV but not $_SERVER, and Laravel's Env repository reads
 * $_SERVER first, so an ambient SERVICES variable still wins and the whole suite
 * dies in setUp with UnknownServiceException before any test body runs.
 *
 * Currently RED. See docs/qa/T-001-monorepo-service-host.md, BUG-4.
 */

it('ignores an ambient SERVICES environment variable', function () {
    // Filtered so the child run can never re-enter this test file.
    $result = Process::path(base_path())
        ->env(['SERVICES' => 'ghost'])
        ->run([
            PHP_BINARY,
            base_path('vendor/bin/pest'),
            '--filter=it reports liveness',
            '--colors=never',
        ]);

    $output = $result->output().$result->errorOutput();

    expect($output)->not->toContain('UnknownServiceException')
        ->and($output)->toMatch('/Tests:\s+\d+ passed/')
        ->and($result->exitCode())->toBe(0);
});
