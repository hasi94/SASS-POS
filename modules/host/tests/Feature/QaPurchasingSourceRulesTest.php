<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-034 step 21 - Purchasing owns no foreign table, does its own arithmetic in
| one file, holds no float and has no surface (AC-46, AC-79, AC-93; D-38;
| Ruling 1), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaSalesSourceRulesTest and QaFinanceSourceRulesTest: its own
| helpers, calling nothing from another Qa*SourceRulesTest file (FP-164), and a
| planted-file control for every rule, run over a real directory holding the
| planted file so the scan itself is shown able to fail.
|
| Reads code with comments and docblocks removed (SourceRules::codeOnly), and the
| arithmetic and float rules go one step further and read tokens, so an operator
| or the word `float` inside a string literal (a message, an array key) is not
| mistaken for code.
|
| **The float rule is not "the word float appears nowhere".** The SQLite stand-in
| hands a DECIMAL back as an int or a float and MySQL as a string, so a handful of
| functions accept `int|float|string` on purpose and turn the value into a
| canonical string before anything else touches it, and two guards ask `is_float`.
| That one union is the single sanctioned spelling and is removed before the scan;
| any other `float` type, any cast, `floatval`, and any float literal is a
| violation.
*/

function qaPurchasingRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaPurchasingFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_purchasing_scan_'.bin2hex(random_bytes(6));

    mkdir($directory, 0777, true);

    return $directory;
}

function qaPurchasingDeleteDirectory(string $directory): void
{
    foreach ((new Finder)->files()->in($directory) as $file) {
        unlink($file->getRealPath());
    }

    foreach (array_reverse(iterator_to_array((new Finder)->directories()->in($directory), false)) as $nested) {
        rmdir($nested->getRealPath());
    }

    rmdir($directory);
}

/**
 * @return array<string, string> path relative to $directory, forward slashes => code-only text
 */
function qaPurchasingSourceFiles(string $directory): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        $files[str_replace('\\', '/', $file->getRelativePathname())] = SourceRules::codeOnly((string) file_get_contents($file->getRealPath()));
    }

    return $files;
}

function qaPurchasingRealPath(string $relative): string
{
    $real = realpath(qaPurchasingRepoRoot().'/'.$relative);

    if ($real === false) {
        throw new \RuntimeException("Expected [{$relative}] to exist; a rename must fail this scan loudly, not widen it.");
    }

    return $real;
}

/**
 * Every file of the Purchasing package's src, code only, keyed by path under `packages/purchasing/src`. Read once per process.
 *
 * @return array<string, string>
 */
function qaPurchasingPackageFiles(): array
{
    static $files = null;

    return $files ??= qaPurchasingSourceFiles(qaPurchasingRealPath('packages/purchasing/src'));
}

/**
 * D-38: the tables, columns and flags that belong to another module's facts or arithmetic, and the stock side AC-79 keeps out.
 *
 * @var list<string>
 */
const QA_PURCHASING_FOREIGN_TOKENS = [
    'unit_conversions', 'base_unit_id', 'tax_rates', 'tax_group_taxes', 'price_list_items', 'is_inclusive',
    'number_sequences', 'workflow_instances', 'workflow_tasks', 'workflow_events', 'customer_ledger',
    'stock_levels', 'stock_movements', 'journal_', 'quantity_incoming', 'grns',
];

/**
 * S-2: every table a literal `->table('...')` names in Purchasing source: the package's own tables and the
 * read-only lookups of other modules' tables (products, locations, branches, and employees for the actor check).
 *
 * @var list<string>
 */
const QA_PURCHASING_ALLOWED_TABLES = [
    'suppliers', 'purchase_requests', 'purchase_request_items', 'purchase_orders', 'purchase_order_items',
    'purchase_order_receipt_applications', 'products', 'locations', 'branches', 'employees',
];

/**
 * @param  list<string>  $allowed
 * @param  array<string, string>  $files
 * @return list<string> `path: table` for every literal `->table('name')` whose name is not allowed
 */
function qaPurchasingTableOffenders(array $allowed, array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        if (preg_match_all('/->table\(\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\)/', $code, $matches) < 1) {
            continue;
        }

        foreach ($matches[1] as $table) {
            if (! in_array($table, $allowed, true)) {
                $found[] = "{$path}: {$table}";
            }
        }
    }

    return $found;
}

