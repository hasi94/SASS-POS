<?php

declare(strict_types=1);

namespace Flexipos\Customers\Customers;

use Flexipos\Customers\Exceptions\CustomersRefused;

/**
 * The pure `status`/`blocked_reason` consistency rule (T-028 plan Ruling 7,
 * AC-9), checked against the **merged** state of the stored row and the
 * patch - never the patch alone, so a request that only touches
 * `blocked_reason` is still checked against whatever `status` the row
 * already holds.
 */
final class CustomerRules
{
    /**
     * @throws CustomersRefused validation
     */
    public static function checkStatusConsistency(CustomerStatus $status, ?string $blockedReason): void
    {
        if ($status === CustomerStatus::Blocked && $blockedReason === null) {
            throw CustomersRefused::validation('A blocked customer requires a blocked_reason.');
        }

        if ($status !== CustomerStatus::Blocked && $blockedReason !== null) {
            throw CustomersRefused::validation("A {$status->value} customer may not carry a blocked_reason.");
        }
    }

    /**
     * @throws CustomersRefused validation
     */
    public static function status(mixed $value): CustomerStatus
    {
        if (! is_string($value) || CustomerStatus::tryFrom($value) === null) {
            throw CustomersRefused::validation('The status must be one of: '.implode(', ', array_map(static fn (CustomerStatus $case): string => $case->value, CustomerStatus::cases())).'.');
        }

        return CustomerStatus::from($value);
    }
}
