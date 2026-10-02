<?php

use App\Support\ModuleHost\ModuleRegistry;
use App\Support\ModuleHost\UnknownModuleException;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * QA tests for T-001 AC-18 through AC-23.
 *
 * ModulesEnvParsingTest covers the happy paths and the missing-directory case.
 * These cover the other ways a module on disk can be unusable, because
 * decision D-1 says nothing may ever be silently skipped: every one of them has
 * to fail fast at boot with the offending name in the message.
 *
 * T-002 note. These fixtures used to declare the namespace Qa\Broken\, which
 * was fine while the registry only asked "is the provider autoloadable". T-002
 * added an earlier check - the namespace must be exactly the one the package is
 * entitled to (AC-2) - so a Qa\Broken\ fixture now stops one check sooner and
 * the case named "cannot be autoloaded" would silently stop testing autoloading
 * while still passing. Each fixture is therefore scoped to the namespace its
 * own directory name earns it, so it still fails at the branch its description
 * claims, and each case now asserts the specific message rather than only the
 * module name. The namespace-trust cases themselves live in
 * QaManifestTrustAttackTest.
 */

/**
 * The one namespace a package called qabrokenfixture may declare.
 */
const QA_BROKEN_NAMESPACE = 'Flexipos\\Module\\Qabrokenfixture\\';

/**
 * Write a deliberately broken module and return its name.
 */
function qaBrokenPackage(string $name, ?string $manifest): string
{
    $root = modulesPath($name);

    File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'src');

    if ($manifest !== null) {
        File::put($root.DIRECTORY_SEPARATOR.'composer.json', $manifest);
    }

    return $name;
}

afterEach(function () {
    foreach (['qabrokenfixture'] as $name) {
        deleteFixtureDirectory(modulesPath($name));
    }
});

it('fails fast when a named directory has no composer.json', function () {
    /** @var TestCase $this */
    qaBrokenPackage('qabrokenfixture', null);

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'qabrokenfixture')
        ->and(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'no module found at');
});

it('fails fast when a module manifest is not valid JSON', function () {
    /** @var TestCase $this */
    qaBrokenPackage('qabrokenfixture', '{ this is not json');

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'qabrokenfixture')
        ->and(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'is not valid JSON');
});

it('fails fast when a module declares no psr-4 namespace', function () {
    /** @var TestCase $this */
    qaBrokenPackage('qabrokenfixture', (string) json_encode([
        'name' => 'flexipos/module-qabrokenfixture',
        'extra' => ['flexipos' => ['provider' => QA_BROKEN_NAMESPACE.'Provider']],
    ]));

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'qabrokenfixture')
        ->and(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'declares no autoload.psr-4 namespace');
});

it('fails fast when a module names no provider class', function () {
    /** @var TestCase $this */
    qaBrokenPackage('qabrokenfixture', (string) json_encode([
        'name' => 'flexipos/module-qabrokenfixture',
        'autoload' => ['psr-4' => [QA_BROKEN_NAMESPACE => 'src/']],
    ]));

    expect(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'qabrokenfixture')
        ->and(fn () => $this->bootHostWith('qabrokenfixture'))
        ->toThrow(UnknownModuleException::class, 'declares no extra.flexipos.provider class');
});

it('fails fast when the named provider class cannot be autoloaded', function () {
    /** @var TestCase $this */
    // Correctly scoped on purpose: everything about this manifest is legal
    // except that the class is not on disk, so the failure can only come from
    // the class_exists() branch this case is named after. With a foreign
    // namespace it would stop at the AC-2 check instead and still pass.
    qaBrokenPackage('qabrokenfixture', (string) json_encode([
        'name' => 'flexipos/module-qabrokenfixture',
        'autoload' => ['psr-4' => [QA_BROKEN_NAMESPACE => 'src/']],
        'extra' => ['flexipos' => ['provider' => QA_BROKEN_NAMESPACE.'NotOnDisk']],
    ]));

    $thrown = null;

    try {
        $this->bootHostWith('qabrokenfixture');
    } catch (UnknownModuleException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(UnknownModuleException::class)
        ->and($thrown?->module())->toBe('qabrokenfixture')
        ->and($thrown?->getMessage())->toContain(QA_BROKEN_NAMESPACE.'NotOnDisk')
        ->and($thrown?->getMessage())->toContain('could not be autoloaded')
        // Not the namespace or provider-prefix check: those are AC-2 and AC-3
        // and are proven in QaManifestTrustAttackTest.
        ->and($thrown?->getMessage())->not->toContain('may only declare')
        ->and($thrown?->getMessage())->not->toContain('outside its own namespace');
});

it('names the offending module in the exception rather than the first one in the list', function () {
    /** @var TestCase $this */
    $thrown = null;

    try {
        $this->bootHostWith('demo,ghost');
    } catch (UnknownModuleException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(UnknownModuleException::class)
        ->and($thrown?->module())->toBe('ghost')
        ->and($thrown?->getMessage())->toContain('MODULES');
});

it('keeps the booted list sorted and free of duplicates whatever order MODULES uses', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->parse('zulu,alpha,mike,alpha'))->toBe(['alpha', 'mike', 'zulu'])
        ->and($registry->parse("\tdemo\n"))->toBe(['demo'])
        ->and($registry->parse(' , , '))->toBe([]);
});

it('serves liveness without a modules key so the two health endpoints stay distinct', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    $live = get('/health/live')->assertOk();

    expect($live->json())->toBe(['status' => 'ok'])
        ->and($live->headers->get('content-type'))->toContain('application/json');

    $ready = get('/health/ready')->assertOk();

    expect($ready->json())->toBe(['status' => 'ok', 'modules' => ['demo']]);
});
