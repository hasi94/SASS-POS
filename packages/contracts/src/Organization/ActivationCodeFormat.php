<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

use InvalidArgumentException;

/**
 * What an activation code is made of, written once (T-014 D-17, D-33).
 *
 * `<tenant code>-XXXX-XXXX`: the tenant part resolves the shop before any
 * authentication exists, and the last two groups are the secret. **Two
 * packages must agree on this and neither may import the other**, so the
 * definition lives here: organization generates and hashes the secret and
 * auth parses it from a request body, and a change to the alphabet is one
 * edit that both read.
 *
 * **The alphabet has 31 characters, not 32** (D-33). Excluding `0`, `O`, `1`,
 * `I` and `L` from the 36 alphanumerics leaves 31, so two groups of four carry
 * 31^8, about 2^39.6.
 *
 * Only a SHA-256 of the eight secret characters, hyphen excluded, is ever
 * stored (D-17).
 */
final class ActivationCodeFormat
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const GROUP_LENGTH = 4;

    /** The secret as submitted, hyphen included, after upper-casing: `XXXX-XXXX`. */
    public const SECRET_PATTERN = '/\A[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}\z/';

    /** A stored tenant code the activation code can carry (Ruling 7). */
    public const TENANT_PART_PATTERN = '/\A[A-Z0-9](?:[A-Z0-9-]{0,28}[A-Z0-9])?\z/';

    /** The longest submission worth parsing: a 30-character tenant, a hyphen and `XXXX-XXXX`. */
    public const MAX_SUBMITTED_LENGTH = 64;

    /**
     * Split a submitted code into its tenant part and its `XXXX-XXXX` secret,
     * or null when it is not shaped like one.
     *
     * Splits from the right, so tenant codes may themselves hold hyphens
     * (Ruling 7). Shape only: whether the tenant or the secret exists is not
     * decided here.
     *
     * @return array{tenantPart: string, secret: string}|null
     */
    public static function parse(mixed $submitted): ?array
    {
        if (! is_string($submitted) || $submitted === '' || strlen($submitted) > self::MAX_SUBMITTED_LENGTH) {
            return null;
        }

        $code = strtoupper($submitted);

        // The last nine characters are XXXX-XXXX, the one before is the
        // separator, the remainder is the tenant part.
        if (strlen($code) < 11) {
            return null;
        }

        $secret = substr($code, -9);
        $separator = substr($code, -10, 1);
        $tenantPart = substr($code, 0, -10);

        if ($separator !== '-'
            || preg_match(self::SECRET_PATTERN, $secret) !== 1
            || preg_match(self::TENANT_PART_PATTERN, $tenantPart) !== 1) {
            return null;
        }

        return ['tenantPart' => $tenantPart, 'secret' => $secret];
    }

    /**
     * The stored form of a secret: SHA-256, hex, of the eight characters
     * without their hyphen.
     */
    public static function hashOf(string $secret): string
    {
        return hash('sha256', str_replace('-', '', $secret));
    }

    /**
     * The printed code for a tenant part and an `XXXX-XXXX` secret.
     *
     * @throws InvalidArgumentException when either part is not shaped like one
     */
    public static function format(string $tenantPart, string $secret): string
    {
        if (preg_match(self::TENANT_PART_PATTERN, $tenantPart) !== 1 || preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            throw new InvalidArgumentException('An activation code needs a conforming tenant part and secret.');
        }

        return $tenantPart.'-'.$secret;
    }
}
