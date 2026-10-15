<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-009 step 3, AC-50: the four authentication tables and users.email_lower
|--------------------------------------------------------------------------
|
| **Written in the same commit as the five migrations and red before them.**
| Every case below describes a table, so before the migrations exist there is
| nothing to describe and each case fails on an absent subject - not on a guard
| stubbed to allow something. That is the red phase this step is allowed to
| have: no mechanism is weakened to produce it, and nothing here can write
| outside the in-memory stand-in.
|
| **What this is and is not.** AC-50's cross-engine comparison is FP-194 and
| stays deferred; the MySQL half of AC-2 (the connection collation) is [ENGINE]
| and NOT VERIFIED behind ENGINE_HOLD. What is asserted here is the half the
| stand-in can answer and that nothing else in the branch would notice: the
| column and key names `docs/database.md` section 4.1 lists, the declared
| foreign key, the two BIGINT AUTO surrogate keys of D-28, and that
| `users.email_lower` is a stored generated column whose unique index refuses a
| case variant.
|
| **The row-count dependency, recorded here because a later failure will read
| as the wrong thing otherwise.** SQLite refuses
| `ALTER TABLE ... ADD COLUMN ... STORED` with "cannot add a STORED column" as
| soon as the table holds one row, and accepts it again after a DELETE; it is a
| row-count check, not a static restriction, and MySQL has none of it. So
| migration ...000205 is safe only because the stand-in migrates into a fresh,
| empty database, and that ordering is a load-bearing property of the stand-in
| rather than an accident. **No test may seed `users` and then run the expand
| migration**; a test that needs both migrates first and seeds second, which is
| what the duplicate case below does. No case here provokes the refusal,
| because provoking it would mean seeding before the expand phase - exactly the
| thing the ordering forbids.
|
| The connection is asserted to be the stand-in before anything migrates, for
| the reason MasterSchemaBuildsTest gives: an ambient DB_MASTER_DRIVER beats
| phpunit.xml, and the shared engine is on hold. The guard runs before
| `migrate` is called, so this file is structurally incapable of writing to
| that server.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

beforeEach(function () {
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
});

/**
 * One master table's column names, sorted.
 *
 * @return list<string>
 */
function authColumns(string $table): array
{
    $names = array_map(
        static fn (array $column): string => (string) $column['name'],
        Schema::connection('master')->getColumns($table)
    );

    sort($names);

    return $names;
}

/**
 * One master table's index labels, as "primary|unique|index:a,b", sorted.
 *
 * @return list<string>
 */
function authIndexes(string $table): array
{
    $labels = [];

    foreach (Schema::connection('master')->getIndexes($table) as $index) {
        $labels[] = ($index['primary'] ? 'primary' : ($index['unique'] ? 'unique' : 'index')).':'.implode(',', $index['columns']);
    }

    sort($labels);

    return $labels;
}

/**
 * One master table's declared foreign keys, as "local->table.foreign", sorted.
 *
 * SQLite does not enforce them on this stanza, so these are read as
 * declarations; the refusal is the engine's and is [ENGINE].
 *
 * @return list<string>
 */
function authForeignKeys(string $table): array
{
    $labels = [];

    foreach (Schema::connection('master')->getForeignKeys($table) as $key) {
        $labels[] = implode(',', $key['columns']).'->'.$key['foreign_table'].'.'.implode(',', $key['foreign_columns']);
    }

    sort($labels);

    return $labels;
}

/**
 * One column's description, or null when the table does not declare it.
 *
 * @return array<string, mixed>|null
 */
