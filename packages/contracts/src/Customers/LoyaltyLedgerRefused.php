<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Customers;

use RuntimeException;

/**
 * `LoyaltyLedger` refused to record a transaction (T-028 plan Ruling 2).
 *
 * **Not constructible without a cause**, the identical shape
 * {@see CreditLedgerRefused} uses: the constructor is private and every way
 * in is a named constructor carrying exactly one {@see LoyaltyRefusal}. The
 * message is operator-facing and may name ids, points and balances; it
 * never carries a secret, because there is none to carry here.
 */
final class LoyaltyLedgerRefused extends RuntimeException
{
    private function __construct(
        public readonly LoyaltyRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownCustomer(string $message): self
    {
        return new self(LoyaltyRefusal::UnknownCustomer, $message);
    }

    public static function customerNotLive(string $message): self
    {
        return new self(LoyaltyRefusal::CustomerNotLive, $message);
    }

    public static function unknownProgram(string $message): self
    {
        return new self(LoyaltyRefusal::UnknownProgram, $message);
    }

    public static function programInactive(string $message): self
    {
        return new self(LoyaltyRefusal::ProgramInactive, $message);
    }

    public static function invalidPoints(string $message): self
    {
        return new self(LoyaltyRefusal::InvalidPoints, $message);
    }

    public static function zeroPoints(string $message): self
    {
        return new self(LoyaltyRefusal::ZeroPoints, $message);
    }

    public static function wrongSign(string $message): self
    {
        return new self(LoyaltyRefusal::WrongSign, $message);
    }

    public static function insufficientPoints(string $message): self
    {
        return new self(LoyaltyRefusal::InsufficientPoints, $message);
    }

    public static function outOfRange(string $message): self
    {
        return new self(LoyaltyRefusal::OutOfRange, $message);
    }

    public static function invalidValue(string $message): self
    {
        return new self(LoyaltyRefusal::InvalidValue, $message);
    }

    public static function unknownEmployee(string $message): self
    {
        return new self(LoyaltyRefusal::UnknownEmployee, $message);
    }

    public static function replayConflict(string $message): self
    {
        return new self(LoyaltyRefusal::ReplayConflict, $message);
    }
}
