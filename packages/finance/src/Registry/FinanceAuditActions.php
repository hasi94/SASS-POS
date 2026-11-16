<?php

declare(strict_types=1);

namespace Flexipos\Finance\Registry;

use Flexipos\Finance\Features\CreateAccount\CreateAccountHandler;
use Flexipos\Finance\Features\DeactivateAccount\DeactivateAccountHandler;
use Flexipos\Finance\Features\DeleteAccount\DeleteAccountHandler;
use Flexipos\Finance\Features\ReactivateAccount\ReactivateAccountHandler;
use Flexipos\Finance\Features\ReparentAccount\ReparentAccountHandler;
use Flexipos\Finance\Features\UpdateAccount\UpdateAccountHandler;

/**
 * The audit actions this package's handlers write through
 * `AuditTrail::record()` (T-032 plan Ruling 11, AC-65, T-017 Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is
 * copied** (the organization, catalog and customers precedent): this list
 * cannot drift from what the handlers actually pass. It grows with each
 * slice; the plan's full set of fifteen is complete when chunk D lands.
 */
final class FinanceAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'finance';

    /**
     * @var list<string>
     */
    public const ALL = [
        CreateAccountHandler::ACTION,
        UpdateAccountHandler::ACTION,
        ReparentAccountHandler::ACTION,
        DeactivateAccountHandler::ACTION,
        ReactivateAccountHandler::ACTION,
        DeleteAccountHandler::ACTION,
    ];

    private function __construct() {}
}
