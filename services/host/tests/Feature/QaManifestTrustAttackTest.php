<?php

use App\Support\ServiceHost\ServiceRegistry;
use App\Support\ServiceHost\UnknownServiceException;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * QA attack proofs for T-002 AC-1, AC-2, AC-3 and AC-4.
 *
 * ServiceRegistryManifestTrustTest (dev's) proves the new checks throw. These
 * prove the thing the checks exist to prevent actually fails: a hostile or
 * copy-pasted manifest must not end up with a source directory donated to a
 * namespace it does not own, and the class it named must never reach the
 * container. Every case therefore plants a real class file under the namespace
 * being claimed and asserts it is still unloadable after the refused boot -
 * "the exception was thrown" and "the attack was blocked" are not the same
 * claim, and registering before validating was the original defect.
 */

/**
 * Composer's live class loader, obtained the way ServiceRegistry obtains it.
 */
function qaTrustLoader(): ClassLoader
{
    /** @var ClassLoader $loader */
    $loader = require base_path('vendor/autoload.php');

    return $loader;
}

/**
 * Generate a real service package, rewrite its manifest, and plant extra files.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 * @param  array<string, string>  $planted  relative path => file contents
 */
function qaTrustFixture(callable $mutate, array $planted = []): string
{
    $name = 'qatrustfixture';

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
 * Would the planted file have become loadable if the declared prefix had been
 * registered, as it was before T-002?
 *
 * Answered on a throwaway ClassLoader that is never registered with
 * spl_autoload_register, so proving the payload is real costs the running
 * process nothing: Composer's ClassLoader has no API for removing a prefix
 * again, so the live loader must not be used for this.
 */
function qaTrustWouldResolve(string $prefix, string $class): bool
{
    $loader = new ClassLoader;
    $loader->addPsr4($prefix, servicesPath('qatrustfixture').DIRECTORY_SEPARATOR.'src');

    return is_string($loader->findFile($class));
}

/**
 * Boot one service and return whatever it threw, or null if it booted.
 *
 * Deliberately catches Throwable, not UnknownServiceException: AC-1 requires
 * the failure to be the registry's own typed exception rather than a TypeError
 * or a fatal escaping from the container, and a narrower catch could not tell
 * the difference.
 */
function qaTrustFailure(string $name): ?Throwable
{
    try {
        app(ServiceRegistry::class)->bootAll($name);
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

afterEach(function () {
    File::deleteDirectory(servicesPath('qatrustfixture'));
});

it('refuses an autoloadable class that is not a service provider without ever constructing it', function () {
    // The package's own controller, pasted over the provider key by mistake.
    $provider = 'Flexipos\\Service\\Qatrustfixture\\Http\\Controllers\\PingController';

    $name = qaTrustFixture(function (array $manifest) use ($provider): array {
        $manifest['extra']['flexipos']['provider'] = $provider;

        return $manifest;
    });

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain($provider)
        ->and($failure->getMessage())->toContain('is not a Illuminate\Support\ServiceProvider')
        // Distinct from the "could not be autoloaded" message, per AC-1.
        ->and($failure->getMessage())->not->toContain('could not be autoloaded');

    // The class really was loadable, so it was the type check that stopped it,
    // not a failed autoload - the exact hole M-1 describes.
    expect(class_exists($provider))->toBeTrue();

    // And it never reached the container: nothing of this package is registered.
    $registered = array_filter(
        array_keys(app()->getLoadedProviders()),
        fn (string $loaded): bool => str_contains($loaded, 'Qatrustfixture')
    );

    expect($registered)->toBe([])
        ->and(app(ServiceRegistry::class)->booted())->not->toContain($name);
});

it('never donates a source directory to another service package namespace', function () {
    $stolen = 'Flexipos\\Service\\Sales\\QaStolenFromSales';

    expect(qaTrustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\')
        ->and(class_exists($stolen))->toBeFalse();

    $name = qaTrustFixture(
        function (array $manifest): array {
            $manifest['autoload']['psr-4'] = ['Flexipos\\Service\\Sales\\' => 'src/'];
            $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Sales\\SalesServiceProvider';

            return $manifest;
        },
        ['src/QaStolenFromSales.php' => "<?php\n\nnamespace Flexipos\\Service\\Sales;\n\nclass QaStolenFromSales {}\n"]
    );

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain('Flexipos\Service\Sales\\')
        ->and($failure->getMessage())->toContain('Flexipos\Service\Qatrustfixture\\');

    // The proof AC-2 asks for, both ways round: no prefix was registered, and
    // a class that would have become loadable through it is still not loadable.
    expect(qaTrustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\')
        ->and(class_exists($stolen))->toBeFalse()
        // ... and the payload was genuinely live: registering that prefix, which
        // is exactly what the registry used to do before validating, would have
        // resolved it.
        ->and(qaTrustWouldResolve('Flexipos\\Service\\Sales\\', $stolen))->toBeTrue();
});

it('never extends the host own App namespace with a service package source directory', function () {
    $hijack = 'App\\QaHijackedByFixture';
    $before = qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [];

    expect($before)->not->toBe([])
        ->and(class_exists($hijack))->toBeFalse();

    $name = qaTrustFixture(
        function (array $manifest): array {
            $manifest['autoload']['psr-4'] = ['App\\' => 'src/'];
            $manifest['extra']['flexipos']['provider'] = 'App\\QaHijackServiceProvider';

            return $manifest;
        },
        ['src/QaHijackedByFixture.php' => "<?php\n\nnamespace App;\n\nclass QaHijackedByFixture {}\n"]
    );

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain('[App\]')
        ->and($failure->getMessage())->toContain('Flexipos\Service\Qatrustfixture\\');

    // Shadowing the host's own classes is the worst case of M-2: the App\
    // prefix must be byte for byte what it was, and the planted class must
    // still be invisible.
    expect(qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [])->toBe($before)
        ->and(class_exists($hijack))->toBeFalse()
        // The shadowing payload is real: a second App\ source directory would
        // have resolved it, which is precisely what used to be registered.
        ->and(qaTrustWouldResolve('App\\', $hijack))->toBeTrue();
});

it('registers nothing for a second psr-4 entry the package is not entitled to', function () {
    // A manifest is not limited to one psr-4 key by anything but convention, so
    // the obvious way around AC-2 is to declare the entitled namespace *and* a
    // second one. Until BUG-1 was fixed this booted silently; it is refused now.
    // The invariant is asserted as well as the refusal, because it is the
    // property a later refactor (iterating the map instead of taking the first
    // key) would break while the refusal still looked fine.
    $hijack = 'App\\QaSmuggledBySecondSlot';
    $before = qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [];

    expect($before)->not->toBe([])
        ->and(class_exists($hijack))->toBeFalse();

    $name = qaTrustFixture(
        function (array $manifest): array {
            $manifest['autoload']['psr-4'] = [
                'Flexipos\\Service\\Qatrustfixture\\' => 'src/',
                'App\\' => 'src/',
            ];

            return $manifest;
        },
        ['src/QaSmuggledBySecondSlot.php' => "<?php\n\nnamespace App;\n\nclass QaSmuggledBySecondSlot {}\n"]
    );

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain('must declare exactly one')
        ->and($failure->getMessage())->toContain('Flexipos\Service\Qatrustfixture\\')
        // The advice names the entry to remove, not "everything after the first".
        ->and($failure->getMessage())->toContain('Remove [App\].');

    expect(qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [])->toBe($before)
        ->and(class_exists($hijack))->toBeFalse()
        ->and(qaTrustWouldResolve('App\\', $hijack))->toBeTrue();
});

it('rejects a two entry psr-4 map and names the unentitled key even when it is listed first', function () {
    // The ordering the count check changed: a two-entry map is refused inside
    // psr4(), which runs before the namespace-equality check, so this shape now
    // reports the count message rather than "may only declare". The refusal and
    // the no-prefix invariant are what AC-2 requires and both still hold - but
    // the message is pinned explicitly, because a case that quietly starts
    // failing at a different check while still passing is exactly the drift
    // this suite's own fixtures suffered in the previous round.
    $second = qaTrustFixture(function (array $manifest): array {
        $manifest['autoload']['psr-4'] = [
            'Flexipos\\Service\\Sales\\' => 'src/',
            'Flexipos\\Service\\Qatrustfixture\\' => 'src/',
        ];

        return $manifest;
    });

    $failure = qaTrustFailure($second);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($second)
        ->and($failure->getMessage())->toContain('declares 2 autoload.psr-4 namespaces')
        ->and($failure->getMessage())->toContain('must declare exactly one')
        // Correct advice: remove the foreign key and keep its own, rather than
        // the other way round just because the foreign one came first.
        ->and($failure->getMessage())->toContain('Remove [Flexipos\Service\Sales\].')
        ->and($failure->getMessage())->not->toContain('Remove [Flexipos\Service\Qatrustfixture\]');

    expect(qaTrustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\');
});

it('names every unentitled key when a psr-4 map declares only namespaces it may not have', function () {
    $name = qaTrustFixture(function (array $manifest): array {
        $manifest['autoload']['psr-4'] = [
            'Flexipos\\Service\\Sales\\' => 'src/',
            'App\\' => 'src/',
        ];

        return $manifest;
    });

    $before = qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [];
    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->getMessage())->toContain('Remove [Flexipos\Service\Sales\], [App\].')
        ->and(qaTrustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\')
        ->and(qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [])->toBe($before);
});

it('still reports an empty or malformed psr-4 map as such, not as a count problem', function (array $psr4, string $expected) {
    // The count check must not swallow the two shapes that already had their
    // own, more specific message: "none at all" and "not a string key".
    $name = 'qatrustfixture';

    expect(Artisan::call('svc:make', ['name' => $name]))->toBe(0);

    $file = servicesPath($name).DIRECTORY_SEPARATOR.'composer.json';

    /** @var array<string, mixed> $manifest */
    $manifest = (array) json_decode((string) File::get($file), true);
    $manifest['autoload']['psr-4'] = $psr4;

    File::put($file, (string) json_encode($manifest, JSON_PRETTY_PRINT));

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->getMessage())->toContain($expected)
        ->and($failure->getMessage())->not->toContain('must declare exactly one');
})->with([
    'empty map' => [[], 'declares no autoload.psr-4 namespace'],
    'numeric key' => [['0' => 'src/'], 'declares a malformed autoload.psr-4 namespace'],
]);

it('cannot have the one entry rule dodged by a manifest that repeats a json key', function () {
    // JSON permits a duplicated key and json_decode keeps the last value, so a
    // repeated key never reaches the count check as two entries. That is sound
    // rather than a hole - a repeated key is the same namespace twice, so there
    // is no second namespace to smuggle - and a genuinely different second key
    // alongside it is still counted and still refused, which is what this
    // asserts.
    $name = 'qatrustfixture';

    expect(Artisan::call('svc:make', ['name' => $name]))->toBe(0);

    File::put(servicesPath($name).DIRECTORY_SEPARATOR.'composer.json', <<<'JSON'
        {
            "name": "flexipos/service-qatrustfixture",
            "autoload": {
                "psr-4": {
                    "Flexipos\\Service\\Qatrustfixture\\": "src/",
                    "App\\": "src/",
                    "App\\": "src/"
                }
            },
            "extra": { "flexipos": { "provider": "Flexipos\\Service\\Qatrustfixture\\QatrustfixtureServiceProvider" } }
        }
        JSON);

    /** @var array<string, mixed> $decoded */
    $decoded = (array) json_decode((string) File::get(servicesPath($name).DIRECTORY_SEPARATOR.'composer.json'), true);

    expect($decoded['autoload']['psr-4'])->toHaveCount(2);

    $before = qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [];
    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->getMessage())->toContain('must declare exactly one')
        ->and(qaTrustLoader()->getPrefixesPsr4()['App\\'] ?? [])->toBe($before);
});

it('refuses a provider declared outside the package own namespace and registers nothing for it', function () {
    $provider = 'Flexipos\\Service\\Sales\\QatrustfixtureServiceProvider';

    $name = qaTrustFixture(function (array $manifest) use ($provider): array {
        $manifest['extra']['flexipos']['provider'] = $provider;

        return $manifest;
    });

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain($provider)
        ->and($failure->getMessage())->toContain('outside its own namespace')
        ->and($failure->getMessage())->toContain('Flexipos\Service\Qatrustfixture\\');

    expect(qaTrustLoader()->getPrefixesPsr4())->not->toHaveKey('Flexipos\\Service\\Sales\\')
        ->and(app(ServiceRegistry::class)->booted())->not->toContain($name);
});

it('refuses a provider that passes the type check but cannot be constructed', function (string $class, string $source) {
    // BUG-3. is_a(..., true) is satisfied by an abstract subclass and by one
    // whose constructor is not public, and register() then died with a raw
    // "Cannot instantiate abstract class" that never named the service - the
    // outcome D-1 and AC-1 exist to prevent, reached by a shape AC-1's wording
    // does not cover. The failure has to be the registry's own exception, so
    // this catches Throwable and asserts the type rather than expecting one.
    $name = qaTrustFixture(
        function (array $manifest) use ($class): array {
            $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Qatrustfixture\\'.$class;

            return $manifest;
        },
        ["src/{$class}.php" => $source]
    );

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain($class)
        ->and($failure->getMessage())->toContain('cannot be instantiated')
        // Distinguishable from its two neighbours, which have different fixes.
        ->and($failure->getMessage())->not->toContain('is not a Illuminate\Support\ServiceProvider')
        ->and($failure->getMessage())->not->toContain('could not be autoloaded');

    expect(app(ServiceRegistry::class)->booted())->not->toContain($name);
})->with([
    'abstract provider' => [
        'QaAbstractProvider',
        "<?php\n\nnamespace Flexipos\\Service\\Qatrustfixture;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nabstract class QaAbstractProvider extends ServiceProvider {}\n",
    ],
    'private constructor' => [
        'QaPrivateProvider',
        "<?php\n\nnamespace Flexipos\\Service\\Qatrustfixture;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass QaPrivateProvider extends ServiceProvider\n{\n    private function __construct() {}\n}\n",
    ],
    'protected constructor' => [
        'QaProtectedProvider',
        "<?php\n\nnamespace Flexipos\\Service\\Qatrustfixture;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass QaProtectedProvider extends ServiceProvider\n{\n    protected function __construct() {}\n}\n",
    ],
]);

it('refuses every other shape a provider key can name, each with its own message', function (string $class, string $source, string $expected, string $notExpected) {
    // The taxonomy the two checks have to keep apart. An enum is a class, so it
    // reaches the type check; a trait and an interface are not, so class_exists()
    // answers no and they stop earlier. None of them may reach the container.
    $name = qaTrustFixture(
        function (array $manifest) use ($class): array {
            $manifest['extra']['flexipos']['provider'] = 'Flexipos\\Service\\Qatrustfixture\\'.$class;

            return $manifest;
        },
        ["src/{$class}.php" => $source]
    );

    $failure = qaTrustFailure($name);

    expect($failure)->toBeInstanceOf(UnknownServiceException::class);

    /** @var UnknownServiceException $failure */
    expect($failure->service())->toBe($name)
        ->and($failure->getMessage())->toContain($class)
        ->and($failure->getMessage())->toContain($expected)
        ->and($failure->getMessage())->not->toContain($notExpected);

    expect(app(ServiceRegistry::class)->booted())->not->toContain($name);
})->with([
    'enum' => [
        'QaProviderEnum',
        "<?php\n\nnamespace Flexipos\\Service\\Qatrustfixture;\n\nenum QaProviderEnum\n{\n    case One;\n}\n",
        'is not a Illuminate\Support\ServiceProvider',
        'cannot be instantiated',
    ],
    'trait' => [
        'QaProviderTrait',
        "<?php\n\nnamespace Flexipos\\Service\\Qatrustfixture;\n\ntrait QaProviderTrait {}\n",
        'could not be autoloaded',
        'cannot be instantiated',
    ],
    'interface' => [
        'QaProviderContract',
        "<?php\n\nnamespace Flexipos\\Service\\Qatrustfixture;\n\ninterface QaProviderContract {}\n",
        'could not be autoloaded',
        'cannot be instantiated',
    ],
]);

it('still boots the committed demo package, so the rule blocks only what it should', function () {
    // A guard that also rejects a legitimate package is a defect, not a fix.
    $this->bootHostWith('demo');

    expect(app(ServiceRegistry::class)->booted())->toBe(['demo'])
        ->and(qaTrustLoader()->getPrefixesPsr4())->toHaveKey('Flexipos\\Service\\Demo\\');

    $this->get('/api/v1/demo/ping')->assertOk()->assertJson(['service' => 'demo', 'status' => 'ok']);
});

it('rejects a trailing newline in the shared name pattern itself', function () {
    // PCRE's $ matches just before a final newline; the D modifier is what
    // closes that. Same defect class as T-001's BUG-2.
    foreach (["billing\n", "demo\n", "http-client\n", "billing\r", "billing\r\n", "billing\n\n", "billing\nhost"] as $name) {
        expect(preg_match(ServiceRegistry::NAME_PATTERN, $name))->toBe(0, json_encode($name));
    }

    foreach (['billing', 'demo', 'http-client'] as $name) {
        expect(preg_match(ServiceRegistry::NAME_PATTERN, $name))->toBe(1, $name);
    }
});

it('rejects a trailing newline at the real command line and creates nothing', function () {
    // AC-4 (a): the actual shell path, not Artisan::call() in process, because
    // the generator is what a developer runs and it does not trim.
    $before = File::directories(servicesPath());

    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('artisan'),
        'svc:make',
        "billing\n",
        '--no-ansi',
    ]);

    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->not->toBe(0)
        ->and($output)->toContain('Invalid service name')
        // The child really did receive the newline, rather than a name the
        // shell had already stripped it from - otherwise this proves nothing.
        ->and($output)->toContain("billing\n]")
        ->and(File::directories(servicesPath()))->toBe($before)
        ->and(File::exists(servicesPath('billing')))->toBeFalse();
});
