<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Psr\Log\LoggerInterface;

/**
 * What a registry row says a value may be, and the check of a value against
 * it (T-014 Ruling 9, AC-46).
 *
 * **The vocabulary is closed**: `type` is one of {@see self::TYPES} and
 * `validation` is a JSON object whose keys come from {@see self::KEYS_BY_TYPE}
 * for that type. A registry row outside it - an unknown type, an unknown or
 * misplaced rule, a rule of the wrong shape - **fails closed**: the write is
 * refused `validation`, and an error-level line names the **setting key** and
 * a short problem code, never a value (a registry row is code-authored, but a
 * line that echoed values is a sink for the day it is not).
 *
 * Messages of the refusals name the key and the rule, never the submitted
 * value: the value may be a secret.
 */
final class SettingsRules
{
    public const TYPES = ['string', 'integer', 'number', 'boolean', 'json'];

    public const VALIDATION_KEYS = ['min', 'max', 'max_length', 'in'];

    /** Which rules apply to which type; a rule outside its type is a malformed row. */
    private const KEYS_BY_TYPE = [
        'string' => ['max_length', 'in'],
        'integer' => ['min', 'max', 'in'],
        'number' => ['min', 'max', 'in'],
        'boolean' => [],
        'json' => [],
    ];

    /**
     * The registry row's type and rules, or a refusal and a log line.
     *
     * @return array{type: string, rules: array<string, mixed>}
     *
     * @throws OrganizationRefused validation (the row is malformed)
     */
    public static function definition(object $registry, LoggerInterface $logger): array
    {
        $key = (string) $registry->key;
        $type = (string) $registry->type;

        if (! array_key_exists($type, self::KEYS_BY_TYPE)) {
            self::malformed($logger, $key, 'unknown_type');
        }

        $rules = [];

        if ($registry->validation !== null) {
            $decoded = json_decode((string) $registry->validation, true);

            if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                self::malformed($logger, $key, 'validation_not_an_object');
            }

            $rules = $decoded;
        }

        foreach ($rules as $rule => $argument) {
            if (! in_array((string) $rule, self::KEYS_BY_TYPE[$type], true)) {
                self::malformed($logger, $key, 'unknown_validation_key');
            }

            if (! self::argumentIsWellFormed((string) $rule, $argument)) {
                self::malformed($logger, $key, 'malformed_validation_argument');
            }
        }

        return ['type' => $type, 'rules' => $rules];
    }

    /**
     * Check a value against the type and rules.
     *
     * @param  array{type: string, rules: array<string, mixed>}  $definition
     *
     * @throws OrganizationRefused validation
     */
    public static function check(mixed $value, array $definition, string $key): void
    {
        $type = $definition['type'];
        $rules = $definition['rules'];

        $matches = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
            default => is_array($value),
        };

        if (! $matches) {
            throw OrganizationRefused::validation("The value for [{$key}] must be of type {$type}.");
        }

        if (isset($rules['max_length']) && is_string($value) && mb_strlen($value) > (int) $rules['max_length']) {
            throw OrganizationRefused::validation("The value for [{$key}] is longer than max_length.");
        }

        if (isset($rules['min']) && (is_int($value) || is_float($value)) && $value < $rules['min']) {
            throw OrganizationRefused::validation("The value for [{$key}] is below min.");
        }

        if (isset($rules['max']) && (is_int($value) || is_float($value)) && $value > $rules['max']) {
            throw OrganizationRefused::validation("The value for [{$key}] is above max.");
        }

        if (isset($rules['in']) && is_array($rules['in']) && ! self::isOneOf($value, $rules['in'])) {
            throw OrganizationRefused::validation("The value for [{$key}] is not one of the allowed values.");
        }
    }

    /**
     * @param  array<mixed>  $allowed
     */
    private static function isOneOf(mixed $value, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if ((is_int($value) || is_float($value)) && (is_int($candidate) || is_float($candidate))) {
                if ($candidate == $value) {
                    return true;
                }

                continue;
            }

            if ($candidate === $value) {
                return true;
            }
        }

        return false;
    }

    private static function argumentIsWellFormed(string $rule, mixed $argument): bool
    {
        return match ($rule) {
            'min', 'max' => is_int($argument) || (is_float($argument) && is_finite($argument)),
            'max_length' => is_int($argument) && $argument >= 1,
            default => is_array($argument) && $argument !== [] && array_is_list($argument)
                && array_reduce($argument, static fn (bool $all, mixed $item): bool => $all && (is_string($item) || is_int($item) || is_float($item)), true),
        };
    }

    /**
     * @throws OrganizationRefused validation
     */
    private static function malformed(LoggerInterface $logger, string $key, string $problem): never
    {
        $logger->error('A settings registry row is malformed, so a write to it was refused.', [
            'key' => $key,
            'problem' => $problem,
        ]);

        throw OrganizationRefused::validation("The registry row for [{$key}] is malformed.");
    }
}
