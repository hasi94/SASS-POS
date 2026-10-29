<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Bus;

use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\InMemoryEventBus;
use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\RedisManager;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/*
|--------------------------------------------------------------------------
| T-018 step 4 - the Redis adapter fails loudly and never falls back
| (AC-4)
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
