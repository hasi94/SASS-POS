<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The tenant baseline, applied to a stand-in connection
|--------------------------------------------------------------------------
|
| T-008 step 4. **This is not AC-22 to AC-30's evidence and must not be recorded
| as it.** Those are [E] criteria, answered from information_schema on the engine
| the environment names, and while ENGINE_HOLD is set they are NOT VERIFIED,
| waiting on gate G-1 (AC-63, D-35).
|
| What is answered here needs no engine, and most of it is behaviour rather than
| shape - which is the half a column list cannot see:
|
| - every file of the shipped tenant tree applies to an empty database, in
|   order - T-008's six, and T-010's eight from step 3;
| - exactly the tables tenantTreeTables() declares exist afterwards, plus
|   Laravel's own `migrations`, and `schema_meta` is **empty** (D-28);
| - no column named `tenant_id` exists anywhere in the applied schema (AC-24);
| - the two stored sentinels **work**: a second company-wide row for the same
|   key is refused, and a second company-wide `sale` sequence is refused, which
|   is the duplicate that lets two sales take one number (D-6, AC-25, AC-26);
| - the foreign-key inventory of the WHOLE tree is exactly the declared list,
|   none of it references a table named `branches`, and the columns D-5 and
|   T-010 D-30 leave unguarded exist, are nullable where the design says so,
|   and reference nothing (T-008 AC-30, T-010 AC-9 and AC-12a);
| - a business row and its outbox event commit or roll back together (AC-28).
|
| The stand-in is a private in-memory SQLite connection this file configures, and
| every case asserts that it IS SQLite before migrating. SQLite agrees with the
| engine on the one property the sentinels are about - NULLs are distinct in a
| unique index - which is why the refusals above are meaningful here at all. It
| disagrees about `ascii_bin`, ADD CONSTRAINT and auto-increment, each of which
| the migrations skip with a stated reason.
|
| Nothing in this file can reach the engine: it names one connection, that
| connection is SQLite, and the assertion comes before the migration.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * The name of the stand-in connection. Not `tenant`: that stanza is T-003's
 * sentinel, with no host and no database, and a test that quietly filled it in
 * would be testing something else.
 */
function tenantStandIn(): string
{
    return 'tenant_baseline_stand_in';
}

/**
 * Configure the stand-in and apply the baseline to it.
 *
 * The tree's location comes from tenantMigrationPath(), which reads
 * config/schema.php's `tenant_migration_path` - the one authority for it since
 * step 5. This file used to carry the directory name as a literal, twice.
 */
