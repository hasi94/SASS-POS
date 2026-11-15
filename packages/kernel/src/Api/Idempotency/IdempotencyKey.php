<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Idempotency;

/**
 * The `Idempotency-Key` header's format rule (T-021 AC-17): 1 to 80
 * characters of `A-Za-z0-9_-:.`
 */
final class IdempotencyKey
{
    // `\z` rather than `$`: in PCRE, `$` also matches just before a
    // trailing `\n`, which would let "abc\n" through as valid (S-2).
    public const PATTERN = '/^[A-Za-z0-9_\-:.]{1,80}\z/';

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }
}
