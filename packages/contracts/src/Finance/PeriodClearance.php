<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

use InvalidArgumentException;

/**
 * The gate's answer: the one period a posting date falls in, and its status
 * (T-032 AC-47, AC-51).
 *
 * Carries an id and a status and nothing else, so no model class crosses the
 * module boundary.
 */
final readonly class PeriodClearance
{
    public string $periodId;

    public function __construct(
        string $periodId,
        public PeriodStatus $status,
    ) {
        if (preg_match('/\A[0-9A-Za-z]{26}\z/', $periodId) !== 1) {
            throw new InvalidArgumentException('[periodId] is not a 26-character ULID.');
        }

        $this->periodId = strtoupper($periodId);
    }
}
