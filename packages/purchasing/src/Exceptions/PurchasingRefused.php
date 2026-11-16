<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Exceptions;

use BackedEnum;
use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Organization\DocumentNumbersRefusal;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use RuntimeException;
use Throwable;

/**
 * The one exception every purchasing slice throws when it refuses to write (the
 * `SalesRefused` shape, T-034 D-37 and D-43).
 *
 * **What a caller reads.** {@see self::code()} is always the purchasing reason.
 * A refusal that came from another package rides along: an enum from units,
 * pricing, numbering or access is the {@see self::$cause} (its class is part of
 * the answer, because values such as `unknown_product` exist in more than one
 * vocabulary), and a workflow refusal, which carries plain strings, is the
 * {@see self::$detail} with the engine's exception as `previous` (D-43).
 *
 * Not constructible without a reason: the constructor is private. The message is
 * operator-facing and may name ids; it never carries a `bank_details` value.
 */
final class PurchasingRefused extends RuntimeException
{
    private function __construct(
        public readonly PurchasingRefusal $reason,
        string $message,
        public readonly ?BackedEnum $cause = null,
        public readonly ?string $detail = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(PurchasingRefusal $reason, string $message, ?BackedEnum $cause = null): self
    {
        return new self($reason, $message, $cause);
    }

    public static function validation(string $message): self
    {
        return new self(PurchasingRefusal::Validation, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(PurchasingRefusal::NotFound, $message);
    }

    public static function outOfRange(string $message): self
    {
        return new self(PurchasingRefusal::OutOfRange, $message);
    }

    /**
     * A unit refusal, carried through: `inherited_unit_mismatch` keeps its name,
     * every other one is `unit_unresolvable` (AC-52) with the cause attached.
     */
    public static function fromUnit(UnitResolutionRefused $refused, ?string $message = null): self
    {
        $reason = $refused->reason === UnitRefusal::InheritedUnitMismatch
            ? PurchasingRefusal::InheritedUnitMismatch
            : PurchasingRefusal::UnitUnresolvable;

        return new self($reason, $message ?? $refused->getMessage(), $refused->reason);
    }

    /**
     * A pricing refusal, carried through as `pricing_refused` with the engine's
     * own reason as the cause.
     */
    public static function fromPricing(PricingRefused $refused): self
    {
        return new self(PurchasingRefusal::PricingRefused, $refused->getMessage(), $refused->reason);
    }

    /**
     * A numbering refusal: `number_too_long` for that cause, `numbering_refused`
     * for any other, the engine's reason as the cause (Ruling 3).
     */
    public static function fromNumbers(DocumentNumbersRefused $refused): self
    {
        $reason = $refused->reason === DocumentNumbersRefusal::NumberTooLong
            ? PurchasingRefusal::NumberTooLong
            : PurchasingRefusal::NumberingRefused;

        return new self($reason, $refused->getMessage(), $refused->reason);
    }

    /**
     * A workflow engine failure, fail closed (D-43, Ruling 6): `workflow_failed`
     * with the exception as `previous` and its plain-string cause or reason as the
     * {@see self::$detail}. There is no enum cause.
     */
    public static function fromWorkflow(WorkflowEvaluationFailed|WorkflowActionRefused $failure): self
    {
        $detail = $failure instanceof WorkflowEvaluationFailed ? $failure->cause : $failure->reason;

        return new self(PurchasingRefusal::WorkflowFailed, $failure->getMessage(), null, $detail, $failure);
    }

    /**
     * An access refusal: `not_authorised` with the resolver's `RefusalReason` as
     * the cause (AC-18, AC-85, AC-86).
     */
    public static function fromAccess(AccessDecision $decision, string $message): self
    {
        return new self(PurchasingRefusal::NotAuthorised, $message, $decision->reason);
    }

    /**
     * The code a caller of Purchasing sees: always the purchasing reason, never
     * the foreign cause (D-37).
     */
    public function code(): string
    {
        return $this->reason->value;
    }
}
