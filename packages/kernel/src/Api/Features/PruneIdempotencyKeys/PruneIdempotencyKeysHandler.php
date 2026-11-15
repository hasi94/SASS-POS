<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Features\PruneIdempotencyKeys;

use Flexipos\Kernel\Api\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Delete this tenant's `idempotency_keys` rows older than the configured
 * retention (T-021 AC-23). It never touches a younger row.
 */
final class PruneIdempotencyKeysHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(PruneIdempotencyKeys $command): PruneIdempotencyKeysResult
    {
        $retentionHours = (int) $this->config->get('api.idempotency.retention_hours', 24);
        $threshold = $this->clock->now()->subHours($retentionHours)->format(RunnerClock::PRECISION);

        $deleted = $this->tenant->get()
            ->table('idempotency_keys')
            ->where('created_at', '<', $threshold)
            ->delete();

        return new PruneIdempotencyKeysResult($deleted);
    }
}
