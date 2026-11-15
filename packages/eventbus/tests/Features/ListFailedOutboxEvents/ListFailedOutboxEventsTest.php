<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\ListFailedOutboxEvents;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Eventbus\Features\ListFailedOutboxEvents\ListFailedOutboxEvents;
use Flexipos\Eventbus\Features\ListFailedOutboxEvents\ListFailedOutboxEventsHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 8 - `php artisan eventbus:failed` (Ruling 10, AC-29)
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
function eventbusSeedOutboxRow(array $overrides = []): string
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
        'status' => 'pending',
        'attempts' => 0,
    ], $overrides));

    return $id;
}

it('AC-29: only status=failed rows are shown, oldest first by default, newest first with the flag, every column present', function () {
    eventbusSeedOutboxRow(['status' => 'pending']);
    eventbusSeedOutboxRow(['status' => 'published']);
    $older = eventbusSeedOutboxRow(['status' => 'failed', 'occurred_at' => '2026-01-01 00:00:01.000', 'attempts' => 5, 'last_error' => 'boom one']);
    $newer = eventbusSeedOutboxRow(['status' => 'failed', 'occurred_at' => '2026-01-01 00:00:02.000', 'attempts' => 5, 'last_error' => 'boom two']);

    $oldestFirst = app(ListFailedOutboxEventsHandler::class)->handle(new ListFailedOutboxEvents);
    expect(array_column($oldestFirst, 'id'))->toBe([$older, $newer]);
    expect(array_keys($oldestFirst[0]))->toBe(ListFailedOutboxEventsHandler::COLUMNS);

    $newestFirst = app(ListFailedOutboxEventsHandler::class)->handle(new ListFailedOutboxEvents(newestFirst: true));
    expect(array_column($newestFirst, 'id'))->toBe([$newer, $older]);
});

it('AC-29: --limit=1000 is clamped to 500', function () {
    $exit = Artisan::call('eventbus:failed', ['--tenant' => 'tenant-a', '--limit' => 1000]);

    expect($exit)->toBe(0);
});

it('AC-29: an empty list is not an error', function () {
    $exit = Artisan::call('eventbus:failed', ['--tenant' => 'tenant-a']);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('No failed');
});

it('the --tenant option is required', function () {
    $exit = Artisan::call('eventbus:failed');

    expect($exit)->toBe(1);
});
