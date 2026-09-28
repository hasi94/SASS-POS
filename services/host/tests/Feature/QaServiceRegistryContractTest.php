<?php

use App\Support\ServiceHost\ServiceRegistry;
use App\Support\ServiceHost\UnknownServiceException;
use Illuminate\Support\Facades\File;

/*
 * QA tests for T-001 AC-18 through AC-23.
 *
 * ServicesEnvParsingTest covers the happy paths and the missing-directory case.
 * These cover the other ways a service package on disk can be unusable, because
 * decision D-1 says nothing may ever be silently skipped: every one of them has
 * to fail fast at boot with the offending name in the message.
 */

/**
 * Write a deliberately broken service package and return its name.
 */
function qaBrokenPackage(string $name, ?string $manifest, bool $withProvider = false): string
{
    $root = servicesPath($name);

    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'src');

    if ($manifest !== null) {
        File::put($root.DIRECTORY_SEPARATOR.'composer.json', $manifest);
    }

    if ($withProvider) {
        File::put(
            $root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Provider.php',
            "<?php\n\nnamespace Qa\\Broken;\n\nclass Provider {}\n"
        );
    }

    return $name;
}

afterEach(function () {
    foreach (['qabrokenfixture'] as $name) {
        File::deleteDirectory(servicesPath($name));
    }
});

it('fails fast when a named directory has no composer.json', function () {
    qaBrokenPackage('qabrokenfixture', null);

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownServiceException::class, 'qabrokenfixture');
});

it('fails fast when a service package manifest is not valid JSON', function () {
    qaBrokenPackage('qabrokenfixture', '{ this is not json');

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownServiceException::class, 'qabrokenfixture');
});

it('fails fast when a service package declares no psr-4 namespace', function () {
    qaBrokenPackage('qabrokenfixture', json_encode([
        'name' => 'flexipos/service-qabrokenfixture',
        'extra' => ['flexipos' => ['provider' => 'Qa\\Broken\\Provider']],
    ]));

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownServiceException::class, 'qabrokenfixture');
});

it('fails fast when a service package names no provider class', function () {
    qaBrokenPackage('qabrokenfixture', json_encode([
        'name' => 'flexipos/service-qabrokenfixture',
        'autoload' => ['psr-4' => ['Qa\\Broken\\' => 'src/']],
    ]));

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownServiceException::class, 'qabrokenfixture');
});

it('fails fast when the named provider class cannot be autoloaded', function () {
    qaBrokenPackage('qabrokenfixture', json_encode([
        'name' => 'flexipos/service-qabrokenfixture',
        'autoload' => ['psr-4' => ['Qa\\Broken\\' => 'src/']],
        'extra' => ['flexipos' => ['provider' => 'Qa\\Broken\\NotOnDisk']],
    ]));

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownServiceException::class, 'qabrokenfixture');
});

it('names the offending service in the exception rather than the first one in the list', function () {
    $thrown = null;

    try {
        $this->bootHostWith('demo,ghost');
    } catch (UnknownServiceException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(UnknownServiceException::class)
        ->and($thrown?->service())->toBe('ghost')
        ->and($thrown?->getMessage())->toContain('SERVICES');
});

it('keeps the booted list sorted and free of duplicates whatever order SERVICES uses', function () {
    $registry = app(ServiceRegistry::class);

    expect($registry->parse('zulu,alpha,mike,alpha'))->toBe(['alpha', 'mike', 'zulu'])
        ->and($registry->parse("\tdemo\n"))->toBe(['demo'])
        ->and($registry->parse(' , , '))->toBe([]);
});

it('serves liveness without a services key so the two health endpoints stay distinct', function () {
    $this->bootHostWith('demo');

    $live = $this->get('/health/live')->assertOk();

    expect($live->json())->toBe(['status' => 'ok'])
        ->and($live->headers->get('content-type'))->toContain('application/json');

    $ready = $this->get('/health/ready')->assertOk();

    expect($ready->json())->toBe(['status' => 'ok', 'services' => ['demo']]);
});
