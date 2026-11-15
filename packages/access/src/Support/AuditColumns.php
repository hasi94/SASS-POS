<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

/**
 * The column list `audit_logs` and `audit_logs_archive` share, in table
 * order (T-017 Ruling 5).
 *
 * **One shared list, read by three things that must agree.** The archive
 * migration declares the same columns with the same `Blueprint` calls; the
 * archive move (`Flexipos\Access\Features\ArchiveAuditLogs\ArchiveAuditLogsHandler`)
 * uses it for the `insertUsing()`/select column list, so the copy is
 * SQL-level and byte-identical; and the two readers
 * (`Flexipos\Access\Features\SearchAuditLog\SearchAuditLog`) select
 * exactly it. A shape test pins this list against both live tables, so a
 * later expand step that adds a column to one and not the other goes red.
 */
final class AuditColumns
{
    /**
     * @var list<string>
     */
    public const ALL = [
        'id',
        'actor_type',
        'actor_id',
        'actor_name',
        'branch_id',
        'module',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'changed_fields',
        'ip',
        'user_agent',
        'request_id',
        'created_at',
    ];
}
