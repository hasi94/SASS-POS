<?php

use App\Support\ModuleHost\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

afterEach(function () {
    deleteFixtureDirectory(modulesPath('modulemakefixture'));
    deleteFixtureDirectory(modulesPath('module-make-fixture'));
});

it('refuses an invalid name, exits non-zero and creates nothing', function (string $name) {
    $before = File::directories(modulesPath());

    expect(Artisan::call('module:make', ['name' => $name]))->not->toBe(0)
        ->and(Artisan::output())->toContain('Invalid module name')
        ->and(File::directories(modulesPath()))->toBe($before);
})->with([
    'uppercase' => 'Billing',
    'spaces and punctuation' => 'Bad Name!',
    'leading digit' => '1billing',
    'leading hyphen' => '-billing',
    'underscore' => 'bill_ing',
    'empty' => '',
    // Decision D-12: a hyphen must separate two non-empty alphanumeric segments.
    'trailing hyphen' => 'billing-',
    'doubled hyphen' => 'bad--name',
    'hyphen only' => '-',
    // T-002 AC-4: PCRE's $ matches before a final newline, so without the D
    // modifier on the shared pattern the generator accepted this and created a
    // directory the registry could never boot.
    'trailing newline' => "billing\n",
]);

it('applies the identical name rule at generation time and at boot time', function (string $name) {
    expect(preg_match(ModuleRegistry::NAME_PATTERN, $name))->toBe(0)
        ->and(Artisan::call('module:make', ['name' => $name]))->not->toBe(0);
})->with(['Billing', '1billing', '-billing', 'bill_ing', 'billing-', 'bad--name', 'demo/../demo', "billing\n"]);

it('creates no directory at all for a name that ends in a newline', function () {
    expect(Artisan::call('module:make', ['name' => "billing\n"]))->not->toBe(0)
        ->and(Artisan::output())->toContain('Invalid module name')
        ->and(File::exists(modulesPath("billing\n")))->toBeFalse()
        ->and(File::exists(modulesPath('billing')))->toBeFalse();
});

it('still accepts a multi-word kebab-case name', function () {
    expect(preg_match(ModuleRegistry::NAME_PATTERN, 'demo'))->toBe(1)
        ->and(preg_match(ModuleRegistry::NAME_PATTERN, 'http-client'))->toBe(1)
        ->and(preg_match(ModuleRegistry::NAME_PATTERN, 'module-make-fixture'))->toBe(1);

    expect(Artisan::call('module:make', ['name' => 'module-make-fixture']))->toBe(0);

    $manifest = json_decode(File::get(modulesPath('module-make-fixture').'/composer.json'), true);

    expect($manifest['name'])->toBe('flexipos/module-module-make-fixture')
        ->and($manifest['extra']['flexipos']['provider'])
        ->toBe('Flexipos\\Module\\ModuleMakeFixture\\ModuleMakeFixtureServiceProvider');
});

it('refuses the reserved name host, exits non-zero and creates nothing', function () {
    $before = File::directories(modulesPath());

    expect(Artisan::call('module:make', ['name' => 'host']))->not->toBe(0)
        ->and(Artisan::output())->toContain('reserved name')
        ->and(File::directories(modulesPath()))->toBe($before);
});

it('refuses a name that already exists and leaves the existing package untouched', function () {
    expect(Artisan::call('module:make', ['name' => 'modulemakefixture']))->toBe(0);

    $provider = modulesPath('modulemakefixture').'/src/ModulemakefixtureServiceProvider.php';
    $marker = '// the existing package must not be touched';

    File::append($provider, $marker."\n");
    $before = File::get($provider);

    expect(Artisan::call('module:make', ['name' => 'modulemakefixture']))->not->toBe(0)
        ->and(Artisan::output())->toContain('already exists')
        ->and(File::get($provider))->toBe($before);
});

it('scaffolds the structure a module must have', function () {
    expect(Artisan::call('module:make', ['name' => 'modulemakefixture']))->toBe(0);

    $root = modulesPath('modulemakefixture');

    foreach ([
        'composer.json',
        // T-001 AC-25's "a database connection configuration entry named for the
        // module" is SUPERSEDED by T-003 AC-19/AC-20, not regressed: a module
        // declares no connection of its own and reaches business data through
        // the shared `tenant` connection. The directory survives as the
        // conventional place for a module's own configuration.
        'config/.gitkeep',
        'routes/api.php',
        'src/ModulemakefixtureServiceProvider.php',
        'src/Http/Controllers/PingController.php',
        'src/Consumers/.gitkeep',
        'src/Models/.gitkeep',
        'src/Services/.gitkeep',
        'src/Hooks/.gitkeep',
        'database/migrations/.gitkeep',
        'tests/Feature/PingRouteTest.php',
    ] as $relative) {
        expect(File::exists($root.DIRECTORY_SEPARATOR.$relative))->toBeTrue("missing {$relative}");
    }

    $manifest = json_decode(File::get($root.'/composer.json'), true);

    expect($manifest)->toBeArray()
        ->and($manifest['name'])->toBe('flexipos/module-modulemakefixture')
        ->and($manifest['autoload']['psr-4'])->toBe(['Flexipos\\Module\\Modulemakefixture\\' => 'src/'])
        ->and($manifest['extra']['flexipos']['provider'])->toBe('Flexipos\\Module\\Modulemakefixture\\ModulemakefixtureServiceProvider')
        // Superseded with the file above (T-003 AC-20): the manifest carries no
        // `connection` and no `schema` key, because there is no per-module
        // connection to name.
        ->and($manifest['extra']['flexipos'])->not->toHaveKey('connection')
        ->and($manifest['extra']['flexipos'])->not->toHaveKey('schema')
        ->and($manifest['extra'])->not->toHaveKey('laravel');
});

it('maps a hyphenated module name onto a StudlyCase namespace segment', function () {
    expect(Str::studly('http-client'))->toBe('HttpClient')
        ->and(Str::studly('plugin-sdk'))->toBe('PluginSdk');
});
