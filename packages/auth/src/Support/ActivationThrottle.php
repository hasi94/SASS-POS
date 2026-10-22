<?php

declare(strict_types=1);

namespace Flexipos\Auth\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The guessing limit of the public activation route (T-014 D-18, AC-31,
 * Ruling 12).
 *
 * **A code is about 40 bits, the route is public, and this is what bounds
 * guessing**: after `max_failures` failures of any cause - counted per
 * tenant and, separately, per caller address - inside the window, further
 * attempts for that tenant (or from that address) are refused with the same
 * refusal **even with a correct code** until the window has passed.
 *
 * **Rules, each proved by a test and each easy to get quietly wrong:**
 *
 * - *Anchored to the first failure.* The window starts at the first failure
 *   and a later failure does not move it, so a patient attacker cannot keep a
 *   tenant locked and a shop is not locked longer by being attacked more.
 * - *A refusal while locked neither counts nor postpones the unlock*, the PIN
 *   lockout's rule (`config/access.php`).
 * - *Success does not reset the counters*: a guesser who finds the code on the
 *   ninth try has still made nine failures.
 * - *The tenant key is the tenant's id, not the code the caller typed*, and an
 *   unknown tenant part counts the address only, so a stranger probing
 *   made-up tenants consumes no tenant-keyed state and locks nobody out.
 * - *The address key is a SHA-256 of the address*, so no address is stored.
 *
 * **Time is the injected `RunnerClock`, not Laravel's `RateLimiter`**: the
 * limiter and the array store read Carbon's clock, which a test clock cannot
 * move, and AC-31's boundary needs a clock that can. The window is judged
 * here from the stored first-failure instant; the cache's own expiry is only
 * a janitor, set well beyond the window.
 *
 * {@see self::tenantKey()} and {@see self::addressKey()} are public so that
 * a test can build the mis-keyed double the isolation control needs.
 */
final class ActivationThrottle
{
    /** How long the cache keeps an entry whatever the window says. */
    private const JANITOR_SECONDS = 86400;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly RunnerClock $clock,
        private readonly ConfigRepository $config,
    ) {}

    public function tenantKey(string $tenantId): string
    {
        return 'terminal-activation:tenant:'.$tenantId;
    }

    public function addressKey(?string $ip): string
    {
        return 'terminal-activation:ip:'.hash('sha256', $ip ?? 'unknown');
    }

    /**
     * Has this key had its fill of failures inside its window?
     */
    public function isLocked(string $key): bool
    {
        $entry = $this->entry($key);

        return $entry !== null && $entry['count'] >= $this->maxFailures();
    }

    /**
     * Count one failure. A caller never calls this while the key is locked
     * (a refusal while locked is not counted), and a window that has elapsed
     * starts afresh at this failure.
     */
    public function recordFailure(string $key): void
    {
        $entry = $this->entry($key);

        $this->cache->put($key, [
            'count' => $entry === null ? 1 : $entry['count'] + 1,
            'first_at' => $entry === null ? $this->clock->now()->getTimestamp() : $entry['first_at'],
        ], self::JANITOR_SECONDS);
    }

    /**
     * @return array{count: int, first_at: int}|null null when absent or elapsed
     */
    private function entry(string $key): ?array
    {
        $stored = $this->cache->get($key);

        if (! is_array($stored) || ! is_int($stored['count'] ?? null) || ! is_int($stored['first_at'] ?? null)) {
            return null;
        }

        if ($this->clock->now()->getTimestamp() >= $stored['first_at'] + $this->windowSeconds()) {
            return null;
        }

        return ['count' => $stored['count'], 'first_at' => $stored['first_at']];
    }

    private function maxFailures(): int
    {
        return max(1, (int) $this->config->get('authentication.terminal_activation.max_failures', 10));
    }

    private function windowSeconds(): int
    {
        return max(1, (int) $this->config->get('authentication.terminal_activation.window_minutes', 15)) * 60;
    }
}
