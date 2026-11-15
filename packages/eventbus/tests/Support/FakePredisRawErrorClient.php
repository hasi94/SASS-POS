<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

/**
 * Mimics predis' own shape for an error reply (T-018 review round 1, R-1):
 * `executeRaw()` returns the error text and sets its by-reference `$error`
 * flag, also without throwing - the second shape
 * `RedisStreamsEventBus::raw()` now reads.
 */
final class FakePredisRawErrorClient
{
    public int $executeRawCalls = 0;

    public function __construct(private readonly string $error) {}

    /**
     * @param  list<string>  $args
     */
    public function executeRaw(array $args, mixed &$error = null): string
    {
        $this->executeRawCalls++;

        $error = true;

        return $this->error;
    }
}
