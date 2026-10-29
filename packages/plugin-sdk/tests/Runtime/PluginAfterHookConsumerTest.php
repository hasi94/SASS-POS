<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Runtime;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\Eventbus\Support\ConsumerRegistry;
use Flexipos\PluginSdk\Exceptions\PluginDispatchRefused;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Gateway\HookClock;
use Flexipos\PluginSdk\Runtime\PluginAfterHookConsumer;
use Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho\EchoAfter;
use Flexipos\PluginSdk\Tests\Support\FakeHookClock;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Flexipos\PluginSdk\Tests\Support\ThrowingHook;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 12 - PluginAfterHookConsumer (AC-27, AC-29, AC-34)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
    app()->instance(HookClock::class, new FakeHookClock);

    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion(__DIR__.'/../Fixtures/FixtureEcho'));
    pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-echo', pluginRegisterFixtureTenant('tenant-a')));

    EchoAfter::reset();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * @param  array<string, mixed>  $payload
 */
function pluginAfterEnvelope(string $eventId, string $tenantId, string $pluginId, string $pluginName, string $point, array $payload = [], mixed $result = null): EventEnvelope
{
    return new EventEnvelope(
        $eventId,
        $tenantId,
        'plugin.'.$point,
        1,
        'plugin',
        $pluginId,
        null,
        ['plugin' => $pluginName, 'plugin_version' => '1.0.0', 'point' => $point, 'payload' => $payload, 'result' => $result],
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::plugin($pluginId, $pluginName),
    );
}

it('ConsumerRegistry::groups() contains plugins.after', function () {
    expect(app(ConsumerRegistry::class)->groups())->toContain('plugins.after');
});

it('declares eventTypes() from the catalogue, not from who registered an after hook', function () {
    // fixture.narrow accepts only before, so it is absent; fixture.echo
    // and fixture.plain both accept after per FixtureHookPointProvider.
    expect(app(PluginAfterHookConsumer::class)->eventTypes())->toBe(['plugin.fixture.echo', 'plugin.fixture.plain']);
});

it('runs the handler once and writes the marker row, end to end through the real commands', function () {
    pluginEnterTenant('tenant-a');
    app(HookGateway::class)->dispatch('fixture.echo', ['veto' => false], fn (array $p) => 'action');
    tenancy()->leave();

    expect(Artisan::call('eventbus:relay', ['--once' => true, '--tenant' => ['tenant-a']]))->toBe(0);
    expect(Artisan::call('eventbus:consume', ['--group' => ['plugins.after'], '--once' => true]))->toBe(0);

    pluginEnterTenant('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('plg_fixture_echo_after_effects')->count())->toBe(1);
});

it('is idempotent by event id, and the control without the check gives two (AC-29)', function () {
    $pluginId = PluginSeed::pluginRow('fixture-echo')['id'];
    $envelope = pluginAfterEnvelope('evt-1', pluginRegisterFixtureTenant('tenant-a'), $pluginId, 'fixture-echo', 'fixture.echo');

    // ConsumeEventsHandler::handle() runs a consumer inside
    // Tenancy::run($envelope->tenantId, ...); this reproduces that scope
    // for the direct call.
    tenancy()->run('tenant-a', fn () => app(PluginAfterHookConsumer::class)->handle($envelope));
    tenancy()->run('tenant-a', fn () => app(PluginAfterHookConsumer::class)->handle($envelope));

    pluginEnterTenant('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('plg_fixture_echo_after_effects')->count())->toBe(1);
    tenancy()->leave();

    // The row count above cannot tell "the app-level check suppressed the
    // second call" apart from "the effect table's event_id primary key
    // silently ignored a duplicate insert regardless of any app check" -
    // both would land on the same count. $applyAttempts only increments
    // when execution gets past the check, so it is what actually proves
    // the app-level check ran and caught the duplicate.
    expect(EchoAfter::$applyAttempts)->toBe(1);

    EchoAfter::$skipIdempotencyCheck = true;

    $second = pluginAfterEnvelope('evt-2', pluginRegisterFixtureTenant('tenant-a'), $pluginId, 'fixture-echo', 'fixture.echo');
    tenancy()->run('tenant-a', fn () => app(PluginAfterHookConsumer::class)->handle($second));
    tenancy()->run('tenant-a', fn () => app(PluginAfterHookConsumer::class)->handle($second));

    pluginEnterTenant('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('plg_fixture_echo_after_effects')->count())->toBe(2);
    tenancy()->leave();

    // With the check skipped, both calls reach the marker - a count the
    // event_id primary key alone can never produce, since it would mask
    // this the same way it masks a removed app-level check.
    expect(EchoAfter::$applyAttempts)->toBe(3);
});

it('feeds the same breaker as synchronous failures, with no synchronous failure at all (AC-34)', function () {
    $tmp = sys_get_temp_dir().'/plugin-sdk-after-fail-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-after-fails';
    $manifest['hooks'] = [
        ['point' => 'fixture.plain', 'mode' => 'after', 'handler' => ThrowingHook::class],
    ];
    File::put($tmp.'/plugin.json', json_encode($manifest));
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-after-fails', pluginRegisterFixtureTenant('tenant-a')));

    $pluginId = PluginSeed::pluginRow('fixture-after-fails')['id'];

    for ($i = 1; $i <= 5; $i++) {
        try {
            tenancy()->run('tenant-a', fn () => app(PluginAfterHookConsumer::class)->handle(pluginAfterEnvelope("evt-{$i}", pluginRegisterFixtureTenant('tenant-a'), $pluginId, 'fixture-after-fails', 'fixture.plain')));
        } catch (RuntimeException) {
            // expected: redelivery applies
        }
    }

    expect(PluginSeed::tenantPluginRow(pluginRegisterFixtureTenant('tenant-a'), $pluginId)['status'])->toBe('error');

    File::deleteDirectory($tmp);
});

it('acknowledges a disabled plugin`s after hook with no call, and refuses a tripped one', function () {
    $pluginId = PluginSeed::pluginRow('fixture-echo')['id'];

    PluginSeed::connection()->table('tenant_plugins')->where('tenant_id', pluginRegisterFixtureTenant('tenant-a'))->where('plugin_id', $pluginId)
        ->update(['status' => 'disabled']);

    // No exception: the consumer acknowledges and does nothing.
    app(PluginAfterHookConsumer::class)->handle(pluginAfterEnvelope('evt-disabled', pluginRegisterFixtureTenant('tenant-a'), $pluginId, 'fixture-echo', 'fixture.echo'));

    pluginEnterTenant('tenant-a');
    expect(DB::connection(AccessTenantFixture::connectionName())->table('plg_fixture_echo_after_effects')->count())->toBe(0);
    tenancy()->leave();

    PluginSeed::connection()->table('tenant_plugins')->where('tenant_id', pluginRegisterFixtureTenant('tenant-a'))->where('plugin_id', $pluginId)
        ->update(['status' => 'error']);

    expect(fn () => app(PluginAfterHookConsumer::class)->handle(pluginAfterEnvelope('evt-tripped', pluginRegisterFixtureTenant('tenant-a'), $pluginId, 'fixture-echo', 'fixture.echo')))
        ->toThrow(PluginDispatchRefused::class);
});
