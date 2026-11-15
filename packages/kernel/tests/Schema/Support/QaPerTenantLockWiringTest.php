<?php

use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-49: is the lock ever taken by anything?
|--------------------------------------------------------------------------
|
| AC-49 is an [E] criterion and stays NOT VERIFIED behind ENGINE_HOLD (G-1):
| whether a second session is refused needs two real sessions, and nothing in
| this file claims to answer it. This is the one fact about AC-49 that needs no
| engine at all. PerTenantLock is a correct, small class with a unit test for
| its name and its refusal on a driver with no advisory lock - and it is
| **not called from anywhere**. A runner that never calls acquire() cannot
| report a tenant "not run because it is locked", with any engine, however the
| second session behaves.
|
| This is a wiring check, not a behavioural one, and it is deliberately the
| weakest kind of evidence: it passes the moment *something* calls acquire(),
| and it says nothing about whether the call is in the right place. It exists
| because the absence of a caller is invisible to every other test in the
| branch (the lock's own tests construct a connection and call it themselves).
|
| Comments and docblocks are stripped first, so a sentence that names the
| class does not count as a caller; the control feeds the same scan a snippet
| that does call it.
*/

/**
 * Whether the given PHP source calls PerTenantLock::acquire().
 */
function qaCallsLockAcquire(string $source): bool
{
    return preg_match('/PerTenantLock\s*::\s*acquire\s*\(/', SourceRules::codeOnly($source)) === 1;
}

it('can see a call to the lock and ignores a comment that only names it', function () {
    expect(qaCallsLockAcquire('<?php if (PerTenantLock::acquire($c, $t)) { run(); }'))->toBeTrue()
        ->and(qaCallsLockAcquire("<?php // PerTenantLock::acquire(\$c, \$t) is described here\nrun();"))->toBeFalse();
});

it('has a caller for PerTenantLock::acquire() in the kernel source outside the class itself', function () {
    $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'src';
    $callers = [];
    $scanned = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        if ($file->getFilename() === 'PerTenantLock.php') {
            continue;
        }

        $scanned++;

        if (qaCallsLockAcquire((string) file_get_contents($file->getPathname()))) {
            $callers[] = $file->getFilename();
        }
    }

    // The scan read the runner, not an empty directory.
    expect($scanned)->toBeGreaterThan(50)
        ->and($callers)->not->toBe([]);
});
