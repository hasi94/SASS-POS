<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Idempotency;

/**
 * {@see IdempotencyStore::claim()}'s full answer: the outcome plus, on a
 * replay, the stored response to return unchanged (T-021 AC-19).
 */
final readonly class IdempotencyOutcome
{
    public function __construct(
        public IdempotencyClaim $outcome,
        public ?int $responseCode = null,
        public mixed $responseBody = null,
    ) {}

    public static function claimed(): self
    {
        return new self(IdempotencyClaim::Claimed);
    }

    public static function inProgress(): self
    {
        return new self(IdempotencyClaim::InProgress);
    }

    public static function reused(): self
    {
        return new self(IdempotencyClaim::Reused);
    }

    public static function replay(int $responseCode, mixed $responseBody): self
    {
        return new self(IdempotencyClaim::Replay, $responseCode, $responseBody);
    }
}
