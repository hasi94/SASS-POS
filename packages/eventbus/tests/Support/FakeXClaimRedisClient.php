<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Eventbus\Bus\RedisStreamsEventBus;
use RuntimeException;

/**
 * Scripts `XCLAIM` and `XPENDING` replies for {@see RedisStreamsEventBus::claim()}
 * (T-018 review round 1, R-2), so the production code's exact `XPENDING
 * stream group <id> <id> 1` call shape can be proved against a fake client
 * with no real Redis connection and no dependency on D-1 lifting.
 *
 * `$deliveryCountsById` is every pending id's own delivery count, as a real
 * `XPENDING` would report it for that id alone - never the group-wide
 * oldest-N-entries shape the pre-fix code queried. `$claimedIds` is which of
 * the ids an `XCLAIM` call actually claims (every entry here is assumed
 * eligible; this fake has no idle-time model).
 */
final class FakeXClaimRedisClient
{
    /**
     * @var list<string>
     */
    public array $xpendingCallsById = [];

    /**
     * @param  array<string, int>  $deliveryCountsById
     * @param  list<string>  $claimedIds
     */
    public function __construct(
        private readonly array $deliveryCountsById,
        private readonly array $claimedIds,
    ) {}

    public function rawCommand(string ...$args): mixed
    {
        $command = $args[0];

        if ($command === 'XCLAIM') {
            // XCLAIM stream group consumer minIdleMs id1 id2 ...
            $requestedIds = array_slice($args, 5);
            $claimed = array_values(array_intersect($requestedIds, $this->claimedIds));

            return array_map(
                static fn (string $id): array => [$id, ['envelope', '', 'type', 'fixture.pinged', 'event_id', $id]],
                $claimed
            );
        }

        if ($command === 'XPENDING') {
            // XPENDING stream group startId endId count - the per-id shape
            // R-2's fix queries; startId and endId are the same claimed id.
            $id = $args[3];
            $this->xpendingCallsById[] = $id;

            if (! isset($this->deliveryCountsById[$id])) {
                return [];
            }

            return [[$id, 'some-consumer', 0, $this->deliveryCountsById[$id]]];
        }

        throw new RuntimeException("FakeXClaimRedisClient: unexpected command [{$command}].");
    }
}
