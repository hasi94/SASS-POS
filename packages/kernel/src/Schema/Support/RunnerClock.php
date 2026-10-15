<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Support;

use Carbon\CarbonImmutable;

/**
 * The runner's clock, as an object the container can replace (T-008 step 9).
 *
 * Every time the runner writes is written through this: the run's
 * `created_at`, a job's `claimed_at` and - the one that matters - a claim's
 * `lock_expires_at`. A claim expiry whose only input is the wall clock cannot
 * be tested at all without sleeping, and a test that sleeps for the configured
 * thirty minutes is a test nobody runs. AC-47 asks for "a job whose claim has
 * expired is claimed again, and a job whose claim has not expired is not" in
 * **one dataset**, which needs a now() that moves on demand.
 *
 * A concrete class rather than an interface with a binding, because the
 * handlers take framework contracts only and are built by reflection: the
 * default constructor argument is what lets the container build the real
 * clock with no registration at all, and a test replaces the instance.
 *
 * `now()` is immutable and `timestamp()` renders the precision the master
 * schema's `DATETIME(3)` columns carry - three fractional digits, because a
 * value written with six would be rounded by the engine and compared unequal
 * by the test that wrote it.
 */
final class RunnerClock
{
    /**
     * The format every `DATETIME(3)` column in the master schema is written in.
     */
    public const PRECISION = 'Y-m-d H:i:s.v';

    public function __construct(private ?CarbonImmutable $fixed = null) {}

    /**
     * A clock stopped at one instant, for a test that needs the expiry to be a
     * known distance away.
     */
    public static function fixedAt(string $at): self
    {
        return new self(CarbonImmutable::parse($at));
    }

    /**
     * Move a fixed clock forward. The real clock refuses, because a caller that
     * moved it would be measuring something it had moved itself.
     */
    public function advanceMinutes(int $minutes): void
    {
        if (! $this->fixed instanceof CarbonImmutable) {
            return;
        }

        $this->fixed = $this->fixed->addMinutes($minutes);
    }

    public function now(): CarbonImmutable
    {
        return $this->fixed ?? CarbonImmutable::now();
    }

    /**
     * Now, in the form a `DATETIME(3)` column takes.
     */
    public function timestamp(): string
    {
        return $this->now()->format(self::PRECISION);
    }

    /**
     * An instant this many minutes from now, in the form a `DATETIME(3)`
     * column takes.
     */
    public function timestampInMinutes(int $minutes): string
    {
        return $this->now()->addMinutes($minutes)->format(self::PRECISION);
    }
}
