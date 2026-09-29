<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * The caller passed something that cannot be a tenant identifier (AC-11).
 *
 * Raised before the mapping source is consulted, so an empty identifier never
 * reaches a lookup, let alone a connection attempt.
 */
final class InvalidTenantIdentifier extends TenancyException
{
    public static function emptyIdentifier(): self
    {
        return new self(
            'A tenant identifier must be a non-empty string; an empty or whitespace-only value was given. '.
            'The mapping source was not consulted.'
        );
    }
}
