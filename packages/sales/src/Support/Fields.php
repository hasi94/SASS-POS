<?php

declare(strict_types=1);

namespace Flexipos\Sales\Support;

use Flexipos\Sales\Exceptions\SalesRefused;

/**
 * Reading submitted fields, in one place (the pricing `Fields` precedent).
 *
 * **A wrong type is `validation`, never a `TypeError`**, and a field the
 * operation does not take is `validation` too, never silently ignored: a handler
 * that picked out the fields it understood would turn "you may not change the
 * code" into a quiet success that changed nothing. That is also how a deferred
 * column (`financial_account_id`, `gateway`, `settings`) and every client-sent
 * `[SYNC]` field or date is refused as unknown (F-8, R-39). Every handler hands
 * the whole submitted array to {@see self::only()} first.
 *
 * Every decimal is a **string** (or an int) of at most 14 integer and 4 fraction
 * digits, never a float and never negative (R-39).
 */
final class Fields
{
    /**
     * Refuse any field outside the allowed set.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws SalesRefused validation
     */
    public static function only(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), $allowed));

        if ($unknown !== []) {
            throw SalesRefused::validation('These fields are not accepted here: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * A required string, trimmed, non-empty and within its width.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function requiredText(array $fields, string $key, int $max): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value)) {
            throw SalesRefused::validation("The {$key} is required and must be a string.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw SalesRefused::validation("The {$key} must be 1 to {$max} characters.");
        }

        return $value;
    }

    /**
     * An optional string: null when absent or null, refused when present and not
     * a string, trimmed to null when blank.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function optionalText(array $fields, string $key, int $max): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw SalesRefused::validation("The {$key} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw SalesRefused::validation("The {$key} must be at most {$max} characters.");
        }

        return $value;
    }

    /**
     * An optional free-text note: null when absent, null or blank, refused when
     * not a string or longer than a `TEXT` column holds (65,535 bytes, R-39).
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function notes(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw SalesRefused::validation("The {$key} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 65535) {
            throw SalesRefused::validation("The {$key} must be at most 65535 bytes.");
        }

        return $value;
    }

    /**
     * A strict boolean: `"true"` is a string and is refused.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function flag(array $fields, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        if (! is_bool($fields[$key])) {
            throw SalesRefused::validation("The {$key} must be true or false.");
        }

        return $fields[$key];
    }

    /**
     * An integer within `[$min, $max]`; `$default` when the key is absent.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function integer(array $fields, string $key, int $default, int $min, int $max): int
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_int($value) || $value < $min || $value > $max) {
            throw SalesRefused::validation("The {$key} must be an integer from {$min} to {$max}.");
        }

        return $value;
    }

    /**
     * An int or a plain non-negative decimal string of at most `$scale`
     * decimals, never a float and never more than `$integerDigits` integer
     * digits (the column's own width). `$default` when the key is absent, null
     * when it is present and null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function decimal(array $fields, string $key, int $integerDigits, int $scale, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw SalesRefused::validation("The {$key} must be an int or a decimal string.");
        }

        if (preg_match('/^\d{1,'.$integerDigits.'}(\.\d{1,'.$scale.'})?$/D', $value) !== 1) {
            throw SalesRefused::validation("The {$key} must be a non-negative decimal of at most {$integerDigits} integer and {$scale} decimal digits.");
        }

        return $value;
    }

    /**
     * A money or quantity column, `DECIMAL(18,4)`, zero allowed. Null when the
     * key is absent or null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function money(array $fields, string $key, ?string $default = null): ?string
    {
        return self::decimal($fields, $key, 14, 4, $default);
    }

    /**
     * A money or quantity column that must be present and strictly greater than
     * zero: a quantity, a tender amount, a movement amount (R-39), where zero is
     * meaningless.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function positiveMoney(array $fields, string $key): string
    {
        $value = self::money($fields, $key);

        if ($value === null || Money::isZero($value)) {
            throw SalesRefused::validation("The {$key} is required and must be greater than zero.");
        }

        return $value;
    }

    /**
     * A money column that must be present, zero allowed (an opening float).
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function requiredMoney(array $fields, string $key): string
    {
        return self::money($fields, $key) ?? throw SalesRefused::validation("The {$key} is required.");
    }

    /**
     * A rate or percentage column, `DECIMAL(9,4)`.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws SalesRefused validation
     */
    public static function rate(array $fields, string $key, ?string $default = null): ?string
    {
        return self::decimal($fields, $key, 5, 4, $default);
    }

    /**
     * A closed string set, compared exactly and in lower case (R-38): a value
     * that differs only by case is not a member.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws SalesRefused validation
     */
    public static function enum(array $fields, string $key, array $allowed, ?string $default): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw SalesRefused::validation("The {$key} must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }
}
