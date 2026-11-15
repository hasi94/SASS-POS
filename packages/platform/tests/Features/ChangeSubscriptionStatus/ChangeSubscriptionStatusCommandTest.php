<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ChangeSubscriptionStatus;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 AC-33, BUG-2 - platform:change-subscription-status
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('changes the status and reconciles the tenant in the same call', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    $exitCode = Artisan::call('platform:change-subscription-status', [
        'subscription' => $subscriptionId,
        'status' => 'cancelled',
        '--actor' => 'op-1',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('subscription_lapsed');

    $subscription = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($subscription['status'])->toBe('cancelled')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');
});

it('exits non-zero and writes nothing when the status is unknown', function () {
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    $exitCode = Artisan::call('platform:change-subscription-status', [
        'subscription' => $subscriptionId,
        'status' => 'not-a-status',
    ]);

    expect($exitCode)->not->toBe(0);

    $subscription = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($subscription['status'])->toBe('active');
});
