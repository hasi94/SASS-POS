<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Closure;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use RuntimeException;

/**
 * Wraps any test double as the client {@see RedisStreamsEventBus::raw()}
 * calls `client()` on (T-018 review round 1, R-1).
 *
 * `Illuminate\Redis\Connections\Connection` is abstract and its real
 * subclasses (`PhpRedisConnection`, `PredisConnection`) both require a live
 * client to construct, so this is the minimal concrete subclass a fixture
 * needs: it never opens a real connection and never calls anything on the
 * wrapped client except what `client()` hands back.
 */
final class FakeRedisConnection extends RedisConnection
{
    public function __construct(private readonly object $fakeClient) {}

    public function client(): object
    {
        return $this->fakeClient;
    }

    /**
     * @param  list<string>|string  $channels
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        throw new RuntimeException('FakeRedisConnection does not support subscriptions.');
    }
}
