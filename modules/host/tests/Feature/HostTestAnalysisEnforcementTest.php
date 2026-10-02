<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

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
 * Read out of that file's source rather than written out here a second time.
 * Review W-2: this used to hold its own copy of the path, plus a
 * function_exists() guard around the assertion that the two agreed - so the
 * check ran in a full run, was skipped in a single-file one, and the case
 * reported a different number of assertions depending on what else had been
 * collected, on the one task whose contract is that counts do not move. A
 * planter is only *declared* when its file is collected; its source is on disk
 * in every run mode.
 *
 * Deliberately not named *ProbePath(): that suffix is the discovery key below,
 * and this function computes nothing - it re-exports another file's planter,
 * which discovery already finds at the source. Same reason
 * toolchainSpawnTokens() keeps its tokens in fragments: a file that is itself
 * swept must not plant a false positive in its own sweep.
 */
function hostDemoProbeTarget(): string
{
    return hostProbeTargetFromSource(
        (string) File::get(base_path('tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'QaTestCodeAnalysisCoverageTest.php')),
        'qaStanProbePath'
    );
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

/**
 * Every probe-planting function declared in a *Test.php file under
 * modules/*\/tests or packages/*\/tests, as function name => declaring file.
 *
 * Not every planter the suite *could* declare: packageTestFiles() walks
 * *Test.php only, so one declared in a Helpers.php or a fixtures class is not
 * discovered (QA BUG-4, measured), and it skips the throwaway packages
 * qaFixturePackageNames() lists, so a planter inside one of those is not
 * discovered either. Widening the walk would be green on today's tree - no
 * planter exists in either place - so the scope is stated here and in
 * tools/clean-probes.php rather than guessed at.
 *
 * The discovered half of the registry check. Without it both sides of that
 * comparison are hand-kept literals in two files and the case cannot fail on
 * the thing it exists to catch: QA BUG-3 measured a probe nobody registered
 * passing the case and then killing `composer stan`, and the reviewer then
 * found three such probes already in the suite.
 *
 * The key is the *ProbePath() suffix every planter in the suite already uses.
 * QA proposed collecting string literals matching ['"]...Probe.php['"]
 * instead; measured on this tree, that predicate finds nothing at all - the
 * paths are assembled from DIRECTORY_SEPARATOR fragments, and two of the
 * planted files are named *ProbeTest.php, so the anchor misses both sides.
 *
 * The enumeration is packageTestFiles() in tests/Helpers.php, the same walk
 * filesSpawningChildProcesses() uses, without its spawn filter:
 * ToolchainGroupCoverageTest declares a planter and deliberately spawns
 * nothing, so filtering by spawning would hide it - and it is the one planter
 * that must be *excluded by the rule* rather than by an exemption, because its
 * target is under storage/ and in no registry.
 *
 * @return array<string, string>
 */
function hostDiscoveredProbePlanters(): array
{
    $planters = [];

    foreach (packageTestFiles() as $file) {
        $found = preg_match_all('/function\s+(\w*ProbePath)\s*\(/', (string) file_get_contents($file), $matches);

        if ($found === false || $found === 0) {
            continue;
        }

        /** @var list<string> $names */
        $names = $matches[1];

        foreach ($names as $name) {
            $planters[$name] = $file;
        }
    }

    ksort($planters);

    return $planters;
}

/**
 * The absolute path a probe planter returns, evaluated from its own source.
 *
 * Takes the source rather than a filename so it can be proven on synthetic
 * input, the same reason toolchainExpectedGroupFiles() takes its spawner list as
 * an argument. Reading the source rather than calling the function is what makes
 * this work in every run mode: a planter declared in another file exists only
 * when that file is collected.
 *
 * Every planter in the suite returns one of three shapes - a base_path(),
 * storage_path() or modulesPath('<module>') root followed by
 * DIRECTORY_SEPARATOR-joined literals. An unreadable shape throws: a planter
 * this cannot evaluate is a planter the registry check would otherwise silently
 * stop covering, which is the defect class the whole check exists to close.
 */
function hostProbeTargetFromSource(string $source, string $function): string
{
    $declaration = '/function\s+'.preg_quote($function, '/').'\s*\([^)]*\)\s*:\s*string\s*\{\s*return\s+([^;]+);/';

    if (preg_match($declaration, $source, $matches) !== 1) {
        throw new RuntimeException(
            'could not find a single-return declaration of '.$function.'() to read a probe path out of - '.
            'either it is not one, or a comment containing the signature was discovered as one, because '.
            'this reader does not strip comments. Rename the mention, or keep the planter readable from '.
            'source: otherwise the gate cleanup registry stops being checked against it.'
        );
    }

    $expression = trim($matches[1]);

    preg_match_all("/'([^']*)'/", $expression, $found);

    /** @var list<string> $segments */
    $segments = $found[1];

    if (str_starts_with($expression, 'base_path(')) {
        $root = base_path();
    } elseif (str_starts_with($expression, 'storage_path(')) {
        $root = storage_path();
    } elseif (preg_match("/^modulesPath\('([^']+)'\)/", $expression, $module) === 1) {
        $root = modulesPath($module[1]);

        // The module name is the root's own argument, not a path segment.
        array_shift($segments);
    } else {
        throw new RuntimeException(
            $function.'() returns a path shape this reader does not understand: '.$expression.'. Use a '.
            'base_path(), storage_path() or modulesPath() root with DIRECTORY_SEPARATOR-joined literals, or '.
            'teach this function the new shape - do not leave it unreadable, because then nothing checks '.
            'that the probe is registered in tools/clean-probes.php.'
        );
    }

    return $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
}

/**
 * Every directory or file the three *collectors* look inside: PHPStan's
 * `paths`, Deptrac's `paths` and phpunit.xml's suite directories, resolved to
 * absolute paths. Not everything the gate looks inside - see below.
 *
 * A planted probe only matters where one of those three finds it - PHPStan and
 * Deptrac turn it into a failing analysis, phpunit collects it as a test - which
 * is what makes the storage/ probe out of scope by rule rather than by name.
 *
 * `@lint` is the fourth pair of eyes and is deliberately not in this list.
 * `pint --test .. ../../packages` sweeps both trees wholesale, so an
 * unformatted probe anywhere in them fails the gate one step earlier than
 * PHPStan would - T-003 lost a run to exactly that. It is left out because
 * Pint, given a directory, excludes storage/ (measured: `pint --test ..
 * ../../packages -v` lists no storage file), so the only probe outside these
 * three registries is outside Pint's reach as well, and adding it would widen
 * the rule with no path to catch today.
 *
 * phpunit.xml's <exclude> entries are deliberately not subtracted: ../host/tests
 * is excluded from the Modules suite only because the Host suite already
 * collects it, so honouring the exclude would under-report what the gate sees.
 *
 * phpstan.neon.dist is read with the YAML parser rather than with
 * HostTestAnalysisCoverageTest's line parser, which exists because its own
 * assertions are about the file's *text* (it mutates that text for a negative
 * control). This needs only the resolved entries, and the Neon this project
 * ships is inside the YAML subset. A parse that stopped working would throw
 * here, and the positive control in the case below fails if it ever returns
 * nothing useful.
 *
 * @return list<string>
 */
function hostAnalysedOrCollectedRoots(): array
{
    /** @var array<string, mixed> $neon */
    $neon = (array) Yaml::parseFile(base_path('phpstan.neon.dist'));

    /** @var array<string, mixed> $parameters */
    $parameters = (array) ($neon['parameters'] ?? []);

    /** @var list<string> $entries */
    $entries = (array) ($parameters['paths'] ?? []);

    /** @var array<string, mixed> $parsed */
    $parsed = (array) Yaml::parseFile(base_path('deptrac.yaml'));

    /** @var array<string, mixed> $deptrac */
    $deptrac = (array) ($parsed['deptrac'] ?? []);

    /** @var list<string> $deptracPaths */
    $deptracPaths = (array) ($deptrac['paths'] ?? []);

    $entries = array_merge($entries, $deptracPaths);

    $phpunit = simplexml_load_string((string) File::get(base_path('phpunit.xml')));

    if ($phpunit === false) {
        throw new RuntimeException('could not parse phpunit.xml, so the suite directories cannot be read');
    }

    foreach ($phpunit->xpath('//testsuite/directory') ?: [] as $directory) {
        $entries[] = (string) $directory;
    }

    $roots = [];

    foreach ($entries as $entry) {
        // glob() expands phpunit.xml's wildcards the way PHPUnit does, and
        // realpath() flattens the ../ segments PHPStan and Deptrac use, so a
        // path can be compared with a planter's absolute target.
        foreach (glob(base_path(str_replace('/', DIRECTORY_SEPARATOR, $entry))) ?: [] as $match) {
            $resolved = realpath($match);

            if ($resolved !== false) {
                $roots[] = $resolved;
            }
        }
    }

    sort($roots);

    return array_values(array_unique($roots));
}

/**
 * Whether a path falls inside one of those roots.
 *
 * @param  list<string>  $roots
 */
function hostPathIsAnalysedOrCollected(string $path, array $roots): bool
{
    foreach ($roots as $root) {
        if ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return true;
        }
    }

    return false;
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
    // wiring, and that its registry covers every probe the suite is discovered
    // to plant inside a path PHPStan, Deptrac or phpunit looks at.
    hostRemoveProbe(hostStanProbePath());
    hostRemoveProbe(hostDeptracProbePath());
    hostRemoveProbe(hostDemoProbeTarget());
});

afterEach(function () {
    // The safety net behind each test's own finally, for the ordinary case where
    // the process does finish.
    hostRemoveProbe(hostStanProbePath());
    hostRemoveProbe(hostDeptracProbePath());
    hostRemoveProbe(hostDemoProbeTarget());

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
 * QA BUG-1 and BUG-2, and review R-1/R-2. The two cases below are the ones that
 * actually protect the gate; the beforeEach above only protects the next test
 * run. See the comment on it, and tools/clean-probes.php.
 *
 * What they guarantee, stated no wider than it is: the first plants every path
 * the registry names and proves the gate's first step removes all of them; the
 * second compares that registry against a DISCOVERED set of probe planters -
 * every *ProbePath() declared in a *Test.php file, with the path each returns
 * evaluated from its source - and fails on any discovered path inside a PHPStan
 * path, a Deptrac path or a phpunit suite directory that the registry does not
 * name. A probe outside all three needs no entry and gets none; a planter
 * outside a *Test.php file is not discovered at all, which is BUG-4's residual
 * and is written down on hostDiscoveredProbePlanters().
 *
 * Neither side is a hand-kept literal any more. That is the correction: the
 * earlier version compared two hand-kept lists and claimed completeness in
 * three places, and it was false in all three - the registry named three probes
 * while the suite planted eight.
 */

it('removes every planted probe before the gate reaches an analysing step', function () {
    // Every path the registry names, planted at once, as a killed run would
    // leave them - taken from the registry rather than from a list of three
    // written out here, which is what let this case claim "every planted probe"
    // while proving it for half of them (review R-1). The content does not
    // matter to the removal, so it is a marker rather than a copy of each
    // probe's deliberate defect; the cases above are what prove the defects are
    // real.
    $registered = hostRegisteredProbePaths();

    $created = [];

    foreach ($registered as $path) {
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            $created[] = $directory;
        }

        File::ensureDirectoryExists($directory);
        File::put($path, "<?php\n\n// Planted to prove the gate cleanup removes it.\n");
    }

    try {
        $unplanted = array_values(array_filter(
            $registered,
            static fn (string $path): bool => ! File::exists($path)
        ));

        expect($registered)->not->toBe([])
            ->and($unplanted)->toBe([], 'these registered probe paths could not be planted: '.implode(', ', $unplanted));

        // The real wiring, run the way the gate runs it.
        $cleaned = Process::path(base_path())->run(['composer', 'run', 'probes:clean']);

        $survivors = array_values(array_filter(
            $registered,
            static fn (string $path): bool => File::exists($path)
        ));

        expect($cleaned->exitCode())->toBe(0)
            ->and($survivors)->toBe([], 'composer run probes:clean left these behind: '.implode(', ', $survivors));
    } finally {
        foreach ($registered as $path) {
            File::delete($path);
        }

        // Only ever removes a directory this case created and left empty.
        foreach ($created as $directory) {
            @rmdir($directory);
        }
    }

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
    /*
     * The registry is compared against a DISCOVERED set of probe planters, not
     * against a second list written out here. An earlier version of this case
     * compared two hand-kept literals and its comment claimed that adding a
     * probe without registering it would fail the gate; it did not, measured
     * twice - QA planted an unregistered probe and watched this case pass and
     * `composer stan` die (BUG-3), and the reviewer then found three probes the
     * suite already plants that the registry did not name (R-1).
     */
    $registered = hostRegisteredProbePaths();

    /*
     * A positive control on the reader, before anything depends on it, on
     * synthetic source covering all three shapes the suite's planters use. The
     * expectations are built by calling the path helpers rather than by writing
     * the answers out, so this is an evaluation check and not a restatement.
     *
     * The synthetic functions are deliberately NOT named *ProbePath(): that
     * suffix is the discovery key, and a file that is itself swept must not
     * plant false positives in its own sweep - the same trap toolchainSpawnTokens()
     * keeps its tokens in fragments for. Here it would be worse than noise: the
     * first of these targets sits inside the analysed tests directory, so it
     * would be discovered, found unregistered, and this case would fail on a
     * file that never exists.
     */
    $synthetic = <<<'PHP'
        function syntheticBaseTarget(): string
        {
            return base_path('tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'SyntheticProbe.php');
        }

        function syntheticModuleTarget(): string
        {
            return modulesPath('demo').DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'SyntheticProbeTest.php';
        }

        function syntheticStorageTarget(): string
        {
            return storage_path('app'.DIRECTORY_SEPARATOR.'SyntheticProbeTest.php');
        }
        PHP;

    expect(hostProbeTargetFromSource($synthetic, 'syntheticBaseTarget'))
        ->toBe(base_path('tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'SyntheticProbe.php'))
        ->and(hostProbeTargetFromSource($synthetic, 'syntheticModuleTarget'))
        ->toBe(modulesPath('demo').DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'SyntheticProbeTest.php')
        ->and(hostProbeTargetFromSource($synthetic, 'syntheticStorageTarget'))
        ->toBe(storage_path('app'.DIRECTORY_SEPARATOR.'SyntheticProbeTest.php'));

    $targets = [];
    $compared = [];
    $mismatches = [];

    foreach (hostDiscoveredProbePlanters() as $name => $file) {
        $targets[$name] = hostProbeTargetFromSource((string) file_get_contents($file), $name);

        /*
         * Where the planter is also declared - all of them are in a full run,
         * this file's two in any run - the path read from source is checked
         * against the path the function returns. The comparison set moves with
         * collection; the number of assertions does not, which is the whole
         * point of review W-2.
         */
        if (function_exists($name)) {
            $compared[] = $name;

            if ($name() !== $targets[$name]) {
                $mismatches[$name] = $name().' != '.$targets[$name];
            }
        }
    }

    expect($compared)->toContain('hostStanProbePath')
        ->and($compared)->toContain('hostDeptracProbePath')
        ->and($mismatches)->toBe(
            [],
            'the path read from source disagrees with the path the planter returns: '.implode('; ', $mismatches)
        );

    $roots = hostAnalysedOrCollectedRoots();

    // Positive and negative control on the scope rule, so "in scope" is a
    // decision and not a coincidence. Neither path exists: the rule is about
    // where a path lies, which is exactly why ToolchainGroupCoverageTest's
    // storage/ probe needs no exemption.
    expect(hostPathIsAnalysedOrCollected(
        modulesPath('demo').DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'SyntheticProbeTest.php',
        $roots
    ))->toBeTrue('a module test directory is analysed by both tools and collected by phpunit')
        ->and(hostPathIsAnalysedOrCollected(
            storage_path('app'.DIRECTORY_SEPARATOR.'synthetic'.DIRECTORY_SEPARATOR.'ProbeTest.php'),
            $roots
        ))->toBeFalse('nothing analyses or collects storage/app, so a probe there needs no registry entry');

    $inScope = array_values(array_filter(
        $targets,
        static fn (string $path): bool => hostPathIsAnalysedOrCollected($path, $roots)
    ));

    sort($inScope);

    $unregistered = array_values(array_diff($inScope, $registered));
    $stale = array_values(array_diff($registered, $targets));

    expect($inScope)->toContain(hostStanProbePath())
        ->and($unregistered)->toBe(
            [],
            'these probes are planted inside a path PHPStan, Deptrac or phpunit looks at, and '.
            'tools/clean-probes.php does not name them, so a killed run leaves them to turn the next gate '.
            'red at its analysing step: '.implode(', ', $unregistered)
        )
        ->and($stale)->toBe(
            [],
            'tools/clean-probes.php names paths no planter in the suite produces, so the registry is '.
            'drifting from what is really planted: '.implode(', ', $stale)
        );
});
