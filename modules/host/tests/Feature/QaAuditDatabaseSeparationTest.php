<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogs;
use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsHandler;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLog;
use Flexipos\Access\Features\SearchAuditLog\SearchAuditLogHandler;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Features\SearchPlatformAuditLog\SearchPlatformAuditLog;
use Flexipos\Platform\Features\SearchPlatformAuditLog\SearchPlatformAuditLogHandler;
use Flexipos\Platform\Tests\Support\PlatformSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-017 step 13 - the topology rule, under a two-connection query log
|--------------------------------------------------------------------------
|
| The same shape as QaPlatformDatabaseSeparationTest: tenant work (the
| search and the archive move) issues no master statement, and platform
| search issues no tenant statement (NFR; AC-4's "never reads the master").
*/

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    PlatformSeed::migrate();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-29 00:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * @return list<string>
 */
function qaAuditSeparationQueries(string $connection, callable $work): array
{
    $handle = DB::connection($connection);

    $handle->flushQueryLog();
    $handle->enableQueryLog();

    try {
        $work();
    } finally {
        $handle->disableQueryLog();
    }

    return array_map(
        static fn (array $entry): string => (string) $entry['query'],
        $handle->getQueryLog()
    );
}

it('tenant search and archival issue no statement on the master connection', function () {
    $key = AccessSeed::permission('audit.read');
    $actor = AccessSeed::actorHolding('reader', [$key]);

    AccessSeed::connection()->table('audit_logs')->insert([
        'actor_type' => 'employee', 'actor_id' => null, 'actor_name' => null, 'branch_id' => null,
        'module' => 'access', 'action' => 'role_created', 'subject_type' => 'role',
        'subject_id' => 'SUBJECT0000000000000000001', 'before' => null, 'after' => null,
        'changed_fields' => null, 'ip' => null, 'user_agent' => null, 'request_id' => null,
        'created_at' => '2025-01-01 00:00:00.000',
    ]);

    $masterQueries = qaAuditSeparationQueries('master', function () use ($actor): void {
        app(SearchAuditLogHandler::class)->handle(new SearchAuditLog($actor));
        app(ArchiveAuditLogsHandler::class)->handle(new ArchiveAuditLogs);
    });

    expect($masterQueries)->toBe([]);
});

it('platform search issues no statement on the tenant connection', function () {
    $staff = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    PlatformSeed::connection()->table('users')->insert([
        'id' => $staff, 'name' => 'Staff', 'email' => $staff.'@example.test',
        'password_hash' => str_repeat('a', 60), 'status' => 'active',
        'is_platform_staff' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $tenantConnectionName = (string) config('tenancy.connection', 'tenant');

    $tenantQueries = qaAuditSeparationQueries($tenantConnectionName, function () use ($staff): void {
        app(SearchPlatformAuditLogHandler::class)->handle(new SearchPlatformAuditLog($staff));
    });

    expect($tenantQueries)->toBe([]);
});
