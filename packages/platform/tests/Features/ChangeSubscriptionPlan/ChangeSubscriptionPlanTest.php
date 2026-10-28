<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ChangeSubscriptionPlan;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlan;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlanHandler;
use Flexipos\Platform\Support\CachingEntitlementSource;
use Flexipos\Platform\Support\Entitlements;
use Flexipos\Platform\Support\EntitlementSource;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterEntitlementSource;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 8 - ChangeSubscriptionPlan (AC-15)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('writes the mirror onto tenants.plan_id', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $oldPlan, 'active');

    app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($subscriptionId, $newPlan));

    $subscription = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect(PlatformSeed::tenantRow($tenantId)['plan_id'])->toBe($newPlan)
        ->and($subscription['plan_id'])->toBe($newPlan);
});

it('refuses on a historical, non-current row', function () {
    $tenantId = PlatformSeed::tenant();
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();

    $historical = PlatformSeed::subscription($tenantId, $oldPlan, 'cancelled', ['current_period_start' => '2020-01-01 00:00:00.000']);
    PlatformSeed::subscription($tenantId, $oldPlan, 'active', ['current_period_start' => '2026-01-01 00:00:00.000']);

    expect(fn () => app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($historical, $newPlan)))
        ->toThrow(PlatformRefused::class);
});

it('evicts the cache after commit, and not when the transaction rolls back', function () {
    PlatformSeed::bindMasterSource();

    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();
    PlatformSeed::planLimit($newPlan, 'terminals', 30);
    $subscriptionId = PlatformSeed::subscription($tenantId, $oldPlan, 'active');

    $inner = new CachingEntitlementSource(app(MasterEntitlementSource::class), app('cache.store'), 300);

    $spy = new class($inner) implements EntitlementSource, EvictsEntitlements
    {
        public int $evictions = 0;

        public function __construct(private readonly CachingEntitlementSource $inner) {}

        public function for(string $tenantId): Entitlements
        {
            return $this->inner->for($tenantId);
        }

        public function evict(string $tenantId): void
        {
            $this->evictions++;
            $this->inner->evict($tenantId);
        }
    };

    app()->instance(EntitlementSource::class, $spy);
    app()->instance(EvictsEntitlements::class, $spy);

    app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($subscriptionId, $newPlan));

    expect($spy->evictions)->toBe(1)
        ->and($spy->for($tenantId)->limit(LimitKey::Terminals)->value)->toBe(30);
});
