<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * A `put()` found a different value already stored at this reference (T-026
 * AC-17 negative, Ruling 3).
 *
 * `put()` is idempotent for an equal value - a resumed `store_credentials`
 * must find its own write and advance - but a *different* value is never
 * silently overwritten: that would be a rotation nobody asked for, and
 * `store_credentials` never means to rotate.
 */
final class SecretWriteRefused extends TenancyException
{
    public static function forReference(string $reference): self
    {
        return new self(sprintf(
            'Credential reference [%s] already holds a different value; put() never rotates a credential.',
            $reference
        ));
    }
}
