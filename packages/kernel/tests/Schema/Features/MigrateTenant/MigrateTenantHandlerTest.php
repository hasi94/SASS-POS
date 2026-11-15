<?php

use Flexipos\Kernel\Schema\Exceptions\TenantIdentityMismatch;
use Flexipos\Kernel\Schema\Exceptions\TenantMigrationFailed;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenant;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantHandler;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantResult;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrationErrorText;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| One job, executed
|--------------------------------------------------------------------------
|
| T-008 step 11, the half that needs no engine: the status transitions, the
| mirror write, the cleared error, the cache eviction and the error
| sanitising. Red is proven by omitting the mechanism, on the suite's SQLite
| master with a fake migrator behind the TenantMigrationApplier seam (plan
| section 11.5, step 11).
|
| **What is not here.** "FX-1's table exists in A, B and C" is the engine half
| and is [gated]; AC-38, AC-43's own-migrations-table half and AC-52's literal
| wording stay NOT VERIFIED behind G-1. No case in this file is named as if it
| covered them.
|
| **The statement pin, and why this file has one** (plan section 7, the
| family's fourth shape). The handler reads the job *as this worker* - by id,
| status and claimed_by - and the statement that completes the job re-checks
| the same two terms. In one process the read never offers a row the
| completion would wrongly write, so none of the behavioural cases below is
| evidence about the statement. The executed UPDATE's WHERE is therefore
| asserted from the query log, in the same commit as the statement, and there
| is a behavioural case beside it - the expired lease - which is the input at
| which the re-check actually binds.
|
| The clock is stopped and moved by hand, because a lease that expires
| mid-migration is one of the inputs and the alternative is sleeping for the
| configured thirty minutes.
|
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();
    migrateFixtureServer();
    migrateFixtureRun();
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * Execute one job as one worker.
 */
function migrateAs(string $jobId, string $worker, int ...$fx): MigrateTenantResult
{
    return app(MigrateTenantHandler::class)->handle(
        new MigrateTenant($jobId, $worker, runFixtureSet(...$fx))
    );
}

/*
|--------------------------------------------------------------------------
| AC-43 - what a finished job leaves behind
|--------------------------------------------------------------------------
*/

it('mirrors the applied version, stamps the time, clears the previous error and closes the job', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), [
        // The input AC-43(d) needs: a tenant carrying the explanation of a run
        // that has since been fixed. Without it, "last_migration_error is
        // null" is also true of a tenant that never failed.
        'last_migration_error' => 'the previous run failed here',
    ]);

    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    $result = migrateAs($job, 'worker-one');

    expect($result->succeeded)->toBeTrue()
        ->and($result->applied)->toHaveCount(runFixtureBaselineVersion())
        ->and($result->schemaVersion)->toBe(runFixtureBaselineVersion())
        ->and($result->claimRetained)->toBeTrue();

    $mapping = migrateFixtureMappingRow($tenant);

    expect((int) $mapping['schema_version'])->toBe(runFixtureBaselineVersion())
        ->and($mapping['last_migrated_at'])->toBe('2026-10-15 09:00:00.000')
        ->and($mapping['last_migration_error'])->toBeNull()
        // Not touched: this plan is not offline, so the tenant served
        // throughout and the status is the one it already had.
        ->and((string) $mapping['status'])->toBe('active');

    $row = migrateFixtureJobRow($job);

    expect((string) $row['status'])->toBe('done')
        ->and((int) $row['from_version'])->toBe(0)
        ->and((int) $row['to_version'])->toBe(runFixtureBaselineVersion())
        ->and($row['duration_ms'])->not->toBeNull()
        ->and($row['finished_at'])->toBe('2026-10-15 09:00:00.000')
        ->and($row['error_class'])->toBeNull();
});

