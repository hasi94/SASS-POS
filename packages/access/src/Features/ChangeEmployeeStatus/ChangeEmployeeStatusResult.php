<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ChangeEmployeeStatus;

/**
 * What one status change did (AC-27, AC-52).
 *
 * `$bumped` is a boolean rather than a count because exactly one employee
 * is affected, and it is reported rather than left to be inferred: whether
 * the change crossed the acting boundary is the fact AC-27 is about, and a
 * caller that had to compare versions before and after to learn it would be
 * re-deriving the handler's own decision.
 */
final class ChangeEmployeeStatusResult
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $from,
        public readonly string $status,
        public readonly bool $bumped,
    ) {}
}
