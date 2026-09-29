<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/*
 * T-002 FP-147 (T-001 review finding M-4).
 *
 * Generating a package writes five rendered files and six .gitkeep markers
 * (T-003 removed the per-module connection config file). If anything threw part
 * way through, the half-made package stayed on disk and the next attempt refused
 * with "already exists. Nothing was created or modified", which was then untrue
 * and left the name unusable. Generation is now all or nothing: any throwable
 * deletes services/<name>/ again.
 *
 * The failure is simulated by moving the last stub the command renders out of
 * the way, so the earlier files are genuinely written before the last one fails.
 * The stub is moved, never rewritten, and moved back in a finally block plus an
 * afterEach, so services/host is byte identical afterwards.
 */

function atomicStubPath(): string
{
    return base_path('stubs'.DIRECTORY_SEPARATOR.'service'.DIRECTORY_SEPARATOR.'PingRouteTest.php.stub');
}

function atomicStubBackupPath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'svcmake-atomic-backup'.DIRECTORY_SEPARATOR.'PingRouteTest.php.stub');
}

function atomicRestoreStub(): void
{
    $backup = atomicStubBackupPath();

    if (File::exists($backup)) {
        File::exists(atomicStubPath())
            ? File::delete($backup)
            : File::move($backup, atomicStubPath());
    }

    File::deleteDirectory(dirname($backup));
}

/**
 * Checksums of every tracked file in services/host, minus the stubs directory
 * this test deliberately borrows from.
 *
 * @return array<string, string>
 */
function atomicHostFingerprint(): array
{
    $files = Finder::create()
        ->in(base_path())
        ->exclude(['vendor', 'storage', 'node_modules', 'bootstrap/cache', 'stubs'])
        ->files()
        ->ignoreDotFiles(false)
        ->ignoreVCS(true);

    $fingerprint = [];

    foreach ($files as $file) {
        $fingerprint[$file->getRelativePathname()] = (string) md5_file($file->getPathname());
    }

    ksort($fingerprint);

    return $fingerprint;
}

afterEach(function () {
    atomicRestoreStub();
    File::deleteDirectory(servicesPath('atomicfixture'));
});

it('removes the partial package when generation fails part way through, and generates the same name cleanly afterwards', function () {
    $name = 'atomicfixture';
    $stub = atomicStubPath();
    $checksum = (string) md5_file($stub);

    $directoriesBefore = File::directories(servicesPath());
    $hostBefore = atomicHostFingerprint();

    File::ensureDirectoryExists(dirname(atomicStubBackupPath()));
    File::move($stub, atomicStubBackupPath());

    try {
        expect(File::exists($stub))->toBeFalse();

        $exit = Artisan::call('svc:make', ['name' => $name]);
        $output = Artisan::output();

        // AC-5: no partial services/<name>/ survives, and the failure says so.
        expect($exit)->not->toBe(0)
            ->and($output)->toContain('was removed')
            ->and(File::exists(servicesPath($name)))->toBeFalse()
            ->and(File::directories(servicesPath()))->toBe($directoriesBefore);

        // The rollback only ever deletes services/<name>/: T-001 AC-27 holds on
        // the failure path too, not just on the happy path.
        expect(atomicHostFingerprint())->toBe($hostBefore);
    } finally {
        atomicRestoreStub();
    }

    expect(md5_file($stub))->toBe($checksum);

    // AC-6: the name is genuinely free again, not stuck behind "already exists".
    expect(Artisan::call('svc:make', ['name' => $name]))->toBe(0);

    foreach ([
        'composer.json',
        // T-003 AC-19/AC-20 supersede T-001 AC-25's per-module connection file;
        // the config directory is now generated empty. See the note in
        // SvcMakeValidationTest.
        'config/.gitkeep',
        'routes/api.php',
        'src/AtomicfixtureServiceProvider.php',
        'src/Http/Controllers/PingController.php',
        'src/Consumers/.gitkeep',
        'src/Models/.gitkeep',
        'src/Services/.gitkeep',
        'src/Hooks/.gitkeep',
        'database/migrations/.gitkeep',
        'tests/Feature/PingRouteTest.php',
    ] as $relative) {
        expect(File::exists(servicesPath($name).DIRECTORY_SEPARATOR.$relative))->toBeTrue("missing {$relative}");
    }

    // "Complete" means the retried package actually passes, not merely that the
    // expected files exist. Filtered so the child run cannot re-enter this file.
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/pest'),
        '--filter='.$name,
        '--colors=never',
    ]);

    $childOutput = $result->output().$result->errorOutput();

    expect($childOutput)->not->toContain('No tests found')
        ->and($childOutput)->toMatch('/Tests:\s+\d+ passed/')
        ->and($result->exitCode())->toBe(0);
});

it('still refuses a genuinely existing package without deleting anything', function () {
    // The rollback must never reach a package the command did not create: the
    // already-exists refusal returns before the try block is entered.
    $demo = servicesPath('demo');
    $before = (string) md5_file($demo.DIRECTORY_SEPARATOR.'composer.json');

    expect(Artisan::call('svc:make', ['name' => 'demo']))->not->toBe(0)
        ->and(Artisan::output())->toContain('already exists')
        ->and(File::isDirectory($demo))->toBeTrue()
        ->and(md5_file($demo.DIRECTORY_SEPARATOR.'composer.json'))->toBe($before);
});
