<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\PlanMigrationRun;

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Write the plan for one run: one run row, one job row per selected tenant,
 * every job `pending` (AC-39, AC-40, AC-51, AC-58, AC-59, AC-32's refusal
 * half).
 *
 * **Planning touches no tenant database. That is not a side effect, it is the
 * criterion.** Every statement this handler issues is on the master connection
 * - the selection read, the run row and the job rows - and every refusal is
 * decided before the first of those writes. So "no tenant was altered" and
 * "zero job rows were written" are properties of the order the method does
 * things in, which is what D-10 asks for and what a rollback could not give:
 * a rolled-back insert still happened, and a refusal that fired after the
 * first tenant connection opened would have proven nothing at all.
 *
 * The order is therefore load-bearing and is the whole design of the method:
 *
 * 1. **The catalogue is verified** (AC-32). A set holding a migration the
 *    catalogue never published, or one whose content moved under a published
 *    row, refuses here - before a run row exists - because a tenant version is
 *    a position in the catalogue and a plan written against a different tree
 *    is a plan in a different numbering.
 * 2. **The tenants are selected** (AC-40, AC-51), by reading the master. This
 *    is a read; nothing is written yet.
 * 3. **The destructive refusal** (AC-59, D-10), keyed on the catalogue flag and
 *    gated by one configuration key defaulting to false. It is keyed on
 *    `is_destructive` and never on the phase: a contract step that only *adds*
 *    - FX-4 - is not refused, and that control is what catches an
 *    implementation that refused on `phase = contract` instead.
 * 4. **The contract guard** (AC-58). A contract step at version V is refused
 *    while any tenant a bulk run would touch is below V - 1, with the blocking
 *    tenants named. Expand and contract means the expand step has reached every
 *    tenant before the contract step reaches any; a tenant in `provisioning`
 *    does not block, because it holds nothing yet.
 * 5. **Only then the writes**, run row first, then the job rows in ascending
 *    `size_bytes`.
 *
 * **Status rules (D-11), and the one reading this handler had to choose.**
 * AC-40 says an `archived` tenant "is not planned" by a bulk run, and AC-51
 * says the statuses outside the accepted set "are refused with the status
 * named". Those are the same sentence about two different runs, and this
 * handler reads them so: a **bulk** run filters - it accepts `active` and
 * `read_only` and silently passes over everything else, because a bulk run is
 * a statement about whoever is eligible; a run that **names** a tenant
 * refuses, with the status in the message, because the operator asked about
 * that tenant and a silent empty plan would answer nothing. A named run
 * additionally accepts `provisioning` and `failed`.
 *
 * The status read is `tenant_databases.status`, the same column
 * `Tenancy::enter()` gates serving on. One status, one authority; a runner that
 * selected on `tenants.status` could plan a run for a database the request path
 * refuses to open.
 */
final class PlanMigrationRunHandler
{
    /**
     * The statuses a run naming one tenant accepts (D-11, D-52).
     *
     * **Derived, never restated.** It is the migrating scope entry's own list,
     * read from the one place it lives. A copied list is one edit away from
     * BUG-1 - a planner that accepts a status the door then refuses, which
     * writes a job row that can only fail (plan 16.1).
     *
     * @var list<string>
     */
    public const NAMED_ACCEPTED = TenantConnectionPurpose::MIGRATING_STATUSES;

    /**
     * The statuses only a run that *names* a tenant accepts (D-11).
     *
     * This is the subtraction, and it is the one list this handler owns: which
     * of the enterable statuses a bulk run declines to pick up on its own.
     *
     * @var list<string>
     */
    public const NAMED_ONLY_STATUSES = ['provisioning', 'failed'];

    /**
     * The statuses a bulk run touches (D-11): the accepted list minus the
     * named-only ones.
     *
     * A method rather than a constant because PHP has no `array_diff` in a
     * constant expression, and the derivation is the point: `BULK_STATUSES`
     * written out as a literal is the second copy this fix exists to remove.
     * `ReportSchemaStatusHandler` reads this rather than repeating it.
     *
     * @return list<string>
     */
    public static function bulkStatuses(): array
    {
        return array_values(array_diff(self::NAMED_ACCEPTED, self::NAMED_ONLY_STATUSES));
    }

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * @throws MigrationRunRefused before any row is written and before any
     *                             tenant connection is opened
     */
    public function handle(PlanMigrationRun $command): PlanMigrationRunResult
    {
        $catalogue = $command->set->catalogue();
        $highest = $command->set->highestVersion();
        $target = $command->targetVersion ?? $highest;

        if ($target > $highest) {
            throw MigrationRunRefused::targetBeyondSet($command->set->name, $target, $highest);
        }

        $this->assertCataloguePublished($catalogue);

        $selected = $this->select($command, $target);
        $applied = $this->appliedVersions($selected, $target);

        $this->assertNothingDestructive($catalogue, $applied);
        $this->assertContractStepsUnblocked($catalogue, $applied);

        return $this->write($command, $target, $selected);
    }

