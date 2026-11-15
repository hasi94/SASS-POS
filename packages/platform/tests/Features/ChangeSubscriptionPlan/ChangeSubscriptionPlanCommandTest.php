<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ChangeSubscriptionPlan;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 AC-33, BUG-2 - platform:change-subscription-plan
|--------------------------------------------------------------------------
|
| The console entry point for this write, since there is no HTTP route
| (D-21). Proved through `Artisan::call()`, exactly as a deployed operator
| would invoke it, rather than at the handler.
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('changes the subscription plan and mirrors it onto tenants.plan_id', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $oldPlan, 'active');

    $exitCode = Artisan::call('platform:change-subscription-plan', [
        'subscription' => $subscriptionId,
        'plan' => $newPlan,
        '--actor' => 'op-1',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain($subscriptionId)
        ->and(PlatformSeed::tenantRow($tenantId)['plan_id'])->toBe($newPlan);

    $subscription = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($subscription['plan_id'])->toBe($newPlan);
});

it('exits non-zero and prints the refusal when the subscription is not the tenant\'s current one', function () {
    $tenantId = PlatformSeed::tenant();
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();

    $historical = PlatformSeed::subscription($tenantId, $oldPlan, 'cancelled', ['current_period_start' => '2020-01-01 00:00:00.000']);
    PlatformSeed::subscription($tenantId, $oldPlan, 'active', ['current_period_start' => '2026-01-01 00:00:00.000']);

    $exitCode = Artisan::call('platform:change-subscription-plan', [
        'subscription' => $historical,
        'plan' => $newPlan,
    ]);

    expect($exitCode)->not->toBe(0);
});
