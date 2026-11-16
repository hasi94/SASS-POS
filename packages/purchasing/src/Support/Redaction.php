<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

/**
 * What a supplier audit image may show of `bank_details` (T-034 Ruling 10, D-9,
 * AC-17).
 *
 * Bank details are payment data: no audit row, log line, refusal message or
 * outbox payload carries them. Every supplier audit image replaces a non-null
 * `bank_details` with {@see self::REDACTED}. If the audit trail diffed two
 * redacted images it would call them equal and drop the field from
 * `changed_fields`, so the **after** image carries {@see self::CHANGED} instead
 * when the value differs from the stored one, and the field is still listed.
 *
 * Pure: it never reads a connection and never throws.
 */
final class Redaction
{
    public const FIELD = 'bank_details';

    public const REDACTED = '[redacted]';

    public const CHANGED = '[redacted, changed]';

    /**
     * The before image: a non-null `bank_details` hidden, everything else as is.
     *
     * @param  array<string, mixed>|null  $before
     * @return array<string, mixed>|null
     */
    public static function before(?array $before): ?array
    {
        if ($before === null || ($before[self::FIELD] ?? null) === null) {
            return $before;
        }

        $before[self::FIELD] = self::REDACTED;

        return $before;
    }

    /**
     * The after image: a non-null `bank_details` hidden, marked as changed when
     * it is not the value the before image held. A null before (a create)
     * counts as a change.
     *
     * @param  array<string, mixed>|null  $before  the raw stored image, not yet redacted
     * @param  array<string, mixed>|null  $after  the raw new image
     * @return array<string, mixed>|null
     */
    public static function after(?array $before, ?array $after): ?array
    {
        if ($after === null || ($after[self::FIELD] ?? null) === null) {
            return $after;
        }

        $same = $before !== null && self::canonical($before[self::FIELD] ?? null) === self::canonical($after[self::FIELD]);

        $after[self::FIELD] = $same ? self::REDACTED : self::CHANGED;

        return $after;
    }

    /**
     * A JSON document in one comparable form: decoded, object keys sorted, and
     * encoded again, so two spellings of the same object are one value.
     */
    private static function canonical(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            // Not JSON at all: compare it as the text it is.
            if ($decoded === null) {
                return $value;
            }

            $value = $decoded;
        }

        return json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