it('mirrors the version the tenant carries, not the version the job asked for', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    // The migrator applies four of the six and returns **successfully** - the
    // shape of a partially applied tree. A handler that wrote the job's target
    // because nothing threw would mirror 6; the mirror is a reading of the
    // tenant's own table, so it says 4.
    migrateFixtureApplier()->applyAtMost = 4;

    $result = migrateAs($job, 'worker-one');

    expect($result->succeeded)->toBeTrue()
        ->and($result->applied)->toHaveCount(4)
        ->and($result->schemaVersion)->toBe(4)
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(4);
});

/*
|--------------------------------------------------------------------------
| The completing statement re-checks what the read filtered on
|--------------------------------------------------------------------------
*/

it('completes the job with one statement whose where re-checks the status and the holder', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    $touched = runFixtureConnectionsUsedBy(static fn () => migrateAs($job, 'worker-one'));

    expect($touched['thrown'])->toBeNull();

    $completions = [];

    foreach ($touched['sql'] as $sql) {
        if (str_starts_with(strtolower(ltrim($sql)), 'update "tenant_migration_jobs"')) {
            $completions[] = $sql;
        }
    }

    expect($completions)->toHaveCount(1);

    // MEASURED shape, and the reason this assertion is not duplication of the
    // behavioural cases: the read that feeds the completion filters on exactly
    // these two terms, so deleting them from the statement leaves every other
    // case in this file green. This is the only case that moves.
    $where = substr($completions[0], (int) strpos($completions[0], ' where '));

    expect($where)->toContain('"id" = ?')
        ->and($where)->toContain('"status" = ?')
        ->and($where)->toContain('"claimed_by" = ?');
});

