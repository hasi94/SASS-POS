<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/*
 * QA proof for T-002 AC-19.
 *
 * ModuleMakeDemoRegenerationTest passes today, which only tells us it is green on
 * an unmodified tree - exactly the state in which the manual T-001 procedure it
 * replaces was also green. What AC-19 buys is that a stub edit which drifts
 * from the committed modules/demo is caught by `composer test` and the
 * differing file is named. That can only be shown by actually drifting a stub,
 * so this mutates one, runs that test in a child process, and asserts it turns
 * red and prints the file name - then restores the stub and asserts it is green
 * again and modules/demo is byte for byte what it was.
 */

function qaDriftStubPath(): string
{
    return base_path('stubs'.DIRECTORY_SEPARATOR.'module'.DIRECTORY_SEPARATOR.'routes.api.php.stub');
}

function qaDriftBackupPath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-stub-drift'.DIRECTORY_SEPARATOR.'routes.api.php.stub');
}

/**
 * Put the borrowed stub back and remove the directory it was parked in.
 *
 * Reviewer finding R-1, and the BUG-3 rule applied one level up: this runs from
 * the afterEach hook *before* qaRestoreCrlfBackup(), so it must not be able to
 * throw. It used to end with the throwing deleteFixtureDirectory(), which meant
 * one Windows delete failure here skipped the restore of the committed
 * modules/demo bytes below it - the same hazard as BUG-3, hidden because the
 * delete and the skipped restore live in different functions.
 *
 * The rule is kept local to the helper rather than to the hook's statement order:
 * a teardown helper reports its failure to its caller and never decides, by
 * throwing, which of its siblings get to run. The hook raises one report after
 * every restore has happened.
 *
 * @return bool whether the parking directory is gone
 */
function qaRestoreDriftedStub(): bool
{
    $backup = qaDriftBackupPath();

    /*
     * file_get_contents() rather than File::get(), which throws
     * FileNotFoundException if the file vanishes between the check and the read.
     * That throw would skip both the File::put() below it and the second restore
     * in the hook - the same property R-1 is about, reached through a different
     * statement. With this, nothing in this helper can throw at all, which is what
     * makes the hook's ordering safe by construction rather than by argument.
     */
    $contents = is_file($backup) ? @file_get_contents($backup) : false;

    if ($contents !== false) {
        File::put(qaDriftStubPath(), $contents);
        File::delete($backup);
    }

    return tryDeleteFixtureDirectory(dirname($backup));
}

/**
 * Relative path => content checksum for every file under a directory.
 *
 * Line endings are normalised before hashing (reviewer finding R-1). `module:make`
 * always writes LF, but nothing guarantees the committed modules/demo tree is
 * LF in a working copy: modules/host/.gitattributes covers only
 * modules/host/, there is no root or modules/demo equivalent yet (FP-156),
 * and core.autocrlf is true on this machine. Hashing raw bytes therefore passes
 * only for as long as nobody re-checks-out modules/demo, and goes red on a
 * fresh clone for someone who changed nothing.
 *
 * @return array<string, string>
 */
function qaTreeFingerprint(string $root): array
{
    $fingerprint = [];

    foreach (Finder::create()->in($root)->files()->ignoreDotFiles(false)->ignoreVCS(true) as $file) {
        $fingerprint[str_replace('\\', '/', $file->getRelativePathname())] = md5(
            str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname()))
        );
    }

    ksort($fingerprint);

    return $fingerprint;
}

/**
 * Relative path => raw byte checksum, no normalisation.
 *
 * Used only where the claim really is "these exact bytes came back", i.e. that
 * a test which moves modules/demo aside restored it untouched. Comparing a
 * tree against itself is safe to do byte for byte; comparing generated output
 * against a checked-out tree is not, which is what qaTreeFingerprint() is for.
 *
 * @return array<string, string>
 */
function qaRawTreeFingerprint(string $root): array
{
    $fingerprint = [];

    foreach (Finder::create()->in($root)->files()->ignoreDotFiles(false)->ignoreVCS(true) as $file) {
        $fingerprint[str_replace('\\', '/', $file->getRelativePathname())] = (string) md5_file($file->getPathname());
    }

    ksort($fingerprint);

    return $fingerprint;
}

function qaCrlfBackupPath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-crlf-backup');
}

/**
 * Restore modules/demo from the byte-for-byte backup this file takes before
 * rewriting its line endings. Safety net for a run that dies mid-test.
 *
 * Non-throwing for the same reason as qaRestoreDriftedStub() above (R-1): a
 * teardown helper reports, and the hook decides. Nothing restorative currently
 * follows this one at either call site, but relying on that would make the
 * property depend on statement order again, which is exactly what R-1 was.
 *
 * @return bool whether the backup directory is gone
 */
