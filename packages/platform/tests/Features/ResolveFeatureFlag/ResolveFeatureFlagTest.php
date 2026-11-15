<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ResolveFeatureFlag;

use Flexipos\Contracts\Platform\FeatureFlags;
use Flexipos\Contracts\Platform\UnknownFeatureFlag;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlan;
use Flexipos\Platform\Features\ChangeSubscriptionPlan\ChangeSubscriptionPlanHandler;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 8 - ResolveFeatureFlag through the full ladder (AC-17 to AC-21)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    PlatformSeed::bindMasterSource();
});

it('AC-17: resolves the global default when neither layer has anything', function () {
    PlatformSeed::featureFlag('demo.flag', true);
    $tenantId = PlatformSeed::tenant();

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();

    PlatformSeed::featureFlag('demo.flag.off', false);

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag.off'))->toBeFalse();
});

it('AC-18: a plan_features row overrides the default, and falls back on a plan change (control)', function () {
    PlatformSeed::featureFlag('demo.flag', true);
    $tenantId = PlatformSeed::tenant();
    $planWithRow = PlatformSeed::plan();
    $planWithoutRow = PlatformSeed::plan();
    PlatformSeed::planFeature($planWithRow, 'demo.flag', false);
    PlatformSeed::subscription($tenantId, $planWithRow, 'active');

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeFalse();

    app(ChangeSubscriptionPlanHandler::class)->handle(
        new ChangeSubscriptionPlan(
            (string) PlatformSeed::connection()->table('subscriptions')->where('tenant_id', $tenantId)->value('id'),
            $planWithoutRow,
        )
    );

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();
});

it('AC-19: all three layers in one fixture - tenant beats plan beats default', function () {
    PlatformSeed::featureFlag('demo.flag', false);
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    PlatformSeed::planFeature($planId, 'demo.flag', true);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();

    PlatformSeed::tenantFeatureFlag($tenantId, 'demo.flag', false);

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeFalse();
});

it('AC-20: a JSON object plan value reads enabled, as-is', function () {
    PlatformSeed::featureFlag('demo.cap', false);
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    PlatformSeed::planFeature($planId, 'demo.cap', ['limit' => 10]);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    $resolved = app(FeatureFlags::class)->resolve($tenantId, 'demo.cap');

    expect($resolved->value)->toBe(['limit' => 10])
        ->and($resolved->isEnabled())->toBeTrue();
});

it('AC-21: an unknown key throws, a real key still resolves, and a key added after caching is found once', function () {
    $tenantId = PlatformSeed::tenant();

    expect(fn () => app(FeatureFlags::class)->isEnabled($tenantId, 'no.such.flag'))
        ->toThrow(UnknownFeatureFlag::class);

    PlatformSeed::featureFlag('demo.flag', true);

    expect(app(FeatureFlags::class)->isEnabled($tenantId, 'demo.flag'))->toBeTrue();
});
