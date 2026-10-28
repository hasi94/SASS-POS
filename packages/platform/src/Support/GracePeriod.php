<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;

/**
 * The past-due grace period (T-016 D-12, Ruling 15, AC-11, AC-16).
 *
 * The elapsed time is always measured from `subscriptions.past_due_at`, never
 * from `current_period_end` (AC-16): that column is set by a different event
 * and may predate or postdate the status actually entering `past_due`.
 */
final class GracePeriod
{
    public const DEFAULT_DAYS = 7;

    public const SETTINGS_KEY = 'billing.past_due_grace_days';

    /**
     * Read the configured grace period, in days.
     *
     * An absent row, or a malformed value (not an integer, or negative), both
     * fall back to `config('platform.past_due_grace_days')`; a malformed value
     * also logs at error level naming the key.
     */
    public static function days(Connection $master, int $configuredDefault = self::DEFAULT_DAYS): int
    {
        $row = $master->table('platform_settings')->where('key', self::SETTINGS_KEY)->first();

        if ($row === null) {
            return $configuredDefault;
        }

        $decoded = json_decode((string) $row->value, true);

        if (! is_int($decoded) || $decoded < 0) {
            Log::error('Malformed platform setting ['.self::SETTINGS_KEY.']; falling back to the configured default.');

            return $configuredDefault;
        }

        return $decoded;
    }

    /**
     * Has the grace period expired at `$now`, measured from `$pastDueAt`?
     *
     * Inclusive at the boundary (T-009 AC-19's convention): `now >=
     * pastDueAt + N days` expires.
     */
    public static function expired(DateTimeImmutable $pastDueAt, DateTimeImmutable $now, int $graceDays): bool
    {
        $deadline = $pastDueAt->modify("+{$graceDays} days");

        return $now->format('Y-m-d H:i:s.v') >= $deadline->format('Y-m-d H:i:s.v');
    }
}
