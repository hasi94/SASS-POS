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
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Finance\Accounts\DatabaseChartOfAccounts;
use Flexipos\Finance\Journal\DatabasePostingService;
use Flexipos\Finance\Journal\Ports\JournalAccountUsage;
use Flexipos\Finance\Journal\Ports\JournalFiscalYearUsage;
use Flexipos\Finance\Journal\Ports\JournalPeriodCloseBlockers;
use Flexipos\Finance\Periods\DatabasePeriodGate;
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
 * **The three usage ports answer from the journal** (T-033 AC-62): T-032 bound
 * them to defaults that reported "none" (`NoAccountUsage`, `NoFiscalYearUsage`,
 * `NoPeriodCloseBlockers`, D-7) while nothing posted; those classes stay for
 * tests that need the old answer. Bound with `bind`, not `singleton`, because a
 * port is stateless and a test double replaces it by rebinding the interface.
 *
 * It declares no route, no command and no job.
 */
final class FinanceServiceProvider extends ServiceProvider implements DeclaresAuditActions, DeclaresPermissions
{
    public function register(): void
    {
        $this->app->bind(ChartOfAccounts::class, DatabaseChartOfAccounts::class);
        $this->app->bind(PeriodGate::class, DatabasePeriodGate::class);
        $this->app->bind(PostingService::class, DatabasePostingService::class);
        $this->app->bind(AccountUsage::class, JournalAccountUsage::class);
        $this->app->bind(FiscalYearUsage::class, JournalFiscalYearUsage::class);
        $this->app->bind(PeriodCloseBlockers::class, JournalPeriodCloseBlockers::class);
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
     * This package's ten keys (T-032 AC-64, D-24; T-033 AC-72).
     *
     * @return list<PermissionDeclaration>
     */
    public function permissionDeclarations(): array
    {
        return FinancePermissions::declarations();
    }
}