function applyTenantBaseline(): void
{
    config()->set('database.connections.'.tenantStandIn(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        // Without this SQLite silently ignores every foreign key, and AC-30's
        // control - an unknown settings key is refused - would pass whatever the
        // schema said.
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.tenantStandIn().'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => tenantStandIn(),
        '--path' => tenantMigrationPath(),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * Every table in the applied stand-in schema.
 *
 * Filtered in PHP, never with a SQL `LIKE`: `_` is a single-character wildcard
 * there, and that habit is what made `SHOW DATABASES LIKE 'flexipos_%'` return
 * the owner's real databases (D-30, D-34).
 *
 * @return list<string>
 */
function tenantStandInTables(): array
{
    $tables = [];

    foreach (DB::connection(tenantStandIn())->select("select name from sqlite_master where type = 'table'") as $row) {
        $name = (string) ((array) $row)['name'];

        if (str_starts_with($name, 'sqlite_')) {
            continue;
        }

        $tables[] = $name;
    }

    sort($tables);

    return $tables;
}

/*
|--------------------------------------------------------------------------
| It applies, and it holds exactly what AC-23 names
|--------------------------------------------------------------------------
*/

it('applies the tenant tree to an empty database and creates exactly its tables', function () {
    applyTenantBaseline();

    // Whole-tree (T-010 Ruling 2): this case applies the DIRECTORY, so the
    // set it must equal is the directory's, not the baseline subset's.
    $expected = array_merge(tenantTreeTables(), ['migrations']);
    sort($expected);

    // Exact equality: "and nothing else" is half of AC-23, and it is the half a
    // projection-state table would slip through (D-1).
    expect(tenantStandInTables())->toBe($expected);

    // A second run changes nothing and exits 0.
    expect(Artisan::call('migrate', [
        '--database' => tenantStandIn(),
        '--path' => tenantMigrationPath(),
        '--force' => true,
    ]))->toBe(0)
        ->and(tenantStandInTables())->toBe($expected);
});

it('leaves schema_meta empty, because T-008 writes no row into it', function () {
    applyTenantBaseline();

    // D-14 and D-28. The consequence is the one that must not be read as
    // delivered: AC-56's "a database with no such row proceeds" is the shipped
    // path, not an edge case, and the writer is FP-206's.
    expect(DB::connection(tenantStandIn())->table('schema_meta')->count())->toBe(0);

    // The control: the table is writable, so "empty" is a fact about the
    // baseline rather than about an unreadable table.
    DB::connection(tenantStandIn())->table('schema_meta')->insert([
        'key' => 'control.key',
        'value' => 'control',
    ]);

    expect(DB::connection(tenantStandIn())->table('schema_meta')->count())->toBe(1);
});

it('has no tenant_id column in any table of the tenant tree', function () {
    applyTenantBaseline();

    $offenders = [];

    foreach (tenantTreeTables() as $table) {
        $columns = array_column(Schema::connection(tenantStandIn())->getColumns($table), 'name');

        if (in_array('tenant_id', $columns, true)) {
            $offenders[] = $table;
        }
    }

    expect($offenders)->toBe([]);

    // The control, because an empty list is also what an unread schema returns:
    // the columns really are being listed, and the owning tenant's id lives in
    // `schema_meta` as a row rather than as a column (AC-24).
    expect(array_column(Schema::connection(tenantStandIn())->getColumns('schema_meta'), 'name'))
        ->toBe(['key', 'value']);
});

/*
|--------------------------------------------------------------------------
| The two sentinels, proven by the duplicate they refuse
|--------------------------------------------------------------------------
*/

it('refuses a second company-wide settings row for the same key', function () {
    applyTenantBaseline();

    DB::connection(tenantStandIn())->table('settings_registry')->insert([
        ['key' => 'pos.theme', 'module' => 'pos', 'type' => 'string'],
        ['key' => 'pos.currency', 'module' => 'pos', 'type' => 'string'],
    ]);

    // Each row gets its own id, so the only key the second insert can violate is
    // the one under test. A fixture that reused the id would be refused by the
    // primary key and the sentinel could be deleted without this test noticing.
    $row = static fn (string $id, string $key, ?string $scopeId): array => [
        'id' => str_pad($id, 26, 'x'),
        'scope_type' => 'company',
        'scope_id' => $scopeId,
        'key' => $key,
        'value' => '"dark"',
        'created_at' => '2026-10-14 00:00:00.000',
        'updated_at' => '2026-10-14 00:00:00.000',
    ];

    DB::connection(tenantStandIn())->table('settings')->insert($row('set1', 'pos.theme', null));

    // The duplicate the sentinel exists to catch: both rows have a NULL
    // scope_id, which a unique key over (scope_type, scope_id, key) would treat
    // as two distinct values and accept. The expected message names
    // `scope_id_key`, so what refused the row is the sentinel's key and not some
    // other constraint that happens to be in the way.
    expect(fn () => DB::connection(tenantStandIn())->table('settings')->insert($row('set2', 'pos.theme', null)))
        ->toThrow(QueryException::class, 'scope_id_key');

    // And the accepted case, which is the assertion that carries the proof:
    // dropping the sentinel column makes the refusal above disappear, but it
    // also makes *this* insert fail - so a schema that merely refuses
    // everything cannot pass both.
    DB::connection(tenantStandIn())->table('settings')->insert($row('set3', 'pos.currency', null));

    expect(DB::connection(tenantStandIn())->table('settings')->count())->toBe(2);
});

it('refuses a second company-wide sequence for one document type', function () {
    applyTenantBaseline();

    $branch = str_pad('branch', 26, 'b');

    $row = static fn (string $id, ?string $branchId, string $type): array => [
        'id' => str_pad($id, 26, 'x'),
        'branch_id' => $branchId,
        'document_type' => $type,
        'next_number' => 1,
        'reset_period' => 'never',
        'created_at' => '2026-10-14 00:00:00.000',
        'updated_at' => '2026-10-14 00:00:00.000',
    ];

    DB::connection(tenantStandIn())->table('number_sequences')->insert($row('seq1', null, 'sale'));

    // Two company-wide `sale` sequences is the defect in its most expensive
    // form: each row allocates its own numbers, so two sales take the same
    // number and the shop has two documents with one identity.
    expect(fn () => DB::connection(tenantStandIn())->table('number_sequences')->insert($row('seq2', null, 'sale')))
        ->toThrow(QueryException::class, 'branch_id_key');

    // The accepted cases: a branch row for the same type, and a company-wide row
    // for a different type.
    DB::connection(tenantStandIn())->table('number_sequences')->insert($row('seq3', $branch, 'sale'));
    DB::connection(tenantStandIn())->table('number_sequences')->insert($row('seq4', null, 'refund'));

    // And the branch row's own duplicate is refused too.
    expect(fn () => DB::connection(tenantStandIn())->table('number_sequences')->insert($row('seq5', $branch, 'sale')))
        ->toThrow(QueryException::class, 'branch_id_key');

    expect(DB::connection(tenantStandIn())->table('number_sequences')->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The foreign-key inventory of the whole tree, and the columns that owe one
|--------------------------------------------------------------------------
|
| **Widened from the baseline six to the whole tree by T-010 (AC-12a, Ruling
| 2), and the widening is deliberate.** Read narrowly this sweep would have
| stayed green forever while the tree grew around it - shape 7, an instrument
| that discriminates perfectly over a domain that has stopped being the one
| that matters. Two things made an edit unavoidable anyway: T-010 adds eight
| tables with foreign keys of their own, and AC-9 asks for an assertion that
| **no** key in the new tables references a table named `branches`, which a
| six-table loop cannot answer.
|
| The inventory is declared as an explicit sorted list, not a count: the
| criterion is about which keys exist. It is stronger than what T-008
| asserted, not weaker, and T-008's own claim - `settings.key` ->
| `settings_registry` is in the list, and an unknown settings key is refused -
| is still here, in this case and the one after it.
|
*/

/**
 * Every foreign key in the applied tenant schema, as `table.column ->
 * table.column`, sorted.
 *
 * @return list<string>
 */
function tenantTreeForeignKeys(): array
{
    $references = [];

    foreach (tenantTreeTables() as $table) {
        foreach (DB::connection(tenantStandIn())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $references[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'];
        }
    }

    sort($references);

    return $references;
}

it('holds exactly the declared foreign keys, none of them onto branches', function () {
    applyTenantBaseline();

    // The whole inventory, in both directions. `settings.updated_by ->
    // employees.id` is T-011's contract step, the one key T-008 D-5 owed to
    // the employees module; any other new key onto `employees` turns this
    // exact list red.
    expect(tenantTreeForeignKeys())->toBe([
        'access_log.employee_id -> employees.id',
        'employee_branches.employee_id -> employees.id',
        'employee_permissions.employee_id -> employees.id',
        'employee_permissions.permission_key -> permissions.key',
        'employee_roles.employee_id -> employees.id',
        'employee_roles.granted_by -> employees.id',
        'employee_roles.role_id -> roles.id',
        'employees.created_by -> employees.id',
        'employees.updated_by -> employees.id',
        'role_permissions.permission_key -> permissions.key',
        'role_permissions.role_id -> roles.id',
        'settings.key -> settings_registry.key',
        'settings.updated_by -> employees.id',
    ]);

    // AC-9, said as a property rather than as a consequence of the list
    // above: nothing references `branches`, and no such table exists to be
    // referenced. Both halves, because a key onto a table that does not exist
    // is the mistake this is guarding against.
    $ontoBranches = array_values(array_filter(
        tenantTreeForeignKeys(),
        static fn (string $reference): bool => str_contains($reference, '-> branches.')
    ));

    expect($ontoBranches)->toBe([])
        ->and(tenantStandInTables())->not->toContain('branches');
});

it('leaves the unguarded identifier columns in place, and typed as identifiers', function () {
    applyTenantBaseline();

    // D-5's three that remain, plus T-010 D-30's three. Each migration docblock names the
    // module that owes the key; this is the assertion that the column was not
    // quietly dropped instead, and that its nullability is the one the design
    // states - `employee_branches.branch_id` is NOT NULL, because a row there
    // IS an attachment to one branch, while the other two carry "all
    // branches" as NULL.
    $unguarded = [
        'number_sequences.branch_id' => true,
        'audit_logs.branch_id' => true,
        'outbox_events.branch_id' => true,
        'employee_roles.branch_id' => true,
        'employee_permissions.branch_id' => true,
        'employee_branches.branch_id' => false,
        'access_log.branch_id' => true,
    ];

    $found = [];

    foreach ($unguarded as $site => $nullable) {
        [$table, $column] = explode('.', $site);

        $columns = collect(Schema::connection(tenantStandIn())->getColumns($table))->keyBy('name');

        expect($columns)->toHaveKey($column);

        $found[$site] = $columns->get($column)['nullable'];
    }

    // Published as a map, so a failure names the column rather than a count,
    // and so a reader can see which domain was examined (D-27).
    expect($found)->toBe($unguarded)
        ->and($found)->toHaveCount(7);

    // `settings.updated_by` left this map when T-011 gave it its key; it is
    // still a nullable identifier, typed as the other identifier columns are.
    $settings = collect(Schema::connection(tenantStandIn())->getColumns('settings'))->keyBy('name');

    expect($settings->get('updated_by')['nullable'] ?? null)->toBeTrue()
        ->and($settings->get('updated_by')['type'] ?? null)->toBe($settings->get('scope_id')['type'] ?? 'missing');
});

it('refuses a settings row whose key is not in the registry', function () {
    applyTenantBaseline();

    // AC-30's control. Without `foreign_key_constraints` SQLite would accept
    // this silently, which is why the stand-in sets it.
    expect(fn () => DB::connection(tenantStandIn())->table('settings')->insert([
        'id' => str_pad('unknown', 26, 'x'),
        'scope_type' => 'company',
        'scope_id' => null,
        'key' => 'nobody.declared.this',
        'value' => '"x"',
        'created_at' => '2026-10-14 00:00:00.000',
        'updated_at' => '2026-10-14 00:00:00.000',
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The outbox is the seam, which means it shares the transaction
|--------------------------------------------------------------------------
*/

it('writes a business row and its event in one transaction, or neither', function () {
    applyTenantBaseline();

    DB::connection(tenantStandIn())->table('settings_registry')->insert([
        'key' => 'pos.theme', 'module' => 'pos', 'type' => 'string',
    ]);

    $setting = static fn (string $id): array => [
        'id' => str_pad($id, 26, 'x'),
        'scope_type' => 'company',
        'scope_id' => null,
        'key' => 'pos.theme',
        'value' => '"dark"',
        'created_at' => '2026-10-14 00:00:00.000',
        'updated_at' => '2026-10-14 00:00:00.000',
    ];

    $event = static fn (string $id): array => [
        'id' => str_pad($id, 26, 'x'),
        'type' => 'settings.changed',
        'version' => 1,
        'aggregate_type' => 'setting',
        'aggregate_id' => str_pad('agg', 26, 'x'),
        'payload' => '{"key":"pos.theme"}',
        'occurred_at' => '2026-10-14 03:04:05.678',
    ];

    // Rolled back: neither exists.
    DB::connection(tenantStandIn())->beginTransaction();
    DB::connection(tenantStandIn())->table('settings')->insert($setting('rolled'));
    DB::connection(tenantStandIn())->table('outbox_events')->insert($event('rolled'));
    DB::connection(tenantStandIn())->rollBack();

    expect(DB::connection(tenantStandIn())->table('settings')->count())->toBe(0)
        ->and(DB::connection(tenantStandIn())->table('outbox_events')->count())->toBe(0);

    // Committed: both exist, and the event defaults to pending with no attempts,
    // so a producer that writes only the business columns still produces a
    // publishable event (AC-27).
    DB::connection(tenantStandIn())->transaction(function () use ($setting, $event) {
        DB::connection(tenantStandIn())->table('settings')->insert($setting('kept'));
        DB::connection(tenantStandIn())->table('outbox_events')->insert($event('kept'));
    });

    $written = DB::connection(tenantStandIn())->table('outbox_events')->first();

    expect(DB::connection(tenantStandIn())->table('settings')->count())->toBe(1)
        ->and($written)->not->toBeNull()
        ->and(((array) $written)['status'])->toBe('pending')
        ->and(((array) $written)['attempts'])->toBe(0);
});
