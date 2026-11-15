<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Gateway;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho\EchoAfter;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 12 - the gateway writes `after` outbox rows (AC-27)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../Fixtures/FixtureEcho'));
    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', $tenant));

    EchoAfter::reset();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * @return Collection<int, \stdClass>
 */
function afterModeOutboxRows(): Collection
{
    return DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->get();
}

it('writes one outbox row for fixture-echo, naming the point and carrying the plugin identity (AC-27)', function () {
    pluginEnterTenant('tenant-a');

    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');

    $rows = afterModeOutboxRows();
    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    $pluginId = PluginSeed::pluginRow('fixture-echo')['id'];

    expect($row->type)->toBe('plugin.fixture.echo')
        ->and($row->aggregate_type)->toBe('plugin')
        ->and($row->aggregate_id)->toBe($pluginId);

    $actor = json_decode((string) $row->actor, true);
    expect($actor['type'])->toBe('plugin')
        ->and($actor['id'])->toBe($pluginId)
        ->and($actor['name'])->toBe('fixture-echo');

    $payload = json_decode((string) $row->payload, true);
    expect($payload['plugin'])->toBe('fixture-echo')
        ->and($payload['point'])->toBe('fixture.echo');
});

it('never calls the after handler in-process', function () {
    pluginEnterTenant('tenant-a');

    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false]);

    expect(DB::connection(AccessTenantFixture::connectionName())->table('plg_fixture_echo_after_effects')->count())->toBe(0);
});

it('leaves zero outbox rows when the action throws, and one when it succeeds', function () {
    // fixture.plain, not fixture.echo: fixture-echo also overrides
    // fixture.echo, which would replace this test's own action before it
    // ever ran.
    $tmp = sys_get_temp_dir().'/plugin-sdk-after-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-after-plain';
    $manifest['hooks'] = [
        ['point' => 'fixture.plain', 'mode' => 'after', 'handler' => EchoAfter::class],
    ];
    File::put($tmp.'/plugin.json', json_encode($manifest));
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-after-plain', pluginRegisterFixtureTenant('tenant-a')));

    pluginEnterTenant('tenant-a');

    try {
        app(HookGateway::class)->dispatch('fixture.plain', [], function (): never {
            throw new RuntimeException('action failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(afterModeOutboxRows())->toHaveCount(0);

    app(HookGateway::class)->dispatch('fixture.plain', [], fn (array $p) => 'ok');

    expect(afterModeOutboxRows())->toHaveCount(1);

    File::deleteDirectory($tmp);
});

it('leaves zero outbox rows on a veto', function () {
    pluginEnterTenant('tenant-a');

    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => true]);

    expect(afterModeOutboxRows())->toHaveCount(0);
});

it('leaves zero outbox rows for a disabled plugin', function () {
    $tenant = pluginRegisterFixtureTenant('tenant-b');

    pluginEnterTenant('tenant-b');

    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false]);

    expect(afterModeOutboxRows())->toHaveCount(0);
});
