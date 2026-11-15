<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ConsumeEvents;

use Flexipos\Contracts\Events\EventConsumer;
use Flexipos\Eventbus\Bus\Delivery;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Support\Backoff;
use Flexipos\Eventbus\Support\ConsumerRegistry;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One pass for one consumer group: reclaim, then read new messages, then
 * handle and acknowledge each one (T-018 Ruling 7, AC-21 to AC-24, AC-27).
 *
 * **Redelivery and reclaim use the stream's own native idle time and
 * delivery count** - never a second, application-level outbox dedup table
 * (D-6, AC-24). **Idempotency is the consumer's own job**, by
 * `$event->id`: this handler acknowledges after `handle()` returns, never
 * before, so a crash between the two leaves the message unacknowledged and
 * it is reclaimed and replayed - which is exactly the case
 * `EventConsumer::handle()`'s own docblock asks every implementation to
 * survive (AC-23).
 */
final class ConsumeEventsHandler
{
    public const DEFAULT_BATCH_SIZE = 10;

    public const DEFAULT_MAX_DELIVERIES = 5;

    public const DEFAULT_CLAIM_TIMEOUT_SECONDS = 60;

    public const DEFAULT_BACKOFF_CAP_SECONDS = 900;

    public function __construct(
        private readonly EventBus $bus,
        private readonly ConsumerRegistry $registry,
        private readonly Tenancy $tenancy,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(ConsumeEvents $command): ConsumeEventsResult
    {
        $stream = (string) $this->config->get('eventbus.stream', 'flexipos:events');
        $deadStream = (string) $this->config->get('eventbus.dead_letter_stream', 'flexipos:events:dead');
        $maxDeliveries = $this->positiveInt('eventbus.consumer.max_deliveries', self::DEFAULT_MAX_DELIVERIES);
        $claimTimeout = $this->positiveInt('eventbus.consumer.claim_timeout_seconds', self::DEFAULT_CLAIM_TIMEOUT_SECONDS);
        $cap = max($claimTimeout, $this->positiveInt('eventbus.consumer.backoff_cap_seconds', self::DEFAULT_BACKOFF_CAP_SECONDS));
        $batchSize = $this->positiveInt('eventbus.consumer.batch_size', self::DEFAULT_BATCH_SIZE);

        $consumer = $this->registry->resolve($command->group);

        $this->bus->ensureGroup($stream, $command->group);

        $handled = 0;
        $deadLettered = 0;
        $reclaimed = 0;
        $acknowledgedNoop = 0;

        // 1. Reclaim: entries idle at least delay(deliveryCount), the
        // consumer's own back-off built from the stream's native idle time
        // and delivery count, with no extra state kept here.
        foreach ($this->bus->pending($stream, $command->group, $batchSize) as $pendingEntry) {
            $delaySeconds = Backoff::delaySeconds($pendingEntry->deliveryCount, $claimTimeout, $cap);
            $minIdleMs = $delaySeconds * 1000;

            if ($pendingEntry->idleMs < $minIdleMs) {
                continue;
            }

            foreach ($this->bus->claim($stream, $command->group, $command->consumer, [$pendingEntry->messageId], $minIdleMs) as $delivery) {
                $reclaimed++;

                match ($this->process($delivery, $stream, $deadStream, $command->group, $consumer, $maxDeliveries)) {
                    'handled' => $handled++,
                    'dead_lettered' => $deadLettered++,
                    'noop' => $acknowledgedNoop++,
                    default => null,
                };
            }
        }

        // 2. New messages.
        foreach ($this->bus->read($stream, $command->group, $command->consumer, $batchSize) as $delivery) {
            match ($this->process($delivery, $stream, $deadStream, $command->group, $consumer, $maxDeliveries)) {
                'handled' => $handled++,
                'dead_lettered' => $deadLettered++,
                'noop' => $acknowledgedNoop++,
                default => null,
            };
        }

        return new ConsumeEventsResult($handled, $acknowledgedNoop, $deadLettered, $reclaimed);
    }

    /**
     * @return 'handled'|'dead_lettered'|'noop'|'retry'
     */
    private function process(
        Delivery $delivery,
        string $stream,
        string $deadStream,
        string $group,
        EventConsumer $consumer,
        int $maxDeliveries,
    ): string {
        if ($delivery->envelope === null) {
            $this->moveToDeadLetter($delivery, $deadStream, $group, 'malformed envelope');

            return 'dead_lettered';
        }

        if ($delivery->deliveryCount > $maxDeliveries) {
            $this->moveToDeadLetter($delivery, $deadStream, $group, "not acknowledged after {$maxDeliveries} deliveries");

            return 'dead_lettered';
        }

        if (! in_array($delivery->envelope->type, $consumer->eventTypes(), true)) {
            $this->bus->acknowledge($stream, $group, $delivery->messageId);

            return 'noop';
        }

        $envelope = $delivery->envelope;

        try {
            $this->tenancy->run($envelope->tenantId, static fn () => $consumer->handle($envelope));
        } catch (Throwable $exception) {
            if ($delivery->deliveryCount >= $maxDeliveries) {
                $this->moveToDeadLetter($delivery, $deadStream, $group, $exception::class.': '.$exception->getMessage());

                return 'dead_lettered';
            }

            $this->logger->warning('A consumer failed to handle an event; it will be retried.', [
                'group' => $group,
                'event_id' => $envelope->id,
                'delivery_count' => $delivery->deliveryCount,
                'exception' => $exception::class,
            ]);

            return 'retry';
        }

        // A failed acknowledge() after a successful handle() is not the
        // consumer's own failure (W-2): it is outside the try above, so
        // EventBusUnreachable propagates to the command loop uncaught,
        // rather than being logged as "a consumer failed to handle an
        // event" and risking a needless dead-letter replay of a message
        // that was in fact handled every time (idempotency already keeps
        // that safe, but the alert and the replay would both be false).
        $this->bus->acknowledge($stream, $group, $delivery->messageId);

        return 'handled';
    }

    /**
     * Every number this config file promises is validated here where it is
     * used (W-4, review round 1: plan Ruling 11): a non-positive value
     * throws loudly rather than being silently clamped up to 1, which would
     * produce a silently different schedule from a typo'd config value.
     *
     * @throws LogicException
     */
    private function positiveInt(string $key, int $default): int
    {
        $value = (int) $this->config->get($key, $default);

        if ($value < 1) {
            throw new LogicException("Configuration [{$key}] must be a positive integer, got [{$value}].");
        }

        return $value;
    }

    private function moveToDeadLetter(Delivery $delivery, string $deadStream, string $group, string $reason): void
    {
        $this->bus->deadLetter($deadStream, $delivery, $group, $reason);

        $this->logger->critical('An event was moved to the dead-letter stream.', [
            'event_id' => $delivery->envelope?->id,
            'tenant_id' => $delivery->envelope?->tenantId,
            'group' => $group,
            'stream' => $delivery->stream,
            'delivery_count' => $delivery->deliveryCount,
            'reason' => $reason,
        ]);
    }
}
