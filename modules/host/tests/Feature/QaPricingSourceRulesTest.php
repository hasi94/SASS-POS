<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-029 step 17 - "one engine" and "the adapter never orders" (AC-25, AC-26,
| AC-30.4), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaCatalogSourceRulesTest: its own helpers (every one is
| prefixed qaPricing, so no other Qa*SourceRulesTest file's helper can be
| called by accident, FP-164), code read with comments and docblocks removed
| (SourceRules::codeOnly), and every rule carries a planted-file control that
| runs over a real temporary directory holding the planted file, so the scan is
| shown able to fail.
|
| WHAT EACH RULE COVERS (named for what it scans)
|
|  AC-25 (a) the tokens. Files under packages/<every>/src, modules/<every>/src
|    and modules/host/app, except packages/pricing/src (whose CRUD slices must
|    name the tables they write). packages/contracts/src/Pricing is scanned too,
|    but may name `is_inclusive` and `is_compound`, because they are the JSON
|    keys of the published breakdown (TaxBreakdown::toArray, TaxLine::toArray)
|    and nothing else; it may not name a table. Migrations are not scanned.
|  AC-25 (b) the arithmetic: a multiplication next to a token ending in `rate`
|    or `percent`, over the same files except packages/pricing/src/Engine, so the
|    CRUD slices may not do tax arithmetic either.
|  Purity: the pure classes of packages/pricing/src/Engine import nothing from
|    Illuminate; the one adapter there imports one named class.
|  No surface: no routes, Route::, Cache::, dispatch( or HookGateway in
|    packages/pricing/src.
|  Contracts: packages/contracts/src/Pricing imports nothing outside the
|    contracts namespace and PHP core.
|  D-29, THE READ PATH NEVER ORDERS. "Read path" is every file in
|    packages/pricing/src OUTSIDE Features/ (the CRUD slices order and paginate
|    for humans): the adapter, the tax group directory, the support helpers, the
|    registry and the provider. Three scans:
|      1. no SQL ordering or limiting call or clause in any of them (orderBy,
|         orderByDesc, orderByRaw, latest, oldest, inRandomOrder, reorder,
|         take, skip, limit, offset, forPage, a paginate call, ORDER BY, LIMIT);
|      2. no PHP sort call in any of them except the pure classes of Engine/,
|         whose job the ordering is (PriceLadder, TaxCalculator);
|      3. in DatabasePricingEngine.php alone, a first(), value(), sole() or find()
|         is allowed only on a statement that names a key: `where('id', ...)`, or
|         the product_branches primary key (product_id then branch_id). That is
|         the plan's "first() only on a primary-key or unique-id lookup".
|    NOT covered: a first() or value() in the directory or in Support/, which
|    this file does not inspect beyond scans 1 and 2. DatabaseTaxGroupDirectory::
|    defaultGroupId() reads the one flagged-default group through a unique key
|    (default_marker); it is not a pick among candidates, but no scan can tell
|    that from one, so it is said here instead of exempted by name.
*/

function qaPricingRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaPricingFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_pricing_scan_'.bin2hex(random_bytes(6));

    mkdir($directory, 0777, true);

    return $directory;
}

function qaPricingDeleteDirectory(string $directory): void
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
function qaPricingSourceFiles(string $directory): array
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
function qaPricingRealPath(string $relative): string
{
    $real = realpath(qaPricingRepoRoot().'/'.$relative);

    if ($real === false) {
        throw new \RuntimeException("Expected [{$relative}] to exist; a rename must fail this scan loudly, not widen it.");
    }

    return $real;
}

/**
 * Every source directory the one-engine rules read.
 *
 * @return list<string>
 */
function qaPricingScannedDirectories(): array
{
    $root = qaPricingRepoRoot();
    $directories = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $directories[] = $dir;
    }

    foreach (glob($root.'/modules/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $directories[] = $dir;
    }

    $directories[] = $root.'/modules/host/app';

    return $directories;
}

