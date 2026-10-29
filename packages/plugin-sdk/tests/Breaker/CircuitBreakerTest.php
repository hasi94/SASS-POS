<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Breaker;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Breaker\CircuitBreaker;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePlugin;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginHandler;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersion;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionHandler;
use Flexipos\PluginSdk\Features\ResetPluginBreaker\ResetPluginBreaker;
use Flexipos\PluginSdk\Features\ResetPluginBreaker\ResetPluginBreakerHandler;
use Flexipos\PluginSdk\Gateway\HookClock;
use Flexipos\PluginSdk\Tests\Support\FakeHookClock;
use Flexipos\PluginSdk\Tests\Support\FixtureHookPointProvider;
use Flexipos\PluginSdk\Tests\Support\PluginSeed;
use Flexipos\PluginSdk\Tests\Support\ThrowingHook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

require_once __DIR__.'/../Support/plugin-fixture.php';

/*
|--------------------------------------------------------------------------
| T-019 step 11 - the circuit breaker (AC-30 to AC-33, plan Ruling 12)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

final class CircuitBreakerRecordingLogger extends NullLogger
{
    /** @var list<string> */
    public array $levels = [];

    /**
     * @param  mixed  $level
     * @param  string|\Stringable  $message
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->levels[] = (string) $level;
    }
}

beforeEach(function () {
    PluginSeed::migrate();
    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * A bare tenant_plugins row at `enabled`, for direct CircuitBreaker unit
 * assertions - no manifest, no handler, no gateway involved.
 *
 * @return array{tenant: string, pluginId: string, name: string}
 */
function breakerRow(string $tenantId = 'tenant-a'): array
{
    $tenant = pluginRegisterFixtureTenant($tenantId);

    $tmp = sys_get_temp_dir().'/plugin-sdk-breaker-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-breaker-'.bin2hex(random_bytes(3));
    $manifest['hooks'] = [];
    File::put($tmp.'/plugin.json', json_encode($manifest));
    $registered = app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));
    app(EnablePluginHandler::class)->handle(new EnablePlugin($manifest['name'], $tenant));
    File::deleteDirectory($tmp);

    return ['tenant' => $tenant, 'pluginId' => $registered->pluginId, 'name' => $manifest['name']];
}

it('counts consecutive failures, and a success resets the count (AC-30)', function () {
    $row = breakerRow();
    $breaker = app(CircuitBreaker::class);

    $breaker->recordFailure($row['tenant'], $row['pluginId'], 'e1');
    $breaker->recordFailure($row['tenant'], $row['pluginId'], 'e2');
    $breaker->recordFailure($row['tenant'], $row['pluginId'], 'e3');

    expect(PluginSeed::tenantPluginRow($row['tenant'], $row['pluginId'])['failure_count'])->toBe(3);

    $breaker->recordSuccess($row['tenant'], $row['pluginId']);

    expect(PluginSeed::tenantPluginRow($row['tenant'], $row['pluginId'])['failure_count'])->toBe(0);
});

it('trips on exactly the fifth consecutive failure, not the fourth (AC-31)', function () {
    $row = breakerRow();
    $breaker = app(CircuitBreaker::class);

    $tripped = false;
    for ($i = 1; $i <= 5; $i++) {
        $tripped = $breaker->recordFailure($row['tenant'], $row['pluginId'], "e{$i}");

        if ($i < 5) {
            expect($tripped)->toBeFalse();
        }
    }

    expect($tripped)->toBeTrue();

    $tripRow = PluginSeed::tenantPluginRow($row['tenant'], $row['pluginId']);
    expect($tripRow['status'])->toBe('error')
        ->and($tripRow['tripped_at'])->not->toBeNull();
});

it('logs exactly one critical entry for the trip', function () {
    $logger = new CircuitBreakerRecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    $row = breakerRow();
    $breaker = app(CircuitBreaker::class);

    for ($i = 1; $i <= 5; $i++) {
        $breaker->recordFailure($row['tenant'], $row['pluginId'], "e{$i}");
    }

    expect(array_filter($logger->levels, fn (string $l) => $l === 'critical'))->toHaveCount(1);
});

