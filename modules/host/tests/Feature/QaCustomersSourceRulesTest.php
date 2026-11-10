<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-028 step 16 - "one writer", append-only, no pricing read and no
| surface (AC-5, AC-19, AC-27, AC-38, AC-41), qa-accountable, written by
| dev
|--------------------------------------------------------------------------
|
| The same shape as QaCatalogSourceRulesTest/QaWorkflowSourceRulesTest: its
| own helpers, calling nothing from another Qa*SourceRulesTest file
| (FP-164). Every rule below has a planted-file control proving the scan
| can fail, run over a real directory holding the planted file, never only
| str_contains() against the planted file's own text.
|
| Reads code with comments and docblocks removed (SourceRules::codeOnly),
| for the same reason QaCatalogSourceRulesTest does: MaintainedColumns'
| own class docblock names all four maintained-column tokens in prose, and
| UpdateCustomerHandler's docblock names three of them too - a scan over
| raw text would report the documentation of the rule as a violation of
| it.
*/

function qaCustomersRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaCustomersFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_customers_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * @return array<string, string>
 */
function qaCustomersSourceFiles(string $directory): array
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

/*
|--------------------------------------------------------------------------
| One writer (the NFR, AC-41): credit_balance, loyalty_points,
| loyalty_tier, balance_after
|--------------------------------------------------------------------------
|
| `MaintainedColumns` lives under `Ledger/` by design (plan section 3), so
| it needs no exemption of its own - the Ledger/Loyalty exclusion below
| already covers it.
|
| Three handlers legitimately touch these columns outside Ledger/Loyalty,
| each for its own job and none of them a second write channel onto an
| *existing* row - the writers remain the only code that changes
| `customers.credit_balance`/`loyalty_points`/`loyalty_tier` on a row that
| already has one:
|
| - `CreateCustomerHandler` sets the row's initial `credit_balance` (0),
|   `loyalty_points` (0) and `loyalty_tier` (null) on the one INSERT that
|   brings the row into existence - there is no prior value to maintain,
|   and no lock to take, because the row is not there yet. Any non-zero
|   opening balance still goes through `CreditLedgerWriter` (AC-7a).
| - `DeleteCustomerHandler` *reads* `credit_balance` once, to refuse the
|   delete while it is not zero (AC-10) - it never assigns it.
| - `MergeCustomersHandler` *reads* both customers' `credit_balance` and
|   `loyalty_points` once each, to decide which compensating entries to
|   post through the same two writers (Ruling 9) - it never assigns
|   either column itself.
|
| Each is named by full path and by exactly the token(s) its own job
| needs, never a blanket pass: a file exempted for `credit_balance` is
| still scanned for `loyalty_points`.
*/

/**
 * @var array<string, list<string>>
 */
const QA_CUSTOMERS_MAINTAINED_COLUMN_EXEMPT = [
    'packages/customers/src/Features/CreateCustomer/CreateCustomerHandler.php' => ['credit_balance', 'loyalty_points', 'loyalty_tier'],
    'packages/customers/src/Features/DeleteCustomer/DeleteCustomerHandler.php' => ['credit_balance'],
    'packages/customers/src/Features/MergeCustomers/MergeCustomersHandler.php' => ['credit_balance', 'loyalty_points'],
];

/**
 * The maintained-column tokens this scan polices.
 *
 * @var list<string>
 */
const QA_CUSTOMERS_MAINTAINED_COLUMN_TOKENS = ['credit_balance', 'loyalty_points', 'loyalty_tier', 'balance_after'];

/**
 * Resolve every full path in {@see QA_CUSTOMERS_MAINTAINED_COLUMN_EXEMPT}
 * to its real, absolute path, asserting each one exists - a rename or
 * move of one of these files must fail this assertion loudly, rather than
 * silently widening the scan by dropping the exemption out of the
 * (then non-matching) map.
 *
 * @return array<string, list<string>> keyed by real absolute path
 */
function qaCustomersResolveMaintainedColumnExemptions(): array
{
    $root = qaCustomersRepoRoot();
    $resolved = [];

    foreach (QA_CUSTOMERS_MAINTAINED_COLUMN_EXEMPT as $relativePath => $tokens) {
        $full = $root.'/'.$relativePath;
        $real = realpath($full);

        if ($real === false) {
            throw new \RuntimeException("Expected an exempt file at [{$full}]; it does not exist.");
        }

        $resolved[$real] = $tokens;
    }

    return $resolved;
}

