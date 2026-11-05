<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that counts every call and never actually stores anything
 * (T-021 AC-33a): proves a liveness/readiness probe never touches the
 * limiter's store.
 */
final class QaCountingStore implements Store
{
    public int $calls = 0;

    public function get($key): mixed
    {
        $this->calls++;

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $this->calls++;

        return [];
    }

    public function put($key, $value, $seconds): bool
    {
        $this->calls++;

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        $this->calls++;

        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        $this->calls++;

        return $value;
    }

    public function decrement($key, $value = 1): int|bool
    {
        $this->calls++;

        return $value;
    }

    public function forever($key, $value): bool
    {
        $this->calls++;

        return true;
    }

    public function forget($key): bool
    {
        $this->calls++;

        return true;
    }

    public function flush(): bool
    {
        $this->calls++;

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
