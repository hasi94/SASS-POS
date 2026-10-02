<?php

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * A service package that exists on disk but is not named in SERVICES must cost
 * nothing at boot: no provider, no routes, no entry in /health/ready.
 */

afterEach(function () {
    deleteFixtureDirectory(servicesPath('unlistedfixture'));
});

it('does not route a service package that exists but is not listed in SERVICES', function () {
    /** @var TestCase $this */
    expect(Artisan::call('svc:make', ['name' => 'unlistedfixture']))->toBe(0)
        ->and(File::exists(servicesPath('unlistedfixture').'/routes/api.php'))->toBeTrue();

    $this->bootHostWith('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo']);

    get('/api/v1/unlistedfixture/ping')->assertNotFound();

    get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => ['demo']]);
});
