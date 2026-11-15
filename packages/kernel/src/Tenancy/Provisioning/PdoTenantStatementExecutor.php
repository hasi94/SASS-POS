<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Tenancy\Provisioning\Contracts\ProvisioningServer;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use PDO;

/**
 * The real, raw-PDO executor (T-026 AC-16, AC-18, Ruling 4).
 *
 * **NOT VERIFIED under ENGINE_HOLD** - nothing in this suite ever
 * constructs this class, because {@see MySqlTenantDatabaseProvisioner} is
 * never bound in a test (`tenancy.provisioning.driver` stays unset). It
 * exists so the real implementation is *built*, per T-008/T-009/T-023
 * precedent, even while it cannot yet be exercised against a live server.
 *
 * Raw PDO, never `Illuminate\Database\Connection`: a `QueryExecuted`
 * listener this project does not control must never see a password-bearing
 * statement. Where the driver allows it, a statement is prepared with a
 * bound value rather than interpolated - {@see SecretStatement::sql()} still
 * builds the literal form for the one engine operation (`CREATE USER`/
 * `ALTER USER … IDENTIFIED BY`) that has no bindable parameter position on
 * MySQL/MariaDB; this is restated here as the design note AC-18 asks for.
 */
final class PdoTenantStatementExecutor implements TenantStatementExecutor
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly ProvisioningServer $server,
        private readonly Secret $credential,
        private readonly string $adminUsername,
    ) {}

    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    public function execSecret(SecretStatement $statement): void
    {
        // CREATE USER/ALTER USER … IDENTIFIED BY has no bindable parameter
        // position on MySQL/MariaDB, so the literal form is built once,
        // here, immediately before execution - never logged, never passed
        // through anything that could retain it.
        $this->pdo()->exec($statement->sql());
    }

    public function query(string $sql): array
    {
        $statement = $this->pdo()->query($sql);

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->server->host, $this->server->port);

        $this->pdo = new PDO($dsn, $this->adminUsername, $this->credential->reveal(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return $this->pdo;
    }
}
