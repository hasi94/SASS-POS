<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\RelayOutbox;

use Flexipos\Contracts\Audit\ActorType;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Support\Backoff;
use Flexipos\Eventbus\Support\RelayPacing;
use Flexipos\Eventbus\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims `outbox_events` rows where `status='pending'`, ordered by
 * `occurred_at`, in one bounded batch, and publishes them (T-018 D-4,
 * Ruling 6, AC-14 to AC-19).
 *
 * **This task assumes exactly one active relay worker per tenant at a time**
 * (D-4, AC-18). Nothing here enforces exclusivity, deliberately: a second,
 * concurrent relay worker racing on the same tenant's outbox, and the
 * claim-mark column that case would need, is explicitly out of scope - the
 * shipped T-008 migration's own docblock already anticipates it as a
 * follow-up.
 */
final class RelayOutboxHandler
{
    public const TABLE = 'outbox_events';

    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_BATCH_SIZE = 500;

    public const DEFAULT_MAX_ATTEMPTS = 5;

    public const DEFAULT_BACKOFF_BASE_SECONDS = 30;

    public const DEFAULT_BACKOFF_CAP_SECONDS = 600;

    public const MAX_ERROR_LENGTH = 1000;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly TenantContext $context,
        private readonly EventBus $bus,
        private readonly RunnerClock $clock,
        private readonly RelayPacing $pacing,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(RelayOutbox $command): RelayOutboxResult
    {
        $tenantId = $this->context->tenantId();
        $connection = $this->tenant->get();
        $stream = (string) $this->config->get('eventbus.stream', 'flexipos:events');

        $batchSize = min(
            $this->positiveInt('eventbus.relay.batch_size', self::DEFAULT_BATCH_SIZE),
            self::MAX_BATCH_SIZE
        );

        $maxAttempts = $this->positiveInt('eventbus.relay.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
        $base = $this->positiveInt('eventbus.relay.backoff_base_seconds', self::DEFAULT_BACKOFF_BASE_SECONDS);
        $cap = max($base, $this->positiveInt('eventbus.relay.backoff_cap_seconds', self::DEFAULT_BACKOFF_CAP_SECONDS));

        // order by occurred_at, id limit :batch - id is only a deterministic
        // tie-break inside this one ORDER BY, never a resume cursor
        // (docs/patterns.md section 5, AC-14).
        $rows = $connection->table(self::TABLE)
            ->where('status', 'pending')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        $published = 0;
        $retried = 0;
        $exhausted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $rowId = (string) $row->id;

            if (! $this->pacing->isDue($tenantId, $rowId)) {
                $skipped++;

                continue;
            }

            try {
                $envelope = self::toEnvelope($row, $tenantId);
                $this->bus->publish($stream, $envelope);
            } catch (Throwable $exception) {
                $attempts = (int) $row->attempts + 1;
                $message = mb_substr($exception->getMessage(), 0, self::MAX_ERROR_LENGTH);

                if ($attempts >= $maxAttempts) {
                    $connection->table(self::TABLE)
                        ->where('id', $rowId)
                        ->update(['attempts' => $attempts, 'last_error' => $message, 'status' => 'failed']);

                    $this->pacing->forget($tenantId, $rowId);
                    $exhausted++;

                    $this->logger->error('An outbox row exhausted its publish attempts and was marked failed.', [
                        'tenant_id' => $tenantId,
                        'outbox_id' => $rowId,
                        'type' => $row->type,
                        'attempts' => $attempts,
                    ]);
                } else {
                    $connection->table(self::TABLE)
                        ->where('id', $rowId)
                        ->update(['attempts' => $attempts, 'last_error' => $message]);

                    $this->pacing->markDueAfter($tenantId, $rowId, Backoff::delaySeconds($attempts, $base, $cap));
                    $retried++;
                }

                continue;
            }

            // Deliberately uncaught (W-3, review round 1): the bus
            // already has this event by the time we reach here, so a
            // failure marking the row published is a tenant-level failure
            // for the command to log, never a publish failure - counting
            // it as one would back the row off and republish it, even
            // though the bus received it every time.
            $connection->table(self::TABLE)
                ->where('id', $rowId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'published',
                    'published_at' => $this->clock->timestamp(),
                ]);

            $this->pacing->forget($tenantId, $rowId);
            $published++;
        }

        return new RelayOutboxResult($published, $retried, $exhausted, $skipped);
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

    private static function toEnvelope(object $row, string $tenantId): EventEnvelope
    {
        /** @var array<string, mixed> $actorData */
        $actorData = $row->actor !== null ? (array) json_decode((string) $row->actor, true) : [];

        $actor = new EventActor(
            ActorType::from((string) ($actorData['type'] ?? ActorType::System->value)),
            isset($actorData['id']) ? (string) $actorData['id'] : null,
            isset($actorData['name']) ? (string) $actorData['name'] : null,
        );

        $payload = $row->payload !== null ? json_decode((string) $row->payload, true) : null;

        return new EventEnvelope(
            (string) $row->id,
            $tenantId,
            (string) $row->type,
            (int) $row->version,
            (string) $row->aggregate_type,
            (string) $row->aggregate_id,
            $row->branch_id !== null ? (string) $row->branch_id : null,
            is_array($payload) ? $payload : null,
            (string) $row->occurred_at,
            $row->correlation_id !== null ? (string) $row->correlation_id : null,
            $row->causation_id !== null ? (string) $row->causation_id : null,
            $actor,
        );
    }
}
