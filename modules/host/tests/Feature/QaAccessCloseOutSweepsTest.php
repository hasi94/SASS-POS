<?php

use Flexipos\Access\Features\AssignRole\AssignRole;
use Flexipos\Access\Features\AssignRole\AssignRoleHandler;
use Flexipos\Access\Features\AttachBranch\AttachBranch;
use Flexipos\Access\Features\AttachBranch\AttachBranchHandler;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatus;
use Flexipos\Access\Features\ChangeEmployeeStatus\ChangeEmployeeStatusHandler;
use Flexipos\Access\Features\CreateEmployee\CreateEmployee;
use Flexipos\Access\Features\CreateEmployee\CreateEmployeeHandler;
use Flexipos\Access\Features\CreateRole\CreateRole;
use Flexipos\Access\Features\CreateRole\CreateRoleHandler;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissions;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissionsHandler;
use Flexipos\Access\Features\ListEmployees\ListEmployees;
use Flexipos\Access\Features\ListEmployees\ListEmployeesHandler;
use Flexipos\Access\Features\ListRoles\ListRoles;
use Flexipos\Access\Features\ListRoles\ListRolesHandler;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission;
use Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermissionHandler;
use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistry;
use Flexipos\Access\Features\SyncPermissionRegistry\SyncPermissionRegistryHandler;
use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;

/*
|--------------------------------------------------------------------------
| T-010 step 24 - the close-out sweeps
|--------------------------------------------------------------------------
|
| AC-2's query-log half (no tenant predicate on any new table, and the
| cross-tenant control), AC-73 (nothing master-side is written), AC-11's
| query-log half (the limit deptrac cannot reach: a table name in a
| string) and Ruling 7's sweep over this task's own test tree.
|
| **Every absence here has a liveness control, because an empty log
| satisfies every "no query did X" perfectly.** Each scan is extracted as
| a function and RUN TWICE: once over the statements this task's
| operations really issued, and once over a statement planted to be
| caught. A scan that found the planted one and nothing else is a scan
| that works; a scan that found nothing either way proves nothing.
|
| **The bound of AC-2's query-log half, stated rather than implied.** The
| criterion says "over a full run of this task's tests"; what runs here is
| a representative pass over every one of the eight new tables, not the
| whole suite - there is no hook that could collect one connection's
| statements across every file. What closes the rest is structural: no
| column is named `tenant_id` (AC-3 lists the columns live), so a tenant
| predicate would have to name a column that does not exist and would
| fail rather than filter.
|
| No engine: the master is the in-memory SQLite stand-in and the tenants
| are files under storage/app. Nothing reaches 127.0.0.1:3306, on hold.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

beforeEach(function () {
    // The master schema is built, so AC-73's scan runs against a master
    // that really could be written to. Against an empty stand-in, "no
    // master write" would also be what a write that *threw* produced,
    // and the two must not look alike.
    authFixtureMigrateMaster();

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

/**
 * The tables this task added, derived from the two helpers rather than
 * listed again (FP-181).
 *
 * @return list<string>
 */
function closeOutNewTables(): array
{
    // T-014's five tables are excluded by name: this is T-010's sweep and its
    // claim is about T-010's eight tables. The five are scanned by T-014's own
    // tenant-isolation test.
    $t014 = ['branches', 'devices', 'locations', 'terminal_credentials', 'terminals'];

    // T-017's one table (audit_logs_archive) is excluded the same way: this
    // sweep's exercise never touches it (nothing archives inside
    // closeOutExerciseEveryTable()), so it would otherwise be reported as a
    // T-010 table this run never saw a statement against.
    $t017 = ['audit_logs_archive'];

    // T-019's three tables are excluded the same way: this sweep's exercise
    // never writes a plugin execution log, migration record or setting.
    $t019 = ['plugin_execution_logs', 'plugin_migrations', 'plugin_settings'];

    // T-020's five tables are excluded the same way: this sweep's exercise
    // never writes a workflow definition, instance, task or event.
    $t020 = ['workflow_definitions', 'workflow_events', 'workflow_instances', 'workflow_steps', 'workflow_tasks'];

    // T-021's one table (idempotency_keys) is excluded the same way: this
    // sweep's exercise never writes an idempotency row.
    $t021 = ['idempotency_keys'];

    return array_values(array_diff(tenantTreeTables(), tenantBaselineTables(), $t014, $t017, $t019, $t020, $t021));
}

