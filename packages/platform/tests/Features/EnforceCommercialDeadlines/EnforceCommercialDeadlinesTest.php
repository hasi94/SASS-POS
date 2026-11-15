<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\EnforceCommercialDeadlines;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Features\EnforceCommercialDeadlines\EnforceCommercialDeadlines;
use Flexipos\Platform\Features\EnforceCommercialDeadlines\EnforceCommercialDeadlinesHandler;
use Flexipos\Platform\Features\EnforceCommercialDeadlines\EnforceCommercialDeadlinesResult;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Support\TenantStatusSync;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Database\Connection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 6 - the one-minute sweep (AC-11, AC-16, AC-25 time half)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

function runSweep(): EnforceCommercialDeadlinesResult
{
    return app(EnforceCommercialDeadlinesHandler::class)->handle(new EnforceCommercialDeadlines);
}

it('AC-11: leaves the tenant past_due one second before the deadline, and suspends it exactly at the deadline', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'past_due');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-07 23:59:59.000'));
    runSweep();
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('past_due');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-08 00:00:00.000'));
    $result = runSweep();

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended')
        ->and(PlatformSeed::tenantRow($tenantId)['suspension_reason'])->toBe('past_due_grace_expired')
        ->and($result->changed)->toBe(1);
});

it('a configured grace period moves the boundary', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'past_due');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);
    PlatformSeed::platformSetting('billing.past_due_grace_days', 2);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-03 00:00:00.000'));
    runSweep();

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');
});

it('AC-16: a current_period_end twenty days ago does not move the grace clock, only past_due_at does', function () {
    // Review W-2: `past_due_at` alone decides this - it is 3 days old at a
    // 7-day grace, so the correct answer is "stays past_due". A handler that
    // measured from `current_period_end` instead (20 days old) would
    // suspend it, which is exactly the mutation this proves red: the sweep's
    // own candidate query would never even pick this tenant up (past_due_at
    // is inside the grace window), so `TenantStatusSync::reconcile()` is
    // called directly rather than through `runSweep()`.
    $tenantId = PlatformSeed::tenant('tenant-a', 'past_due');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'past_due', [
        'current_period_end' => '2026-01-08 00:00:00.000',
        'past_due_at' => '2026-01-25 00:00:00.000',
    ]);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-28 00:00:00.000'));

    app(TenantStatusSync::class)->reconcile(
        PlatformSeed::connection(),
        $tenantId,
        null,
    );

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('past_due');
});

it('stamps a null past_due_at, and does not suspend on that same run', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'past_due');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'past_due');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-01 00:00:00.000'));
    runSweep();

    $row = (array) PlatformSeed::connection()->table('subscriptions')->where('id', $subscriptionId)->first();

    expect($row['past_due_at'])->toBe('2026-01-01 00:00:00.000')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('past_due');
});

it('AC-25 time half: suspends at valid_until, and lifts it once a new valid licence is inserted', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'active');
    PlatformSeed::licence($tenantId, 'platform', 'active', ['valid_until' => '2026-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2025-12-31 00:00:00.000'));
    runSweep();
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-01 00:00:00.000'));
    runSweep();
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');

    PlatformSeed::licence($tenantId, 'platform', 'active', ['valid_until' => '2027-01-01 00:00:00.000']);
    runSweep();
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');
});

it('is idempotent: a second run against nothing new reports zero changed', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'past_due');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-08 00:00:00.000'));
    runSweep();
    $second = runSweep();

    expect($second->changed)->toBe(0);
});

it('review W-5: a protected tenant with an old past_due subscription is never a candidate', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'closed');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-08 00:00:00.000'));
    $result = runSweep();

    expect($result->examined)->toBe(0)
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('closed');
});

it('review W-5: a tenant whose expired licence a currently-valid one has already superseded is never a candidate', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($tenantId, $planId, 'active');
    PlatformSeed::licence($tenantId, 'platform', 'active', ['valid_until' => '2020-01-01 00:00:00.000']);
    PlatformSeed::licence($tenantId, 'platform', 'active', ['valid_until' => '2030-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-08 00:00:00.000'));
    $result = runSweep();

    expect($result->examined)->toBe(0)
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');
});

it('isolates one tenant failure: the other still changes and the failure count is 1', function () {
    $good = PlatformSeed::tenant('tenant-good', 'past_due');
    $bad = PlatformSeed::tenant('tenant-bad', 'past_due');
    $planId = PlatformSeed::plan();
    PlatformSeed::subscription($good, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);
    PlatformSeed::subscription($bad, $planId, 'past_due', ['past_due_at' => '2026-01-01 00:00:00.000']);

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-01-08 00:00:00.000'));

    app()->bind(TenantStatusSync::class, fn () => new class(app(RunnerClock::class), app(PlatformAudit::class), 7, $bad) extends TenantStatusSync
    {
        public function __construct(RunnerClock $clock, PlatformAudit $audit, int $configuredGraceDays, private readonly string $failingTenantId)
        {
            parent::__construct($clock, $audit, $configuredGraceDays);
        }

        public function reconcile(Connection $master, string $tenantId, ?string $actorUserId): ?string
        {
            if ($tenantId === $this->failingTenantId) {
                throw new \RuntimeException('injected fault');
            }

            return parent::reconcile($master, $tenantId, $actorUserId);
        }
    });

    $result = runSweep();

    expect($result->examined)->toBe(2)
        ->and($result->failed)->toBe(1)
        ->and($result->changed)->toBe(1)
        ->and(PlatformSeed::tenantRow($good)['status'])->toBe('suspended')
        ->and(PlatformSeed::tenantRow($bad)['status'])->toBe('past_due');
});
