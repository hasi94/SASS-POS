<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\TenantNameRejected;

/**
 * The mechanical naming rule from a tenant's `sequence` alone (T-026 AC-12).
 *
 * Computed once, at `reserve_identity`, and never recomputed from a
 * later-read `sequence` - every method here is a pure function of the
 * sequence, so a retried step reproduces the exact same names.
 *
 * `database_name` widens past six digits once the sequence reaches
 * 1,000,000 (docs/database.md section 2); the usernames widen with it, and
 * every produced name still fits a MySQL/MariaDB 32-character identifier
 * limit at any sequence this design can reach before the digits alone
 * would not.
 *
 * `assertDatabaseName()`/`assertUsername()` are the second half of
 * Ruling 4's two-check guard: {@see MySqlTenantDatabaseProvisioner} calls
 * these immediately before issuing a statement, and
 * {@see ProvisionerStatements} adds its own independent `flx_t_`/`flx_t`
 * literal check beside it - the same "two independent checks at the point
 * of effect" shape `Flexipos\Testing\Engine\EngineDatabase` already uses,
 * after the incident that shape exists to prevent.
 */
final class TenantDatabaseNames
{
    public const DATABASE_PREFIX = 'flx_t_';

    public const USER_PREFIX = 'flx_t';

    public static function databaseName(int $sequence): string
    {
        return self::DATABASE_PREFIX.self::digits($sequence);
    }

    public static function appUsername(int $sequence): string
    {
        return self::USER_PREFIX.self::digits($sequence).'_app';
    }

    public static function migrateUsername(int $sequence): string
    {
        return self::USER_PREFIX.self::digits($sequence).'_mig';
    }

    /**
     * The `file://` credential reference `store_credentials` writes to and
     * `create_users` reads back (T-026 Ruling 3): `file://<root>/tenant_
     * <sequence, 6+ digits>_app`. A pure function of `sequence` and the
     * configured root alone, so it is the same value however many times a
     * resumed run recomputes it - nothing about it is ever journalled as a
     * separate fact.
     */
    public static function appCredentialRef(int $sequence, string $root): string
    {
        return 'file://'.rtrim($root, '/\\').'/tenant_'.self::digits($sequence).'_app';
    }

    public static function migrateCredentialRef(int $sequence, string $root): string
    {
        return 'file://'.rtrim($root, '/\\').'/tenant_'.self::digits($sequence).'_mig';
    }

    /**
     * @throws TenantNameRejected
     */
    public static function assertDatabaseName(string $name): void
    {
        self::assertShape($name);

        if (preg_match('/^'.self::DATABASE_PREFIX.'[0-9]{6,}$/', $name) !== 1) {
            throw TenantNameRejected::because(
                $name,
                'a database name must be "'.self::DATABASE_PREFIX.'" followed by at least six digits.'
            );
        }

        if (strlen($name) > 32) {
            throw TenantNameRejected::because($name, 'it is longer than the 32-character identifier limit.');
        }
    }

    /**
     * @throws TenantNameRejected
     */
    public static function assertUsername(string $name): void
    {
        self::assertShape($name);

        if (preg_match('/^'.self::USER_PREFIX.'[0-9]{6,}_(app|mig)$/', $name) !== 1) {
            throw TenantNameRejected::because(
                $name,
                'a username must be "'.self::USER_PREFIX.'" followed by at least six digits and "_app" or "_mig".'
            );
        }

        if (strlen($name) > 32) {
            throw TenantNameRejected::because($name, 'it is longer than the 32-character identifier limit.');
        }
    }

    /**
     * The checks every identifier shares, independent of which one it is:
     * no quote, no backtick, and never anything but lower case.
     *
     * @throws TenantNameRejected
     */
    private static function assertShape(string $name): void
    {
        if ($name === '') {
            throw TenantNameRejected::because($name, 'it is blank.');
        }

        if (str_contains($name, '`') || str_contains($name, "'") || str_contains($name, '"')) {
            throw TenantNameRejected::because($name, 'it contains a quote or a backtick.');
        }

        if ($name !== strtolower($name)) {
            throw TenantNameRejected::because($name, 'it is not all lower case.');
        }
    }

    /**
     * Zero-padded to six digits; widens past 1,000,000 rather than
     * truncating.
     */
    private static function digits(int $sequence): string
    {
        return $sequence < 1_000_000 ? sprintf('%06d', $sequence) : (string) $sequence;
    }
}
