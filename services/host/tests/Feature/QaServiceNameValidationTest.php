<?php

use App\Console\Commands\SvcMakeCommand;
use App\Support\ServiceHost\ServiceRegistry;
use App\Support\ServiceHost\UnknownServiceException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * QA tests for T-001 AC-22 / AC-28 robustness (run 1 BUG-2 and BUG-3, fixed in run 2).
 *
 * Before the fix, ServiceRegistry treated whatever SERVICES contained as a
 * directory name and reported it verbatim from /health/ready, so the endpoint an
 * operator uses to "verify a given host process's configuration at a glance"
 * could list entries that were not service packages and whose routes were not
 * mounted. Both the generator and the registry now validate against the single
 * pattern in ServiceRegistry::NAME_PATTERN (decision D-12).
 *
 * Note on the contract these tests encode: an invalid entry must make the host
 * fail fast, not be silently dropped. Decision D-1 is explicit that nothing in
 * SERVICES may ever be skipped in silence, because a silently dropped entry is
 * exactly the typo'd-configuration bug D-1 exists to catch.
 */

afterEach(function () {
    File::deleteDirectory(servicesPath('qa-'));
    File::deleteDirectory(servicesPath('qa-name-fixture'));
});

it('refuses a SERVICES entry that is not a plain service package name', function () {
    // services/demo/../demo/composer.json resolves on every OS, so before the fix
    // the registry accepted this, registered the demo provider, and then reported
    // the raw string "demo/../demo" as a booted service identifier.
    expect(fn () => $this->bootHostWith('demo/../demo'))
        ->toThrow(UnknownServiceException::class, 'demo/../demo');
});

it('refuses a SERVICES entry whose case does not match the directory on disk', function () {
    // is_dir('services/Demo') answers yes on Windows and no on Linux. Validating
    // the name and comparing against a directory listing makes the same SERVICES
    // value behave identically in XAMPP development and in a Docker image.
    expect(fn () => $this->bootHostWith('Demo'))
        ->toThrow(UnknownServiceException::class, 'Demo');
});

it('fails the whole boot rather than dropping one bad entry out of several', function () {
    expect(fn () => $this->bootHostWith('demo,demo/../demo'))
        ->toThrow(UnknownServiceException::class, 'demo/../demo');
});

it('never reports a service from /health/ready without a matching registered provider', function () {
    $this->bootHostWith('demo');

    $booted = app(ServiceRegistry::class)->booted();
    $providers = array_keys(app()->getLoadedProviders());

    expect($this->get('/health/ready')->assertOk()->json('services'))->toBe($booted);

    foreach ($booted as $name) {
        $matching = array_values(array_filter(
            $providers,
            fn (string $provider): bool => str_starts_with($provider, 'Flexipos\\Service\\'.Str::studly($name).'\\')
        ));

        expect($matching)->not->toBe([], "/health/ready reports [{$name}] but no provider of that service is registered");
    }
});

it('refuses a service name whose Composer package name would be invalid', function () {
    // "qa-" satisfied D-5's original pattern but yields the Composer package name
    // "flexipos/service-qa-", which Composer's own schema rejects, and namespace
    // Flexipos\Service\Qa\, which collides with a later `svc:make qa`.
    expect(Artisan::call('svc:make', ['name' => 'qa-']))->not->toBe(0)
        ->and(Artisan::output())->toContain('Invalid service name')
        ->and(File::exists(servicesPath('qa-')))->toBeFalse();
});

it('refuses doubled hyphens but still accepts a legitimate multi word name', function () {
    $before = File::directories(servicesPath());

    expect(Artisan::call('svc:make', ['name' => 'qa--name']))->not->toBe(0)
        ->and(File::directories(servicesPath()))->toBe($before);

    expect(Artisan::call('svc:make', ['name' => 'qa-name-fixture']))->toBe(0)
        ->and(File::exists(servicesPath('qa-name-fixture').'/composer.json'))->toBeTrue();

    $manifest = json_decode((string) File::get(servicesPath('qa-name-fixture').'/composer.json'), true);

    expect($manifest['name'])->toBe('flexipos/service-qa-name-fixture')
        ->and($manifest['autoload']['psr-4'])->toHaveKey('Flexipos\\Service\\QaNameFixture\\');
});

it('uses one shared definition of a valid service name for the generator and the registry', function () {
    $command = new ReflectionClass(SvcMakeCommand::class);

    expect($command->getConstant('NAME_PATTERN'))->toBe(ServiceRegistry::NAME_PATTERN);

    foreach (['billing', 'http-client', 'a', 'a1-b2'] as $valid) {
        expect(preg_match(ServiceRegistry::NAME_PATTERN, $valid))->toBe(1, $valid);
    }

    foreach (['Billing', '1billing', '-billing', 'billing-', 'bill--ing', 'bill_ing', '', 'demo/../demo'] as $invalid) {
        expect(preg_match(ServiceRegistry::NAME_PATTERN, $invalid))->toBe(0, $invalid);
    }
});
