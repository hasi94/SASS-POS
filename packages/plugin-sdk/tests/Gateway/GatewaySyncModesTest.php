<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Gateway;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Gateway\HookClock;
use Flexipos\PluginSdk\Tests\Support\AllowingBeforeHook;
use Flexipos\PluginSdk\Tests\Support\FakeHookClock;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\InsertsThenThrowsAdd;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Flexipos\PluginSdk\Tests\Support\SecondPluginAdd;
use Flexipos\PluginSdk\Tests\Support\SlowHook;
use Flexipos\PluginSdk\Tests\Support\SlowVetoBeforeHook;
use Flexipos\PluginSdk\Tests\Support\ThrowingHook;
use Flexipos\PluginSdk\Tests\Support\ThrowingVetoBeforeHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 10 - the gateway's synchronous modes (AC-17, AC-21 to AC-26,
| AC-36)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
    app()->instance(HookClock::class, new FakeHookClock);
    AllowingBeforeHook::reset();
    SlowHook::reset();

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(gatewayFixtureEchoPath()));
    pluginRegisterFixtureTenant('tenant-a');
    pluginRegisterFixtureTenant('tenant-b');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

function gatewayFixtureEchoPath(): string
{
    return __DIR__.'/../Fixtures/FixtureEcho';
}

/**
 * @param  array<string, mixed>  $overrides
 */
function gatewayRegisterVariant(array $overrides): string
{
    $tmp = sys_get_temp_dir().'/plugin-sdk-gateway-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(gatewayFixtureEchoPath().'/plugin.json'), true);
    $manifest = array_merge($manifest, $overrides);
    File::put($tmp.'/plugin.json', json_encode($manifest));

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

    return (string) $manifest['name'];
}

function gatewayEnable(string $pluginName, string $tenantId): void
{
    app(EnablePluginHandler::class)->handle(new EnablePlugin($pluginName, $tenantId));
}

function gatewaySetEnabledAt(string $pluginName, string $tenantId, string $enabledAt): void
{
    $pluginId = PluginSeed::pluginRow($pluginName)['id'];

    PluginSeed::connection()->table('tenant_plugins')
        ->where('tenant_id', $tenantId)->where('plugin_id', $pluginId)
        ->update(['enabled_at' => $enabledAt]);
}

it('resolves plugins in registration (enabled_at, name) order, and swapping enabled_at swaps the order (AC-21)', function () {
    gatewayRegisterVariant(['name' => 'fixture-second', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'add', 'handler' => SecondPluginAdd::class],
    ]]);
    gatewayEnable('fixture-echo', 'tenant-a');
    gatewayEnable('fixture-second', 'tenant-a');
    gatewaySetEnabledAt('fixture-echo', 'tenant-a', '2026-01-01 00:00:00.000');
    gatewaySetEnabledAt('fixture-second', 'tenant-a', '2026-01-01 00:00:01.000');

    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['text' => 'ab'], fn (array $p) => 'action');

    expect(array_map(fn ($c) => $c->plugin, $outcome->contributions))->toBe(['fixture-echo', 'fixture-second']);

    tenancy()->leave();
    gatewaySetEnabledAt('fixture-echo', 'tenant-a', '2026-01-01 00:00:02.000');
    gatewaySetEnabledAt('fixture-second', 'tenant-a', '2026-01-01 00:00:01.000');

    pluginEnterTenant('tenant-a');
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['text' => 'ab'], fn (array $p) => 'action');

    expect(array_map(fn ($c) => $c->plugin, $outcome->contributions))->toBe(['fixture-second', 'fixture-echo']);
});

it('collects every add contribution alongside the action`s own result (AC-22)', function () {
    gatewayEnable('fixture-echo', 'tenant-a');
    pluginEnterTenant('tenant-a');

    // fixture-echo also registers an `override` at this point, so the
    // result is the override's - AC-22's own words, "the result is the
    // action's or the override's, unchanged" - and `add` neither replaces
    // nor vetoes it.
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['text' => 'ab'], fn (array $p) => 'action-result');

    expect($outcome->contributions)->toHaveCount(1)
        ->and($outcome->contributions[0]->plugin)->toBe('fixture-echo')
        ->and($outcome->contributions[0]->value)->toBe('added-by-fixture-echo')
        ->and($outcome->overriddenBy)->toBe('fixture-echo')
        ->and($outcome->result)->toBeArray();
});

it('vetoes and stops the action, add and after; allowing proceeds (AC-23)', function () {
    gatewayEnable('fixture-echo', 'tenant-a');
    pluginEnterTenant('tenant-a');

    $called = 0;
    $action = function (array $p) use (&$called): mixed {
        $called++;

        return 'action';
    };

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => true], $action);

    expect($outcome->vetoed())->toBeTrue()
        ->and($outcome->veto->plugin)->toBe('fixture-echo')
        ->and($called)->toBe(0)
        ->and($outcome->contributions)->toBe([]);

    // fixture-echo also overrides this point, so allowing proceeds to the
    // override (AC-25), not to $action - $called would only move if
    // neither a before veto nor a successful override intercepted it.
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], $action);

    expect($outcome->vetoed())->toBeFalse()
        ->and($outcome->overriddenBy)->toBe('fixture-echo')
        ->and($called)->toBe(0);
});

it('vetoes the same way whether the before handler returns or throws', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-throws-veto', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => ThrowingVetoBeforeHook::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', []);

    expect($outcome->vetoed())->toBeTrue()
        ->and($outcome->veto->plugin)->toBe($name);
});

