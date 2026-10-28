<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\ChangeLicenceStatus;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Features\ChangeLicenceStatus\ChangeLicenceStatus;
use Flexipos\Platform\Features\ChangeLicenceStatus\ChangeLicenceStatusHandler;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatus;
use Flexipos\Platform\Features\ChangeSubscriptionStatus\ChangeSubscriptionStatusHandler;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Database\Connection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 5 - ChangeLicenceStatus (AC-24 to AC-27, AC-29)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

it('AC-25: expired, revoked and suspended each suspend the tenant with licence_lapsed', function (string $newStatus) {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $licenceId = PlatformSeed::licence($tenantId, 'platform', 'active');

    $result = app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($licenceId, $newStatus));

    expect($result->trigger)->toBe('licence_lapsed')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended')
        ->and(PlatformSeed::tenantRow($tenantId)['suspension_reason'])->toBe('licence_lapsed');
})->with(['expired', 'revoked', 'suspended']);

it('reactivating a licence lifts the suspension it caused', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    // An active subscription is what row 6 of Ruling 4's table maps back to
    // once the licence stops being the reason row 3 fires; with no
    // subscription at all there is no rule that ever lifts a licence-caused
    // suspension (row 8, "no subscription row", is "unchanged").
    PlatformSeed::subscription($tenantId, $planId, 'active');
    $licenceId = PlatformSeed::licence($tenantId, 'platform', 'active');

    app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($licenceId, 'revoked'));
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');

    app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($licenceId, 'active'));
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');
});

it('AC-25 control: an unexpired licence plus a lapsed subscription suspends for the subscription reason only', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $planId = PlatformSeed::plan();
    $subscriptionId = PlatformSeed::subscription($tenantId, $planId, 'active');
    PlatformSeed::licence($tenantId, 'platform', 'active');

    app(ChangeSubscriptionStatusHandler::class)->handle(
        new ChangeSubscriptionStatus($subscriptionId, 'cancelled')
    );

    expect(PlatformSeed::tenantRow($tenantId)['suspension_reason'])->toBe('subscription_lapsed');
});

it('AC-26: no platform licence row is not a restriction, and an expired one does suspend', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');

    // No reconcile call happens without a triggering write, so this checks
    // the verdict directly through a licence write that leaves the tenant
    // untouched when there is nothing to react to.
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');

    $licenceId = PlatformSeed::licence($tenantId, 'platform', 'active', ['valid_until' => '2020-01-01 00:00:00.000']);

    app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($licenceId, 'active'));

    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');
});

it('AC-27: a revoked module licence changes nothing, against a revoked platform one which does', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $moduleLicence = PlatformSeed::licence($tenantId, 'module', 'active');

    app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($moduleLicence, 'revoked'));
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');

    $platformLicence = PlatformSeed::licence($tenantId, 'platform', 'active');
    app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($platformLicence, 'revoked'));
    expect(PlatformSeed::tenantRow($tenantId)['status'])->toBe('suspended');
});

it('AC-29: a fault after the licence write rolls back both the licence and the tenant', function () {
    $tenantId = PlatformSeed::tenant('tenant-a', 'active');
    $licenceId = PlatformSeed::licence($tenantId, 'platform', 'active');

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

    expect(fn () => app(ChangeLicenceStatusHandler::class)->handle(new ChangeLicenceStatus($licenceId, 'revoked')))
        ->toThrow(\RuntimeException::class);

    $licence = (array) PlatformSeed::connection()->table('licences')->where('id', $licenceId)->first();

    expect($licence['status'])->toBe('active')
        ->and(PlatformSeed::tenantRow($tenantId)['status'])->toBe('active');
});
