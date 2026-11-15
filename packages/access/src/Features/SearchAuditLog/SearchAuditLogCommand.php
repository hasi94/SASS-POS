<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SearchAuditLog;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Kernel\Audit\AuditExport;
use Flexipos\Kernel\Audit\ExportFormat;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan audit:search` (T-017 D-7: no HTTP route exists or will exist
 * for this read, by the same precedent T-016 D-21 already set).
 *
 * Enters the named tenant through `Tenancy::run()` (its own default
 * purpose, Serving - Package_Access may not name
 * `Flexipos\Kernel\Tenancy\TenantConnectionPurpose` at all), runs one
 * search, prints the page, and optionally exports it.
 */
final class SearchAuditLogCommand extends Command
{
    protected $signature = 'audit:search
        {--tenant= : The tenant to search}
        {--as= : The acting employee id, checked against audit.read}
        {--subject-type=}
        {--subject-id=}
        {--actor-id=}
        {--module=}
        {--action=}
        {--from=}
        {--to=}
        {--archive : Search audit_logs_archive instead of audit_logs}
        {--before-id= : Keyset cursor - the page before this id}
        {--page-size=}
        {--output= : A local path to export the page to}
        {--format=json : json or csv, when --output is given}';

    protected $description = "Search one tenant's audit trail";

    public function handle(SearchAuditLogHandler $handler, Tenancy $tenancy): int
    {
        $tenantId = $this->option('tenant');
        $actorEmployeeId = $this->option('as');

        if ($tenantId === null || $actorEmployeeId === null) {
            $this->components->error('Both --tenant and --as are required.');

            return self::FAILURE;
        }

        $pageSizeOption = $this->option('page-size');
        $beforeIdOption = $this->option('before-id');

        $query = new SearchAuditLog(
            actorEmployeeId: (string) $actorEmployeeId,
            subjectType: $this->option('subject-type'),
            subjectId: $this->option('subject-id'),
            actorId: $this->option('actor-id'),
            module: $this->option('module'),
            action: $this->option('action'),
            from: $this->option('from'),
            to: $this->option('to'),
            archive: (bool) $this->option('archive'),
            pageSize: $pageSizeOption === null ? null : (int) $pageSizeOption,
            beforeId: $beforeIdOption === null ? null : (int) $beforeIdOption,
        );

        try {
            $page = $tenancy->run((string) $tenantId, static fn () => $handler->handle($query));
        } catch (AccessRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        foreach ($page->rows as $row) {
            $this->components->twoColumnDetail((string) $row['id'], (string) $row['action']);
        }

        $this->components->info(
            $page->nextBeforeId === null
                ? 'No further page.'
                : "Next page: --before-id={$page->nextBeforeId}"
        );

        $output = $this->option('output');

        if ($output !== null) {
            AuditExport::write($page, (string) $output, ExportFormat::from((string) $this->option('format')));
            $this->components->info("Wrote {$output}.");
        }

        return self::SUCCESS;
    }
}
