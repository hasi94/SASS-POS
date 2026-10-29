<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\RetryFailedOutboxEvent;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 8 - `php artisan eventbus:retry` (Ruling 10, AC-31)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../../Support/eventbus-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function eventbusSeedRetryRow(array $overrides = []): string
{
    $id = (string) Str::ulid();

    DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->insert(array_merge([
        'id' => $id,
        'type' => 'fixture.pinged',
        'version' => 1,
        'branch_id' => null,
        'aggregate_type' => 'fixture',
        'aggregate_id' => $id,
        'payload' => null,
        'occurred_at' => '2026-01-01 00:00:00.000',
        'correlation_id' => null,
        'causation_id' => null,
        'actor' => json_encode(['type' => 'system', 'id' => null, 'name' => null]),
        'status' => 'failed',
        'attempts' => 5,
        'last_error' => 'boom',
    ], $overrides));

    return $id;
}

/**
 * @return array<string, mixed>
 */
function eventbusRetryRow(string $id): array
{
    $row = DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->where('id', $id)->first();

    return $row === null ? [] : (array) $row;
}

it('AC-31: a failed row becomes pending with attempts reset to 0, keeping last_error as evidence', function () {
    $id = eventbusSeedRetryRow();

    $exit = Artisan::call('eventbus:retry', ['id' => $id, '--tenant' => 'tenant-a']);

    expect($exit)->toBe(0);

    $row = eventbusRetryRow($id);
    expect($row['status'])->toBe('pending')
        ->and((int) $row['attempts'])->toBe(0)
        ->and($row['last_error'])->toBe('boom');
});

it('AC-31: a pending id, a published id and an unknown id each refuse with exit 1, and nothing changes', function (string $status) {
    $id = eventbusSeedRetryRow(['status' => $status, 'attempts' => 0]);

    $exit = Artisan::call('eventbus:retry', ['id' => $id, '--tenant' => 'tenant-a']);

    expect($exit)->toBe(1);
    expect(eventbusRetryRow($id)['status'])->toBe($status);
})->with(['pending', 'published']);

it('AC-31: an unknown id refuses with exit 1', function () {
    $exit = Artisan::call('eventbus:retry', ['id' => (string) Str::ulid(), '--tenant' => 'tenant-a']);

    expect($exit)->toBe(1);
});
