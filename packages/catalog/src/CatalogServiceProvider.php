<?php

declare(strict_types=1);

namespace Flexipos\Catalog;

use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Illuminate\Support\ServiceProvider;

/**
 * The catalog package's entry point into the host application (T-027).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is: the host's provider list is the one
 * place that says which packages boot.
 *
 * **A package and not a `modules/*` module** (plan Ruling 1): every later
 * business module (pricing, sales, inventory, purchasing, the till) reads the
 * catalog through `Flexipos\Contracts\Catalog\*`, so the tables' owner is
 * shared infrastructure rather than a leaf that other modules may not import.
 *
 * **Bindings arrive with the slices that earn them** (organization's own
 * precedent). `UnitResolver` is bound to `Units\DatabaseUnitResolver` once
 * step 12 builds it; nothing here is bound to a stub, because a contract
 * with no implementation resolves to a binding error, which is a refusal to
 * answer and never a permissive one.
 */
final class CatalogServiceProvider extends ServiceProvider implements DeclaresAuditActions
{
    public function register(): void {}

    /**
     * This package's declared audit actions (T-017 Ruling 3).
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [CatalogAuditActions::MODULE => CatalogAuditActions::ALL];
    }
}
