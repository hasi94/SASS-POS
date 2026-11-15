<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Contracts\Access\TerminalLoginLog;

/**
 * {@see TerminalLoginLog} over access's one `access_log` writer (T-014
 * AC-29).
 *
 * A small adapter and nothing more: the row is written by
 * {@see AccessLogWriter::terminalLogin()}, on the tenant connection the caller
 * has entered and inside the caller's transaction, so a rolled-back activation
 * takes its log row with it and no `terminal_login` row records an activation
 * that did not happen.
 */
final class TerminalLoginLogWriter implements TerminalLoginLog
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AccessLogWriter $log,
    ) {}

    public function record(string $terminalId, string $branchId, ?string $ip): void
    {
        $this->log->terminalLogin($this->tenant->get(), $terminalId, $branchId, $ip);
    }
}
