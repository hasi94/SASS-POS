<?php

use App\Support\ServiceHost\ServiceRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * A service package that exists on disk but is not named in SERVICES must cost
 * nothing at boot: no provider, no routes, no entry in /health/ready.
 */

afterEach(function () {
    File::deleteDirectory(servicesPath('unlistedfixture'));
});

it('does not route a service package that exists but is not listed in SERVICES', function () {
    expect(Artisan::call('svc:make', ['name' => 'unlistedfixture']))->toBe(0)
        ->and(File::exists(servicesPath('unlistedfixture').'/routes/api.php'))->toBeTrue();

    $this->bootHostWith('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo']);

    $this->get('/api/v1/unlistedfixture/ping')->assertNotFound();

    $this->get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => ['demo']]);
});
