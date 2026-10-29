<?php

declare(strict_types=1);

namespace Flexipos\Organization\Registry;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Organization\Features\ChangeBranchStatus\ChangeBranchStatusHandler;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatusHandler;
use Flexipos\Organization\Features\ChangeTerminalStatus\ChangeTerminalStatusHandler;
use Flexipos\Organization\Features\ClearSetting\ClearSettingHandler;
use Flexipos\Organization\Features\CreateBranch\CreateBranchHandler;
use Flexipos\Organization\Features\CreateTerminal\CreateTerminalHandler;
use Flexipos\Organization\Features\CreateWarehouse\CreateWarehouseHandler;
use Flexipos\Organization\Features\ReissueActivationCode\ReissueActivationCodeHandler;
use Flexipos\Organization\Features\RevokeTerminal\RevokeTerminalHandler;
use Flexipos\Organization\Features\SetSetting\SetSettingHandler;
use Flexipos\Organization\Features\UpdateBranch\UpdateBranchHandler;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocationHandler;
use Flexipos\Organization\Features\UpdateTerminal\UpdateTerminalHandler;

/**
 * The sixteen `audit_logs.action` values the T-014 handlers already write
 * through {@see AuditTrail::record()} (T-017
 * Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is
 * copied** (plan section 3): this list cannot drift from what the handlers
 * actually pass, because it is not a second spelling of the same string, it
 * is the same constant read a second time.
 */
final class OrganizationAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'organization';

    /**
     * @var list<string>
     */
    public const ALL = [
        ClearSettingHandler::ACTION,
        CreateBranchHandler::ACTION,
        CreateTerminalHandler::ACTION,
        CreateWarehouseHandler::ACTION,
        ReissueActivationCodeHandler::ACTION,
        RevokeTerminalHandler::ACTION,
        SetSettingHandler::ACTION,
        UpdateBranchHandler::ACTION,
        UpdateLocationHandler::ACTION,
        UpdateTerminalHandler::ACTION,
        ChangeBranchStatusHandler::ACTION_DEACTIVATED,
        ChangeBranchStatusHandler::ACTION_REACTIVATED,
        ChangeLocationStatusHandler::ACTION_DEACTIVATED,
        ChangeLocationStatusHandler::ACTION_REACTIVATED,
        ChangeTerminalStatusHandler::ACTION_DEACTIVATED,
        ChangeTerminalStatusHandler::ACTION_REACTIVATED,
    ];
}
