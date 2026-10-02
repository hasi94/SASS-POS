<?php

use App\Support\ModuleHost\ModuleRegistry;
use App\Support\ModuleHost\UnknownModuleException;
use Flexipos\Module\Demo\DemoServiceProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

use function Pest\Laravel\get;

it('registers only the module named in MODULES', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    expect(app(ModuleRegistry::class)->booted())->toBe(['demo'])
        ->and(app()->getLoadedProviders())->toHaveKey(DemoServiceProvider::class);

    $modules = collect(array_keys(app()->getLoadedProviders()))
        ->filter(fn (string $provider): bool => str_starts_with($provider, 'Flexipos\\Module\\'))
        ->values()
        ->all();

    expect($modules)->toBe([DemoServiceProvider::class]);
});

it('registers a duplicated, whitespace padded name exactly once', function () {
    /** @var TestCase $this */
    $this->bootHostWith(' demo , demo ');

    expect(app(ModuleRegistry::class)->booted())->toBe(['demo']);

    $pings = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'api/v1/demo/ping')
        ->count();

    expect($pings)->toBe(1);

    get('/api/v1/demo/ping')->assertOk();
});

it('is idempotent when the same module is booted twice', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    app(ModuleRegistry::class)->bootAll('demo');

    expect(app(ModuleRegistry::class)->booted())->toBe(['demo']);
});

it('parses a MODULES value into a sorted, trimmed, de-duplicated list', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->parse('demo'))->toBe(['demo'])
        ->and($registry->parse(' demo , demo '))->toBe(['demo'])
        ->and($registry->parse('catalog, identity,catalog'))->toBe(['catalog', 'identity'])
        ->and($registry->parse(''))->toBe([])
        ->and($registry->parse('   '))->toBe([])
        ->and($registry->parse(',,'))->toBe([]);
});

it('fails fast naming the unknown module when MODULES lists a package that does not exist', function () {
    /** @var TestCase $this */
    expect(fn () => $this->bootHostWith('demo,ghost'))
        ->toThrow(UnknownModuleException::class, 'ghost');
});

it('refuses to boot the host application itself as a module', function () {
    /** @var TestCase $this */
    expect(fn () => $this->bootHostWith('host'))
        ->toThrow(UnknownModuleException::class, 'host');
});

it('fails fast on a MODULES entry that is not a plain module name', function (string $entry) {
    /** @var TestCase $this */
    expect(fn () => $this->bootHostWith($entry))
        ->toThrow(UnknownModuleException::class, $entry);
})->with([
    // Resolves to modules/demo on every OS, but is not a module identifier:
    // accepting it made /health/ready report a module twice under two names.
    'path traversal' => 'demo/../demo',
    'absolute-ish path' => '/demo',
    // Boots on Windows, fails fast on a case-sensitive filesystem - the same
    // MODULES value must behave identically on both.
    'wrong case' => 'Demo',
    'trailing hyphen' => 'demo-',
    'doubled hyphen' => 'de--mo',
    'underscore' => 'de_mo',
]);

it('never reports an entry it did not actually register', function () {
    /** @var TestCase $this */
    expect(fn () => $this->bootHostWith('demo,demo/../demo'))
        ->toThrow(UnknownModuleException::class);

    // The host refused to start at all, so nothing can be half-booted and
    // /health/ready cannot disagree with the routing table.
    $this->bootHostWith('demo');

    expect(app(ModuleRegistry::class)->booted())->toBe(['demo']);

    get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'modules' => ['demo']]);
});

it('boots with zero modules and still serves HTTP when MODULES is empty', function () {
    /** @var TestCase $this */
    $this->bootHostWith('');

    expect(app(ModuleRegistry::class)->booted())->toBe([]);

    get('/health/live')->assertOk();
});

it('boots with zero modules when MODULES is unset', function () {
    /** @var TestCase $this */
    $this->bootHostWith(null);

    expect(app(ModuleRegistry::class)->booted())->toBe([]);

    get('/health/live')->assertOk();
});
