<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Contracts\Organization\ActivationCodeFormat;

/**
 * Generating an activation code's secret (T-014 AC-26, D-17).
 *
 * The alphabet, the pattern and the hash are `ActivationCodeFormat`'s, which
 * lives in the contracts package because auth parses what this class makes
 * and may not import it. What is here is only the one thing auth never does:
 * drawing a secret, from `random_int()`, a CSPRNG.
 *
 * Each character is drawn independently and uniformly over 31 symbols, so
 * there is no modulo bias to correct: `random_int()` itself is uniform over
 * the closed range asked for.
 */
final class ActivationCode
{
    /**
     * A fresh secret in the printed form `XXXX-XXXX`.
     */
    public static function generateSecret(): string
    {
        $alphabet = ActivationCodeFormat::ALPHABET;
        $last = strlen($alphabet) - 1;

        $groups = [];

        for ($group = 0; $group < 2; $group++) {
            $characters = '';

            for ($i = 0; $i < ActivationCodeFormat::GROUP_LENGTH; $i++) {
                $characters .= $alphabet[random_int(0, $last)];
            }

            $groups[] = $characters;
        }

        return implode('-', $groups);
    }

    /**
     * The stored form of a secret: SHA-256 hex of its eight characters.
     */
    public static function hashOf(string $secret): string
    {
        return ActivationCodeFormat::hashOf($secret);
    }

    /**
     * The printed code, as the one response that carries it shows it.
     */
    public static function format(string $tenantPart, string $secret): string
    {
        return ActivationCodeFormat::format($tenantPart, $secret);
    }
}
