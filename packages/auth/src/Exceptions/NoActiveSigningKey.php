<?php

declare(strict_types=1);

namespace Flexipos\Auth\Exceptions;

use RuntimeException;

/**
 * No key is in a state to sign, so nothing is issued.
 *
 * Thrown in exactly one place, `SigningKeyring::signingKey()`, which is the
 * only code that asks which key signs. That is the criterion's own site: AC-31
 * is about the moment the issuer looks for an `active` key and finds none, and
 * naming the exception for anywhere else would put a refusal somewhere the
 * rule is not read.
 *
 * **This is an operational failure, not a credential one** (AC-31). The caller
 * renders a 5xx-class refusal, no refresh-token row exists to clean up because
 * the key is resolved before anything is written, and the attempt is not
 * counted toward a lockout: the person supplied the right password to a
 * platform that could not sign.
 *
 * It also covers a key whose row names an algorithm this code does not sign
 * with. The algorithm is ES256 and is named by the code (Ruling 1) precisely
 * so that it cannot be chosen by data; a row disagreeing with it is a
 * misconfiguration, and signing anyway - with the algorithm the *row* asked
 * for - is how alg-confusion gets in through the back door.
 */
final class NoActiveSigningKey extends RuntimeException
{
    public static function forSigning(): self
    {
        return new self('no signing key is active; no token can be issued');
    }

    public static function wrongAlgorithm(string $kid, string $declared, string $expected): self
    {
        return new self(sprintf(
            'signing key %s declares algorithm %s; this platform signs only %s',
            $kid,
            $declared,
            $expected
        ));
    }
}
