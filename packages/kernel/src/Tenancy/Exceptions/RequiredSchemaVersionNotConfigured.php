<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * Nobody configured the serving schema-version floor, so the tenant is not
 * served (T-008 step 7a, risk R-14, ruling 13.1).
 *
 * This is a **misconfiguration**, not a tenant state. The floor has three
 * states: a number is the floor, an explicit `0` is the recorded "no floor;
 * `tenant_databases.status` is the only guard" opt-out, and absence is this.
 * Before step 7a absence and zero were the same fact in code, so a deployment
 * that forgot the variable served a tenant whose mapping said schema version 0
 * - a database that may hold nothing but `migrations` - against
 * `docs/domain-core.md`'s rule that a half-migrated tenant never serves.
 *
 * Raised **before any network connection is attempted**, at the same point as
 * TenantSchemaBehind: between the status check and the credential dereference
 * in Tenancy::enter(). It carries the tenant id and the name of the variable an
 * operator has to set, and no credential - there is nothing dereferenced yet to
 * redact.
 *
 * The host renders it as HTTP 503 **without** `Retry-After`. A behind tenant's
 * 503 promises that waiting helps; this one does not, and a till that retried
 * forever against a missing environment variable would be a second incident.
 * That is the difference a monitor can see at the wire rather than only in a
 * log.
 *
 * **Serving only.** The migrating purpose is exempt, for the reason the refusal
 * sits at the gate instead of at boot: a gate that disabled
 * `php artisan tenant:migrate` would disable the remedy for the very condition
 * it is reporting, which is fail-stuck rather than fail-closed.
 */
final class RequiredSchemaVersionNotConfigured extends TenancyException
{
    /**
     * The environment variable an operator has to set, named in the message
     * because the message is the whole of what an operator gets - on the log
     * line, which since R-W2 is the only place it is written.
     */
    public const VARIABLE = 'APP_REQUIRED_SCHEMA_VERSION';

    /**
     * What an unauthenticated caller is told (R-W2).
     *
     * `getMessage()` names the tenant **and the configuration key that fixes
     * the deployment**. Handing both to anyone who can reach a misconfigured
     * installation is the leak; the status code and the deliberate absence of
     * `Retry-After` are not. So the message goes to the log an operator
     * reads, and the wire gets a sentence that says only "not us to fix, and
     * waiting will not help".
     */
    public const PUBLIC_MESSAGE = 'This shop is not available. The deployment is misconfigured and an operator has to act; retrying will not help.';

    public static function forTenant(string $tenantId): self
    {
        return new self(sprintf(
            'Tenant [%s] cannot be served: no serving schema version floor is configured.'
            .' Set %s - the catalogue\'s highest version is what a deployment ships -'
            .' or set it to 0 to record that this installation wants no floor.',
            $tenantId,
            self::VARIABLE
        ));
    }
}