/**
 * Every file this scan reads, other than the ones under
 * `packages/customers/src/Ledger` and `packages/customers/src/Loyalty`
 * (the two writers, their calculators and their contract adapters - the
 * one place these tokens legitimately live).
 *
 * `packages/*\/src` and `modules/*\/src` are each walked as one tree so a
 * provider class sitting directly in `src/` is not silently skipped, and
 * `modules/host/app` is included by name (plan step 16).
 *
 * @return array<string, string>
 */
function qaCustomersOneWriterScanFiles(): array
{
    $root = qaCustomersRepoRoot();
    $ledgerPath = (string) realpath($root.'/packages/customers/src/Ledger');
    $loyaltyPath = (string) realpath($root.'/packages/customers/src/Loyalty');
    $files = [];

    $directories = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $directories[] = $dir;
    }

    foreach (glob($root.'/modules/*/src', GLOB_ONLYDIR) ?: [] as $dir) {
        $directories[] = $dir;
    }

    $directories[] = $root.'/modules/host/app';

    foreach ($directories as $directory) {
        foreach (qaCustomersSourceFiles($directory) as $path => $code) {
            if ($ledgerPath !== '' && str_starts_with($path, $ledgerPath)) {
                continue;
            }

            if ($loyaltyPath !== '' && str_starts_with($path, $loyaltyPath)) {
                continue;
            }

            $files[$path] = $code;
        }
    }

    return $files;
}

/**
 * Every offender for one maintained-column token: a file outside
 * Ledger/Loyalty whose code contains the bare word, and which is not
 * exempt for that specific token.
 *
 * @param  array<string, string>|null  $files
 * @param  array<string, list<string>>|null  $exemptions
 * @return list<string>
 */
function qaCustomersMaintainedColumnOffenders(string $token, ?array $files = null, ?array $exemptions = null): array
{
    $exemptions ??= qaCustomersResolveMaintainedColumnExemptions();
    $found = [];

    foreach ($files ?? qaCustomersOneWriterScanFiles() as $path => $code) {
        if (preg_match('/\b'.preg_quote($token, '/').'\b/', $code) !== 1) {
            continue;
        }

        $allowedTokens = $exemptions[$path] ?? [];

        if (in_array($token, $allowedTokens, true)) {
            continue;
        }

        $found[] = $path;
    }

    return array_values(array_unique($found));
}

it('every scanned directory actually exists, so an absent one cannot silently empty the scan', function () {
    foreach ([
        qaCustomersRepoRoot().'/packages/customers/src',
        qaCustomersRepoRoot().'/packages/customers/src/Ledger',
        qaCustomersRepoRoot().'/packages/customers/src/Loyalty',
        qaCustomersRepoRoot().'/packages/contracts/src/Customers',
        qaCustomersRepoRoot().'/modules/host/app',
        qaCustomersRepoRoot().'/modules/demo/src',
    ] as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }

    qaCustomersResolveMaintainedColumnExemptions();

    $files = qaCustomersOneWriterScanFiles();
    $ledgerPath = (string) realpath(qaCustomersRepoRoot().'/packages/customers/src/Ledger');
    $loyaltyPath = (string) realpath(qaCustomersRepoRoot().'/packages/customers/src/Loyalty');

    expect($files)->not->toBe([]);

    foreach (array_keys($files) as $path) {
        expect(str_starts_with($path, $ledgerPath))->toBeFalse();
        expect(str_starts_with($path, $loyaltyPath))->toBeFalse();
    }
});

