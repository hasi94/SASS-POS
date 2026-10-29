<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Bus;

use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RuntimeException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 steps 3 and 4 - one contract dataset, run against the stand-in now
| and against a real Redis connection the day D-1 lifts (Ruling 9, AC-3,
| AC-20, AC-21, AC-22, AC-24, AC-25, AC-26, AC-30)
|--------------------------------------------------------------------------
|
| The `memory` row always runs. The `redis` row runs only when
| EVENTBUS_REDIS_TEST_CONNECTION names a configured Redis connection -
| otherwise it is skipped with a reason naming D-1. When the variable is
| set and Redis is unreachable, the case FAILS - "it fails, it never
| skips" - because the skip guard below only ever checks whether the
| variable is set, never whether the connection answers.
*/

uses(TestCase::class);

/**
 * `$test->markTestSkipped()` is declared `: never` by PHPUnit, so the
 * `memory` branch is this function's only reachable return when no Redis
 * connection is configured - there is no third, "skipped", outcome to
 * model in the return type.
 */
function eventbusContractBus(PHPUnitTestCase $test, string $driver, RunnerClock $clock): EventBus
{
    if ($driver === 'memory') {
        return new InMemoryEventBus($clock);
    }

    $connection = getenv('EVENTBUS_REDIS_TEST_CONNECTION');

    if ($connection === false || $connection === '') {
        $test->markTestSkipped(
            'REDIS ENGINE (T-018 D-1): no Redis infrastructure or client in this repository; '.
            'set EVENTBUS_REDIS_TEST_CONNECTION to run'
        );
    }

    return new RedisStreamsEventBus(Redis::getFacadeRoot(), $connection, 100000);
}

function eventbusContractEnvelope(string $id, string $type = 'fixture.pinged'): EventEnvelope
{
    return new EventEnvelope(
        $id,
        'tenant-a',
        $type,
        1,
        'fixture',
        $id,
        null,
        ['n' => 1, 'nested' => ['x' => 'y']],
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );
}

$drivers = ['memory', 'redis'];

it('publishes then reads back the identical envelope (AC-3 on the stand-in)', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $envelope = eventbusContractEnvelope('01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $bus->ensureGroup($stream, 'g1');
    $bus->publish($stream, $envelope);

    $delivered = $bus->read($stream, 'g1', 'c1', 10);

    expect($delivered)->toHaveCount(1);
    expect($delivered[0]->envelope?->payload)->toBe($envelope->payload);
    expect($delivered[0]->envelope)->toBeInstanceOf(EventEnvelope::class);
    expect($delivered[0]->envelope?->id)->toBe($envelope->id);
})->with($drivers);

it('delivers each message to exactly one consumer of a group (AC-21)', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $bus->ensureGroup($stream, 'g1');

    foreach (['e1', 'e2', 'e3'] as $id) {
        $bus->publish($stream, eventbusContractEnvelope($id));
    }

    $byC1 = $bus->read($stream, 'g1', 'c1', 10);
    $byC2 = $bus->read($stream, 'g1', 'c2', 10);

    $idsC1 = array_map(static fn ($d) => $d->envelope?->id, $byC1);
    $idsC2 = array_map(static fn ($d) => $d->envelope?->id, $byC2);

    expect(array_intersect($idsC1, $idsC2))->toBe([]);
    expect(array_merge($idsC1, $idsC2))->toEqualCanonicalizing(['e1', 'e2', 'e3']);
})->with($drivers);

it('never redelivers an acknowledged message (AC-22)', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $bus->ensureGroup($stream, 'g1');
    $bus->publish($stream, eventbusContractEnvelope('e1'));

    $delivered = $bus->read($stream, 'g1', 'c1', 10);
    $bus->acknowledge($stream, 'g1', $delivered[0]->messageId);

    $clock->advanceMinutes(60);

    $claimed = $bus->claim($stream, 'g1', 'c2', [$delivered[0]->messageId], 1);

    expect($claimed)->toBe([]);
})->with($drivers);

