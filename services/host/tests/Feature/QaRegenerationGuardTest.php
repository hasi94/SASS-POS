<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/*
 * QA proof for T-002 AC-19.
 *
 * SvcMakeDemoRegenerationTest passes today, which only tells us it is green on
 * an unmodified tree - exactly the state in which the manual T-001 procedure it
 * replaces was also green. What AC-19 buys is that a stub edit which drifts
 * from the committed services/demo is caught by `composer test` and the
 * differing file is named. That can only be shown by actually drifting a stub,
 * so this mutates one, runs that test in a child process, and asserts it turns
 * red and prints the file name - then restores the stub and asserts it is green
 * again and services/demo is byte for byte what it was.
 */

function qaDriftStubPath(): string
{
    return base_path('stubs'.DIRECTORY_SEPARATOR.'service'.DIRECTORY_SEPARATOR.'routes.api.php.stub');
}

function qaDriftBackupPath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-stub-drift'.DIRECTORY_SEPARATOR.'routes.api.php.stub');
}

function qaRestoreDriftedStub(): void
{
    if (File::exists(qaDriftBackupPath())) {
        File::put(qaDriftStubPath(), File::get(qaDriftBackupPath()));
        File::delete(qaDriftBackupPath());
    }

    File::deleteDirectory(dirname(qaDriftBackupPath()));
}

/**
 * Relative path => content checksum for every file under a directory.
 *
 * Line endings are normalised before hashing (reviewer finding R-1). `svc:make`
 * always writes LF, but nothing guarantees the committed services/demo tree is
 * LF in a working copy: services/host/.gitattributes covers only
 * services/host/, there is no root or services/demo equivalent yet (FP-156),
 * and core.autocrlf is true on this machine. Hashing raw bytes therefore passes
 * only for as long as nobody re-checks-out services/demo, and goes red on a
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
 * a test which moves services/demo aside restored it untouched. Comparing a
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
 * Restore services/demo from the byte-for-byte backup this file takes before
 * rewriting its line endings. Safety net for a run that dies mid-test.
 */
function qaRestoreCrlfBackup(): void
{
    $backup = qaCrlfBackupPath();

    if (File::isDirectory($backup)) {
        foreach (Finder::create()->in($backup)->files()->ignoreDotFiles(false) as $file) {
            $target = servicesPath('demo').DIRECTORY_SEPARATOR.$file->getRelativePathname();

            File::ensureDirectoryExists(dirname($target));
            File::put($target, (string) file_get_contents($file->getPathname()));
        }
    }

    File::deleteDirectory($backup);
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
    qaRestoreDriftedStub();
    qaRestoreCrlfBackup();
});

it('survives a services/demo working tree checked out with CRLF line endings', function () {
    // Reviewer finding R-1, proven rather than argued. On a fresh clone with
    // core.autocrlf=true and no .gitattributes covering services/demo, git
    // writes CRLF into that tree while svc:make still generates LF. This
    // reproduces exactly that state and asserts the regeneration test still
    // passes, then puts the original bytes back.
    $demo = servicesPath('demo');
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

        File::deleteDirectory($aside);
        File::ensureDirectoryExists(dirname($aside));
        File::moveDirectory($demo, $aside);

        try {
            expect(Artisan::call('svc:make', ['name' => 'demo']))->toBe(0);

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
            File::deleteDirectory($demo);
            File::moveDirectory($aside, $demo);
            File::deleteDirectory($aside);
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
    $demoBefore = qaTreeFingerprint(servicesPath('demo'));
    $rawBefore = qaRawTreeFingerprint(servicesPath('demo'));

    expect($demoBefore)->toHaveKey('routes/api.php');

    File::ensureDirectoryExists(dirname(qaDriftBackupPath()));
    File::put(qaDriftBackupPath(), $original);

    try {
        // A realistic drift: someone edits the template and forgets that
        // services/demo is the committed copy of its output.
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
    // services/demo. Asserted byte for byte as well as by content, because
    // "restored" is a claim about the exact bytes and this comparison is of the
    // committed tree against itself, where raw hashing is the stronger check.
    expect(qaTreeFingerprint(servicesPath('demo')))->toBe($demoBefore)
        ->and(qaRawTreeFingerprint(servicesPath('demo')))->toBe($rawBefore);

    $green = qaRunRegenerationTest();

    expect($green->exitCode())->toBe(0)
        ->and($green->output())->toMatch('/Tests:\s+1 passed/')
        ->and(qaRawTreeFingerprint(servicesPath('demo')))->toBe($rawBefore)
        ->and(qaTreeFingerprint(servicesPath('demo')))->toBe($demoBefore)
        ->and(File::isDirectory(storage_path('app'.DIRECTORY_SEPARATOR.'demo-regen-backup')))->toBeFalse();
});