it('AC-41: no file outside Ledger/Loyalty contains a maintained-column token it is not exempt for', function () {
    foreach (QA_CUSTOMERS_MAINTAINED_COLUMN_TOKENS as $token) {
        expect(qaCustomersMaintainedColumnOffenders($token))->toBe([], "Unexpected offender(s) for [{$token}].");
    }

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->credit_balance = '5.0000';\n");

    try {
        expect(qaCustomersMaintainedColumnOffenders('credit_balance', qaCustomersSourceFiles($directory)))->toBe([
            (string) realpath($directory.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-41: each exemption is scoped to only its own tokens - the other tokens still redden that same file', function () {
    // CreateCustomerHandler is exempt for credit_balance/loyalty_points/
    // loyalty_tier, never for balance_after - proven by scanning with its
    // real exemption and planting balance_after inside a copy of exactly
    // that file's path shape is unnecessary: the exemption map itself is
    // asked directly, so a future widening of the map is what this proves
    // narrow, not the file's own content.
    $exemptions = qaCustomersResolveMaintainedColumnExemptions();

    foreach ($exemptions as $path => $allowedTokens) {
        foreach (QA_CUSTOMERS_MAINTAINED_COLUMN_TOKENS as $token) {
            if (in_array($token, $allowedTokens, true)) {
                continue;
            }

            // This file is not exempt for $token. If its code happens to
            // contain that token too, the scan must still report it -
            // i.e. the per-file exemption truly is per-token.
            $code = SourceRules::codeOnly((string) file_get_contents($path));

            if (preg_match('/\b'.preg_quote($token, '/').'\b/', $code) === 1) {
                expect(qaCustomersMaintainedColumnOffenders($token, [$path => $code], $exemptions))->toBe([$path]);
            }
        }
    }

    expect($exemptions)->not->toBe([]);
});

it('AC-41 control: a docblock mentioning credit_balance in prose is not reported, because codeOnly strips it', function () {
    // MaintainedColumns' own class docblock, and UpdateCustomerHandler's,
    // each name these tokens in prose. This is the discriminating half of
    // the rule above: a scan that read raw text would report either file
    // as a violation of the rule it documents.
    $maintainedColumns = qaCustomersRepoRoot().'/packages/customers/src/Ledger/MaintainedColumns.php';
    $updateHandler = qaCustomersRepoRoot().'/packages/customers/src/Features/UpdateCustomer/UpdateCustomerHandler.php';

    expect(is_file($maintainedColumns))->toBeTrue()
        ->and(is_file($updateHandler))->toBeTrue()
        ->and(str_contains((string) file_get_contents($updateHandler), 'credit_balance'))->toBeTrue();

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n/**\n * Never read credit_balance directly, nor loyalty_points, nor loyalty_tier, nor balance_after.\n */\nfunction f(): void {}\n");

    try {
        foreach (QA_CUSTOMERS_MAINTAINED_COLUMN_TOKENS as $token) {
            expect(qaCustomersMaintainedColumnOffenders($token, qaCustomersSourceFiles($directory), []))->toBe([]);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Append-only (AC-19, AC-27): no update/delete/increment/decrement
| chained onto customer_ledger or loyalty_transactions anywhere
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, string>|null  $files
 * @return list<string>
 */
function qaCustomersAppendOnlyOffenders(string $table, ?array $files = null): array
{
    $pattern = '/table\s*\(\s*[\'"]'.preg_quote($table, '/').'[\'"]\s*\)/';
    $found = [];

    foreach ($files ?? qaCustomersSourceFiles(qaCustomersRepoRoot().'/packages/customers/src') as $path => $code) {
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $start = (int) $match[1];
            // The statement chain this call opens, up to the next
            // statement terminator or a generous bound - PHP method
            // chains do not normally run past a `;`.
            $end = strpos($code, ';', $start);
            $window = $end === false
                ? substr($code, $start, 400)
                : substr($code, $start, $end - $start);

            if (preg_match('/->\s*(update|delete|increment|decrement)\s*\(/', $window) === 1) {
                $found[] = $path;

                break;
            }
        }
    }

    return array_values(array_unique($found));
}

it('AC-19, AC-27: no code statement chains table(customer_ledger)/table(loyalty_transactions) into update/delete/increment/decrement', function () {
    expect(qaCustomersAppendOnlyOffenders('customer_ledger'))->toBe([])
        ->and(qaCustomersAppendOnlyOffenders('loyalty_transactions'))->toBe([]);

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$connection->table('customer_ledger')->where('id', \$id)->update(['debit' => 0]);\n");

    try {
        expect(qaCustomersAppendOnlyOffenders('customer_ledger', qaCustomersSourceFiles($directory)))->toBe([
            (string) realpath($directory.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory);
    }

    $directory2 = qaCustomersFreshTempDirectory();
    File::put($directory2.'/Planted.php', "<?php\n\$connection->table('loyalty_transactions')->where('id', \$id)->delete();\n");

    try {
        expect(qaCustomersAppendOnlyOffenders('loyalty_transactions', qaCustomersSourceFiles($directory2)))->toBe([
            (string) realpath($directory2.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory2);
    }
});

it('AC-19, AC-27 control: a read (select/exists/first) on either table is not reported', function () {
    // ListCreditLedgerEntriesHandler/ListLoyaltyTransactionsHandler select
    // from these tables, and UpdateCustomerHandler/MergeCustomersHandler
    // check existence/read the latest row - none of that is a write.
    expect(qaCustomersAppendOnlyOffenders('customer_ledger'))->toBe([])
        ->and(qaCustomersAppendOnlyOffenders('loyalty_transactions'))->toBe([]);

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$connection->table('customer_ledger')->where('customer_id', \$id)->exists();\n");

    try {
        expect(qaCustomersAppendOnlyOffenders('customer_ledger', qaCustomersSourceFiles($directory)))->toBe([]);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| No pricing read (AC-5): price_list appears nowhere in
| packages/customers/src
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, string>|null  $files
 * @return list<string>
 */
function qaCustomersPriceListOffenders(?array $files = null): array
{
    $found = [];

    foreach ($files ?? qaCustomersSourceFiles(qaCustomersRepoRoot().'/packages/customers/src') as $path => $code) {
        if (preg_match('/\bprice_list\b/', $code) === 1) {
            $found[] = $path;
        }
    }

    return array_values(array_unique($found));
}

it('AC-5: packages/customers/src never names price_list', function () {
    expect(qaCustomersPriceListOffenders())->toBe([]);

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('price_list')->get();\n");

    try {
        expect(qaCustomersPriceListOffenders(qaCustomersSourceFiles($directory)))->toBe([
            (string) realpath($directory.'/Planted.php'),
        ]);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| No surface (AC-41, NFR): no routes, no cache, no jobs, no hooks
|--------------------------------------------------------------------------
*/

it('AC-41: packages/customers has no routes/ directory', function () {
    expect(is_dir(qaCustomersRepoRoot().'/packages/customers/routes'))->toBeFalse();
});

it('AC-41: packages/customers/src contains no Route::, Cache::, dispatch(, ShouldQueue or HookGateway', function () {
    $offenders = [];

    foreach (qaCustomersSourceFiles(qaCustomersRepoRoot().'/packages/customers/src') as $path => $code) {
        foreach (['Route::', 'Cache::', 'dispatch(', 'ShouldQueue', 'HookGateway'] as $token) {
            if (str_contains($code, $token)) {
                $offenders[] = $path.':'.$token;
            }
        }
    }

    expect($offenders)->toBe([]);

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nRoute::get('x', fn () => null);\n");

    try {
        $offenders2 = [];

        foreach (qaCustomersSourceFiles($directory) as $path => $code) {
            if (str_contains($code, 'Route::')) {
                $offenders2[] = $path.':Route::';
            }
        }

        expect($offenders2)->toBe([(string) realpath($directory.'/Planted.php').':Route::']);
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Contracts purity: packages/contracts/src/Customers imports nothing
| outside Flexipos\Contracts and PHP core
|--------------------------------------------------------------------------
*/

/**
 * @return list<string>
 */
function qaCustomersContractImportOffenders(string $directory): array
{
    $found = [];

    foreach (qaCustomersSourceFiles($directory) as $path => $code) {
        preg_match_all('/\buse\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/', $code, $matches);

        foreach ($matches[1] as $import) {
            $import = ltrim((string) $import, '\\');

            if (str_starts_with($import, 'Flexipos\\Contracts\\')) {
                continue;
            }

            // A PHP-core (global-namespace) class carries no backslash at
            // all - InvalidArgumentException, RuntimeException. Anything
            // with one is a vendor or framework import, which would be
            // this package's first.
            if (! str_contains($import, '\\')) {
                continue;
            }

            $found[] = $path.':'.$import;
        }
    }

    return $found;
}

it('contracts purity: packages/contracts/src/Customers imports nothing outside Flexipos\Contracts and PHP core', function () {
    expect(qaCustomersContractImportOffenders(qaCustomersRepoRoot().'/packages/contracts/src/Customers'))->toBe([]);

    $directory = qaCustomersFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\nuse Illuminate\\Support\\Str;\n");

    try {
        expect(qaCustomersContractImportOffenders($directory))->toBe([(string) realpath($directory.'/Planted.php').':Illuminate\\Support\\Str']);
    } finally {
        File::deleteDirectory($directory);
    }
});