it('is not claimable before minIdle and is claimable at minIdle, with delivery count 2 (AC-24, AC-25 on the stand-in)', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $bus->ensureGroup($stream, 'g1');
    $bus->publish($stream, eventbusContractEnvelope('e1'));

    $delivered = $bus->read($stream, 'g1', 'c1', 10);
    $messageId = $delivered[0]->messageId;

    $clock->advanceSeconds(59);
    expect($bus->claim($stream, 'g1', 'c2', [$messageId], 60_000))->toBe([]);

    $clock->advanceSeconds(1);
    $claimed = $bus->claim($stream, 'g1', 'c2', [$messageId], 60_000);

    expect($claimed)->toHaveCount(1);
    expect($claimed[0]->deliveryCount)->toBe(2);
})->with($drivers);

it('does not let a group see a publish that happened before it was created, but does see one after', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $bus->publish($stream, eventbusContractEnvelope('before'));

    $bus->ensureGroup($stream, 'late-group');
    $bus->publish($stream, eventbusContractEnvelope('after'));

    $delivered = $bus->read($stream, 'late-group', 'c1', 10);
    $ids = array_map(static fn ($d) => $d->envelope?->id, $delivered);

    expect($ids)->toBe(['after']);
})->with($drivers);

it('ensureGroup is idempotent', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $bus->ensureGroup($stream, 'g1');
    $bus->ensureGroup($stream, 'g1');

    $bus->publish($stream, eventbusContractEnvelope('e1'));

    expect($bus->read($stream, 'g1', 'c1', 10))->toHaveCount(1);
})->with($drivers);

it('dead-letters a message, acknowledges the original, and lists oldest and newest first (AC-27, AC-30 on the stand-in)', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $deadStream = 'contract-dead-'.uniqid('', true);
    $bus->ensureGroup($stream, 'g1');

    $bus->publish($stream, eventbusContractEnvelope('e1'));
    $bus->publish($stream, eventbusContractEnvelope('e2'));

    $delivered = $bus->read($stream, 'g1', 'c1', 10);

    $bus->deadLetter($deadStream, $delivered[0], 'g1', 'reason one');
    $clock->advanceSeconds(1);
    $bus->deadLetter($deadStream, $delivered[1], 'g1', 'reason two');

    // Acknowledged in the original: a reclaim of the same message, far in
    // the future, is claimable by no one because it is no longer pending.
    $clock->advanceMinutes(60);
    expect($bus->claim($stream, 'g1', 'c2', [$delivered[0]->messageId], 1))->toBe([]);

    $oldestFirst = $bus->deadLetters($deadStream, 10, false);
    $newestFirst = $bus->deadLetters($deadStream, 10, true);

    expect(array_map(static fn ($d) => $d->eventId, $oldestFirst))->toBe(['e1', 'e2']);
    expect(array_map(static fn ($d) => $d->eventId, $newestFirst))->toBe(['e2', 'e1']);
    expect($oldestFirst[0]->reason)->toBe('reason one');
})->with($drivers);

it('replays a dead letter with the identical envelope, and removes it; an unknown id refuses', function (string $driver) {
    $clock = RunnerClock::fixedAt('2026-01-01 00:00:00');
    $bus = eventbusContractBus($this, $driver, $clock);

    $stream = 'contract-'.uniqid('', true);
    $deadStream = 'contract-dead-'.uniqid('', true);
    $bus->ensureGroup($stream, 'g1');

    $envelope = eventbusContractEnvelope('e1');
    $bus->publish($stream, $envelope);

    $delivered = $bus->read($stream, 'g1', 'c1', 10);
    $deadId = $bus->deadLetter($deadStream, $delivered[0], 'g1', 'poison');

    $bus->replay($deadStream, $deadId);

    // g1 already existed when the replay happened, so it sees the
    // re-appended message on its next read - "every group sees it again"
    // (Ruling 10), proved on the one group this test already has.
    $replayedDelivery = $bus->read($stream, 'g1', 'c1', 10);

    expect($replayedDelivery)->toHaveCount(1);
    expect($replayedDelivery[0]->envelope?->payload)->toBe($envelope->payload);

    expect($bus->deadLetters($deadStream, 10, false))->toBe([]);

    expect(fn () => $bus->replay($deadStream, 'no-such-id'))->toThrow(RuntimeException::class);
})->with($drivers);
