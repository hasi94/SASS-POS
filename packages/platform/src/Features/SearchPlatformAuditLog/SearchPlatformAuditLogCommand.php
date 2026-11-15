<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\SearchPlatformAuditLog;

use Flexipos\Kernel\Audit\AuditExport;
use Flexipos\Kernel\Audit\ExportFormat;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Illuminate\Console\Command;

/**
 * `php artisan platform:audit-search` (T-017 AC-16, AC-17, D-7: no HTTP
 * route, same precedent T-016 D-21 already set for every master-side
 * operation).
 */
final class SearchPlatformAuditLogCommand extends Command
{
    protected $signature = 'platform:audit-search
        {--as= : The acting user id, checked against users.is_platform_staff}
        {--tenant-id=}
        {--actor-id=}
        {--action=}
        {--subject-type=}
        {--subject-id=}
        {--from=}
        {--to=}
        {--before-id= : Keyset cursor - the page before this id}
        {--page-size=}
        {--output= : A local path to export the page to}
        {--format=json : json or csv, when --output is given}';

    protected $description = 'Search the platform audit trail';

    public function handle(SearchPlatformAuditLogHandler $handler): int
    {
        $actorUserId = $this->option('as');

        if ($actorUserId === null) {
            $this->components->error('--as is required.');

            return self::FAILURE;
        }

        $pageSizeOption = $this->option('page-size');
        $beforeIdOption = $this->option('before-id');

        $query = new SearchPlatformAuditLog(
            actorUserId: (string) $actorUserId,
            tenantId: $this->option('tenant-id'),
            actorId: $this->option('actor-id'),
            action: $this->option('action'),
            subjectType: $this->option('subject-type'),
            subjectId: $this->option('subject-id'),
            from: $this->option('from'),
            to: $this->option('to'),
            pageSize: $pageSizeOption === null ? null : (int) $pageSizeOption,
            beforeId: $beforeIdOption === null ? null : (int) $beforeIdOption,
        );

        try {
            $page = $handler->handle($query);
        } catch (PlatformRefused $refused) {
            $this->components->error("[{$refused->reason}] {$refused->getMessage()}");

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