/**
 * The one file that does arithmetic, and the one caller of its product.
 */
const QA_PURCHASING_MONEY_FILE = 'Support/Money.php';

const QA_PURCHASING_PRODUCT_CALLER = 'Documents/RequestAmount.php';

/**
 * @param  list<string>  $tokens
 * @param  array<string, string>  $files
 * @return list<string> the files naming any token as a code word (a token ending in `_` is a prefix)
 */
function qaPurchasingTokenOffenders(array $tokens, array $files): array
{
    $alternatives = array_map(
        static fn (string $token): string => str_ends_with($token, '_') ? preg_quote($token, '/').'\w*' : preg_quote($token, '/'),
        $tokens
    );
    $pattern = '/\b(?:'.implode('|', $alternatives).')\b/';
    $found = [];

    foreach ($files as $path => $code) {
        if (preg_match($pattern, $code) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * @param  list<string>  $patterns
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaPurchasingPatternOffenders(array $patterns, array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

/**
 * An arithmetic operator or a BigDecimal multiply or divide, as a token in code: strings and comments are not read.
 *
 * @param  array<string, string>  $files  code-only text
 * @return list<string>
 */
function qaPurchasingArithmeticOffenders(array $files): array
{
    $operators = [T_MUL_EQUAL, T_DIV_EQUAL, T_POW, T_POW_EQUAL];
    $found = [];

    foreach ($files as $path => $code) {
        foreach (token_get_all($code) as $token) {
            $hit = match (true) {
                $token === '*' || $token === '/' => true,
                is_array($token) && in_array($token[0], $operators, true) => true,
                is_array($token) && $token[0] === T_STRING && in_array($token[1], ['multipliedBy', 'dividedBy'], true) => true,
                default => false,
            };

            if ($hit) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

/**
 * A float cast, `floatval`, a float literal, or a float type other than the sanctioned `int|float|string` union.
 *
 * @param  array<string, string>  $files  code-only text
 * @return list<string>
 */
function qaPurchasingFloatOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        $code = (string) preg_replace('/\bint\s*\|\s*float\s*\|\s*string\b/', 'int|string', $code);

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $hit = match (true) {
                $token[0] === T_DOUBLE_CAST, $token[0] === T_DNUMBER => true,
                $token[0] === T_STRING && in_array(strtolower($token[1]), ['float', 'double', 'floatval', 'doubleval'], true) => true,
                default => false,
            };

            if ($hit) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

// ------------------------------------------------------------------ scope (anti-vacuity)

it('every scanned directory exists and holds the files the rules name, so nothing can silently empty a scan', function () {
    $paths = array_keys(qaPurchasingPackageFiles());

    expect(count($paths))->toBeGreaterThan(100)
        ->and($paths)->toContain(QA_PURCHASING_MONEY_FILE)
        ->and($paths)->toContain(QA_PURCHASING_PRODUCT_CALLER)
        ->and($paths)->toContain('Documents/ReceiptRollup.php')
        ->and($paths)->toContain('Features/ApplyReceivedQuantity/ApplyReceivedQuantityHandler.php')
        ->and($paths)->toContain('Features/ConvertRequestToOrder/ConvertRequestToOrderHandler.php')
        ->and($paths)->toContain('PurchasingServiceProvider.php');
});

it('the scans read what the rules are about: Money.php holds the arithmetic, RequestAmount.php its one call, and the sanctioned float union exists', function () {
    $files = qaPurchasingPackageFiles();

    expect(qaPurchasingArithmeticOffenders([QA_PURCHASING_MONEY_FILE => $files[QA_PURCHASING_MONEY_FILE]]))->toBe([QA_PURCHASING_MONEY_FILE])
        ->and(preg_match('/\bMoney\s*::\s*product\s*\(/', $files[QA_PURCHASING_PRODUCT_CALLER]))->toBe(1)
        ->and(qaPurchasingPatternOffenders(['/\bint\s*\|\s*float\s*\|\s*string\b/'], $files))->toContain(QA_PURCHASING_MONEY_FILE)
        ->and(qaPurchasingFloatOffenders([QA_PURCHASING_MONEY_FILE => $files[QA_PURCHASING_MONEY_FILE]]))->toBe([]);
});

// ------------------------------------------------------------------ (a) AC-93, AC-79, D-38

it('AC-93, AC-79 (a): no file under packages/purchasing/src names another module\'s table, column or flag, or the stock side, in code', function () {
    expect(qaPurchasingTokenOffenders(QA_PURCHASING_FOREIGN_TOKENS, qaPurchasingPackageFiles()))->toBe([]);
});

it('AC-93, AC-79 (a) control: a planted file naming each token is reported, a prose mention and a longer word are not', function () {
    $directory = qaPurchasingFreshTempDirectory();

    try {
        foreach (QA_PURCHASING_FOREIGN_TOKENS as $index => $token) {
            $word = str_ends_with($token, '_') ? $token.'entries' : $token;

            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n\$x->table('{$word}')->get();\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n/** The tax_rates table and stock_levels are the engines'. */\n// number_sequences\n\$x = 1;\n");
        file_put_contents($directory.'/Longer.php', "<?php\n\$x->table('grns_archive_none')->get();\n\$y = 'workflow_instances_view';\n\$z = \$subjournal_entries;\n");

        $offenders = qaPurchasingTokenOffenders(QA_PURCHASING_FOREIGN_TOKENS, qaPurchasingSourceFiles($directory));

        expect($offenders)->toHaveCount(count(QA_PURCHASING_FOREIGN_TOKENS))
            ->and($offenders)->not->toContain('Prose.php')
            ->and($offenders)->not->toContain('Longer.php');
    } finally {
        qaPurchasingDeleteDirectory($directory);
    }
});

it('S-2: every literal table call in Purchasing source names an allowed table', function () {
    $files = qaPurchasingPackageFiles();

    expect(count($files))->toBeGreaterThan(100)
        ->and(qaPurchasingTableOffenders(QA_PURCHASING_ALLOWED_TABLES, $files))->toBe([]);
});

it('S-2 control: a planted table call outside the list is reported, an allowed table and a prose mention are not', function () {
    $directory = qaPurchasingFreshTempDirectory();

    try {
        file_put_contents($directory.'/Planted.php', "<?php\n\$x->table('stock_items')->get();\n\$y->table(\"receipts\")->get();\n");
        file_put_contents($directory.'/Fine.php', "<?php\n\$x->table('suppliers')->get();\n// ->table('tax_rates')\n");

        expect(qaPurchasingTableOffenders(QA_PURCHASING_ALLOWED_TABLES, qaPurchasingSourceFiles($directory)))
            ->toBe(['Planted.php: stock_items', 'Planted.php: receipts']);
    } finally {
        qaPurchasingDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ (b) AC-46

it('AC-46 (b), Ruling 1: no file under packages/purchasing/src outside Support/Money.php multiplies or divides', function () {
    $files = array_filter(qaPurchasingPackageFiles(), static fn (string $path): bool => $path !== QA_PURCHASING_MONEY_FILE, ARRAY_FILTER_USE_KEY);

    expect(count($files))->toBeGreaterThan(100)
        ->and(qaPurchasingArithmeticOffenders($files))->toBe([]);
});

it('AC-46 (b) control: each spelling is reported, a string or a comment is not', function () {
    $directory = qaPurchasingFreshTempDirectory();
    $spellings = [
        'Star' => '$x = $a * $b;',
        'Slash' => '$x = $a / $b;',
        'Multiply' => '$x = $a->multipliedBy($b);',
        'Divide' => '$x = $a->dividedBy($b);',
        'StarEquals' => '$x *= 2;',
        'SlashEquals' => '$x /= 2;',
        'Power' => '$x = $a ** 2;',
    ];

    try {
        foreach ($spellings as $name => $line) {
            file_put_contents($directory.'/'.$name.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\n\$m = 'a/b and a * b';\n// \$x * \$y / \$z\n/** multipliedBy( */\n\$t = \$a + \$b;\n");

        $offenders = qaPurchasingArithmeticOffenders(qaPurchasingSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))
            ->and($offenders)->not->toContain('Clean.php');
    } finally {
        qaPurchasingDeleteDirectory($directory);
    }
});

it('AC-46 (b): Money::product( is called from Documents/RequestAmount.php only, so no second place computes a line amount', function () {
    $offenders = qaPurchasingPatternOffenders(['/\bMoney\s*::\s*product\s*\(/'], qaPurchasingPackageFiles());

    expect($offenders)->toBe([QA_PURCHASING_PRODUCT_CALLER]);
});

it('AC-46 (b) control: a planted second caller is reported alongside the request-amount helper, a prose mention is not', function () {
    $directory = qaPurchasingFreshTempDirectory();

    try {
        file_put_contents($directory.'/Copied.php', "<?php\n\$subtotal = Money::product(\$price, \$quantity);\n");
        file_put_contents($directory.'/Spaced.php', "<?php\n\$subtotal = Money :: product (\$price, \$quantity);\n");
        file_put_contents($directory.'/Prose.php', "<?php\n/** Money::product( is the request amount's */\n\$x = 1;\n");

        $offenders = qaPurchasingPatternOffenders(['/\bMoney\s*::\s*product\s*\(/'], qaPurchasingSourceFiles($directory));

        expect($offenders)->toEqualCanonicalizing(['Copied.php', 'Spaced.php']);
    } finally {
        qaPurchasingDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ (c) AC-46

it('AC-46 (c): no float cast, floatval, float literal or float type exists in Purchasing source, apart from the int|float|string readback union', function () {
    expect(qaPurchasingFloatOffenders(qaPurchasingPackageFiles()))->toBe([]);
});

it('AC-46 (c) control: each planted spelling is reported, the sanctioned union, is_float, strings and prose are not', function () {
    $directory = qaPurchasingFreshTempDirectory();
    $spellings = [
        'Cast' => '$x = (float) $a;',
        'DoubleCast' => '$x = (double) $a;',
        'Floatval' => '$x = floatval($a);',
        'Doubleval' => '$x = doubleval($a);',
        'Literal' => '$x = 0.5;',
        'Exponent' => '$x = 1e3;',
        'Param' => 'function f(float $a): int { return 1; }',
        'Return' => 'function f($a): float { return 1; }',
        'Nullable' => 'function f(?float $a): int { return 1; }',
        'WrongUnion' => 'function f(float|string $a): int { return 1; }',
        'ReversedUnion' => 'function f(string|float|int $a): int { return 1; }',
        'Property' => 'final class P { public float $v = 1; }',
    ];

    try {
        foreach ($spellings as $name => $line) {
            file_put_contents($directory.'/'.$name.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\nfunction of(int|float|string \$v): string { return is_float(\$v) ? 'x' : (string) \$v; }\n\$k = ['float' => 'double'];\n// (float) \$a floatval( 0.5\n/** float \$a */\n\$n = 10;\n");

        $offenders = qaPurchasingFloatOffenders(qaPurchasingSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))
            ->and($offenders)->not->toContain('Clean.php');
    } finally {
        qaPurchasingDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ (d) no surface

it('AC-93 (d): Purchasing has no routes directory, no Route::, no Cache:: and no dispatch(', function () {
    expect(is_dir(qaPurchasingRepoRoot().'/packages/purchasing/routes'))->toBeFalse();

    $patterns = ['/\bRoute\s*::/', '/\bCache\s*::/', '/\bdispatch\s*\(/'];

    expect(qaPurchasingPatternOffenders($patterns, qaPurchasingPackageFiles()))->toBe([]);
});

it('AC-93 (d) control: each planted spelling is reported, a prose mention is not', function () {
    $patterns = ['/\bRoute\s*::/', '/\bCache\s*::/', '/\bdispatch\s*\(/'];
    $directory = qaPurchasingFreshTempDirectory();

    try {
        foreach (['Route::get("/x", fn () => 1);', 'Cache::put("k", 1);', 'dispatch(new Job);', 'Route :: post("/y", fn () => 1);'] as $index => $line) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n// Route::get and Cache::put and dispatch( are not here\n\$x = 1;\n");

        $offenders = qaPurchasingPatternOffenders($patterns, qaPurchasingSourceFiles($directory));

        expect($offenders)->toHaveCount(4)->and($offenders)->not->toContain('Prose.php');
    } finally {
        qaPurchasingDeleteDirectory($directory);
    }
});
