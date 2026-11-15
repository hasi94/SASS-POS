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
 *
 * Not final, and extended exactly once: {@see TenantCredentialRejected} is the
 * one failure a re-read of the mapping can fix (AC-16). `new static` rather
 * than `new self` so the subclass keeps this message and does not restate it.
 */
class TenantConnectionFailed extends TenancyException
{
    /**
     * Final, so `new static()` below is safe: no subclass can change what
     * constructing one of these needs, and the message stays this class's.
     */
    final public function __construct(string $message)
    {
        parent::__construct($message, 0, previous: null);
    }

    public static function forTenant(string $tenantId, string $driver, string $sanitisedReason): static
    {
        return new static(sprintf(
            'Could not open the database for tenant [%s] using driver [%s]: %s',
            $tenantId,
            $driver,
            $sanitisedReason
        ));
    }
}
