<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ArchiveAuditLogs;

/**
 * One request to move this tenant's aged `audit_logs` rows into
 * `audit_logs_archive` (T-017 AC-8).
 *
 * **It carries nothing**, for `SyncPermissionRegistry`'s
 * reason: the retention threshold and the chunk size are operational
 * configuration (`config/audit.php`), not a caller's choice, and which
 * tenant is moved is a property of which tenant connection is entered when
 * this is handled, not of a field on this command.
 */
final class ArchiveAuditLogs {}
