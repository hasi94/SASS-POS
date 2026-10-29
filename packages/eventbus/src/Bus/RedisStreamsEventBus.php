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
 *
 * **An error reply is not success, even though neither client throws on
 * one.** phpredis' `rawCommand()` returns `false` for an error reply
 * (`READONLY`, `OOM`, `NOAUTH`, `WRONGTYPE`, `NOGROUP`, ...) without
 * throwing; predis' `executeRaw()` returns the error text and sets its
 * by-reference `$error` flag, also without throwing. {@see self::raw()}
 * turns both shapes into a thrown failure, and {@see self::publish()},
 * {@see self::deadLetter()} and {@see self::replay()} additionally refuse
 * any reply that is not a well-formed stream id - R-1.
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

        return $this->assertStreamId($this->run('publish', $args), 'publish');
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

        // XCLAIM can legitimately return fewer ids than requested (even
        // zero) when another consumer already acknowledged the message or
        // it was trimmed by XADD MAXLEN ~ between the earlier pending()
        // call and this claim(). Only ids XCLAIM actually returned are
        // looked up in deliveryCounts(); a requested-but-not-claimed id
        // must never reach XPENDING.
        $entries = [];

        foreach ($reply as $entry) {
            if (! is_array($entry) || count($entry) < 2) {
                continue;
            }

            $entries[] = [(string) $entry[0], $entry[1]];
        }

        if ($entries === []) {
            return $deliveries;
        }

        // XCLAIM's own reply carries ids and fields, not the new delivery
        // count; XPENDING is the authority this adapter re-reads it from
        // immediately afterwards, with no extra state kept here.
        $counts = $this->deliveryCounts($stream, $group, array_column($entries, 0));

        foreach ($entries as [$id, $rawFields]) {
            $fields = self::pairs(is_array($rawFields) ? $rawFields : []);

            if (! isset($counts[$id])) {
                throw EventBusUnreachable::forConnection($this->connection, 'claim', new RuntimeException(
                    "No delivery count resolved for claimed id [{$id}] on stream [{$stream}] group [{$group}]."
                ));
            }

            $deliveries[] = new Delivery($id, $stream, EnvelopeCodec::decode($fields), $fields, $counts[$id]);
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

        $deadId = $this->assertStreamId($this->run('deadLetter', $args), 'deadLetter');

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

        $newId = $this->assertStreamId($this->run('replay.publish', [
            'XADD', $originalStream, '*',
            EnvelopeCodec::FIELD, $fields[EnvelopeCodec::FIELD] ?? '',
            'type', $fields['type'] ?? '',
            'event_id', $fields['event_id'] ?? '',
        ]), 'replay.publish');

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
     * One `XPENDING stream group <id> <id> 1` per claimed id - never the
     * group-wide `XPENDING stream group - + n`, which returns the oldest
     * `n` pending entries of the *whole group*, not the ids just claimed
     * (R-2). A claimed id with no pending entry fails loudly rather than
     * silently defaulting to a delivery count of 1.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function deliveryCounts(string $stream, string $group, array $ids): array
    {
        $counts = [];

        foreach ($ids as $id) {
            $reply = $this->run('pending', ['XPENDING', $stream, $group, $id, $id, '1']);

            if (! is_array($reply) || ! isset($reply[0]) || ! is_array($reply[0]) || count($reply[0]) < 4) {
                throw EventBusUnreachable::forConnection($this->connection, 'pending', new RuntimeException(
                    "XPENDING returned no pending entry for claimed id [{$id}] on stream [{$stream}] group [{$group}]."
                ));
            }

            [, , , $deliveryCount] = $reply[0];

            $counts[$id] = (int) $deliveryCount;
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
     * Neither client throws on an error reply (R-1): phpredis' `rawCommand`
     * returns `false`, predis' `executeRaw` returns the error text and sets
     * `$error` by reference. Both shapes become a thrown `RuntimeException`
     * here, which {@see self::run()} wraps in {@see EventBusUnreachable} -
     * so a `READONLY`/`OOM`/`NOAUTH`/`WRONGTYPE`/`NOGROUP`/`BUSYGROUP`
     * reply is never mistaken for a successful command.
     *
     * @param  list<string>  $args
     */
    private function raw(RedisConnection $connection, array $args): mixed
    {
        $client = $connection->client();

        if (method_exists($client, 'rawCommand')) {
            $reply = $client->rawCommand(...$args);

            if ($reply === false) {
                $error = method_exists($client, 'getLastError') ? $client->getLastError() : null;

                if (method_exists($client, 'clearLastError')) {
                    $client->clearLastError();
                }

                throw new RuntimeException(
                    $error !== null && $error !== '' ? (string) $error : 'Redis returned an error reply for '.$args[0]
                );
            }

            return $reply;
        }

        if (method_exists($client, 'executeRaw')) {
            return $this->callExecuteRaw($client, $args);
        }

        throw new RuntimeException('The Redis client exposes neither rawCommand() nor executeRaw().');
    }

    /**
     * Split out of {@see self::raw()} so `$client` can be narrowed to
     * {@see PredisRawClient} in a `@param` docblock one call frame away
     * from the `method_exists()` guard that justifies it (PHPStan would
     * otherwise report that guard as always true, since a native type
     * hint here would make PHPStan treat every caller as already proven).
     * That real type boundary is also what lets PHPStan track `$error`'s
     * by-reference mutation at all: against the untyped `object` this
     * method is declared to accept, `$error`'s inferred type never changes
     * through an opaque call, which is the bug this split exists to avoid
     * re-introducing.
     *
     * @param  PredisRawClient  $client
     * @param  list<string>  $args
     */
    private function callExecuteRaw(object $client, array $args): mixed
    {
        $error = false;
        $reply = $client->executeRaw($args, $error);

        if ($error) {
            throw new RuntimeException((string) $reply);
        }

        return $reply;
    }

    /**
     * Refuses any reply that is not a well-formed Redis stream id
     * (`<ms>-<seq>`), where `publish()`, `deadLetter()` and `replay()`
     * expect one (R-1) - a malformed reply is exactly as untrustworthy as
     * an error reply `raw()` already catches.
     */
    private function assertStreamId(mixed $reply, string $operation): string
    {
        $id = (string) $reply;

        if (preg_match('/^\d+-\d+$/', $id) !== 1) {
            throw EventBusUnreachable::forConnection($this->connection, $operation, new RuntimeException(
                "Redis returned a malformed stream id [{$id}] for [{$operation}]."
            ));
        }

        return $id;
    }
}
