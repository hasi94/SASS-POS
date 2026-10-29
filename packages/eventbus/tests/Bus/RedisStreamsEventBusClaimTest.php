<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Bus;

use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;
use Flexipos\Eventbus\Tests\Support\FakeRedisConnection;
use Flexipos\Eventbus\Tests\Support\FakeXClaimRedisClient;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 review round 1, R-2 - claim() must ask XPENDING for each claimed id
| individually; the group-wide "oldest N pending entries" shape gives a
| claimed message the wrong delivery count whenever it is not the group's
| single oldest pending entry
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('R-2: claiming a non-oldest pending id gets its own XPENDING-reported delivery count, not the oldest id\'s and not a fallback of 1', function () {
    $olderId = '1700000000000-0';
    $newerId = '1700000000001-0';

    // The older id's own delivery count (7) is deliberately the larger
    // one, so a group-wide "give me the oldest pending entry" query
    // returning it in place of the newer id's own count (3) could never
    // be mistaken for a coincidence.
    $client = new FakeXClaimRedisClient(
        deliveryCountsById: [$olderId => 7, $newerId => 3],
        claimedIds: [$newerId],
    );

    $factory = new class($client) implements RedisFactory
    {
        public function __construct(private readonly FakeXClaimRedisClient $client) {}

        public function connection($name = null)
        {
            return new FakeRedisConnection($this->client);
        }
    };

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    $claimed = $bus->claim('flexipos:events', 'g1', 'c2', [$newerId], 0);

    expect($claimed)->toHaveCount(1);
    expect($claimed[0]->messageId)->toBe($newerId);
    // Its own count (3) - never the older pending entry's count (7), and
    // never the silent fallback of 1 the pre-fix code used when the
    // group-wide query did not happen to return this exact id.
    expect($claimed[0]->deliveryCount)->toBe(3);

    // XPENDING was asked for the claimed id specifically, never a
    // group-wide range.
    expect($client->xpendingCallsById)->toBe([$newerId]);
});

it('R-2: a claimed id with no XPENDING entry fails loudly instead of defaulting to delivery count 1', function () {
    $client = new FakeXClaimRedisClient(deliveryCountsById: [], claimedIds: ['1700000000000-0']);

    $factory = new class($client) implements RedisFactory
    {
        public function __construct(private readonly FakeXClaimRedisClient $client) {}

        public function connection($name = null)
        {
            return new FakeRedisConnection($this->client);
        }
    };

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    expect(fn () => $bus->claim('flexipos:events', 'g1', 'c2', ['1700000000000-0'], 0))
        ->toThrow(EventBusUnreachable::class);
});

/*
|--------------------------------------------------------------------------
| T-018 review round 2, R-3 - XCLAIM legitimately returning fewer ids than
| requested (including zero) is normal Redis behaviour, not an error; only
| ids XCLAIM actually returned may ever reach deliveryCounts()/XPENDING
|--------------------------------------------------------------------------
*/

it('R-3: an id requested but not actually claimed by XCLAIM returns no deliveries and never reaches XPENDING', function () {
    $client = new FakeXClaimRedisClient(deliveryCountsById: [], claimedIds: []);

    $factory = new class($client) implements RedisFactory
    {
        public function __construct(private readonly FakeXClaimRedisClient $client) {}

        public function connection($name = null)
        {
            return new FakeRedisConnection($this->client);
        }
    };

    $bus = new RedisStreamsEventBus($factory, 'fixture-connection', 100000);

    $claimed = $bus->claim('flexipos:events', 'g1', 'c2', ['1700000000000-0'], 0);

    expect($claimed)->toBe([]);
    // No XPENDING lookup was even attempted for an id that was never
    // actually claimed - distinct from the R-2 case, where the id was
    // claimed by XCLAIM but XPENDING had nothing for it.
    expect($client->xpendingCallsById)->toBe([]);
});
