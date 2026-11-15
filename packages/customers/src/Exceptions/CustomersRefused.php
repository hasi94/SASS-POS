<?php

declare(strict_types=1);

namespace Flexipos\Customers\Exceptions;

use RuntimeException;

/**
 * The one exception every `packages/customers` slice throws when it refuses
 * to do something that is not a ledger write (T-028 plan section 3; the
 * `OrganizationRefused`/`CatalogRefused` shape).
 *
 * **Not constructible without a cause.** The constructor is private and
 * every way in is a named constructor carrying exactly one
 * {@see CustomersRefusal}. A ledger or loyalty write refuses through the
 * published contract's own `CreditLedgerRefused`/`LoyaltyLedgerRefused`
 * instead - this exception is for everything else (groups, customers,
 * addresses, programs, notes, communications, the merge, and `TierRules`'
 * own shape validation).
 */
final class CustomersRefused extends RuntimeException
{
    private function __construct(
        public readonly CustomersRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message): self
    {
        return new self(CustomersRefusal::Validation, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(CustomersRefusal::NotFound, $message);
    }

    public static function duplicate(string $message): self
    {
        return new self(CustomersRefusal::Duplicate, $message);
    }

    public static function inUse(string $message): self
    {
        return new self(CustomersRefusal::InUse, $message);
    }

    public static function stale(string $message): self
    {
        return new self(CustomersRefusal::Stale, $message);
    }

    public static function maintained(string $message): self
    {
        return new self(CustomersRefusal::Maintained, $message);
    }

    public static function balanceOutstanding(string $message): self
    {
        return new self(CustomersRefusal::BalanceOutstanding, $message);
    }

    public static function selfMerge(string $message): self
    {
        return new self(CustomersRefusal::SelfMerge, $message);
    }

    public static function alreadyMerged(string $message): self
    {
        return new self(CustomersRefusal::AlreadyMerged, $message);
    }

    public static function notLive(string $message): self
    {
        return new self(CustomersRefusal::NotLive, $message);
    }

    public static function currencyMismatch(string $message): self
    {
        return new self(CustomersRefusal::CurrencyMismatch, $message);
    }
}