it('isolates one tripped (tenant, plugin) pair from another plugin and another tenant (AC-32)', function () {
    $rowA = breakerRow('tenant-a');
    $rowOther = breakerRow('tenant-a');
    $rowB = breakerRow('tenant-b');
    $breaker = app(CircuitBreaker::class);

    for ($i = 1; $i <= 5; $i++) {
        $breaker->recordFailure($rowA['tenant'], $rowA['pluginId'], "e{$i}");
    }

    expect(PluginSeed::tenantPluginRow($rowA['tenant'], $rowA['pluginId'])['status'])->toBe('error')
        ->and(PluginSeed::tenantPluginRow($rowOther['tenant'], $rowOther['pluginId'])['status'])->toBe('enabled')
        ->and(PluginSeed::tenantPluginRow($rowB['tenant'], $rowB['pluginId'])['status'])->toBe('enabled')
        ->and(PluginSeed::tenantPluginRow($rowB['tenant'], $rowB['pluginId'])['failure_count'])->toBe(0);
});

it('is only returned to enabled by plugin:reset, not by time passing (AC-33)', function () {
    $row = breakerRow();
    $breaker = app(CircuitBreaker::class);

    for ($i = 1; $i <= 5; $i++) {
        $breaker->recordFailure($row['tenant'], $row['pluginId'], "e{$i}");
    }

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-06-01 00:00:00.000'));
    $farFuture = RunnerClock::fixedAt('2026-07-01 00:00:00.000');
    app()->instance(RunnerClock::class, $farFuture);

    expect(PluginSeed::tenantPluginRow($row['tenant'], $row['pluginId'])['status'])->toBe('error');

    app(ResetPluginBreakerHandler::class)->handle(new ResetPluginBreaker($row['name'], $row['tenant']));

    $reset = PluginSeed::tenantPluginRow($row['tenant'], $row['pluginId']);
    expect($reset['status'])->toBe('enabled')
        ->and($reset['failure_count'])->toBe(0)
        ->and($reset['tripped_at'])->toBeNull()
        ->and($reset['last_error'])->toBeNull();
});

it('refuses resetting a row that is not tripped', function () {
    $row = breakerRow();

    expect(fn () => app(ResetPluginBreakerHandler::class)->handle(new ResetPluginBreaker($row['name'], $row['tenant'])))
        ->toThrow(PluginRefused::class);
});

it('still counts a failure when the caller`s tenant transaction rolls back', function () {
    $row = breakerRow();
    $breaker = app(CircuitBreaker::class);

    pluginEnterTenant($row['tenant']);

    // The breaker writes through its own master connection, never the
    // tenant connection a dispatch's own transaction runs on - so a
    // caller's tenant transaction rolling back cannot undo this.
    try {
        DB::connection(AccessTenantFixture::connectionName())->transaction(function () use ($breaker, $row) {
            $breaker->recordFailure($row['tenant'], $row['pluginId'], 'inside a rolled-back caller');

            throw new \RuntimeException('force rollback');
        });
    } catch (\RuntimeException) {
        // expected
    }

    expect(PluginSeed::tenantPluginRow($row['tenant'], $row['pluginId'])['failure_count'])->toBe(1);
});

it('trips end-to-end through the gateway, refuses the next dispatch, and still runs the action', function () {
    app()->register(new FixtureHookPointProvider(app()), true);
    app()->instance(HookClock::class, new FakeHookClock);

    $tmp = sys_get_temp_dir().'/plugin-sdk-breaker-e2e-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($tmp.'/migrations');
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/FixtureEcho/plugin.json'), true);
    $manifest['name'] = 'fixture-throws-everywhere';
    $manifest['hooks'] = [
        ['point' => 'fixture.echo', 'mode' => 'before', 'handler' => ThrowingHook::class],
    ];
    File::put($tmp.'/plugin.json', json_encode($manifest));
    app(RegisterPluginVersionHandler::class)->handle(new RegisterPluginVersion($tmp));

    $tenant = pluginRegisterFixtureTenant('tenant-a');
    app(EnablePluginHandler::class)->handle(new EnablePlugin('fixture-throws-everywhere', $tenant));

    pluginEnterTenant('tenant-a');

    ThrowingHook::reset();

    for ($i = 0; $i < 5; $i++) {
        app(HookGateway::class)->dispatch('fixture.echo', []);
    }

    expect(ThrowingHook::$callCount)->toBe(5);

    $pluginId = PluginSeed::pluginRow('fixture-throws-everywhere')['id'];
    expect(PluginSeed::tenantPluginRow($tenant, $pluginId)['status'])->toBe('error');

    $outcome = app(HookGateway::class)->dispatch('fixture.echo', [], fn (array $p) => 'action-still-ran');

    expect(ThrowingHook::$callCount)->toBe(5)
        ->and($outcome->skipped)->toContain('fixture-throws-everywhere')
        ->and($outcome->result)->toBe('action-still-ran');

    File::deleteDirectory($tmp);
});
