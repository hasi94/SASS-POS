<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ReportOutboxAge;

use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `php artisan eventbus:outbox-age` (T-018 Ruling 10, AC-32).
 *
 * No option visits every serving tenant; `--tenant` (repeatable) visits
 * exactly the named tenants. One tenant's failure is logged and the loop
 * continues, the same isolation `eventbus:relay` and `audit:archive` use.
 */
final class ReportOutboxAgeCommand extends Command
{
    protected $signature = 'eventbus:outbox-age {--tenant=*}';

    protected $description = "Report the age in seconds of each tenant's oldest pending outbox_events row";

    public function handle(
        ReportOutboxAgeHandler $handler,
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
                $seconds = $tenancy->run($tenantId, fn (): ?int => $handler->handle(new ReportOutboxAge));

                $this->components->twoColumnDetail($tenantId, $seconds === null ? 'none' : (string) $seconds);
            } catch (Throwable $exception) {
                $failed++;
                $exceptionClass = $exception::class;

                $logger->error('Reporting outbox age failed for one tenant.', [
                    'tenant_id' => $tenantId,
                    'exception' => $exceptionClass,
                ]);

                $this->components->error("{$tenantId}: {$exceptionClass}");
            }
        }

        if ($tenantIds === []) {
            $this->components->info('No tenant was named and the fleet is empty.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
