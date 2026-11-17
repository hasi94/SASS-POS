<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tenders;

/**
 * `payment_methods.type` (`docs/database.md` section 6.8).
 *
 * `loyalty` and `voucher` may be created as methods but never tendered: neither
 * has a backing deduction this task can call, and accepting one would be free
 * money (D-41, AC-12).
 */
enum MethodType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Digital = 'digital';
    case Credit = 'credit';
    case Voucher = 'voucher';
    case Bank = 'bank';
    case Loyalty = 'loyalty';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function isTenderable(): bool
    {
        return $this !== self::Loyalty && $this !== self::Voucher;
    }
}
