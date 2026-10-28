<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The local stand-in (T-016 Ruling 12), read only under
 * `MappingSource::isConfigStandIn()`.
 *
 * Reads `platform.stand_in.entitlements.<tenant_id>`, which defaults to no
 * entry - meaning no plan, Ruling 6's third branch - so every T-010 and
 * T-014 fixture tenant stays uncapped exactly as before this task.
 *
 * `evict()` is a no-op: there is no cache behind this source to drop.
 */
final class ConfigEntitlementSource implements EntitlementSource, EvictsEntitlements
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function for(string $tenantId): Entitlements
    {
        $entry = $this->config->get("platform.stand_in.entitlements.{$tenantId}");

        if (! is_array($entry)) {
            return Entitlements::none();
        }

        return new Entitlements(
            $entry['plan_id'] ?? null,
            (array) ($entry['limits'] ?? []),
            (array) ($entry['plan_features'] ?? []),
            (array) ($entry['tenant_overrides'] ?? []),
            (array) ($entry['flag_catalogue'] ?? []),
        );
    }

    public function evict(string $tenantId): void
    {
        // Nothing cached behind the stand-in.
    }
}
