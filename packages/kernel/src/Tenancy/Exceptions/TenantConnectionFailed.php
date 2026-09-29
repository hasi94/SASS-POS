<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * A driver-level failure while opening the tenant database, sanitised (AC-12,
 * AC-14).
 *
 * Deliberately constructed with `previous: null`. The driver exception's own
 * trace carries an Illuminate\Database\Connectors\Connector::createConnection(
 * $dsn, array $config, $options) frame whose $config array holds the plaintext
 * tenant password, and Symfony's FlattenException::flattenArgs() copies string
 * argument values verbatim into a flattened trace. Laravel's own debug page
 * prints argument types only, but Symfony's fallback HtmlErrorRenderer prints
 * var_export() of values - so the credential is kept out of the trace rather
 * than the renderer being trusted to hide it.
 *
 * The reason string is the driver's message with every occurrence of the
 * credential replaced, not the driver exception itself, so ops still gets a
 * diagnosable message.
 */
final class TenantConnectionFailed extends TenancyException
{
    public static function forTenant(string $tenantId, string $driver, string $sanitisedReason): self
    {
        return new self(
            sprintf(
                'Could not open the database for tenant [%s] using driver [%s]: %s',
                $tenantId,
                $driver,
                $sanitisedReason
            ),
            previous: null
        );
    }
}
