<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * The one resolver call every module uses to read flag state (T-016 D-9, AC-22).
 *
 * No module-local re-implementation of the three-layer precedence is
 * permitted; this is the only place the ladder is computed (`FlagLadder`,
 * behind this interface).
 */
interface FeatureFlags
{
    /**
     * @throws UnknownFeatureFlag
     */
    public function resolve(string $tenantId, string $key): FeatureFlagValue;

    /**
     * @throws UnknownFeatureFlag
     */
    public function isEnabled(string $tenantId, string $key): bool;
}
