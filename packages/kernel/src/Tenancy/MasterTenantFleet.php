<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

/**
 * The shipped fleet reader: every tenant whose mapping status the serving
 * purpose accepts, read from `tenant_databases.status` on the master schema
 * (T-017 Ruling 6).
 *
 * The access package never names the master connection directly - this
 * class lives in the kernel, which already may, so T-016's AC-32 source
 * scan ("access/organization never name `tenancy.master_connection`") stays
 * true.
 */
final class MasterTenantFleet implements TenantFleet
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function servingTenantIds(): array
    {
        $ids = $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_databases')
            ->whereIn('status', TenantConnectionPurpose::SERVING_STATUSES)
            ->pluck('tenant_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        sort($ids);

        return $ids;
    }
}
