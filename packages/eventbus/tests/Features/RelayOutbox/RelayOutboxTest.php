<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\RelayOutbox;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Features\RelayOutbox\RelayOutbox;
use Flexipos\Eventbus\Features\RelayOutbox\RelayOutboxHandler;
use Flexipos\Eventbus\Features\RelayOutbox\RelayOutboxResult;
use Flexipos\Eventbus\Tests\Support\FailingEventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 6 - the relay: bounded batches, publish, mark, retry with
| back-off (D-4, Ruling 6, AC-12, AC-14 to AC-19)
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
function eventbusInsertOutboxRow(array $overrides = []): string
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

/**
 * @return array<string, mixed>
 */
function eventbusOutboxEventRow(string $id): array
{
    $row = DB::connection(AccessTenantFixture::connectionName())->table('outbox_events')->where('id', $id)->first();

    return $row === null ? [] : (array) $row;
}

function eventbusRelayPass(): RelayOutboxResult
{
    return app(RelayOutboxHandler::class)->handle(new RelayOutbox);
}

it('AC-14: claims the oldest rows by occurred_at in bounded batches, never a cursor on id', function () {
    config(['eventbus.relay.batch_size' => 2]);

    $connection = DB::connection(AccessTenantFixture::connectionName());

    eventbusInsertOutboxRow(['occurred_at' => '2026-01-01 00:00:05.000']);
    $oldest = eventbusInsertOutboxRow(['occurred_at' => '2026-01-01 00:00:01.000']);
    eventbusInsertOutboxRow(['occurred_at' => '2026-01-01 00:00:04.000']);
    $secondOldest = eventbusInsertOutboxRow(['occurred_at' => '2026-01-01 00:00:02.000']);
    eventbusInsertOutboxRow(['occurred_at' => '2026-01-01 00:00:03.000']);

    $connection->enableQueryLog();
    $result = eventbusRelayPass();
    $log = $connection->getQueryLog();
    $connection->disableQueryLog();

    expect($result->published)->toBe(2)
        ->and(eventbusOutboxEventRow($oldest)['status'])->toBe('published')
        ->and(eventbusOutboxEventRow($secondOldest)['status'])->toBe('published');

    $selectQuery = null;

    foreach ($log as $entry) {
        if (str_contains((string) $entry['query'], 'select') && str_contains((string) $entry['query'], 'outbox_events')) {
            $selectQuery = (string) $entry['query'];

            break;
        }
    }

    expect($selectQuery)->not->toBeNull();
    expect((string) $selectQuery)->toContain('"status" = ?');
    expect((string) $selectQuery)->toContain('order by "occurred_at"');
    expect((string) $selectQuery)->toContain('limit 2');
    expect((string) $selectQuery)->not->toContain('"id" >');
});

it('AC-15: published rows get published_at, a second pass republishes nothing, the envelope carries the tenant and version unchanged', function () {
    $clock = eventbusClockAt('2026-02-01 12:00:00');
    $id = eventbusInsertOutboxRow(['version' => 3]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, 'probe');

    $result = eventbusRelayPass();

    $row = eventbusOutboxEventRow($id);

    expect($result->published)->toBe(1)
        ->and($row['status'])->toBe('published')
        ->and($row['published_at'])->toBe($clock->timestamp());

    $delivered = $bus->read($stream, 'probe', 'c1', 10);
    expect($delivered)->toHaveCount(1);
    expect($delivered[0]->envelope?->tenantId)->toBe('tenant-a');
    expect($delivered[0]->envelope?->version)->toBe(3);

    // A second pass finds the row already `published`, so the bounded
    // `status='pending'` query selects nothing and the bus count is
    // unchanged (still the one message read above).
    $second = eventbusRelayPass();
    expect($second->published)->toBe(0);
});

