<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Secrets\Secret;

/**
 * The suite stand-in (T-026 Ruling 4): records each call, with the password
 * replaced by a marker, and opens no socket.
 *
 * For a `sqlite`-engine server it creates an empty SQLite file named exactly
 * `database_name` in the process's current working directory, so a slice
 * test that migrates the "created" tenant database can open a real file
 * (the step 4 probe this plan calls for).
 *
 * The same guard the real implementation runs - {@see TenantDatabaseNames} -
 * still runs here first, so a bad identifier is refused by the stand-in
 * exactly as it would be refused for real, and a test proving the guard
 * does not need the real driver bound at all.
 */
final class RecordingTenantDatabaseProvisioner implements TenantDatabaseProvisioner
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var array<string, DatabaseState> */
    private array $databases = [];

    /** @var array<string, true> */
    private array $users = [];

    public function databaseState(ProvisioningServer $server, string $name): DatabaseState
    {
        TenantDatabaseNames::assertDatabaseName($name);

        $this->record('databaseState', ['server' => $server->code, 'name' => $name]);

        if (array_key_exists($name, $this->databases)) {
            return $this->databases[$name];
        }

        if ($server->engine === 'sqlite' && is_file($name)) {
            return DatabaseState::Empty;
        }

        return DatabaseState::Absent;
    }

    public function createDatabase(ProvisioningServer $server, string $name, string $charset, string $collation): void
    {
        TenantDatabaseNames::assertDatabaseName($name);

        $this->record('createDatabase', [
            'server' => $server->code,
            'name' => $name,
            'charset' => $charset,
            'collation' => $collation,
        ]);

        $this->databases[$name] = DatabaseState::Empty;

        if ($server->engine === 'sqlite') {
            touch($name);
        }
    }

    public function userExists(ProvisioningServer $server, string $user): bool
    {
        TenantDatabaseNames::assertUsername($user);

        $this->record('userExists', ['server' => $server->code, 'user' => $user]);

        return array_key_exists($user, $this->users);
    }

    public function createUser(
        ProvisioningServer $server,
        string $user,
        Secret $password,
        TenantGrant $grant,
        string $database,
    ): void {
        TenantDatabaseNames::assertUsername($user);
        TenantDatabaseNames::assertDatabaseName($database);

        $this->record('createUser', [
            'server' => $server->code,
            'user' => $user,
            'password' => '[recorded]',
            'password_hash' => hash('sha256', $password->reveal()),
            'grant' => $grant->value,
            'database' => $database,
        ]);

        $this->users[$user] = true;
    }

    public function dropUser(ProvisioningServer $server, string $user): void
    {
        TenantDatabaseNames::assertUsername($user);

        $this->record('dropUser', ['server' => $server->code, 'user' => $user]);

        unset($this->users[$user]);
    }

    /**
     * Marks a database as already present before this run, so a test can
     * exercise the "foreign occupant" refusal (AC-5 negative) without a
     * prior createDatabase() call.
     */
    public function seedExistingDatabase(string $name, DatabaseState $state): void
    {
        $this->databases[$name] = $state;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = array_merge(['method' => $method], $args);
    }
}
