<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

use Carbon\CarbonImmutable;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Support\EnvelopeCodec;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use RuntimeException;

/**
 * A deterministic, in-process stand-in for the Redis Streams adapter (T-018
 * Ruling 3, D-2).
 *
 * Pure PHP arrays, no I/O and no sleep - the same shape
 * `Flexipos\Kernel\Tenancy\InMemoryTenantFleet` already is. It implements
 * exactly the Redis rules {@see EventBus}
 * documents:
 *
 * - a message is delivered by {@see self::read()} to one consumer of a
 *   group only (AC-21);
 * - {@see self::acknowledge()} removes it from the pending list for good
 *   (AC-22);
 * - {@see self::claim()} reassigns only entries idle for at least
 *   `minIdleMs`, and increments their delivery count (AC-24);
 * - a group created after a publish does not see that publish - its cursor
 *   starts at the stream's current length.
 *
 * It never trims, and it is bound as a singleton so a relay pass and a
 * consume pass in **one process** share it. It cannot carry messages
 * between two processes, which is why {@see EventBusDriver}
 * refuses it outside local environments (Ruling 8).
 */
final class InMemoryEventBus implements EventBus
{
    /**
     * @var array<string, list<array{id: string, fields: array<string, string>}>>
     */
    private array $streams = [];

    /**
     * @var array<string, array<string, array{cursor: int, pending: array<string, array{consumer: string, count: int, deliveredAtMs: int}>}>>
     */
    private array $groups = [];

    private int $sequence = 0;

    public function __construct(private readonly RunnerClock $clock) {}

    public function publish(string $stream, EventEnvelope $event): string
    {
        $id = $this->nextId();

        $this->streams[$stream][] = ['id' => $id, 'fields' => EnvelopeCodec::encode($event)];

        return $id;
    }

    public function ensureGroup(string $stream, string $group): void
    {
        if (isset($this->groups[$stream][$group])) {
            return;
        }

        $this->groups[$stream][$group] = [
            'cursor' => count($this->streams[$stream] ?? []),
            'pending' => [],
        ];
    }

    public function read(string $stream, string $group, string $consumer, int $count): array
    {
        $this->ensureGroup($stream, $group);

        $entries = $this->streams[$stream] ?? [];
        $cursor = $this->groups[$stream][$group]['cursor'];
        $delivered = [];

        while ($cursor < count($entries) && count($delivered) < $count) {
            $entry = $entries[$cursor];
            $cursor++;

            $this->groups[$stream][$group]['pending'][$entry['id']] = [
                'consumer' => $consumer,
                'count' => 1,
                'deliveredAtMs' => $this->nowMs(),
            ];

            $delivered[] = $this->toDelivery($stream, $entry, 1);
        }

        $this->groups[$stream][$group]['cursor'] = $cursor;

        return $delivered;
    }

    public function acknowledge(string $stream, string $group, string $messageId): void
    {
        unset($this->groups[$stream][$group]['pending'][$messageId]);
    }

    public function pending(string $stream, string $group, int $count): array
    {
        $now = $this->nowMs();
        $result = [];

        foreach (($this->groups[$stream][$group]['pending'] ?? []) as $id => $info) {
            if (count($result) >= $count) {
                break;
            }

            $result[] = new PendingDelivery(
                $id,
                $stream,
                $info['consumer'],
                max(0, $now - $info['deliveredAtMs']),
                $info['count']
            );
        }

        return $result;
    }

    public function claim(string $stream, string $group, string $consumer, array $ids, int $minIdleMs): array
    {
        $now = $this->nowMs();
        $claimed = [];

        foreach ($ids as $id) {
            $info = $this->groups[$stream][$group]['pending'][$id] ?? null;

            if ($info === null) {
                continue;
            }

            $idle = $now - $info['deliveredAtMs'];

            if ($idle < $minIdleMs) {
                continue;
            }

            $newCount = $info['count'] + 1;

            $this->groups[$stream][$group]['pending'][$id] = [
                'consumer' => $consumer,
                'count' => $newCount,
                'deliveredAtMs' => $now,
            ];

            $entry = $this->findEntry($stream, $id);

            if ($entry === null) {
                continue;
            }

            $claimed[] = $this->toDelivery($stream, $entry, $newCount);
        }

        return $claimed;
    }

