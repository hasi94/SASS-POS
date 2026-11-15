<?php

declare(strict_types=1);

namespace Flexipos\Customers\Support;

use Flexipos\Customers\Exceptions\CustomersRefused;

/**
 * Reading submitted fields, in one place (T-028 plan Ruling 10, the
 * organization/catalog precedent).
 *
 * **A wrong type is `validation`, never a `TypeError`**, and a field the
 * operation does not take is `validation` too, never silently ignored: a
 * handler that picked out the fields it understood would turn "you may not
 * change this" into a quiet success that changed nothing. Every handler
 * hands the whole submitted array to {@see self::only()} first.
 */
final class Fields
{
    /**
     * Refuse any field outside the allowed set.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws CustomersRefused validation
     */
    public static function only(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), $allowed));

        if ($unknown !== []) {
            throw CustomersRefused::validation('These fields are not accepted here: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function has(array $fields, string $key): bool
    {
        return array_key_exists($key, $fields);
    }

    /**
     * A required string, trimmed, non-empty and within its width.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function requiredText(array $fields, string $key, int $max): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value)) {
            throw CustomersRefused::validation("The {$key} is required and must be a string.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw CustomersRefused::validation("The {$key} must be 1 to {$max} characters.");
        }

        return $value;
    }

    /**
     * An optional string: null when absent or null, refused when present and
     * not a string, trimmed to null when blank.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function optionalText(array $fields, string $key, int $max): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw CustomersRefused::validation("The {$key} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw CustomersRefused::validation("The {$key} must be at most {$max} characters.");
        }

        return $value;
    }

    /**
     * A strict boolean: `"true"` is a string and is refused.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function flag(array $fields, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        if (! is_bool($fields[$key])) {
            throw CustomersRefused::validation("The {$key} must be true or false.");
        }

        return $fields[$key];
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function integer(array $fields, string $key, ?int $default = null): ?int
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < -2147483648 || $value > 2147483647) {
            throw CustomersRefused::validation("The {$key} must be an integer.");
        }

        return $value;
    }

    /**
     * An unsigned small integer, or null, or absent (returns `$default`).
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function unsignedSmallInt(array $fields, string $key, ?int $default = null): ?int
    {
        $value = self::integer($fields, $key, $default);

        if ($value !== null && ($value < 0 || $value > 65535)) {
            throw CustomersRefused::validation("The {$key} must be between 0 and 65535.");
        }

        return $value;
    }

    /**
     * An int or a plain decimal string of at most `$scale` decimals, never a
     * float. Required when `$default` is null and the key is absent.
     *
     * **`$maxIntegerDigits`** (W-6) caps the number of digits before the
     * decimal point, so a value that fits the `$scale` but would overflow
     * the target column's real precision (e.g. `DECIMAL(18,4)` allows at
     * most 14 integer digits, `DECIMAL(9,4)` at most 5) is refused here
     * instead of raising a raw `QueryException` on strict MySQL. `null`
     * means no cap.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function decimal(array $fields, string $key, int $scale, bool $allowNegative = false, ?string $default = null, ?int $maxIntegerDigits = null): ?string
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
            throw CustomersRefused::validation("The {$key} must be an int or a decimal string.");
        }

        $pattern = $allowNegative ? '/^-?\d+(\.\d{1,'.$scale.'})?$/' : '/^\d+(\.\d{1,'.$scale.'})?$/';

        if (preg_match($pattern, $value) !== 1) {
            throw CustomersRefused::validation("The {$key} must be a decimal with at most {$scale} decimal places".($allowNegative ? '.' : ', and not negative.'));
        }

        if ($maxIntegerDigits !== null) {
            $integerDigits = mb_strlen(ltrim(explode('.', ltrim($value, '-'))[0], '0') ?: '0');

            if ($integerDigits > $maxIntegerDigits) {
                throw CustomersRefused::validation("The {$key} must have at most {$maxIntegerDigits} digits before the decimal point.");
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws CustomersRefused validation
     */
    public static function enum(array $fields, string $key, array $allowed, ?string $default): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw CustomersRefused::validation("The {$key} must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * A required int version number, for optimistic concurrency (plan
     * Ruling 7, AC-4).
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function requiredVersion(array $fields): int
    {
        if (! array_key_exists('version', $fields)) {
            throw CustomersRefused::validation('A version is required.');
        }

        $value = $fields['version'];

        if (! is_int($value)) {
            throw CustomersRefused::validation('The version must be an integer.');
        }

        return $value;
    }

    /**
     * A valid `Y-m-d` date string, or null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function date(array $fields, string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || \DateTime::createFromFormat('Y-m-d', $value) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw CustomersRefused::validation("The {$key} must be a valid Y-m-d date.");
        }

        return $value;
    }

    /**
     * A valid `Y-m-d H:i:s` datetime, optionally with up to 6 fractional
     * digits (`Y-m-d H:i:s.v`), or null. W-6: without this, an unvalidated
     * `expires_at` reaches a DATETIME(3) column as a raw string, raising an
     * uncaught `QueryException` on strict MySQL instead of a refusal.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function datetime(array $fields, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $fields) || $fields[$key] === null) {
            return null;
        }

        $value = $fields[$key];

        if (! is_string($value) || mb_strlen($value) > $max) {
            throw CustomersRefused::validation("The {$key} must be a string of at most {$max} characters.");
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $value) !== 1) {
            throw CustomersRefused::validation("The {$key} must be a Y-m-d H:i:s[.v] datetime.");
        }

        if (\DateTime::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19)) === false) {
            throw CustomersRefused::validation("The {$key} must be a valid datetime.");
        }

        return $value;
    }

    /**
     * Any JSON-encodable value, encoded to a string, or null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws CustomersRefused validation
     */
    public static function json(array $fields, string $key): ?string
    {
        if (! array_key_exists($key, $fields) || $fields[$key] === null) {
            return null;
        }

        $encoded = json_encode($fields[$key]);

        if ($encoded === false) {
            throw CustomersRefused::validation("The {$key} is not a valid JSON value.");
        }

        return $encoded;
    }
}
