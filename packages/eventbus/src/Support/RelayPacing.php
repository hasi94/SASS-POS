<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Support;

use Carbon\CarbonImmutable;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Between-attempt back-off, kept in process memory rather than as a new
 * `outbox_events` column (T-018 Ruling 6, D-12).
 *
 * A singleton map, keyed by tenant id and row id, of due instants read from
 * the injected {@see RunnerClock} - provable with no real wait (AC-17).
 *
 * **`attempts` stays the durable truth.** A process restart only forgets the
 * pacing map, never the attempt count, which gives at most one early retry
 * per row and never lets a row retry past the configured maximum across a
 * restart (risk 6).
 */
final class RelayPacing
{
    /**
     * @var array<string, CarbonImmutable>
     */
    private array $due = [];

    public function __construct(private readonly RunnerClock $clock) {}

    public function isDue(string $tenantId, string $rowId): bool
    {
        $key = self::key($tenantId, $rowId);

        if (! isset($this->due[$key])) {
            return true;
        }

        return ! $this->clock->now()->lt($this->due[$key]);
    }

    public function markDueAfter(string $tenantId, string $rowId, int $seconds): void
    {
        $this->due[self::key($tenantId, $rowId)] = $this->clock->now()->addSeconds($seconds);
    }

    public function forget(string $tenantId, string $rowId): void
    {
        unset($this->due[self::key($tenantId, $rowId)]);
    }

    private static function key(string $tenantId, string $rowId): string
    {
        return $tenantId.'|'.$rowId;
    }
}
