<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
 * T-002 FP-153 (AC-19).
 *
 * services/demo is the committed reference package: it is what `svc:make`
 * produces, and every claim the skeleton makes about generated services is
 * anchored to it. T-001 proved the two match by hand, once. This makes it a
 * permanent regression test, so a stub edit that drifts from the committed tree
 * is caught by `composer test` instead of by a human diffing directories.
 *
 * This is the one test in the suite that manipulates real committed content, so
 * it moves services/demo aside rather than overwriting it, and restores it in a
 * finally block: a failing assertion must never leave the working tree without
 * a services/demo.
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
 * `svc:make` always writes LF (SvcMakeCommand::stub() strips CRLF), but the
 * committed tree is whatever Git checked out: `core.autocrlf` is true on the
 * development machine and only services/host/ has a .gitattributes, so a fresh
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

afterEach(function () {
    // Safety net only: the test body restores in a finally block. This catches
    // the case where the move succeeded but the process died before it.
    if (File::isDirectory(demoRegenBackupPath()) && ! File::isDirectory(servicesPath('demo'))) {
        File::moveDirectory(demoRegenBackupPath(), servicesPath('demo'));
    }

    File::deleteDirectory(demoRegenBackupPath());
});

it('regenerates the committed demo package byte for byte', function () {
    $demo = servicesPath('demo');
    $backup = demoRegenBackupPath();

    expect(File::isDirectory($demo))->toBeTrue()
        ->and(File::exists($backup))->toBeFalse('a leftover backup from an earlier crashed run would make this test unsafe');

    $committed = demoTreeFingerprint($demo);

    expect($committed)->not->toBe([]);

    File::ensureDirectoryExists(dirname($backup));
    File::moveDirectory($demo, $backup);

    try {
        expect(File::exists($demo))->toBeFalse()
            ->and(Artisan::call('svc:make', ['name' => 'demo']))->toBe(0);

        $regenerated = demoTreeFingerprint($demo);

        expect(array_keys($regenerated))->toBe(
            array_keys($committed),
            'svc:make demo no longer produces the same file list as the committed services/demo'
        );

        $differing = [];

        foreach ($committed as $relative => $checksum) {
            if (($regenerated[$relative] ?? null) !== $checksum) {
                $differing[] = $relative;
            }
        }

        expect($differing)->toBe(
            [],
            'regenerating services/demo differs from the committed tree in: '.implode(', ', $differing)
        );
    } finally {
        File::deleteDirectory($demo);
        File::moveDirectory($backup, $demo);
    }

    // The committed tree is back, untouched, whatever happened above.
    expect(demoTreeFingerprint($demo))->toBe($committed)
        ->and(File::exists($backup))->toBeFalse();
});
