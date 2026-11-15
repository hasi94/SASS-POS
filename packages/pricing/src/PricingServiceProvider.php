<?php

declare(strict_types=1);

namespace Flexipos\Pricing;

use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Taxes\DatabaseTaxGroupDirectory;
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
 * **Bindings arrive with the slices that earn them** (the catalog precedent).
 * `TaxGroupDirectory` is bound to `Taxes\DatabaseTaxGroupDirectory`. The
 * `PricingEngine` binding lands with the database adapter that implements it:
 * there is no earlier stub, and a contract with no implementation resolves to a
 * binding error, never a permissive default.
 */
final class PricingServiceProvider extends ServiceProvider implements DeclaresAuditActions
{
    public function register(): void
    {
        $this->app->singleton(TaxGroupDirectory::class, DatabaseTaxGroupDirectory::class);
    }

    /**
     * This package's declared audit actions (T-017 Ruling 3).
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [PricingAuditActions::MODULE => PricingAuditActions::ALL];
    }
}