function qaRestoreCrlfBackup(): bool
{
    $backup = qaCrlfBackupPath();

    if (File::isDirectory($backup)) {
        foreach (Finder::create()->in($backup)->files()->ignoreDotFiles(false) as $file) {
            $target = modulesPath('demo').DIRECTORY_SEPARATOR.$file->getRelativePathname();

            File::ensureDirectoryExists(dirname($target));
            File::put($target, (string) file_get_contents($file->getPathname()));
        }
    }

    return tryDeleteFixtureDirectory($backup);
}

function qaRunRegenerationTest(): ProcessResult
{
    return Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/pest'),
        '--filter=regenerates the committed demo package byte for byte',
        '--colors=never',
    ]);
}

afterEach(function () {
    /*
     * Reviewer finding R-1. Every restore runs first, unconditionally; the report
     * comes last. Neither helper throws any more, so no delete failure in the
     * first one can skip the second - the second is the one that puts the
     * committed modules/demo bytes back from a byte-for-byte backup, and the
     * backup-creation loop in the first case sits outside any finally, so this
     * hook is the only thing that restores it on that path.
     *
     * A RuntimeException rather than an expect() call, the same choice
     * deleteFixtureDirectory() makes: this runs from a hook whose tests' assertion
     * counts must not change (AC-4), and a leftover fixture directory has to be
     * loud rather than counted.
     */
    $leftovers = [];

    if (! qaRestoreDriftedStub()) {
        $leftovers[] = dirname(qaDriftBackupPath());
    }

    if (! qaRestoreCrlfBackup()) {
        $leftovers[] = qaCrlfBackupPath();
    }

    if ($leftovers !== []) {
        throw new RuntimeException(
            'could not remove the fixture directories '.implode(', ', $leftovers).
            '; the restores above did run. Close any process holding a handle inside them and delete them by hand.'
        );
    }
});

it('survives a modules/demo working tree checked out with CRLF line endings', function () {
    // Reviewer finding R-1, proven rather than argued. On a fresh clone with
    // core.autocrlf=true and no .gitattributes covering modules/demo, git
    // writes CRLF into that tree while module:make still generates LF. This
    // reproduces exactly that state and asserts the regeneration test still
    // passes, then puts the original bytes back.
    $demo = modulesPath('demo');
    $backup = qaCrlfBackupPath();
    $raw = qaRawTreeFingerprint($demo);
    $normalised = qaTreeFingerprint($demo);

    expect(File::exists($backup))->toBeFalse('a leftover backup from a crashed run would make this unsafe');

    $rewritten = [];

    foreach (Finder::create()->in($demo)->files()->ignoreDotFiles(false)->ignoreVCS(true) as $file) {
        $relative = $file->getRelativePathname();
        $contents = (string) file_get_contents($file->getPathname());

        File::ensureDirectoryExists(dirname($backup.DIRECTORY_SEPARATOR.$relative));
        File::put($backup.DIRECTORY_SEPARATOR.$relative, $contents);

        $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $contents));

        if ($crlf !== $contents) {
            File::put($file->getPathname(), $crlf);
            $rewritten[] = str_replace('\\', '/', $relative);
        }
    }

    try {
        // The tree really is CRLF now: every rewritten file's raw hash changed
        // while its content hash did not.
        expect($rewritten)->not->toBe([]);

        $crlfRaw = qaRawTreeFingerprint($demo);

        foreach ($rewritten as $relative) {
            expect($crlfRaw[$relative])->not->toBe($raw[$relative], $relative);
        }

        expect(qaTreeFingerprint($demo))->toBe($normalised);

        // End to end: the real regeneration test, run against a CRLF working
        // tree in a child process.
        $result = qaRunRegenerationTest();

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
            ->and($result->output())->toMatch('/Tests:\s+1 passed/');

        // And the negative control, which is what makes the line above evidence
        // rather than a coincidence: perform that test's own comparison here,
        // with both hash functions, against a genuinely regenerated tree. Raw
        // hashing - the shape R-1 reported - calls every rewritten file a
        // difference; normalised hashing calls the trees identical.
        $aside = storage_path('app'.DIRECTORY_SEPARATOR.'qa-crlf-aside');

        deleteFixtureDirectory($aside);
        File::ensureDirectoryExists(dirname($aside));
        File::moveDirectory($demo, $aside);

        try {
            expect(Artisan::call('module:make', ['name' => 'demo']))->toBe(0);

            $generatedRaw = qaRawTreeFingerprint($demo);
            $generatedNormalised = qaTreeFingerprint($demo);

            expect(array_keys($generatedRaw))->toBe(array_keys($crlfRaw));

            $rawDifferences = array_keys(array_filter(
                $crlfRaw,
                fn (string $checksum, string $relative): bool => $generatedRaw[$relative] !== $checksum,
                ARRAY_FILTER_USE_BOTH
            ));

            expect($rawDifferences)->toBe($rewritten)
                ->and($generatedNormalised)->toBe($normalised);
        } finally {
            /*
             * QA BUG-3's rule, adopted here as well: a delete that precedes a
             * restore uses the non-throwing helper with the restore inside the
             * conditional, so one delete failure - the condition that helper
             * exists for - can never jump over putting modules/demo back.
             * $aside's own removal happens only inside that branch, because
             * deleting it while the restore has not happened would discard the
             * only copy of the tree this case rewrote.
             *
             * Reviewer finding R-1: that removal is now non-throwing too. It is a
             * cleanup-only delete, so the throwing variant was defensible - but a
             * throw here would leave qaRestoreCrlfBackup(), in the enclosing
             * finally, as the thing that still has to run, and relying on PHP
             * executing an outer finally while an exception escapes an inner one is
             * exactly the "depends on control flow rather than on the helper"
             * shape R-1 reported. A surviving $aside is instead reported by the
             * pre-flight deleteFixtureDirectory($aside) above on the next run,
             * which is strict and names the path.
             *
             * Milder here than in ModuleMakeDemoRegenerationTest, and deliberately
             * still fixed: qaRestoreCrlfBackup() below restores the committed
             * bytes from a byte-for-byte backup whatever happens, so the tree
             * recovers either way and the assertion after it is what reports a
             * working tree that needs a hand.
             */
            if (tryDeleteFixtureDirectory($demo)) {
                File::moveDirectory($aside, $demo);

                tryDeleteFixtureDirectory($aside);
            }
        }
    } finally {
        qaRestoreCrlfBackup();
    }

    // Byte for byte, not merely content-equal: the tree is exactly as found.
    expect(qaRawTreeFingerprint($demo))->toBe($raw)
        ->and(File::exists($backup))->toBeFalse();
});

