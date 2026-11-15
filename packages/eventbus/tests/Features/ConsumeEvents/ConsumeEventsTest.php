<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Features\ConsumeEvents;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\Delivery;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;
use Flexipos\Eventbus\Features\ConsumeEvents\ConsumeEvents;
use Flexipos\Eventbus\Features\ConsumeEvents\ConsumeEventsHandler;
use Flexipos\Eventbus\Features\ConsumeEvents\ConsumeEventsResult;
use Flexipos\Eventbus\Tests\Support\FailingAcknowledgeEventBus;
use Flexipos\Eventbus\Tests\Support\FixtureEventConsumerProvider;
use Flexipos\Eventbus\Tests\Support\PingedConsumer;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 7 - the consumer runner: reclaim, read, handle, acknowledge,
| dead-letter (Ruling 7, AC-21 to AC-24, AC-27, AC-28)
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../../Support/eventbus-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    eventbusEnterTenant('tenant-a');
    eventbusCreatePingsTable();
    PingedConsumer::reset();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

function eventbusConsumePass(string $group, string $consumer = 'c1'): ConsumeEventsResult
{
    return app(ConsumeEventsHandler::class)->handle(new ConsumeEvents($group, $consumer));
}

it('AC-22: handle() runs inside the envelope tenant and acknowledges, leaving the pending list empty', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $result = eventbusConsumePass(PingedConsumer::GROUP, 'c1');

    expect($result->handled)->toBe(1);
    expect($bus->pending($stream, PingedConsumer::GROUP, 10))->toBe([]);
    expect(DB::connection(AccessTenantFixture::connectionName())->table('fixture_pings')->count())->toBe(1);
});

it('a type the consumer does not list is acknowledged as a no-op and the handler is not called', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'tenant-a'));

    // A different event type than the consumer declares.
    $envelope = new EventEnvelope(
        '01ARZ3NDEKTSV4RRFFQ69G5FBB',
        'tenant-a',
        'fixture.other',
        1,
        'fixture',
        '01ARZ3NDEKTSV4RRFFQ69G5FBB',
        null,
        null,
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );
    $bus->publish($stream, $envelope);

    $result = eventbusConsumePass(PingedConsumer::GROUP, 'c1');

    expect($result->handled)->toBe(1)
        ->and($result->acknowledgedNoop)->toBe(1);
    expect(PingedConsumer::$callCount)->toBe(1);
    expect($bus->pending($stream, PingedConsumer::GROUP, 10))->toBe([]);
});

it('AC-23: a message handled but not acknowledged (simulated crash) is reclaimed and handled once more by another consumer, ending in one effect', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    // c1 reads and applies the effect directly, then "crashes" before
    // acknowledging - simulated by calling the consumer directly rather
    // than through the runner, which always acknowledges right after a
    // successful handle().
    $delivered = $bus->read($stream, PingedConsumer::GROUP, 'c1', 10);
    (new PingedConsumer)->handle($delivered[0]->envelope);

    expect(PingedConsumer::$effectsApplied)->toBe(1);

    // The claim timeout for a first delivery is 60 seconds.
    $clock->advanceSeconds(60);
    $result = eventbusConsumePass(PingedConsumer::GROUP, 'c2');

    expect($result->reclaimed)->toBe(1)
        ->and($result->handled)->toBe(1);

    expect(DB::connection(AccessTenantFixture::connectionName())->table('fixture_pings')->count())->toBe(1);
    expect(PingedConsumer::$effectsApplied)->toBe(1);
});

it('control: without the idempotency check, the identical redelivery applies the effect a second time', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);
    PingedConsumer::$skipIdempotencyCheck = true;

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $delivered = $bus->read($stream, PingedConsumer::GROUP, 'c1', 10);
    (new PingedConsumer)->handle($delivered[0]->envelope);

    $clock->advanceSeconds(60);
    eventbusConsumePass(PingedConsumer::GROUP, 'c2');

    expect(PingedConsumer::$effectsApplied)->toBe(2);
});

