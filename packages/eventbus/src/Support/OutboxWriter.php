<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Support;

use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\NewEvent;
use Flexipos\Contracts\Events\Outbox;
use Flexipos\Contracts\Events\OutboxEventRefused;
use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use JsonException;

/**
 * The one writer of `outbox_events` (T-018 D-3, AC-5, Ruling 5).
 *
 * **Validates before touching any connection** (AC-13): every rule below
 * runs against the `NewEvent` value object alone, so a caller with no tenant
 * resolved at all still gets `OutboxEventRefused` rather than
 * `Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved` - the control that
 * proves validation genuinely runs first.
 *
 * **Opens no transaction of its own** (AC-6). It inserts on the connection
 * `Flexipos\Eventbus\Support\TenantConnection` resolves - whatever
 * transaction the caller is already inside carries the row, so rolling back
 * the business change takes the outbox row with it.
 *
 * **Leaves `status`, `attempts` and `published_at` to the schema's own
 * defaults** (AC-7): the shipped migration is the one authority for those
 * three values, and restating them here would be a second one.
 */
final class OutboxWriter implements Outbox
{
    public const TABLE = 'outbox_events';

    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const MAX_TYPE_LENGTH = 80;

    private const MAX_AGGREGATE_TYPE_LENGTH = 80;

    private const IDENTIFIER_LENGTH = 26;

    public function __construct(
        private readonly RunnerClock $clock,
        private readonly RequestId $requestId,
        private readonly TenantConnection $tenant,
    ) {}

    public function record(NewEvent $event): string
    {
        self::validate($event);

        $connection = $this->tenant->get();

        $id = (string) Str::ulid();

        $connection->table(self::TABLE)->insert([
            'id' => $id,
            'type' => $event->type,
            'version' => $event->version,
            'branch_id' => $event->branchId,
            'aggregate_type' => trim($event->aggregateType),
            'aggregate_id' => $event->aggregateId,
            'payload' => $event->payload === null ? null : self::encode($event->payload),
            'occurred_at' => $this->clock->timestamp(),
            'correlation_id' => $event->correlationId ?? $this->requestId->current(),
            'causation_id' => $event->causationId,
            'actor' => self::encode(($event->actor ?? EventActor::system())->toArray()),
        ]);

        return $id;
    }

    /**
     * @throws OutboxEventRefused
     */
    private static function validate(NewEvent $event): void
    {
        if (
            $event->type === ''
            || mb_strlen($event->type) > self::MAX_TYPE_LENGTH
            || preg_match(self::TYPE_PATTERN, $event->type) !== 1
        ) {
            throw OutboxEventRefused::invalidType($event->type);
        }

        if (trim($event->aggregateType) === '' || mb_strlen($event->aggregateType) > self::MAX_AGGREGATE_TYPE_LENGTH) {
            throw OutboxEventRefused::invalidAggregateType($event->aggregateType);
        }

        self::validateIdentifier('aggregate_id', $event->aggregateId);

        if ($event->branchId !== null) {
            self::validateIdentifier('branch_id', $event->branchId);
        }

        if ($event->causationId !== null) {
            self::validateIdentifier('causation_id', $event->causationId);
        }

        if ($event->correlationId !== null) {
            self::validateIdentifier('correlation_id', $event->correlationId);
        }

        if ($event->version < 1 || $event->version > 65535) {
            throw OutboxEventRefused::invalidVersion($event->version);
        }

        if ($event->payload !== null) {
            try {
                json_encode($event->payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw OutboxEventRefused::invalidPayload($exception->getMessage());
            }
        }
    }

    /**
     * @throws OutboxEventRefused
     */
    private static function validateIdentifier(string $field, string $value): void
    {
        if (mb_strlen($value) !== self::IDENTIFIER_LENGTH || ! Str::isUlid($value) || $value !== strtoupper($value)) {
            throw OutboxEventRefused::invalidIdentifier($field, $value);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