it('writes no job row when the lease expired and another worker took the job over', function () {
    // The input at which the re-check binds. Without it the statement pin
    // above is the only evidence, and a reader could believe the terms are
    // decoration.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    $first = runFixtureSet()->catalogue()[0]->migration;

    migrateFixtureApplier()->onMigration = function (string $database, string $migration) use ($job, $first): void {
        if ($migration !== $first) {
            return;
        }

        // The lease ran out mid-migration and a second worker claimed the job
        // legitimately - exactly what ClaimMigrationJobHandler does on an
        // expired lease.
        DB::connection('master')->table('tenant_migration_jobs')->where('id', $job)->update([
            'claimed_by' => 'worker-two',
            'lock_expires_at' => '2026-10-15 10:30:00.000',
        ]);
    };

    $result = migrateAs($job, 'worker-one');

    expect($result->succeeded)->toBeTrue()
        ->and($result->claimRetained)->toBeFalse();

    $row = migrateFixtureJobRow($job);

    // The other worker's job is untouched: no `done`, no finished_at, no
    // duration. That is what the re-check buys.
    expect((string) $row['status'])->toBe('running')
        ->and((string) $row['claimed_by'])->toBe('worker-two')
        ->and($row['finished_at'])->toBeNull();

    // The mirror is still written: the database really did change, and hiding
    // that because of a bookkeeping race would leave the mapping claiming a
    // version the tenant has passed.
    expect((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(runFixtureBaselineVersion());
});

it('refuses a job this worker does not hold', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    expect(fn () => migrateAs($job, 'worker-two'))
        ->toThrow(TenantMigrationFailed::class, 'is not held as [running] by the worker [worker-two]');

    // And nothing ran: the migrator was never asked.
    expect(migrateFixtureApplier()->calls)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| AC-52's stand-in half - the status seen from inside the migration
|--------------------------------------------------------------------------
*/

it('shows migrating from inside an offline plan and active again afterwards', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion() + 1);

    $seen = [];

    migrateFixtureApplier()->onMigration = function () use ($tenant, &$seen): void {
        // Read through the **mapping reader**, not straight from the table:
        // that is the read the request path makes, and D-19's whole content is
        // that it must not answer from a cache written before the status
        // changed.
        $seen[] = app(TenantDatabaseRepository::class)->find($tenant)?->status;
    };

    // FX-3 declares @requires-offline, so this plan is an offline one.
    $result = migrateAs($job, 'worker-one', 3);

    expect($result->succeeded)->toBeTrue()
        ->and(array_unique($seen))->toBe(['migrating'])
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe('active')
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(runFixtureBaselineVersion() + 1);
});

it('leaves the status active throughout a plan that does not require offline', function () {
    // AC-52's control, and it is the one that catches a handler that sets
    // `migrating` for every run: without it, "the status was migrating" is
    // also true of an implementation that never read requires_offline.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    $seen = [];

    migrateFixtureApplier()->onMigration = function () use ($tenant, &$seen): void {
        $seen[] = app(TenantDatabaseRepository::class)->find($tenant)?->status;
    };

    migrateAs($job, 'worker-one');

    expect(array_unique($seen))->toBe(['active'])
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe('active');
});

it('leaves an offline tenant failed when its migration fails', function () {
    // D-12: an offline migration that fails leaves the shop stopped, because
    // the schema is in an unknown state and serving it would be the guess.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion() + 1);

    migrateFixtureApplier()->onMigration = function (string $database, string $migration): void {
        if (str_contains($migration, 'fx3')) {
            throw new RuntimeException('FX-3 failed on this tenant');
        }
    };

    $result = migrateAs($job, 'worker-one', 3);

    expect($result->succeeded)->toBeFalse()
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| AC-52 / D-53 - a successful offline run restores the status it entered on
|--------------------------------------------------------------------------
|
| T-008 step 13b, and this is the defect the BUG-1 waiver creates if it is
| not fixed with it. The migrating entry now accepts `read_only`,
| `provisioning` and `failed` as well, and the handler used to write the
| literal `active` on success - so a schema migration would silently promote
| a shop the business put into read-only.
|
| The four cases check each other: an implementation that writes the literal
| `active` passes (c) and (d) and fails (a) and (b), which is the signature
| of that specific defect rather than of a general failure. (d) is the one
| documented exception, and it is not a convenience: restoring `failed`
| would make a retry a no-op and reopen the deadlock from the other end.
*/

it('restores the status an offline run entered on, promoting only a failed tenant', function (string $entry, string $after) {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => $entry]);
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion() + 1);

    $seen = [];

    migrateFixtureApplier()->onMigration = function () use ($tenant, &$seen): void {
        $seen[] = app(TenantDatabaseRepository::class)->find($tenant)?->status;
    };

    // FX-3 declares @requires-offline, so this plan is an offline one and the
    // status does move - without that the restore would have nothing to
    // restore and every row below would pass on a handler that writes no
    // status at all.
    $result = migrateAs($job, 'worker-one', 3);

    expect($result->succeeded)->toBeTrue()
        ->and(array_unique($seen))->toBe(['migrating'])
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe($after);
})->with([
    '(a) read_only stays read_only: a schema change must not promote a shop the business stopped serving' => ['read_only', 'read_only'],
    '(b) provisioning stays provisioning: FP-206 owns when that becomes active' => ['provisioning', 'provisioning'],
    '(c) active stays active: the control, and the case the old literal already passed' => ['active', 'active'],
    '(d) failed becomes active: the one documented exception, or AC-46 retry is a no-op' => ['failed', 'active'],
]);

it('writes failed whatever the entry status when an offline run fails', function (string $entry) {
    // The failure side is unchanged by D-53: D-12 stops the shop whatever it
    // was before, because the schema is in an unknown state. Without this
    // pair the restore above is also true of a handler that writes the entry
    // status back on *every* path.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => $entry]);
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion() + 1);

    migrateFixtureApplier()->onMigration = function (string $database, string $migration): void {
        if (str_contains($migration, 'fx3')) {
            throw new RuntimeException('FX-3 failed on this tenant');
        }
    };

    $result = migrateAs($job, 'worker-one', 3);

    expect($result->succeeded)->toBeFalse()
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe('failed');
})->with([
    'read_only' => ['read_only'],
    'provisioning' => ['provisioning'],
]);

