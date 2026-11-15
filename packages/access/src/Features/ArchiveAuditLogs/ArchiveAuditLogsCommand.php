<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ArchiveAuditLogs;

use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `php artisan audit:archive` (T-017 Ruling 6, AC-8).
 *
 * No option visits every tenant `TenantFleet::servingTenantIds()` names;
 * `--tenant` (repeatable) visits exactly the named tenants instead, which is
 * how an operator re-runs the move for one shop without waiting for the
 * daily schedule.
 *
 * **One tenant's failure does not stop the others** (the same sweep shape
 * T-016's deadline enforcement uses): logged with the tenant id and the
 * exception class, the loop continues, and the command exits 1 if any
 * tenant failed.
 */
final class ArchiveAuditLogsCommand extends Command
{
    protected $signature = 'audit:archive {--tenant=* : Archive only the named tenants, instead of the whole serving fleet}';

    protected $description = "Move each tenant's aged audit_logs rows into audit_logs_archive";

    public function handle(
        ArchiveAuditLogsHandler $handler,
        Tenancy $tenancy,
        TenantFleet $fleet,
        LoggerInterface $logger,
    ): int {
        /** @var list<string> $named */
        $named = $this->option('tenant');

        $tenantIds = $named === [] ? $fleet->servingTenantIds() : $named;

        $failed = 0;

        foreach ($tenantIds as $tenantId) {
            try {
                // No purpose named: Tenancy::run()'s own default is Serving,
                // and Package_Access may not depend on
                // Flexipos\Kernel\Tenancy\TenantConnectionPurpose at all
                // (deptrac) - deciding whether a tenant database is served
                // is not this package's question.
                $result = $tenancy->run(
                    $tenantId,
                    static fn (): ArchiveAuditLogsResult => $handler->handle(new ArchiveAuditLogs)
                );

                $this->components->twoColumnDetail(
                    $tenantId,
                    "{$result->moved} row(s) in {$result->chunks} chunk(s)"
                );
            } catch (Throwable $exception) {
                $failed++;
                $exceptionClass = $exception::class;

                $logger->error('Archiving audit_logs failed for one tenant.', [
                    'tenant_id' => $tenantId,
                    'exception' => $exceptionClass,
                ]);

                $this->components->error("{$tenantId}: {$exceptionClass}");
            }
        }

        if ($tenantIds === []) {
            $this->components->info('No tenant was named and the fleet is empty; nothing was archived.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
