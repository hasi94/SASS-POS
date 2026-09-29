<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * QA proofs for T-002 AC-12 and AC-13.
 *
 * HostTestSuiteCollectionTest (dev's) proves a probe under tests/Integration
 * runs, and asserts the absence of PHPUnit's duplicate-collection warning in a
 * child run whose --filter matches nothing - arguing that collection happens
 * before filtering. Both are assertions about what is *not* there, which is
 * exactly the shape that passes for the wrong reason, so each gets a negative
 * control here:
 *
 *  - the same probe, run against a config in the pre-T-002 shape (literal
 *    tests/Unit + tests/Feature suites), must NOT be found. Otherwise "it ran"
 *    would not be evidence that collapsing the suites is what made it run.
 *  - the duplicate-collection warning must genuinely appear, under the same
 *    nothing-matching filter, as soon as <exclude>../host/tests</exclude> is
 *    taken out. That is what makes dev's "no warning" assertion meaningful
 *    rather than vacuous, and it settles the deviation dev flagged (an
 *    unfiltered child run would recurse into this very file).
 */

function qaCollectionProbePath(): string
{
    return base_path('tests'.DIRECTORY_SEPARATOR.'Integration'.DIRECTORY_SEPARATOR.'QaCollectionProbeTest.php');
}

/**
 * Write a variant of the shipped phpunit.xml next to it, so its relative paths
 * keep resolving, and return the file name to pass to --configuration.
 */
function qaWriteSuiteConfig(string $fileName, callable $mutate): string
{
    $path = base_path($fileName);

    File::put($path, $mutate(File::get(base_path('phpunit.xml'))));

    return $path;
}

function qaSuiteChildRun(array $arguments): ProcessResult
{
    return Process::path(base_path())->run(array_merge(
        [PHP_BINARY, base_path('vendor/bin/pest'), '--colors=never'],
        $arguments
    ));
}

afterEach(function () {
    File::deleteDirectory(dirname(qaCollectionProbePath()));
    File::delete(base_path('phpunit.qa-legacy.xml'));
    File::delete(base_path('phpunit.qa-no-exclude.xml'));
});

it('collects a new host test directory only because the Host suite is recursive', function () {
    $marker = 'qa collection probe in a third host directory';

    File::ensureDirectoryExists(dirname(qaCollectionProbePath()));
    File::put(qaCollectionProbePath(), <<<PHP
        <?php

        it('{$marker}', function () {
            expect(true)->toBeTrue();
        });

        PHP);

    $legacy = qaWriteSuiteConfig('phpunit.qa-legacy.xml', function (string $xml): string {
        // The pre-T-002 shape, exactly as T-001 shipped it.
        $replaced = preg_replace(
            '#<testsuite name="Host">.*?</testsuite>#s',
            "<testsuite name=\"Unit\">\n            <directory>tests/Unit</directory>\n        </testsuite>\n".
            "        <testsuite name=\"Feature\">\n            <directory>tests/Feature</directory>\n        </testsuite>",
            $xml,
            1
        );

        expect($replaced)->toBeString()->not->toBe($xml);

        return (string) $replaced;
    });

    try {
        // Negative control: the old suite shape cannot see the directory at all.
        $before = qaSuiteChildRun(['--configuration='.$legacy, '--filter='.$marker]);

        expect($before->output().$before->errorOutput())->toContain('No tests found');

        // AC-12: the shipped configuration collects and runs it.
        $after = qaSuiteChildRun(['--filter='.$marker]);
        $output = $after->output().$after->errorOutput();

        expect($output)->not->toContain('No tests found')
            ->and($output)->toMatch('/Tests:\s+1 passed/')
            ->and($after->exitCode())->toBe(0);
    } finally {
        File::deleteDirectory(dirname(qaCollectionProbePath()));
        File::delete($legacy);
    }

    expect(File::exists(qaCollectionProbePath()))->toBeFalse()
        ->and(File::exists($legacy))->toBeFalse();
});

it('proves a nothing-matching filter still performs the whole collection, then that collection is duplicate free', function () {
    $noMatch = '--filter=zzz no test is named this zzz';

    $withoutExclude = qaWriteSuiteConfig('phpunit.qa-no-exclude.xml', function (string $xml): string {
        $replaced = str_replace('<exclude>../host/tests</exclude>', '', $xml);

        expect($replaced)->not->toBe($xml);

        return $replaced;
    });

    try {
        // Negative control: with the exclude removed, every host test file is
        // offered to the Services suite too. The warnings appear even though
        // the filter matches nothing, which is the proof that collection runs
        // before filtering - so dev's filtered run is a valid way to assert
        // their absence without an unfiltered child run recursing into itself.
        $duplicating = qaSuiteChildRun(['--configuration='.$withoutExclude, $noMatch]);
        $noisy = $duplicating->output().$duplicating->errorOutput();

        expect($noisy)->toContain('as it was already added to test suite')
            ->and($noisy)->toContain('QaHostSuiteCollectionProofTest.php')
            ->and($noisy)->toContain('No tests found');

        // AC-13: the shipped configuration collects the same files once each.
        $shipped = qaSuiteChildRun([$noMatch]);
        $clean = $shipped->output().$shipped->errorOutput();

        expect($clean)->not->toContain('as it was already added to test suite')
            ->and($clean)->not->toContain('WARN')
            ->and($clean)->toContain('No tests found');
    } finally {
        File::delete($withoutExclude);
    }

    expect(File::exists($withoutExclude))->toBeFalse();
});

it('keeps collecting the two original host directories under the single suite', function () {
    // Collapsing Unit and Feature into one recursive suite must not have made
    // either of them stop running - the failure mode that would look like a
    // green, smaller suite.
    foreach ([
        'test_that_true_is_true' => 'tests/Unit/ExampleTest.php',
        'it reports liveness' => 'tests/Feature/HealthEndpointTest.php',
    ] as $marker => $file) {
        expect(File::exists(base_path($file)))->toBeTrue();

        $result = qaSuiteChildRun(['--filter='.$marker]);
        $output = $result->output().$result->errorOutput();

        expect($output)->not->toContain('No tests found')
            ->and($output)->toMatch('/Tests:\s+\d+ passed/')
            ->and($result->exitCode())->toBe(0);
    }
});
