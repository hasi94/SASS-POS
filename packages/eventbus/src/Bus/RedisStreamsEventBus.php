<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

use Carbon\CarbonImmutable;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;
use Flexipos\Eventbus\Support\EnvelopeCodec;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use RuntimeException;
use Throwable;

/**
 * The real transport (T-018 Ruling 3 and 8): one event stream, one
 * dead-letter stream, shared by every tenant.
 *
 * **NOT VERIFIED against a real Redis connection in this environment**
 * (D-1): no Redis service, client dependency or Docker Compose file exists
 * in this repository today. {@see InMemoryEventBus}
 * proves the identical contract deterministically in the meantime, and
 * `EventBusContractTest`'s `redis` dataset row joins automatically the day
 * `EVENTBUS_REDIS_TEST_CONNECTION` names a reachable connection (Ruling 9).
 *
 * **Every Redis interaction runs through {@see self::raw()}.** It calls
 * `rawCommand` (phpredis) or `executeRaw` (predis), chosen with
 * `method_exists` on the client object, so PHPStan needs neither class
 * installed to analyse this file. Any `Throwable` from resolving the
 * connection or from running a command becomes {@see EventBusUnreachable} -
 * there is no catch-and-continue and no fallback (AC-4). The one non-error
 * it swallows is a `BUSYGROUP` reply in {@see self::ensureGroup()}.
 */