    /**
     * Every migration in the set is published, at the version the set gives it
     * and with the content the catalogue recorded (AC-32).
     *
     * The whole set is checked and not only the part below the target: a file
     * that exists and is unpublished is the condition AC-32 names, whatever
     * version this particular run was asked for.
     *
     * @param  list<MigrationCatalogue>  $catalogue
     */
    private function assertCataloguePublished(array $catalogue): void
    {
        $published = [];

        foreach ($this->table('schema_versions')->get() as $row) {
            $row = (array) $row;
            $published[(int) $row['version']] = (string) $row['checksum'];
        }

        foreach ($catalogue as $entry) {
            if (! array_key_exists($entry->version, $published)) {
                throw MigrationRunRefused::migrationNotPublished($entry->migration, $entry->version);
            }

            if ($published[$entry->version] !== $entry->checksum) {
                throw MigrationRunRefused::publishedContentChanged($entry->migration, $entry->version);
            }
        }
    }

    /**
     * The tenants this run plans, in ascending `size_bytes` (AC-40).
     *
     * A tenant already at or above the target is not selected at all - there is
     * nothing to apply to it - which is why "a tenant at the target is not
     * planned" needs no separate branch.
     *
     * @return list<array{tenant_id: string, server_id: string, schema_version: int}>
     */
    private function select(PlanMigrationRun $command, int $target): array
    {
        $query = $this->table('tenant_databases')
            ->select(['tenant_id', 'server_id', 'status', 'schema_version', 'size_bytes']);

        if ($command->namesTenants()) {
            $query->whereIn('tenant_id', $command->tenantIds ?? []);
        }

        // Ascending size, then the identifier, so two tenants of the same size
        // are still planned in one order rather than in whichever order the
        // engine returned them.
        $rows = $query->orderBy('size_bytes')->orderBy('tenant_id')->get();

        $byTenant = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $byTenant[(string) $row['tenant_id']] = $row;
        }

        if ($command->namesTenants()) {
            return $this->selectNamed($command->tenantIds ?? [], $byTenant, $target);
        }

        $selected = [];

        foreach ($byTenant as $row) {
            if (! in_array((string) $row['status'], self::bulkStatuses(), true)) {
                continue;
            }

            if ((int) $row['schema_version'] >= $target) {
                continue;
            }

            $selected[] = $this->selection($row);
        }

