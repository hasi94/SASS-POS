<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use DateTimeImmutable;
use DateTimeZone;
use Flexipos\Organization\Exceptions\OrganizationRefused;

/**
 * Reading submitted fields, in one place (T-014 AC-11, AC-12).
 *
 * **A wrong type is `validation`, never a `TypeError`**, and a field the
 * operation does not take is `validation` too, never silently ignored
 * (AC-12): a handler that picked out the fields it understood would turn the
 * refusal "you may not change the code" into a quiet success that changed
 * nothing. So every handler hands the whole submitted array to
 * {@see self::only()} first.
 *
 * Messages name the field and the rule and never the submitted value: the
 * value may be a secret, and an echoed value is a sink.
 *
 * The limits are the columns' widths (T-014 Ruling 10 (d)); a value over its
 * width is refused here and never truncated by the engine.
 */
final class Fields
{
    /** The values `business_type` may hold, mirroring `tenants.business_type` (Ruling 10 (a)). */
    public const BUSINESS_TYPES = ['retail', 'restaurant', 'both'];

    /**
     * Refuse any field outside the allowed set.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $allowed
     *
     * @throws OrganizationRefused validation
     */
    public static function only(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), $allowed));

        if ($unknown !== []) {
            throw OrganizationRefused::validation('These fields are not accepted here: '.implode(', ', $unknown).'.');
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
     * @throws OrganizationRefused validation
     */
    public static function requiredText(array $fields, string $key, int $max): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value)) {
            throw OrganizationRefused::validation("The {$key} is required and must be a string.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max) {
            throw OrganizationRefused::validation("The {$key} must be 1 to {$max} characters.");
        }

        return $value;
    }

    /**
     * An optional string: null when absent or null, refused when present and
     * not a string, trimmed to null when blank.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function optionalText(array $fields, string $key, int $max): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw OrganizationRefused::validation("The {$key} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw OrganizationRefused::validation("The {$key} must be at most {$max} characters.");
        }

        return $value;
    }

    /**
     * A strict boolean: `"true"` is a string and is refused.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function flag(array $fields, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        if (! is_bool($fields[$key])) {
            throw OrganizationRefused::validation("The {$key} must be true or false.");
        }

        return $fields[$key];
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function integer(array $fields, string $key, int $default): int
    {
        if (! array_key_exists($key, $fields)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_int($value) || $value < -2147483648 || $value > 2147483647) {
            throw OrganizationRefused::validation("The {$key} must be an integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function timezone(array $fields): string
    {
        $value = self::requiredText($fields, 'timezone', 64);

        if (! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw OrganizationRefused::validation('The timezone is not an IANA zone.');
        }

        return $value;
    }

    /**
     * Three letters, folded to upper case.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function currency(array $fields): string
    {
        $value = $fields['currency'] ?? null;

        if (! is_string($value) || preg_match('/\A[A-Za-z]{3}\z/', trim($value)) !== 1) {
            throw OrganizationRefused::validation('The currency is required and must be three letters.');
        }

        return strtoupper(trim($value));
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function email(array $fields): ?string
    {
        $value = self::optionalText($fields, 'email', 191);

        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw OrganizationRefused::validation('The email is not a valid address.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function businessType(array $fields): ?string
    {
        $value = $fields['business_type'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! in_array($value, self::BUSINESS_TYPES, true)) {
            throw OrganizationRefused::validation('The business_type must be null or one of '.implode(', ', self::BUSINESS_TYPES).'.');
        }

        return $value;
    }

    /**
     * A calendar date `Y-m-d`, or null.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function date(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw OrganizationRefused::validation("The {$key} must be a date as YYYY-MM-DD.");
        }

        return $value;
    }

    /**
     * An address as a JSON object (or null), encoded for the JSON column.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws OrganizationRefused validation
     */
    public static function address(array $fields): ?string
    {
        $value = $fields['address'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw OrganizationRefused::validation('The address must be an object of values, or null.');
        }

        foreach ($value as $part) {
            if (! is_scalar($part) && $part !== null) {
                throw OrganizationRefused::validation('The address may hold only scalar values.');
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        if ($encoded === false || strlen($encoded) > 2000) {
            throw OrganizationRefused::validation('The address is too long.');
        }

        return $encoded;
    }
}
