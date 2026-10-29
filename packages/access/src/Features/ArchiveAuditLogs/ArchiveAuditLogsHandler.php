<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ArchiveAuditLogs;

use Flexipos\Access\Support\AuditColumns;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use LogicException;

/**
 * Move this tenant's aged `audit_logs` rows into `audit_logs_archive`, in
 * bounded chunks (T-017 Ruling 5, AC-8).
 *
 * **Byte-identical, by construction.** Each chunk's copy is one
 * `INSERT … SELECT` built from {@see AuditColumns::ALL} - the binary `ip`
 * and the JSON text never pass through PHP - and ids are preserved, so
 * archive order equals live order.
 *
 * **One tenant transaction per chunk, never one for the whole move.** A
 * crash between chunks leaves a consistent state and the next run
 * continues from it; a chunk that fails partway leaves neither table
 * holding any of that chunk's ids (never half the chunk inserted and none
 * deleted, or the reverse).
 *
 * **The only inserter into `audit_logs_archive` anywhere in the diff**
 * (AC-19) - the second half of `AuditWriter`
 * being the only inserter into `audit_logs`.
 */
final class ArchiveAuditLogsHandler
{
    /**
     * SQLite's default bound-parameter limit is 999; this stays
     * comfortably under it (Ruling 5).
     */
    public const MAX_CHUNK_SIZE = 900;

    public const DEFAULT_CHUNK_SIZE = 500;

    public const DEFAULT_RETENTION_DAYS = 365;

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws LogicException when the configured retention is not a
     *                        positive number of days
     */
    public function handle(ArchiveAuditLogs $command): ArchiveAuditLogsResult
    {
        $retentionDays = (int) $this->config->get('audit.retention_days', self::DEFAULT_RETENTION_DAYS);

        if ($retentionDays <= 0) {
            throw new LogicException(
                "audit.retention_days must be a positive number of days; [{$retentionDays}] is not a value a typo should produce."
            );
        }

        $chunkSize = max(1, min(
            (int) $this->config->get('audit.archive_chunk_size', self::DEFAULT_CHUNK_SIZE),
            self::MAX_CHUNK_SIZE
        ));

        $connection = $this->tenant->get();
        $threshold = $this->clock->now()->subDays($retentionDays)->format(RunnerClock::PRECISION);

        $moved = 0;
        $chunks = 0;

        while (true) {
            $ids = $connection->table('audit_logs')
                ->where('created_at', '<=', $threshold)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted = $connection->transaction(function () use ($connection, $ids): int {
                $connection->table('audit_logs_archive')->insertUsing(
                    AuditColumns::ALL,
                    $connection->table('audit_logs')->whereIn('id', $ids)->select(AuditColumns::ALL)
                );

                return $connection->table('audit_logs')->whereIn('id', $ids)->delete();
            });

            // The delete statement's own affected-row count (S-2), not
            // count($ids): a manual `audit:archive --tenant=X` run that
            // overlaps the scheduled one can find some of these ids already
            // moved, and the report must say how many rows this chunk
            // actually deleted, not how many it asked for.
            $moved += $deleted;
            $chunks++;
        }

        return new ArchiveAuditLogsResult($moved, $chunks);
    }
}
