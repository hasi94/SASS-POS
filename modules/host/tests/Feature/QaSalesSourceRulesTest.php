<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-030 step 23 - Sales owns no tax, price, unit, ledger or number arithmetic,
| posts no stock or journal, and has no surface (AC-42, AC-27A, AC-15, AC-31;
| D-48; Ruling 3), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaPricingSourceRulesTest and QaCustomersSourceRulesTest: its
| own helpers, calling nothing from another Qa*SourceRulesTest file (FP-164).
| Every rule below has a planted-file control proving the scan can fail, run over
| a real directory holding the planted file, never only str_contains() against
| the planted file's own text.
|
| Reads code with comments and docblocks removed (SourceRules::codeOnly): the
| tokens D-48 names appear in Sales' docblocks on purpose, for example
| RefundSaleHandler's lock-order paragraph, so a scan over raw text would report
| the documentation of the rule as a violation of it. The arithmetic rule goes one
| step further and reads tokens, so a `/` or a `*` inside a string literal (a
| message, a path) is not mistaken for an operator.
*/

function qaSalesRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaSalesFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_sales_scan_'.bin2hex(random_bytes(6));

    mkdir($directory, 0777, true);

    return $directory;
}

function qaSalesDeleteDirectory(string $directory): void
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
 * @return array<string, string> real path => code-only text
 */
function qaSalesSourceFiles(string $directory): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        $files[$file->getRealPath()] = SourceRules::codeOnly((string) file_get_contents($file->getRealPath()));
    }

    return $files;
}

/**
 * @param  string  $relative  repo-relative
 */
function qaSalesRealPath(string $relative): string
{
    $real = realpath(qaSalesRepoRoot().'/'.$relative);

    if ($real === false) {
        throw new \RuntimeException("Expected [{$relative}] to exist; a rename must fail this scan loudly, not widen it.");
    }

    return $real;
}

/**
 * Every file of the Sales package, code only. Read once per process.
 *
 * @return array<string, string>
 */
function qaSalesPackageFiles(): array
{
    static $files = null;

    return $files ??= qaSalesSourceFiles(qaSalesRealPath('packages/sales/src'));
}

/**
 * @param  list<string>  $tokens
 * @param  array<string, string>  $files
 * @return list<string> the files naming any token as a code word
 */
function qaSalesTokenOffenders(array $tokens, array $files): array
{
    $pattern = '/\b(?:'.implode('|', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)).')\b/';
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
function qaSalesPatternOffenders(array $patterns, array $files): array
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
 * D-48: the tables, columns and flags that belong to another module's arithmetic.
 *
 * @var list<string>
 */
const QA_SALES_FOREIGN_TOKENS = [
    'tax_rates', 'tax_group_taxes', 'price_list_items', 'price_list_assignments', 'is_inclusive', 'is_compound',
    'unit_conversions', 'base_unit_id', 'customer_ledger', 'credit_balance', 'balance_after', 'number_sequences', 'used_count',
];

/**
 * The two stock-side columns Sales legitimately names: `products.stock_unit_id`, read for a refusal message, and
 * `sales.stock_applied`.
 *
 * @var list<string>
 */
const QA_SALES_STOCK_JOURNAL_ALLOWED = ['stock_unit_id', 'stock_applied'];

/**
 * A stock or journal table name, as a token: `stock_` or `journal_` followed by a word, less the two allowed columns.
 *
 * @param  array<string, string>  $files
 * @return list<string> file => the offending tokens
 */
function qaSalesStockJournalOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        if (preg_match_all('/\b(?:stock|journal)_[A-Za-z]\w*/', $code, $matches) === 0) {
            continue;
        }

        $bad = array_values(array_diff(array_unique($matches[0]), QA_SALES_STOCK_JOURNAL_ALLOWED));

        if ($bad !== []) {
            $found[] = $path.' ['.implode(', ', $bad).']';
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
function qaSalesArithmeticOffenders(array $files): array
{
    $operators = [T_MUL_EQUAL, T_DIV_EQUAL, T_POW, T_POW_EQUAL];
    $found = [];

    foreach ($files as $path => $code) {
        $tokens = token_get_all($code);

        foreach ($tokens as $index => $token) {
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

// ------------------------------------------------------------------ scope

it('every scanned directory exists and holds the files the rules name, so nothing can silently empty a scan', function () {
    $files = array_keys(qaSalesPackageFiles());

    expect(is_dir(qaSalesRepoRoot().'/packages/sales/src'))->toBeTrue()
        ->and(count($files))->toBeGreaterThan(50)
        ->and($files)->toContain(qaSalesRealPath('packages/sales/src/Sales/LineWriter.php'))
        ->and($files)->toContain(qaSalesRealPath('packages/sales/src/Support/Money.php'))
        ->and($files)->toContain(qaSalesRealPath('packages/sales/src/Features/RefundSale/RefundSaleHandler.php'))
        ->and($files)->toContain(qaSalesRealPath('packages/sales/src/SalesServiceProvider.php'));
});

// ------------------------------------------------------------------ AC-42 (a)

it('AC-42 (a), D-48: no file under packages/sales/src names another module\'s table, column or flag in code', function () {
    expect(qaSalesTokenOffenders(QA_SALES_FOREIGN_TOKENS, qaSalesPackageFiles()))->toBe([]);
});

it('AC-42 (a) control: a planted file naming each token is reported, a prose mention is not', function () {
    $directory = qaSalesFreshTempDirectory();

    try {
        foreach (QA_SALES_FOREIGN_TOKENS as $index => $token) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n\$x->table('{$token}')->get();\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n/** The tax_rates table and used_count are the engine's. */\n// number_sequences\n\$x = 1;\n");

        $offenders = qaSalesTokenOffenders(QA_SALES_FOREIGN_TOKENS, qaSalesSourceFiles($directory));

        expect($offenders)->toHaveCount(count(QA_SALES_FOREIGN_TOKENS))
            ->and($offenders)->not->toContain((string) realpath($directory.'/Prose.php'));
    } finally {
        qaSalesDeleteDirectory($directory);
    }

    // The scope proof: the docblocks do name them, so the code-only read is what keeps this green.
    $raw = (string) file_get_contents(qaSalesRealPath('packages/sales/src/Features/RefundSale/RefundSaleHandler.php'));

    expect(preg_match('/\bnumber_sequences\b/', $raw))->toBe(1);
});

// ------------------------------------------------------------------ AC-42 (b)

it('AC-42 (b), Ruling 3: no file under packages/sales/src outside Support/Money.php multiplies or divides', function () {
    $money = qaSalesRealPath('packages/sales/src/Support/Money.php');
    $files = array_filter(qaSalesPackageFiles(), static fn (string $path): bool => $path !== $money, ARRAY_FILTER_USE_KEY);

    expect($files)->not->toBe([])
        ->and(qaSalesArithmeticOffenders($files))->toBe([]);
});

it('AC-42 (b) control: each spelling is reported, a string or a comment is not, and Money.php really does contain arithmetic', function () {
    $directory = qaSalesFreshTempDirectory();
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

        $offenders = qaSalesArithmeticOffenders(qaSalesSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))
            ->and($offenders)->not->toContain((string) realpath($directory.'/Clean.php'));
    } finally {
        qaSalesDeleteDirectory($directory);
    }

    expect(qaSalesArithmeticOffenders([qaSalesRealPath('packages/sales/src/Support/Money.php') => qaSalesPackageFiles()[qaSalesRealPath('packages/sales/src/Support/Money.php')]]))->not->toBe([]);
});

// ------------------------------------------------------------------ AC-27A, the static half

it('AC-27A: Money::product( is called from Sales/LineWriter.php only, so a refund path cannot copy the override arithmetic', function () {
    $offenders = qaSalesPatternOffenders(['/\bMoney\s*::\s*product\s*\(/'], qaSalesPackageFiles());

    expect($offenders)->toBe([qaSalesRealPath('packages/sales/src/Sales/LineWriter.php')]);
});

it('AC-27A control: a planted second caller is reported alongside LineWriter, a prose mention is not', function () {
    $directory = qaSalesFreshTempDirectory();

    try {
        file_put_contents($directory.'/Copied.php', "<?php\n\$subtotal = Money::product(\$price, \$quantity);\n");
        file_put_contents($directory.'/Spaced.php', "<?php\n\$subtotal = Money :: product (\$price, \$quantity);\n");
        file_put_contents($directory.'/Prose.php', "<?php\n/** Money::product( is the override's */\n\$x = 1;\n");

        $offenders = qaSalesPatternOffenders(['/\bMoney\s*::\s*product\s*\(/'], qaSalesSourceFiles($directory));

        expect($offenders)->toHaveCount(2)
            ->and($offenders)->not->toContain((string) realpath($directory.'/Prose.php'));
    } finally {
        qaSalesDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ AC-42 (c): the other two scans really cover Sales

it('AC-42 (c): the unit-factor scan and the pricing-arithmetic scan both reach Sales, find nothing there, and exempt no Sales file', function () {
    $root = qaSalesRepoRoot();

    // Both scans walk every packages/*/src tree; this is the same enumeration, and it must contain Sales.
    $tree = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $tree += qaSalesSourceFiles($directory);
    }

    $sales = array_filter($tree, static fn (string $path): bool => str_starts_with($path, qaSalesRealPath('packages/sales/src').DIRECTORY_SEPARATOR), ARRAY_FILTER_USE_KEY);

    expect($sales)->not->toBe([])
        ->and(array_keys($sales))->toContain(qaSalesRealPath('packages/sales/src/Sales/LineWriter.php'));

    // T-027's factor scan: the two tokens, and the three multiplication spellings.
    $factorPatterns = ['/\*\s*[\$\w>:-]*factor\b/i', '/\bfactor\b\s*[\$\w>:-]*\*/i', '/multipliedBy\s*\([^)]*factor/i'];

    expect(qaSalesTokenOffenders(['unit_conversions', 'base_unit_id'], $sales))->toBe([])
        ->and(qaSalesPatternOffenders($factorPatterns, $sales))->toBe([]);

    // T-029's AC-25 scan: the six tokens, and the four rate or percent multiplication spellings.
    $pricingTokens = ['tax_rates', 'tax_group_taxes', 'price_list_items', 'price_list_assignments', 'is_inclusive', 'is_compound'];
    $token = '[\w>$\[\]\'-]*(?:rate|percent)';
    $pricingPatterns = [
        '/\*\s*\(?\s*'.$token.'\b/i',
        '/\b'.$token.'\b\s*\)?\s*\*/i',
        '/multipliedBy\s*\([^;]*?(?:rate|percent)\b/i',
        '/\b\w*(?:rate|percent)\b\s*->\s*multipliedBy/i',
    ];

    expect(qaSalesTokenOffenders($pricingTokens, $sales))->toBe([])
        ->and(qaSalesPatternOffenders($pricingPatterns, $sales))->toBe([]);

    // No exemption list in either scan's file names a Sales path: Sales is held to the rule, never excused from it.
    foreach (['QaCatalogSourceRulesTest.php', 'QaPricingSourceRulesTest.php'] as $name) {
        $text = (string) file_get_contents(__DIR__.'/'.$name);

        expect(preg_match('/packages[\\\\\/]sales/i', $text))->toBe(0);
    }
});

// ------------------------------------------------------------------ AC-15, AC-31

it('AC-15, AC-31: no file under packages/sales/src names a stock or journal table; stock_unit_id and stock_applied are the only exceptions', function () {
    $files = qaSalesPackageFiles();

    expect(qaSalesStockJournalOffenders($files))->toBe([]);

    // The two allowed columns are in use, so the allow-list is what keeps the rule green and a bare prefix rule would not be.
    $named = array_filter($files, static fn (string $code): bool => preg_match('/\bstock_(?:unit_id|applied)\b/', $code) === 1);

    expect($named)->not->toBe([]);
});

it('AC-15, AC-31 control: stock_levels, stock_movements and journal_entries are reported; the two allowed columns and prose are not', function () {
    $directory = qaSalesFreshTempDirectory();

    try {
        foreach (['stock_levels', 'stock_movements', 'journal_entries'] as $name) {
            file_put_contents($directory.'/'.$name.'.php', "<?php\n\$x->table('{$name}')->insert([]);\n");
        }

        file_put_contents($directory.'/Allowed.php', "<?php\n\$a = \$p->stock_unit_id;\n\$b = ['stock_applied' => false];\n");
        file_put_contents($directory.'/Prose.php', "<?php\n/** stock_levels and journal_entries are not ours. */\n\$x = 1;\n");

        $offenders = qaSalesStockJournalOffenders(qaSalesSourceFiles($directory));

        $listed = implode("\n", $offenders);

        expect($offenders)->toHaveCount(3)
            ->and($listed)->not->toContain('Allowed.php');

        expect($listed)->not->toContain('Prose.php');
    } finally {
        qaSalesDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ surface

it('the sales package has no routes, no Route::, no Cache:: and no dispatch(', function () {
    expect(is_dir(qaSalesRepoRoot().'/packages/sales/routes'))->toBeFalse();

    $patterns = ['/\bRoute::/', '/\bCache::/', '/\bdispatch\s*\(/'];

    expect(qaSalesPatternOffenders($patterns, qaSalesPackageFiles()))->toBe([]);

    $directory = qaSalesFreshTempDirectory();

    try {
        foreach (['Route::get("/x", fn () => 1);', 'Cache::put("k", 1);', 'dispatch(new Job);'] as $index => $line) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n// Route::get and Cache::put and dispatch(\n\$x = 1;\n");

        $offenders = qaSalesPatternOffenders($patterns, qaSalesSourceFiles($directory));

        expect($offenders)->toHaveCount(3)->and($offenders)->not->toContain((string) realpath($directory.'/Prose.php'));
    } finally {
        qaSalesDeleteDirectory($directory);
    }
});
