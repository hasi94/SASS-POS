<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Hr;

use InvalidArgumentException;

/**
 * How many records of one kind point at a department or designation
 * (T-038 AC-86), for example `expense_items.department_id`.
 */
final readonly class HrReferrer
{
    public function __construct(
        public string $kind,
        public int $count,
    ) {
        if ($kind === '') {
            throw new InvalidArgumentException('[kind] must not be empty.');
        }

        if ($count < 0) {
            throw new InvalidArgumentException('[count] must not be negative.');
        }
    }
}
