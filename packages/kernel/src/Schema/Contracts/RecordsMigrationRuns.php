<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Contracts;

use Illuminate\Database\Connection;

/**
 * A port the kernel owns and the platform package implements (T-017 Ruling
 * 7, AC-14, AC-20).
 *
 * **Why a port instead of an import.** `deptrac.yaml` grants
 * `Package_Kernel` only `Package_Kernel_TenancyStatus`; the kernel cannot
 * import `Flexipos\Platform\Support\PlatformAudit` directly, and granting
 * that edge would form a cycle, because `packages/platform` already depends
 * on the kernel. The interface is declared here, where
 * `Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler`
 * can depend on it without crossing the boundary, and
 * `Flexipos\Platform\Support\MigrationRunAudit` implements it by
 * calling the existing `Flexipos\Platform\Support\PlatformAudit::write()` -
 * which is what keeps that class the only inserter into
 * `platform_audit_logs` (AC-20).
 *
 * **No default binding exists in the kernel, on purpose.** A host that boots
 * the kernel without the platform package cannot resolve this interface and
 * fails loudly with a resolution error rather than planning a migration run
 * nobody audits. `PlatformServiceProvider` is the one binding.
 */
interface RecordsMigrationRuns
{
    /**
     * Record that a migration run was planned (AC-14).
     *
     * Called once, at the point a run is created - never on retry of the
     * same run, and never per job.
     *
     * @param  list<string>|null  $tenantIds  null for a bulk run; the run's
     *                                        own selector, as given
     */
    public function recordPlanned(
        Connection $master,
        string $runId,
        int $targetVersion,
        string $mode,
        ?array $tenantIds,
        string $setName,
        ?string $initiatedBy,
    ): void;
}
