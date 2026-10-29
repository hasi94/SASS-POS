<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Support\TenantStatusSync;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 review W-8 - the stale suspension_reason, when the cause changes
| but the status stays suspended
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('updates suspension_reason and writes one audit row when a second sentinel cause supersedes the first, even though the status stays suspended', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'suspended', ['suspension_reason' => 'licence_lapsed']);
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'active');

    // The subscription is cancelled directly on the row - the reconciler
    // reads it fresh, so this reproduces "a subscription is also cancelled
    // while already suspended for a licence" without going through a
    // second handler.
    PlatformSeed::connection()->table('subscriptions')->where('tenant_id', $tenantId)->update(['status' => 'cancelled']);

    $trigger = app(TenantStatusSync::class)->reconcile(PlatformSeed::connection(), $tenantId, null);

    expect($trigger)->toBe('subscription_lapsed');

    $tenant = PlatformSeed::tenantRow($tenantId);

    expect($tenant['status'])->toBe('suspended')
        ->and($tenant['suspension_reason'])->toBe('subscription_lapsed')
        ->and(PlatformSeed::auditRows('tenant_status_synced'))->toHaveCount(1);
});

it('is a no-op once the reason already matches the live cause', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'suspended', ['suspension_reason' => 'subscription_lapsed']);
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'cancelled');

    $trigger = app(TenantStatusSync::class)->reconcile(PlatformSeed::connection(), $tenantId, null);

    expect($trigger)->toBeNull()
        ->and(PlatformSeed::auditRows('tenant_status_synced'))->toHaveCount(0);
});

it('never touches a manually-suspended tenant, even when a sentinel cause is also present', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'suspended', ['suspension_reason' => 'operator_decision']);
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'cancelled');

    $trigger = app(TenantStatusSync::class)->reconcile(PlatformSeed::connection(), $tenantId, null);

    expect($trigger)->toBeNull()
        ->and(PlatformSeed::tenantRow($tenantId)['suspension_reason'])->toBe('operator_decision')
        ->and(PlatformSeed::auditRows('tenant_status_synced'))->toHaveCount(0);
});
