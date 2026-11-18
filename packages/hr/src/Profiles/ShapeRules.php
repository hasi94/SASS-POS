<?php

declare(strict_types=1);

namespace Flexipos\Hr\Profiles;

use Flexipos\Hr\Exceptions\HrRefused;
use Flexipos\Hr\Support\Input;
use Flexipos\Hr\Support\Limits;

/**
 * The shaped text fields of a profile (T-038 AC-47): the two email
 * addresses and the two JSON objects, `emergency_contact` and `address`.
 *
 * Pure. Every refusal is `validation` naming the field, and none echoes a
 * value: these are sensitive fields.
 */
final class ShapeRules
{
    /** @var list<string> */
    public const EMERGENCY_KEYS = ['name', 'relationship', 'phone'];

    /** @var list<string> */
    public const ADDRESS_KEYS = ['line1', 'line2', 'city', 'region', 'postal_code', 'country'];

    /**
     * A syntactically valid email address, trimmed, at most 191 characters;
     * a blank value is absent (null).
     *
     * @throws HrRefused validation
     */
    public static function email(mixed $value, string $field): ?string
    {
        $email = Input::text($value, Limits::EMAIL, false, $field);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw HrRefused::validation($field, "The {$field} must be a valid email address.");
        }

        return $email;
    }

    /**
     * `{name, relationship?, phone}`: keys from the three only; `name` and
     * `phone` required when the object is present; every value a string of at
     * most 100 characters. Null clears.
     *
     * @return array<string, string>|null the canonical object: present keys in the fixed key order
     *
     * @throws HrRefused validation
     */
    public static function emergencyContact(mixed $value): ?array
    {
        return self::object($value, 'emergency_contact', self::EMERGENCY_KEYS, ['name', 'phone'], Limits::EMERGENCY_CONTACT_PART);
    }

    /**
     * `{line1, line2, city, region, postal_code, country}`: keys from the six
     * only, each a string of at most 150 characters, at least one present.
     * Null clears.
     *
     * @return array<string, string>|null
     *
     * @throws HrRefused validation
     */
    public static function address(mixed $value): ?array
    {
        return self::object($value, 'address', self::ADDRESS_KEYS, [], Limits::ADDRESS_PART);
    }

    /**
     * @param  list<string>  $keys  the only keys allowed, in canonical order
     * @param  list<string>  $required
     * @return array<string, string>|null
     */
    private static function object(mixed $value, string $field, array $keys, array $required, int $max): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw HrRefused::validation($field, "The {$field} must be an object.");
        }

        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                throw HrRefused::validation($field, "The {$field} has a key that is not accepted.");
            }
        }

        $shaped = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                if (in_array($key, $required, true)) {
                    throw HrRefused::validation($field, "The {$field} needs a {$key}.");
                }

                continue;
            }

            $text = Input::text($value[$key], $max, in_array($key, $required, true), $field.'.'.$key);

            if ($text !== null) {
                $shaped[$key] = $text;
            }
        }

        if ($shaped === []) {
            throw HrRefused::validation($field, "The {$field} must hold at least one value.");
        }

        return $shaped;
    }
}
