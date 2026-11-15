<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Schema;

use Flexipos\Access\Support\AuditColumns;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-017 step 6 - audit_logs_archive is column-for-column audit_logs (AC-7)
|--------------------------------------------------------------------------
|
| A private SQLite stand-in, migrated with the whole tenant tree, exactly
| the shape QaTenantBaselineStandInShapeTest already uses. The behavioural
| half of "refuses invalid JSON" is [E], gated behind ENGINE_HOLD exactly as
| it already is for audit_logs itself (JsonValidity::enforce() issues no
| statement on SQLite) - what is provable here is that the archive
| migration calls it with the identical column list the live migration does.
*/

uses(TestCase::class);

beforeEach(function () {
    config()->set('database.connections.audit_archive_shape_stand_in', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.audit_archive_shape_stand_in.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => 'audit_archive_shape_stand_in',
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
});

/**
 * @return array<string, array{type_name: string, nullable: bool}>
 */
function auditArchiveShapeColumns(string $table): array
{
    $columns = [];

    foreach (Schema::connection('audit_archive_shape_stand_in')->getColumns($table) as $column) {
        $columns[(string) $column['name']] = [
            'type_name' => (string) $column['type_name'],
            'nullable' => (bool) $column['nullable'],
        ];
    }

    return $columns;
}

/**
 * @return list<string>
 */
function auditArchiveShapeIndexes(string $table): array
{
    $labels = [];

    foreach (Schema::connection('audit_archive_shape_stand_in')->getIndexes($table) as $index) {
        $labels[] = ($index['primary'] ? 'primary' : ($index['unique'] ? 'unique' : 'index')).':'.implode(',', $index['columns']);
    }

    sort($labels);

    return $labels;
}

it('gives audit_logs_archive the same column names, types and nullability as audit_logs', function () {
    $live = auditArchiveShapeColumns('audit_logs');
    $archive = auditArchiveShapeColumns('audit_logs_archive');

    expect(array_keys($archive))->toBe(AuditColumns::ALL)
        ->and(array_keys($live))->toBe(AuditColumns::ALL)
        ->and($archive)->toBe($live);
});

it('gives audit_logs_archive the same four declared indexes as audit_logs, plus the identical primary key', function () {
    // Five labels, not four: getIndexes() also reports the auto-incrementing
    // primary key itself, which both tables declare the same way through
    // $table->id(). The four indexes AC-7 means are the explicit ones below.
    expect(auditArchiveShapeIndexes('audit_logs_archive'))->toBe(auditArchiveShapeIndexes('audit_logs'))
        ->and(auditArchiveShapeIndexes('audit_logs_archive'))->toContain(
            'index:created_at',
            'index:subject_type,subject_id',
            'index:actor_id,created_at',
            'index:module,action,created_at',
        );
});

it('calls JsonValidity::enforce with the same three columns audit_logs enforces (AC-29 parity, source half)', function () {
    $archiveSource = (string) file_get_contents(archiveMigrationPath());
    $liveSource = (string) file_get_contents(liveAuditLogsMigrationPath());

    expect($archiveSource)->toContain("JsonValidity::enforce('audit_logs_archive', 'before', 'after', 'changed_fields')")
        ->and($liveSource)->toContain("JsonValidity::enforce('audit_logs', 'before', 'after', 'changed_fields')");
});

it('breaks none of SourceRules::inspect() s rules for the archive migration file', function () {
    $source = (string) file_get_contents(archiveMigrationPath());

    expect(SourceRules::inspect($source, MigrationTree::Tenant))->toBe([]);
});

it('reports a scratch archive file missing one column, which is what makes the shape comparison a measurement', function () {
    $live = auditArchiveShapeColumns('audit_logs');
    unset($live['user_agent']);

    expect($live)->not->toBe(auditArchiveShapeColumns('audit_logs_archive'));
});

/**
 * The one migration this task ships, by its known file name fragment.
 */
function archiveMigrationPath(): string
{
    $directory = base_path((string) config('schema.tenant_migration_path'));

    $matches = glob($directory.DIRECTORY_SEPARATOR.'*create_audit_logs_archive_table.php') ?: [];

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

/**
 * The shipped T-008 audit_logs migration.
 */
function liveAuditLogsMigrationPath(): string
{
    $directory = base_path((string) config('schema.tenant_migration_path'));

    $matches = glob($directory.DIRECTORY_SEPARATOR.'*create_audit_logs_table.php') ?: [];

    expect($matches)->toHaveCount(1);

    return $matches[0];
}
