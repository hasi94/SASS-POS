<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\GrantPluginAccess;

use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 7 - plugin:grant, exit codes and output
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    app()->register(new FixtureHookPointProvider(app()), true);
});

it('exits 0 and reports the grant', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-fixture-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-private-cmd';
    $manifest['visibility'] = 'private';
    $manifest['hooks'] = [];
    File::put($tmp.'/plugin.json', json_encode($manifest));

    try {
        app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));
        $tenant = PluginSeed::tenant();

        $exitCode = Artisan::call('plugin:grant', ['plugin' => 'fixture-private-cmd', '--tenant' => $tenant]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('Granted');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('exits 1 for an unknown plugin', function () {
    $exitCode = Artisan::call('plugin:grant', ['plugin' => 'no-such-plugin', '--tenant' => PluginSeed::tenant()]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('unknownPlugin');
});
