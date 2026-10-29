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
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Database\Connection;
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

/**
 * A spy around the real caching source, counting evictions without
 * disturbing the cache behaviour itself.
 */
final class ChangeSubscriptionPlanCacheSpy implements EntitlementSource, EvictsEntitlements
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
}

function changeSubscriptionPlanCacheSpy(CachingEntitlementSource $inner): ChangeSubscriptionPlanCacheSpy
{
    return new ChangeSubscriptionPlanCacheSpy($inner);
}

it('evicts the cache after commit, so a warmed-cold answer changes to the new plan', function () {
    // T-016 reviewer round 1, W-3: the old version of this test never
    // warmed the cache with the *old* plan's answer before the change, so
    // `limit(Terminals)->value === 30` after the change passed whether or
    // not eviction actually ran - a cold cache would have read the new
    // plan's value anyway. Warming it first means a skipped eviction would
    // leave the stale, uncapped (old-plan) answer in place.
    PlatformSeed::bindMasterSource();

    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();
    PlatformSeed::planLimit($newPlan, 'terminals', 30);
    $subscriptionId = PlatformSeed::subscription($tenantId, $oldPlan, 'active');

    $inner = new CachingEntitlementSource(app(MasterEntitlementSource::class), app('cache.store'), 300);
    $spy = changeSubscriptionPlanCacheSpy($inner);

    app()->instance(EntitlementSource::class, $spy);
    app()->instance(EvictsEntitlements::class, $spy);

    // Warm the cache under the old plan: terminals is uncapped there.
    expect($spy->for($tenantId)->limit(LimitKey::Terminals)->value)->toBeNull();

    app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($subscriptionId, $newPlan));

    expect($spy->evictions)->toBe(1)
        ->and($spy->for($tenantId)->limit(LimitKey::Terminals)->value)->toBe(30);
});

it('does not evict the cache when the transaction rolls back', function () {
    PlatformSeed::bindMasterSource();

    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $oldPlan = PlatformSeed::plan();
    $newPlan = PlatformSeed::plan();
    PlatformSeed::planLimit($newPlan, 'terminals', 30);
    $subscriptionId = PlatformSeed::subscription($tenantId, $oldPlan, 'active');

    $inner = new CachingEntitlementSource(app(MasterEntitlementSource::class), app('cache.store'), 300);
    $spy = changeSubscriptionPlanCacheSpy($inner);

    app()->instance(EntitlementSource::class, $spy);
    app()->instance(EvictsEntitlements::class, $spy);

    // Warm the cache under the old plan first, same reason as the commit case.
    expect($spy->for($tenantId)->limit(LimitKey::Terminals)->value)->toBeNull();

    app()->bind(PlatformAudit::class, fn () => new class(app(RunnerClock::class)) extends PlatformAudit
    {
        public function write(Connection $master, string $action, string $subjectType, ?string $subjectId, ?string $tenantId, ?string $actorUserId, ?array $before, ?array $after): void
        {
            if ($action === 'subscription_plan_changed') {
                throw new \RuntimeException('injected fault');
            }

            parent::write($master, $action, $subjectType, $subjectId, $tenantId, $actorUserId, $before, $after);
        }
    });

    expect(fn () => app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan($subscriptionId, $newPlan)))
        ->toThrow(\RuntimeException::class);

    $subscription = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($spy->evictions)->toBe(0)
        ->and($subscription['plan_id'])->toBe($oldPlan)
        // The cached answer is still the old, uncapped plan's - proving the
        // cache was never told to forget it.
        ->and($spy->for($tenantId)->limit(LimitKey::Terminals)->value)->toBeNull();
});
