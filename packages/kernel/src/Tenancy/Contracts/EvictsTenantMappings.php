<?php

namespace Flexipos\Kernel\Tenancy\Contracts;

/**
 * A mapping source that holds a copy and can be told to drop one tenant's
 * copy (S-2).
 *
 * **Why a capability and not a class check.** Every write of a tenant's
 * status or schema version is followed by an eviction (D-19), and the three
 * places that do it used to ask `instanceof CachingTenantDatabaseRepository`.
 * That reason for not asking the container is right - the suite binds the
 * in-memory reader and a deployment binds the decorator, so neither can be
 * assumed - but it names one class, and a *second* caching decorator would
 * have stopped being evicted with nothing going red and D-19 would quietly
 * have become false. The thing those sites care about is the capability, so
 * the capability is what they check.
 *
 * Deliberately **not** part of {@see TenantDatabaseRepository}: a source that
 * keeps no copy has nothing to evict, and a contract that made every reader
 * implement an empty method would hide exactly the distinction this exists
 * to carry.
 *
 * Never a flush. One tenant's migration is not a reason to make every other
 * shop on the server read the master again.
 */
interface EvictsTenantMappings
{
    public function evict(string $tenantId): void;
}
