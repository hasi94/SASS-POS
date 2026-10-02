<?php

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\get;

/*
 * Adding a service must be a configuration change, never a code change: the
 * generator writes nothing inside services/host, and naming the package is
 * enough to make it reachable.
 */

/**
 * Checksums of every tracked file in services/host.
 *
 * @return array<string, string>
 */
function hostFingerprint(): array
{
    $files = Finder::create()
        ->in(base_path())
        ->exclude(['vendor', 'storage', 'node_modules', 'bootstrap/cache'])
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
    deleteFixtureDirectory(servicesPath('reachablefixture'));
});

it('generates a package without touching a single file inside services/host', function () {
    $before = hostFingerprint();

    expect(Artisan::call('svc:make', ['name' => 'reachablefixture']))->toBe(0)
        ->and(hostFingerprint())->toBe($before);
});

it('makes a generated package reachable purely by naming it', function () {
    expect(Artisan::call('svc:make', ['name' => 'reachablefixture']))->toBe(0);

    // Exactly what the host does at boot for every name in SERVICES.
    app(ServiceRegistry::class)->bootAll('reachablefixture');

    expect(app(ServiceRegistry::class)->booted())->toContain('reachablefixture');

    get('/api/v1/reachablefixture/ping')
        ->assertOk()
        ->assertJson(['service' => 'reachablefixture', 'status' => 'ok']);

    get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => ['demo', 'reachablefixture']]);
});
