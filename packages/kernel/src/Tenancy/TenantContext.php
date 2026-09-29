<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;

/**
 * "Which shop is this process working for, right now."
 *
 * Registered as a **scoped** singleton, so a queue worker's
 * forgetScopedInstances() between jobs drops it and the process is back to "no
 * tenant resolved" whether or not the job remembered to leave (decision D-27,
 * AC-18).
 *
 * There is deliberately no accessor that can return null, no empty string and no
 * default (AC-5): every getter either answers with a real tenant or throws
 * NoTenantResolved. A nullable getter is how "no tenant" becomes a silent read
 * from whatever happened to be configured.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?TenantDatabase $database = null;

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * @throws NoTenantResolved
     */
    public function tenantId(): string
    {
        if ($this->tenantId === null) {
            throw NoTenantResolved::forContext('identifier');
        }

        return $this->tenantId;
    }

    /**
     * @throws NoTenantResolved
     */
    public function database(): TenantDatabase
    {
        if ($this->database === null) {
            throw NoTenantResolved::forContext('database mapping');
        }

        return $this->database;
    }

    /**
     * @internal Only Flexipos\Kernel\Tenancy\Tenancy may call this. It is what
     *           makes Tenancy the single place a tenant is resolved (AC-15);
     *           calling it from anywhere else would set a context whose tenant
     *           connection was never configured.
     */
    public function resolved(string $tenantId, TenantDatabase $database): void
    {
        $this->tenantId = $tenantId;
        $this->database = $database;
    }

    /**
     * @internal Only Flexipos\Kernel\Tenancy\Tenancy may call this.
     */
    public function forget(): void
    {
        $this->tenantId = null;
        $this->database = null;
    }
}
