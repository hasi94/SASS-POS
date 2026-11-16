<?php

declare(strict_types=1);

namespace Flexipos\Finance;

use Illuminate\Support\ServiceProvider;

/**
 * The finance package's entry point into the host application (T-032).
 *
 * Registered explicitly in `modules/host/bootstrap/providers.php`, the way
 * every other `packages/*` provider is, and **not** a `MODULES` module (plan
 * Ruling 1, F-7, D-24): catalog and customers resolve
 * `Flexipos\Contracts\Finance\ChartOfAccounts` inside their own update
 * handlers, so a Finance that could be left out of `MODULES` would leave an
 * unbound interface behind every product update.
 *
 * It declares no audit action, no permission key and no binding yet: each
 * arrives with the step that owns it (plan steps 8 onwards), so the provider
 * never advertises something nothing implements.
 */
final class FinanceServiceProvider extends ServiceProvider {}
