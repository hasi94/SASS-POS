<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Contracts;

use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\DeadLetter;
use Flexipos\Eventbus\Bus\Delivery;
use Flexipos\Eventbus\Bus\PendingDelivery;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;

/**
 * The transport port (T-018 Ruling 2 and 3): Redis Streams behind an
 * interface, with a deterministic in-memory stand-in proving the identical
 * contract.
 *
 * **Internal to this package, by design.** D-3 and AC-5 say modules publish
 * only through the outbox (`Flexipos\Contracts\Events\Outbox`); publishing
 * this port in `packages/contracts` would hand every module a way around
 * the outbox the boundary check could never flag.
 *
 * Every method throws {@see EventBusUnreachable} when the transport cannot
 * be reached - never a silent fallback to another implementation, never a
 * silently dropped event (AC-4).
 */
interface EventBus
{
    /**
     * `XADD stream MAXLEN ~ n * envelope <json> type <type> event_id <id>`.
     * Returns the new message id.
     *
     * @throws EventBusUnreachable
     */
    public function publish(string $stream, EventEnvelope $event): string;

    /**
     * `XGROUP CREATE stream group $ MKSTREAM`. A `BUSYGROUP` reply is
     * success: idempotent, so calling it once per declared group per process
     * is always safe.
     *
     * @throws EventBusUnreachable
     */
    public function ensureGroup(string $stream, string $group): void;

    /**
     * `XREADGROUP GROUP g c COUNT n STREAMS stream >`. New messages only;
     * each delivery's count is 1.
     *
     * @return list<Delivery>
     *
     * @throws EventBusUnreachable
     */
    public function read(string $stream, string $group, string $consumer, int $count): array;

    /**
     * `XACK`. An acknowledged message is never redelivered to this group
     * again.
     *
     * @throws EventBusUnreachable
     */
    public function acknowledge(string $stream, string $group, string $messageId): void;

    /**
     * `XPENDING stream group - + n`: id, consumer, idle time and delivery
     * count - all native to the stream, never a second, application-level
     * dedup table (AC-24).
     *
     * @return list<PendingDelivery>
     *
     * @throws EventBusUnreachable
     */
    public function pending(string $stream, string $group, int $count): array;

    /**
     * `XCLAIM`. The stream increments the delivery count; the returned
     * {@see Delivery} carries it.
     *
     * @param  list<string>  $ids
     * @return list<Delivery>
     *
     * @throws EventBusUnreachable
     */
    public function claim(string $stream, string $group, string $consumer, array $ids, int $minIdleMs): array;

    /**
     * `XADD` the delivery onto the dead stream, then `XACK` of the original
     * - in that order, so a crash between the two never loses the message
     * from both places. Returns the new dead-letter entry's id.
     *
     * @throws EventBusUnreachable
     */
    public function deadLetter(string $deadStream, Delivery $delivery, string $group, string $reason): string;

    /**
     * `XRANGE`/`XREVRANGE` over the dead stream.
     *
     * @return list<DeadLetter>
     *
     * @throws EventBusUnreachable
     */
    public function deadLetters(string $deadStream, int $count, bool $newestFirst): array;

    /**
     * `XADD` the stored envelope back onto its original stream, then `XDEL`
     * the dead entry. A crash between the two leaves a harmless duplicate in
     * the dead list, never a lost event. Returns the new message's id.
     *
     * @throws EventBusUnreachable
     */
    public function replay(string $deadStream, string $deadId): string;
}
