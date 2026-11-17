<?php

namespace Flexipos\Hr\Tests\Schema;

use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The four HR migrations in the committed catalogue (T-038 plan step 2, AC-5)
|--------------------------------------------------------------------------
|
| The fixture is the committed authority for the version each migration holds
| (`database/catalogue/tenant_schema_versions.json`). It is republished only by
| `schema:publish --write-fixture`; no HR migration names its own version. This
| file reads the fixture and the tree and asserts they agree, so a hand-edited
| row or a migration added without a republish is reported by name.
*/

/**
 * @return list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}>
 */
function hrCatalogue(): array
{
    /** @var list<array{version: int, migration: string, phase: string, checksum: string, is_destructive: bool, requires_offline: bool}> $rows */
    $rows = json_decode((string) file_get_contents(base_path('database/catalogue/tenant_schema_versions.json')), true);

    return $rows;
}

/**
 * @return list<string> the tree's migration names, sorted, without the extension
 */
function hrTreeMigrationNames(): array
{
    $names = array_map(
        static fn (string $file): string => basename($file, '.php'),
        (array) glob(base_path((string) config('schema.tenant_migration_path')).'/*.php')
    );
    sort($names);

    return $names;
}

it('holds the four HR migrations as consecutive expand rows that are not destructive (AC-5)', function () {
    $rows = array_values(array_filter(
        hrCatalogue(),
        static fn (array $row): bool => str_starts_with($row['migration'], '2026_11_16_00380')
    ));

    expect(array_column($rows, 'migration'))->toBe([
        '2026_11_16_003801_create_departments_table',
        '2026_11_16_003802_create_designations_table',
        '2026_11_16_003803_create_employee_profiles_table',
        '2026_11_16_003804_create_employee_documents_table',
    ])->and(array_column($rows, 'phase'))->toBe(['expand', 'expand', 'expand', 'expand'])
        ->and(array_column($rows, 'is_destructive'))->toBe([false, false, false, false])
        ->and(array_column($rows, 'requires_offline'))->toBe([false, false, false, false])
        ->and($rows[3]['version'] - $rows[0]['version'])->toBe(3);
});

it('keeps the HR rows on top, with versions increasing by one from one (AC-5)', function () {
    $rows = hrCatalogue();
    $versions = array_column($rows, 'version');

    expect($versions)->toBe(range(1, count($rows)))
        ->and(end($rows)['migration'])->toBe('2026_11_16_003804_create_employee_documents_table');
});

it('has a fixture that equals the tree: same names in the same order (AC-5)', function () {
    expect(array_column(hrCatalogue(), 'migration'))->toBe(hrTreeMigrationNames());
});

it('records each HR migration with the checksum of its file as shipped (AC-5)', function () {
    $directory = base_path((string) config('schema.tenant_migration_path'));
    $rows = array_values(array_filter(
        hrCatalogue(),
        static fn (array $row): bool => str_starts_with($row['migration'], '2026_11_16_00380')
    ));

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        $source = (string) file_get_contents($directory.'/'.$row['migration'].'.php');

        // The catalogue's checksum is a SHA-256 of the file; a changed file names itself here.
        expect($row['checksum'])->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($row['checksum'])->toBe(hash('sha256', $source));
    }
});
