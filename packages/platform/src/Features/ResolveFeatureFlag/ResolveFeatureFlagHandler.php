<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ResolveFeatureFlag;

use Flexipos\Contracts\Platform\FeatureFlags;
use Flexipos\Contracts\Platform\FeatureFlagValue;
use Flexipos\Contracts\Platform\UnknownFeatureFlag;
use Flexipos\Platform\Support\EntitlementSource;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\FlagLadder;

/**
 * Implements `FeatureFlags` (T-016 D-5, D-9, AC-17 to AC-22).
 *
 * An unknown key evicts and reloads the snapshot **once**, so a key added to
 * the catalogue in the last five minutes is found; if it is still unknown, it
 * throws `UnknownFeatureFlag` rather than resolving as disabled (AC-21).
 */
final class ResolveFeatureFlagHandler implements FeatureFlags
{
    public function __construct(
        private readonly EntitlementSource $source,
        private readonly EvictsEntitlements $evictor,
    ) {}

    public function resolve(string $tenantId, string $key): FeatureFlagValue
    {
        return $this->handle(new ResolveFeatureFlag($tenantId, $key));
    }

    public function isEnabled(string $tenantId, string $key): bool
    {
        return $this->resolve($tenantId, $key)->isEnabled();
    }

    public function handle(ResolveFeatureFlag $command): FeatureFlagValue
    {
        $entitlements = $this->source->for($command->tenantId);

        if (! array_key_exists($command->key, $entitlements->flagCatalogue)) {
            $this->evictor->evict($command->tenantId);
            $entitlements = $this->source->for($command->tenantId);

            if (! array_key_exists($command->key, $entitlements->flagCatalogue)) {
                throw new UnknownFeatureFlag("No feature flag [{$command->key}] is declared.");
            }
        }

        $planHasRow = array_key_exists($command->key, $entitlements->planFeatures);
        $tenantHasOverride = array_key_exists($command->key, $entitlements->tenantOverrides);

        return FlagLadder::resolve(
            $command->key,
            $entitlements->flagCatalogue[$command->key],
            $planHasRow,
            $planHasRow ? $entitlements->planFeatures[$command->key] : null,
            $tenantHasOverride,
            $tenantHasOverride ? $entitlements->tenantOverrides[$command->key] : null,
        );
    }
}
