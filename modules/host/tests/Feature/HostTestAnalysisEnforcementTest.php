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

/**
 * The probe QaTestCodeAnalysisCoverageTest plants inside the demo module.
 *
 * Named here only so the gate-cleanup registry can be checked against it; this
 * file never relies on that test having run.
 */
function hostDemoProbePath(): string
{
    return modulesPath('demo').DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'QaStanProbe.php';
}

/**
 * Absolute paths the gate cleanup says it removes.
 *
 * @return list<string>
 */
function hostRegisteredProbePaths(): array
{
    $result = Process::path(base_path())->run([PHP_BINARY, base_path('tools/clean-probes.php'), '--list']);

    $paths = [];

    foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
        $line = trim($line);

        if (str_starts_with($line, 'probe ')) {
            $paths[] = substr($line, strlen('probe '));
        }
    }

    sort($paths);

    return $paths;
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
    // This protects the next *test run* from a leftover, and nothing more.
    //
    // It does NOT protect the gate, and an earlier version of this comment
    // claimed it did. `composer check` runs lint, then stan, then deptrac, then
    // test: a surviving probe aborts the run at the second or third step, before
    // any test hook executes, so this hook is dead code in exactly the case it
    // was written for. QA measured that by planting the probe by hand and
    // reproducing the original two violations.
    //
    // What actually protects the gate is `composer probes:clean`, the first
    // entry of the check script - the only place ahead of stan and deptrac. See
    // tools/clean-probes.php. The two cases at the end of this file assert that
    // wiring and that its registry names every probe the suite can plant.
    hostRemoveProbe(hostStanProbePath());
    hostRemoveProbe(hostDeptracProbePath());
    hostRemoveProbe(hostDemoProbePath());
});

afterEach(function () {
    // The safety net behind each test's own finally, for the ordinary case where
    // the process does finish.
    hostRemoveProbe(hostStanProbePath());
    hostRemoveProbe(hostDeptracProbePath());
    hostRemoveProbe(hostDemoProbePath());

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

/*
 * QA BUG-1 and BUG-2. The two cases below are the ones that actually protect the
 * gate; the beforeEach above only protects the next test run. See the comment on
 * it, and tools/clean-probes.php.
 */

it('removes every planted probe before the gate reaches an analysing step', function () {
    // All three planted at once, as a killed run would leave them.
    File::put(hostStanProbePath(), "<?php\n\nfunction hostStanProbeUntyped(\$value)\n{\n    return \$value;\n}\n");
    File::put(hostDeptracProbePath(), "<?php\n\nnamespace Tests\\Feature;\n\nclass HostDeptracProbe {}\n");
    File::put(hostDemoProbePath(), "<?php\n\nfunction hostDemoProbeUntyped(\$value)\n{\n    return \$value;\n}\n");

    expect(File::exists(hostStanProbePath()))->toBeTrue()
        ->and(File::exists(hostDeptracProbePath()))->toBeTrue()
        ->and(File::exists(hostDemoProbePath()))->toBeTrue();

    // The real wiring, run the way the gate runs it.
    $cleaned = Process::path(base_path())->run(['composer', 'run', 'probes:clean']);

    expect($cleaned->exitCode())->toBe(0)
        ->and(File::exists(hostStanProbePath()))->toBeFalse()
        ->and(File::exists(hostDeptracProbePath()))->toBeFalse()
        ->and(File::exists(hostDemoProbePath()))->toBeFalse();

    /** @var array{scripts?: array<string, mixed>} $manifest */
    $manifest = (array) json_decode((string) File::get(base_path('composer.json')), true);

    /** @var array<string, mixed> $scripts */
    $scripts = (array) ($manifest['scripts'] ?? []);

    /** @var list<string> $check */
    $check = (array) ($scripts['check'] ?? []);

    // Position is the whole defect. check runs lint, stan, deptrac, test, so a
    // cleanup anywhere after the first entry is a cleanup that a surviving probe
    // has already pre-empted - which is exactly what BUG-1 was.
    expect($check[0] ?? null)->toBe('@probes:clean')
        ->and($scripts)->toHaveKey('probes:clean')
        ->and(array_search('@stan', $check, true))->toBeGreaterThan(0);
});

it('names every probe the suite can plant in the gate cleanup registry', function () {
    // Without this, adding a probe and forgetting to register it reintroduces
    // BUG-2 silently: the new probe would simply never be cleaned.
    $registered = hostRegisteredProbePaths();

    $planted = [hostStanProbePath(), hostDeptracProbePath(), hostDemoProbePath()];
    sort($planted);

    expect($registered)->toBe($planted);

    // And the demo probe is the one QaTestCodeAnalysisCoverageTest really uses,
    // not a path this file invented. That file declares the function at
    // collection time, so it is available here in a full run and in a filtered
    // one alike; if it ever stops being, this assertion is skipped rather than
    // wrong, and the toBe() above still pins the registry.
    if (function_exists('qaStanProbePath')) {
        expect($registered)->toContain(qaStanProbePath());
    }
});
