<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Pipeline;

/*
|--------------------------------------------------------------------------
| T-019 step 13 - the proof-of-pipeline fixture (AC-44, AC-28 on the
| stand-in)
|--------------------------------------------------------------------------
|
| fixture-echo is a proof-of-pipeline fixture, not a reference plugin
| (D-13, AC-45): the first and only real, business-meaningful plugin is
| FP-296's, Phase 6. This test proves the mechanism end to end, through
| the console commands, on the deterministic stand-in event bus - no real
| Redis connection is reached or required (AC-28 stays NOT VERIFIED on
| the real engine; T-018 D-1).
*/

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
    app()->register(new FixtureHookPointProvider(app()), true);
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('AC-44: writes, enables, vetoes, overrides, filters, adds, writes one outbox row, drains it idempotently, and lists it', function () {
    $tenant = pluginRegisterFixtureTenant('tenant-a');

    // 1. plugin:register
    expect(Artisan::call('plugin:register', ['path' => __DIR__.'/../Fixtures/FixtureEcho']))->toBe(0);

    // 2. plugin:enable
    expect(Artisan::call('plugin:enable', ['plugin' => 'fixture-echo', '--tenant' => $tenant]))->toBe(0);

    pluginEnterTenant('tenant-a');

    // 3. before vetoes
    $vetoedOutcome = app(HookGateway::class)->dispatch('fixture.echo', ['veto' => true], fn (array $p) => 'action');

    expect($vetoedOutcome->vetoed())->toBeTrue()
        ->and($vetoedOutcome->veto->plugin)->toBe('fixture-echo')
        ->and($vetoedOutcome->veto->reason)->toContain('refuses');

    // 4. override, two chained filters, one add contribution, one outbox row
    $outcome = app(HookGateway::class)->dispatch('fixture.echo', ['text' => 'ab'], fn (array $p) => 'action-result');

    expect($outcome->overriddenBy)->toBe('fixture-echo')
        ->and($outcome->result)->toBe(['overridden_by' => 'fixture-echo', 'payload' => ['text' => 'ABx']])
        ->and($outcome->contributions)->toHaveCount(1)
        ->and($outcome->contributions[0]->value)->toBe('added-by-fixture-echo');

    $connection = DB::connection(AccessTenantFixture::connectionName());
    expect($connection->table('outbox_events')->count())->toBe(1);

    tenancy()->leave();

    // 5. eventbus:relay then eventbus:consume
    expect(Artisan::call('eventbus:relay', ['--once' => true, '--tenant' => ['tenant-a']]))->toBe(0);
    expect(Artisan::call('eventbus:consume', ['--group' => ['plugins.after'], '--once' => true]))->toBe(0);

    pluginEnterTenant('tenant-a');
    expect($connection->table('plg_fixture_echo_after_effects')->count())->toBe(1);
    tenancy()->leave();

    // 6. a simulated redelivery leaves one marker
    expect(Artisan::call('eventbus:consume', ['--group' => ['plugins.after'], '--once' => true]))->toBe(0);

    pluginEnterTenant('tenant-a');
    expect($connection->table('plg_fixture_echo_after_effects')->count())->toBe(1);
    tenancy()->leave();

    // 7. plugin:list
    Artisan::call('plugin:list', ['--tenant' => $tenant]);
    $output = Artisan::output();

    expect($output)->toContain('fixture-echo')
        ->and($output)->toContain('enabled')
        ->and(PluginSeed::tenantPluginRow($tenant, PluginSeed::pluginRow('fixture-echo')['id'])['failure_count'])->toBe(0);
});
