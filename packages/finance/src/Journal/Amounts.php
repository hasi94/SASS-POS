<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use UnexpectedValueException;

/**
 * Exact decimal money for the journal (T-033 Ruling 4, AC-17 to AC-22).
 *
 * Finance's own, stricter copy of the decimal rules: a value is a string that
 * matches the grammar below and nothing else, so no integer, no leading zero,
 * no sign, no exponent and no fifth decimal place ever gets in. A value that
 * does not fit is refused, never adjusted. Every sum is exact big-decimal
 * arithmetic and every output has exactly four decimals. Nothing in this file
 * converts to a binary floating-point number, because a sum made that way can
 * be a hair off and a ledger cannot be.
 */
final class Amounts
{
    /** The largest value `DECIMAL(18,4)` holds (AC-21). */
    public const MAX = '99999999999999.9999';

    /** The decimal places every stored amount has. */
    public const SCALE = 4;

    private const PATTERN = '/\A(0|[1-9][0-9]{0,13})(\.[0-9]{1,4})?\z/';

    private function __construct() {}

    /**
     * One submitted amount (AC-17, AC-18).
     *
     * @throws PostingRefused invalid_amount
     */
    public static function parse(mixed $value, string $field): BigDecimal
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw PostingRefused::because(
                PostingRefusal::InvalidAmount,
                "The {$field} must be a decimal string with at most 14 integer and 4 decimal digits.",
                $field,
            );
        }

        return BigDecimal::of($value);
    }

    /**
     * The two sides of one line, as four-decimal strings (AC-19).
     *
     * Debit is read first, then credit, then the one-side rule; a line that is
     * both positive or both zero is refused naming the line.
     *
     * @return array{0: string, 1: string} debit, credit
     *
     * @throws PostingRefused invalid_amount, line_both_sides, line_zero
     */
    public static function line(mixed $debit, mixed $credit, int $index): array
    {
        $d = self::parse($debit, 'lines.'.$index.'.debit');
        $c = self::parse($credit, 'lines.'.$index.'.credit');

        if ($d->isPositive() && $c->isPositive()) {
            throw PostingRefused::because(
                PostingRefusal::LineBothSides,
                "Line {$index} carries both a debit and a credit.",
                'lines.'.$index,
            );
        }

        if ($d->isZero() && $c->isZero()) {
            throw PostingRefused::because(
                PostingRefusal::LineZero,
                "Line {$index} carries neither a debit nor a credit.",
                'lines.'.$index,
            );
        }

        return [self::format($d), self::format($c)];
    }

    /**
     * Prove the entry balances and fits the column (AC-20, AC-21).
     *
     * Balance first, then range: an unbalanced entry reports both totals even
     * when one is also out of range.
     *
     * @param  list<string>  $debits  four-decimal strings
     * @param  list<string>  $credits  four-decimal strings
     * @return array{0: string, 1: string} total debit, total credit
     *
     * @throws PostingRefused entry_unbalanced, total_out_of_range
     */
    public static function balance(array $debits, array $credits): array
    {
        $totalDebit = self::sum($debits);
        $totalCredit = self::sum($credits);
        $context = ['total_debit' => self::format($totalDebit), 'total_credit' => self::format($totalCredit)];

        if (! $totalDebit->isEqualTo($totalCredit)) {
            throw PostingRefused::because(
                PostingRefusal::EntryUnbalanced,
                'The debits and credits do not balance.',
                'lines',
                $context,
            );
        }

        if ($totalDebit->isGreaterThan(BigDecimal::of(self::MAX))) {
            throw PostingRefused::because(
                PostingRefusal::TotalOutOfRange,
                'The entry total does not fit the ledger column.',
                'lines',
                $context,
            );
        }

        return [$context['total_debit'], $context['total_credit']];
    }

    /**
     * A stored amount read back as four decimals (AC-22).
     *
     * A driver may hand a DECIMAL back as a string, an integer, or (the SQLite
     * stand-in) a float. A float is formatted with `%.4F`, which does not depend
     * on the `precision` ini (a plain string cast keeps only 14 digits). A double
     * holds about 15 significant digits, so the stand-in is exact only up to
     * that; it cannot hold the column maximum 99999999999999.9999 (17 digits).
     * MySQL returns DECIMAL as a string and is exact at every width.
     *
     * @throws UnexpectedValueException when the value is not a number
     * @throws RoundingNecessaryException when the value has more than four places
     */
    public static function fromStored(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new UnexpectedValueException('A stored amount must be a string, an integer or a float.');
        }

        return self::format(BigDecimal::of(is_float($value) ? sprintf('%.4F', $value) : (string) $value));
    }

    /**
     * Four decimals, refusing any value that would need adjusting to get there.
     */
    public static function format(BigDecimal $value): string
    {
        return (string) $value->toScale(self::SCALE, RoundingMode::Unnecessary);
    }

    /**
     * @param  list<string>  $values
     */
    private static function sum(array $values): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($values as $value) {
            $total = $total->plus(BigDecimal::of($value));
        }

        return $total;
    }
}