/**
 * Every scanned file whose real path is not under one of the excluded real
 * directories.
 *
 * @param  list<string>  $excludedDirectories  repo-relative
 * @return array<string, string>
 */
function qaPricingScanFiles(array $excludedDirectories): array
{
    $excluded = array_map(static fn (string $relative): string => qaPricingRealPath($relative).DIRECTORY_SEPARATOR, $excludedDirectories);

    // The real tree is read and tokenised once per process; only the planted
    // controls read a directory of their own.
    static $tree = null;

    if ($tree === null) {
        $tree = [];

        foreach (qaPricingScannedDirectories() as $directory) {
            $tree += qaPricingSourceFiles($directory);
        }
    }

    $files = [];

    foreach ($tree as $path => $code) {
        foreach ($excluded as $prefix) {
            if (str_starts_with($path, $prefix)) {
                continue 2;
            }
        }

        $files[$path] = $code;
    }

    return $files;
}

/**
 * @param  list<string>  $tokens
 * @param  array<string, string>  $files
 * @return list<string> the files naming any token as a code word
 */
function qaPricingTokenOffenders(array $tokens, array $files): array
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

const QA_PRICING_ENGINE_TOKENS = ['tax_rates', 'tax_group_taxes', 'price_list_items', 'price_list_assignments', 'is_inclusive', 'is_compound'];

/**
 * The two tokens that are JSON keys of the published breakdown, not tables.
 */
const QA_PRICING_CONTRACT_KEY_TOKENS = ['is_inclusive', 'is_compound'];

/**
 * A multiplication next to a token ending in `rate` or `percent`.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaPricingArithmeticOffenders(array $files): array
{
    $token = '[\w>$\[\]\'-]*(?:rate|percent)';
    $patterns = [
        '/\*\s*\(?\s*'.$token.'\b/i',
        '/\b'.$token.'\b\s*\)?\s*\*/i',
        '/multipliedBy\s*\([^;]*?(?:rate|percent)\b/i',
        '/\b\w*(?:rate|percent)\b\s*->\s*multipliedBy/i',
    ];
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
 * SQL ordering and limiting, as a call or as a clause in a string.
 *
 * @var list<string>
 */
const QA_PRICING_SQL_ORDERING_PATTERNS = [
    '/\borderBy(?:Desc|Raw)?\s*\(/i',
    '/\b(?:latest|oldest|inRandomOrder|reorder)\s*\(/i',
    '/->\s*(?:limit|take|skip|offset|forPage|paginate|simplePaginate|cursorPaginate)\s*\(/i',
    '/\border\s+by\b/i',
    '/\blimit\s+(?:\d|\?|:)/i',
];

/**
 * A PHP-side sort, free function or collection method.
 *
 * @var list<string>
 */
const QA_PRICING_PHP_SORT_PATTERNS = [
    '/\b(?:usort|uasort|uksort|sort|rsort|asort|arsort|ksort|krsort|natsort|natcasesort|array_multisort)\s*\(/',
    '/->\s*(?:sortBy|sortByDesc|sortKeys|sortKeysDesc|sort|sortDesc)\s*\(/',
];

/**
 * @param  list<string>  $patterns
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaPricingPatternOffenders(array $patterns, array $files): array
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
 * Every file of the pricing read path: packages/pricing/src outside Features/.
 *
 * @return array<string, string>
 */
