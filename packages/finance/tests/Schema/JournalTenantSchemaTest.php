<?php

declare(strict_types=1);

namespace Flexipos\Finance\Tests\Schema;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Finance\Tests\Support\FinanceSeed;
use Flexipos\Kernel\Schema\Support\CheckConstraint;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The two journal tables and the number sequence row, on the SQLite stand-in
| (T-033 plan step A2; the T-032 `FinanceTenantSchemaTest` shape)
|--------------------------------------------------------------------------
|
| The schema halves of AC-32, AC-33a, AC-33b, AC-39, AC-41 to AC-45, AC-75,
| AC-78 and AC-80. The fixture tenant is built from the whole shipped tenant
| tree, with foreign keys enforced, so these cases see what a freshly
| provisioned tenant sees.
|
| No lengths or precisions are asserted against the database: SQLite reports
| bare types. `JournalWidthsTest` and `TenantDecimalPrecisionTest` handle
| those statically. The CHECK constraints are engine-only (the stand-in cannot
| express them), so what is asserted here is the text each migration asks for;
| their enforcement is `[ENGINE]`, NOT VERIFIED under ENGINE_HOLD.
|
| Versions are looked up by migration name, never written as a literal (P-2).
*/

uses(TestCase::class);

const JOURNAL_ENTRIES_MIGRATION = '2026_11_16_003301_create_journal_entries_table';
const JOURNAL_LINES_MIGRATION = '2026_11_16_003302_create_journal_lines_table';
const JOURNAL_SEQUENCE_MIGRATION = '2026_11_16_003303_seed_journal_entry_number_sequence';

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-06-15 09:30:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function journalSchemaMigrationPath(string $name): string
{
    return dirname(__DIR__, 4).'/modules/host/database/migrations-tenant/'.$name.'.php';
}

function journalSchemaSource(string $name): string
{
    return (string) file_get_contents(journalSchemaMigrationPath($name));
}

/**
 * @return list<string>
 */
