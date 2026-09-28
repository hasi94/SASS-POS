<?php

use App\Support\ServiceHost\ServiceRegistry;
use App\Support\ServiceHost\UnknownServiceException;
use Flexipos\Service\Demo\DemoServiceProvider;
use Illuminate\Support\Facades\Route;

it('registers only the service named in SERVICES', function () {
    $this->bootHostWith('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo'])
        ->and(app()->getLoadedProviders())->toHaveKey(DemoServiceProvider::class);

    $services = collect(array_keys(app()->getLoadedProviders()))
        ->filter(fn (string $provider): bool => str_starts_with($provider, 'Flexipos\\Service\\'))
        ->values()
        ->all();

    expect($services)->toBe([DemoServiceProvider::class]);
});

it('registers a duplicated, whitespace padded name exactly once', function () {
    $this->bootHostWith(' demo , demo ');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo']);

    $pings = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === 'api/v1/demo/ping')
        ->count();

    expect($pings)->toBe(1);

    $this->get('/api/v1/demo/ping')->assertOk();
});

it('is idempotent when the same service is booted twice', function () {
    $this->bootHostWith('demo');

    app(ServiceRegistry::class)->bootAll('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo']);
});

it('parses a SERVICES value into a sorted, trimmed, de-duplicated list', function () {
    $registry = app(ServiceRegistry::class);

    expect($registry->parse('demo'))->toBe(['demo'])
        ->and($registry->parse(' demo , demo '))->toBe(['demo'])
        ->and($registry->parse('catalog, identity,catalog'))->toBe(['catalog', 'identity'])
        ->and($registry->parse(''))->toBe([])
        ->and($registry->parse('   '))->toBe([])
        ->and($registry->parse(',,'))->toBe([]);
});

it('fails fast naming the unknown service when SERVICES lists a package that does not exist', function () {
    expect(fn () => $this->bootHostWith('demo,ghost'))
        ->toThrow(UnknownServiceException::class, 'ghost');
});

it('refuses to boot the host application itself as a service package', function () {
    expect(fn () => $this->bootHostWith('host'))
        ->toThrow(UnknownServiceException::class, 'host');
});

it('fails fast on a SERVICES entry that is not a plain service package name', function (string $entry) {
    expect(fn () => $this->bootHostWith($entry))
        ->toThrow(UnknownServiceException::class, $entry);
})->with([
    // Resolves to services/demo on every OS, but is not a service identifier:
    // accepting it made /health/ready report a service twice under two names.
    'path traversal' => 'demo/../demo',
    'absolute-ish path' => '/demo',
    // Boots on Windows, fails fast on a case-sensitive filesystem - the same
    // SERVICES value must behave identically on both.
    'wrong case' => 'Demo',
    'trailing hyphen' => 'demo-',
    'doubled hyphen' => 'de--mo',
    'underscore' => 'de_mo',
]);

it('never reports an entry it did not actually register', function () {
    expect(fn () => $this->bootHostWith('demo,demo/../demo'))
        ->toThrow(UnknownServiceException::class);

    // The host refused to start at all, so nothing can be half-booted and
    // /health/ready cannot disagree with the routing table.
    $this->bootHostWith('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo']);

    $this->get('/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'services' => ['demo']]);
});

it('boots with zero services and still serves HTTP when SERVICES is empty', function () {
    $this->bootHostWith('');

    expect(app(ServiceRegistry::class)->booted())->toBe([]);

    $this->get('/health/live')->assertOk();
});

it('boots with zero services when SERVICES is unset', function () {
    $this->bootHostWith(null);

    expect(app(ServiceRegistry::class)->booted())->toBe([]);

    $this->get('/health/live')->assertOk();
});