it('turns red and names the file when a stub drifts from the committed demo package', function () {
    $stub = qaDriftStubPath();
    $original = File::get($stub);
    $checksum = (string) md5_file($stub);
    $demoBefore = qaTreeFingerprint(modulesPath('demo'));
    $rawBefore = qaRawTreeFingerprint(modulesPath('demo'));

    expect($demoBefore)->toHaveKey('routes/api.php');

    File::ensureDirectoryExists(dirname(qaDriftBackupPath()));
    File::put(qaDriftBackupPath(), $original);

    try {
        // A realistic drift: someone edits the template and forgets that
        // modules/demo is the committed copy of its output.
        File::put($stub, $original."\n// drifted by QA, restored below\n");

        expect(md5_file($stub))->not->toBe($checksum);

        $red = qaRunRegenerationTest();
        $output = $red->output().$red->errorOutput();

        expect($red->exitCode())->not->toBe(0)
            ->and($output)->toContain('1 failed')
            // AC-19: it must name the differing file, not merely fail.
            ->and($output)->toContain('routes/api.php');
    } finally {
        qaRestoreDriftedStub();
    }

    expect(md5_file($stub))->toBe($checksum);

    // The failing run still restored the committed tree, which is the other
    // half of AC-19: a red regeneration test must not cost the repository its
    // modules/demo. Asserted byte for byte as well as by content, because
    // "restored" is a claim about the exact bytes and this comparison is of the
    // committed tree against itself, where raw hashing is the stronger check.
    expect(qaTreeFingerprint(modulesPath('demo')))->toBe($demoBefore)
        ->and(qaRawTreeFingerprint(modulesPath('demo')))->toBe($rawBefore);

    $green = qaRunRegenerationTest();

    expect($green->exitCode())->toBe(0)
        ->and($green->output())->toMatch('/Tests:\s+1 passed/')
        ->and(qaRawTreeFingerprint(modulesPath('demo')))->toBe($rawBefore)
        ->and(qaTreeFingerprint(modulesPath('demo')))->toBe($demoBefore)
        ->and(File::isDirectory(storage_path('app'.DIRECTORY_SEPARATOR.'demo-regen-backup')))->toBeFalse();
});
