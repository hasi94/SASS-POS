<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tenancy;

use Flexipos\Auth\Features\IssueTokenPair\IssueTokenPairHandler;
use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * {@see TerminalSessions} over the master database (T-014 AC-26, AC-38).
 *
 * Organization works inside one tenant database and may not read the master;
 * this class is the two facts it needs from across that line, and it is
 * deliberately all of them.
 *
 * **`tenantPartFor()` returns `tenants.code` verbatim** (Ruling 7), from a live
 * row only: a soft-deleted tenant has no activation code to print. Whether the
 * stored code fits the activation-code format is the caller's question and its
 * refusal, because the format is published through the contracts package and
 * this class decides nothing about it.
 *
 * **`revokeAll()` is one statement and idempotent.** It revokes every refresh
 * row of principal type `terminal` for this `principal_id` **and this tenant**
 * that is not already revoked - a second terminal with the same id in another
 * tenant, a user's refresh tokens and rows already revoked are all untouched,
 * and a rerun changes nothing and returns 0, which is what makes a revoke that
 * stopped half way safe to repeat (D-21).
 */
final class MasterTerminalSessions implements TerminalSessions
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
    ) {}

    public function tenantPartFor(string $tenantId): string
    {
        $code = $this->master()->table('tenants')
            ->where('id', $tenantId)
            ->whereNull('deleted_at')
            ->value('code');

        if ($code === null) {
            throw new RuntimeException("No live tenant has id [{$tenantId}].");
        }

        return (string) $code;
    }

    public function revokeAll(string $tenantId, string $terminalId): int
    {
        $now = $this->clock->timestamp();

        return $this->master()->table('refresh_tokens')
            ->where('principal_type', IssueTokenPairHandler::PRINCIPAL_TYPE_TERMINAL)
            ->where('principal_id', $terminalId)
            ->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);
    }

    private function master(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));

        return $connection;
    }
}
