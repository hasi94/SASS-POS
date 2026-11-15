<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Features\RegisterPluginVersion;

use Flexipos\PluginSdk\Exceptions\ManifestRefused;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 7 - RegisterPluginVersionHandler (AC-8, AC-10, AC-11 data)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

function pluginSdkFixtureEchoPath(): string
{
    return __DIR__.'/../../Fixtures/FixtureEcho';
}

beforeEach(function () {
    PluginSeed::migrate();
    app()->register(new FixtureHookPointProvider(app()), true);
});

it('registers the fixture plugin, with one plugins row and one plugin_versions row (AC-8)', function () {
    $result = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(pluginSdkFixtureEchoPath()));

    expect($result->name)->toBe('fixture-echo')
        ->and($result->version)->toBe('1.0.0');

    $plugin = PluginSeed::pluginRow('fixture-echo');
    expect($plugin)->not->toBeNull()
        ->and($plugin['visibility'])->toBe('official')
        ->and($plugin['requires_licence'])->toBe(0)
        ->and($plugin['latest_version_id'])->toBe($result->versionId);

    $version = (array) PluginSeed::connection()->table('plugin_versions')->where('id', $result->versionId)->first();

    expect($version['status'])->toBe('available')
        ->and($version['migration_count'])->toBe(2)
        ->and($version['checksum'])->toBe(hash('sha256', (string) file_get_contents(pluginSdkFixtureEchoPath().'/plugin.json')))
        ->and(json_decode((string) $version['manifest'], true)['name'])->toBe('fixture-echo');

    expect(PluginSeed::connection()->table('plugins')->count())->toBe(1)
        ->and(PluginSeed::connection()->table('plugin_versions')->count())->toBe(1);
});

it('refuses registering the same plugin and version twice, with the rows and manifest unchanged', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(pluginSdkFixtureEchoPath()));

    $pluginsBefore = PluginSeed::connection()->table('plugins')->count();
    $versionsBefore = PluginSeed::connection()->table('plugin_versions')->count();
    $manifestBefore = PluginSeed::connection()->table('plugin_versions')->value('manifest');

    expect(fn () => app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(pluginSdkFixtureEchoPath())))
        ->toThrow(PluginRefused::class);

    try {
        app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(pluginSdkFixtureEchoPath()));
    } catch (PluginRefused $refused) {
        expect($refused->reason)->toBe('versionAlreadyRegistered');
    }

    expect(PluginSeed::connection()->table('plugins')->count())->toBe($pluginsBefore)
        ->and(PluginSeed::connection()->table('plugin_versions')->count())->toBe($versionsBefore)
        ->and(PluginSeed::connection()->table('plugin_versions')->value('manifest'))->toBe($manifestBefore);
});

it('moves latest_version_id to a higher version, and never backwards', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-fixture-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    File::copy(
        pluginSdkFixtureEchoPath().'/migrations/0001_create_plg_fixture_echo_after_effects_table.php',
        $tmp.'/migrations/0001_create_plg_fixture_echo_after_effects_table.php'
    );

    $manifest = json_decode((string) file_get_contents(pluginSdkFixtureEchoPath().'/plugin.json'), true);
    $manifest['hooks'] = [];
    $manifest['version'] = '1.1.0';
    File::put($tmp.'/plugin.json', json_encode($manifest));

    try {
        app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(pluginSdkFixtureEchoPath()));
        $v11 = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

        expect(PluginSeed::pluginRow('fixture-echo')['latest_version_id'])->toBe($v11->versionId);

        // 1.0.1, registered after 1.1.0, must not move latest_version_id backwards.
        $manifest['version'] = '1.0.1';
        File::put($tmp.'/plugin.json', json_encode($manifest));

        $v101 = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

        expect(PluginSeed::pluginRow('fixture-echo')['latest_version_id'])->toBe($v11->versionId)
            ->and(PluginSeed::pluginRow('fixture-echo')['latest_version_id'])->not->toBe($v101->versionId);
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('produces one plugin_dependencies row per requires entry', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-fixture-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');

    $manifest = json_decode((string) file_get_contents(pluginSdkFixtureEchoPath().'/plugin.json'), true);
    $manifest['name'] = 'fixture-needs-a';
    $manifest['hooks'] = [];
    $manifest['requires'] = ['fixture-echo' => '^1.0'];
    File::put($tmp.'/plugin.json', json_encode($manifest));

    try {
        $result = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

        $deps = PluginSeed::connection()->table('plugin_dependencies')->where('plugin_version_id', $result->versionId)->get();

        expect($deps)->toHaveCount(1)
            ->and($deps->first()->depends_on_plugin)->toBe('fixture-echo')
            ->and($deps->first()->version_constraint)->toBe('^1.0');
    } finally {
        File::deleteDirectory($tmp);
    }
});

it('refuses a core_constraint mismatch with zero rows written (AC-10)', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-fixture-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');

    $manifest = json_decode((string) file_get_contents(pluginSdkFixtureEchoPath().'/plugin.json'), true);
    $manifest['hooks'] = [];
    $manifest['core_constraint'] = '>=9.0';
    File::put($tmp.'/plugin.json', json_encode($manifest));

    try {
        expect(fn () => app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp)))
            ->toThrow(ManifestRefused::class);

        expect(PluginSeed::connection()->table('plugins')->count())->toBe(0)
            ->and(PluginSeed::connection()->table('plugin_versions')->count())->toBe(0);
    } finally {
        File::deleteDirectory($tmp);
    }
});