it('AC-16, AC-17: counts attempts, records last_error, backs off, marks failed on the fifth failure, and logs error once', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    $id = eventbusInsertOutboxRow();

    app()->instance(EventBus::class, new FailingEventBus(app(InMemoryEventBus::class)));

    $logger = eventbusRecordingLogger();

    $result = eventbusRelayPass();
    expect($result->retried)->toBe(1);
    $row = eventbusOutboxEventRow($id);
    expect((int) $row['attempts'])->toBe(1)
        ->and($row['status'])->toBe('pending')
        ->and($row['last_error'])->not->toBeNull();

    // An immediate second pass skips the row: still due at the same instant.
    $second = eventbusRelayPass();
    expect($second->skipped)->toBe(1)
        ->and($second->retried)->toBe(0);
    expect((int) eventbusOutboxEventRow($id)['attempts'])->toBe(1);

    // 29 seconds is not enough (base 30s back-off for attempt 1).
    $clock->advanceSeconds(29);
    $third = eventbusRelayPass();
    expect($third->skipped)->toBe(1);
    expect((int) eventbusOutboxEventRow($id)['attempts'])->toBe(1);

    // The 30th second is due: attempt 2.
    $clock->advanceSeconds(1);
    $fourth = eventbusRelayPass();
    expect($fourth->retried)->toBe(1);
    expect((int) eventbusOutboxEventRow($id)['attempts'])->toBe(2);

    // Attempts 3, 4, 5 - the formula backs off 60s then 120s between them.
    $clock->advanceSeconds(60);
    eventbusRelayPass();
    expect((int) eventbusOutboxEventRow($id)['attempts'])->toBe(3);

    $clock->advanceSeconds(120);
    eventbusRelayPass();
    expect((int) eventbusOutboxEventRow($id)['attempts'])->toBe(4);

    $clock->advanceSeconds(240);
    $fifth = eventbusRelayPass();
    expect($fifth->exhausted)->toBe(1);

    $row = eventbusOutboxEventRow($id);
    expect((int) $row['attempts'])->toBe(5)
        ->and($row['status'])->toBe('failed');

    // A further pass no longer selects it.
    $clock->advanceSeconds(1000);
    $sixth = eventbusRelayPass();
    expect($sixth->published)->toBe(0)
        ->and($sixth->retried)->toBe(0)
        ->and($sixth->exhausted)->toBe(0)
        ->and($sixth->skipped)->toBe(0);

    expect($logger->atLevel('error'))->toHaveCount(1);
});

it('recovers: two failures, then the bus heals and the next due pass publishes the row', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    $id = eventbusInsertOutboxRow();

    $failing = new FailingEventBus(app(InMemoryEventBus::class), 2);
    app()->instance(EventBus::class, $failing);

    eventbusRelayPass();
    $clock->advanceSeconds(30);
    eventbusRelayPass();
    expect((int) eventbusOutboxEventRow($id)['attempts'])->toBe(2);

    $failing->heal();
    $clock->advanceSeconds(60);
    $result = eventbusRelayPass();

    expect($result->published)->toBe(1);
    expect(eventbusOutboxEventRow($id)['status'])->toBe('published');
});

it('AC-19: no pending rows changes nothing and does not error', function () {
    $result = eventbusRelayPass();

    expect($result->published)->toBe(0)
        ->and($result->retried)->toBe(0)
        ->and($result->exhausted)->toBe(0)
        ->and($result->skipped)->toBe(0);
});

it('AC-4 through the relay: EventBusUnreachable counts as an attempt and the row stays pending', function () {
    eventbusClockAt('2026-01-01 00:00:00');
    $id = eventbusInsertOutboxRow();

    app()->instance(EventBus::class, new FailingEventBus(app(InMemoryEventBus::class)));

    eventbusRelayPass();

    $row = eventbusOutboxEventRow($id);
    expect((int) $row['attempts'])->toBe(1)
        ->and($row['status'])->toBe('pending');
    expect((string) $row['last_error'])->toContain('FailingEventBus');
});