/**
 * Run something inside a fixture tenant, then leave (Ruling 7).
 */
function closeOutInTenant(string $tenant, callable $work): mixed
{
    AccessTenantFixture::switchTo($tenant);

    try {
        return $work();
    } finally {
        tenancy()->leave();
    }
}

/**
 * A representative pass over every one of the eight new tables, run
 * inside whichever tenant is current.
 *
 * It is the operations and not a hand-written list of queries, because
 * what AC-2 is about is what the application issues, not what a test can
 * imagine it issuing.
 */
function closeOutExerciseEveryTable(): void
{
    /** @var SyncPermissionRegistryHandler $sync */
    $sync = app(SyncPermissionRegistryHandler::class);
    $sync->handle(new SyncPermissionRegistry);

    $key = AccessSeed::permission('fixture.a');
    $branch = AccessSeed::branch();
    $role = AccessSeed::role('shift_lead', [$key]);
    $subject = AccessSeed::employee('subject');
    $actor = AccessSeed::actorHolding('actor', array_merge([$key], ['employees.manage', 'roles.manage', 'access.assign', 'employees.set_pin']));

    app(CreateRoleHandler::class)->handle(new CreateRole('tidy', 'Tidy', null, $actor));
    app(CreateEmployeeHandler::class)->handle(new CreateEmployee(code: 'new-emp', firstName: 'New', actorId: $actor));
    app(AttachBranchHandler::class)->handle(new AttachBranch($subject, $branch, false, $actor));
    app(AssignRoleHandler::class)->handle(new AssignRole($subject, $role, $branch, $actor));
    app(SetEmployeePermissionHandler::class)->handle(new SetEmployeePermission($subject, $key, true, null, $actor));
    app(SetPinHandler::class)->handle(new SetPin($subject, '7391', $actor));
    app(VerifyPinHandler::class)->handle(new VerifyPin('subject', '7391'));
    app(ChangeEmployeeStatusHandler::class)->handle(new ChangeEmployeeStatus($subject, EmployeeStatus::ON_LEAVE, $actor));
    app(ListRolesHandler::class)->handle(new ListRoles);
    app(ListEmployeesHandler::class)->handle(new ListEmployees);
    app(GetMyPermissionsHandler::class)->handle(new GetMyPermissions($subject));
}

/**
 * The scan AC-2 is: no statement touching a new table carries a tenant
 * predicate.
 *
 * Extracted so it can be run against a planted statement as well as
 * against the real log. It returns what it examined, so a caller can
 * assert the domain was not empty.
 *
 * @param  list<string>  $statements
 * @return list<string> the statements that touched a new table
 */
function closeOutScanForTenantPredicate(array $statements): array
{
    $tables = closeOutNewTables();
    $touching = [];

    foreach ($statements as $statement) {
        $mentions = false;

        foreach ($tables as $table) {
            if (str_contains($statement, '"'.$table.'"') || str_contains($statement, '`'.$table.'`')) {
                $mentions = true;

                break;
            }
        }

        if (! $mentions) {
            continue;
        }

        $touching[] = $statement;

        // `tenant_id` is the column D-1 forbids; `tenant` on its own
        // would match `tenant_users`, which is a master table and not
        // this scan's business.
        expect($statement)->not->toContain('tenant_id');
    }

    return $touching;
}

/**
 * Every statement one connection issued while the work ran.
 *
 * @return list<string>
 */
