<?php

declare(strict_types=1);

namespace Flexipos\Customers\Ledger;

use Flexipos\Contracts\Customers\CreditEntryType;

/**
 * The hard debit/credit side rule for each `CreditEntryType` (T-028 plan
 * D-26, F-9).
 *
 * Internal to `CreditLedgerCalculator` - this is not part of the published
 * contract, because a caller never supplies a side, only `debit`/`credit`
 * amounts; the calculator enforces which side each type must land on.
 */
enum EntrySide
{
    case DebitOnly;
    case CreditOnly;
    case Either;

    public static function for(CreditEntryType $type): self
    {
        return match ($type) {
            CreditEntryType::CreditSale, CreditEntryType::DebitNote => self::DebitOnly,
            CreditEntryType::Payment, CreditEntryType::Refund, CreditEntryType::CreditNote, CreditEntryType::WriteOff => self::CreditOnly,
            CreditEntryType::Opening, CreditEntryType::Adjustment => self::Either,
        };
    }
}