function authColumn(string $table, string $column): ?array
{
    foreach (Schema::connection('master')->getColumns($table) as $described) {
        if ($described['name'] === $column) {
            return $described;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| The four tables exist, with exactly the columns the design lists
|--------------------------------------------------------------------------
*/

it('creates the four authentication tables', function () {
    foreach (['signing_keys', 'refresh_tokens', 'login_history', 'security_events'] as $table) {
        expect(Schema::connection('master')->hasTable($table))->toBeTrue();
    }

    // The control: the reader can also say no. A table the design does not
    // have must come back absent, or "all four exist" is a fact about a
    // method that answers true to everything.
    expect(Schema::connection('master')->hasTable('mfa_factors'))->toBeFalse();
});

it('gives signing_keys the section 4.1 columns, keyed on the kid', function () {
    $expected = ['kid', 'algorithm', 'public_key', 'private_key_ref', 'status', 'created_at', 'retired_at'];
    sort($expected);

    expect(authColumns('signing_keys'))->toBe($expected)
        ->and(authIndexes('signing_keys'))->toContain('primary:kid')
        ->and(authIndexes('signing_keys'))->toContain('index:status');

    // `kid` is a JOSE header value, not an internal identifier: it is the one
    // id that leaves the system in a token header, so it is a VARCHAR and not
    // a ULID identifier column (D-28's companion reading).
    $kid = authColumn('signing_keys', 'kid');

    expect($kid)->not->toBeNull()
        // The length is the engine's to report: Laravel's SQLite grammar
    // emits a bare `varchar`, so what the stand-in can say is the family.
        ->and($kid['type_name'])->toBe('varchar')
        ->and($kid['nullable'])->toBeFalse();

    // The private key is a reference and never a value - the column name says
    // so, and MasterSchemaBuildsTest's credential-shaped scan is what holds
    // the `_ref` suffix to its meaning.
    expect(authColumns('signing_keys'))->not->toContain('private_key');
});

it('gives refresh_tokens the section 4.1 columns, the unique hash and the principal index', function () {
    $expected = [
        'id', 'principal_type', 'principal_id', 'tenant_id', 'token_hash', 'family_id',
        'expires_at', 'used_at', 'revoked_at', 'ip', 'user_agent', 'created_at', 'updated_at',
    ];
    sort($expected);

    expect(authColumns('refresh_tokens'))->toBe($expected)
        ->and(authIndexes('refresh_tokens'))->toContain('primary:id')
        // AC-32's uniqueness, and AC-50 names it in terms.
        ->and(authIndexes('refresh_tokens'))->toContain('unique:token_hash')
        ->and(authIndexes('refresh_tokens'))->toContain('index:principal_type,principal_id')
        // The rotation chain is read as a whole family on replay (AC-34), so
        // the family is indexed too.
        ->and(authIndexes('refresh_tokens'))->toContain('index:family_id')
        ->and(authForeignKeys('refresh_tokens'))->toBe(['tenant_id->tenants.id']);

    // The hash column is fixed width: SHA-256 hex is always 64 characters, and
    // a wider column would let something that is not a hash be stored.
    $hash = authColumn('refresh_tokens', 'token_hash');

    expect($hash)->not->toBeNull()
        ->and($hash['type_name'])->toBe('varchar')
        ->and($hash['nullable'])->toBeFalse();

    // A row other things refer to, whose id is minted alongside a secret: the
    // ULID identifier column, not a surrogate BIGINT (D-28).
    $id = authColumn('refresh_tokens', 'id');

    expect($id['type_name'])->toBe('varchar')
        ->and($id['auto_increment'])->toBeFalse();
});

it('refuses two refresh rows with the same token hash', function () {
    $connection = DB::connection('master');

    $row = static fn (string $id, string $hash): array => [
        'id' => str_pad($id, 26, 'R'),
        'principal_type' => 'user',
        'principal_id' => str_pad('u', 26, 'U'),
        'token_hash' => $hash,
        'family_id' => str_pad('f', 26, 'F'),
        'expires_at' => '2026-10-29 00:00:00.000',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ];

    $connection->table('refresh_tokens')->insert($row('one', str_repeat('a', 64)));

    // The control: a different hash is accepted, so the refusal below is the
    // key and not the insert shape.
    $connection->table('refresh_tokens')->insert($row('two', str_repeat('b', 64)));

    expect($connection->table('refresh_tokens')->count())->toBe(2)
        ->and(fn () => $connection->table('refresh_tokens')->insert($row('three', str_repeat('a', 64))))
        ->toThrow(QueryException::class)
        ->and($connection->table('refresh_tokens')->count())->toBe(2);
});

it('gives login_history the section 4.1 columns, a BIGINT AUTO key and the two indexes', function () {
    $expected = [
        'id', 'user_id', 'tenant_id', 'email', 'principal_type', 'success',
        'failure_reason', 'ip', 'user_agent', 'device_id', 'created_at',
    ];
    sort($expected);

    expect(authColumns('login_history'))->toBe($expected)
        ->and(authIndexes('login_history'))->toContain('index:email,created_at')
        ->and(authIndexes('login_history'))->toContain('index:user_id,created_at');

    // D-28: the table's own surrogate key is append-only and nothing cites it,
    // so it is BIGINT AUTO - the `platform_audit_logs` precedent.
    expect(authColumn('login_history', 'id')['auto_increment'])->toBeTrue();

    // The identifier rule governs references to another row's identity, and
    // these two are such references.
    expect(authColumn('login_history', 'user_id')['type_name'])->toBe('varchar')
        ->and(authColumn('login_history', 'user_id')['nullable'])->toBeTrue()
        ->and(authColumn('login_history', 'tenant_id')['type_name'])->toBe('varchar')
        ->and(authColumn('login_history', 'tenant_id')['nullable'])->toBeTrue();

    // A log outlives what it is about: no foreign key, for the reason
    // `platform_audit_logs` states.
    expect(authForeignKeys('login_history'))->toBe([]);
});

it('gives security_events the section 4.1 columns, a BIGINT AUTO key and a JSON detail', function () {
    $expected = ['id', 'type', 'user_id', 'tenant_id', 'actor_id', 'detail', 'ip', 'created_at'];
    sort($expected);

    expect(authColumns('security_events'))->toBe($expected)
        ->and(authColumn('security_events', 'id')['auto_increment'])->toBeTrue()
        ->and(authIndexes('security_events'))->toContain('index:type,created_at')
        ->and(authIndexes('security_events'))->toContain('index:user_id,created_at')
        ->and(authForeignKeys('security_events'))->toBe([]);

    foreach (['user_id', 'tenant_id', 'actor_id'] as $reference) {
        expect(authColumn('security_events', $reference)['type_name'])->toBe('varchar')
            ->and(authColumn('security_events', $reference)['nullable'])->toBeTrue();
    }

    // `detail` is JSON with an explicit validity check on the engine
    // (JsonValidity::enforce). SQLite has no ALTER TABLE ... ADD CONSTRAINT,
    // so the constraint is not applied here and no refusal is asserted; what
    // the stand-in can say is the declared type.
    // Laravel's SQLite grammar renders `json()` as TEXT unless the stanza asks
    // for native JSON, and MariaDB's JSON is itself an alias for LONGTEXT, so
    // the two engines agree here and the family is what is asserted.
    expect(authColumn('security_events', 'detail')['type_name'])->toBe('text');
});

/*
|--------------------------------------------------------------------------
| users.email_lower: the stored generated column and its unique index
|--------------------------------------------------------------------------
*/

it('adds email_lower to users as a stored generated column with its own unique index', function () {
    $column = authColumn('users', 'email_lower');

    expect($column)->not->toBeNull()
        ->and($column['generation'])->not->toBeNull()
        // STORED, not VIRTUAL: a virtual column cannot be indexed on every
        // engine, and the index is the whole point (section 2 of the plan).
        ->and($column['generation']['type'])->toBe('stored')
        ->and(strtolower((string) $column['generation']['expression']))->toContain('lower(email)')
        ->and(authIndexes('users'))->toContain('unique:email_lower')
        // The column the design already had is untouched: the display form is
        // the user's (AC-2 stores `Owner@Example.com` and expects it back).
        ->and(authIndexes('users'))->toContain('unique:email');
});

it('populates email_lower and makes it an indexed equality lookup on the stand-in', function () {
    $connection = DB::connection('master');

    // Migrate first, seed second. The ordering is the stand-in's constraint,
    // stated in this file's header, and it is the reason this case inserts
    // only after beforeEach() has migrated.
    $connection->table('users')->insert([
        'id' => str_pad('o', 26, 'O'),
        'name' => 'Owner',
        'email' => 'Owner@Example.com',
        'password_hash' => 'not-a-hash',
        'status' => 'active',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ]);

    // The generated column is computed by the database, not written by us.
    expect($connection->table('users')->where('email_lower', 'owner@example.com')->value('email'))
        ->toBe('Owner@Example.com');

    // The control AC-2 names: a second, genuinely different address is not
    // confused with the first.
    $connection->table('users')->insert([
        'id' => str_pad('x', 26, 'X'),
        'name' => 'Other',
        'email' => 'other@example.com',
        'password_hash' => 'not-a-hash',
        'status' => 'active',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ]);

    expect($connection->table('users')->where('email_lower', 'owner@example.com')->count())->toBe(1)
        ->and($connection->table('users')->where('email_lower', 'other@example.com')->count())->toBe(1);
});

it('refuses a case-variant duplicate email on the stand-in', function () {
    $connection = DB::connection('master');

    $row = static fn (string $id, string $email): array => [
        'id' => str_pad($id, 26, 'U'),
        'name' => 'Someone',
        'email' => $email,
        'password_hash' => 'not-a-hash',
        'status' => 'active',
        'created_at' => '2026-10-15 00:00:00.000',
        'updated_at' => '2026-10-15 00:00:00.000',
    ];

    $connection->table('users')->insert($row('a', 'Owner@Example.com'));

    // The unique index over the generated column is what refuses this: a plain
    // unique index on `email` is case-SENSITIVE on SQLite and would accept it.
    expect(fn () => $connection->table('users')->insert($row('b', 'owner@example.COM')))
        ->toThrow(QueryException::class);

    // The control: a different address is still accepted, so the refusal is
    // the case variant and not the second insert.
    $connection->table('users')->insert($row('c', 'someone.else@example.com'));

    expect($connection->table('users')->count())->toBe(2);

    // **This is a side effect, not a re-proof of T-008's AC-8(a).** That
    // criterion is the engine's collation and stays [ENGINE]; what is shown
    // here is that the stand-in now has the same uniqueness property, which is
    // what lets T-009's own lookup criteria be proven without an engine.
});
