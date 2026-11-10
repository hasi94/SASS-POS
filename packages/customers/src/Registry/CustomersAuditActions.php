<?php

declare(strict_types=1);

namespace Flexipos\Customers\Registry;

use Flexipos\Customers\Features\CreateCustomer\CreateCustomerHandler;
use Flexipos\Customers\Features\CreateCustomerGroup\CreateCustomerGroupHandler;
use Flexipos\Customers\Features\DeleteCustomer\DeleteCustomerHandler;
use Flexipos\Customers\Features\DeleteCustomerGroup\DeleteCustomerGroupHandler;
use Flexipos\Customers\Features\UpdateCustomer\UpdateCustomerHandler;
use Flexipos\Customers\Features\UpdateCustomerGroup\UpdateCustomerGroupHandler;
use Flexipos\Customers\Ledger\CreditLedgerWriter;

/**
 * The audit actions this package's handlers write through
 * `AuditTrail::record()` (T-028 plan Ruling 11, T-017 Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is
 * copied** (the organization/catalog precedent): this list cannot drift
 * from what the handlers actually pass. Grows with each chunk as the
 * remaining slices (loyalty, addresses, notes, communications, merge) land.
 */
final class CustomersAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'customers';

    /**
     * @var list<string>
     */
    public const ALL = [
        CreateCustomerGroupHandler::ACTION,
        UpdateCustomerGroupHandler::ACTION,
        DeleteCustomerGroupHandler::ACTION,
        CreateCustomerHandler::ACTION,
        UpdateCustomerHandler::ACTION,
        DeleteCustomerHandler::ACTION,
        CreditLedgerWriter::ACTION,
    ];
}
