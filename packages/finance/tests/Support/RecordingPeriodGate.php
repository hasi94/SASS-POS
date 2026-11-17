<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Support;

use Closure;
use Flexipos\Contracts\Finance\PeriodClearance;
use Flexipos\Contracts\Finance\PeriodGate;

/**
 * A spy over the real gate (T-033 chunks C and D): it records every date it is
 * asked about, then answers like the gate it wraps. `$before` runs first, so a
 * test can slip a row in between the replay look-up and the insert (the
 * stand-in has no second connection to race with, AC-40, AC-57).
 */
final class RecordingPeriodGate implements PeriodGate
{
    /**
     * @var list<string>
     */
    public array $dates = [];

    /**
     * @param  (Closure(): void)|null  $before
     */
    public function __construct(private readonly PeriodGate $inner, private readonly ?Closure $before = null) {}

    public function clear(mixed $date, ?string $actorId): PeriodClearance
    {
        $this->dates[] = (string) (is_string($date) ? $date : '');

        if ($this->before !== null) {
            ($this->before)();
        }

        return $this->inner->clear($date, $actorId);
    }
}
