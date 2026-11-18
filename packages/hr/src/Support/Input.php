<?php

declare(strict_types=1);

namespace Flexipos\Hr\Support;

use BackedEnum;
use Flexipos\Hr\Exceptions\HrRefused;
use Illuminate\Support\Str;

/**
 * Strict, pure parsing of one submitted value (T-038 plan Ruling 1, step 5).
 *
 * Copied from finance's strict parser, not from the older trimming copies
 * (FP-433 tracks the per-package duplication). Each method takes `mixed`,
 * accepts exactly one shape and refuses anything else with a
 * {@see HrRefused} naming the field. **No message ever echoes the value**:
 * a refusal here may be about a sensitive field. None of it touches a
 * database, so every rule is a dataset.
 *
 * Regular expressions use `\A` and `\z`, never `^` and `$`: `$` matches
 * before a trailing newline, so `"FIN-01\n"` would pass.
 */
final class Input
{
    /**
     * A department or designation code, folded to upper case (AC-17, D-41).
     *
     * One expression decides: after upper-casing it must match
     * `\A[0-9A-Z][0-9A-Z.-]{0,19}\z`. That alone refuses whitespace anywhere,
     * a leading `.` or `-`, a control byte, NUL, any non-ASCII letter (`É`,
     * a full-width `Ａ`) and a 21st character. Codes are never trimmed,
     * because trimming would hide a collision (D-19).
     *
     * @throws HrRefused validation
     */
    public static function code(mixed $value, string $field = 'code'): string
    {
        if (! is_string($value)) {
            throw self::invalidCode($field);
        }

        $tail = Limits::CODE - 1;

        if (preg_match('/\A[0-9A-Z][0-9A-Z.\-]{0,'.$tail.'}\z/', strtoupper($value)) !== 1) {
            throw self::invalidCode($field);
        }

        return strtoupper($value);
    }

    /**
     * A free-text field, trimmed, counted in characters (D-19, AC-16, AC-47).
     *
     * Order is fixed: a string, valid UTF-8, no control character in the
     * **raw** value (a trailing newline is refused, not quietly trimmed),
     * then trim, then blank, then width. Over-length input is refused, never
     * truncated. A blank value after trimming is a refusal when required and
     * `null` (absent on create, a clear on update) when optional; a literal
     * `null` is accepted only when optional.
     *
     * @throws HrRefused validation
     */
    public static function text(mixed $value, int $max, bool $required, string $field): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }

        if (! is_string($value)) {
            throw HrRefused::validation($field, "The {$field} must be a string.");
        }

        if (preg_match('//u', $value) !== 1) {
            throw HrRefused::validation($field, "The {$field} is not valid UTF-8.");
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw HrRefused::validation($field, "The {$field} contains a control character.");
        }

        $trimmed = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);

        if ($trimmed === null || $trimmed === '') {
            if ($required) {
                throw HrRefused::validation($field, "The {$field} must not be blank.");
            }

            return null;
        }

        if (mb_strlen($trimmed) > $max) {
            throw HrRefused::validation($field, "The {$field} must be at most {$max} characters.");
        }

        return $trimmed;
    }

    /**
     * A member of a string-backed enum, matched exactly.
     *
     * No trimming and no case folding: the engine compares enum columns
     * case-insensitively, so `Permanent` would otherwise be stored as a value
     * the application never produces (FP-435).
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     *
     * @throws HrRefused validation
     */
    public static function enum(mixed $value, string $enum, string $field): BackedEnum
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            throw HrRefused::validation($field, "The {$field} is not one of the accepted values.");
        }

        return $case;
    }

    /**
     * A JSON boolean and nothing else: not `"true"`, not `1`, not null.
     *
     * @throws HrRefused validation
     */
    public static function flag(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw HrRefused::validation($field, "The {$field} must be a boolean.");
        }

        return $value;
    }

    /**
     * An integer within `[$min, $max]` (a designation level is 0 to 255). A
     * float or a numeric string is refused.
     *
     * @throws HrRefused validation
     */
    public static function integer(mixed $value, int $min, int $max, string $field): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw HrRefused::validation($field, "The {$field} must be an integer from {$min} to {$max}.");
        }

        return $value;
    }

    /**
     * A real calendar date in strict `Y-m-d` (AC-41).
     *
     * `checkdate` is what refuses `2025-02-30`: `DateTime::createFromFormat`
     * alone rolls it over to March (FP-436).
     *
     * @throws HrRefused validation
     */
    public static function date(mixed $value, string $field): string
    {
        if (is_string($value) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        throw HrRefused::validation($field, "The {$field} must be a real date in Y-m-d form.");
    }

    /**
     * A 26-character ULID, folded to upper case.
     *
     * @throws HrRefused validation
     */
    public static function id(mixed $value, string $field): string
    {
        if (! is_string($value) || strlen($value) !== 26 || ! Str::isUlid($value)) {
            throw HrRefused::validation($field, "The {$field} is not a 26-character ULID.");
        }

        return strtoupper($value);
    }

    private static function invalidCode(string $field): HrRefused
    {
        return HrRefused::validation(
            $field,
            'The '.$field.' must be 1 to '.Limits::CODE.' upper-case letters, digits, "." or "-", starting with a letter or digit, with no whitespace.',
        );
    }
}
