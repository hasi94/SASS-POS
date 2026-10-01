<?php

use Illuminate\Support\Facades\File;

/*
 * T-004, QA BUG-3.
 *
 * The two fixture-teardown helpers and the one property that matters about them:
 * a delete that cannot succeed must never be able to skip the step that puts
 * something back.
 *
 * The failure condition is reproduced exactly rather than mocked. Measured on
 * this platform: with a file handle open inside a directory, unlink() on that
 * file SUCCEEDS but rmdir() on the directory FAILS - so the files go and the
 * directory stays, which is precisely the shape that made a cold gate run fail in
 * ServiceRegistryManifestTrustTest and made `svc:make` refuse the name
 * afterwards. No child process, no sleep, milliseconds.
 *
 * Every case closes its handle and force-removes its fixture in a finally: a
 * leftover undeletable directory under storage/app would poison unrelated tests
 * for the rest of the run.
 */

/**
 * A fixture directory holding one file, with a handle open on that file so the
 * directory cannot be removed.
 *
 * @return array{0: string, 1: resource}
 */
function teardownUndeletableDirectory(string $name): array
{
    $root = storage_path('app'.DIRECTORY_SEPARATOR.$name);

    File::ensureDirectoryExists($root);
    File::put($root.DIRECTORY_SEPARATOR.'held.txt', 'x');

    $handle = fopen($root.DIRECTORY_SEPARATOR.'held.txt', 'r');

    if ($handle === false) {
        throw new RuntimeException('could not open the fixture file to hold it');
    }

    return [$root, $handle];
}

/**
 * Close the handle and remove the directory whatever happened.
 *
 * @param  resource  $handle
 */
function teardownRelease(string $root, $handle): void
{
    if (is_resource($handle)) {
        fclose($handle);
    }

    clearstatcache();

    File::deleteDirectory($root);

    clearstatcache();
}

it('removes a populated fixture directory and reports that it is gone', function () {
    $root = storage_path('app'.DIRECTORY_SEPARATOR.'teardown-happy');

    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'nested');
    File::put($root.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'a.txt', 'a');

    expect(File::isDirectory($root))->toBeTrue();

    expect(tryDeleteFixtureDirectory($root))->toBeTrue()
        ->and(File::isDirectory($root))->toBeFalse();

    // Both variants are safe on a directory that is already absent, which every
    // afterEach relies on.
    expect(tryDeleteFixtureDirectory($root))->toBeTrue();

    deleteFixtureDirectory($root);

    expect(File::isDirectory($root))->toBeFalse();
});

it('reports failure without throwing when the directory cannot be removed', function () {
    [$root, $handle] = teardownUndeletableDirectory('teardown-try');

    try {
        $started = microtime(true);

        // The real condition: the file is removed, the directory survives.
        expect(tryDeleteFixtureDirectory($root))->toBeFalse()
            ->and(File::isDirectory($root))->toBeTrue();

        $elapsed = microtime(true) - $started;

        /*
         * And it really backed off between attempts. This is the assertion that
         * would have caught the first attempt at this fix: three immediate passes
         * complete in microseconds, so they cannot outlast a handle that another
         * part of the process is about to release - and the trustfixture flake
         * recurred on a full run because of exactly that. A lower bound only, so
         * a slow machine can never make it flaky in the other direction.
         */
        expect($elapsed)->toBeGreaterThan(
            0.3,
            'tryDeleteFixtureDirectory() gave up in '.round($elapsed, 3).'s, which means it is retrying in a '.
            'tight loop again rather than waiting for whatever holds the directory to let go'
        );
    } finally {
        teardownRelease($root, $handle);
    }

    expect(File::isDirectory($root))->toBeFalse();
});

it('throws and names the directory when a plain teardown cannot remove it', function () {
    [$root, $handle] = teardownUndeletableDirectory('teardown-strict');

    try {
        // The strict variant is what every ordinary afterEach uses: a leftover
        // directory has to be loud, because the next test to generate that name
        // would otherwise fail for a reason that has nothing to do with it.
        expect(fn () => deleteFixtureDirectory($root))
            ->toThrow(RuntimeException::class, $root);
    } finally {
        teardownRelease($root, $handle);
    }

    expect(File::isDirectory($root))->toBeFalse();
});

it('still restores a moved directory when the delete before it fails', function () {
    /*
     * QA BUG-3, as a regression test rather than a promise.
     *
     * The shape SvcMakeDemoRegenerationTest uses: something was moved aside into
     * a backup that now holds the ONLY copy of committed content, and the
     * teardown has to remove a regenerated tree before moving the backup home.
     * With the throwing helper first, one delete failure skipped the restore and
     * the safety net then deleted the backup - losing both copies. The teardown
     * must therefore survive the failure and leave the backup intact.
     */
    [$regenerated, $handle] = teardownUndeletableDirectory('teardown-regenerated');
    $backup = storage_path('app'.DIRECTORY_SEPARATOR.'teardown-backup');

    File::ensureDirectoryExists($backup);
    File::put($backup.DIRECTORY_SEPARATOR.'committed.txt', 'the only copy');

    try {
        $restored = false;

        // Exactly the teardown shape, run against a delete that cannot succeed.
        if (tryDeleteFixtureDirectory($regenerated)) {
            $restored = true;
        }

        expect($restored)->toBeFalse('the delete was expected to fail in this fixture');

        // The point: control reached here at all, and the only copy is untouched.
        expect(File::isDirectory($backup))->toBeTrue()
            ->and(File::get($backup.DIRECTORY_SEPARATOR.'committed.txt'))->toBe('the only copy');

        // And once the obstruction is gone the same teardown completes: delete,
        // then restore, in that order, with the copy surviving the whole way.
        fclose($handle);
        clearstatcache();

        expect(tryDeleteFixtureDirectory($regenerated))->toBeTrue();

        File::moveDirectory($backup, $regenerated);

        expect(File::get($regenerated.DIRECTORY_SEPARATOR.'committed.txt'))->toBe('the only copy')
            ->and(File::isDirectory($backup))->toBeFalse();
    } finally {
        // The handle is closed above on the happy path, but an assertion failing
        // before that point would otherwise leave an undeletable directory under
        // storage/app and poison every later test that generates a fixture.
        teardownRelease($regenerated, $handle);

        File::deleteDirectory($backup);

        clearstatcache();
    }

    expect(File::isDirectory($regenerated))->toBeFalse()
        ->and(File::isDirectory($backup))->toBeFalse();
});
