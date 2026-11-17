<?php

namespace Flexipos\Finance\Tests\Schema;

use Flexipos\Finance\Support\Limits;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Every journal string width equals its Limits constant (T-033 plan step A2)
|--------------------------------------------------------------------------
|
| AC-44 and AC-80's width halves, in the `FinanceWidthsTest` shape: a static
| read of `003301` and `003302`, so it needs no database. Every `string()` and
| `char()` call must be named in the map below and declare exactly the width
| its `Limits` constant states. The two comparison helpers are this file's own,
| named for it, because test files share one global function namespace and
| `FinanceWidthsTest` is not required here; the red-first control below proves
| the very comparison the real case relies on.
|
| This is a new file rather than an edit of `FinanceWidthsTest` (plan "Files").
*/

/**
 * The `[column => width]` of every `string()`/`char()` call in a migration's
 * code (comments stripped).
 *
 * @return array<string, int>
 */
function journalDeclaredWidths(string $source): array
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
function journalWidthMismatches(string $source, array $expected): array
{
    $declared = journalDeclaredWidths($source);
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
 * The two journal migrations and the constant each width must equal.
 *
 * @return array<string, array<string, string>>
 */
function journalWidthMap(): array
{
    return [
        '2026_11_16_003301_create_journal_entries_table.php' => [
            'number' => 'NUMBER', 'source_type' => 'ENUM', 'source_purpose' => 'ENUM',
            'source_purpose_key' => 'ENUM', 'status' => 'ENUM', 'description' => 'DESCRIPTION',
            'payload_fingerprint' => 'FINGERPRINT',
        ],
        '2026_11_16_003302_create_journal_lines_table.php' => [
            'memo' => 'MEMO',
        ],
    ];
}

it('declares every journal VARCHAR and CHAR width equal to its Limits constant, in both migrations', function () {
    $directory = base_path((string) config('schema.tenant_migration_path'));
    $found = [];

    foreach (journalWidthMap() as $file => $expected) {
        expect(file_exists($directory.'/'.$file))->toBeTrue();

        foreach (journalWidthMismatches((string) file_get_contents($directory.'/'.$file), $expected) as $mismatch) {
            $found[] = $file.': '.$mismatch;
        }
    }

    expect($found)->toBe([]);
});

it('red-first: a memo declared one wider than its constant is reported by name (AC-44)', function () {
    $fixture = "<?php\nreturn new class { public function up() { \$table->string('memo', 256); } };";

    expect(journalWidthMismatches($fixture, ['memo' => 'MEMO']))
        ->toBe(['memo: declared 256, Limits::MEMO is 255']);
});

it('keeps the six new Limits constants at their documented numbers', function () {
    expect([
        Limits::MEMO, Limits::REASON, Limits::NUMBER, Limits::FINGERPRINT, Limits::MIN_LINES, Limits::MAX_LINES,
    ])->toBe([255, 500, 30, 64, 2, 200]);
});
