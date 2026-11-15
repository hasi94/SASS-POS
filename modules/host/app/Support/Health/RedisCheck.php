<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Redis, only when some configured driver actually uses it (T-021 Ruling 7,
 * D-13, AC-44): the cache store, the queue connection, or the event bus.
 *
 * `skipped` otherwise - missing Redis in the XAMPP/local mode never fails
 * readiness, because nothing shipped is relying on it.
 *
 * **Never goes through Laravel's own Redis integration.**
 * `QaEventbusSourceRulesTest` (T-018 AC-2) confines every reference to the
 * framework's Redis-namespaced classes and its Redis facade to
 * `packages/eventbus/src` - this is host code, outside that boundary. A
 * bare connectivity ping, through the `redis` PHP extension directly and
 * never the stream commands that boundary actually polices, reaches the
 * same bound probe without a second high-level Redis caller.
 *
 * **[REDIS]**: the real-outage half is not provable on this machine (no
 * `redis` PHP extension, no reachable Redis server); a bound throwing fake
 * proves the deterministic half (AC-44).
 */
final class RedisCheck implements ReadinessCheck
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): CheckStatus
    {
        $connections = $this->connectionsInUse();

        if ($connections === []) {
            return CheckStatus::Skipped;
        }

        $timeout = (float) $this->config->get('api.health.timeout_seconds', 2);

        foreach ($connections as $connection) {
            try {
                $this->ping($connection, $timeout);
            } catch (Throwable $exception) {
                Log::warning('Readiness: redis check failed.', [
                    'connection' => $connection,
                    'exception' => $exception::class,
                ]);

                return CheckStatus::Failed;
            }
        }

        return CheckStatus::Ok;
    }

    private function ping(string $connectionName, float $timeout): void
    {
        $extensionClass = 'Redis';

        if (! class_exists($extensionClass)) {
            throw new RuntimeException('The redis PHP extension is not loaded.');
        }

        /** @var array<string, mixed> $stanza */
        $stanza = (array) $this->config->get("database.redis.{$connectionName}", []);

        $client = new $extensionClass;

        try {
            $client->connect(
                (string) ($stanza['host'] ?? '127.0.0.1'),
                (int) ($stanza['port'] ?? 6379),
                $timeout
            );

            $password = $stanza['password'] ?? null;

            if (is_string($password) && $password !== '') {
                $client->auth($password);
            }

            $client->ping();
        } finally {
            $client->close();
        }
    }

    /**
     * @return list<string>
     */
    private function connectionsInUse(): array
    {
        $connections = [];

        $cacheDefault = (string) $this->config->get('cache.default');
        if ((string) $this->config->get("cache.stores.{$cacheDefault}.driver") === 'redis') {
            $connections[] = (string) $this->config->get("cache.stores.{$cacheDefault}.connection", 'default');
        }

        $queueDefault = (string) $this->config->get('queue.default');
        if ((string) $this->config->get("queue.connections.{$queueDefault}.driver") === 'redis') {
            $connections[] = (string) $this->config->get("queue.connections.{$queueDefault}.connection", 'default');
        }

        if ((string) $this->config->get('eventbus.driver') === 'redis') {
            $connections[] = (string) $this->config->get('eventbus.redis.connection', 'default');
        }

        return array_values(array_unique($connections));
    }
}
