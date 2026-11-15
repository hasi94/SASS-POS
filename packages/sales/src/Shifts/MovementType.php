<?php

declare(strict_types=1);

namespace Flexipos\Sales\Shifts;

/**
 * `cash_movements.type` and its sign (AC-33, D-39/F-15).
 *
 * The stored `amount` is always positive. The sign is a property of the type and
 * never of the number, so a report can recover "how much was ever handled"
 * without knowing the sign rule first: `cash_in` and `float_in` add to the
 * drawer's expected cash, the other five take cash out of it.
 */
enum MovementType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case FloatIn = 'float_in';
    case FloatOut = 'float_out';
    case Drop = 'drop';
    case PettyCash = 'petty_cash';
    case BankDeposit = 'bank_deposit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /**
     * Whether this movement puts cash into the drawer.
     */
    public function addsToDrawer(): bool
    {
        return $this === self::CashIn || $this === self::FloatIn;
    }
}