it('W-2: a failed acknowledge() after a successful handle() propagates uncaught, is never logged as a consumer failure, and never dead-letters', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = new FailingAcknowledgeEventBus(app(InMemoryEventBus::class));
    app()->instance(EventBus::class, $bus);

    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $logger = eventbusRecordingLogger();

    expect(fn () => eventbusConsumePass(PingedConsumer::GROUP, 'c1'))->toThrow(EventBusUnreachable::class);

    // handle() ran and applied its effect before acknowledge() failed -
    // the opposite of "the consumer failed to handle the event".
    expect(DB::connection(AccessTenantFixture::connectionName())->table('fixture_pings')->count())->toBe(1);
    expect(PingedConsumer::$callCount)->toBe(1);

    // Before the fix, this exact scenario logged a "consumer failed"
    // warning and, on the 5th such delivery, dead-lettered a message that
    // was in fact handled every time.
    expect($logger->atLevel('warning'))->toBe([]);
    expect($logger->atLevel('critical'))->toBe([]);
    expect($bus->deadLetters($deadStream, 10, false))->toBe([]);
});

it('AC-24: reclaims at 60, then 120, then 240 seconds of idle, not before, with delivery counts 2, 3, 4', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);
    PingedConsumer::$throwTimes = 3;

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    eventbusConsumePass(PingedConsumer::GROUP, 'c1');

    $clock->advanceSeconds(59);
    expect(eventbusConsumePass(PingedConsumer::GROUP, 'c2')->reclaimed)->toBe(0);

    $clock->advanceSeconds(1);
    expect(eventbusConsumePass(PingedConsumer::GROUP, 'c2')->reclaimed)->toBe(1);
    expect($bus->pending($stream, PingedConsumer::GROUP, 10)[0]->deliveryCount)->toBe(2);

    $clock->advanceSeconds(119);
    expect(eventbusConsumePass(PingedConsumer::GROUP, 'c2')->reclaimed)->toBe(0);

    $clock->advanceSeconds(1);
    expect(eventbusConsumePass(PingedConsumer::GROUP, 'c2')->reclaimed)->toBe(1);
    expect($bus->pending($stream, PingedConsumer::GROUP, 10)[0]->deliveryCount)->toBe(3);

    $clock->advanceSeconds(239);
    expect(eventbusConsumePass(PingedConsumer::GROUP, 'c2')->reclaimed)->toBe(0);

    $clock->advanceSeconds(1);
    $final = eventbusConsumePass(PingedConsumer::GROUP, 'c2');
    expect($final->reclaimed)->toBe(1)
        ->and($final->handled)->toBe(1);
});

it('AC-27: at delivery 5 a throw moves the message to the dead stream, with the exception named in the reason, and logs one critical', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);
    PingedConsumer::$throwTimes = 5;

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $logger = eventbusRecordingLogger();

    eventbusConsumePass(PingedConsumer::GROUP, 'c1'); // delivery 1, throws

    foreach ([60, 120, 240, 480] as $idleSeconds) {
        $clock->advanceSeconds($idleSeconds);
        eventbusConsumePass(PingedConsumer::GROUP, 'c2'); // deliveries 2, 3, 4, 5
    }

    $deadLetters = $bus->deadLetters($deadStream, 10, false);

    expect($deadLetters)->toHaveCount(1);
    expect($deadLetters[0]->reason)->toContain('RuntimeException');
    expect($bus->pending($stream, PingedConsumer::GROUP, 10))->toBe([]);

    expect($logger->atLevel('critical'))->toHaveCount(1);

    // No tenant table but fixture_pings was touched - a dead-lettered
    // message never reached the effect at all.
    expect(DB::connection(AccessTenantFixture::connectionName())->table('fixture_pings')->count())->toBe(0);
});

it('AC-27 crash path: a message never acknowledged and never throwing is dead-lettered once its delivery count exceeds the maximum, and the handler is never called', function () {
    $clock = eventbusClockAt('2026-01-01 00:00:00');
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    $delivered = $bus->read($stream, PingedConsumer::GROUP, 'ghost', 10);
    $messageId = $delivered[0]->messageId;

    // Drive the delivery count from 1 to 5 directly on the bus: a ghost
    // consumer that keeps dying before acknowledging. No real wait is
    // needed to set the scenario up.
    for ($i = 0; $i < 4; $i++) {
        $bus->claim($stream, PingedConsumer::GROUP, 'ghost', [$messageId], 0);
    }

    expect($bus->pending($stream, PingedConsumer::GROUP, 10)[0]->deliveryCount)->toBe(5);

    $clock->advanceSeconds(100000);
    $result = eventbusConsumePass(PingedConsumer::GROUP, 'c-final');

    expect($result->deadLettered)->toBe(1);
    expect(PingedConsumer::$callCount)->toBe(0);

    $deadLetters = $bus->deadLetters($deadStream, 10, false);
    expect($deadLetters)->toHaveCount(1);
    expect($deadLetters[0]->reason)->toContain('not acknowledged after 5 deliveries');
});

