<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimit;

/**
 * The cached entitlement snapshot, one per tenant (T-016 Ruling 12, AC-5).
 *
 * A plain array round-trip, like the kernel's `CachingTenantDatabaseRepository`
 * cache entry: an array written by one deployment stays readable after the
 * shape grows a property.
 *
 * `$limits` carries only the keys a `plan_limits` row actually caps; an absent
 * key is uncapped (AC-3), which is Ruling 6's resolution for "no plan" too -
 * every array here is empty and `limit()` answers uncapped for everything.
 */
final class Entitlements
{
    /**
     * @param  array<string, int>  $limits  limit_key => value (already Ruling-14 floored at 0 if negative)
     * @param  array<string, mixed>  $planFeatures  feature_key => value, only keys with a row
     * @param  array<string, bool>  $tenantOverrides  flag_key => enabled
     * @param  array<string, bool>  $flagCatalogue  flag_key => default_enabled
     */
    public function __construct(
        public readonly ?string $planId,
        public readonly array $limits,
        public readonly array $planFeatures,
        public readonly array $tenantOverrides,
        public readonly array $flagCatalogue,
    ) {}

    public static function none(): self
    {
        return new self(null, [], [], [], []);
    }

    public function limit(LimitKey $key): PlanLimit
    {
        return new PlanLimit($key, $this->limits[$key->value] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'limits' => $this->limits,
            'plan_features' => $this->planFeatures,
            'tenant_overrides' => $this->tenantOverrides,
            'flag_catalogue' => $this->flagCatalogue,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        return new self(
            $entry['plan_id'] ?? null,
            (array) ($entry['limits'] ?? []),
            (array) ($entry['plan_features'] ?? []),
            (array) ($entry['tenant_overrides'] ?? []),
            (array) ($entry['flag_catalogue'] ?? []),
        );
    }
}
