<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\RegisterPluginVersion;

use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 7 - plugin:register, exit codes and output
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

function pluginSdkFixtureEchoPathForCommandTest(): string
{
    return __DIR__.'/../../Fixtures/FixtureEcho';
}

beforeEach(function () {
    PluginSeed::migrate();
    app()->register(new FixtureHookPointProvider(app()), true);
});

it('exits 0 and reports the registered version', function () {
    $exitCode = Artisan::call('plugin:register', ['path' => pluginSdkFixtureEchoPathForCommandTest()]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('fixture-echo 1.0.0');
});

it('exits 1 and reports the refusal on a second registration', function () {
    Artisan::call('plugin:register', ['path' => pluginSdkFixtureEchoPathForCommandTest()]);

    $exitCode = Artisan::call('plugin:register', ['path' => pluginSdkFixtureEchoPathForCommandTest()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('versionAlreadyRegistered');
});
