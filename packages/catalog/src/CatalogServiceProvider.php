<?php

declare(strict_types=1);

namespace Flexipos\Catalog;

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
 * **Bindings and audit-action declarations arrive with the slices that earn
 * them** (organization's own precedent) - this step ships the empty shell
 * only, so the package exists, boots and is reachable by `deptrac` before any
 * real code lives under it.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
