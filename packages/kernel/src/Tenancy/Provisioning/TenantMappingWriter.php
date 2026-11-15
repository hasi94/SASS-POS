<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Tenancy\Provisioning;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\EvictsTenantMappings;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * `insert`, `activate` (compare-and-set) and `archive` for one
 * `tenant_databases` row, each followed by eviction (T-026 AC-19, AC-33,
 * Ruling 2, Ruling 9) - the same write-then-evict shape
 * `MigrateTenantHandler::writeMapping()` already uses, so the two cannot
 * drift into "a write D-19 forgot to evict".
 */
final class TenantMappingWriter
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly TenantDatabaseRepository $mappings,
    ) {}

    /**
     * `write_mapping`: inserts AC-19's row. Idempotent when a row for this
     * tenant already exists with equal values (a resumed step finds its own
     * write); refused - by the caller reading the mismatch, not here - when
     * it differs. This method itself only ever inserts or reports the
     * existing row; `write_mapping`'s own idempotence decision belongs to
     * the step, which is why this returns the existing row rather than
     * silently reconciling it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the row that exists after this call -
     *                              the one just inserted, or the one already
     *                              there
     */
    public function insert(array $row): array
    {
        $existing = $this->table()->where('tenant_id', $row['tenant_id'])->first();

        if ($existing !== null) {
            return (array) $existing;
        }

        $now = $this->clock->timestamp();

        $this->table()->insert(array_merge($row, [
            'created_at' => $row['created_at'] ?? $now,
            'updated_at' => $now,
        ]));

        $this->evict((string) $row['tenant_id']);

        return (array) $this->table()->where('tenant_id', $row['tenant_id'])->first();
    }

    /**
     * `activate`: `provisioning` → `active`, compare-and-set. Returns
     * whether this call is the one that made the change (AC-35): a second
     * call affects zero rows and returns false, so `activate` can tell "I
     * just activated it" from "it was already active" without a second
     * read.
     */
    public function activate(string $tenantId): bool
    {
        $affected = $this->table()
            ->where('tenant_id', $tenantId)
            ->where('status', 'provisioning')
            ->update([
                'status' => 'active',
                'provisioned_at' => $this->clock->timestamp(),
                'updated_at' => $this->clock->timestamp(),
            ]);

        if ($affected > 0) {
            $this->evict($tenantId);
        }

        return $affected > 0;
    }

    /**
     * `abandon`: marks the mapping `archived` if the row exists at all - a
     * run abandoned before `write_mapping` has no row to archive, which is
     * not an error (Ruling 10).
     *
     * Compare-and-set on `status = 'provisioning'` (review round 1 W-2):
     * `AbandonProvisioningHandler` already re-reads and refuses before
     * calling this at all, but this write is the one that would actually
     * archive an already-`active` mapping if that earlier check were ever
     * bypassed or raced - affecting zero rows here is the last line of
     * defence against archiving a database tenants are already being served
     * from.
     */
    public function archive(string $tenantId): void
    {
        $affected = $this->table()
            ->where('tenant_id', $tenantId)
            ->where('status', 'provisioning')
            ->update([
                'status' => 'archived',
                'archived_at' => $this->clock->timestamp(),
                'updated_at' => $this->clock->timestamp(),
            ]);

        if ($affected > 0) {
            $this->evict($tenantId);
        }
    }

    /**
     * Asked by capability, never by class (S-2/D-19's own precedent): the
     * suite binds the in-memory reader, a deployment binds the caching
     * decorator, and only the latter has anything to evict.
     */
    private function evict(string $tenantId): void
    {
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
