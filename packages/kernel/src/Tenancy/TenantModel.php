<?php

namespace Flexipos\Kernel\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * The base class every tenant-scoped business model extends (FP-204 onward).
 *
 * This is a correctness *default*, not the boundary: it stops a module's model
 * quietly landing on `master`, but it does nothing about DB::table(), raw
 * queries, the query builder, or a model that forgets to extend it. The boundary
 * is the connection guard in TenancyServiceProvider, which every one of those
 * paths goes through.
 *
 * setConnection() is deliberately not overridden - Eloquent calls it internally
 * while hydrating relations and query results, and blocking it would break the
 * ORM without adding any protection the guard does not already provide.
 */
abstract class TenantModel extends Model
{
    /**
     * A literal, because Eloquent reads this property directly. It must stay in
     * step with config('tenancy.connection').
     *
     * @var string|null
     */
    protected $connection = 'tenant';
}
