<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * What `packages/organization`'s {@see DefaultBranch} needs to validate or
 * create a tenant's first branch (T-026 Ruling 5, AC-1, AC-26).
 *
 * **`code` and `name` are nullable, and the default is this value object's
 * own, not a second copy of it in the caller.** AC-26 defaults an unsupplied
 * branch code to `MAIN` and an unsupplied name to the tenant's own name;
 * {@see self::resolvedCode()} and {@see self::resolvedName()} are the one
 * place that default is applied, so `validate()` (checked at AC-1, before
 * any row is written) and `ensure()` (run later, at `seed_core`) cannot come
 * to apply it differently.
 */
final class BranchSeed
{
    public const DEFAULT_CODE = 'MAIN';

    public function __construct(
        public readonly string $tenantName,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly string $businessType,
        public readonly ?string $code = null,
        public readonly ?string $name = null,
    ) {}

    public function resolvedCode(): string
    {
        return $this->code ?? self::DEFAULT_CODE;
    }

    public function resolvedName(): string
    {
        return $this->name ?? $this->tenantName;
    }
}
