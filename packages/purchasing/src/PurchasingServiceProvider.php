<?php

declare(strict_types=1);

namespace Flexipos\Purchasing;

use Illuminate\Support\ServiceProvider;

/**
 * The purchasing package's entry point into the host application (T-034).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is. It publishes no contract (plan
 * Ruling 1) and reaches the rest of the system only through
 * `Flexipos\Contracts`.
 *
 * Empty on purpose at this step: the audit actions and permission keys arrive
 * with the registries that hold them, so a provider never advertises what
 * nothing writes.
 */
final class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing is bound: every slice and service autowires from the container.
    }

    public function boot(): void
    {
        // Nothing to boot yet.
    }
}
