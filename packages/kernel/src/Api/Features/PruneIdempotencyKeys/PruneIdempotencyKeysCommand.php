<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\PruneIdempotencyKeys;

use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Flexipos\Kernel\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `php artisan idempotency:prune` (T-021 Ruling 6, AC-23).
 *
 * No option visits every tenant `TenantFleet::servingTenantIds()` names;
 * `--tenant` (repeatable) narrows the run. The same sweep shape as
 * `audit:archive` (T-017): one tenant's failure is logged and the loop
 * continues, and the command exits 1 if any tenant failed.
 */
final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune {--tenant=* : Prune only the named tenants, instead of the whole serving fleet}';

    protected $description = "Delete each tenant's idempotency_keys rows older than the retention window";

    public function handle(
        PruneIdempotencyKeysHandler $handler,
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
                $result = $tenancy->run(
                    $tenantId,
                    static fn (): PruneIdempotencyKeysResult => $handler->handle(new PruneIdempotencyKeys)
                );

                $this->line("{$tenantId}  {$result->deleted} key(s) deleted");
            } catch (Throwable $exception) {
                $failed++;
                $exceptionClass = $exception::class;

                $logger->error('Pruning idempotency_keys failed for one tenant.', [
                    'tenant_id' => $tenantId,
                    'exception' => $exceptionClass,
                ]);

                $this->components->error("{$tenantId}: {$exceptionClass}");
            }
        }

        if ($tenantIds === []) {
            $this->components->info('No tenant was named and the fleet is empty; nothing was pruned.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
