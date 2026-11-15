<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Tenancy\Secrets\Secret;

/**
 * A pure builder for the exact SQL `create_database`/`create_users` issue
 * (T-026 AC-16, Ruling 4) - no connection, no side effect.
 *
 * The grant lists are exactly docs/database.md section 2's:
 * `app` is `SELECT, INSERT, UPDATE, DELETE, EXECUTE`; `migrate` is the same
 * plus `CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, TRIGGER`.
 *
 * This class, {@see SecretStatement} and
 * {@see MySqlTenantDatabaseProvisioner} are the only places in the
 * repository allowed to spell `create database`, `create user`,
 * `alter user`, `grant … on` or `drop user` (check 3,
 * `DestructiveStatementContainmentTest`).
 */
final class ProvisionerStatements
{
    public static function createDatabase(string $name, string $charset, string $collation): string
    {
        return 'create database `'.$name.'` character set `'.$charset.'` collate `'.$collation.'`';
    }

    public static function createUserIfNotExists(string $user, string $host, Secret $password): SecretStatement
    {
        return new SecretStatement(
            'create user if not exists `'.$user.'`@\''.$host.'\' identified by '.SecretStatement::PLACEHOLDER,
            $password
        );
    }

    public static function alterUser(string $user, string $host, Secret $password): SecretStatement
    {
        return new SecretStatement(
            'alter user `'.$user.'`@\''.$host.'\' identified by '.SecretStatement::PLACEHOLDER,
            $password
        );
    }

    public static function grant(string $user, string $host, string $database, TenantGrant $grant): string
    {
        return 'grant '.self::privileges($grant).' on `'.$database.'`.* to `'.$user.'`@\''.$host.'\'';
    }

    public static function dropUser(string $user, string $host): string
    {
        return 'drop user if exists `'.$user.'`@\''.$host.'\'';
    }

    public static function privileges(TenantGrant $grant): string
    {
        return match ($grant) {
            TenantGrant::App => 'SELECT, INSERT, UPDATE, DELETE, EXECUTE',
            TenantGrant::Migrate => 'SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE, ALTER, DROP, INDEX, '
                .'REFERENCES, CREATE VIEW, TRIGGER',
        };
    }
}
