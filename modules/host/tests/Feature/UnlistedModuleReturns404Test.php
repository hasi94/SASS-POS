<?php

use App\Support\ModuleHost\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * A module that exists on disk but is not named in MODULES must cost
 * nothing at boot: no provider, no routes, no entry in /health/ready.
 */

afterEach(function () {
    deleteFixtureDirectory(modulesPath('unlistedfixture'));
});

it('does not route a module that exists but is not listed in MODULES', function () {
    /** @var TestCase $this */
    expect(Artisan::call('module:make', ['name' => 'unlistedfixture']))->toBe(0)
        ->and(File::exists(modulesPath('unlistedfixture').'/routes/api.php'))->toBeTrue();

    $this->bootHostWith('demo');

    expect(app(ModuleRegistry::class)->booted())->toBe(['demo']);

    get('/api/v1/unlistedfixture/ping')->assertNotFound();

    get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'modules' => ['demo']]);
});
