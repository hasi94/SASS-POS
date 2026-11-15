<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\SearchAuditLog;

use Closure;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogs;
use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsHandler;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLog;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLogHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Audit\AuditPage;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 11 - tenant search (AC-4, AC-5, AC-9)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-29 00:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function searchReaderActor(): string
{
    $key = AccessSeed::permission('audit.read');

    return AccessSeed::actorHolding('reader', [$key]);
}

/**
 * One whole audit_logs (or, with $archive, audit_logs_archive) row,
 * written directly.
 *
 * @param  array<string, mixed>  $overrides
 */
function searchSeedRow(array $overrides = [], bool $archive = false): int
{
    $table = $archive ? 'audit_logs_archive' : 'audit_logs';

    return (int) AccessSeed::connection()->table($table)->insertGetId(array_merge([
        'actor_type' => 'employee',
        'actor_id' => null,
        'actor_name' => null,
        'branch_id' => null,
        'module' => 'access',
        'action' => 'role_created',
        'subject_type' => 'role',
        'subject_id' => 'SUBJECT0000000000000000001',
        'before' => null,
        'after' => null,
        'changed_fields' => null,
        'ip' => null,
        'user_agent' => null,
        'request_id' => null,
        'created_at' => '2026-06-01 00:00:00.000',
    ], $overrides));
}

function search(SearchAuditLog $query): AuditPage
{
    /** @var SearchAuditLogHandler $handler */
    $handler = app(SearchAuditLogHandler::class);

    return $handler->handle($query);
}

it('filters by subject, actor, module, action and a created_at range, alone and combined', function () {
    $actor = searchReaderActor();

    $matching = searchSeedRow([
        'actor_id' => $actor,
        'module' => 'access',
        'action' => 'role_created',
        'subject_type' => 'role',
        'subject_id' => 'ROLE00000000000000000000X',
        'created_at' => '2026-06-15 00:00:00.000',
    ]);

    searchSeedRow(['action' => 'role_deleted']);
    searchSeedRow(['module' => 'organization', 'action' => 'branch_created']);
    searchSeedRow(['subject_type' => 'employee', 'subject_id' => 'OTHER0000000000000000000X']);
    searchSeedRow(['created_at' => '2025-01-01 00:00:00.000']);

    $byAction = search(new SearchAuditLog($actor, action: 'role_created'));
    $byModule = search(new SearchAuditLog($actor, module: 'organization'));
    $bySubject = search(new SearchAuditLog($actor, subjectType: 'role', subjectId: 'ROLE00000000000000000000X'));
    $byActor = search(new SearchAuditLog($actor, actorId: $actor));
    $byRange = search(new SearchAuditLog($actor, from: '2026-06-01', to: '2026-06-30'));

    expect(array_column($byAction->rows, 'id'))->toContain($matching)
        ->and(array_column($byModule->rows, 'id'))->not->toContain($matching)
        ->and(array_column($bySubject->rows, 'id'))->toBe([$matching])
        ->and(array_column($byActor->rows, 'id'))->toBe([$matching])
        ->and(array_column($byRange->rows, 'id'))->toContain($matching);

    $combined = search(new SearchAuditLog(
        $actor,
        subjectType: 'role',
        subjectId: 'ROLE00000000000000000000X',
        actorId: $actor,
        module: 'access',
        action: 'role_created',
        from: '2026-06-01',
        to: '2026-06-30',
    ));

    expect(array_column($combined->rows, 'id'))->toBe([$matching]);
});

it('orders newest-first, and keyset paging covers every row exactly once across pages', function () {
    $actor = searchReaderActor();

    $ids = [];

    for ($i = 1; $i <= 5; $i++) {
        $ids[] = searchSeedRow(['created_at' => '2026-06-0'.$i.' 00:00:00.000']);
    }

    $first = search(new SearchAuditLog($actor, pageSize: 2));
    $second = search(new SearchAuditLog($actor, pageSize: 2, beforeId: $first->nextBeforeId));
    $third = search(new SearchAuditLog($actor, pageSize: 2, beforeId: $second->nextBeforeId));

    $seen = array_merge(
        array_column($first->rows, 'id'),
        array_column($second->rows, 'id'),
        array_column($third->rows, 'id'),
    );

    expect($first->rows)->toHaveCount(2)
        ->and($second->rows)->toHaveCount(2)
        ->and($third->rows)->toHaveCount(1)
        ->and($third->nextBeforeId)->toBeNull()
        ->and($seen)->toBe(array_reverse($ids))
        ->and(array_unique($seen))->toHaveCount(5);
});

