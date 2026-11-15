<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Runtime;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 10 - PluginRuntime (AC-17, AC-35, AC-37)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

final class PluginRuntimeRecordingLogger extends NullLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  string|\Stringable  $message
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
    pluginRegisterFixtureTenant('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('isolates a plugin whose stored manifest fails re-validation, logging once', function () {
    $logger = new PluginRuntimeRecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../Fixtures/FixtureEcho'));
    gatewayRuntimeEnable('fixture-echo', 'tenant-a');

    // Corrupt the stored manifest directly in the master, after the fact -
    // removing a required field so re-validation at load fails.
    $plugin = PluginSeed::pluginRow('fixture-echo');
    $manifest = json_decode((string) PluginSeed::connection()->table('plugin_versions')->where('id', $plugin['latest_version_id'])->value('manifest'), true);
    unset($manifest['core_constraint']);
    PluginSeed::connection()->table('plugin_versions')->where('id', $plugin['latest_version_id'])->update(['manifest' => json_encode($manifest)]);

    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');

    expect($outcome->contributions)->toBe([])
        ->and($outcome->result)->toBe('action');

    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');

    $errorLogs = array_filter($logger->records, fn (array $r) => $r['level'] === 'error');
    expect($errorLogs)->toHaveCount(1);
});

it('isolates a plugin whose stored manifest is well-formed but outside core_constraint', function () {
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../Fixtures/FixtureEcho'));
    gatewayRuntimeEnable('fixture-echo', 'tenant-a');

    config(['plugins.core_version' => '9.9.9']);

    pluginEnterTenant('tenant-a');
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');

    expect($outcome->contributions)->toBe([])
        ->and($outcome->result)->toBe('action');
});

it('loads only the earlier of two plugins forced to override the same point, and logs it', function () {
    $logger = new PluginRuntimeRecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    $registeredA = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../Fixtures/FixtureEcho'));

    $tmp = sys_get_temp_dir().'/plugin-sdk-runtime-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-echo-rival';
    File::put($tmp.'/plugin.json', json_encode($manifest));
    $registeredB = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

    $now = '2026-01-01 00:00:00.000';
    foreach ([[$registeredA, '2026-01-01 00:00:00.000'], [$registeredB, '2026-01-01 00:00:01.000']] as [$registered, $enabledAt]) {
        PluginSeed::connection()->table('tenant_plugins')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => 'tenant-a', 'plugin_id' => $registered->pluginId,
            'plugin_version_id' => $registered->versionId, 'status' => 'enabled', 'schema_version' => 0,
            'enabled_at' => $enabledAt, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    pluginEnterTenant('tenant-a');
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');

    expect($outcome->overriddenBy)->toBe('fixture-echo');

    $errorLogs = array_filter($logger->records, fn (array $r) => $r['level'] === 'error');
    expect($errorLogs)->not->toBe([]);

    File::deleteDirectory($tmp);
});

/**
 * Enable a plugin for a tenant with no migrations to run (empty hooks
 * would also work, but fixture-echo already ships two harmless ones).
 */
function gatewayRuntimeEnable(string $pluginName, string $tenantId): void
{
    app(EnablePluginHandler::class)->handle(new EnablePlugin($pluginName, $tenantId));
}
