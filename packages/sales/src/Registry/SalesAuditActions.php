<?php

declare(strict_types=1);

namespace Flexipos\Sales\Registry;

use Flexipos\Sales\Features\CreatePaymentMethod\CreatePaymentMethodHandler;
use Flexipos\Sales\Features\DecideApproval\DecideApprovalHandler;
use Flexipos\Sales\Features\DeletePaymentMethod\DeletePaymentMethodHandler;
use Flexipos\Sales\Features\OpenCashShift\OpenCashShiftHandler;
use Flexipos\Sales\Features\RecordCashMovement\RecordCashMovementHandler;
use Flexipos\Sales\Features\RequestApproval\RequestApprovalHandler;
use Flexipos\Sales\Features\UpdatePaymentMethod\UpdatePaymentMethodHandler;

/**
 * The audit actions this package's handlers write through
 * `AuditTrail::record()` (T-017 Ruling 3).
 *
 * **Built from each handler's own `ACTION` constant, so no literal is copied**
 * (the pricing precedent): this list cannot drift from what the handlers
 * actually pass. A handler joins it in the same change that adds it, so the
 * provider never advertises an action no slice writes.
 */
final class SalesAuditActions
{
    /**
     * The module every action below belongs to.
     */
    public const MODULE = 'sales';

    /**
     * @var list<string>
     */
    public const ALL = [
        RequestApprovalHandler::ACTION,
        DecideApprovalHandler::ACTION,
        CreatePaymentMethodHandler::ACTION,
        UpdatePaymentMethodHandler::ACTION,
        DeletePaymentMethodHandler::ACTION,
        OpenCashShiftHandler::ACTION,
        RecordCashMovementHandler::ACTION,
    ];
}