it('writes no status at all for a non-offline run on a read_only tenant', function () {
    // The other half of "only when this handler moved it": an ordinary expand
    // plan leaves the status alone, so a read_only tenant is still read_only
    // and the restore never ran.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => 'read_only']);
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    $result = migrateAs($job, 'worker-one');

    expect($result->succeeded)->toBeTrue()
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe('read_only');
});

/*
|--------------------------------------------------------------------------
| D-19 / AC-53's stand-in half - the eviction, and the mask under it
|--------------------------------------------------------------------------
*/

it('evicts the cached mapping in the same step as the status change', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion() + 1);

    // Warm the cache *before* the run, which is AC-53's own Given: an entry
    // written while the tenant was active and a 300-second window in front of
    // it. No clock is advanced anywhere in this case.
    expect(app(TenantDatabaseRepository::class)->find($tenant)?->status)->toBe('active');

    $seen = [];

    migrateFixtureApplier()->onMigration = function () use ($tenant, &$seen): void {
        $seen[] = app(TenantDatabaseRepository::class)->find($tenant)?->status;
    };

    migrateAs($job, 'worker-one', 3);

    expect(array_unique($seen))->toBe(['migrating']);

    // And the far side: the cache is evicted again when the run restores the
    // status and advances the version, so the request path sees the tenant
    // servable without waiting out the window.
    $after = app(TenantDatabaseRepository::class)->find($tenant);

    expect($after?->status)->toBe('active')
        ->and($after?->schemaVersion)->toBe(runFixtureBaselineVersion() + 1);
});

it('would have served the stale mapping without the eviction', function () {
    // The control for the case above, and the reason it is not measuring a
    // reader that never caches: the same read, against the same warm cache,
    // after a status change written **around** the handler's own write path.
    // It answers `active` - which is exactly what the case above would see if
    // writeMapping() stopped evicting.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));

    expect(app(TenantDatabaseRepository::class)->find($tenant)?->status)->toBe('active');

    DB::connection('master')->table('tenant_databases')
        ->where('tenant_id', $tenant)
        ->update(['status' => 'migrating']);

    expect(app(TenantDatabaseRepository::class)->find($tenant)?->status)->toBe('active');
});

/*
|--------------------------------------------------------------------------
| AC-45 - nothing a failure stores carries a credential
|--------------------------------------------------------------------------
*/

it('stores a failure without the password or the credential reference', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    // The shape a driver really produces on a rejected login: the whole stanza
    // in the message, credential and reference together.
    $leak = 'SQLSTATE[HY000] [1045] Access denied for user \'flx_mig\'@\'%\' using password '
        .migrateFixturePassword().' resolved from '.migrateFixtureCredentialRef();

    migrateFixtureApplier()->onMigration = function () use ($leak): void {
        throw new RuntimeException($leak);
    };

    $result = migrateAs($job, 'worker-one');

    $row = migrateFixtureJobRow($job);
    $mapping = migrateFixtureMappingRow($tenant);

    foreach ([$result->errorMessage, (string) $row['error_message'], (string) $mapping['last_migration_error']] as $stored) {
        expect($stored)->not->toContain(migrateFixturePassword())
            ->and($stored)->not->toContain(migrateFixtureCredentialRef())
            ->and($stored)->toContain('[redacted]')
            // Still useful: the driver's own diagnosis survives the redaction.
            ->and($stored)->toContain('1045');
    }

    expect((string) $row['status'])->toBe('failed')
        // The cause, not our wrapper: TenantMigrationFailed is on every failed
        // row, so a column holding it would distinguish nothing.
        ->and((string) $row['error_class'])->toBe(RuntimeException::class)
        ->and((string) $row['error_migration'])->toBe(runFixtureSet()->catalogue()[0]->migration)
        // AC-44: the tenant is left where it was.
        ->and((int) $mapping['schema_version'])->toBe(0);
});