it('clamps the page size to the configured maximum', function () {
    $actor = searchReaderActor();

    config()->set('audit.max_page_size', 2);

    for ($i = 0; $i < 3; $i++) {
        searchSeedRow();
    }

    $page = search(new SearchAuditLog($actor, pageSize: 100));

    expect($page->rows)->toHaveCount(2)
        ->and($page->nextBeforeId)->not->toBeNull();
});

it('gives an empty page for a valid filter matching nothing, not an error', function () {
    $actor = searchReaderActor();

    searchSeedRow(['module' => 'access']);

    $page = search(new SearchAuditLog($actor, module: 'organization'));

    expect($page->rows)->toBe([])
        ->and($page->nextBeforeId)->toBeNull();
});

it('refuses each invalid filter with validation, named', function (Closure $makeQuery) {
    $query = $makeQuery();

    expect(fn () => search($query))->toThrow(AccessRefused::class);

    try {
        search($query);
    } catch (AccessRefused $refused) {
        expect($refused->reason()->value)->toBe('validation');
    }
})->with([
    'to before from' => [fn () => new SearchAuditLog(searchReaderActor(), from: '2026-06-10', to: '2026-06-01')],
    'an unparseable date' => [fn () => new SearchAuditLog(searchReaderActor(), from: 'not-a-date')],
    'an unknown module' => [fn () => new SearchAuditLog(searchReaderActor(), module: 'no_such_module')],
    'an unknown action' => [fn () => new SearchAuditLog(searchReaderActor(), action: 'no_such_action')],
    'an action known under another module only' => [fn () => new SearchAuditLog(searchReaderActor(), module: 'organization', action: 'role_created')],
    'subject_id without subject_type' => [fn () => new SearchAuditLog(searchReaderActor(), subjectId: 'X')],
    'before_id of 0' => [fn () => new SearchAuditLog(searchReaderActor(), beforeId: 0)],
]);

it('AC-9: the default call does not see archived rows, and archive: true sees exactly them with the same filters', function () {
    $actor = searchReaderActor();

    $old = searchSeedRow(['created_at' => '2025-01-01 00:00:00.000']);
    searchSeedRow(['created_at' => '2026-10-28 00:00:00.000']);

    app(ArchiveAuditLogsHandler::class)->handle(new ArchiveAuditLogs);

    $live = search(new SearchAuditLog($actor));
    $archived = search(new SearchAuditLog($actor, archive: true));

    expect(array_column($live->rows, 'id'))->not->toContain($old)
        ->and(array_column($archived->rows, 'id'))->toBe([$old]);
});

it('isolation: every statement names only the tenant connection and audit_logs/audit_logs_archive/access tables', function () {
    $actor = searchReaderActor();
    searchSeedRow();

    $tenant = AccessSeed::connection();
    $tenant->flushQueryLog();
    $tenant->enableQueryLog();

    search(new SearchAuditLog($actor));

    $statements = array_map(static fn (array $entry): string => (string) $entry['query'], $tenant->getQueryLog());
    $tenant->disableQueryLog();

    expect($statements)->not->toBe([]);

    foreach ($statements as $statement) {
        expect($statement)->not->toContain('"tenant_databases"')
            ->and($statement)->not->toContain('"platform_audit_logs"');
    }

    $masterQueries = [];
    DB::listen(static function ($query) use (&$masterQueries): void {
        if ((string) $query->connectionName === 'master') {
            $masterQueries[] = (string) $query->sql;
        }
    });

    search(new SearchAuditLog($actor));

    expect($masterQueries)->toBe([]);
});
