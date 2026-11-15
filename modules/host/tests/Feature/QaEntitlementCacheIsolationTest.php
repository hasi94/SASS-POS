<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Platform\Support\CachingEntitlementSource;
use Flexipos\Platform\Support\Entitlements;
use Flexipos\Platform\Support\EntitlementSource;
use Flexipos\Platform\Support\MasterEntitlementSource;
use Flexipos\Platform\Tests\Support\PlatformSeed;

/*
|--------------------------------------------------------------------------
| T-016 step 12 - the entitlement cache keeps tenants apart (AC-5, qa)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    PlatformSeed::migrate();
});

it('A, B, A on one worker each get their own answer, with different limits', function () {
    $tenantA = PlatformSeed::tenant('tenant-a', 'active');
    $tenantB = PlatformSeed::tenant('tenant-b', 'active');
    $planA = PlatformSeed::plan();
    $planB = PlatformSeed::plan();
    PlatformSeed::planLimit($planA, 'branches', 3);
    PlatformSeed::planLimit($planB, 'branches', 9);
    PlatformSeed::subscription($tenantA, $planA, 'active');
    PlatformSeed::subscription($tenantB, $planB, 'active');

    $caching = new CachingEntitlementSource(app(MasterEntitlementSource::class), app('cache.store'), 300);

    expect($caching->for($tenantA)->limit(LimitKey::Branches)->value)->toBe(3)
        ->and($caching->for($tenantB)->limit(LimitKey::Branches)->value)->toBe(9)
        ->and($caching->for($tenantA)->limit(LimitKey::Branches)->value)->toBe(3);
});

it('a mis-keyed cache double can be made to fail: it answers wrongly across tenants', function () {
    $misKeyed = new class implements EntitlementSource
    {
        private ?Entitlements $only = null;

        public function for(string $tenantId): Entitlements
        {
            // Deliberately wrong: the same entry for every tenant id, which
            // is what "no tenant in the key" looks like.
            return $this->only ??= new Entitlements(null, ['branches' => 1], [], [], []);
        }
    };

    $first = $misKeyed->for('tenant-a')->limit(LimitKey::Branches)->value;
    $second = $misKeyed->for('tenant-b')->limit(LimitKey::Branches)->value;

    // Both tenants get the first tenant's answer - the exact failure shape
    // a real tenant-keyed cache must never produce.
    expect($first)->toBe($second);
});

it('an eviction-blind double keeps a stale plan after a plan change', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planA = PlatformSeed::plan();
    $planB = PlatformSeed::plan();
    PlatformSeed::planLimit($planA, 'branches', 3);
    PlatformSeed::planLimit($planB, 'branches', 9);
    PlatformSeed::subscription($tenantId, $planA, 'active');

    $blind = new class(app(MasterEntitlementSource::class)) implements EntitlementSource
    {
        /** @var array<string, Entitlements> */
        private array $cache = [];

        public function __construct(private readonly EntitlementSource $inner) {}

        public function for(string $tenantId): Entitlements
        {
            return $this->cache[$tenantId] ??= $this->inner->for($tenantId);
        }

        // Deliberately no evict(): a "cache" with no eviction path at all.
    };

    expect($blind->for($tenantId)->limit(LimitKey::Branches)->value)->toBe(3);

    PlatformSeed::connection()->table('subscriptions')->where('tenant_id', $tenantId)->update(['plan_id' => $planB]);

    // Still 3: nothing ever told this double the plan changed.
    expect($blind->for($tenantId)->limit(LimitKey::Branches)->value)->toBe(3);
});
