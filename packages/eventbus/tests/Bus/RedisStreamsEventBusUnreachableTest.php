<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Bus;

use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;
use Flexipos\Eventbus\Tests\Support\FakePhpRedisRawErrorClient;
use Flexipos\Eventbus\Tests\Support\FakePredisRawErrorClient;
use Flexipos\Eventbus\Tests\Support\FakeRedisConnection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\RedisManager;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/*
|--------------------------------------------------------------------------
| T-018 step 4 - the Redis adapter fails loudly and never falls back
| (AC-4). Review round 1, R-1: an error reply from either client library
| is not success, even though neither one throws on it.
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

function eventbusFixtureEnvelope(): EventEnvelope
{
    return new EventEnvelope(
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'tenant-a',
        'fixture.pinged',
        1,
        'fixture',
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        null,
        null,
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );
}

/**
 * A `RedisFactory` whose `connection()` always hands back the given
 * connection, regardless of name.
 */
function eventbusFixedConnectionFactory(RedisConnection $connection): RedisFactory
{
    return new class($connection) implements RedisFactory
    {
        public function __construct(private readonly RedisConnection $connection) {}

        public function connection($name = null)
        {
            return $this->connection;
        }
    };
}

it('turns a connection resolution failure into EventBusUnreachable, naming the connection and keeping the previous exception', function () {
    $factory = new class implements RedisFactory
    {
        public function connection($name = null)
        {
            throw new RuntimeException('simulated: no such connection');
        }
    };

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    try {
        $bus->publish('flexipos:events', eventbusFixtureEnvelope());
        $this->fail('Expected EventBusUnreachable.');
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(EventBusUnreachable::class)
            ->and($exception->getMessage())->toContain('fixture-connection')
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('simulated: no such connection');
    }
});

it('turns a real but unreachable Redis connection into EventBusUnreachable, deterministically', function () {
    $manager = new RedisManager(app(), 'phpredis', [
        'fixture' => [
            'host' => '127.0.0.1',
            // Port 1 is a privileged, essentially never-listening port on
            // any development machine, so this is deterministic whether the
            // underlying cause is a missing phpredis extension or a refused
            // connection.
            'port' => 1,
            'timeout' => 0.5,
            'read_timeout' => 0.5,
        ],
    ]);

    $bus = new RedisStreamsEventBus($manager, 'fixture', 100000);

    expect(fn () => $bus->publish('flexipos:events', eventbusFixtureEnvelope()))
        ->toThrow(EventBusUnreachable::class);
});

it('never falls back to the in-memory bus after either failure', function () {
    $factory = new class implements RedisFactory
    {
        public function connection($name = null)
        {
            throw new RuntimeException('simulated: no such connection');
        }
    };

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    try {
        $bus->publish('flexipos:events', eventbusFixtureEnvelope());
    } catch (EventBusUnreachable) {
        // expected
    }

    $memory = app(InMemoryEventBus::class);
    $memory->ensureGroup('flexipos:events', 'g1');

    expect($memory->read('flexipos:events', 'g1', 'c1', 10))->toBe([]);
});

it('R-1: a phpredis error reply (rawCommand returns false) is EventBusUnreachable, never success', function () {
    $client = new FakePhpRedisRawErrorClient('READONLY You can\'t write against a read only replica.');
    $factory = eventbusFixedConnectionFactory(new FakeRedisConnection($client));

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    try {
        $bus->publish('flexipos:events', eventbusFixtureEnvelope());
        $this->fail('Expected EventBusUnreachable.');
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(EventBusUnreachable::class)
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('READONLY You can\'t write against a read only replica.');
    }

    expect($client->rawCommandCalls)->toBe(1)
        ->and($client->lastErrorCleared)->toBeTrue();
});

it('R-1: a predis error reply (executeRaw sets $error) is EventBusUnreachable, never success', function () {
    $client = new FakePredisRawErrorClient('OOM command not allowed when used memory > \'maxmemory\'.');
    $factory = eventbusFixedConnectionFactory(new FakeRedisConnection($client));

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    try {
        $bus->publish('flexipos:events', eventbusFixtureEnvelope());
        $this->fail('Expected EventBusUnreachable.');
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(EventBusUnreachable::class)
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()?->getMessage())->toBe('OOM command not allowed when used memory > \'maxmemory\'.');
    }

    expect($client->executeRawCalls)->toBe(1);
});

it('R-1: ensureGroup() still treats a BUSYGROUP error reply as idempotent success after the raw() fix', function () {
    $client = new FakePhpRedisRawErrorClient('BUSYGROUP Consumer Group name already exists');
    $factory = eventbusFixedConnectionFactory(new FakeRedisConnection($client));

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    // No exception: ensureGroup() reads the wrapped EventBusUnreachable's
    // previous exception message for "BUSYGROUP" and swallows it.
    $bus->ensureGroup('flexipos:events', 'g1');

    expect($client->rawCommandCalls)->toBe(1);
});

it('R-1: a malformed reply where a stream id is expected is EventBusUnreachable, never treated as the id', function () {
    // Not an error reply - rawCommand() returns a non-false, non-error
    // value - but not a well-formed `<ms>-<seq>` stream id either, which
    // is the shape a corrupted or unexpected server reply would take.
    $client = new class
    {
        public function rawCommand(string ...$args): string
        {
            return '';
        }
    };

    $bus = new RedisStreamsEventBus(eventbusFixedConnectionFactory(new FakeRedisConnection($client)), 'fixture-connection', 100000);

    try {
        $bus->publish('flexipos:events', eventbusFixtureEnvelope());
        $this->fail('Expected EventBusUnreachable.');
    } catch (Throwable $exception) {
        expect($exception)->toBeInstanceOf(EventBusUnreachable::class)
            ->and($exception->getMessage())->toContain('malformed stream id');
    }
});
