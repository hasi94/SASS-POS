<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Conditions;

/**
 * Exact decimal comparison of two int-or-decimal-string values, with no
 * bcmath and no float (T-020 Ruling 4b).
 *
 * Both sides are normalised to the same shape - a sign, an integer part
 * with no leading zeros, and a four-digit fraction - and then compared as
 * strings of equal length, which is exact for DECIMAL(18,4).
 */
final class DecimalComparator
{
    /**
     * -1, 0 or 1, the way `strcmp()` does.
     */
    public static function compare(int|string $left, int|string $right): int
    {
        [$leftSign, $leftInt, $leftFrac] = self::normalise($left);
        [$rightSign, $rightInt, $rightFrac] = self::normalise($right);

        if ($leftSign !== $rightSign) {
            return $leftSign === '-' ? -1 : 1;
        }

        $width = max(strlen($leftInt), strlen($rightInt));
        $leftInt = str_pad($leftInt, $width, '0', STR_PAD_LEFT);
        $rightInt = str_pad($rightInt, $width, '0', STR_PAD_LEFT);

        $magnitude = strcmp($leftInt.$leftFrac, $rightInt.$rightFrac);
        $result = $magnitude <=> 0;

        // A negative sign reverses the magnitude's ordering.
        return $leftSign === '-' ? -$result : $result;
    }

    public static function equal(int|string $left, int|string $right): bool
    {
        return self::compare($left, $right) === 0;
    }

    /**
     * @return array{0: string, 1: string, 2: string} sign, integer part (no leading zeros, at least one digit), fraction padded to four digits
     */
    private static function normalise(int|string $value): array
    {
        $value = (string) $value;
        $sign = '+';

        if (str_starts_with($value, '-')) {
            $sign = '-';
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        [$intPart, $fracPart] = str_contains($value, '.') ? explode('.', $value, 2) : [$value, ''];

        $intPart = ltrim($intPart, '0');
        $intPart = $intPart === '' ? '0' : $intPart;

        $fracPart = str_pad(substr($fracPart, 0, 4), 4, '0', STR_PAD_RIGHT);

        // A normalised zero has no sign, so "-0" sorts beside "0".
        if ($intPart === '0' && $fracPart === '0000') {
            $sign = '+';
        }

        return [$sign, $intPart, $fracPart];
    }
}
