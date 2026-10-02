<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * T-002 FP-150 (T-001 review finding M-7).
 *
 * phpunit.xml used to list tests/Unit and tests/Feature literally while the
 * globbed Modules suite explicitly excluded ../host/tests, so any other
 * directory under modules/host/tests was collected by nothing at all: it never
 * ran and the gate still went green. That is T-001's BUG-1, relocated to the
 * host. One recursive `tests` suite closes it.
 *
 * The exclude still has to stay, which is the other half of this: without it
 * every host test file is offered to the Modules suite as well and PHPUnit
 * warns once per file while de-duplicating.
 */

function hostSuiteProbePath(): string
{
    return base_path('tests'.DIRECTORY_SEPARATOR.'Integration'.DIRECTORY_SEPARATOR.'HostSuiteProbeTest.php');
}

/**
 * @param  list<string>  $arguments
 */
function hostSuiteChildRun(array $arguments): ProcessResult
{
    return Process::path(base_path())->run(array_merge(
        [PHP_BINARY, base_path('vendor/bin/pest'), '--colors=never'],
        $arguments
    ));
}

afterEach(function () {
    File::deleteDirectory(dirname(hostSuiteProbePath()));
});

it('collects a host test directory that is neither Unit nor Feature', function () {
    File::ensureDirectoryExists(dirname(hostSuiteProbePath()));
    File::put(hostSuiteProbePath(), <<<'PHP'
        <?php

        it('runs from a host directory that is neither Unit nor Feature', function () {
            expect(true)->toBeTrue();
        });

        PHP);

    try {
        // Filtered so the child run can never re-enter this test file.
        $result = hostSuiteChildRun(['--filter=runs from a host directory that is neither Unit nor Feature']);
        $output = $result->output().$result->errorOutput();

        expect($output)->not->toContain('No tests found')
            ->and($output)->toMatch('/Tests:\s+1 passed/')
            ->and($result->exitCode())->toBe(0);
    } finally {
        File::deleteDirectory(dirname(hostSuiteProbePath()));
    }

    expect(File::exists(hostSuiteProbePath()))->toBeFalse();
});

it('collects no test file into two suites at once', function () {
    // A filter nothing matches still walks and registers every suite, so the
    // de-duplication warnings would appear here if they were going to appear at
    // all - without the recursion of shelling out an unfiltered full run.
    $result = hostSuiteChildRun(['--filter=zzz no test is named this zzz']);
    $output = $result->output().$result->errorOutput();

    expect($output)->not->toContain('as it was already added to test suite')
        ->and($output)->not->toContain('WARN');
});