function journalSchemaColumns(string $table): array
{
    $names = array_column(Schema::connection(AccessTenantFixture::connectionName())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function journalSchemaNullable(string $table): array
{
    $names = array_column(
        array_filter(
            Schema::connection(AccessTenantFixture::connectionName())->getColumns($table),
            static fn (array $column): bool => $column['nullable']
        ),
        'name'
    );
    sort($names);

    return $names;
}

/**
 * The foreign keys a table declares, as `column -> table.column (on delete)`.
 *
 * @return list<string>
 */
function journalSchemaForeignKeys(string $table): array
{
    $keys = [];

    foreach (DB::connection(AccessTenantFixture::connectionName())->select('pragma foreign_key_list("'.$table.'")') as $key) {
        $key = (array) $key;
        $keys[] = $key['from'].' -> '.$key['table'].'.'.$key['to'].' ('.$key['on_delete'].')';
    }

    sort($keys);

    return $keys;
}

function journalSchemaPeriodId(): string
{
    return (string) FinanceSeed::connection()->table('fiscal_periods')->orderBy('start_date')->value('id');
}

function journalSchemaAccountId(): string
{
    return (string) FinanceSeed::connection()->table('accounts')->where('is_postable', true)->orderBy('code')->value('id');
}

/**
 * One raw `journal_entries` row (the only way a test writes one: the
 * application's writer arrives in chunk C).
 *
 * @param  array<string, mixed>  $overrides
 */
function journalSchemaEntry(array $overrides = []): string
{
    $id = strtoupper((string) Str::ulid());

    FinanceSeed::connection()->table('journal_entries')->insert(array_merge([
        'id' => $id,
        'number' => 'JE-'.$id,
        'entry_date' => '2026-06-15',
        'period_id' => journalSchemaPeriodId(),
        'source_type' => 'manual',
        'status' => 'posted',
        'total_debit' => '10.0000',
        'total_credit' => '10.0000',
        'payload_fingerprint' => str_repeat('a', 64),
        'posted_at' => FinanceSeed::NOW,
        'created_at' => FinanceSeed::NOW,
        'updated_at' => FinanceSeed::NOW,
    ], $overrides));

    return $id;
}

/**
 * One raw `journal_lines` row.
 *
 * @param  array<string, mixed>  $overrides
 */
function journalSchemaLine(string $entryId, int $lineNo, array $overrides = []): string
{
    $id = strtoupper((string) Str::ulid());

    FinanceSeed::connection()->table('journal_lines')->insert(array_merge([
        'id' => $id,
        'entry_id' => $entryId,
        'line_no' => $lineNo,
        'account_id' => journalSchemaAccountId(),
        'debit' => '10.0000',
        'credit' => '0.0000',
        'created_at' => FinanceSeed::NOW,
    ], $overrides));

    return $id;
}

/**
 * The three arguments of the first `CheckConstraint::<method>(` call in a
 * migration's code, comments stripped.
 *
 * @return list<string>
 */
function journalSchemaCheckArguments(string $source, string $method): array
{
    $code = SourceRules::codeOnly($source);

    expect(preg_match("/CheckConstraint::{$method}\\(\\s*'([a-z_]+)'\\s*,\\s*'([a-z_]+)'\\s*,\\s*'([a-z_]+)'\\s*\\)/", $code, $matches))->toBe(1);

    return [$matches[1], $matches[2], $matches[3]];
}

/**
 * Run a migration file's own `up()` on the fixture connection.
 */
function journalSchemaRun(string $name): void
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection(AccessTenantFixture::connectionName());

    try {
        /** @var Migration $migration */
        $migration = require journalSchemaMigrationPath($name);

        // `up()` is declared by each migration, not by the base class.
        if (! method_exists($migration, 'up')) {
            throw new LogicException('The migration has no up().');
        }

        $migration->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

it('creates journal_entries with exactly the documented columns and no tenant, soft-delete or currency column (AC-42, AC-79)', function () {
    expect(journalSchemaColumns('journal_entries'))->toBe([
        'branch_id', 'created_at', 'created_by', 'description', 'entry_date', 'id', 'is_auto', 'number',
        'payload_fingerprint', 'period_id', 'posted_at', 'posted_by', 'reversal_of_id', 'reversed_by_id',
        'source_id', 'source_purpose', 'source_purpose_key', 'source_type', 'status', 'total_credit',
        'total_debit', 'updated_at',
    ])->and(journalSchemaNullable('journal_entries'))->toBe([
        // SQLite reports a stored generated column as nullable (as it does
        // `accounts.control_marker`); the declaration is NOT NULL and its
        // expression never yields NULL.
        'branch_id', 'created_by', 'description', 'posted_by', 'reversal_of_id', 'reversed_by_id', 'source_id',
        'source_purpose', 'source_purpose_key',
    ]);

    foreach (['tenant_id', 'deleted_at', 'currency', 'plugin_name'] as $absent) {
        expect(journalSchemaColumns('journal_entries'))->not->toContain($absent);
    }
});

it('creates journal_lines with exactly the documented columns and no unit, quantity, currency or tax column (AC-43)', function () {
    expect(journalSchemaColumns('journal_lines'))->toBe([
        'account_id', 'branch_id', 'created_at', 'credit', 'customer_id', 'debit', 'department_id', 'employee_id',
        'entry_id', 'id', 'line_no', 'location_id', 'memo', 'product_id', 'supplier_id',
    ])->and(journalSchemaNullable('journal_lines'))->toBe([
        'branch_id', 'customer_id', 'department_id', 'employee_id', 'location_id', 'memo', 'product_id', 'supplier_id',
    ]);

    foreach (['tenant_id', 'deleted_at', 'currency', 'unit_id', 'quantity', 'project_ref', 'tax_id', 'tax_base'] as $absent) {
        expect(journalSchemaColumns('journal_lines'))->not->toContain($absent);
    }
});

it('holds journal_entries.number unique (AC-33a)', function () {
    journalSchemaEntry(['number' => 'JE-000001']);

    expect(fn () => journalSchemaEntry(['number' => 'JE-000001']))->toThrow(QueryException::class);

    journalSchemaEntry(['number' => 'JE-000002']);

    expect(FinanceSeed::connection()->table('journal_entries')->count())->toBe(2);
});

it('holds (entry_id, line_no) unique', function () {
    $entry = journalSchemaEntry();
    $other = journalSchemaEntry();

    journalSchemaLine($entry, 1);

    expect(fn () => journalSchemaLine($entry, 1))->toThrow(QueryException::class);

    // The same line number under another entry, and the next line number, are fine.
    journalSchemaLine($other, 1);
    journalSchemaLine($entry, 2);

    expect(FinanceSeed::connection()->table('journal_lines')->count())->toBe(3);
});

it('holds the idempotency key (source_type, source_id, source_purpose_key), a null purpose included (AC-39, AC-41)', function () {
    $source = strtoupper((string) Str::ulid());

    journalSchemaEntry(['source_type' => 'sale', 'source_id' => $source, 'source_purpose' => 'revenue']);

    // The same type, id and purpose.
    expect(fn () => journalSchemaEntry(['source_type' => 'sale', 'source_id' => $source, 'source_purpose' => 'revenue']))
        ->toThrow(QueryException::class);

    // A different purpose for the same document coexists: revenue and cogs.
    journalSchemaEntry(['source_type' => 'sale', 'source_id' => $source, 'source_purpose' => 'cogs']);

    // A null purpose is a value of the key, not an escape from it (AC-39):
    // two rows with the same type and id and no purpose are refused.
    $other = strtoupper((string) Str::ulid());

    journalSchemaEntry(['source_type' => 'purchase', 'source_id' => $other, 'source_purpose' => null]);

    expect(fn () => journalSchemaEntry(['source_type' => 'purchase', 'source_id' => $other, 'source_purpose' => null]))
        ->toThrow(QueryException::class);

    // The key column is the stored sentinel, never a purpose the application accepts.
    expect(FinanceSeed::connection()->table('journal_entries')->where('source_id', $other)->value('source_purpose_key'))->toBe('-')
        ->and(FinanceSeed::connection()->table('journal_entries')->where('source_purpose', 'cogs')->value('source_purpose_key'))->toBe('cogs');

    // Two `manual` entries with no source id coexist: they escape the key (AC-41).
    journalSchemaEntry(['source_type' => 'manual', 'source_id' => null]);
    journalSchemaEntry(['source_type' => 'manual', 'source_id' => null]);

    expect(FinanceSeed::connection()->table('journal_entries')->where('source_type', 'manual')->whereNull('source_id')->count())->toBe(2);
});

it('holds exactly the thirteen foreign keys of the two journal tables, and entry_id is the only cascade (AC-75)', function () {
    expect(journalSchemaForeignKeys('journal_entries'))->toBe([
        'branch_id -> branches.id (NO ACTION)',
        'created_by -> employees.id (NO ACTION)',
        'period_id -> fiscal_periods.id (NO ACTION)',
        'posted_by -> employees.id (NO ACTION)',
        'reversal_of_id -> journal_entries.id (NO ACTION)',
        'reversed_by_id -> journal_entries.id (NO ACTION)',
    ])->and(journalSchemaForeignKeys('journal_lines'))->toBe([
        'account_id -> accounts.id (NO ACTION)',
        'branch_id -> branches.id (NO ACTION)',
        'customer_id -> customers.id (NO ACTION)',
        'employee_id -> employees.id (NO ACTION)',
        'entry_id -> journal_entries.id (CASCADE)',
        'location_id -> locations.id (NO ACTION)',
        'product_id -> products.id (NO ACTION)',
    ]);
});

it('leaves department_id and supplier_id unguarded, because departments and suppliers do not exist (D-16)', function () {
    $keyed = array_map(
        static fn (string $key): string => explode(' ', $key)[0],
        journalSchemaForeignKeys('journal_lines')
    );

    expect($keyed)->not->toContain('department_id')
        ->and($keyed)->not->toContain('supplier_id')
        ->and(Schema::connection(AccessTenantFixture::connectionName())->hasTable('departments'))->toBeFalse()
        ->and(Schema::connection(AccessTenantFixture::connectionName())->hasTable('suppliers'))->toBeFalse();
});

it('cascades the delete of an entry to its lines, and refuses a line whose account does not exist', function () {
    $entry = journalSchemaEntry();
    $other = journalSchemaEntry();

    journalSchemaLine($entry, 1);
    journalSchemaLine($entry, 2);
    $kept = journalSchemaLine($other, 1);

    expect(fn () => journalSchemaLine($other, 2, ['account_id' => strtoupper((string) Str::ulid())]))
        ->toThrow(QueryException::class);

    FinanceSeed::connection()->table('journal_entries')->where('id', $entry)->delete();

    expect(FinanceSeed::connection()->table('journal_lines')->pluck('id')->all())->toBe([$kept]);
});

it('calls balanced and oneSided with the documented arguments, and the statements are the helpers\' own (AC-45, D-34)', function () {
    [$table, $debit, $credit] = journalSchemaCheckArguments(journalSchemaSource(JOURNAL_ENTRIES_MIGRATION), 'balanced');

    expect([$table, $debit, $credit])->toBe(['journal_entries', 'total_debit', 'total_credit'])
        ->and(CheckConstraint::balancedStatement($table, $debit, $credit))
        ->toBe('ALTER TABLE `journal_entries` ADD CONSTRAINT `journal_entries_total_debit_total_credit_balanced`'
            .' CHECK (`total_debit` = `total_credit`)');

    [$table, $debit, $credit] = journalSchemaCheckArguments(journalSchemaSource(JOURNAL_LINES_MIGRATION), 'oneSided');

    $statement = CheckConstraint::oneSidedStatement($table, $debit, $credit);

    expect([$table, $debit, $credit])->toBe(['journal_lines', 'debit', 'credit'])
        ->and($statement)->toBe('ALTER TABLE `journal_lines` ADD CONSTRAINT `journal_lines_debit_credit_one_sided`'
            .' CHECK ((`debit` = 0 OR `credit` = 0) AND `debit` >= 0 AND `credit` >= 0)')
        // The one combined constraint states both halves of AC-45.
        ->and($statement)->toContain('= 0 OR')
        ->and($statement)->toContain('>= 0');
});

it('seeds exactly one company-wide journal_entry sequence row with the documented values (AC-32, AC-78)', function () {
    $rows = FinanceSeed::connection()->table('number_sequences')->where('document_type', 'journal_entry')->get();

    expect($rows)->toHaveCount(1);

    $row = (array) $rows->first();

    expect($row['branch_id'])->toBeNull()
        ->and($row['branch_id_key'])->toBe('-')
        ->and($row['prefix'])->toBe('JE-')
        ->and((int) $row['padding'])->toBe(6)
        ->and($row['reset_period'])->toBe('never')
        ->and((int) $row['next_number'])->toBe(1)
        ->and($row['suffix'])->toBeNull()
        ->and($row['last_reset_at'])->toBeNull();
});

it('runs the seed twice and leaves one row, and leaves a row a tenant already tuned untouched (AC-78)', function () {
    $connection = FinanceSeed::connection();

    journalSchemaRun(JOURNAL_SEQUENCE_MIGRATION);
    journalSchemaRun(JOURNAL_SEQUENCE_MIGRATION);

    expect($connection->table('number_sequences')->where('document_type', 'journal_entry')->count())->toBe(1);

    $connection->table('number_sequences')->where('document_type', 'journal_entry')
        ->update(['prefix' => 'JV-', 'padding' => 8, 'next_number' => 41]);

    journalSchemaRun(JOURNAL_SEQUENCE_MIGRATION);

    $row = (array) $connection->table('number_sequences')->where('document_type', 'journal_entry')->first();

    expect($connection->table('number_sequences')->where('document_type', 'journal_entry')->count())->toBe(1)
        ->and($row['prefix'])->toBe('JV-')
        ->and((int) $row['padding'])->toBe(8)
        ->and((int) $row['next_number'])->toBe(41);
});

it('keeps the seed row and the allocator default equal: a deleted row is recreated with the same prefix, padding and reset (AC-33b)', function () {
    $connection = FinanceSeed::connection();

    $seeded = (array) $connection->table('number_sequences')->where('document_type', 'journal_entry')->first();

    $connection->table('number_sequences')->where('document_type', 'journal_entry')->delete();

    expect(app(DocumentNumbers::class)->next('journal_entry', null))->toBe('JE-000001');

    $recreated = (array) $connection->table('number_sequences')->where('document_type', 'journal_entry')->first();

    expect($recreated['prefix'])->toBe($seeded['prefix'])
        ->and($recreated['padding'])->toBe($seeded['padding'])
        ->and($recreated['reset_period'])->toBe($seeded['reset_period'])
        ->and($recreated['branch_id'])->toBeNull();
});

it('reports no SourceRules violation, declares no down(), and builds every ULID column through IdentifierColumn (AC-80)', function () {
    foreach ([JOURNAL_ENTRIES_MIGRATION, JOURNAL_LINES_MIGRATION, JOURNAL_SEQUENCE_MIGRATION] as $name) {
        $source = journalSchemaSource($name);
        $code = SourceRules::codeOnly($source);

        expect(SourceRules::inspect($source, MigrationTree::Tenant))->toBe([])
            ->and($source)->not->toContain('function down(')
            ->and($code)->not->toMatch('/->\s*ulid\s*\(/')
            ->and($code)->not->toMatch('/->\s*char\s*\(\s*[\'"](?:\w*_id|id)[\'"]\s*,\s*26\s*\)/');
    }

    // Anti-vacuity: the identifier scan reads code that does declare ULID columns.
    expect(SourceRules::codeOnly(journalSchemaSource(JOURNAL_LINES_MIGRATION)))->toContain('IdentifierColumn::required($table, \'entry_id\')');
});

it('declares the three journal migrations in the order expand, expand, backfill, after every T-032 file (P-2, P-3)', function () {
    $directory = dirname(__DIR__, 4).'/modules/host/database/migrations-tenant';
    $files = array_map('basename', glob($directory.'/2026_11_16_0033*.php') ?: []);
    sort($files);

    expect($files)->toContain(JOURNAL_ENTRIES_MIGRATION.'.php', JOURNAL_LINES_MIGRATION.'.php', JOURNAL_SEQUENCE_MIGRATION.'.php');

    $phases = [];

    foreach ([JOURNAL_ENTRIES_MIGRATION, JOURNAL_LINES_MIGRATION, JOURNAL_SEQUENCE_MIGRATION] as $name) {
        preg_match('/@phase\s+(\w+)/', journalSchemaSource($name), $matches);
        $phases[] = $matches[1] ?? '?';
    }

    expect($phases)->toBe(['expand', 'expand', 'backfill']);
});
