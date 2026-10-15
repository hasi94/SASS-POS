<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * The tenant's schema is below the version this code requires, so the tenant is
 * not served (AC-19).
 *
 * Raised **before any network connection is attempted**: the gate sits between
 * the status check and the credential dereference in Tenancy::enter(), so a
 * behind tenant costs no connection and the refusal can never be confused with
 * the database being unreachable.
 *
 * It carries the two version numbers and the tenant id, and no credential -
 * the gate runs before the credential reference is dereferenced, so there is
 * nothing to redact.
 *
 * The host renders it as HTTP 503 with a Retry-After header, because it is a
 * state that passes: the migration runner is what clears it.
 *
 * **Serving only.** Entering the same tenant for the migration purpose is not
 * refused by this gate - a tenant below the required version is the only kind
 * the runner migrates (plan section 9, finding 2).
 */
final class TenantSchemaBehind extends TenancyException
{
    /**
     * What an unauthenticated caller is told, which is everything it can act
     * on and nothing more (R-W2).
     *
     * `getMessage()` names the tenant and both version numbers. That belongs
     * in the log, where an operator reads it, and not in a 503 body, which
     * anyone who can reach the deployment receives. The sentence lives here
     * rather than in the host's renderer so that the public half and the
     * operator half of this refusal are written side by side.
     */
    public const PUBLIC_MESSAGE = 'This shop is temporarily unavailable while its database is brought up to date. Please try again shortly.';

    public static function forTenant(string $tenantId, int $schemaVersion, int $requiredVersion): self
    {
        return new self(sprintf(
            'Tenant [%s] cannot be served: its schema version is %d and this code requires %d.'
            .' The migration runner is what clears this.',
            $tenantId,
            $schemaVersion,
            $requiredVersion
        ));
    }
}
