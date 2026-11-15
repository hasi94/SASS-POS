<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Contracts;

/**
 * Which tenants exist, for the one caller that needs to visit all of them
 * (T-017 Ruling 6, AC-8): the archival sweep.
 *
 * **Not `TenantDatabaseRepository`.** That contract answers "where does one
 * named tenant live" and is read on the one request/migration path that
 * already knows which tenant it wants. Listing every tenant is a different
 * question with a different shape of answer, and the plan's own rejected
 * alternative - adding `list()` to `TenantDatabaseRepository` - would have
 * touched all three implementations, including the caching one, whose
 * single-tenant cache key has no meaning for a list.
 *
 * **Serving tenants only.** `servingTenantIds()` returns exactly the
 * tenants a request may be served for right now - the same set
 * `TenantConnectionPurpose::SERVING_STATUSES` names - because a tenant in
 * any other status writes no new `audit_logs` rows, so its archival
 * backlog does not grow. It is archived on its first run after it becomes
 * active again.
 */
interface TenantFleet
{
    /**
     * Every tenant id this process may serve a request for, sorted.
     *
     * @return list<string>
     */
    public function servingTenantIds(): array;
}
