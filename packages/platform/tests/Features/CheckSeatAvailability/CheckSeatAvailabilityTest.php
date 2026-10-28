<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\CheckSeatAvailability;

use Flexipos\Contracts\Platform\PlanLimitReached;
use Flexipos\Contracts\Platform\TenantSeats;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-016 step 9 - CheckSeatAvailability (AC-8, Ruling 9)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
    PlatformSeed::bindMasterSource();
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-28 10:00:00.000'));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function seatMembership(string $tenantId, string $status = 'active', array $overrides = []): void
{
    PlatformSeed::connection()->table('tenant_users')->insert(array_merge([
        'id' => (string) Str::ulid(),
        'tenant_id' => $tenantId,
        'user_id' => (string) Str::ulid(),
        'access_level' => 'owner',
        'status' => $status,
        'is_default' => false,
        'created_at' => '2026-10-28 10:00:00.000',
        'updated_at' => '2026-10-28 10:00:00.000',
    ], $overrides));
}

it('refuses at the limit, with the users key, and accepts one below it', function () {
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'users', 3);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    seatMembership($tenantId);
    seatMembership($tenantId);

    app(TenantSeats::class)->ensureRoomForOneMore($tenantId);

    seatMembership($tenantId);

    try {
        app(TenantSeats::class)->ensureRoomForOneMore($tenantId);
        $thrown = null;
    } catch (PlanLimitReached $reached) {
        $thrown = $reached;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->limitKey()->value)->toBe('users')
        ->and($thrown->limit())->toBe(3);
});

it('does not count a suspended membership or a soft-deleted one', function () {
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'users', 3);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    seatMembership($tenantId, 'active');
    seatMembership($tenantId, 'active');
    seatMembership($tenantId, 'suspended');
    seatMembership($tenantId, 'active', ['deleted_at' => '2026-10-28 10:00:00.000']);

    // Two active, one suspended, one soft-deleted: still below 3.
    app(TenantSeats::class)->ensureRoomForOneMore($tenantId);

    expect(true)->toBeTrue();
});

it('the query log shows master tables only', function () {
    $tenantId = PlatformSeed::tenant();
    $planId = PlatformSeed::plan();
    PlatformSeed::planLimit($planId, 'users', 3);
    PlatformSeed::subscription($tenantId, $planId, 'active');

    $connection = PlatformSeed::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    app(TenantSeats::class)->ensureRoomForOneMore($tenantId);

    $queries = array_map(static fn (array $entry): string => (string) $entry['query'], $connection->getQueryLog());
    $connection->disableQueryLog();

    expect($queries)->not->toBe([]);

    foreach ($queries as $query) {
        expect($query)->not->toContain('employees')
            ->and($query)->not->toContain('branches');
    }
});
