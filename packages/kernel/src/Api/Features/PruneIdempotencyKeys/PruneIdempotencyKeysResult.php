<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\PruneIdempotencyKeys;

/**
 * How many of one tenant's `idempotency_keys` rows this run deleted (T-021
 * AC-23).
 */
final readonly class PruneIdempotencyKeysResult
{
    public function __construct(public int $deleted) {}
}
