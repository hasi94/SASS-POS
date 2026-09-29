<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * The scheme is supported but the secret behind the reference could not be
 * obtained.
 *
 * Carries the reference and the reason, never the value - including when the
 * reason is "it was empty", which must not be reported by echoing what was
 * found.
 */
final class CredentialNotAvailable extends TenancyException
{
    public static function forReference(string $reference, string $reason): self
    {
        return new self(sprintf(
            'The credential behind reference [%s] is not available: %s',
            $reference,
            $reason
        ));
    }
}
