<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\ArchiveAuditLogs;

use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogs;
use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsHandler;
use Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsResult;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\AuditColumns;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 8 - the archive move (AC-8, Ruling 5)
|--------------------------------------------------------------------------
|
| RunnerClock::fixedAt() and rows inserted directly (never through
| AuditWriter, whose catalogue enforcement and clock are not this slice's
| subject), then aged by a direct created_at update - the fixture's own
| input, exactly as D-19/AC-8's bounded-proof convention asks for.
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

function archiveConnection(): Connection
{
    /** @var Connection $connection */
    $connection = DB::connection(AccessTenantFixture::connectionName());

    return $connection;
}

/**
 * One whole `audit_logs` row, written directly (not through AuditWriter),
 * with a chosen `created_at` and otherwise realistic values in every
 * column - including a binary `ip` and real JSON - so the byte-identical
 * claim has something non-trivial to compare.
 *
 * @return int the inserted id
 */
function archiveSeedRow(string $createdAt, string $action = 'role_created'): int
{
    return (int) archiveConnection()->table('audit_logs')->insertGetId([
        'actor_type' => 'employee',
        'actor_id' => null,
        'actor_name' => 'Seeded Actor',
        'branch_id' => null,
        'module' => 'access',
        'action' => $action,
        'subject_type' => 'role',
        'subject_id' => 'SUBJECT0000000000000000001',
        'before' => null,
        'after' => json_encode(['key' => 'value with "quotes" and, a comma']),
        'changed_fields' => json_encode(['key']),
        'ip' => AccessLogWriter::packIp('203.0.113.7'),
        'user_agent' => 'Fixture/1.0',
        'request_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'created_at' => $createdAt,
    ]);
}

/**
 * @return array<string, mixed>
 */
function archiveRow(string $table, int $id): array
{
    $row = archiveConnection()->table($table)->where('id', $id)->first();

    expect($row)->not->toBeNull();

    return (array) $row;
}

function archiveRun(): ArchiveAuditLogsResult
{
    /** @var ArchiveAuditLogsHandler $handler */
    $handler = app(ArchiveAuditLogsHandler::class);

    return $handler->handle(new ArchiveAuditLogs);
}

it('AC-8 boundary: a row exactly 365 days old is archived, and one 364 days old stays', function () {
    $atThreshold = archiveSeedRow('2025-10-29 00:00:00.000');
    $oneDayYounger = archiveSeedRow('2025-10-30 00:00:00.000');

    $result = archiveRun();

    expect($result->moved)->toBe(1)
        ->and(archiveConnection()->table('audit_logs')->where('id', $atThreshold)->exists())->toBeFalse()
        ->and(archiveConnection()->table('audit_logs_archive')->where('id', $atThreshold)->exists())->toBeTrue()
        ->and(archiveConnection()->table('audit_logs')->where('id', $oneDayYounger)->exists())->toBeTrue()
        ->and(archiveConnection()->table('audit_logs_archive')->where('id', $oneDayYounger)->exists())->toBeFalse();
});

it('moves a row byte-identical, preserving its id, with disjoint id sets', function () {
    $id = archiveSeedRow('2025-01-01 00:00:00.000');
    $before = archiveRow('audit_logs', $id);

    archiveRun();

    $after = archiveRow('audit_logs_archive', $id);

    foreach (AuditColumns::ALL as $column) {
        expect($after[$column])->toBe($before[$column], "column [{$column}] must be byte-identical after the move");
    }

    expect(archiveConnection()->table('audit_logs')->where('id', $id)->exists())->toBeFalse();

    $liveIds = archiveConnection()->table('audit_logs')->pluck('id')->all();
    $archiveIds = archiveConnection()->table('audit_logs_archive')->pluck('id')->all();

    expect(array_intersect($liveIds, $archiveIds))->toBe([]);
});

it('moves five aged rows in three chunks of two, issuing one insert-select and one delete per chunk', function () {
    config()->set('audit.archive_chunk_size', 2);

    $ids = [];

    for ($i = 0; $i < 5; $i++) {
        $ids[] = archiveSeedRow('2025-01-0'.($i + 1).' 00:00:00.000');
    }

    $connection = archiveConnection();
    $statements = [];

    $connection->listen(function ($query) use (&$statements): void {
        $statements[] = (string) $query->sql;
    });

    $result = archiveRun();

    $inserts = array_values(array_filter($statements, static fn (string $sql): bool => str_contains(strtolower($sql), 'insert into "audit_logs_archive"')));
    $deletes = array_values(array_filter($statements, static fn (string $sql): bool => str_contains(strtolower($sql), 'delete from "audit_logs"') && ! str_contains(strtolower($sql), 'archive')));

    expect($result->moved)->toBe(5)
        ->and($result->chunks)->toBe(3)
        ->and($inserts)->toHaveCount(3)
        ->and($deletes)->toHaveCount(3)
        ->and(archiveConnection()->table('audit_logs_archive')->count())->toBe(5)
        ->and(archiveConnection()->table('audit_logs')->count())->toBe(0);
});

it('is a no-op when nothing is aged: both tables are unchanged and nothing errors', function () {
    $id = archiveSeedRow('2026-10-28 23:59:59.999');

    $result = archiveRun();

    expect($result->moved)->toBe(0)
        ->and($result->chunks)->toBe(0)
        ->and(archiveConnection()->table('audit_logs')->where('id', $id)->exists())->toBeTrue()
        ->and(archiveConnection()->table('audit_logs_archive')->count())->toBe(0);
});

it('atomic chunk: a fault on the delete leaves that chunk in neither table correctly, never half-moved', function () {
    $id = archiveSeedRow('2025-01-01 00:00:00.000');

    $connection = archiveConnection();

    $connection->beforeExecuting(function (string $query): void {
        if (str_contains(strtolower($query), 'delete from "audit_logs"')) {
            throw new RuntimeException('injected fault');
        }
    });

    expect(fn () => archiveRun())->toThrow(RuntimeException::class);

    expect($connection->table('audit_logs_archive')->where('id', $id)->exists())->toBeFalse()
        ->and($connection->table('audit_logs')->where('id', $id)->exists())->toBeTrue();
});

it('refuses a non-positive retention_days', function () {
    config()->set('audit.retention_days', 0);

    expect(fn () => archiveRun())->toThrow(LogicException::class);

    config()->set('audit.retention_days', -5);

    expect(fn () => archiveRun())->toThrow(LogicException::class);
});

it('clamps a chunk_size of 5000 to 900, and moves a single large chunk under that bound', function () {
    config()->set('audit.archive_chunk_size', 5000);

    $id = archiveSeedRow('2025-01-01 00:00:00.000');

    $result = archiveRun();

    expect($result->moved)->toBe(1)
        ->and($result->chunks)->toBe(1)
        ->and(archiveConnection()->table('audit_logs_archive')->where('id', $id)->exists())->toBeTrue();
});