function closeOutStatementsOf(string $connection, callable $work): array
{
    $handle = DB::connection($connection);

    $handle->flushQueryLog();
    $handle->enableQueryLog();

    try {
        $work();
    } finally {
        $handle->disableQueryLog();
    }

    return array_map(
        static fn (array $entry): string => (string) $entry['query'],
        $handle->getQueryLog()
    );
}

it('issues no query carrying a tenant predicate against any new table (AC-2, AC-11)', function () {
    $statements = closeOutInTenant('tenant-a', static fn (): array => closeOutStatementsOf(
        AccessTenantFixture::connectionName(),
        static fn () => closeOutExerciseEveryTable()
    ));

    $touching = closeOutScanForTenantPredicate($statements);

    $tables = closeOutNewTables();
    $seen = [];

    foreach ($tables as $table) {
        foreach ($touching as $statement) {
            if (str_contains($statement, '"'.$table.'"')) {
                $seen[] = $table;

                break;
            }
        }
    }

    fwrite(STDOUT, PHP_EOL.'AC-2 query-log scan: '.count($statements).' statements, '
        .count($touching).' touching a new table, tables seen: '.implode(', ', $seen).PHP_EOL);

    // The liveness half, and it is what makes the absence mean
    // something: the scan really saw statements, and it saw at least one
    // against every new table. A log the probe failed to collect
    // satisfies "no tenant predicate" perfectly.
    expect($statements)->not->toBe([])
        ->and($touching)->not->toBe([])
        ->and($seen)->toEqualCanonicalizing($tables);
});

it('reports a planted tenant predicate, which is what makes the absence a measurement (AC-2)', function () {
    // The same scan, over a statement written to be caught. Without
    // this, "nothing carried a tenant predicate" is equally consistent
    // with a scan that cannot see one.
    expect(fn () => closeOutScanForTenantPredicate([
        'select * from "employees" where "tenant_id" = ?',
    ]))->toThrow(AssertionFailedError::class);

    // And it does not fire on a statement that merely mentions a master
    // table whose name starts the same way, which is the false positive
    // that would make the scan useless.
    expect(closeOutScanForTenantPredicate(['select * from "tenant_users" where "tenant_id" = ?']))->toBe([]);
});

it('writes nothing to the master database (AC-73)', function () {
    $writes = [];

    $statements = closeOutInTenant('tenant-a', static fn (): array => closeOutStatementsOf(
        'master',
        static fn () => closeOutExerciseEveryTable()
    ));

    foreach ($statements as $statement) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $statement) === 1) {
            $writes[] = $statement;
        }
    }

    // T-009's selection and refresh rows are not produced here: this
    // runs the access operations only, which is exactly the set AC-73
    // names ("apart from the rows T-009 already writes").
    expect($writes)->toBe([]);

    // Liveness: the probe is watching a connection that really can
    // report a write, shown by making one through it.
    $planted = closeOutStatementsOf('master', static function (): void {
        authFixtureTenant('liveness');
    });

    $plantedWrites = array_values(array_filter(
        $planted,
        static fn (string $statement): bool => preg_match('/^\s*(insert|update|delete)\b/i', $statement) === 1
    ));

    expect($plantedWrites)->not->toBe([], 'the master probe saw no write it was shown');
});

