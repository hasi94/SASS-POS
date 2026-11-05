<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store that throws on every call (T-021 AC-33b, D-10): proves the
 * rate limiter fails open on a store outage.
 */
final class QaThrowingStore implements Store
{
    public function get($key): mixed
    {
        throw new RuntimeException('store down');
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        throw new RuntimeException('store down');
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('store down');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        throw new RuntimeException('store down');
    }

    public function increment($key, $value = 1): int|bool
    {
        throw new RuntimeException('store down');
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new RuntimeException('store down');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('store down');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('store down');
    }

    public function flush(): bool
    {
        throw new RuntimeException('store down');
    }

    public function getPrefix(): string
    {
        return '';
    }
}
