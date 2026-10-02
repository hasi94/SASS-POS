<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Remove planted probes, before anything analyses the tree
|--------------------------------------------------------------------------
|
| Two test files plant a file carrying a deliberate defect, to prove the gate
| can actually fail: HostTestAnalysisEnforcementTest plants a level 6 defect and
| a boundary violation inside the host's own tests, and
| QaTestCodeAnalysisCoverageTest plants a level 6 defect inside the demo
| module's tests. Each deletes its own probe in a finally and in afterEach, and
| each removes it again in beforeEach so a leftover cannot poison the next run.
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
| The list below is the single source of truth. HostTestAnalysisEnforcementTest
| asserts that it names every probe the suite can plant, so adding a probe
| without registering it here fails the gate rather than silently reintroducing
| the defect.
|
*/

// Paths relative to the modules/ directory, so no entry needs a ".." segment.
$probes = [
    'host/tests/Feature/HostStanProbe.php',
    'host/tests/Feature/HostDeptracProbe.php',
    'demo/tests/Feature/QaStanProbe.php',
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
