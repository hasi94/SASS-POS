<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
 * T-002 FP-153 (AC-19).
 *
 * modules/demo is the committed reference package: it is what `module:make`
 * produces, and every claim the skeleton makes about generated modules is
 * anchored to it. T-001 proved the two match by hand, once. This makes it a
 * permanent regression test, so a stub edit that drifts from the committed tree
 * is caught by `composer test` instead of by a human diffing directories.
 *
 * This is the one test in the suite that manipulates real committed content, so
 * it moves modules/demo aside rather than overwriting it, and restores it in a
 * finally block: a failing assertion must never leave the working tree without
 * a modules/demo.
 */

function demoRegenBackupPath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'demo-regen-backup');
}

/**
 * Relative path => line-ending-normalised checksum for every file under a
 * directory.
 *
 * Normalised, not md5_file(), because the two sides are produced differently.
 * `module:make` always writes LF (ModuleMakeCommand::stub() strips CRLF), but the
 * committed tree is whatever Git checked out: `core.autocrlf` is true on the
 * development machine and only modules/host/ has a .gitattributes, so a fresh
 * clone - or a branch switch that re-materialises a demo file - gives CRLF on
 * disk. Hashing raw bytes would then fail the gate for someone who changed
 * nothing, which is exactly the false red FP-153 exists to prevent. The
 * content, not the checkout's line-ending policy, is what AC-19 is about.
 * QaTestCodeAnalysisCoverageTest normalises both sides the same way. A root
 * .gitattributes is the durable fix and is tracked separately (FP-156).
 *
 * @return array<string, string>
 */
function demoTreeFingerprint(string $root): array
{
    $files = Finder::create()
        ->in($root)
        ->files()
        ->ignoreDotFiles(false)
        ->ignoreVCS(true);

    $fingerprint = [];

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());
        $contents = (string) file_get_contents($file->getPathname());

        $fingerprint[$relative] = md5(str_replace("\r\n", "\n", $contents));
    }

    ksort($fingerprint);

    return $fingerprint;
}

/**
 * Relative path => raw byte checksum for every file under a directory.
 *
 * The opposite trade-off to demoTreeFingerprint() above, for the one place that
 * needs it: the afterEach guard that decides whether the backup is safe to
 * delete (QA BUG-4).
 *
 * That guard must only ever delete a copy it can prove is redundant, so it needs
 * the strictest comparison available, not the most forgiving one. Raw equality is
 * strictly stronger than normalised equality: anything it calls identical really
 * is identical, byte for byte. Where normalised hashing is right for the
 * assertion in the test body - two trees from different producers, where a line
 * ending must not fail the gate - it is wrong for a delete decision, because it
 * would call two trees interchangeable while one of them still differs on disk.
 *
 * @return array<string, string>
 */
function demoRawTreeFingerprint(string $root): array
{
    $files = Finder::create()
        ->in($root)
        ->files()
        ->ignoreDotFiles(false)
        ->ignoreVCS(true);

    $fingerprint = [];

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        $fingerprint[$relative] = (string) md5_file($file->getPathname());
    }

    ksort($fingerprint);

    return $fingerprint;
}

afterEach(function () {
    $demo = modulesPath('demo');
    $backup = demoRegenBackupPath();

    // Safety net only: the test body restores in a finally block. This catches
    // the case where the move succeeded but the process died before it.
    if (File::isDirectory($backup) && ! File::isDirectory($demo)) {
        File::moveDirectory($backup, $demo);
    }

    /*
     * QA BUG-3. A surviving backup is the ONLY copy of the committed module
     * whenever modules/demo holds something else, so it is never deleted blind.
     * The original version deleted it unconditionally, which - combined with a
     * delete failure in the finally below skipping the restore - could take the
     * committed module and its backup out together.
     *
     * QA BUG-4: raw bytes, deliberately, and NOT demoTreeFingerprint(), which
     * normalises line endings before hashing. A delete decision needs the
     * strictest comparison available; normalised equality would call two trees
     * interchangeable while one still differed on disk, and the thing being
     * deleted here may be the only pristine copy.
     *
     * Worth being precise about what the two sides are, because it is not the
     * self-comparison it looks like. This branch is reachable only when the
     * restore did NOT happen, so modules/demo holds the regenerated tree and the
     * backup holds the committed one - two different producers. Raw equality can
     * therefore report "different" for trees whose content matches, if their line
     * endings ever diverge again. That false negative keeps the backup, which is
     * the safe direction, and the next run's pre-flight assertion names it and
     * refuses to start. A false positive, which normalised hashing allowed, loses
     * the copy.
     */
    if (File::isDirectory($backup)
        && File::isDirectory($demo)
        && demoRawTreeFingerprint($demo) === demoRawTreeFingerprint($backup)) {
        tryDeleteFixtureDirectory($backup);
    }
});

