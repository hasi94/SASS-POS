<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\MigrateTenant;

use Flexipos\Kernel\Schema\Exceptions\TenantIdentityMismatch;
use Flexipos\Kernel\Schema\Exceptions\TenantMigrationFailed;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\PerTenantLock;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\EvictsTenantMappings;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Secrets\SecretResolver;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Execute one claimed job: migrate one tenant, mirror the result, and leave
 * nothing resolved (AC-38, AC-42 to AC-45, AC-52, AC-53, AC-60).
 *
 * **The order is the design, and five things about it are load-bearing.**
 *
 * 1. **The tenant is entered before its status is changed.** The migrating
 *    entry accepts D-11's four statuses and `migrating` is deliberately not
 *    one of them, so writing `migrating` first would make the tenant
 *    unenterable by the very run that wrote it. The scope is opened on the
 *    entry status and the status moves inside it - which is also the only
 *    order at which AC-52's observation from inside `up()` can see
 *    `migrating` at all. The entry status is captured there too, because it
 *    is what a successful offline run writes back (D-53): a literal `active`
 *    would promote a `read_only` shop by migrating it.
 * 2. **`migrating` is written only for a plan that requires it** (D-12). The
 *    status is a statement to the request path that the shop is offline; a
 *    plan of ordinary expand steps is applied to a serving tenant and says so
 *    by leaving the status alone. AC-52's control is exactly this: a
 *    non-offline migration sees `active` from inside.
 * 3. **Every status or version write is followed by a cache eviction, in the
 *    same step** (D-19, AC-53). Not at the end of the run: between the
 *    `migrating` write and the end of the migration there is a window in which
 *    the request path must already refuse the tenant, and a cached mapping
 *    with a 300-second window would serve it for five more minutes.
 * 4. **The mapping mirror is a reading, not an assumption.** The resulting
 *    `schema_version` is computed from the tenant's own `migrations` table,
 *    read inside the same scope after the apply (D-14: the tenant's table is
 *    the authority and the mapping is the mirror). A handler that wrote the
 *    target version because the apply did not throw would mirror a number the
 *    database might not carry.
 * 5. **The statement that completes the job re-checks what the read filtered
 *    on** - the status and the holding worker - and that re-check is pinned
 *    from the query log in this slice's test. The feeding read selects the job
 *    *as this worker*, so no behavioural case in one process can tell the two
 *    sites apart (plan section 7, the family's fourth shape). It is not
 *    redundant: a lease can expire during a long migration and another worker
 *    can claim the job legitimately, and a completion written without the
 *    re-check would mark that worker's running job done.
 *
 * **What is deliberately not here.** The run row's counters and final status
 * belong to `RunTenantMigrations`, which is what knows when the last job
 * finished; the drift repair of AC-54 and the `schema_meta` interlock of AC-56
 * are step 12's slices. This handler is one job.
 */
final class MigrateTenantHandler
{
    /**
     * The job status a claim leaves behind, and the two this handler writes.
     */
    public const JOB_RUNNING = 'running';

    public const JOB_DONE = 'done';

    public const JOB_FAILED = 'failed';

    /**
     * The status a job ends in when the tenant's lock was held by somebody
     * else (AC-49). Counted by the run, and not a failure.
     */
    public const JOB_SKIPPED = 'skipped';

    /**
     * The mapping statuses this handler moves a tenant between (D-12).
     */
    public const TENANT_ACTIVE = 'active';

    public const TENANT_MIGRATING = 'migrating';

    public const TENANT_FAILED = 'failed';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly Tenancy $tenancy,
        private readonly TenantMigrationApplier $applier,
        private readonly TenantDatabaseRepository $mappings,
        private readonly SecretResolver $secrets,
    ) {}

    /**
     * @throws TenantMigrationFailed when there is nothing to execute - the job
     *                               is not held by this worker, or its tenant
     *                               has no mapping. A migration that *fails* is
     *                               not an exception here: it is a `failed` job
     *                               row and a result, because the run continues
     *                               past it (D-13, AC-44).
     */
    public function handle(MigrateTenant $command): MigrateTenantResult
    {
        $job = $this->heldJob($command->jobId, $command->workerId);

        if ($job === null) {
            throw TenantMigrationFailed::jobNotHeld($command->jobId, $command->workerId);
        }

        $tenantId = (string) $job['tenant_id'];
        $target = (int) $job['to_version'];
        $mapping = $this->mappingRow($tenantId);

        if ($mapping === null) {
            throw TenantMigrationFailed::tenantNotMapped($tenantId);
        }

        $offline = $this->requiresOffline($command->set, $target);
        $needles = $this->needlesFor((string) $mapping['migrate_credential_ref']);
        $startedAt = hrtime(true);

        $applied = [];
        $present = [];
        $failure = null;
        $locked = false;
        $entryStatus = null;

        try {
            [$applied, $present] = $this->tenancy->run(
                $tenantId,
                function () use ($command, $target, $tenantId, $offline, &$locked, &$entryStatus): array {
                    $connection = $this->tenantConnectionName();

                    // AC-56, and it is **first inside the scope** on purpose:
                    // a database that says it belongs to somebody else must be
                    // left exactly as it was, so the identity is checked
                    // before the status is touched and long before anything is
                    // applied.
                    $this->assertIdentity($tenantId, $connection);

                    // AC-49, and it is before the status write for the same
                    // reason: a tenant somebody else is migrating must be left
                    // exactly as it was. The lock is never waited for - see
                    // takeLock().
                    if (! $this->takeLock($connection, $tenantId)) {
                        $locked = true;

                        return [[], []];
                    }

                    try {
                        if ($offline) {
                            // AC-52, D-53: the status the run entered on,
                            // captured **inside the scope** and immediately
                            // before it is overwritten, because that is the
                            // value the success path restores. Read from the
                            // master rather than from the row this handler
                            // read before the entry, so what is restored is
                            // what the gate actually let through.
                            $entryStatus = $this->currentStatus($tenantId);

                            $this->writeMapping($tenantId, ['status' => self::TENANT_MIGRATING]);
                        }

                        return [
                            $this->applier->apply($connection, $command->set, $target),
                            $this->applier->appliedMigrations($connection),
                        ];
                    } finally {
                        $this->releaseLock($connection, $tenantId);
                    }
                },
                TenantConnectionPurpose::Migrating
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $version = $this->versionFrom($command->set, $present, (int) $mapping['schema_version']);

        if ($locked && ! $failure instanceof Throwable) {
            return $this->recordLocked($command, $job, $mapping, $duration);
        }

        return $failure instanceof Throwable
            ? $this->recordFailure($command, $job, $mapping, $offline, $duration, $failure, $needles)
            : $this->recordSuccess($command, $job, $mapping, $offline, $duration, $version, $applied, $entryStatus);
    }

    /**
     * Take the per-tenant advisory lock, or report that somebody else holds
     * it (AC-49).
     *
     * **Never waits**: `PerTenantLock` asks with a zero timeout, because a
     * run that blocked on one busy tenant would stall every tenant behind it.
     * A refused lock is not a failure - the tenant is left untouched and
     * reported as not run, and the next run picks it up.
     *
     * **A driver with no advisory lock is not asked.** `GET_LOCK` exists on
     * MySQL and MariaDB and `PerTenantLock` throws on anything else rather
     * than reporting a lock it does not hold. The platform allows a tenant
     * connection on those two engines only (`tenancy.allowed_drivers`), so
     * the skip is the suite's SQLite stand-in and nothing a deployment can
     * reach - and it is a decision about the driver's **capability**, not a
     * list of names to keep up to date. Say plainly what that costs: on such
     * a driver two runners are not kept out of one tenant by anything, and
     * AC-49 and AC-65 stay NOT VERIFIED until two real sessions can be run
     * against the engine (G-1).
     */
    private function takeLock(string $connection, string $tenantId): bool
    {
        $handle = $this->database->connection($connection);

        if (! in_array($handle->getDriverName(), PerTenantLock::SUPPORTED_DRIVERS, true)) {
            return true;
        }

        return PerTenantLock::acquire($handle, $tenantId);
    }

    /**
     * Give the lock back, in a `finally` so a failed migration releases it
     * too.
     *
     * Closing the connection would release it anyway - that is what makes a
     * dead worker harmless - but the scope this runs in is left by
     * `Tenancy::leave()` and a process that migrates several tenants should
     * not hold one tenant's lock until it exits.
     */
    private function releaseLock(string $connection, string $tenantId): void
    {
        $handle = $this->database->connection($connection);

        if (! in_array($handle->getDriverName(), PerTenantLock::SUPPORTED_DRIVERS, true)) {
            return;
        }

        PerTenantLock::release($handle, $tenantId);
    }

    /**
     * The locked path (AC-49): nothing in the tenant's database changed, the
     * job is left for the next run, and the run carries on.
     *
     * `skipped` and not `failed`: a tenant another worker is already
     * migrating is not an error, and a run that ended
     * `completed_with_failures` because of one would have an operator
     * investigating a healthy platform. It is not `pending` either - that
     * would make this run's own claim loop pick the job up again
     * immediately.
     *
     * No mapping write at all, so the status, the version and
     * `last_migration_error` are exactly what the other worker is managing.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $mapping
     */
    private function recordLocked(
        MigrateTenant $command,
        array $job,
        array $mapping,
        int $duration,
    ): MigrateTenantResult {
        $message = sprintf(
            'Tenant [%s] was not run: another session holds the migration lock [%s].',
            (string) $job['tenant_id'],
            PerTenantLock::nameFor((string) $job['tenant_id'])
        );

        $retained = $this->completeJob($command, [
            'status' => self::JOB_SKIPPED,
            'finished_at' => $this->clock->timestamp(),
            'duration_ms' => $duration,
            'error_class' => null,
            'error_message' => $message,
            'error_migration' => null,
        ]);

        return new MigrateTenantResult(
            jobId: $command->jobId,
            tenantId: (string) $job['tenant_id'],
            databaseName: (string) $mapping['database_name'],
            succeeded: false,
            schemaVersion: (int) $mapping['schema_version'],
            applied: [],
            durationMs: $duration,
            claimRetained: $retained,
            errorMessage: $message,
            locked: true,
        );
    }

    /**
     * The database this scope opened must not claim to be another tenant
     * (AC-56).
     *
     * Absent says nothing and proceeds: `schema_meta` is created empty by the
     * baseline and T-008 never writes it, so for every tenant this task can
     * produce the no-row path is the shipped path (D-28). Only a row naming
     * somebody else refuses.
     *
     * @throws TenantIdentityMismatch before the status write and before the
     *                                apply, so nothing in that database
     *                                changed
     */
    private function assertIdentity(string $tenantId, string $connection): void
    {
        $handle = $this->database->connection($connection);
        $claimed = SchemaMetaIdentity::of($handle);

        if ($claimed === null || $claimed === $tenantId) {
            return;
        }

        throw TenantIdentityMismatch::between($tenantId, $claimed, (string) $handle->getDatabaseName());
    }

    /**
     * The job, read **as this worker**: the id, the status and the holder.
     *
     * Every term here is re-checked by the statement that completes the job,
     * which is the point - see the class docblock, rule 5.
     *
     * @return array<string, mixed>|null
     */
    private function heldJob(string $jobId, string $workerId): ?array
    {
        $row = $this->table('tenant_migration_jobs')
            ->where('id', $jobId)
            ->where('status', self::JOB_RUNNING)
            ->where('claimed_by', $workerId)
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The mapping status right now, read from the master.
     */
    private function currentStatus(string $tenantId): ?string
    {
        $row = $this->mappingRow($tenantId);

        return $row === null ? null : (string) $row['status'];
    }

    /**
     * The status an offline run writes back when it succeeds (AC-52, D-53).
     *
     * The entry status, with **one documented exception**: a tenant entered at
     * `failed` becomes `active`. `failed` means "do not serve, the schema may
     * be half-applied", and a successful run is precisely the removal of that
     * condition; restoring `failed` would make the retry path a no-op and
     * reopen BUG-1's deadlock from the other end (AC-46).
     *
     * `provisioning` restores to `provisioning` - FP-206 owns when that
     * becomes `active` - and `read_only` to `read_only`, because a schema
     * change must not promote a shop the business made read-only. A null entry
     * status cannot happen on this path (it is captured before the `migrating`
     * write) and falls back to `active` rather than writing nothing.
     */
    private static function restoredStatus(?string $entryStatus): string
    {
        if ($entryStatus === null || $entryStatus === self::TENANT_FAILED) {
            return self::TENANT_ACTIVE;
        }

        return $entryStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mappingRow(string $tenantId): ?array
    {
        $row = $this->table('tenant_databases')->where('tenant_id', $tenantId)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Whether any migration this run would apply declares `@requires-offline`.
     *
     * Read from the catalogue the set built, never from the file at run time:
     * whether a shop has to stop is a declaration, and a run that discovered it
     * halfway through would have served the tenant for the first half.
     */
    private function requiresOffline(MigrationSet $set, int $target): bool
    {
        foreach ($set->catalogue() as $entry) {
            if ($entry->version <= $target && $entry->requiresOffline) {
                return true;
            }
        }

        return false;
    }

    /**
     * The version the tenant now carries: the highest set version whose
     * migration its own `migrations` table lists.
     *
     * Falls back to the mapping's current value when the tenant lists nothing
     * this set knows - a database migrated by another set is not evidence that
     * it is at version zero.
     *
     * @param  list<string>  $present
     */
    private function versionFrom(MigrationSet $set, array $present, int $current): int
    {
        $highest = 0;

        foreach ($present as $migration) {
            $version = $set->versionOf($migration);

            if ($version !== null && $version > $highest) {
                $highest = $version;
            }
        }

        return $highest === 0 ? $current : $highest;
    }

    /**
     * The success path: the mirror first, then the job.
     *
     * The mirror is written before the bookkeeping because it is the statement
     * about the database, and the database has already changed. A `status` is
     * written back only when this handler moved it, so a run of ordinary
     * expand steps writes no status at all and cannot resurrect a tenant
     * something else suspended while it ran.
     *
     * **What is written back is the entry status, never a fixed `active`**
     * (AC-52, D-53, plan 16.1). The migrating entry now accepts `read_only`,
     * `provisioning` and `failed` as well, so a literal `active` here would
     * let a schema migration silently promote a shop the business put into
     * read-only.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $mapping
     * @param  list<string>  $applied
     * @param  string|null  $entryStatus  the status captured inside the scope;
     *                                    null when nothing moved the status
     */
    private function recordSuccess(
        MigrateTenant $command,
        array $job,
        array $mapping,
        bool $offline,
        int $duration,
        int $version,
        array $applied,
        ?string $entryStatus = null,
    ): MigrateTenantResult {
        $now = $this->clock->timestamp();

        $this->writeMapping((string) $job['tenant_id'], array_merge([
            'schema_version' => $version,
            'last_migrated_at' => $now,
            // AC-43(d): a previous failure is cleared, not left to be read as
            // the explanation of a run that has since succeeded.
            'last_migration_error' => null,
        ], $offline ? ['status' => self::restoredStatus($entryStatus)] : []));

        $retained = $this->completeJob($command, [
            'status' => self::JOB_DONE,
            'finished_at' => $now,
            'duration_ms' => $duration,
            'error_class' => null,
            'error_message' => null,
            'error_migration' => null,
        ]);

        return new MigrateTenantResult(
            jobId: $command->jobId,
            tenantId: (string) $job['tenant_id'],
            databaseName: (string) $mapping['database_name'],
            succeeded: true,
            schemaVersion: $version,
            applied: $applied,
            durationMs: $duration,
            claimRetained: $retained,
        );
    }

    /**
     * The failure path (AC-44, AC-45, D-12, D-13).
     *
     * `schema_version` is **not** written: the tenant is left at the version it
     * carried, which is what makes "C's schema_version is unchanged" a property
     * of the statement rather than of the value happening to be equal. The
     * status becomes `failed` only for an offline plan - an ordinary expand
     * step that failed leaves a serving tenant serving.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $mapping
     * @param  list<string>  $needles
     */
    private function recordFailure(
        MigrateTenant $command,
        array $job,
        array $mapping,
        bool $offline,
        int $duration,
        Throwable $failure,
        array $needles,
    ): MigrateTenantResult {
        $now = $this->clock->timestamp();
        $message = MigrationErrorText::sanitise($this->describe($failure), $needles);
        $migration = $failure instanceof TenantMigrationFailed ? $failure->migration() : null;
        $class = $this->blameFor($failure);

        $this->writeMapping((string) $job['tenant_id'], array_merge([
            'last_migration_error' => $message,
        ], $offline ? ['status' => self::TENANT_FAILED] : []));

        $retained = $this->completeJob($command, [
            'status' => self::JOB_FAILED,
            'finished_at' => $now,
            'duration_ms' => $duration,
            'error_class' => $class,
            'error_message' => $message,
            'error_migration' => $migration,
        ]);

        return new MigrateTenantResult(
            jobId: $command->jobId,
            tenantId: (string) $job['tenant_id'],
            databaseName: (string) $mapping['database_name'],
            succeeded: false,
            schemaVersion: (int) $mapping['schema_version'],
            applied: [],
            durationMs: $duration,
            claimRetained: $retained,
            errorClass: $class,
            errorMessage: $message,
            errorMigration: $migration,
        );
    }

    /**
     * The class an operator filtering `error_class` is actually after.
     *
     * The **cause**, when the runner wrapped one. `TenantMigrationFailed` is
     * on every failed row by construction, so storing it would make the column
     * distinguish nothing; what tells a rejected login from a broken migration
     * is the exception the driver or the migration itself threw. The runner's
     * own wrapper still survives in `error_message`, where it names the
     * migration.
     */
    private function blameFor(Throwable $failure): string
    {
        $previous = $failure->getPrevious();

        return $failure instanceof TenantMigrationFailed && $previous instanceof Throwable
            ? $previous::class
            : $failure::class;
    }

    /**
     * The failure, and the cause it wraps.
     *
     * Both, because the runner's own message names the migration and the
     * driver's names what actually went wrong, and an operator reading one
     * column needs the second sentence more than the first.
     */
    private function describe(Throwable $failure): string
    {
        $previous = $failure->getPrevious();

        return $previous instanceof Throwable
            ? $failure->getMessage().' '.$previous::class.': '.$previous->getMessage()
            : $failure->getMessage();
    }

    /**
     * The statement that completes the job, and the re-check that makes it
     * safe.
     *
     * Returns whether this worker still held the job: zero affected rows means
     * the lease expired during the migration and somebody else has it, so the
     * bookkeeping belongs to them.
     *
     * @param  array<string, mixed>  $values
     */
    private function completeJob(MigrateTenant $command, array $values): bool
    {
        return $this->table('tenant_migration_jobs')
            ->where('id', $command->jobId)
            ->where('status', self::JOB_RUNNING)
            ->where('claimed_by', $command->workerId)
            ->update($values) === 1;
    }

    /**
     * One mapping write, and the eviction that belongs with it (D-19).
     *
     * One method so the two can never be separated by an edit: every status or
     * version change in this slice goes through here, and the eviction is the
     * next statement rather than a step somebody remembers at the end of the
     * run.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeMapping(string $tenantId, array $values): void
    {
        $this->table('tenant_databases')
            ->where('tenant_id', $tenantId)
            ->update(array_merge($values, ['updated_at' => $this->clock->timestamp()]));

        $this->evict($tenantId);
    }

    /**
     * Drop one tenant's cached mapping, when the bound reader caches at all.
     *
     * Asked of the object rather than of the container's configuration: the
     * suite binds the in-memory reader and a deployment binds the caching
     * decorator, and a handler that assumed either would be wrong in one of
     * them. Asked by **capability** rather than by class (S-2), so a second
     * caching decorator is evicted too instead of silently making D-19 false.
     * Never a flush - a cache clear would make one tenant's migration a
     * stampede for every other shop on the server.
     */
    private function evict(string $tenantId): void
    {
        if ($this->mappings instanceof EvictsTenantMappings) {
            $this->mappings->evict($tenantId);
        }
    }

    /**
     * The credential-shaped values that must not survive into a stored error.
     *
     * The reference is resolved purely so its *value* can be redacted; the
     * Secret is never used to connect from here, and a reference that cannot be
     * resolved is still redacted as a reference. A resolver failure is
     * swallowed on purpose: this runs on the failure path, and a sanitiser that
     * threw would replace the operator's error with its own.
     *
     * @return list<string>
     */
    private function needlesFor(string $credentialRef): array
    {
        $needles = [$credentialRef];

        try {
            $needles[] = $this->secrets->resolve($credentialRef)->reveal();
        } catch (Throwable) {
            // Nothing to add: the reference itself is already a needle.
        }

        return $needles;
    }

    private function tenantConnectionName(): string
    {
        return (string) $this->config->get('tenancy.connection', 'tenant');
    }

    private function table(string $table): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table($table);
    }
}