it('reports the planted credential when nothing redacts it', function () {
    // The control the criterion asks for: the scan above can see what it
    // claims to see. Unsanitised, the same text does carry the password.
    $leak = 'using password '.migrateFixturePassword().' from '.migrateFixtureCredentialRef();

    expect($leak)->toContain(migrateFixturePassword())
        ->and(MigrationErrorText::sanitise($leak, [migrateFixturePassword(), migrateFixtureCredentialRef()]))
        ->not->toContain(migrateFixturePassword());
});

it('redacts a reference the caller never named', function () {
    // Rule 2 of MigrationErrorText: a cause thrown from deeper carries
    // references the handler had no way to list. A sanitiser whose coverage is
    // the caller's list is a sanitiser whose coverage is an accident.
    $message = 'rotating env://SOMEBODY_ELSES_SECRET failed';

    expect(MigrationErrorText::sanitise($message, []))
        ->not->toContain('SOMEBODY_ELSES_SECRET')
        ->toContain('[redacted]');
});

it('truncates after redacting, never before', function () {
    // Cutting first could leave half a secret behind, which is why the order
    // is stated in the helper and asserted here.
    $message = str_repeat('x', MigrationErrorText::MAX_LENGTH).migrateFixturePassword();

    $sanitised = MigrationErrorText::sanitise($message, [migrateFixturePassword()]);

    expect(strlen($sanitised))->toBe(MigrationErrorText::MAX_LENGTH)
        ->and($sanitised)->not->toContain(migrateFixturePassword());
});

/*
|--------------------------------------------------------------------------
| AC-56 - the database says it belongs to somebody else
|--------------------------------------------------------------------------
|
| Added in step 12, where the plan puts the interlock refusal. Three states,
| and the third is the shipped one: a matching row proceeds, a mismatching row
| refuses, and **no row at all** proceeds - which is every tenant this task
| can produce, because the baseline creates `schema_meta` empty and T-008
| never writes it (D-28).
|
| The mismatch is planted by the test, because nothing in the product writes
| that row. That is the criterion's own construction, not a shortcut.
|
*/

it('refuses a tenant whose schema_meta row names a different tenant, and alters nothing', function () {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    migrateFixtureSchemaMeta('tenant-a', 'SOMEBODY-ELSES-TENANT-ID00');

    $result = migrateAs($job, 'worker-one');

    expect($result->succeeded)->toBeFalse()
        ->and((string) $result->errorClass)->toBe(TenantIdentityMismatch::class)
        ->and((string) $result->errorMessage)->toContain('SOMEBODY-ELSES-TENANT-ID00');

    // Nothing was applied, and the status was never moved - the check runs
    // first inside the scope, before the offline status write and before the
    // apply.
    expect(migrateFixtureApplier()->calls)->toBe([])
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(0)
        ->and((string) migrateFixtureMappingRow($tenant)['status'])->toBe('active')
        // Recorded, which is the other half of the criterion.
        ->and((string) migrateFixtureMappingRow($tenant)['last_migration_error'])->toContain('Nothing was applied');
});

it('proceeds when the schema_meta row names this tenant', function () {
    // The control that stops the refusal above from passing against a check
    // that refuses whenever the table exists.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    migrateFixtureSchemaMeta('tenant-a', $tenant);

    expect(migrateAs($job, 'worker-one')->succeeded)->toBeTrue()
        ->and((int) migrateFixtureMappingRow($tenant)['schema_version'])->toBe(runFixtureBaselineVersion());
});

it('proceeds when the database does not say who it is', function () {
    // The shipped path, and the second control: the table exists and holds no
    // identity row, which is what the baseline leaves behind. A check that
    // refused on absence would refuse every tenant on the platform.
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'));
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), runFixtureBaselineVersion());

    migrateFixtureSchemaMeta('tenant-a', null);

    expect(migrateAs($job, 'worker-one')->succeeded)->toBeTrue();
});
