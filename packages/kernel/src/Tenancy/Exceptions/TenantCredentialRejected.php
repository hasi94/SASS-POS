<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

use Throwable;

/**
 * The tenant database refused the credential we connected with (AC-16).
 *
 * A `TenantConnectionFailed`, and deliberately a subclass of it rather than a
 * sibling: every caller that already handles a failed tenant connection keeps
 * working, and the criterion's "a `TenantConnectionFailed` surfaces after
 * exactly two attempts" is satisfied by the thing that surfaces.
 *
 * **Why the distinction exists at all.** It is the one connection failure that
 * a *re-read of the mapping* can fix: the master was rotated, the cached entry
 * still names the old reference, and the server is right to refuse us. Every
 * other failure - an unreachable host, a missing database, a refused driver -
 * is unchanged by reading the mapping again, and retrying it would double the
 * cost of an outage for nothing.
 *
 * The recognition lives here, beside the name, because an exception named for
 * its throw site and recognised somewhere else is two definitions of the same
 * thing.
 *
 * **Final** (R-W3). The base is open by exactly one subclass and this is it;
 * there is no third reading of a rejected credential, and a subclass of this
 * one would inherit a `describes()` that answers for a name it no longer
 * carries. The count itself is enforced in
 * packages/kernel/tests/Unit/TenantConnectionFailedFamilyTest.php, because a
 * second class extending the base directly is the growth this keyword cannot
 * see.
 */
final class TenantCredentialRejected extends TenantConnectionFailed
{
    /**
     * The SQLSTATE class every supported server uses for "the credential was
     * rejected": 28000 is the standard's invalid-authorization-specification,
     * and MySQL and MariaDB both raise it with driver error 1045.
     *
     * @var list<string>
     */
    private const SQL_STATES = ['28000', '28P01'];

    /**
     * Driver error numbers, for the drivers that report the number and leave
     * SQLSTATE at the generic value.
     *
     * @var list<int>
     */
    private const DRIVER_ERRORS = [1045, 1044, 1698];

    /**
     * Whether this driver-level failure is the server refusing the credential.
     *
     * Three readings, because PDO is not consistent about where the fact is:
     * the exception code (which is the SQLSTATE for PDOException), the driver
     * error number in `errorInfo`, and the message text that both MySQL and
     * MariaDB write. The text is read last and matched on the fixed part of
     * the server's own wording.
     */
    public static function describes(Throwable $exception): bool
    {
        if (in_array((string) $exception->getCode(), self::SQL_STATES, true)) {
            return true;
        }

        /** @var array<int, mixed> $info */
        $info = property_exists($exception, 'errorInfo') && is_array($exception->errorInfo)
            ? $exception->errorInfo
            : [];

        if (isset($info[0]) && in_array((string) $info[0], self::SQL_STATES, true)) {
            return true;
        }

        if (isset($info[1]) && is_numeric($info[1]) && in_array((int) $info[1], self::DRIVER_ERRORS, true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        foreach (self::SQL_STATES as $state) {
            if (str_contains($message, 'sqlstate['.strtolower($state).']')) {
                return true;
            }
        }

        return str_contains($message, 'access denied for user');
    }
}