it('never reaches another tenant s rows, with the same employee id in both (AC-2, cross-tenant control)', function () {
    $employeeId = (string) Str::ulid();

    // The same id in both shops, with different rights: if an operation
    // in A ever reached B, the two would have to be told apart by
    // something other than the connection, and there is nothing else -
    // no `tenant_id` column exists to tell them apart with.
    foreach (['tenant-a', 'tenant-b'] as $tenant) {
        closeOutInTenant($tenant, static function () use ($employeeId, $tenant): void {
            app(SyncPermissionRegistryHandler::class)->handle(new SyncPermissionRegistry);

            AccessSeed::permission('fixture.a');
            AccessSeed::employee('shared', ['id' => $employeeId]);
            AccessSeed::assign($employeeId, AccessSeed::role('role_'.str_replace('-', '_', $tenant), ['fixture.a']));
        });
    }

    $countsInB = static fn (): array => closeOutInTenant('tenant-b', static function (): array {
        $counts = [];

        foreach (closeOutNewTables() as $table) {
            $counts[$table] = AccessSeed::connection()->table($table)->count();
        }

        $counts['perm_version'] = AccessSeed::permVersion(
            (string) AccessSeed::connection()->table('employees')->where('code', 'shared')->value('id')
        );

        return $counts;
    });

    $before = $countsInB();

    closeOutInTenant('tenant-a', static function () use ($employeeId): void {
        $actor = AccessSeed::actorHolding('actor', ['fixture.a', 'access.assign']);

        app(SetEmployeePermissionHandler::class)
            ->handle(new SetEmployeePermission($employeeId, 'fixture.a', false, null, $actor));
    });

    $after = $countsInB();

    expect($after)->toBe($before);

    // The liveness half: B's counts are not frozen by accident - a write
    // made IN B moves them, so "unchanged" above is a fact about where
    // the write went.
    closeOutInTenant('tenant-b', static function () use ($employeeId): void {
        AccessSeed::personal($employeeId, 'fixture.a', false);
    });

    expect($countsInB())->not->toBe($before);
});

/**
 * Every test file that belongs to this task.
 *
 * **The domain is found, not listed** (D-27). This package's own test
 * tree is one half; the other is the host's own tests that reach a
 * fixture tenant, and those are found by the reference they must make -
 * `AccessTenantFixture` - rather than by a name pattern or a list here.
 * A new T-010 host test that touches a tenant is therefore in the domain
 * on the day it is written.
 *
 * @return array<string, string> path => source
 */
