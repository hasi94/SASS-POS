<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Support;

use Flexipos\Contracts\Audit\ActorType;
use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use JsonException;
use Throwable;

/**
 * Encodes and decodes an envelope as one JSON field, so the payload
 * round-trips exactly (T-018 Ruling 3, AC-3).
 *
 * `type` and `event_id` are duplicated as top-level wire fields, for
 * inspection only (an operator reading the raw stream, or `XRANGE`'s own
 * field listing) - the `envelope` field is the only one either side reads
 * back.
 *
 * **A corrupt or missing field decodes to null, never a throw.** A poison
 * message on the stream must stay inert data all the way to the dead-letter
 * move, not an exception the consumer runner has to additionally guard
 * against.
 */
final class EnvelopeCodec
{
    public const FIELD = 'envelope';

    /**
     * @return array<string, string>
     */
    public static function encode(EventEnvelope $envelope): array
    {
        return [
            self::FIELD => (string) json_encode(
                self::toArray($envelope),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'type' => $envelope->type,
            'event_id' => $envelope->id,
        ];
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function decode(array $fields): ?EventEnvelope
    {
        $raw = $fields[self::FIELD] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return self::fromArray($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(EventEnvelope $envelope): array
    {
        return [
            'id' => $envelope->id,
            'tenant_id' => $envelope->tenantId,
            'type' => $envelope->type,
            'version' => $envelope->version,
            'aggregate_type' => $envelope->aggregateType,
            'aggregate_id' => $envelope->aggregateId,
            'branch_id' => $envelope->branchId,
            'payload' => $envelope->payload,
            'occurred_at' => $envelope->occurredAt,
            'correlation_id' => $envelope->correlationId,
            'causation_id' => $envelope->causationId,
            'actor' => $envelope->actor->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromArray(array $data): ?EventEnvelope
    {
        try {
            $actorData = is_array($data['actor'] ?? null) ? $data['actor'] : [];

            $actor = new EventActor(
                ActorType::from((string) ($actorData['type'] ?? ActorType::System->value)),
                isset($actorData['id']) ? (string) $actorData['id'] : null,
                isset($actorData['name']) ? (string) $actorData['name'] : null,
            );

            $payload = $data['payload'] ?? null;

            return new EventEnvelope(
                (string) $data['id'],
                (string) $data['tenant_id'],
                (string) $data['type'],
                (int) $data['version'],
                (string) $data['aggregate_type'],
                (string) $data['aggregate_id'],
                isset($data['branch_id']) ? (string) $data['branch_id'] : null,
                is_array($payload) ? $payload : null,
                (string) $data['occurred_at'],
                isset($data['correlation_id']) ? (string) $data['correlation_id'] : null,
                isset($data['causation_id']) ? (string) $data['causation_id'] : null,
                $actor,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
