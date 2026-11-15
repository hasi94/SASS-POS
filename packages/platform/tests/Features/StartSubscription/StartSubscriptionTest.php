<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\StartSubscription;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\StartSubscription\StartSubscription;
use Flexipos\Platform\Features\StartSubscription\StartSubscriptionHandler;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Database\Connection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 4 - StartSubscription (AC-9 to AC-15, AC-29)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('starts a subscription, mirrors the plan onto tenants.plan_id, and reconciles status in one call', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'pending');
    $planId = PlatformSeed::plan();

    $handler = app(StartSubscriptionHandler::class);
    $result = $handler->handle(new StartSubscription($tenantId, $planId, 'active'));

    $subscription = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $result->subscriptionId)->first();
    $tenant = PlatformSeed::tenantRow($tenantId);

    expect($subscription['plan_id'])->toBe($planId)
        ->and($subscription['status'])->toBe('active')
        ->and($tenant['plan_id'])->toBe($planId)
        // tenant started as `pending`, which is protected (Ruling 3): the
        // reconciler never touches it.
        ->and($tenant['status'])->toBe('pending')
        ->and($result->trigger)->toBeNull();
});

it('AC-13: an active tenant with no prior subscription becomes active exactly once', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'trial');
    $planId = PlatformSeed::plan();

    $handler = app(StartSubscriptionHandler::class);
    $result = $handler->handle(new StartSubscription($tenantId, $planId, 'active'));

    expect($result->trigger)->toBe('subscription_active')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active')
        ->and(PlatformSeed::auditRows('tenant_status_synced'))->toHaveCount(1);
});

it('refuses a non-existent or inactive plan, with nothing written', function () {
    $tenantId = PlatformSeed::tenant();

    $handler = app(StartSubscriptionHandler::class);

    expect(fn () => $handler->handle(new StartSubscription($tenantId, 'not-a-plan', 'active')))
        ->toThrow(PlatformRefused::class);

    expect(PlatformSeed::connection()->table('subscriptions')->count())->toBe(0);
});

it('refuses a status other than trial or active', function () {
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();

    $handler = app(StartSubscriptionHandler::class);

    expect(fn () => $handler->handle(new StartSubscription($tenantId, $planId, 'paused')))
        ->toThrow(PlatformRefused::class);
});

it('AC-29: a fault in the audit write rolls back the subscription and the tenant mirror together', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'trial');
    $planId = PlatformSeed::plan();

    app()->bind(PlatformAudit::class, fn () => new class(app(RunnerClock::class)) extends PlatformAudit
    {
        public function write(Connection $master, string $action, string $subjectType, ?string $subjectId, ?string $tenantId, ?string $actorUserId, ?array $before, ?array $after): void
        {
            if ($action === 'tenant_status_synced') {
                throw new \RuntimeException('injected fault');
            }

            parent::write($master, $action, $subjectType, $subjectId, $tenantId, $actorUserId, $before, $after);
        }
    });

    $handler = app(StartSubscriptionHandler::class);

    expect(fn () => $handler->handle(new StartSubscription($tenantId, $planId, 'active')))
        ->toThrow(\RuntimeException::class);

    expect(PlatformSeed::connection()->table('subscriptions')->where('tenant_id', $tenantId)->count())->toBe(0)
        ->and(PlatformSeed::tenantRow($tenantId)['plan_id'])->toBeNull();
});
