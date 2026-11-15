<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

/**
 * One tenant's resolved registrations, by point, for one dispatch call
 * (T-019 plan Ruling 10).
 */
final readonly class TenantHookTable
{
    /**
     * @param  array<string, list<Registration>>  $byPoint
     */
    public function __construct(
        private array $byPoint,
    ) {}

    /**
     * @return list<Registration>
     */
    public function registrationsFor(string $point): array
    {
        return $this->byPoint[$point] ?? [];
    }
}