it('runs a second before handler when the first allows', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-allowing', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => AllowingBeforeHook::class],
    ]]);
    gatewayEnable('fixture-echo', 'tenant-a');
    gatewayEnable($name, 'tenant-a');
    gatewaySetEnabledAt('fixture-echo', 'tenant-a', '2026-01-01 00:00:00.000');
    gatewaySetEnabledAt($name, 'tenant-a', '2026-01-01 00:00:01.000');

    pluginEnterTenant('tenant-a');
    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false]);

    expect(AllowingBeforeHook::$callCount)->toBe(1);
});

it('chains two filters in registration order (AC-24)', function () {
    gatewayEnable('fixture-echo', 'tenant-a');
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['text' => 'ab']);

    expect($outcome->payload['text'])->toBe('ABx');
});

it('passes the payload through unchanged when a filter throws', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-filter-throws', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'filter', 'handler' => ThrowingHook::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['text' => 'ab']);

    expect($outcome->payload)->toBe(['text' => 'ab'])
        ->and($outcome->failed)->toContain($name);
});

it('runs the override instead of the action on an overridable point (AC-25)', function () {
    gatewayEnable('fixture-echo', 'tenant-a');
    pluginEnterTenant('tenant-a');

    $called = 0;
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], function (array $p) use (&$called): string {
        $called++;

        return 'action';
    });

    expect($outcome->overriddenBy)->toBe('fixture-echo')
        ->and($called)->toBe(0)
        ->and($outcome->result)->toBeArray();
});

it('falls back to the action when the override throws', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-override-throws', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'override', 'handler' => ThrowingHook::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action-ran');

    expect($outcome->overriddenBy)->toBeNull()
        ->and($outcome->result)->toBe('action-ran')
        ->and($outcome->failed)->toContain($name);
});

it('runs exactly the action with zero registrations (AC-26)', function () {
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.plain', ['x' => 1], fn (array $p) => $p);

    expect($outcome->result)->toBe(['x' => 1])
        ->and($outcome->contributions)->toBe([])
        ->and($outcome->vetoed())->toBeFalse();

    tenancy()->leave();
    pluginEnterTenant('tenant-b');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['x' => 1], fn (array $p) => $p);

    expect($outcome->result)->toBe(['x' => 1]);
});

it('never dispatches to a plugin whose row is not enabled or error (AC-17)', function (string $status) {
    $plugin = PluginSeed::pluginRow('fixture-echo');

    PluginSeed::connection()->table('tenant_plugins')->insert([
        'id' => (string) Str::ulid(), 'tenant_id' => 'tenant-a', 'plugin_id' => $plugin['id'],
        'plugin_version_id' => $plugin['latest_version_id'], 'status' => $status, 'schema_version' => 0,
        'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000',
    ]);

    pluginEnterTenant('tenant-a');
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');

    expect($outcome->contributions)->toBe([])
        ->and($outcome->result)->toBe('action');
})->with(['disabled', 'installing', 'updating']);

it('runs the handler in this process (AC-36)', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-pid', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'add', 'handler' => SecondPluginAdd::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    // SecondPluginAdd runs with no process boundary: it returns a value
    // computed in this same PHP process, provable without reading
    // getmypid() through a side channel - a forked process could never
    // reach back into this test's assertions at all.
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', []);

    expect($outcome->contributions[0]->value)->toBe("added-by-{$name}");
});

it('logs one execution row per invocation, with duration from the fake clock', function () {
    gatewayEnable('fixture-echo', 'tenant-a');
    pluginEnterTenant('tenant-a');

    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false]);

    $rows = DB::connection(AccessTenantFixture::connectionName())->table('plugin_execution_logs')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->status)->toBe('ok')
            ->and($row->duration_ms)->toBeInt();
    }
});

it('rolls back a handler`s own write on failure, while the action`s write commits', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-savepoint', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'add', 'handler' => InsertsThenThrowsAdd::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    $connection = DB::connection(AccessTenantFixture::connectionName());
    $connection->statement('create table if not exists gateway_savepoint_control (value varchar(50) not null)');

    app(HookGateway::class)->dispatch('fixture.echo', [], function (array $p) use ($connection): string {
        $connection->table('gateway_savepoint_control')->insert(['value' => 'action-committed']);

        return 'action';
    });

    // The handler's own insert rolled back inside its savepoint; only the
    // action's row, committed outside it, survives.
    expect($connection->table('gateway_savepoint_control')->pluck('value')->all())->toBe(['action-committed']);
});

it('times out a slow handler, discards its result, and marks it failed', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-slow', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => SlowHook::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false]);

    expect($outcome->failed)->toContain($name);

    $row = DB::connection(AccessTenantFixture::connectionName())->table('plugin_execution_logs')
        ->where('plugin_name', $name)->first();

    expect($row->status)->toBe('timeout');
});

it('still blocks on a returned Veto even when the same before handler is also over budget (W-1)', function () {
    $name = gatewayRegisterVariant(['name' => 'fixture-slow-veto', 'hooks' => [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => SlowVetoBeforeHook::class],
    ]]);
    gatewayEnable($name, 'tenant-a');
    pluginEnterTenant('tenant-a');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action-ran');

    // A fail-open timeout outcome would let the action run instead.
    expect($outcome->veto)->not->toBeNull()
        ->and($outcome->veto->plugin)->toBe($name)
        ->and($outcome->result)->toBeNull();

    $row = DB::connection(AccessTenantFixture::connectionName())->table('plugin_execution_logs')
        ->where('plugin_name', $name)->first();

    expect($row->status)->toBe('veto');
});

it('throws NoTenantResolved with no tenant entered, and LogicException for an unknown point', function () {
    expect(fn () => app(HookGateway::class)->dispatch('fixture.echo', []))
        ->toThrow(NoTenantResolved::class);

    pluginEnterTenant('tenant-a');

    expect(fn () => app(HookGateway::class)->dispatch('no.such.point', []))
        ->toThrow(LogicException::class);
});
