<?php

declare(strict_types=1);

namespace Flexipos\Customers;

use Flexipos\Contracts\Audit\DeclaresAuditActions;
use Flexipos\Contracts\Customers\CustomerCreditLedger;
use Flexipos\Contracts\Customers\LoyaltyLedger;
use Flexipos\Customers\Ledger\ContractCreditLedger;
use Flexipos\Customers\Loyalty\ContractLoyaltyLedger;
use Flexipos\Customers\Registry\CustomersAuditActions;
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
 * **`CustomerCreditLedger` is bound to `Ledger\ContractCreditLedger`**
 * (plan chunk C, Ruling 2/6), and **`LoyaltyLedger` to
 * `Loyalty\ContractLoyaltyLedger`** (plan chunk D).
 */
final class CustomersServiceProvider extends ServiceProvider implements DeclaresAuditActions
{
    public function register(): void
    {
        $this->app->singleton(CustomerCreditLedger::class, ContractCreditLedger::class);
        $this->app->singleton(LoyaltyLedger::class, ContractLoyaltyLedger::class);
    }

    /**
     * This package's declared audit actions (T-017 Ruling 3).
     *
     * @return array<string, list<string>>
     */
    public function auditActions(): array
    {
        return [CustomersAuditActions::MODULE => CustomersAuditActions::ALL];
    }
}
