<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

/**
 * A fresh, high-entropy database password (T-026 AC-15).
 *
 * `store_credentials` generates both passwords; the engine port never does -
 * deliberately split out so a password exists and is durably stored before
 * anything needs it (B-1).
 *
 * 40 characters of `[A-Za-z0-9]`, from `random_bytes()`. 40 was chosen as a
 * round, generous length well clear of any MySQL/MariaDB password-length
 * default; the alphabet avoids any character a shell, a `.env` parser or a
 * DSN string could misinterpret.
 */
final class CredentialGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public const LENGTH = 40;

    public static function generate(): Secret
    {
        $alphabetLength = strlen(self::ALPHABET);
        $bytes = random_bytes(self::LENGTH);
        $value = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $value .= self::ALPHABET[ord($bytes[$i]) % $alphabetLength];
        }

        return new Secret($value);
    }
}