it('regenerates the committed demo package byte for byte', function () {
    $demo = modulesPath('demo');
    $backup = demoRegenBackupPath();

    expect(File::isDirectory($demo))->toBeTrue()
        ->and(File::exists($backup))->toBeFalse('a leftover backup from an earlier crashed run would make this test unsafe');

    $committed = demoTreeFingerprint($demo);

    expect($committed)->not->toBe([]);

    File::ensureDirectoryExists(dirname($backup));
    File::moveDirectory($demo, $backup);

    try {
        expect(File::exists($demo))->toBeFalse()
            ->and(Artisan::call('module:make', ['name' => 'demo']))->toBe(0);

        $regenerated = demoTreeFingerprint($demo);

        expect(array_keys($regenerated))->toBe(
            array_keys($committed),
            'module:make demo no longer produces the same file list as the committed modules/demo'
        );

        $differing = [];

        foreach ($committed as $relative => $checksum) {
            if (($regenerated[$relative] ?? null) !== $checksum) {
                $differing[] = $relative;
            }
        }

        expect($differing)->toBe(
            [],
            'regenerating modules/demo differs from the committed tree in: '.implode(', ', $differing)
        );
    } finally {
        /*
         * QA BUG-3: the restore must not be reachable only when the delete
         * succeeds. tryDeleteFixtureDirectory() never throws, so nothing here can
         * jump over the two lines below and leave the committed module living
         * only in a backup that afterEach might then remove.
         *
         * If the regenerated tree cannot be removed, the backup stays exactly
         * where it is and the assertions after this block report a working tree
         * that needs a hand, rather than this teardown quietly making it worse.
         */
        if (tryDeleteFixtureDirectory($demo)) {
            File::moveDirectory($backup, $demo);
        }
    }

    // Recovery information, not an assertion: this keeps the case's assertion
    // count unchanged (T-004 AC-4) while still naming where the only copy of the
    // committed module is if the restore above could not run.
    if (! File::isDirectory($demo)) {
        throw new RuntimeException('modules/demo was not restored; its only copy is still at '.$backup);
    }

    // The committed tree is back, untouched, whatever happened above. The backup
    // assertion is also what reports an un-removable regenerated tree, now that
    // the teardown no longer throws on that path.
    expect(demoTreeFingerprint($demo))->toBe($committed)
        ->and(File::exists($backup))->toBeFalse();
});

it('distinguishes raw bytes from normalised content, which is what the teardown rests on', function () {
    /*
     * QA BUG-4. The afterEach guard above deletes the backup only when the two
     * trees are identical byte for byte, and the comment there says so. That is a
     * claim about which of these two functions it calls, so it is pinned here
     * rather than left to a reader to verify.
     *
     * Two trees with the same characters and different line endings: normalised
     * hashing must call them equal (which is why the assertion in the case above
     * uses it - a CRLF checkout must not fail the gate, T-002 R-1 / FP-153), and
     * raw hashing must call them different (which is why the delete decision uses
     * it). If either of those ever stops being true, the teardown's safety
     * argument has changed and this says so.
     */
    $lf = storage_path('app'.DIRECTORY_SEPARATOR.'demo-eol-lf');
    $crlf = storage_path('app'.DIRECTORY_SEPARATOR.'demo-eol-crlf');

    try {
        File::ensureDirectoryExists($lf);
        File::ensureDirectoryExists($crlf);

        File::put($lf.DIRECTORY_SEPARATOR.'a.txt', "one\ntwo\n");
        File::put($crlf.DIRECTORY_SEPARATOR.'a.txt', "one\r\ntwo\r\n");

        expect(demoTreeFingerprint($lf))->toBe(
            demoTreeFingerprint($crlf),
            'demoTreeFingerprint() stopped normalising line endings, so the assertion in the case above will '.
            'now fail the gate on a CRLF checkout - the false red FP-153 exists to prevent'
        );

        expect(demoRawTreeFingerprint($lf))->not->toBe(
            demoRawTreeFingerprint($crlf),
            'demoRawTreeFingerprint() stopped distinguishing line endings, so the afterEach guard can delete the '.
            'only copy of the committed module while it still differs from modules/demo on disk'
        );
    } finally {
        tryDeleteFixtureDirectory($lf);
        tryDeleteFixtureDirectory($crlf);
    }

    expect(File::isDirectory($lf))->toBeFalse()
        ->and(File::isDirectory($crlf))->toBeFalse();

    /*
     * And the teardown really calls the strict one. Without this, the two
     * assertions above would keep passing after someone swapped the guard back to
     * the normalising function - a test that pins a property nobody uses, which is
     * the shape of QA BUG-2.
     *
     * Checked as source because afterEach cannot be invoked directly, and the
     * branch only runs when a delete has already failed. Same precedent as
     * RouteCachingTest reading routes/web.php.
     */
    $source = (string) File::get(__FILE__);
    $teardown = substr($source, (int) strpos($source, 'afterEach(function ()'));
    $teardown = substr($teardown, 0, (int) strpos($teardown, "\nit("));

    expect(str_contains($teardown, 'demoRawTreeFingerprint($demo) === demoRawTreeFingerprint($backup)'))
        ->toBeTrue('the afterEach guard no longer compares raw bytes before deleting the backup (QA BUG-4)');

    expect(str_contains($teardown, 'demoTreeFingerprint($demo) === demoTreeFingerprint($backup)'))
        ->toBeFalse('the afterEach guard is comparing line-ending-normalised content before deleting the only copy of the committed module (QA BUG-4)');
});