it('a malformed envelope goes to the dead letter at once, without calling the handler', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');

    // A production bus never stores an entry EnvelopeCodec cannot decode -
    // EnvelopeCodecTest already proves that half. This proves the
    // runner's own reaction to one, through the exact private branch that
    // handles it: a Delivery whose envelope is null, the shape
    // EnvelopeCodec::decode() itself returns for a corrupt field.
    $handler = app(ConsumeEventsHandler::class);
    $process = new ReflectionMethod($handler, 'process');
    $process->setAccessible(true);

    $delivery = new Delivery('1-1', $stream, null, ['envelope' => 'not json'], 1);

    $outcome = $process->invoke($handler, $delivery, $stream, $deadStream, PingedConsumer::GROUP, new PingedConsumer, 5);

    expect($outcome)->toBe('dead_lettered');
    expect(PingedConsumer::$callCount)->toBe(0);
    expect($bus->deadLetters($deadStream, 10, false))->toHaveCount(1);
});

it('AC-28: a relay-side failed outbox row and a consumer-side dead letter are two distinct states', function () {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);
    PingedConsumer::$throwTimes = 5;

    $clock = eventbusClockAt('2026-01-01 00:00:00');

    $connection = DB::connection(AccessTenantFixture::connectionName());
    $connection->table('outbox_events')->insert([
        'id' => '01ARZ3NDEKTSV4RRFFQ69G5FCC',
        'type' => 'fixture.pinged',
        'version' => 1,
        'branch_id' => null,
        'aggregate_type' => 'fixture',
        'aggregate_id' => '01ARZ3NDEKTSV4RRFFQ69G5FCC',
        'payload' => null,
        'occurred_at' => '2026-01-01 00:00:00.000',
        'correlation_id' => null,
        'causation_id' => null,
        'actor' => json_encode(['type' => 'system', 'id' => null, 'name' => null]),
        'status' => 'failed',
        'attempts' => 5,
        'last_error' => 'relay-side failure',
    ]);

    $bus = app(InMemoryEventBus::class);
    $stream = (string) config('eventbus.stream');
    $deadStream = (string) config('eventbus.dead_letter_stream');
    $bus->ensureGroup($stream, PingedConsumer::GROUP);
    $bus->publish($stream, eventbusPingedEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FDD'));

    eventbusConsumePass(PingedConsumer::GROUP, 'c1');
    foreach ([60, 120, 240, 480] as $idleSeconds) {
        $clock->advanceSeconds($idleSeconds);
        eventbusConsumePass(PingedConsumer::GROUP, 'c2');
    }

    $failedRows = $connection->table('outbox_events')->where('status', 'failed')->pluck('id')->all();
    $deadLetters = $bus->deadLetters($deadStream, 10, false);

    expect($failedRows)->toBe(['01ARZ3NDEKTSV4RRFFQ69G5FCC']);
    expect($deadLetters)->toHaveCount(1);
    expect($deadLetters[0]->eventId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FDD');
});

it('W-4: a non-positive consumer config value throws LogicException rather than being silently clamped up to 1', function (string $key, int $value) {
    FixtureEventConsumerProvider::declaring(app(), [PingedConsumer::class]);
    config([$key => $value]);

    expect(fn () => eventbusConsumePass(PingedConsumer::GROUP, 'c1'))->toThrow(LogicException::class);
})->with([
    'max_deliveries' => ['eventbus.consumer.max_deliveries', 0],
    'claim_timeout_seconds' => ['eventbus.consumer.claim_timeout_seconds', -1],
    'backoff_cap_seconds' => ['eventbus.consumer.backoff_cap_seconds', 0],
    'batch_size' => ['eventbus.consumer.batch_size', -10],
]);
