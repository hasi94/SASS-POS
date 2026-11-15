<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Closure;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Provisioning\Contracts\TenantDatabaseProvisioner;
use Flexipos\Kernel\Tenancy\Provisioning\Exceptions\TenantNameRejected;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The real MySQL/MariaDB implementation (T-026 AC-15, AC-16, Ruling 4).
 *
 * Bound only when `tenancy.provisioning.driver=mysql` is set explicitly -
 * never by the test suite - so this class is never reached under
 * ENGINE_HOLD. **Every identifier passes a two-check guard at the point of
 * effect**, the same shape `Flexipos\Testing\Engine\EngineDatabase` uses
 * after the incident that shape exists to prevent:
 * {@see TenantDatabaseNames}'s own rule, plus this class's independent
 * `flx_t_`/`flx_t` literal. One class being wrong must not be enough to
 * reach a server.
 *
 * Statement execution is behind {@see TenantStatementExecutor}, a seam a
 * test can double with no socket opened at all, which is how
 * `ProvisionerBindingTest`/`MySqlTenantDatabaseProvisionerTest` prove the
 * guard and the exact SQL today, with AC-16 itself staying NOT VERIFIED
 * until G-1.
 */
final class MySqlTenantDatabaseProvisioner implements TenantDatabaseProvisioner
{
    public function __construct(
        private readonly SecretResolver $secrets,
        private readonly ConfigRepository $config,
        private readonly ?Closure $executorFactory = null,
    ) {}

    public function databaseState(ProvisioningServer $server, string $name): DatabaseState
    {
        $this->assertDatabase($name);

        $schemata = $this->executor($server)->query(
            "select schema_name from information_schema.schemata where schema_name = '".addslashes($name)."'"
        );

        if ($schemata === []) {
            return DatabaseState::Absent;
        }

        $tables = $this->executor($server)->query(
            "select table_name from information_schema.tables where table_schema = '".addslashes($name)."' limit 1"
        );

        return $tables === [] ? DatabaseState::Empty : DatabaseState::Occupied;
    }

    public function createDatabase(ProvisioningServer $server, string $name, string $charset, string $collation): void
    {
        $this->assertDatabase($name);

        $this->executor($server)->exec(ProvisionerStatements::createDatabase($name, $charset, $collation));
    }

    public function userExists(ProvisioningServer $server, string $user): bool
    {
        $this->assertUser($user);

        $rows = $this->executor($server)->query(
            "select User from mysql.user where User = '".addslashes($user)."'"
        );

        return $rows !== [];
    }

    public function createUser(
        ProvisioningServer $server,
        string $user,
        Secret $password,
        TenantGrant $grant,
        string $database,
    ): void {
        $this->assertUser($user);
        $this->assertDatabase($database);

        $host = (string) $this->config->get('tenancy.provisioning.user_host', '%');
        $executor = $this->executor($server);

        $executor->execSecret(ProvisionerStatements::createUserIfNotExists($user, $host, $password));
        $executor->execSecret(ProvisionerStatements::alterUser($user, $host, $password));
        $executor->exec(ProvisionerStatements::grant($user, $host, $database, $grant));
    }

    public function dropUser(ProvisioningServer $server, string $user): void
    {
        $this->assertUser($user);

        $host = (string) $this->config->get('tenancy.provisioning.user_host', '%');

        $this->executor($server)->exec(ProvisionerStatements::dropUser($user, $host));
    }

    /**
     * @throws TenantNameRejected
     */
    private function assertDatabase(string $name): void
    {
        TenantDatabaseNames::assertDatabaseName($name);

        if (! str_starts_with($name, TenantDatabaseNames::DATABASE_PREFIX)) {
            throw TenantNameRejected::because(
                $name,
                'it passed the name guard but does not start with "'.TenantDatabaseNames::DATABASE_PREFIX
                .'". The two checks disagree, so the guard itself is wrong and nothing is created.'
            );
        }
    }

    /**
     * @throws TenantNameRejected
     */
    private function assertUser(string $user): void
    {
        TenantDatabaseNames::assertUsername($user);

        if (! str_starts_with($user, TenantDatabaseNames::USER_PREFIX)) {
            throw TenantNameRejected::because(
                $user,
                'it passed the name guard but does not start with "'.TenantDatabaseNames::USER_PREFIX
                .'". The two checks disagree, so the guard itself is wrong and nothing is created.'
            );
        }
    }

    private function executor(ProvisioningServer $server): TenantStatementExecutor
    {
        if ($this->executorFactory instanceof Closure) {
            return ($this->executorFactory)($server);
        }

        return new PdoTenantStatementExecutor(
            $server,
            $this->secrets->resolve($server->provisionerCredentialRef),
            (string) $this->config->get('tenancy.provisioning.admin_username', 'root'),
        );
    }
}