        return $selected;
    }

    /**
     * The named half of the selection: every named tenant must exist and must
     * carry an accepted status, or the whole plan is refused (AC-51).
     *
     * @param  list<string>  $tenantIds
     * @param  array<string, array<string, mixed>>  $byTenant  in size order
     * @return list<array{tenant_id: string, server_id: string, schema_version: int}>
     */
    private function selectNamed(array $tenantIds, array $byTenant, int $target): array
    {
        $accepted = self::NAMED_ACCEPTED;

        foreach ($tenantIds as $tenantId) {
            if (! array_key_exists($tenantId, $byTenant)) {
                throw MigrationRunRefused::tenantNotMapped($tenantId);
            }

            $status = (string) $byTenant[$tenantId]['status'];

            if (! in_array($status, $accepted, true)) {
                throw MigrationRunRefused::tenantStatusRefused($tenantId, $status, $accepted);
            }
        }

        $selected = [];

        // Iterated over the size-ordered map rather than over the names the
        // caller gave, so a named run is ordered by size exactly like a bulk
        // one.
        foreach ($byTenant as $tenantId => $row) {
            if (! in_array($tenantId, $tenantIds, true)) {
                continue;
            }

            if ((int) $row['schema_version'] >= $target) {
                continue;
            }

            $selected[] = $this->selection($row);
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{tenant_id: string, server_id: string, schema_version: int}
     */
    private function selection(array $row): array
    {
        return [
            'tenant_id' => (string) $row['tenant_id'],
            'server_id' => (string) $row['server_id'],
            'schema_version' => (int) $row['schema_version'],
        ];
    }

    /**
     * The versions this run would actually apply to somebody.
     *
     * A version no selected tenant is below is not applied by this run, so a
     * destructive migration every tenant already carries does not refuse a run
     * that cannot reach it.
     *
     * @param  list<array{tenant_id: string, server_id: string, schema_version: int}>  $selected
     * @return list<int>
     */
    private function appliedVersions(array $selected, int $target): array
    {
        if ($selected === []) {
            return [];
        }

        $lowest = min(array_map(static fn (array $row): int => $row['schema_version'], $selected));

        return range($lowest + 1, $target);
    }

    /**
     * D-10: while no backup facility exists, a destructive migration is refused
     * for every tenant, at plan time (AC-59).
     *
     * @param  list<MigrationCatalogue>  $catalogue
     * @param  list<int>  $applied
     */
    private function assertNothingDestructive(array $catalogue, array $applied): void
    {
        if ((bool) $this->config->get(MigrationRunRefused::BACKUPS_KEY, false)) {
            return;
        }

        foreach ($catalogue as $entry) {
            if (! $entry->isDestructive) {
                continue;
            }

            if (! in_array($entry->version, $applied, true)) {
                continue;
            }

            throw MigrationRunRefused::destructiveWithoutBackups($entry->migration, $entry->version);
        }
    }

    /**
     * AC-58: a contract step is planned only once every tenant a bulk run would
     * touch carries the version before it.
     *
     * The blocking set is read fresh from the master and is **not** the
     * selection: a tenant left out of a named run still blocks, because the
     * contract step is about the fleet rather than about this run.
     *
     * @param  list<MigrationCatalogue>  $catalogue
     * @param  list<int>  $applied
     */
    private function assertContractStepsUnblocked(array $catalogue, array $applied): void
    {
        foreach ($catalogue as $entry) {
            if ($entry->phase !== MigrationPhase::Contract) {
                continue;
            }

            if (! in_array($entry->version, $applied, true)) {
                continue;
            }

            $blocking = $this->tenantsBelow($entry->version - 1);

            if ($blocking !== []) {
                throw MigrationRunRefused::contractStepBlocked($entry->migration, $entry->version, $blocking);
            }
        }
    }

    /**
     * The tenants a bulk run would touch that are below one version.
     *
     * @return list<string>
     */
    private function tenantsBelow(int $version): array
    {
        $tenants = [];

        $rows = $this->table('tenant_databases')
            ->whereIn('status', self::bulkStatuses())
            ->where('schema_version', '<', $version)
            ->orderBy('tenant_id')
            ->get();

        foreach ($rows as $row) {
            $tenants[] = (string) ((array) $row)['tenant_id'];
        }

        return $tenants;
    }

    /**
     * The run row and the job rows, in that order.
     *
     * @param  list<array{tenant_id: string, server_id: string, schema_version: int}>  $selected
     */
    private function write(PlanMigrationRun $command, int $target, array $selected): PlanMigrationRunResult
    {
        $runId = (string) Str::ulid();
        $now = $this->clock->timestamp();
        $total = count($selected);

        $this->table('tenant_migration_runs')->insert([
            'id' => $runId,
            'target_version' => $target,
            'initiated_by' => $command->initiatedBy,
            'mode' => $command->mode(),
            'selector' => (string) json_encode([
                'mode' => $command->mode(),
                'tenants' => $command->tenantIds,
                'target_version' => $target,
                'set' => $command->set->name,
            ]),
            'concurrency' => $command->concurrency,
            'status' => 'planned',
            'total' => $total,
            'pending' => $total,
            'running' => 0,
            'done' => 0,
            'failed' => 0,
            'skipped' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $jobs = [];
        $rows = [];

        foreach ($selected as $tenant) {
            $jobId = (string) Str::ulid();

            $jobs[] = [
                'job_id' => $jobId,
                'tenant_id' => $tenant['tenant_id'],
                'server_id' => $tenant['server_id'],
                'from_version' => $tenant['schema_version'],
                'to_version' => $target,
            ];

            $rows[] = [
                'id' => $jobId,
                'run_id' => $runId,
                'tenant_id' => $tenant['tenant_id'],
                'server_id' => $tenant['server_id'],
                'from_version' => $tenant['schema_version'],
                'to_version' => $target,
                'status' => 'pending',
                'attempts' => 0,
            ];
        }

        if ($rows !== []) {
            $this->table('tenant_migration_jobs')->insert($rows);
        }

        return new PlanMigrationRunResult(
            runId: $runId,
            targetVersion: $target,
            mode: $command->mode(),
            jobs: $jobs,
        );
    }

    private function table(string $table): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table($table);
    }
}
