<?php

namespace Flexipos\Hr\Tests\Schema;

use Flexipos\Hr\Support\Limits;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Every HR string width equals its Limits constant (T-038 plan step 3)
|--------------------------------------------------------------------------
|
| AC-4's width half, AC-16, AC-32 and AC-47's lengths. A static read of the
| four HR migrations, so it needs no database: every `string()` and `char()`
| call must be named in the map below and must declare exactly the width its
| `Limits` constant states. A column added later with no mapping fails here
| by name.
|
| ULID columns are built by `IdentifierColumn`, not by `string()` or `char()`,
| so they are outside this file's subject by construction.
*/

/**
 * The `[column => width]` of every `string()`/`char()` call in a migration's
 * code (comments stripped).
 *
 * @return array<string, int>
 */
function hrDeclaredWidths(string $source): array
{
    $widths = [];
    $code = SourceRules::codeOnly($source);

    if (preg_match_all('/->\s*(?:string|char)\s*\(\s*[\'"](\w+)[\'"]\s*,\s*(\d+)\s*\)/', $code, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $widths[$match[1]] = (int) $match[2];
        }
    }

    return $widths;
}

/**
 * Every disagreement between a source's declared widths and the expected
 * constants, by column, so a failure names what drifted.
 *
 * @param  array<string, string>  $expected  column => name of a Limits constant
 * @return list<string>
 */
function hrWidthMismatches(string $source, array $expected): array
{
    $declared = hrDeclaredWidths($source);
    $found = [];

    foreach ($declared as $column => $width) {
        if (! array_key_exists($column, $expected)) {
            $found[] = "{$column}: declared {$width} but no Limits constant is named for it";

            continue;
        }

        $limit = (int) constant(Limits::class.'::'.$expected[$column]);

        if ($width !== $limit) {
            $found[] = "{$column}: declared {$width}, Limits::{$expected[$column]} is {$limit}";
        }
    }

    foreach (array_diff_key($expected, $declared) as $column => $constant) {
        $found[] = "{$column}: Limits::{$constant} is named but the migration declares no such width";
    }

    sort($found);

    return $found;
}

/**
 * The four migrations and the constant each width must equal.
 *
 * @return array<string, array<string, string>>
 */
function hrWidthMap(): array
{
    return [
        '2026_11_16_003801_create_departments_table.php' => [
            'code' => 'CODE', 'name' => 'NAME',
        ],
        '2026_11_16_003802_create_designations_table.php' => [
            'code' => 'CODE', 'name' => 'NAME',
        ],
        '2026_11_16_003803_create_employee_profiles_table.php' => [
            'employment_type' => 'ENUM', 'exit_reason' => 'EXIT_REASON', 'gender' => 'ENUM',
            'marital_status' => 'ENUM', 'nationality' => 'NATIONALITY', 'nic' => 'IDENTITY_NUMBER',
            'passport_no' => 'IDENTITY_NUMBER', 'personal_email' => 'EMAIL', 'work_email' => 'EMAIL',
            'phone' => 'PHONE',
        ],
        '2026_11_16_003804_create_employee_documents_table.php' => [
            'type' => 'ENUM', 'title' => 'DOCUMENT_TITLE', 'file_path' => 'FILE_PATH',
            'original_name' => 'ORIGINAL_NAME', 'mime' => 'MIME', 'checksum' => 'CHECKSUM',
            'notes' => 'DOCUMENT_NOTES',
        ],
    ];
}

it('declares every HR VARCHAR and CHAR width equal to its Limits constant, in all four migrations', function () {
    $directory = base_path((string) config('schema.tenant_migration_path'));
    $found = [];

    foreach (hrWidthMap() as $file => $expected) {
        expect(file_exists($directory.'/'.$file))->toBeTrue();

        foreach (hrWidthMismatches((string) file_get_contents($directory.'/'.$file), $expected) as $mismatch) {
            $found[] = $file.': '.$mismatch;
        }
    }

    expect($found)->toBe([]);
});

it('red-first: a column declared one wider than its constant is reported by name', function () {
    $fixture = "<?php\nreturn new class { public function up() { \$table->string('code', 21); } };";

    expect(hrWidthMismatches($fixture, ['code' => 'CODE']))
        ->toBe(['code: declared 21, Limits::CODE is 20']);
});

it('red-first: a column with no named constant, and a constant with no column, are each reported', function () {
    $fixture = "<?php\nreturn new class { public function up() { \$table->string('nickname', 40); \$table->char('checksum', 64); } };";

    expect(hrWidthMismatches($fixture, ['checksum' => 'CHECKSUM', 'code' => 'CODE']))->toBe([
        'code: Limits::CODE is named but the migration declares no such width',
        'nickname: declared 40 but no Limits constant is named for it',
    ]);
});

it('ignores a width written only in a comment', function () {
    $fixture = "<?php\n// \$table->string('code', 99);\nreturn new class { public function up() { \$table->string('code', 20); } };";

    expect(hrWidthMismatches($fixture, ['code' => 'CODE']))->toBe([]);
});

it('keeps the Limits constants at their documented numbers', function () {
    expect([
        Limits::CODE, Limits::NAME, Limits::ENUM, Limits::EXIT_REASON, Limits::NATIONALITY,
        Limits::IDENTITY_NUMBER, Limits::EMAIL, Limits::PHONE, Limits::DOCUMENT_TITLE,
        Limits::FILE_PATH, Limits::ORIGINAL_NAME, Limits::MIME, Limits::CHECKSUM, Limits::DOCUMENT_NOTES,
    ])->toBe([20, 150, 30, 500, 60, 30, 191, 30, 150, 255, 255, 100, 64, 1000]);
});
