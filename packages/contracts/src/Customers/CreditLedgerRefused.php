<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

use RuntimeException;

/**
 * `CustomerCreditLedger` refused to record an entry (T-028 plan Ruling 2).
 *
 * **Not constructible without a cause**, the `UnitResolutionRefused`/
 * `OrganizationRefused` shape: the constructor is private and every way in
 * is a named constructor carrying exactly one {@see CreditRefusal}. The
 * message is operator-facing and may name ids, amounts and balances; it
 * never carries a secret, because there is none to carry here.
 */
final class CreditLedgerRefused extends RuntimeException
{
    private function __construct(
        public readonly CreditRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownCustomer(string $message): self
    {
        return new self(CreditRefusal::UnknownCustomer, $message);
    }

    public static function customerNotLive(string $message): self
    {
        return new self(CreditRefusal::CustomerNotLive, $message);
    }

    public static function customerNotActive(string $message): self
    {
        return new self(CreditRefusal::CustomerNotActive, $message);
    }

    public static function invalidAmount(string $message): self
    {
        return new self(CreditRefusal::InvalidAmount, $message);
    }

    public static function zeroAmount(string $message): self
    {
        return new self(CreditRefusal::ZeroAmount, $message);
    }

    public static function bothSides(string $message): self
    {
        return new self(CreditRefusal::BothSides, $message);
    }

    public static function wrongSide(string $message): self
    {
        return new self(CreditRefusal::WrongSide, $message);
    }

    public static function outOfRange(string $message): self
    {
        return new self(CreditRefusal::OutOfRange, $message);
    }

    public static function creditNotAllowed(string $message): self
    {
        return new self(CreditRefusal::CreditNotAllowed, $message);
    }

    public static function overLimit(string $message): self
    {
        return new self(CreditRefusal::OverLimit, $message);
    }

    public static function currencyRequired(string $message): self
    {
        return new self(CreditRefusal::CurrencyRequired, $message);
    }

    public static function currencyMismatch(string $message): self
    {
        return new self(CreditRefusal::CurrencyMismatch, $message);
    }

    public static function unknownBranch(string $message): self
    {
        return new self(CreditRefusal::UnknownBranch, $message);
    }

    public static function unknownEmployee(string $message): self
    {
        return new self(CreditRefusal::UnknownEmployee, $message);
    }

    public static function replayConflict(string $message): self
    {
        return new self(CreditRefusal::ReplayConflict, $message);
    }

    public static function reservedReferenceType(string $message): self
    {
        return new self(CreditRefusal::ReservedReferenceType, $message);
    }
}
