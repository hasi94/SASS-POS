<?php

declare(strict_types=1);

namespace Flexipos\Finance\Support;

use BackedEnum;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Illuminate\Support\Str;

/**
 * Strict, pure parsing of one submitted value (T-032 plan Ruling 3).
 *
 * Finance does not copy the other packages' `Fields`: those trim every
 * string, accept a date `createFromFormat` happens to roll over, and coerce
 * types. Each method here takes `mixed`, accepts exactly one shape and
 * refuses anything else with a {@see FinanceRefused} naming the field. None
 * of them touches a database, so every rule is a dataset.
 *
 * Regular expressions use `\A` and `\z`, never `^` and `$`: `$` matches
 * before a trailing newline, so `"AB1\n"` would pass.
 */
final class Input
{
    /**
     * An account code, folded to upper case (AC-2).
     *
     * The raw bytes are checked **before** folding and before anything else:
     * any byte outside printable ASCII, and any whitespace anywhere, is
     * refused. Codes are never trimmed, because trimming would hide a
     * collision (D-26).
     *
     * @throws FinanceRefused invalid_code
     */
    public static function code(mixed $value, string $field = 'code'): string
    {
        if (! is_string($value) || $value === '') {
            throw self::invalidCode($field);
        }

        if (preg_match('/[^\x21-\x7E]/', $value) === 1) {
            throw self::invalidCode($field);
        }

        $folded = strtoupper($value);
        $tail = Limits::CODE - 1;

        if (preg_match('/\A[0-9A-Z][0-9A-Z.\-]{0,'.$tail.'}\z/', $folded) !== 1) {
            throw self::invalidCode($field);
        }

        return $folded;
    }

    /**
     * A free-text field, trimmed, counted in characters (AC-4, AC-34, F-16).
     *
     * Order matters and is fixed: a string, valid UTF-8, no control
     * character in the **raw** value (so a trailing newline is refused, not
     * quietly trimmed), then trim, then blank, then width. Over-length input
     * is refused, never truncated. An optional field accepts null and
     * nothing else blank.
     *
     * @throws FinanceRefused validation
     */
    public static function text(mixed $value, int $max, bool $required, string $field): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }

        if (! is_string($value)) {
            throw FinanceRefused::validation($field, "The {$field} must be a string.");
        }

        if (preg_match('//u', $value) !== 1) {
            throw FinanceRefused::validation($field, "The {$field} is not valid UTF-8.");
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw FinanceRefused::validation($field, "The {$field} contains a control character.");
        }

        $trimmed = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);

        if ($trimmed === null || $trimmed === '') {
            throw FinanceRefused::validation($field, "The {$field} must not be blank.");
        }

        if (mb_strlen($trimmed) > $max) {
            throw FinanceRefused::validation($field, "The {$field} must be at most {$max} characters.");
        }

        return $trimmed;
    }

    /**
     * A member of a string-backed enum, matched exactly (AC-5).
     *
     * No trimming and no case folding: the engine compares enum columns
     * case-insensitively, so `Asset` would otherwise be stored as a value
     * the application never produces (FP-435).
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     *
     * @throws FinanceRefused validation
     */
    public static function enum(mixed $value, string $enum, string $field): BackedEnum
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw FinanceRefused::validation($field, "The {$field} is not one of the accepted values.");
        }

        return $case;
    }

    /**
     * A JSON boolean and nothing else: not `"true"`, not `1`, not null (AC-9).
     *
     * @throws FinanceRefused validation
     */
    public static function flag(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw FinanceRefused::validation($field, "The {$field} must be a boolean.");
        }

        return $value;
    }

    /**
     * An integer from 0 to 65535 (AC-9). A float or a numeric string is refused.
     *
     * @throws FinanceRefused validation
     */
    public static function smallUnsigned(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0 || $value > 65535) {
            throw FinanceRefused::validation($field, "The {$field} must be an integer from 0 to 65535.");
        }

        return $value;
    }

    /**
     * A real calendar date in strict `Y-m-d` (AC-32, AC-48).
     *
     * `checkdate` is what refuses `2026-02-30`: `DateTime::createFromFormat`
     * alone rolls it over to March (FP-436). The year range is the caller's
     * check, because the gate and the calendar disagree about it.
     *
     * @throws FinanceRefused invalid_date
     */
    public static function date(mixed $value, string $field): string
    {
        if (is_string($value) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        throw FinanceRefused::because(
            FinanceRefusal::InvalidDate,
            "The {$field} must be a real date in Y-m-d form.",
            $field,
        );
    }

    /**
     * A 26-character ULID, folded to upper case.
     *
     * @throws FinanceRefused validation
     */
    public static function id(mixed $value, string $field): string
    {
        if (! is_string($value) || strlen($value) !== 26 || ! Str::isUlid($value)) {
            throw FinanceRefused::validation($field, "The {$field} is not a 26-character ULID.");
        }

        return strtoupper($value);
    }

    private static function invalidCode(string $field): FinanceRefused
    {
        return FinanceRefused::because(
            FinanceRefusal::InvalidCode,
            'The '.$field.' must be 1 to '.Limits::CODE.' upper-case letters, digits, "." or "-", starting with a letter or digit, with no whitespace.',
            $field,
        );
    }
}
