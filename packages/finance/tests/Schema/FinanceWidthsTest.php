<?php

namespace Flexipos\Finance\Tests\Schema;

use Flexipos\Finance\Support\Limits;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Every finance string width equals its Limits constant (T-032 plan step 3)
|--------------------------------------------------------------------------
|
| AC-4 and AC-34's width halves, and AC-66. A static read of the three
| finance migrations, so it needs no database: every `string()` and `char()`
| call must be named in the map below and must declare exactly the width its
| `Limits` constant states. A column added later with no mapping fails here
| by name, which is the point: a validator that is not tied to a width is a
| width nobody checks.
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
function financeDeclaredWidths(string $source): array
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
function financeWidthMismatches(string $source, array $expected): array
{
    $declared = financeDeclaredWidths($source);
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
 * The three migrations and the constant each width must equal.
 *
 * @return array<string, array<string, string>>
 */
function financeWidthMap(): array
{
    return [
        '2026_11_15_003201_create_accounts_table.php' => [
            'code' => 'CODE', 'name' => 'ACCOUNT_NAME', 'description' => 'DESCRIPTION', 'type' => 'ENUM',
            'subtype' => 'ENUM', 'path' => 'PATH', 'normal_balance' => 'ENUM', 'control_type' => 'ENUM',
            'control_marker' => 'ENUM', 'currency' => 'CURRENCY',
        ],
        '2026_11_15_003202_create_fiscal_years_table.php' => [
            'name' => 'YEAR_NAME', 'status' => 'ENUM',
        ],
        '2026_11_15_003203_create_fiscal_periods_table.php' => [
            'name' => 'PERIOD_NAME', 'status' => 'ENUM',
        ],
    ];
}

it('declares every finance VARCHAR and CHAR width equal to its Limits constant, in all three migrations', function () {
    $directory = base_path((string) config('schema.tenant_migration_path'));
    $found = [];

    foreach (financeWidthMap() as $file => $expected) {
        expect(file_exists($directory.'/'.$file))->toBeTrue();

        foreach (financeWidthMismatches((string) file_get_contents($directory.'/'.$file), $expected) as $mismatch) {
            $found[] = $file.': '.$mismatch;
        }
    }

    expect($found)->toBe([]);
});

it('red-first: a column declared one wider than its constant is reported by name', function () {
    $fixture = "<?php\nreturn new class { public function up() { \$table->string('code', 21); } };";

    expect(financeWidthMismatches($fixture, ['code' => 'CODE']))
        ->toBe(['code: declared 21, Limits::CODE is 20']);
});

it('red-first: a column with no named constant, and a constant with no column, are each reported', function () {
    $fixture = "<?php\nreturn new class { public function up() { \$table->string('nickname', 40); \$table->char('currency', 3); } };";

    expect(financeWidthMismatches($fixture, ['currency' => 'CURRENCY', 'code' => 'CODE']))->toBe([
        'code: Limits::CODE is named but the migration declares no such width',
        'nickname: declared 40 but no Limits constant is named for it',
    ]);
});

it('ignores a width written only in a comment', function () {
    $fixture = "<?php\n// \$table->string('code', 99);\nreturn new class { public function up() { \$table->string('code', 20); } };";

    expect(financeWidthMismatches($fixture, ['code' => 'CODE']))->toBe([]);
});

it('keeps the eight Limits constants at their documented numbers', function () {
    expect([
        Limits::CODE, Limits::ACCOUNT_NAME, Limits::DESCRIPTION, Limits::PATH,
        Limits::YEAR_NAME, Limits::PERIOD_NAME, Limits::ENUM, Limits::CURRENCY,
    ])->toBe([20, 150, 500, 500, 50, 50, 30, 3]);
});
