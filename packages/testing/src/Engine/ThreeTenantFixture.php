<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * The three tenants every runner criterion is measured against (T-008 step 8).
 *
 * Tenant **A** is an empty database at schema version 0, **B** has the baseline
 * applied, and **C** has the baseline applied and is the tenant FX-2 is told to
 * fail on. One database can never satisfy a criterion about a *run*: "the
 * others were migrated anyway" (AC-44), "the plan was written before any tenant
 * was touched" (AC-39) and "two tenants on one server are capped" (AC-50) are
 * all statements about a set.
 *
 * It is in step 8 rather than step 0 because it cannot exist earlier: it needs
 * the `tenant_databases` and `database_servers` mappings of step 6 and the
 * migration-set seam of step 8.
 *
 * **Two halves, and the split is deliberate.**
 *
 * {@see mapInto()} writes the master rows and touches no engine at all - the
 * suite's master is in-memory SQLite - so the mapping half is exercised while
 * `ENGINE_HOLD` is set. {@see build()} and {@see drop()} are the engine half:
 * they create and drop databases, so they assert the hold first and every
 * statement goes through {@see EngineDatabase}, by name, one at a time. There
 * is no sweep here and there must never be one; a leftover is made harmless by
 * recreating the one name, which is what `EngineDatabase::recreate()` is for.
 */
final class ThreeTenantFixture
{
    /**
     * The three tenants, as tenant id => the suffix of their database name.
     *
     * The ids are ULID-shaped because `tenants.id` is `CHAR(26)` `ascii_bin`,
     * and they are fixed rather than generated so a failing run names the same
     * tenant twice running.
     */
    public const TENANTS = [
        '01J0000000000000000000000A' => 'tenant_a',
        '01J0000000000000000000000B' => 'tenant_b',
        '01J0000000000000000000000C' => 'tenant_c',
    ];

    /**
     * The tenant the baseline is **not** applied to.
     */
    public const EMPTY_TENANT = '01J0000000000000000000000A';

    /**
     * The tenant FX-2 is told to fail on.
     */
    public const FAILING_TENANT = '01J0000000000000000000000C';

    /**
     * @param  string  $baselineDirectory  absolute path of the tenant baseline
     *                                     tree, read by the caller from
     *                                     config('schema.tenant_migration_path')
     *                                     - the one authority for it
     */
    public function __construct(private readonly string $baselineDirectory) {}

    /**
     * The reserved database name for one tenant.
     *
     * Built from {@see ReservedDatabaseName::PREFIX} rather than from a literal
     * of its own: the prefix has exactly two second copies in this repository,
     * both of them deliberate second *rules* at a point of effect, and this is
     * neither.
     */
    public function databaseNameFor(string $tenantId): string
    {
        if (! array_key_exists($tenantId, self::TENANTS)) {
            throw ReservedDatabaseNameViolation::because(
                $tenantId,
                'it is not one of the three tenants this fixture owns'
            );
        }

        return ReservedDatabaseName::PREFIX.self::TENANTS[$tenantId];
    }

    /**
     * Every database name this fixture owns, in tenant order.
     *
     * @return list<string>
     */
    public function databaseNames(): array
    {
        return array_map(fn (string $tenantId): string => $this->databaseNameFor($tenantId), array_keys(self::TENANTS));
    }

