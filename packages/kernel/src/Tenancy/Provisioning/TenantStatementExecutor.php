<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

/**
 * The seam between {@see MySqlTenantDatabaseProvisioner}'s guarded, built
 * statements and whatever actually runs them (T-026 Ruling 4).
 *
 * Production binds {@see PdoTenantStatementExecutor}, which opens a raw PDO
 * so no `QueryExecuted` listener ever sees a password-bearing statement. A
 * test binds a recording double instead, proving the guard and the exact
 * SQL with no socket ever opened - the "recording statement-runner seam"
 * the plan names for step 4.
 */
interface TenantStatementExecutor
{
    public function exec(string $sql): void;

    public function execSecret(SecretStatement $statement): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function query(string $sql): array;
}
