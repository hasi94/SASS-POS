<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantFleet;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The local stand-in fleet reader: `config('tenancy.databases')`, filtered
 * the identical way the shipped reader filters the master schema
 * (T-017 Ruling 6).
 *
 * No query: the same shape as {@see InMemoryTenantDatabaseRepository}, and
 * selected by the same {@see MappingSource} switch.
 */
final class InMemoryTenantFleet implements TenantFleet
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function servingTenantIds(): array
    {
        /** @var array<string, mixed> $databases */
        $databases = (array) $this->config->get('tenancy.databases', []);

        $ids = [];

        foreach ($databases as $tenantId => $attributes) {
            $status = (string) (((array) $attributes)['status'] ?? '');

            if (in_array($status, TenantConnectionPurpose::SERVING_STATUSES, true)) {
                $ids[] = (string) $tenantId;
            }
        }

        sort($ids);

        return $ids;
    }
}
