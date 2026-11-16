<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Carbon\CarbonImmutable;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use InvalidArgumentException;

/**
 * The one thing Purchasing needs a clock for: the branch-local business date an
 * order or a request is dated by (T-034 D-53c).
 *
 * A copy of the sales `BusinessClock` reduced to `businessDate()`: deptrac
 * forbids purchasing to import sales, and a third user would be the moment to
 * move it to the kernel. The date is read off {@see RunnerClock}, the one clock
 * a test can stop, in the **branch's own IANA timezone**, never the server's and
 * never the client's. Around midnight it differs from the UTC date, which is the
 * whole reason it is not `substr($timestamp, 0, 10)`.
 */
final class BusinessClock
{
    public function __construct(private readonly RunnerClock $clock) {}

    /**
     * The branch-local calendar date at this moment.
     *
     * @throws InvalidArgumentException when `$timezone` is not an IANA zone (Organization guarantees one)
     */
    public function businessDate(string $timezone): string
    {
        return $this->stored($this->clock->timestamp())->setTimezone($timezone)->format('Y-m-d');
    }

    private function stored(string $stored): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $stored) !== 1) {
            throw new InvalidArgumentException('A stored timestamp must be Y-m-d H:i:s with optional fraction digits.');
        }

        return CarbonImmutable::parse($stored, 'UTC');
    }
}