final class RedisStreamsEventBus implements EventBus
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection,
        private readonly int $maxLength,
    ) {}

    public function publish(string $stream, EventEnvelope $event): string
    {
        $args = ['XADD', $stream, 'MAXLEN', '~', (string) $this->maxLength, '*'];

        foreach (EnvelopeCodec::encode($event) as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }

        return (string) $this->run('publish', $args);
    }

    public function ensureGroup(string $stream, string $group): void
    {
        try {
            $this->run('ensureGroup', ['XGROUP', 'CREATE', $stream, $group, '$', 'MKSTREAM']);
        } catch (EventBusUnreachable $exception) {
            if (str_contains((string) $exception->getPrevious()?->getMessage(), 'BUSYGROUP')) {
                return;
            }

            throw $exception;
        }
    }

    public function read(string $stream, string $group, string $consumer, int $count): array
    {
        $reply = $this->run('read', [
            'XREADGROUP', 'GROUP', $group, $consumer, 'COUNT', (string) $count, 'STREAMS', $stream, '>',
        ]);

        return $this->parseReadReply($stream, $reply);
    }

    public function acknowledge(string $stream, string $group, string $messageId): void
    {
        $this->run('acknowledge', ['XACK', $stream, $group, $messageId]);
    }

    public function pending(string $stream, string $group, int $count): array
    {
        $reply = $this->run('pending', ['XPENDING', $stream, $group, '-', '+', (string) $count]);

        $result = [];

        if (! is_array($reply)) {
            return $result;
        }

        foreach ($reply as $entry) {
            if (! is_array($entry) || count($entry) < 4) {
                continue;
            }

            [$id, $consumer, $idle, $deliveryCount] = $entry;

            $result[] = new PendingDelivery((string) $id, $stream, (string) $consumer, (int) $idle, (int) $deliveryCount);
        }

        return $result;
    }

    public function claim(string $stream, string $group, string $consumer, array $ids, int $minIdleMs): array
    {
        if ($ids === []) {
            return [];
        }

        $reply = $this->run('claim', ['XCLAIM', $stream, $group, $consumer, (string) $minIdleMs, ...$ids]);

        $deliveries = [];

        if (! is_array($reply)) {
            return $deliveries;
        }

        // XCLAIM's own reply carries ids and fields, not the new delivery
        // count; XPENDING is the authority this adapter re-reads it from
        // immediately afterwards, with no extra state kept here.
        $counts = $this->deliveryCounts($stream, $group, $ids);

        foreach ($reply as $entry) {
            if (! is_array($entry) || count($entry) < 2) {
                continue;
            }

            [$id, $rawFields] = $entry;
            $id = (string) $id;
            $fields = self::pairs(is_array($rawFields) ? $rawFields : []);

            $deliveries[] = new Delivery($id, $stream, EnvelopeCodec::decode($fields), $fields, $counts[$id] ?? 1);
        }

        return $deliveries;
    }

    public function deadLetter(string $deadStream, Delivery $delivery, string $group, string $reason): string
    {
        $fields = [
            'event_id' => $delivery->envelope !== null ? $delivery->envelope->id : ($delivery->rawFields['event_id'] ?? ''),
            'tenant_id' => $delivery->envelope !== null ? $delivery->envelope->tenantId : '',
            'type' => $delivery->envelope !== null ? $delivery->envelope->type : ($delivery->rawFields['type'] ?? ''),
            'original_stream' => $delivery->stream,
            'original_message_id' => $delivery->messageId,
            'group' => $group,
            'delivery_count' => (string) $delivery->deliveryCount,
            'reason' => $reason,
            EnvelopeCodec::FIELD => $delivery->rawFields[EnvelopeCodec::FIELD] ?? '',
        ];

        $args = ['XADD', $deadStream, '*'];

        foreach ($fields as $key => $value) {
            $args[] = $key;
            $args[] = $value;
        }

        $deadId = (string) $this->run('deadLetter', $args);

        $this->acknowledge($delivery->stream, $group, $delivery->messageId);

        return $deadId;
    }

    public function deadLetters(string $deadStream, int $count, bool $newestFirst): array
    {
        $args = $newestFirst
            ? ['XREVRANGE', $deadStream, '+', '-', 'COUNT', (string) $count]
            : ['XRANGE', $deadStream, '-', '+', 'COUNT', (string) $count];

        $reply = $this->run('deadLetters', $args);

        $result = [];

        if (! is_array($reply)) {
            return $result;
        }

        foreach ($reply as $entry) {
            if (! is_array($entry) || count($entry) < 2) {
                continue;
            }

            [$id, $rawFields] = $entry;
            $fields = self::pairs(is_array($rawFields) ? $rawFields : []);

            $result[] = self::toDeadLetter((string) $id, $fields);
        }

        return $result;
    }

    public function replay(string $deadStream, string $deadId): string
    {
        $reply = $this->run('replay.read', ['XRANGE', $deadStream, $deadId, $deadId]);

        if (! is_array($reply) || $reply === []) {
            throw new RuntimeException("No dead-letter entry [{$deadId}] in stream [{$deadStream}].");
        }

        $entry = $reply[0];
        $rawFields = is_array($entry) && count($entry) >= 2 ? $entry[1] : [];
        $fields = self::pairs(is_array($rawFields) ? $rawFields : []);

        $originalStream = $fields['original_stream'] ?? '';

        $newId = (string) $this->run('replay.publish', [
            'XADD', $originalStream, '*',
            EnvelopeCodec::FIELD, $fields[EnvelopeCodec::FIELD] ?? '',
            'type', $fields['type'] ?? '',
            'event_id', $fields['event_id'] ?? '',
        ]);

        $this->run('replay.delete', ['XDEL', $deadStream, $deadId]);

        return $newId;
    }

    /**
     * @return list<Delivery>
     */
    private function parseReadReply(string $stream, mixed $reply): array
    {
        $deliveries = [];

        if (! is_array($reply)) {
            return $deliveries;
        }

        foreach ($reply as $streamReply) {
            if (! is_array($streamReply) || count($streamReply) < 2) {
                continue;
            }

            $entries = $streamReply[1];

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry) || count($entry) < 2) {
                    continue;
                }

                [$id, $rawFields] = $entry;
                $fields = self::pairs(is_array($rawFields) ? $rawFields : []);

                // New deliveries from XREADGROUP always start at count 1.
                $deliveries[] = new Delivery((string) $id, $stream, EnvelopeCodec::decode($fields), $fields, 1);
            }
        }

        return $deliveries;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function deliveryCounts(string $stream, string $group, array $ids): array
    {
        $reply = $this->run('pending', ['XPENDING', $stream, $group, '-', '+', (string) max(count($ids), 1)]);

        $counts = [];

        if (! is_array($reply)) {
            return $counts;
        }

        foreach ($reply as $entry) {
            if (! is_array($entry) || count($entry) < 4) {
                continue;
            }

            [$id, , , $deliveryCount] = $entry;

            $counts[(string) $id] = (int) $deliveryCount;
        }

        return $counts;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function toDeadLetter(string $id, array $fields): DeadLetter
    {
        $envelope = EnvelopeCodec::decode([EnvelopeCodec::FIELD => $fields[EnvelopeCodec::FIELD] ?? '']);
        $ms = (int) strtok($id, '-');

        return new DeadLetter(
            $id,
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

    /**
     * @param  list<mixed>  $flat
     * @return array<string, string>
     */
    private static function pairs(array $flat): array
    {
        $pairs = [];

        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            $pairs[(string) $flat[$i]] = (string) $flat[$i + 1];
        }

        return $pairs;
    }

    /**
     * @param  list<string>  $args
     *
     * @throws EventBusUnreachable
     */
    private function run(string $operation, array $args): mixed
    {
        try {
            $connection = $this->redis->connection($this->connection);
        } catch (Throwable $exception) {
            throw EventBusUnreachable::forConnection($this->connection, $operation, $exception);
        }

        try {
            return $this->raw($connection, $args);
        } catch (Throwable $exception) {
            throw EventBusUnreachable::forConnection($this->connection, $operation, $exception);
        }
    }

    /**
     * @param  list<string>  $args
     */
    private function raw(RedisConnection $connection, array $args): mixed
    {
        $client = $connection->client();

        if (method_exists($client, 'rawCommand')) {
            return $client->rawCommand(...$args);
        }

        if (method_exists($client, 'executeRaw')) {
            return $client->executeRaw($args);
        }

        throw new RuntimeException('The Redis client exposes neither rawCommand() nor executeRaw().');
    }
}
