<?php

declare(strict_types=1);

namespace Flexipos\Pricing;

use Illuminate\Support\ServiceProvider;

/**
 * The pricing package's entry point into the host application (T-029).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is: the host's provider list is the one
 * place that says which packages boot.
 *
 * **A package and not a `modules/*` module** (plan Ruling 1): Sales, Purchasing
 * and the till all price lines, and catalog reads the tax group directory, so
 * the tables' owner is shared infrastructure rather than a leaf other modules
 * may not import. Everything outside this package reaches it only through
 * `Flexipos\Contracts\Pricing`.
 *
 * **Empty for now, deliberately** (plan step 1; the catalog and customers
 * providers' own precedent, "bindings arrive with the slices that earn them").
 * The `PricingEngine` and `TaxGroupDirectory` bindings and the
 * `DeclaresAuditActions` implementation land with the code they bind - there
 * is no earlier stub, and a contract with no implementation resolves to a
 * binding error, never a permissive default.
 */
final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
