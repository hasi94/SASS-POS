<?php

use App\Console\Commands\ModuleMakeCommand;
use App\Support\ModuleHost\ModuleRegistry;
use App\Support\ModuleHost\UnknownModuleException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

use function Pest\Laravel\get;

/*
 * QA tests for T-001 AC-22 / AC-28 robustness (run 1 BUG-2 and BUG-3, fixed in run 2).
 *
 * Before the fix, ModuleRegistry treated whatever MODULES contained as a
 * directory name and reported it verbatim from /health/ready, so the endpoint an
 * operator uses to "verify a given host process's configuration at a glance"
 * could list entries that were not modules and whose routes were not
 * mounted. Both the generator and the registry now validate against the single
 * pattern in ModuleRegistry::NAME_PATTERN (decision D-12).
 *
 * Note on the contract these tests encode: an invalid entry must make the host
 * fail fast, not be silently dropped. Decision D-1 is explicit that nothing in
 * MODULES may ever be skipped in silence, because a silently dropped entry is
 * exactly the typo'd-configuration bug D-1 exists to catch.
 */

afterEach(function () {
    deleteFixtureDirectory(modulesPath('qa-'));
    deleteFixtureDirectory(modulesPath('qa-name-fixture'));
});

it('refuses a MODULES entry that is not a plain module name', function () {
    /** @var TestCase $this */
    // modules/demo/../demo/composer.json resolves on every OS, so before the fix
    // the registry accepted this, registered the demo provider, and then reported
    // the raw string "demo/../demo" as a booted module identifier.
    expect(fn () => $this->bootHostWith('demo/../demo'))
        ->toThrow(UnknownModuleException::class, 'demo/../demo');
});

it('refuses a MODULES entry whose case does not match the directory on disk', function () {
    /** @var TestCase $this */
    // is_dir('modules/Demo') answers yes on Windows and no on Linux. Validating
    // the name and comparing against a directory listing makes the same MODULES
    // value behave identically in XAMPP development and in a Docker image.
    expect(fn () => $this->bootHostWith('Demo'))
        ->toThrow(UnknownModuleException::class, 'Demo');
});

it('fails the whole boot rather than dropping one bad entry out of several', function () {
    /** @var TestCase $this */
    expect(fn () => $this->bootHostWith('demo,demo/../demo'))
        ->toThrow(UnknownModuleException::class, 'demo/../demo');
});

it('never reports a module from /health/ready without a matching registered provider', function () {
    /** @var TestCase $this */
    $this->bootHostWith('demo');

    $booted = app(ModuleRegistry::class)->booted();
    $providers = array_keys(app()->getLoadedProviders());

    expect(get('/health/ready')->assertOk()->json('modules'))->toBe($booted);

    foreach ($booted as $name) {
        $matching = array_values(array_filter(
            $providers,
            fn (string $provider): bool => str_starts_with($provider, 'Flexipos\\Module\\'.Str::studly($name).'\\')
        ));

        expect($matching)->not->toBe([], "/health/ready reports [{$name}] but no provider of that module is registered");
    }
});

it('refuses a module name whose Composer package name would be invalid', function () {
    // "qa-" satisfied D-5's original pattern but yields the Composer package name
    // "flexipos/module-qa-", which Composer's own schema rejects, and namespace
    // Flexipos\Module\Qa\, which collides with a later `module:make qa`.
    expect(Artisan::call('module:make', ['name' => 'qa-']))->not->toBe(0)
        ->and(Artisan::output())->toContain('Invalid module name')
        ->and(File::exists(modulesPath('qa-')))->toBeFalse();
});

it('refuses doubled hyphens but still accepts a legitimate multi word name', function () {
    $before = File::directories(modulesPath());

    expect(Artisan::call('module:make', ['name' => 'qa--name']))->not->toBe(0)
        ->and(File::directories(modulesPath()))->toBe($before);

    expect(Artisan::call('module:make', ['name' => 'qa-name-fixture']))->toBe(0)
        ->and(File::exists(modulesPath('qa-name-fixture').'/composer.json'))->toBeTrue();

    $manifest = json_decode((string) File::get(modulesPath('qa-name-fixture').'/composer.json'), true);

    expect($manifest['name'])->toBe('flexipos/module-qa-name-fixture')
        ->and($manifest['autoload']['psr-4'])->toHaveKey('Flexipos\\Module\\QaNameFixture\\');
});

it('uses one shared definition of a valid module name for the generator and the registry', function () {
    $command = new ReflectionClass(ModuleMakeCommand::class);

    expect($command->getConstant('NAME_PATTERN'))->toBe(ModuleRegistry::NAME_PATTERN);

    foreach (['billing', 'http-client', 'a', 'a1-b2'] as $valid) {
        expect(preg_match(ModuleRegistry::NAME_PATTERN, $valid))->toBe(1, $valid);
    }

    foreach (['Billing', '1billing', '-billing', 'billing-', 'bill--ing', 'bill_ing', '', 'demo/../demo'] as $invalid) {
        expect(preg_match(ModuleRegistry::NAME_PATTERN, $invalid))->toBe(0, $invalid);
    }
});
