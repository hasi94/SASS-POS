<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Remove planted probes, before anything analyses the tree
|--------------------------------------------------------------------------
|
| Several test files plant a file carrying a deliberate defect, to prove the gate
| can actually fail: HostTestAnalysisEnforcementTest plants a level 6 defect and
| a boundary violation inside the host's own tests, DeptracBoundaryEnforcementTest
| and QaTestCodeAnalysisCoverageTest plant a boundary violation and a level 6
| defect inside the demo module's tests, and HostTestSuiteCollectionTest and
| QaHostSuiteCollectionProofTest plant collectable test files under
| tests/Integration. Each deletes its own probe in a finally and in afterEach,
| and removes it again in beforeEach so a leftover cannot poison the next run.
|
| None of that helps the gate. `composer check` runs lint, then stan, then
| deptrac, then test - so a surviving probe aborts the run at the second or
| third step, BEFORE any test hook has executed. The before-each cleanup is
| therefore dead code in exactly the case it was written for: a run killed
| part-way (a Composer process timeout, Ctrl-C, a CI step limit) runs no PHP
| teardown at all, and the next `composer check` then dies in static analysis or
| the boundary check, naming a file that reads like real code. That was measured,
| twice.
|
| This script is the only place ahead of those steps, so it is the first entry of
| the `check` script. It is deliberately dependency-free and does not boot
| Laravel: it has to work when the application cannot.
|
| The list below is the single source of truth, and it is not trusted to be
| complete: HostTestAnalysisEnforcementTest discovers the probe-planting
| functions - every *ProbePath() declared in a *Test.php file under
| modules/*\/tests or packages/*\/tests - evaluates the path each one returns,
| and fails if any of those paths lies inside a PHPStan path, a Deptrac path or a
| phpunit suite directory without appearing here. So a *test file* that plants a
| probe inside an analysed or collected path cannot leave it unregistered.
|
| Two residuals, stated because the sentence above is only true with them. A
| planter declared somewhere else - a Helpers.php, a Pest.php, a fixtures class,
| anything not matching *Test.php - is NOT discovered: QA planted one and the
| guard stayed green (BUG-4), measured again here before this paragraph was
| written. Widening the walk to every .php would close it and would be green on
| today's tree, where no such planter exists, so it is deferred rather than
| guessed at. And a file dropped into the tree by hand declares no planter at
| all; it is nobody's probe, and the gate dying on it in static analysis is the
| right answer rather than a hole.
|
| That sentence used to claim the registry was complete while both sides of the
| comparison were hand-kept literals; it named three probes and the suite planted
| eight. The discovered half is what makes the claim checkable, and it went red
| on three real paths the first time it ran.
|
| A probe outside all three registries needs no entry, and
| ToolchainGroupCoverageTest's storage/app probe is one: nothing analyses or
| collects storage/app, so the rule excludes it rather than an exemption list.
|
*/

// Paths relative to the modules/ directory, so no entry needs a ".." segment.
$probes = [
    'host/tests/Feature/HostStanProbe.php',
    'host/tests/Feature/HostDeptracProbe.php',
    'host/tests/Integration/HostSuiteProbeTest.php',
    'host/tests/Integration/QaCollectionProbeTest.php',
    'demo/tests/Feature/QaStanProbe.php',
    'demo/tests/Feature/DeptracProbeTest.php',
];

$modules = dirname(__DIR__, 2);

$absolute = array_map(
    static fn (string $probe): string => $modules.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $probe),
    $probes
);

// --list lets a test compare this registry against the probes it actually
// plants, without duplicating the list on the test side.
if (in_array('--list', array_slice($argv, 1), true)) {
    // Each line is marked, so a reader cannot mistake a PHP startup warning on
    // stdout for a probe path. This machine emits one ("Module openssl is
    // already loaded"), which a suffix-based filter silently mis-parsed.
    foreach ($absolute as $path) {
        echo 'probe ', $path, PHP_EOL;
    }

    exit(0);
}

$survivors = [];

foreach ($absolute as $path) {
    if (! file_exists($path)) {
        continue;
    }

    @unlink($path);

    clearstatcache(true, $path);

    if (file_exists($path)) {
        $survivors[] = $path;
    }
}

// Silent on success, so the gate's output stays readable.
if ($survivors !== []) {
    fwrite(
        STDERR,
        'Could not remove a planted probe. Each one carries a deliberate defect, so the gate '.
        'cannot report anything trustworthy until it is gone. Delete it by hand:'.PHP_EOL.
        '  '.implode(PHP_EOL.'  ', $survivors).PHP_EOL
    );

    exit(1);
}

exit(0);
