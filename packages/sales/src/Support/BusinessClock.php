<?php

declare(strict_types=1);

namespace Flexipos\Sales\Support;

use Carbon\CarbonImmutable;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use InvalidArgumentException;

/**
 * The three things Sales needs a clock for (T-030 AC-1, AC-4, AC-27; R-10).
 *
 * - **The UTC instant** in the form the pricing contract takes
 *   (`YYYY-MM-DDTHH:MM:SS.mmmZ`), for `LineQuote::$at`;
 * - **the business date**: the branch-local `Y-m-d` at that instant, from the
 *   branch's own IANA timezone, never the server's and never the client's. Around
 *   midnight it differs from the UTC date, which is the whole reason it is not
 *   `substr($instant, 0, 10)`;
 * - **a stored `DATETIME(3)` as an instant**, the way the pricing adapter turns
 *   one into one: a refund is quoted at the original sale's `completed_at`.
 *
 * Everything is read off {@see RunnerClock}, the one clock a test can stop, and
 * a stored timestamp is a UTC wall time (the same convention
 * {@see RunnerClock::timestamp()} writes in).
 */
final class BusinessClock
{
    public function __construct(private readonly RunnerClock $clock) {}

    /**
     * Now, as the instant a quote is made at.
     */
    public function instant(): string
    {
        return self::instantOf($this->clock->timestamp());
    }

    /**
     * The branch-local calendar date at this moment.
     *
     * @throws InvalidArgumentException when `$timezone` is not an IANA zone (Organization guarantees one)
     */
    public function businessDate(string $timezone): string
    {
        return self::stored($this->clock->timestamp())->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * A `DATETIME(3)` value (`Y-m-d H:i:s` with up to six fraction digits) as
     * `YYYY-MM-DDTHH:MM:SS.mmmZ`.
     *
     * @throws InvalidArgumentException when it is not a timestamp
     */
    public static function instantOf(string $stored): string
    {
        return self::stored($stored)->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function stored(string $stored): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $stored) !== 1) {
            throw new InvalidArgumentException('A stored timestamp must be Y-m-d H:i:s with optional fraction digits.');
        }

        return CarbonImmutable::parse($stored, 'UTC');
    }
}
