<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * No secret driver is registered for this credential reference's scheme.
 *
 * T-003 implements `env://` only (decision D-31). `vault://`, `awssm://`,
 * `azkv://`, `file://` and `enc://` are documented in docs/database.md section
 * 4.4 and land with whichever task first needs a real secret store; until then
 * they are refused rather than silently falling back to anything.
 *
 * The reference is in the message - it names a location, never a value.
 */
final class UnsupportedCredentialReference extends TenancyException
{
    public static function forScheme(string $reference, string $scheme): self
    {
        return new self(sprintf(
            'No secret resolver is registered for the [%s] scheme of credential reference [%s].',
            $scheme,
            $reference
        ));
    }

    public static function withoutScheme(string $reference): self
    {
        return new self(sprintf(
            'Credential reference [%s] has no scheme; a reference must look like scheme://rest, for example '.
            'env://TENANT_000123_APP_PASSWORD.',
            $reference
        ));
    }
}
