<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SearchAuditLog;

/**
 * One request to list `audit_logs` (or, with `archive`, `audit_logs_archive`)
 * rows (T-017 AC-4, AC-9).
 *
 * `actorEmployeeId` is who is asking, checked against `audit.read` (AC-5) -
 * not a filter. `beforeId` is keyset paging on the monotonic `id`: the
 * newest-first page before that id, never an `OFFSET` over a growing log.
 */
final readonly class SearchAuditLog
{
    public function __construct(
        public string $actorEmployeeId,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $actorId = null,
        public ?string $module = null,
        public ?string $action = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $archive = false,
        public ?int $pageSize = null,
        public ?int $beforeId = null,
    ) {}
}
