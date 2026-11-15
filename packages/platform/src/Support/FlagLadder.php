<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Contracts\Platform\FeatureFlagValue;

/**
 * The one place the three-layer precedence is built (T-016 D-5, D-9, AC-22).
 *
 * Tenant override, if any, beats the plan's value, if any, beats the global
 * default. `$planHasRow`/`$tenantHasOverride` are carried separately from the
 * value itself, because a `plan_features` row with a stored `null` is a
 * plan-layer answer of "disabled", not a fall-through to the default
 * (Ruling 13).
 */
final class FlagLadder
{
    public static function resolve(
        string $key,
        mixed $default,
        bool $planHasRow,
        mixed $planValue,
        bool $tenantHasOverride,
        mixed $tenantValue,
    ): FeatureFlagValue {
        if ($tenantHasOverride) {
            return new FeatureFlagValue($key, $tenantValue, 'tenant');
        }

        if ($planHasRow) {
            return new FeatureFlagValue($key, $planValue, 'plan');
        }

        return new FeatureFlagValue($key, $default, 'default');
    }
}
