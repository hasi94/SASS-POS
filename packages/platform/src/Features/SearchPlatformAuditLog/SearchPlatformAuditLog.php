<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\SearchPlatformAuditLog;

/**
 * One request to list `platform_audit_logs` rows (T-017 AC-16, AC-17).
 *
 * `actorUserId` is who is asking, checked against `users.is_platform_staff`
 * (Ruling 10) - not a filter. `beforeId` is keyset paging on the monotonic
 * `id`, the same shape as the tenant side's
 * `Flexipos\Access\Features\SearchAuditLog\SearchAuditLog`.
 */
final readonly class SearchPlatformAuditLog
{
    public function __construct(
        public string $actorUserId,
        public ?string $tenantId = null,
        public ?string $actorId = null,
        public ?string $action = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $pageSize = null,
        public ?int $beforeId = null,
    ) {}
}
