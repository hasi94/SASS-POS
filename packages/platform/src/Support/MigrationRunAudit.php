<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Kernel\Schema\Contracts\RecordsMigrationRuns;
use Illuminate\Database\Connection;

/**
 * The platform's implementation of the kernel's migration-run audit port
 * (T-017 Ruling 7, AC-14).
 *
 * **Calls the existing {@see PlatformAudit::write()} once, and inserts
 * nothing itself** - which is what keeps `PlatformAudit` the only inserter
 * into `platform_audit_logs` anywhere in the diff (AC-20), including from
 * this new call site.
 */
final class MigrationRunAudit implements RecordsMigrationRuns
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function recordPlanned(
        Connection $master,
        string $runId,
        int $targetVersion,
        string $mode,
        ?array $tenantIds,
        string $setName,
        ?string $initiatedBy,
    ): void {
        // The run row's own tenant_id rule (Ruling 7): named when the mode
        // is `tenant` and exactly one distinct tenant was named, null
        // otherwise - a bulk run and a multi-tenant named run are both "not
        // one tenant", and a duplicate (`--tenant=A --tenant=A`) is still
        // one tenant, not two.
        $uniqueTenantIds = $tenantIds === null ? [] : array_values(array_unique($tenantIds));

        $tenantId = $mode === 'tenant' && count($uniqueTenantIds) === 1
            ? $uniqueTenantIds[0]
            : null;

        $this->audit->write(
            $master,
            PlatformAuditActions::MIGRATION_RUN,
            'tenant_migration_run',
            $runId,
            $tenantId,
            $initiatedBy,
            null,
            [
                'target_version' => $targetVersion,
                'mode' => $mode,
                'selector' => [
                    'mode' => $mode,
                    'tenants' => $tenantIds,
                    'target_version' => $targetVersion,
                    'set' => $setName,
                ],
            ],
        );
    }
}