    /**
     * The master rows this fixture stands for, without writing them.
     *
     * Returned rather than only inserted so a test can assert what the mapping
     * *says* - tenant A at version 0 and the other two at the baseline's
     * highest version is the input AC-40's selection needs - without a master
     * schema in the way.
     *
     * @return array{tenants: list<array<string, mixed>>, servers: list<array<string, mixed>>, databases: list<array<string, mixed>>}
     */
    public function mappingRows(int $baselineVersion): array
    {
        $now = '2026-10-15 00:00:00.000';
        $serverId = '01J00000000000000000SERVER';

        $tenants = [];
        $databases = [];
        $sequence = 0;

        foreach (self::TENANTS as $tenantId => $suffix) {
            $sequence++;

            $tenants[] = [
                'id' => $tenantId,
                'sequence' => $sequence,
                'code' => 'FIXTURE'.$sequence,
                'slug' => str_replace('_', '-', $suffix),
                'name' => 'Fixture '.$suffix,
                'status' => 'active',
                'country' => 'LK',
                'timezone' => 'Asia/Colombo',
                'currency' => 'LKR',
                'business_type' => 'retail',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $databases[] = [
                'tenant_id' => $tenantId,
                'server_id' => $serverId,
                'database_identifier' => $suffix,
                'database_name' => $this->databaseNameFor($tenantId),
                'app_username' => 'flx_app_'.$suffix,
                'app_credential_ref' => 'env://ENGINE_PASSWORD',
                'migrate_username' => 'flx_mig_'.$suffix,
                'migrate_credential_ref' => 'env://ENGINE_PASSWORD',
                'credential_version' => 1,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'status' => 'active',
                // Tenant A holds nothing, which is what makes it the tenant a
                // run has something to do to.
                'schema_version' => $tenantId === self::EMPTY_TENANT ? 0 : $baselineVersion,
                'profile' => 'standard',
                // Ascending, and distinct, because AC-40 orders jobs by it.
                'size_bytes' => $sequence * 1024,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [
            'tenants' => $tenants,
            'servers' => [[
                'id' => $serverId,
                'code' => 'fixture-engine',
                'name' => 'The engine the fixture databases live on',
                'host' => (string) EngineConnection::stanza()['host'],
                'port' => (int) EngineConnection::stanza()['port'],
                'engine' => EngineConnection::driver(),
                'tls_mode' => 'disabled',
                'provisioner_credential_ref' => 'env://ENGINE_PASSWORD',
                'tier' => 'standard',
                'max_tenants' => 10,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            'databases' => $databases,
        ];
    }

    /**
     * Write the mapping into a master connection.
     *
     * In tenant → server → mapping order, because `tenant_databases` keys onto
     * both. No engine is involved: the master is whatever connection the caller
     * hands in, which under the suite is in-memory SQLite.
     */
    public function mapInto(ConnectionInterface $master, int $baselineVersion): void
    {
        $rows = $this->mappingRows($baselineVersion);

        $master->table('tenants')->insert($rows['tenants']);
        $master->table('database_servers')->insert($rows['servers']);
        $master->table('tenant_databases')->insert($rows['databases']);
    }

    /**
     * Create the three databases and apply the baseline to B and C.
     *
     * Every name is recreated rather than created, so a database left behind by
     * a killed run is replaced instead of colliding - and `recreate()` drops
     * *that one name*, which is the only form of cleanup this repository has.
     */
    public function build(): void
    {
        EngineConnection::assertNotOnHold();

        $engine = EngineDatabase::engine();

        foreach ($this->databaseNames() as $name) {
            $engine->recreate($name);
        }

        foreach (array_keys(self::TENANTS) as $tenantId) {
            if ($tenantId === self::EMPTY_TENANT) {
                continue;
            }

            $this->applyBaselineTo($this->databaseNameFor($tenantId));
        }
    }

    /**
     * Drop the three databases, by name, one at a time.
     */
    public function drop(): void
    {
        EngineConnection::assertNotOnHold();

        $engine = EngineDatabase::engine();

        foreach ($this->databaseNames() as $name) {
            $engine->drop($name);
            EngineConnection::forget($name);
        }
    }

    /**
     * Apply the baseline tree to one reserved database, through the framework's
     * migrator and the framework's connector.
     *
     * `--realpath`, because the directory is absolute: the caller read it from
     * configuration and this class never rebuilds it. Not `--pretend`, not a
     * hand-built PDO - the connector is what sets strict mode, and without it a
     * refusal criterion passes as a silent truncation (AC-68).
     */
    private function applyBaselineTo(string $database): void
    {
        $connection = EngineConnection::connection($database);

        $exit = Artisan::call('migrate', [
            '--database' => $connection->getName(),
            '--path' => $this->baselineDirectory,
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw EngineUnreachable::at(
                EngineConnection::driver(),
                (string) EngineConnection::stanza()['host'],
                (string) EngineConnection::stanza()['port'],
                $database,
                new RuntimeException('the baseline migration exited '.$exit.': '.Artisan::output())
            );
        }
    }
}
