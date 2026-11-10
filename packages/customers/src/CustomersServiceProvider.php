<?php

declare(strict_types=1);

namespace Flexipos\Customers;

use Illuminate\Support\ServiceProvider;

/**
 * The customers package's entry point into the host application (T-028).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is: the host's provider list is the one
 * place that says which packages boot.
 *
 * **A package and not a `modules/*` module** (plan Ruling 1): Sales,
 * Finance, the till and the pricing engine all read customers, so the
 * tables' owner is shared infrastructure rather than a leaf other modules
 * may not import.
 *
 * **Empty for now, deliberately** (plan step 1; T-027/`CatalogServiceProvider`'s
 * own precedent, "bindings arrive with the slices that earn them"). The two
 * ledger contract bindings (`CustomerCreditLedger`/`LoyaltyLedger`) and the
 * `DeclaresAuditActions` implementation land once their own slices exist
 * (plan chunks B and C) - there is no earlier stub, and a contract with no
 * implementation resolves to a binding error, never a permissive default.
 */
final class CustomersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
