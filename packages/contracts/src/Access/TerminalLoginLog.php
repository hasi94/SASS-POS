<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Record that a terminal activated (T-014 AC-29).
 *
 * `access_log` has one writer, in access (T-010 D-21), so organization asks
 * for the row through this contract instead of inserting it.
 */
interface TerminalLoginLog
{
    /**
     * Write one `access_log` row of type `terminal_login` with `terminal_id`
     * and `branch_id` set, no employee, and the caller's printed address
     * packed. Runs on the connection the caller has entered, inside the
     * caller's transaction.
     */
    public function record(string $terminalId, string $branchId, ?string $ip): void;
}
