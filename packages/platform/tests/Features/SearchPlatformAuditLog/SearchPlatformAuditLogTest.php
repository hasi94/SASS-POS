<?php

declare(strict_types=1);

namespace Flexipos\Platform\Tests\Features\SearchPlatformAuditLog;

use Closure;
use Flexipos\Kernel\Audit\AuditPage;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Features\SearchPlatformAuditLog\SearchPlatformAuditLog;
use Flexipos\Platform\Features\SearchPlatformAuditLog\SearchPlatformAuditLogHandler;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 12 - platform search (AC-16, AC-17)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    PlatformSeed::migrate();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function platformSearchSeedUser(array $overrides = []): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('users')->insert(array_merge([
        'id' => $id,
        'name' => 'Staff',
        'email' => $id.'@example.test',
        'password_hash' => str_repeat('a', 60),
        'status' => 'active',
        'is_platform_staff' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function platformSearchSeedRow(array $overrides = []): int
{
    $now = app(RunnerClock::class)->timestamp();

    return (int) PlatformSeed::connection()->table('platform_audit_logs')->insertGetId(array_merge([
        'actor_type' => 'system',
        'actor_id' => null,
        'actor_email' => null,
        'tenant_id' => null,
        'action' => 'tenant_status_synced',
        'subject_type' => 'tenant',
        'subject_id' => 'SUBJECT0000000000000000001',
        'before' => null,
        'after' => null,
        'ip' => null,
        'user_agent' => null,
        'request_id' => null,
        'created_at' => $now,
    ], $overrides));
}

function platformSearch(SearchPlatformAuditLog $query): AuditPage
{
    /** @var SearchPlatformAuditLogHandler $handler */
    $handler = app(SearchPlatformAuditLogHandler::class);

    return $handler->handle($query);
}

it('filters by tenant, actor, action, subject and a created_at range', function () {
    $staff = platformSearchSeedUser();

    $matching = platformSearchSeedRow([
        'tenant_id' => 'TENANT0000000000000000001',
        'actor_id' => 'ACTOR0000000000000000000X',
        'action' => 'subscription_started',
        'subject_type' => 'subscription',
        'subject_id' => 'SUB00000000000000000000X',
        'created_at' => '2026-06-15 00:00:00.000',
    ]);

    platformSearchSeedRow(['action' => 'subscription_plan_changed']);
    platformSearchSeedRow(['tenant_id' => 'OTHER0000000000000000001']);
    platformSearchSeedRow(['created_at' => '2025-01-01 00:00:00.000']);

    $byTenant = platformSearch(new SearchPlatformAuditLog($staff, tenantId: 'TENANT0000000000000000001'));
    $byAction = platformSearch(new SearchPlatformAuditLog($staff, action: 'subscription_started'));
    $bySubject = platformSearch(new SearchPlatformAuditLog($staff, subjectType: 'subscription', subjectId: 'SUB00000000000000000000X'));
    $byRange = platformSearch(new SearchPlatformAuditLog($staff, from: '2026-06-01', to: '2026-06-30'));

    expect(array_column($byTenant->rows, 'id'))->toBe([$matching])
        ->and(array_column($byAction->rows, 'id'))->toBe([$matching])
        ->and(array_column($bySubject->rows, 'id'))->toBe([$matching])
        ->and(array_column($byRange->rows, 'id'))->toContain($matching);
});

it('orders newest-first with keyset paging, and clamps the page size', function () {
    $staff = platformSearchSeedUser();

    $ids = [];

    for ($i = 1; $i <= 3; $i++) {
        $ids[] = platformSearchSeedRow(['created_at' => '2026-06-0'.$i.' 00:00:00.000']);
    }

    $page = platformSearch(new SearchPlatformAuditLog($staff, pageSize: 2));

    expect($page->rows)->toHaveCount(2)
        ->and(array_column($page->rows, 'id'))->toBe([$ids[2], $ids[1]])
        ->and($page->nextBeforeId)->toBe($ids[1]);

    $next = platformSearch(new SearchPlatformAuditLog($staff, pageSize: 2, beforeId: $page->nextBeforeId));

    expect(array_column($next->rows, 'id'))->toBe([$ids[0]])
        ->and($next->nextBeforeId)->toBeNull();
});

it('gives an empty page for a valid filter matching nothing', function () {
    $staff = platformSearchSeedUser();
    platformSearchSeedRow();

    $page = platformSearch(new SearchPlatformAuditLog($staff, tenantId: 'NO_SUCH_TENANT'));

    expect($page->rows)->toBe([]);
});

it('refuses each invalid filter with validation', function (Closure $makeQuery) {
    $staff = platformSearchSeedUser();
    $query = $makeQuery($staff);

    expect(fn () => platformSearch($query))->toThrow(PlatformRefused::class);
})->with([
    'to before from' => [fn (string $staff) => new SearchPlatformAuditLog($staff, from: '2026-06-10', to: '2026-06-01')],
    'an unparseable date' => [fn (string $staff) => new SearchPlatformAuditLog($staff, from: 'not-a-date')],
    'an unknown action' => [fn (string $staff) => new SearchPlatformAuditLog($staff, action: 'no_such_action')],
    'subject_id without subject_type' => [fn (string $staff) => new SearchPlatformAuditLog($staff, subjectId: 'X')],
    'before_id of 0' => [fn (string $staff) => new SearchPlatformAuditLog($staff, beforeId: 0)],
]);

it('AC-17: a staff user is allowed; non-staff, soft-deleted, non-active and unknown ids are each forbidden', function () {
    $staff = platformSearchSeedUser();
    $nonStaff = platformSearchSeedUser(['is_platform_staff' => false]);
    $deleted = platformSearchSeedUser(['deleted_at' => app(RunnerClock::class)->timestamp()]);
    $suspended = platformSearchSeedUser(['status' => 'suspended']);
    $unknown = (string) Str::ulid();

    expect(platformSearch(new SearchPlatformAuditLog($staff))->rows)->toBe([]);

    foreach ([$nonStaff, $deleted, $suspended, $unknown] as $actorId) {
        $refusal = null;

        try {
            platformSearch(new SearchPlatformAuditLog($actorId));
        } catch (PlatformRefused $thrown) {
            $refusal = $thrown;
        }

        expect($refusal)->toBeInstanceOf(PlatformRefused::class)
            ->and($refusal?->reason)->toBe('forbidden');
    }
});

it('AC-17 control: flipping is_platform_staff on the same user changes the answer', function () {
    $user = platformSearchSeedUser(['is_platform_staff' => false]);

    expect(fn () => platformSearch(new SearchPlatformAuditLog($user)))->toThrow(PlatformRefused::class);

    PlatformSeed::connection()->table('users')->where('id', $user)->update(['is_platform_staff' => true]);

    expect(platformSearch(new SearchPlatformAuditLog($user))->rows)->toBe([]);
});

it('isolation: every statement names the master connection only', function () {
    $staff = platformSearchSeedUser();
    platformSearchSeedRow();

    $master = PlatformSeed::connection();
    $master->flushQueryLog();
    $master->enableQueryLog();

    platformSearch(new SearchPlatformAuditLog($staff));

    $statements = array_map(static fn (array $entry): string => (string) $entry['query'], $master->getQueryLog());
    $master->disableQueryLog();

    expect($statements)->not->toBe([]);

    foreach ($statements as $statement) {
        expect($statement)->not->toContain('"audit_logs"');
    }
});
