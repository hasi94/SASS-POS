<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * FP-169, the behavioural half. The configuration half - that both registries
 * name the host's own tests directory - is
 * tests/Feature/HostTestAnalysisCoverageTest.php.
 *
 * A path in a registry proves nothing about whether the tool then finds
 * anything: adding deptrac.yaml's `tests` path alone was measured to be
 * completely inert, because the host's Pest files declare no classes and
 * Tests\* belonged to no layer. So each half of FP-169 is proven the same way
 * T-002 proved the module test directories - by planting a real defect inside
 * the host's own tests directory and watching the shipped configuration fail on
 * it, then watching the clean tree pass.
 *
 * Both probes are named *Probe.php, not *Test.php: PHPUnit's default suffix
 * filter is Test.php, so neither is ever collected as a test, and Pest's
 * BootFiles bootstrapper loads only Helpers.php and Pest.php by name.
 *
 * This file spawns child processes that boot a whole tool, so it belongs to the
 * `toolchain` group and is registered in toolchainGroupFiles(). Its spawn-free
 * sibling deliberately is not.
 */

function hostStanProbePath(): string
{
    return base_path('tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'HostStanProbe.php');
}

function hostDeptracProbePath(): string
{
    return base_path('tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'HostDeptracProbe.php');
}

function hostDeptracCachePath(): string
{
    return base_path('storage/framework/cache/.deptrac-hosttests.cache');
}

function hostRunShippedStan(): ProcessResult
{
    return Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan.neon.dist'),
        '--no-progress',
        '--no-ansi',
        '--error-format=raw',
    ]);
}

function hostRunShippedDeptrac(): ProcessResult
{
    return Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/deptrac'),
        'analyse',
        '--config-file='.base_path('deptrac.yaml'),
        '--cache-file='.hostDeptracCachePath(),
        '--no-progress',
        '--no-ansi',
    ]);
}

/**
 * Remove a planted probe, or say so loudly.
 *
 * Harmless when the probe is already gone, which is what lets it run both before
 * and after every test. Failure is a thrown RuntimeException rather than an
 * expect() call on purpose: this runs from beforeEach/afterEach hooks of tests
 * whose assertion counts are declared, and a surviving planted violation has to
 * be loud rather than counted.
 */
function hostRemoveProbe(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    File::delete($path);

    clearstatcache(true, $path);

    if (file_exists($path)) {
        throw new RuntimeException(
            'could not remove the planted probe '.$path.'. HostDeptracProbe.php declares a '.
            'deliberate boundary violation and HostStanProbe.php a deliberate level 6 defect, so '.
            'either one left behind turns `composer deptrac` or `composer stan` red for every '.
            'later run, naming a file that reads like real code. Delete it by hand.'
        );
    }
}

beforeEach(function () {
    // Load bearing, and not redundant with the afterEach below. No PHP-level
    // teardown runs when the process is killed rather than finishing - a
    // Composer process timeout, a Ctrl-C, a CI step limit - and that is exactly
    // how a planted probe escapes: the measured incident was `composer check`
    // being killed at its 300 s timeout mid-test, after which the next run's
    // deptrac reported a violation in HostDeptracProbe.php and the gate was red
    // for a reason that had nothing to do with the change under test. Cleaning
    // up before planting is the only thing that survives a kill, because it
    // moves the guarantee out of the dying process and into the next one.
    hostRemoveProbe(hostStanProbePath());
    hostRemoveProbe(hostDeptracProbePath());
});

afterEach(function () {
    // The safety net behind each test's own finally, for the ordinary case where
    // the process does finish.
    hostRemoveProbe(hostStanProbePath());
    hostRemoveProbe(hostDeptracProbePath());

    File::delete(hostDeptracCachePath());
});

it('fails PHPStan on a real level 6 defect planted inside the host test directory', function () {
    // An untyped *function* parameter, not an untyped closure parameter: level 6
    // does not report the latter, so a probe built on a closure would pass and
    // look like proof.
    File::put(hostStanProbePath(), <<<'PHP'
        <?php

        function hostStanProbeUntyped($value)
        {
            return $value;
        }

        PHP);

    try {
        $failing = hostRunShippedStan();
        $output = $failing->output().$failing->errorOutput();

        expect($failing->exitCode())->not->toBe(0)
            ->and($output)->toContain('HostStanProbe.php')
            ->and($output)->toContain('hostStanProbeUntyped() has parameter $value with no type specified');
    } finally {
        File::delete(hostStanProbePath());
    }

    // The clean-tree control. Without it, a configuration that failed on
    // everything would pass the assertions above.
    $clean = hostRunShippedStan();

    expect(File::exists(hostStanProbePath()))->toBeFalse()
        ->and($clean->exitCode())->toBe(0);
});

it('fails Deptrac when a host test class imports a module internal', function () {
    // Tests\Feature\* is the HostTests layer; PingController is Module_Demo's
    // internal, which that layer is deliberately not allowed to reach.
    File::put(hostDeptracProbePath(), <<<'PHP'
        <?php

        namespace Tests\Feature;

        use Flexipos\Module\Demo\Http\Controllers\PingController;

        class HostDeptracProbe
        {
            public function borrow(PingController $controller): PingController
            {
                return $controller;
            }
        }

        PHP);

    try {
        $result = hostRunShippedDeptrac();
        $output = $result->output().$result->errorOutput();

        expect($result->exitCode())->not->toBe(0)
            ->and($output)->toContain('HostTests')
            ->and($output)->toContain('Module_Demo')
            ->and($output)->toContain('must not depend on');
    } finally {
        File::delete(hostDeptracProbePath());
    }

    // The clean-tree control: the legitimate shared test infrastructure
    // (TestCase, Helpers.php, Pest.php) and the DeptracViolation fixtures are
    // all inside the newly checked path, and none of them may produce a
    // violation of its own.
    $clean = hostRunShippedDeptrac();

    expect(File::exists(hostDeptracProbePath()))->toBeFalse()
        ->and($clean->exitCode())->toBe(0)
        ->and($clean->output())->toContain('Violations           0');
});
