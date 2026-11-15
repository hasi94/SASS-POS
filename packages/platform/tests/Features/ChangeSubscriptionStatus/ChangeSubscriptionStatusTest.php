<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ChangeSubscriptionStatus;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatus;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatusHandler;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Database\Connection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 4 - ChangeSubscriptionStatus (AC-9 to AC-14, AC-16)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

function changeStatusHandler(): ChangeSubscriptionStatusHandler
{
    return app(ChangeSubscriptionStatusHandler::class);
}

it('AC-9: cancelled and expired each suspend the tenant with subscription_lapsed', function (string $newStatus) {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    $result = changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, $newStatus));

    expect($result->trigger)->toBe('subscription_lapsed')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended')
        ->and(PlatformSeed::tenantRow($tenantId)['suspension_reason'])->toBe('subscription_lapsed');
})->with(['cancelled', 'expired']);

it('AC-10: active from trial, past_due and paused each select the tenant active', function (string $from) {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, $from);

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'active'));

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');
})->with(['trial', 'past_due', 'paused']);

it('AC-10 control: a closed tenant stays closed, with no audit row, when its subscription reactivates', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'closed');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'cancelled');

    $result = changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'active'));

    expect($result->trigger)->toBeNull()
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('closed')
        ->and(PlatformSeed::auditRows('tenant_status_synced'))->toHaveCount(0);
});

it('AC-11 write half: entering past_due stamps past_due_at, and leaving it clears it', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'past_due'));

    $row = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($row['past_due_at'])->toBe('2026-10-28 10:00:00.000')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('past_due');

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'active'));

    $row = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($row['past_due_at'])->toBeNull();
});

it('AC-12: paused selects read_only', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'paused'));

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('read_only');
});

it('AC-14: changing a historical row does not react, changing the current one does', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();

    $historical = PlatformSeed::subscription($tenantId, $planId, 'active', ['current_period_start' => '2020-01-01 00:00:00.000']);
    $current = PlatformSeed::subscription($tenantId, $planId, 'active', ['current_period_start' => '2026-01-01 00:00:00.000']);

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($historical, 'cancelled'));

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($current, 'cancelled'));

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');
});

it('review S-4: reactivating a cancelled subscription clears cancelled_at and the reason', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'cancelled', 'owner request'));

    $row = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($row['cancelled_at'])->not->toBeNull()
        ->and($row['cancellation_reason'])->toBe('owner request');

    changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'active'));

    $row = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($row['cancelled_at'])->toBeNull()
        ->and($row['cancellation_reason'])->toBeNull();
});

it('refuses a move to the same status', function () {
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');

    expect(fn () => changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'active')))
        ->toThrow(PlatformRefused::class);
});

it('AC-29: a fault after the subscription write rolls back both the subscription and the tenant', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'trial');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'trial');

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

    expect(fn () => changeStatusHandler()->handle(new ChangeSubscriptionStatus($subscriptionId, 'active')))
        ->toThrow(\RuntimeException::class);

    $row = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($row['status'])->toBe('trial')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('trial');
});
