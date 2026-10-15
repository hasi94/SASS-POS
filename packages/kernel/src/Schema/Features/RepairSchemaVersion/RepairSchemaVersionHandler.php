<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RepairSchemaVersion;

use Flexipos\Kernel\Schema\Exceptions\SchemaRepairRefused;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDrift;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\DetectSchemaDriftHandler;
use Flexipos\Kernel\Schema\Features\DetectSchemaDrift\SchemaDrift;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\EvictsTenantMappings;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Bring a mapping back into line with the database it points at (AC-54), and
 * refuse the one direction that cannot be corrected by code (AC-55).
 *
 * **It applies nothing.** There is no migrator here and no apply call to
 * omit: the repair reads the tenant's own `migrations` table through the
 * drift query and writes one master row. That is what makes "no migration is
 * re-applied" a property of the slice rather than of a flag, and it is why
 * the test asserts the tenant's `migrations` table is byte-for-byte the same
 * afterwards - a repair indistinguishable from an ordinary run would pass a
 * weaker assertion.
 *
 * **Behind is repaired, ahead is refused, and the asymmetry is the design.**
 * A mapping behind its tenant is a crash between the two writes the runner
 * makes, and raising the mirror loses nothing. A mapping ahead of it says the
 * database has lost migrations; the only correction available to code is to
 * lower the mirror, which would make a half-restored database look healthy.
 * So the ahead case writes the status that stops the tenant serving - which
 * is AC-55's "refused for serving until corrected", structurally, because
 * `Tenancy::enter()` refuses any status but `active` - evicts the cached
 * mapping in the same step (D-19), and then throws.
 *
 * The write happens **before** the throw on purpose: a refusal that only
 * threw would leave a tenant whose database is behind its own mirror serving
 * requests for as long as nobody read the exception.
 */
final class RepairSchemaVersionHandler
{
    /**
     * The status a mapping ahead of its database is moved to.
     *
     * The same value the runner uses for a failed offline migration, and for
     * the same reason: the schema is in a state nobody has vouched for, so
     * the shop does not serve until somebody says it may.
     */
    public const UNSERVABLE_STATUS = 'failed';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly DetectSchemaDriftHandler $drift,
        private readonly TenantDatabaseRepository $mappings,
    ) {}

    /**
     * @throws SchemaRepairRefused on the first mapping found ahead of its own
     *                             database, after that tenant has been made
     *                             unservable
     */
    public function handle(RepairSchemaVersion $command): RepairSchemaVersionResult
    {
        $corrected = [];
        $aligned = [];

        foreach ($this->drift->handle(new DetectSchemaDrift($command->set, $command->tenantIds)) as $drift) {
            if ($drift->direction === SchemaDrift::AHEAD) {
                $this->refuse($drift);
            }

            if ($drift->direction !== SchemaDrift::BEHIND) {
                // Aligned, or unreachable. Neither is corrected, and an
                // unreachable tenant is certainly not: a mapping whose
                // database could not be opened has not been compared with
                // anything.
                if ($drift->direction === SchemaDrift::ALIGNED) {
                    $aligned[] = $drift->tenantId;
                }

                continue;
            }

            $this->writeMapping($drift->tenantId, ['schema_version' => $drift->appliedVersion]);

            $corrected[$drift->tenantId] = $drift->appliedVersion;
        }

        return new RepairSchemaVersionResult($corrected, $aligned);
    }

    /**
     * Stop the tenant serving, then say why.
     */
    private function refuse(SchemaDrift $drift): never
    {
        $this->writeMapping($drift->tenantId, [
            'status' => self::UNSERVABLE_STATUS,
            'status_reason' => 'the mapping claims schema version '.$drift->mappingVersion
                .' and the database carries '.$drift->appliedVersion,
        ]);

        throw SchemaRepairRefused::mirrorAheadOfTenant(
            $drift->tenantId,
            $drift->mappingVersion,
            $drift->appliedVersion,
            self::UNSERVABLE_STATUS
        );
    }

    /**
     * One mapping write and the eviction that belongs with it (D-19).
     *
     * The eviction is asked of the reader by capability rather than by class
     * (S-2), so any source that holds a copy is told - not only the one
     * decorator the shipped container happens to bind.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeMapping(string $tenantId, array $values): void
    {
        $this->table()
            ->where('tenant_id', $tenantId)
            ->update(array_merge($values, ['updated_at' => $this->clock->timestamp()]));

        if ($this->mappings instanceof EvictsTenantMappings) {
            $this->mappings->evict($tenantId);
        }
    }

    private function table(): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_databases');
    }
}