function qaPricingReadPathFiles(): array
{
    $features = qaPricingRealPath('packages/pricing/src/Features').DIRECTORY_SEPARATOR;

    return array_filter(
        qaPricingSourceFiles(qaPricingRealPath('packages/pricing/src')),
        static fn (string $path): bool => ! str_starts_with($path, $features),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * The read path without the pure engine classes: the adapter, the directory,
 * Support/, Registry/, Exceptions/ and the provider.
 *
 * @return array<string, string>
 */
function qaPricingReadPathWithoutPureEngine(): array
{
    $adapter = qaPricingRealPath('packages/pricing/src/Engine/DatabasePricingEngine.php');
    $engine = qaPricingRealPath('packages/pricing/src/Engine').DIRECTORY_SEPARATOR;

    return array_filter(
        qaPricingReadPathFiles(),
        static fn (string $path): bool => $path === $adapter || ! str_starts_with($path, $engine),
        ARRAY_FILTER_USE_KEY,
    );
}

/**
 * The statements of one file's code that call a one-row read (`first`, `value`,
 * `sole`, `find`) without naming a key.
 *
 * @return list<string>
 */
function qaPricingUnkeyedFirstStatements(string $code): array
{
    $found = [];

    foreach (explode(';', $code) as $statement) {
        if (preg_match('/->\s*(?:first|value|sole|find|firstWhere|firstOrFail)\s*\(/', $statement) !== 1) {
            continue;
        }

        $keyed = preg_match('/->where\(\s*[\'"]id[\'"]\s*,/', $statement) === 1
            || preg_match('/->where\(\s*\'product_id\'\s*,[^;]*->where\(\s*\'branch_id\'\s*,/s', $statement) === 1;

        if (! $keyed) {
            $found[] = trim($statement);
        }
    }

    return $found;
}

// ---------------------------------------------------------------- anti-vacuity

it('every scanned directory and every named file exists, so nothing can silently empty a scan', function () {
    foreach ([
        'packages/pricing/src', 'packages/pricing/src/Engine', 'packages/pricing/src/Features', 'packages/pricing/src/Taxes',
        'packages/pricing/src/Support', 'packages/contracts/src/Pricing', 'packages/catalog/src', 'modules/host/app', 'modules/demo/src',
    ] as $directory) {
        expect(is_dir(qaPricingRepoRoot().'/'.$directory))->toBeTrue("Expected a directory at [{$directory}].");
    }

    foreach ([
        'packages/pricing/src/Engine/DatabasePricingEngine.php', 'packages/pricing/src/Engine/PriceLadder.php',
        'packages/pricing/src/Taxes/DatabaseTaxGroupDirectory.php', 'packages/pricing/src/PricingServiceProvider.php',
    ] as $file) {
        qaPricingRealPath($file);
    }

    $everything = qaPricingScanFiles([]);
    $outsidePricing = qaPricingScanFiles(['packages/pricing/src']);
    $outsideEngine = qaPricingScanFiles(['packages/pricing/src/Engine']);

    expect(array_keys($everything))->toContain(qaPricingRealPath('packages/catalog/src/CatalogServiceProvider.php'))
        ->and(count($outsidePricing))->toBeLessThan(count($everything))
        ->and(count($outsideEngine))->toBeLessThan(count($everything))
        ->and(array_keys($outsidePricing))->not->toContain(qaPricingRealPath('packages/pricing/src/Features/CreateTax/CreateTaxHandler.php'))
        ->and(array_keys($outsideEngine))->toContain(qaPricingRealPath('packages/pricing/src/Features/CreateTax/CreateTaxHandler.php'));

    // The read path is the whole package but Features/, and it holds the files this file names.
    $readPath = array_keys(qaPricingReadPathFiles());

    expect($readPath)->toContain(qaPricingRealPath('packages/pricing/src/Engine/DatabasePricingEngine.php'))
        ->and($readPath)->toContain(qaPricingRealPath('packages/pricing/src/Taxes/DatabaseTaxGroupDirectory.php'))
        ->and($readPath)->toContain(qaPricingRealPath('packages/pricing/src/Support/Lookup.php'))
        ->and($readPath)->not->toContain(qaPricingRealPath('packages/pricing/src/Features/CreateTax/CreateTaxHandler.php'));
});

// ------------------------------------------------------------- AC-25 (a): tokens

it('AC-25 (a): no file outside packages/pricing/src names a pricing table or a tax flag, except the contract keys', function () {
    $files = qaPricingScanFiles(['packages/pricing/src', 'packages/contracts/src/Pricing']);

    expect(qaPricingTokenOffenders(QA_PRICING_ENGINE_TOKENS, $files))->toBe([]);

    // The contract directory is scanned for the four table tokens: it may name
    // the two breakdown keys and nothing else.
    $tables = array_values(array_diff(QA_PRICING_ENGINE_TOKENS, QA_PRICING_CONTRACT_KEY_TOKENS));

    expect(qaPricingTokenOffenders($tables, qaPricingSourceFiles(qaPricingRealPath('packages/contracts/src/Pricing'))))->toBe([]);
});

it('AC-25 (a) control: a planted file naming each token is reported, a prose mention is not', function () {
    $directory = qaPricingFreshTempDirectory();

    try {
        foreach (QA_PRICING_ENGINE_TOKENS as $index => $token) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n\$x->table('{$token}')->get();\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n/** The tax_rates table is read by the engine. */\n// is_inclusive\n\$x = 1;\n");

        $offenders = qaPricingTokenOffenders(QA_PRICING_ENGINE_TOKENS, qaPricingSourceFiles($directory));

        expect($offenders)->toHaveCount(count(QA_PRICING_ENGINE_TOKENS))
            ->and($offenders)->not->toContain((string) realpath($directory.'/Prose.php'));
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

it('AC-3 / AC-25: packages/catalog/src never names tax_groups; it asks the published TaxGroupDirectory', function () {
    $files = qaPricingSourceFiles(qaPricingRealPath('packages/catalog/src'));

    expect($files)->not->toBe([])
        ->and(qaPricingTokenOffenders(['tax_groups'], $files))->toBe([]);

    $directory = qaPricingFreshTempDirectory();

    try {
        file_put_contents($directory.'/Planted.php', "<?php\n\$x->table('tax_groups')->first();\n");

        expect(qaPricingTokenOffenders(['tax_groups'], qaPricingSourceFiles($directory)))->toBe([(string) realpath($directory.'/Planted.php')]);
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

// --------------------------------------------------------- AC-25 (b): arithmetic

it('AC-25 (b): no file outside packages/pricing/src/Engine multiplies by a rate or a percent', function () {
    expect(qaPricingArithmeticOffenders(qaPricingScanFiles(['packages/pricing/src/Engine'])))->toBe([]);
});

it('AC-25 (b) control: each multiplication spelling is reported, and the pure engine really does contain one', function () {
    $directory = qaPricingFreshTempDirectory();
    $spellings = [
        'Star' => '$tax = $amount * $rate;',
        'StarLeft' => '$tax = $percent * $amount;',
        'Method' => '$tax = $amount->multipliedBy($taxRate);',
        'Receiver' => '$tax = $percent->multipliedBy($amount);',
    ];

    try {
        foreach ($spellings as $name => $line) {
            file_put_contents($directory.'/'.$name.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\n\$total = \$a + \$b;\n// \$x * \$rate in a comment\n");

        $offenders = qaPricingArithmeticOffenders(qaPricingSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))
            ->and($offenders)->not->toContain((string) realpath($directory.'/Clean.php'));
    } finally {
        qaPricingDeleteDirectory($directory);
    }

    // The scope proof: the exclusion is what keeps the suite green, because the
    // pure engine itself does the arithmetic the scan forbids everywhere else.
    expect(qaPricingArithmeticOffenders(qaPricingSourceFiles(qaPricingRealPath('packages/pricing/src/Engine'))))->not->toBe([]);
});

// ----------------------------------------------------------------- purity, surface

it('the pure engine classes import nothing from Illuminate, and the adapter imports exactly one class', function () {
    $engine = qaPricingSourceFiles(qaPricingRealPath('packages/pricing/src/Engine'));
    $adapter = qaPricingRealPath('packages/pricing/src/Engine/DatabasePricingEngine.php');
    $pure = array_filter($engine, static fn (string $path): bool => $path !== $adapter, ARRAY_FILTER_USE_KEY);

    expect($pure)->not->toBe([])
        ->and(qaPricingPatternOffenders(['/\bIlluminate\\\\/'], $pure))->toBe([]);

    preg_match_all('/\bIlluminate\\\\[\w\\\\]+/', $engine[$adapter], $imports);

    expect(array_values(array_unique($imports[0])))->toBe(['Illuminate\\Database\\Connection']);

    $directory = qaPricingFreshTempDirectory();

    try {
        file_put_contents($directory.'/Planted.php', "<?php\nuse Illuminate\\Support\\Facades\\DB;\n");

        expect(qaPricingPatternOffenders(['/\bIlluminate\\\\/'], qaPricingSourceFiles($directory)))->toBe([(string) realpath($directory.'/Planted.php')]);
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

it('the pricing package has no routes, no cache, no dispatch and no hook gateway', function () {
    expect(is_dir(qaPricingRepoRoot().'/packages/pricing/routes'))->toBeFalse();

    $patterns = ['/\bRoute::/', '/\bCache::/', '/\bdispatch\s*\(/', '/\bHookGateway\b/'];
    $files = qaPricingSourceFiles(qaPricingRealPath('packages/pricing/src'));

    expect($files)->not->toBe([])
        ->and(qaPricingPatternOffenders($patterns, $files))->toBe([]);

    $directory = qaPricingFreshTempDirectory();

    try {
        foreach (['Route::get("/x", fn () => 1);', 'Cache::put("k", 1);', 'dispatch(new Job);', '$g = new HookGateway;'] as $index => $line) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n{$line}\n");
        }

        expect(qaPricingPatternOffenders($patterns, qaPricingSourceFiles($directory)))->toHaveCount(4);
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaPricingForeignImports(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        preg_match_all('/^use\s+([^;]+);/m', $code, $matches);

        foreach ($matches[1] as $import) {
            $import = trim($import);

            if (str_contains($import, '\\') && ! str_starts_with($import, 'Flexipos\\Contracts\\')) {
                $found[] = $path.' imports '.$import;
            }
        }
    }

    return $found;
}

it('Contracts\\Pricing imports nothing outside the contracts namespace and PHP core', function () {
    $files = qaPricingSourceFiles(qaPricingRealPath('packages/contracts/src/Pricing'));

    expect($files)->not->toBe([])
        ->and(qaPricingForeignImports($files))->toBe([]);

    $directory = qaPricingFreshTempDirectory();

    try {
        file_put_contents($directory.'/Planted.php', "<?php\nuse Brick\\Math\\BigDecimal;\nuse Flexipos\\Contracts\\Pricing\\Shape;\nuse InvalidArgumentException;\n");

        expect(qaPricingForeignImports(qaPricingSourceFiles($directory)))->toHaveCount(1);
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

// ------------------------------------------------- D-29: the read path never orders

it('D-29: no file of the pricing read path orders or limits in SQL', function () {
    $files = qaPricingReadPathFiles();

    expect($files)->not->toBe([])
        ->and(qaPricingPatternOffenders(QA_PRICING_SQL_ORDERING_PATTERNS, $files))->toBe([]);
});

it('D-29 control: every SQL ordering or limiting spelling is reported, and a prose mention is not', function () {
    $spellings = [
        '$q->orderBy("code");', '$q->orderByDesc("code");', '$q->orderByRaw("code");', '$q->latest();', '$q->oldest();',
        '$q->inRandomOrder();', '$q->reorder();', '$q->limit(1);', '$q->take(1);', '$q->skip(1);', '$q->offset(1);',
        '$q->forPage(1, 5);', '$q->paginate(5);', '$q->simplePaginate(5);', '$s = "select * from t order by code";',
        '$s = "select * from t limit 1";',
    ];
    $directory = qaPricingFreshTempDirectory();

    try {
        foreach ($spellings as $index => $line) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n/** Never orderBy('code') or ORDER BY or limit(1) here. */\n\$ok = 1;\n");

        $offenders = qaPricingPatternOffenders(QA_PRICING_SQL_ORDERING_PATTERNS, qaPricingSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))
            ->and($offenders)->not->toContain((string) realpath($directory.'/Prose.php'));
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

it('D-29: only the pure engine sorts in PHP; the adapter, the directory and Support never do', function () {
    $files = qaPricingReadPathWithoutPureEngine();

    expect(array_keys($files))->toContain(qaPricingRealPath('packages/pricing/src/Engine/DatabasePricingEngine.php'))
        ->and(array_keys($files))->not->toContain(qaPricingRealPath('packages/pricing/src/Engine/PriceLadder.php'))
        ->and(qaPricingPatternOffenders(QA_PRICING_PHP_SORT_PATTERNS, $files))->toBe([]);

    // The scope proof: the pure engine does sort, which is why it is excluded.
    $pure = qaPricingSourceFiles(qaPricingRealPath('packages/pricing/src/Engine'));

    expect(qaPricingPatternOffenders(QA_PRICING_PHP_SORT_PATTERNS, [qaPricingRealPath('packages/pricing/src/Engine/PriceLadder.php') => $pure[qaPricingRealPath('packages/pricing/src/Engine/PriceLadder.php')]]))->not->toBe([]);
});

it('D-29 control: every PHP sort spelling is reported', function () {
    $spellings = [
        'usort($a, fn () => 0);', 'uasort($a, fn () => 0);', 'uksort($a, fn () => 0);', 'sort($a);', 'rsort($a);', 'asort($a);',
        'arsort($a);', 'ksort($a);', 'krsort($a);', 'natsort($a);', 'array_multisort($a);', '$c->sortBy("code");',
        '$c->sortByDesc("code");', '$c->sortKeys();', '$c->sort();',
    ];
    $directory = qaPricingFreshTempDirectory();

    try {
        foreach ($spellings as $index => $line) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\n\$resorted = 1;\n\$x->isSorted = true;\n");

        $offenders = qaPricingPatternOffenders(QA_PRICING_PHP_SORT_PATTERNS, qaPricingSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))
            ->and($offenders)->not->toContain((string) realpath($directory.'/Clean.php'));
    } finally {
        qaPricingDeleteDirectory($directory);
    }
});

it('D-29: DatabasePricingEngine.php reads one row (first, value, sole, find) only through a key', function () {
    $adapter = qaPricingRealPath('packages/pricing/src/Engine/DatabasePricingEngine.php');
    $code = qaPricingSourceFiles(dirname($adapter))[$adapter];

    expect(qaPricingUnkeyedFirstStatements($code))->toBe([]);

    // Anti-vacuity: the adapter does read rows this way, so an empty list is not an empty scan.
    expect(preg_match_all('/->\s*(?:first|value)\s*\(/', $code))->toBeGreaterThanOrEqual(4);
});

it('D-29 control: an unkeyed first or value over a candidate set is reported, a keyed one is not', function () {
    $unkeyed = [
        '$db->table("price_lists")->where("is_active", true)->first();',
        '$db->table("price_lists")->first();',
        '$db->table("price_lists")->where("code", "a")->value("id");',
        '$db->table("tax_rates")->where("tax_id", $t)->sole();',
        '$db->table("price_list_items")->find($x);',
    ];
    $keyed = [
        "\$db->table('products')->where('id', \$id)->first();",
        "\$db->table('product_branches')->where('product_id', \$p)->where('branch_id', \$b)->value('selling_price');",
    ];

    foreach ($unkeyed as $line) {
        expect(qaPricingUnkeyedFirstStatements(SourceRules::codeOnly("<?php\n{$line}\n")))->toHaveCount(1, $line);
    }

    foreach ($keyed as $line) {
        expect(qaPricingUnkeyedFirstStatements(SourceRules::codeOnly("<?php\n{$line}\n")))->toBe([], $line);
    }
});
