<?php

use App\Support\ServiceHost\ServiceRegistry;
use App\Support\ServiceHost\UnknownServiceException;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * T-002 FP-146 (T-001 review findings M-1, M-2, M-3).
 *
 * A service package manifest is untrusted input. Before T-002 the registry took
 * the PSR-4 namespace it declared verbatim, registered it with Composer's class
 * loader before validating anything, and then constructed whatever class the
 * manifest named as long as it was autoloadable. A copy-pasted package could
 * therefore add a second source directory for another service's namespace - or
 * for the host's own App\ - and a manifest naming any loadable class killed the
 * process with a fatal that never said which service was at fault.
 *
 * The rule now: the single autoload.psr-4 key must be exactly
 * Flexipos\Service\<Studly>\ for the package's own directory name, the provider
 * must be declared inside it, and the provider must really be a service
 * provider. The first two are checked before addPsr4() ever runs.
 */

/**
 * Generate a throwaway service package, rewrite its manifest and plant files.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 * @param  array<string, string>  $planted  relative path => file contents
 */
function trustFixture(callable $mutate, array $planted = []): string
{
    $name = 'trustfixture';

    expect(Artisan::call('svc:make', ['name' => $name]))->toBe(0);

    $file = servicesPath($name).DIRECTORY_SEPARATOR.'composer.json';

    /** @var array<string, mixed> $manifest */
    $manifest = (array) json_decode((string) File::get($file), true);

    File::put($file, (string) json_encode($mutate($manifest), JSON_PRETTY_PRINT));

    foreach ($planted as $relative => $contents) {
        $path = servicesPath($name).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    return $name;
}

/**
 * Composer's class loader, obtained exactly the way ServiceRegistry obtains it.
 */
function trustLoader(): ClassLoader
{
    /** @var ClassLoader $loader */
    $loader = require base_path('vendor/autoload.php');

    return $loader;
}

/**
 * Boot one service and return the failure, or null if it booted.
 */
function trustBootFailure(string $name): ?UnknownServiceException
{
    try {
        app(ServiceRegistry::class)->bootAll($name);
    } catch (UnknownServiceException $exception) {
        return $exception;
    }

    return null;
}

/**
 * Boot one service catching anything at all, so a raw PHP Error escaping the
 * container is observable rather than fatal to the run.
 */
function trustBootThrowable(string $name): ?Throwable
{
    try {
        app(ServiceRegistry::class)->bootAll($name);
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

afterEach(function () {
    deleteFixtureDirectory(servicesPath('trustfixture'));
});

it('refuses a manifest naming a class that is not a service provider', function () {
    // The package's own controller, pasted into the manifest by mistake: really
    // autoloadable, so class_exists() alone lets it through to the container.
    $name = trustFixture(function (array $manifest): array {
        $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Trustfixture\\Http\\Controllers\\PingController';

        return $manifest;
    });

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Trustfixture\Http\Controllers\PingController')
        ->and($failure?->getMessage())->toContain('is not a Illuminate\Support\ServiceProvider')
        ->and($failure?->getMessage())->not->toContain('could not be autoloaded');
});

it('refuses a manifest that claims another service package namespace and registers nothing for it', function () {
    $name = trustFixture(function (array $manifest): array {
        $manifest['autoload']['psr-4'] = ['Flexipos\\Service\\Sales\\' => 'src/'];
        $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Sales\\SalesServiceProvider';

        return $manifest;
    });

    expect(trustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\');

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Sales\\')
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Trustfixture\\');

    // The proof M-2 actually asks for: the rejected package left no PSR-4
    // prefix behind for a namespace it was never entitled to.
    expect(trustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\');
});

it('refuses a manifest that claims the host own App namespace and never extends that prefix', function () {
    $before = trustLoader()->getPrefixesPsr4()['App\\'] ?? [];

    expect($before)->not->toBe([]);

    $name = trustFixture(function (array $manifest): array {
        $manifest['autoload']['psr-4'] = ['App\\' => 'src/'];
        $manifest['extra']['flexipos']['provider'] = 'App\\TrustfixtureServiceProvider';

        return $manifest;
    });

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('[App\]')
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Trustfixture\\');

    // A second source directory for App\ would let a service package shadow the
    // host's own classes. The prefix must be byte for byte what it was.
    expect(trustLoader()->getPrefixesPsr4()['App\\'] ?? [])->toBe($before);
});

/*
 * BUG-1 (QA run 1): the manifest's psr-4 map has to hold exactly one pair, not
 * merely start with the right one. Only array_key_first() was ever validated,
 * so a manifest could declare the namespace it is entitled to and a second one
 * beside it and still boot. The extra entry was inert - nothing registered it -
 * but the plan's rule was "the single autoload.psr-4 key", and a later refactor
 * that iterated the map would have turned it back into the M-2 attack.
 */

it('refuses a manifest that smuggles a second psr-4 namespace beside its own', function () {
    $before = trustLoader()->getPrefixesPsr4()['App\\'] ?? [];

    expect($before)->not->toBe([]);

    $name = trustFixture(
        function (array $manifest): array {
            $manifest['autoload']['psr-4'] = [
                'Flexipos\\Service\\Trustfixture\\' => 'src/',
                'App\\' => 'src/',
            ];

            return $manifest;
        },
        ['src/TrustSmuggled.php' => "<?php\n\nnamespace App;\n\nclass TrustSmuggled {}\n"]
    );

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('declares 2 autoload.psr-4 namespaces')
        ->and($failure?->getMessage())->toContain('[App\]')
        ->and($failure?->getMessage())->toContain('must declare exactly one');

    expect(trustLoader()->getPrefixesPsr4()['App\\'] ?? [])->toBe($before)
        ->and(class_exists('App\\TrustSmuggled'))->toBeFalse()
        ->and(app(ServiceRegistry::class)->booted())->not->toContain($name);
});

it('still refuses a two entry psr-4 map when the foreign entry is listed first', function () {
    $name = trustFixture(function (array $manifest): array {
        $manifest['autoload']['psr-4'] = [
            'Flexipos\\Service\\Sales\\' => 'src/',
            'Flexipos\\Service\\Trustfixture\\' => 'src/',
        ];

        return $manifest;
    });

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('must declare exactly one');

    expect(trustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\');
});

it('refuses an abstract provider instead of letting the container fatal on it', function () {
    // BUG-3 (QA run 1): is_a(..., ServiceProvider::class, true) is satisfied by
    // an abstract subclass, so register() died with a raw
    // "Cannot instantiate abstract class" that never named the service - the
    // outcome D-1 and AC-1's rationale exist to prevent, just outside AC-1's
    // literal "does not extend ServiceProvider" wording.
    $provider = 'Flexipos\\Service\\Trustfixture\\TrustAbstractProvider';

    $name = trustFixture(
        function (array $manifest) use ($provider): array {
            $manifest['extra']['flexipos']['provider'] = $provider;

            return $manifest;
        },
        ['src/TrustAbstractProvider.php' => "<?php\n\nnamespace Flexipos\\Service\\Trustfixture;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nabstract class TrustAbstractProvider extends ServiceProvider {}\n"]
    );

    $failure = trustBootThrowable($name);

    // Not an Error, not a TypeError: the registry's own typed exception.
    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain($provider)
        ->and($failure->getMessage())->toContain('cannot be instantiated')
        // A different mistake from "that is not a service provider", so it must
        // read differently.
        ->and($failure->getMessage())->not->toContain('is not a Illuminate\Support\ServiceProvider');

    // It really did pass the checks before it, so isInstantiable() is what
    // stopped it - and it never reached the container.
    expect(class_exists($provider))->toBeTrue()
        ->and(is_a($provider, ServiceProvider::class, true))->toBeTrue()
        ->and(app(ServiceRegistry::class)->booted())->not->toContain($name);
});

it('refuses a provider class declared outside the package own namespace', function () {
    $name = trustFixture(function (array $manifest): array {
        $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Sales\\TrustfixtureServiceProvider';

        return $manifest;
    });

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Sales\TrustfixtureServiceProvider')
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Trustfixture\\')
        ->and($failure?->getMessage())->toContain('outside its own namespace');
});

it('still reports a correctly scoped provider class that is not on disk as not autoloadable', function () {
    // The namespace/prefix checks run first now, so this is the only shape that
    // still reaches the class_exists() branch. QaServiceRegistryContractTest's
    // own fixture for it declares a foreign namespace and therefore now stops
    // one check earlier; keep the branch proven here.
    $name = trustFixture(function (array $manifest): array {
        $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Trustfixture\\NotOnDisk';

        return $manifest;
    });

    $failure = trustBootFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class)
        ->and($failure?->service())->toBe($name)
        ->and($failure?->getMessage())->toContain('Flexipos\Service\Trustfixture\NotOnDisk')
        ->and($failure?->getMessage())->toContain('could not be autoloaded');
});

it('rejects a candidate service name that ends in a newline', function () {
    // PCRE's $ also matches just before a final newline, so without the D
    // modifier "billing\n" passed the shared pattern - the same defect class as
    // T-001's BUG-2, and the reason svc:make could create a directory the
    // registry (which trims in parse()) could never boot.
    expect(preg_match(ServiceRegistry::NAME_PATTERN, "billing\n"))->toBe(0)
        ->and(preg_match(ServiceRegistry::NAME_PATTERN, "demo\n"))->toBe(0)
        ->and(preg_match(ServiceRegistry::NAME_PATTERN, "http-client\n"))->toBe(0)
        ->and(preg_match(ServiceRegistry::NAME_PATTERN, 'billing'))->toBe(1);
});

it('still boots a correctly scoped service package and registers its prefix on the required loader', function () {
    $name = trustFixture(fn (array $manifest): array => $manifest);

    expect(trustBootFailure($name))->toBeNull()
        ->and(app(ServiceRegistry::class)->booted())->toContain($name);

    // The loader ServiceRegistry required is the live one, not a second copy:
    // the prefix it just added is visible through a fresh require.
    expect(trustLoader()->getPrefixesPsr4())->toHaveKey('Flexipos\\Service\\Trustfixture\\');

    get('/api/v1/trustfixture/ping')->assertOk();
});

it('returns Composer own cached class loader every time the autoload file is required', function () {
    $first = require base_path('vendor/autoload.php');
    $second = require base_path('vendor/autoload.php');

    expect($first)->toBeInstanceOf(ClassLoader::class)
        ->and($second)->toBe($first);
});

it('boots the committed demo package unchanged under the tightened rule', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo']);

    get('/api/v1/demo/ping')->assertOk()->assertJson(['service' => 'demo', 'status' => 'ok']);

    get('/health/ready')->assertOk()->assertExactJson(['status' => 'ok', 'services' => ['demo']]);
});
