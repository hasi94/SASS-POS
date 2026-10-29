<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\GrantPluginAccess;

use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\GrantPluginAccess\GrantPluginAccess;
use Flexipos\PluginSdk\Features\GrantPluginAccess\GrantPluginAccessHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 7 - GrantPluginAccessHandler (AC-11)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    app()->register(new FixtureHookPointProvider(app()), true);
});

function grantFixtureEchoPath(): string
{
    return __DIR__.'/../../Fixtures/FixtureEcho';
}

it('inserts a grant, and a repeat is a no-op', function () {
    // fixture-echo itself is visibility=official, so a temporary private
    // variant is registered for this test.
    $tmp = sys_get_temp_dir().'/plugin-sdk-fixture-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(grantFixtureEchoPath().'/plugin.json'), true);
    $manifest['name'] = 'fixture-private';
    $manifest['visibility'] = 'private';
    $manifest['hooks'] = [];
    File::put($tmp.'/plugin.json', json_encode($manifest));

    try {
        app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));
        $tenant = PluginSeed::tenant();

        $first = app(GrantPluginAccessHandler::class)->handle(new GrantPluginAccess('fixture-private', $tenant));
        expect($first->alreadyGranted)->toBeFalse();

        expect(PluginSeed::connection()->table('plugin_tenant_access')->count())->toBe(1);

        $second = app(GrantPluginAccessHandler::class)->handle(new GrantPluginAccess('fixture-private', $tenant));
        expect($second->alreadyGranted)->toBeTrue();

        expect(PluginSeed::connection()->table('plugin_tenant_access')->count())->toBe(1);
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('refuses granting a non-private plugin', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(grantFixtureEchoPath()));
    $tenant = PluginSeed::tenant();

    try {
        app(GrantPluginAccessHandler::class)->handle(new GrantPluginAccess('fixture-echo', $tenant));
        throw new RuntimeException('Expected PluginRefused.');
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('notPrivate');
    }

    expect(PluginSeed::connection()->table('plugin_tenant_access')->count())->toBe(0);
});

it('refuses an unknown plugin', function () {
    expect(fn () => app(GrantPluginAccessHandler::class)->handle(new GrantPluginAccess('no-such-plugin', PluginSeed::tenant())))
        ->toThrow(PluginRefused::class);
});