function closeOutTaskTestFiles(): array
{
    $files = [];

    $roots = [
        dirname(__DIR__, 4).'/packages/access/tests',
        dirname(__DIR__, 1).'/Feature',
    ];

    foreach ($roots as $root) {
        /** @var iterable<SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($found as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($root, 'packages') || str_contains($source, 'AccessTenantFixture')) {
                $files[$file->getPathname()] = $source;
            }
        }
    }

    ksort($files);

    return $files;
}

/**
 * Ruling 7, as an assertion: a test of this task that asserts a database
 * constraint reaches the database through `AccessTenantFixture`.
 *
 * **Why it is a standing property and not a fixture detail.** The stanza
 * `Tenancy` writes carries no `foreign_key_constraints` key, and SQLite
 * leaves foreign keys off unless told otherwise - so a tenant entered
 * with a plain `tenancy()->enter()` ignores every `REFERENCES` clause in
 * the tree. A test that built its own connection and asserted a foreign
 * key on the stand-in would be asserting something the database was
 * never going to refuse. The real fix is the stanza and is a filed row;
 * what keeps this task's evidence honest in the meantime is that every
 * such test goes through one door.
 *
 * **The marker is `QueryException`, and the first marker I chose was
 * wrong.** "Asserts a foreign key" looks like it should be found by
 * searching for the words - and a scan for `FOREIGN KEY` matched
 * exactly one file in this task: *this* one, because of the control
 * string below. The real assertions are
 * `->toThrow(QueryException::class)` after an insert that violates a
 * key, and they contain none of the words. So the marker is the
 * exception type a violated constraint surfaces as, which also catches
 * unique-key assertions - deliberate over-inclusion: a unique-key test
 * is no worse for going through the enforcing door.
 *
 * **Stated residual: the granularity is the file.** This cannot see a
 * file that enters through the fixture in one case and laxly in
 * another. Exactly one file does both, and it is the one whose subject
 * *is* the laxness; it reaches it through
 * the fixture's named lax door, whose caller count is asserted by the
 * next case. A finer rule would need the call
 * graph rather than the file, which is a static-analysis rule and not a
 * test.
 *
 * @param  array<string, string>  $files  path => source
 * @return list<string> the files that assert a constraint
 */
function assertConstraintTestsGoThroughTheFixture(array $files): array
{
    $asserting = [];

    foreach ($files as $path => $source) {
        if (! str_contains($source, 'QueryException')) {
            continue;
        }

        $asserting[] = basename($path);

        expect(
            str_contains($source, 'AccessTenantFixture::enter(')
            || str_contains($source, 'AccessTenantFixture::switchTo(')
        )->toBeTrue(
            basename($path).' asserts a database constraint without entering through AccessTenantFixture, '
            .'where the stand-in enforces no foreign key (Ruling 7)'
        );
    }

    return $asserting;
}

/**
 * Every test file that calls the fixture's lax door.
 *
 * @param  array<string, string>  $files  path => source
 * @return list<string>
 */
function closeOutLaxDoorCallers(array $files): array
{
    // Split, because this file is inside the domain it scans: written
    // whole, the needle would match the needle and this file would
    // report itself as a caller. Splitting it is the alternative to
    // excluding this file by name, which is the allow-list entry a
    // scanner should never grow.
    $needle = 'AccessTenantFixture::'.'enterWithoutEnforcement(';

    $callers = [];

    foreach ($files as $path => $source) {
        if (str_contains($source, $needle)) {
            $callers[] = basename($path);
        }
    }

    return $callers;
}

it('reaches every tenant constraint assertion through the fixture that enforces them (Ruling 7)', function () {
    $files = closeOutTaskTestFiles();
    $asserting = assertConstraintTestsGoThroughTheFixture($files);

    fwrite(STDOUT, PHP_EOL.'Ruling 7 sweep: '.count($files).' test files in this task, '
        .count($asserting).' asserting a database constraint:'.PHP_EOL
        .'  '.implode(PHP_EOL.'  ', $asserting).PHP_EOL);

    // Published and non-empty in both senses: the walk found files, and
    // some of them really do assert a constraint. A sweep over an empty
    // domain satisfies the rule perfectly - which is what the first
    // version of this case did, with a marker that matched nothing.
    expect($files)->not->toBe([])
        ->and(count($asserting))->toBeGreaterThan(1);
});

it('keeps the lax door to exactly one caller, the case that measures the gap (Ruling 7)', function () {
    $files = closeOutTaskTestFiles();
    $callers = closeOutLaxDoorCallers($files);

    // One named door, one caller. This is what makes the file-level
    // granularity of the case above safe: the one file that enters both
    // ways is visible here by name, and a second file acquiring the lax
    // entry is a failure rather than a diff nobody read.
    expect($callers)->toBe(['AccessTenantFixtureTest.php']);
});

it('reports a constraint test that builds its own lax connection (Ruling 7, control)', function () {
    // The same scan over a file that does the forbidden thing. Without
    // it, the green above is equally consistent with a scan that never
    // recognises a constraint assertion at all - which is exactly what
    // the first version of this scan did.
    expect(fn () => assertConstraintTestsGoThroughTheFixture([
        '/fixture/LaxForeignKeyTest.php' => '<?php tenancy()->enter("tenant-a"); '
            .'expect(fn () => insert())->toThrow(QueryException::class);',
    ]))->toThrow(AssertionFailedError::class);

    // And it accepts the same file once it goes through the fixture, so
    // the rule is about the door and not about the words.
    expect(assertConstraintTestsGoThroughTheFixture([
        '/fixture/StrictForeignKeyTest.php' => '<?php AccessTenantFixture::enter("tenant-a"); '
            .'expect(fn () => insert())->toThrow(QueryException::class);',
    ]))->toBe(['StrictForeignKeyTest.php']);
});
