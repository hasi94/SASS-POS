<?php

declare(strict_types=1);

namespace Flexipos\Finance;

use Flexipos\Contracts\Access\DeclaresPermissions;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Contracts\Finance\AccountUsage;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\FiscalYearUsage;
use Flexipos\Contracts\Finance\PeriodCloseBlockers;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Finance\Accounts\DatabaseChartOfAccounts;
use Flexipos\Finance\Accounts\NoAccountUsage;
use Flexipos\Finance\Periods\DatabasePeriodGate;
use Flexipos\Finance\Periods\NoFiscalYearUsage;
use Flexipos\Finance\Periods\NoPeriodCloseBlockers;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
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
 * **The three usage ports are bound to defaults that report "none"** (D-7,
 * AC-25): nothing posts yet, so no account has postings, no year has entries
 * and nothing blocks a close. FP-261 rebinds them. Bound with `bind`, not
 * `singleton`, because a default is stateless and a test double replaces it
 * by rebinding the interface.
 *
 * It declares no route, no command and no job.
 */
final class FinanceServiceProvider extends ServiceProvider implements DeclaresAuditActions, DeclaresPermissions
{
    public function register(): void
    {
        $this->app->bind(ChartOfAccounts::class, DatabaseChartOfAccounts::class);
        $this->app->bind(PeriodGate::class, DatabasePeriodGate::class);
        $this->app->bind(AccountUsage::class, NoAccountUsage::class);
        $this->app->bind(FiscalYearUsage::class, NoFiscalYearUsage::class);
        $this->app->bind(PeriodCloseBlockers::class, NoPeriodCloseBlockers::class);
    }

    /**
     * This package's declared audit actions (T-017 Ruling 3).
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [FinanceAuditActions::MODULE => FinanceAuditActions::ALL];
    }

    /**
     * This package's seven keys (AC-64, D-24).
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return FinancePermissions::declarations();
    }
}
