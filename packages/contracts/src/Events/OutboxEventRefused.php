<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

use InvalidArgumentException;

/**
 * The outbox writer refused a `NewEvent` before touching any connection
 * (T-018 AC-13).
 *
 * **Not constructible without a cause.** The constructor is private and
 * every way in is a named constructor naming exactly which rule the event
 * broke - the same shape `Flexipos\Access\Exceptions\AccessRefused` uses for
 * the identical reason: a caller catching "refused" and nothing more cannot
 * tell a real defect from a validation message meant for a developer.
 */
final class OutboxEventRefused extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * `type` is empty, over 80 characters, or not dotted lower case
     * (`^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`, e.g. `sale.completed`) - the
     * "a typo is caught loudly" convention applied to the one string a
     * consumer subscribes by.
     */
    public static function invalidType(string $type): self
    {
        return new self(
            "Event type [{$type}] must be non-empty, at most 80 characters, and dotted lower case (e.g. [sale.completed])."
        );
    }

    /**
     * `aggregateType` is empty after trimming, or over 80 characters.
     */
    public static function invalidAggregateType(string $aggregateType): self
    {
        return new self(
            "Aggregate type [{$aggregateType}] must be non-empty after trimming, and at most 80 characters."
        );
    }

    /**
     * `aggregateId`, or a given `branchId`/`causationId`/`correlationId`, is
     * not a 26-character, upper-case Crockford ULID. A lower-case spelling
     * is refused - the safe side of FP-309 - rather than folded, the way
     * `Flexipos\Access\Support\BranchScope` folds a branch id.
     */
    public static function invalidIdentifier(string $field, string $value): self
    {
        return new self("The [{$field}] [{$value}] is not a 26-character, upper-case ULID.");
    }

    /**
     * `version` is outside 1 to 65535 (`SMALLINT UNSIGNED`).
     */
    public static function invalidVersion(int $version): self
    {
        return new self("Event version [{$version}] must be between 1 and 65535.");
    }

    /**
     * `payload` could not be encoded as JSON (invalid UTF-8, `NAN`, `INF`,
     * …). Never silently coerced.
     */
    public static function invalidPayload(string $reason): self
    {
        return new self("The event payload could not be encoded as JSON: {$reason}");
    }
}
