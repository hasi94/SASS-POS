<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

/**
 * Mimics phpredis' own shape for an error reply (T-018 review round 1,
 * R-1): `rawCommand()` returns `false` without throwing, `getLastError()`
 * carries the error text until `clearLastError()` is called - the same
 * sequence `RedisStreamsEventBus::raw()` now reads.
 */
final class FakePhpRedisRawErrorClient
{
    public int $rawCommandCalls = 0;

    public bool $lastErrorCleared = false;

    public function __construct(private readonly string $error) {}

    public function rawCommand(string ...$args): bool
    {
        $this->rawCommandCalls++;

        return false;
    }

    public function getLastError(): ?string
    {
        return $this->lastErrorCleared ? null : $this->error;
    }

    public function clearLastError(): void
    {
        $this->lastErrorCleared = true;
    }
}