    public function deadLetter(string $deadStream, Delivery $delivery, string $group, string $reason): string
    {
        $deadId = $this->nextId();

        $this->streams[$deadStream][] = [
            'id' => $deadId,
            'fields' => [
                'event_id' => $delivery->envelope !== null ? $delivery->envelope->id : ($delivery->rawFields['event_id'] ?? ''),
                'tenant_id' => $delivery->envelope !== null ? $delivery->envelope->tenantId : '',
                'type' => $delivery->envelope !== null ? $delivery->envelope->type : ($delivery->rawFields['type'] ?? ''),
                'original_stream' => $delivery->stream,
                'original_message_id' => $delivery->messageId,
                'group' => $group,
                'delivery_count' => (string) $delivery->deliveryCount,
                'reason' => $reason,
                EnvelopeCodec::FIELD => $delivery->rawFields[EnvelopeCodec::FIELD] ?? '',
            ],
        ];

        // In this order: the original is only acknowledged once the dead
        // entry actually exists, mirroring the Redis adapter's XADD-then-XACK
        // sequence (Ruling 3) - a crash between the two leaves the message
        // dead-lettered but still pending in the original, never lost.
        $this->acknowledge($delivery->stream, $group, $delivery->messageId);

        return $deadId;
    }

    public function deadLetters(string $deadStream, int $count, bool $newestFirst): array
    {
        $entries = $this->streams[$deadStream] ?? [];

        if ($newestFirst) {
            $entries = array_reverse($entries);
        }

        $result = [];

        foreach ($entries as $entry) {
            if (count($result) >= $count) {
                break;
            }

            $result[] = $this->toDeadLetter($entry);
        }

        return $result;
    }

    public function replay(string $deadStream, string $deadId): string
    {
        $index = null;

        foreach (($this->streams[$deadStream] ?? []) as $i => $entry) {
            if ($entry['id'] === $deadId) {
                $index = $i;

                break;
            }
        }

        if ($index === null) {
            throw new RuntimeException("No dead-letter entry [{$deadId}] in stream [{$deadStream}].");
        }

        $fields = $this->streams[$deadStream][$index]['fields'];
        $originalStream = $fields['original_stream'];

        $newId = $this->nextId();

        $this->streams[$originalStream][] = [
            'id' => $newId,
            'fields' => [
                EnvelopeCodec::FIELD => $fields[EnvelopeCodec::FIELD] ?? '',
                'type' => $fields['type'] ?? '',
                'event_id' => $fields['event_id'] ?? '',
            ],
        ];

        array_splice($this->streams[$deadStream], $index, 1);

        return $newId;
    }

    /**
     * @param  array{id: string, fields: array<string, string>}  $entry
     */
    private function toDelivery(string $stream, array $entry, int $deliveryCount): Delivery
    {
        return new Delivery($entry['id'], $stream, EnvelopeCodec::decode($entry['fields']), $entry['fields'], $deliveryCount);
    }

    /**
     * @return array{id: string, fields: array<string, string>}|null
     */
    private function findEntry(string $stream, string $id): ?array
    {
        foreach ($this->streams[$stream] ?? [] as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array{id: string, fields: array<string, string>}  $entry
     */
    private function toDeadLetter(array $entry): DeadLetter
    {
        $fields = $entry['fields'];
        $envelope = EnvelopeCodec::decode([EnvelopeCodec::FIELD => $fields[EnvelopeCodec::FIELD] ?? '']);
        $ms = (int) strtok($entry['id'], '-');

        return new DeadLetter(
            $entry['id'],
            ($fields['event_id'] ?? '') !== '' ? $fields['event_id'] : null,
            ($fields['tenant_id'] ?? '') !== '' ? $fields['tenant_id'] : null,
            ($fields['type'] ?? '') !== '' ? $fields['type'] : null,
            $fields['original_stream'] ?? '',
            ($fields['original_message_id'] ?? '') !== '' ? $fields['original_message_id'] : null,
            $fields['group'] ?? '',
            (int) ($fields['delivery_count'] ?? 0),
            $fields['reason'] ?? '',
            CarbonImmutable::createFromTimestampMs($ms)->format(RunnerClock::PRECISION),
            $envelope,
        );
    }

    private function nextId(): string
    {
        return sprintf('%d-%d', $this->nowMs(), ++$this->sequence);
    }

    private function nowMs(): int
    {
        return $this->clock->now()->getTimestampMs();
    }
}
