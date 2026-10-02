<?php

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\get;

/*
 * QA proofs for T-002 AC-5 and AC-6.
 *
 * SvcMakeAtomicGenerationTest fails generation on the last of the six stubs.
 * This one fails it on the second, so the rollback has to remove a file the
 * command had already successfully written (composer.json) rather than only the
 * directories around the one that threw - the state in which the old "already
 * exists. Nothing was created or modified" refusal was a lie.
 *
 * AC-6 is then proven by using the retried package rather than by listing its
 * files: it is booted into the running host and its route answered, which is
 * what "a complete, passing package" has to mean. The happy-path file set is
 * compared against the committed services/demo, so a rollback that quietly
 * changed what a successful generation writes would be caught too.
 */

function qaAtomicStub(): string
{
    return base_path('stubs'.DIRECTORY_SEPARATOR.'service'.DIRECTORY_SEPARATOR.'ServiceProvider.php.stub');
}

function qaAtomicBackup(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-atomic-backup'.DIRECTORY_SEPARATOR.'ServiceProvider.php.stub');
}

function qaAtomicRestoreStub(): void
{
    if (File::exists(qaAtomicBackup()) && ! File::exists(qaAtomicStub())) {
        File::move(qaAtomicBackup(), qaAtomicStub());
    }

    deleteFixtureDirectory(dirname(qaAtomicBackup()));
}

/**
 * Relative paths of every file in a tree, sorted.
 *
 * Passing a package name folds its own StudlyCase segment back to {{studly}},
 * so two packages generated from the same stubs under different names compare
 * equal - the provider file is the one path that carries the name.
 *
 * @return list<string>
 */
function qaRelativeFileList(string $root, ?string $package = null): array
{
    $files = [];

    foreach (Finder::create()->in($root)->files()->ignoreDotFiles(false)->ignoreVCS(true) as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        $files[] = $package === null
            ? $relative
            : str_replace(Str::studly($package), '{{studly}}', $relative);
    }

    sort($files);

    return $files;
}

afterEach(function () {
    qaAtomicRestoreStub();
    deleteFixtureDirectory(servicesPath('qaatomicfixture'));
});

it('leaves nothing behind when generation fails on an early file, and the retry produces a working package', function () {
    $name = 'qaatomicfixture';
    $checksum = (string) md5_file(qaAtomicStub());
    $directoriesBefore = File::directories(servicesPath());

    expect(File::exists(servicesPath($name)))->toBeFalse();

    File::ensureDirectoryExists(dirname(qaAtomicBackup()));
    File::move(qaAtomicStub(), qaAtomicBackup());

    try {
        // composer.json is written first and succeeds; the provider stub is the
        // second of the six and now cannot be read.
        $exit = Artisan::call('svc:make', ['name' => $name]);
        $output = Artisan::output();

        expect($exit)->not->toBe(0)
            ->and($output)->toContain('was removed')
            // AC-5: no partial package at all, not even the file that had
            // already been written successfully.
            ->and(File::exists(servicesPath($name)))->toBeFalse()
            ->and(File::exists(servicesPath($name).DIRECTORY_SEPARATOR.'composer.json'))->toBeFalse()
            ->and(File::directories(servicesPath()))->toBe($directoriesBefore);
    } finally {
        qaAtomicRestoreStub();
    }

    expect(md5_file(qaAtomicStub()))->toBe($checksum);

    // AC-6: the same name is genuinely free again. The old behaviour was a
    // refusal claiming nothing had been created.
    $retry = Artisan::call('svc:make', ['name' => $name]);

    expect($retry)->toBe(0)
        ->and(Artisan::output())->not->toContain('already exists');

    // The happy path still writes exactly the file set the committed reference
    // package has - the rollback wrapper changed the error path only.
    expect(qaRelativeFileList(servicesPath($name), $name))->toBe(qaRelativeFileList(servicesPath('demo'), 'demo'));

    // "Complete" proven by use: the retried package boots into the running host
    // and serves its own route, which is all a real service has to do.
    app(ServiceRegistry::class)->bootAll($name);

    expect(app(ServiceRegistry::class)->booted())->toContain($name);

    get("/api/v1/{$name}/ping")
        ->assertOk()
        ->assertJson(['service' => $name, 'status' => 'ok']);

    get('/health/ready')->assertOk()->assertJsonPath('services', ['demo', $name]);
});

it('refuses a genuinely existing package before writing or deleting anything', function () {
    // The rollback must never be able to reach a package the command did not
    // create: the refusal returns before the write block is entered. T-001
    // AC-29, re-proven because T-002 put a delete on that code path.
    $demo = servicesPath('demo');
    $before = qaRelativeFileList($demo);
    $manifest = (string) md5_file($demo.DIRECTORY_SEPARATOR.'composer.json');

    expect(Artisan::call('svc:make', ['name' => 'demo']))->not->toBe(0)
        ->and(Artisan::output())->toContain('already exists')
        ->and(qaRelativeFileList($demo))->toBe($before)
        ->and(md5_file($demo.DIRECTORY_SEPARATOR.'composer.json'))->toBe($manifest);
});

it('writes nothing inside services/host on the failure path either', function () {
    // T-001 AC-27 on the error path: the only thing the rollback deletes is
    // services/<name>/, and the only thing generation reads from the host is a
    // stub. Checked with the stub directory excluded, because this test is the
    // one moving a stub about.
    $name = 'qaatomicfixture';
    $before = qaHostFingerprintWithoutStubs();

    File::ensureDirectoryExists(dirname(qaAtomicBackup()));
    File::move(qaAtomicStub(), qaAtomicBackup());

    try {
        expect(Artisan::call('svc:make', ['name' => $name]))->not->toBe(0)
            ->and(qaHostFingerprintWithoutStubs())->toBe($before);
    } finally {
        qaAtomicRestoreStub();
    }

    expect(qaHostFingerprintWithoutStubs())->toBe($before);
});

/**
 * Checksums of every tracked file in services/host except the stubs.
 *
 * @return array<string, string>
 */
function qaHostFingerprintWithoutStubs(): array
{
    $fingerprint = [];

    $files = Finder::create()
        ->in(base_path())
        ->exclude(['vendor', 'storage', 'node_modules', 'bootstrap/cache', 'stubs'])
        ->files()
        ->ignoreDotFiles(false)
        ->ignoreVCS(true);

    foreach ($files as $file) {
        $fingerprint[$file->getRelativePathname()] = (string) md5_file($file->getPathname());
    }

    ksort($fingerprint);

    return $fingerprint;
}
