<?php

use Flexipos\Kernel\Audit\AuditExport;
use Flexipos\Kernel\Audit\AuditPage;
use Flexipos\Kernel\Audit\ExportFormat;

/*
 * T-017 step 10 - writing one page to a local file (AC-18 mechanism).
 *
 * A fresh, uniquely-named temp directory per test, never the system temp
 * directory itself: the same reasoning QaPlatformFreshTempDirectory() gives.
 */

$GLOBALS['auditExportFreshDirectories'] = [];

function auditExportFreshPath(string $name): string
{
    $directory = sys_get_temp_dir().'/audit_export_test_'.bin2hex(random_bytes(6));

    mkdir($directory);

    $GLOBALS['auditExportFreshDirectories'][] = $directory;

    return $directory.'/'.$name;
}

afterEach(function () {
    /** @var list<string> $directories */
    $directories = $GLOBALS['auditExportFreshDirectories'];

    foreach ($directories as $directory) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    $GLOBALS['auditExportFreshDirectories'] = [];
});

it('JSON: round-trips an array of row objects', function () {
    $page = new AuditPage(
        columns: ['id', 'action'],
        rows: [
            ['id' => 1, 'action' => 'role_created'],
            ['id' => 2, 'action' => 'role_deleted'],
        ],
        nextBeforeId: null,
    );

    $path = auditExportFreshPath('page.json');

    AuditExport::write($page, $path, ExportFormat::Json);

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBe($page->rows);
});

it('CSV: the header equals the given columns, and a JSON cell with a comma and a quote survives str_getcsv', function () {
    $page = new AuditPage(
        columns: ['id', 'after'],
        rows: [
            ['id' => 1, 'after' => '{"name":"A, \\"quoted\\" value"}'],
        ],
        nextBeforeId: null,
    );

    $path = auditExportFreshPath('page.csv');

    AuditExport::write($page, $path, ExportFormat::Csv);

    $lines = array_filter(array_map('rtrim', (array) file($path)), static fn (string $line): bool => $line !== '');
    $rows = array_map('str_getcsv', $lines);

    expect($rows[0])->toBe(['id', 'after'])
        ->and($rows[1])->toBe(['1', '{"name":"A, \"quoted\" value"}']);
});

it('refuses to overwrite an existing file, and leaves it unchanged', function () {
    $page = new AuditPage(columns: ['id'], rows: [['id' => 1]], nextBeforeId: null);
    $path = auditExportFreshPath('existing.json');

    file_put_contents($path, 'untouched');

    expect(fn () => AuditExport::write($page, $path, ExportFormat::Json))->toThrow(LogicException::class);

    expect(file_get_contents($path))->toBe('untouched');
});

it('empty page: gives [] for JSON, and a header-only file for CSV', function () {
    $page = new AuditPage(columns: ['id', 'action'], rows: [], nextBeforeId: null);

    $jsonPath = auditExportFreshPath('empty.json');
    AuditExport::write($page, $jsonPath, ExportFormat::Json);
    expect(json_decode((string) file_get_contents($jsonPath), true))->toBe([]);

    $csvPath = auditExportFreshPath('empty.csv');
    AuditExport::write($page, $csvPath, ExportFormat::Csv);

    $lines = array_filter(array_map('rtrim', (array) file($csvPath)), static fn (string $line): bool => $line !== '');

    expect($lines)->toHaveCount(1)
        ->and(str_getcsv($lines[0]))->toBe(['id', 'action']);
});
