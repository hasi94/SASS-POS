<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\DetectSchemaDrift;

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrationErrorText;
use Flexipos\Kernel\Schema\Support\TenantSchemaVersion;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Compare every mapping with the database it points at (AC-55).
 *
 * **It writes nothing.** Not the mapping, not the job table, not a status.
 * That is what makes it safe to run against a live platform, and it is why
 * the repair is a different slice: a report that quietly corrected what it
 * found would be a migration nobody asked for.
 *
 * The tenant is entered for the **migrating** purpose, because the serving
 * purpose is gated on the schema version and the whole point of this query is
 * the tenants whose version is wrong - a drift check that could not open a
 * drifted tenant would report nothing about exactly the tenants it exists
 * for.
 *
 * A tenant whose database cannot be opened is reported as `unreachable` and
 * the fleet-wide scan continues. The reason is sanitised before it is
 * returned: a connection failure is the one message most likely to carry a
 * credential (AC-45).
 */
final class DetectSchemaDriftHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * @return list<SchemaDrift> one per tenant, in tenant-id order
     */
    public function handle(DetectSchemaDrift $query): array
    {
        $report = [];

        foreach ($this->mappings($query->tenantIds) as $mapping) {
            $report[] = $this->inspect($query, $mapping);
        }

        return $report;
    }

    /**
     * @param  array{tenant_id: string, schema_version: int, migrate_credential_ref: string}  $mapping
     */
    private function inspect(DetectSchemaDrift $query, array $mapping): SchemaDrift
    {
        $tenantId = $mapping['tenant_id'];
        $mappingVersion = $mapping['schema_version'];

        try {
            $applied = $this->tenancy->run(
                $tenantId,
                fn (): int => TenantSchemaVersion::of(
                    $this->database->connection($this->tenantConnectionName()),
                    $query->set
                ),
                TenantConnectionPurpose::Migrating
            );
        } catch (Throwable $exception) {
            return new SchemaDrift(
                tenantId: $tenantId,
                direction: SchemaDrift::UNREACHABLE,
                mappingVersion: $mappingVersion,
                appliedVersion: 0,
                reason: MigrationErrorText::sanitise(
                    $exception->getMessage(),
                    [$mapping['migrate_credential_ref']]
                ),
            );
        }

        return new SchemaDrift(
            tenantId: $tenantId,
            direction: SchemaDrift::directionFor($mappingVersion, $applied),
            mappingVersion: $mappingVersion,
            appliedVersion: $applied,
        );
    }

    /**
     * The mappings to inspect, in tenant-id order.
     *
     * Every status, deliberately: a tenant suspended *because* of drift is
     * exactly the one an operator is looking for, so a report that filtered on
     * status would hide its own best evidence.
     *
     * @param  list<string>|null  $tenantIds
     * @return list<array{tenant_id: string, schema_version: int, migrate_credential_ref: string}>
     */
    private function mappings(?array $tenantIds): array
    {
        $query = $this->table()->select(['tenant_id', 'schema_version', 'migrate_credential_ref']);

        if ($tenantIds !== null) {
            $query->whereIn('tenant_id', $tenantIds);
        }

        $mappings = [];

        foreach ($query->orderBy('tenant_id')->get() as $row) {
            $row = (array) $row;

            $mappings[] = [
                'tenant_id' => (string) $row['tenant_id'],
                'schema_version' => (int) $row['schema_version'],
                'migrate_credential_ref' => (string) $row['migrate_credential_ref'],
            ];
        }

        return $mappings;
    }

    private function tenantConnectionName(): string
    {
        return (string) $this->config->get('tenancy.connection', 'tenant');
    }

    private function table(): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_databases');
    }
}
