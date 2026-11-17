<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-032 step 19 - the Finance construction rules (AC-12, AC-21, AC-44, AC-50,
| AC-51, AC-57 construction halves, AC-60), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaSalesSourceRulesTest: its own helpers, calling nothing from
| another Qa*SourceRulesTest file (FP-164), and a planted-file control for every
| rule, run over a real directory holding the planted file so the scan itself is
| shown able to fail.
|
| Reads code with comments and docblocks removed (SourceRules::codeOnly). Finance's
| docblocks name `lockForUpdate`, `first` and `closing` on purpose, so a scan over
| raw text would report the documentation of a rule as a violation of it.
|
| **What the locked-read rules are for.** On the SQLite stand-in `lockForUpdate`
| and `sharedLock` compile to nothing, so no behavioural test can catch a missing
| lock or a decision resting on a stale REPEATABLE READ snapshot. Plan 0.3 makes
| both a source construction: a write handler never calls a terminal read on a
| builder (it calls an `AccountRows` / `PeriodRows` method whose name states its
| lock mode), and those two classes lock every terminal read outside the methods
| named `*Unlocked`. This file enforces both halves.
|
| **One writer** covers production source and migrations only (`src/`, `app/`,
| `database/`), never tests: catalog and customers tests insert into `accounts`
| with raw SQL through `CatalogSeed::account` and `CustomersSeed::account`,
| because the boundary check forbids those packages importing Finance.
*/

/** Every Finance slice that writes (plan Ruling 9). A slice not listed here or below fails the scope case. */
const QA_FINANCE_WRITE_SLICES = [
    'CreateAccount', 'UpdateAccount', 'ReparentAccount', 'DeactivateAccount', 'ReactivateAccount', 'DeleteAccount',
    'CreateFiscalYear', 'RenameFiscalYear', 'DeleteFiscalYear', 'RenameFiscalPeriod',
    'TransitionFiscalPeriod', 'CloseFiscalYear', 'ReopenFiscalYear',
];

/** Every Finance slice that only reads (plan Ruling 9, Ruling 10). */
const QA_FINANCE_READ_SLICES = [
    'ListAccounts', 'GetAccount', 'GetAccountTree', 'ListFiscalYears', 'ListFiscalPeriods', 'FindPeriodByDate',
];

/**
 * The only callers of a `*Unlocked` read, as built, by path under `packages/finance/src` and by method.
 *
 * The plan's wording is "read slices, and `PeriodRows::immutableCoordinates`". As built, the coordinates read is three
 * small methods on `PeriodRows` that the two transition handlers call to learn where to take their locks, and the
 * contract implementation answers a caller outside any transaction with plain reads. Each entry is exact, so a new
 * unlocked read in a write handler is a failure, never a silent widening.
 *
 * @var array<string, list<string>>
 */
const QA_FINANCE_UNLOCKED_CALLERS = [
    'Features/GetAccount/GetAccountHandler.php' => ['liveByIdUnlocked'],
    'Features/GetAccountTree/GetAccountTreeHandler.php' => ['liveTreeUnlocked'],
    'Features/ListAccounts/ListAccountsHandler.php' => ['pageUnlocked'],
    'Features/ListFiscalYears/ListFiscalYearsHandler.php' => ['yearsPageUnlocked'],
    'Features/ListFiscalPeriods/ListFiscalPeriodsHandler.php' => ['yearByIdUnlocked', 'periodsOfYearUnlocked'],
    'Features/FindPeriodByDate/FindPeriodByDateHandler.php' => ['periodCoveringUnlocked'],
    'Features/TransitionFiscalPeriod/TransitionFiscalPeriodHandler.php' => ['periodByIdUnlocked', 'previousPeriodStartUnlocked', 'nextPeriodStartUnlocked'],
    'Features/ReopenFiscalYear/ReopenFiscalYearHandler.php' => ['yearByIdUnlocked'],
    'Features/ReactivateAccount/ReactivateAccountHandler.php' => ['parentIdUnlocked'],
    'Accounts/DatabaseChartOfAccounts.php' => ['byIdUnlocked', 'byCodeUnlocked', 'liveByControlTypeUnlocked'],
    // T-033 (Ruling 7 step 2, Ruling 8 steps 4 and 7): the replay look-up and the original's read are plain by design (a
    // shared-lock look-up of a missing key takes a gap lock and two first posts would deadlock); the original's lines are
    // immutable, read before the original is locked so that the account locks come first (Ruling 9).
    'Journal/DatabasePostingService.php' => ['byKeyUnlocked', 'entryByIdUnlocked', 'linesOfEntryUnlocked'],
];

/**
 * The four migrations that own the three tables, by exact name. Index 3, the seed, is the one-writer rule's only
 * exemption: it is the only one that writes through `table()` (the others create the tables).
 */
const QA_FINANCE_OWNING_MIGRATIONS = [
    '2026_11_15_003201_create_accounts_table.php',
    '2026_11_15_003202_create_fiscal_years_table.php',
    '2026_11_15_003203_create_fiscal_periods_table.php',
    '2026_11_15_003204_seed_chart_of_accounts_and_first_fiscal_year.php',
];

/**
 * T-033's contract file for `fiscal_years.retained_earnings_entry_id` (T-033 D-42, F-24). It counts orphans with a
 * literal `table('fiscal_years')`, so the one-writer scan reports it, and it is exempted here by name, visibly, rather
 * than passing table names through variables to get past the scan.
 */
const QA_FINANCE_T033_CONTRACT_MIGRATION = '2026_11_16_003304_add_retained_earnings_entry_foreign_key.php';

const QA_FINANCE_TERMINAL_READ = '/->\s*(?:first|get|exists|count|value|pluck|find|sole|max|min)\s*\(/';
const QA_FINANCE_LOCK_CALL = '/->\s*(?:lockForUpdate|sharedLock)\s*\(/';

function qaFinanceRepoRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaFinanceFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_finance_scan_'.bin2hex(random_bytes(6));

    mkdir($directory, 0777, true);

    return $directory;
}

function qaFinanceDeleteDirectory(string $directory): void
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
function qaFinanceSourceFiles(string $directory, string $prefix = ''): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        // `$this->tenant->get()` is the connection accessor, not a terminal read: blank it so the read rules see builders only.
        $code = SourceRules::codeOnly((string) file_get_contents($file->getRealPath()));
        $files[$prefix.str_replace('\\', '/', $file->getRelativePathname())] = (string) preg_replace('/\btenant\s*->\s*get\s*\(\s*\)/', 'tenant', $code);
    }

    return $files;
}

function qaFinanceRealPath(string $relative): string
{
    $real = realpath(qaFinanceRepoRoot().'/'.$relative);

    if ($real === false) {
        throw new \RuntimeException("Expected [{$relative}] to exist; a rename must fail this scan loudly, not widen it.");
    }

    return $real;
}

/**
 * Every file of the Finance package's src, code only, keyed by path under `packages/finance/src`. Read once per process.
 *
 * @return array<string, string>
 */
function qaFinancePackageFiles(): array
{
    static $files = null;

    return $files ??= qaFinanceSourceFiles(qaFinanceRealPath('packages/finance/src'));
}

/**
 * @param  list<string>  $patterns
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaFinancePatternOffenders(array $patterns, array $files): array
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
 * The slice a Finance path belongs to, or null for a file outside `Features/`.
 */
function qaFinanceSliceOf(string $path): ?string
{
    return preg_match('#\AFeatures/([^/]+)/#', $path, $match) === 1 ? $match[1] : null;
}

/**
 * Write slices that call a terminal read on a builder. `$this->tenant->get()` is the connection accessor, not a read, so
 * it is blanked first; `->table('x')->get()` after it is still seen.
 *
 * @param  array<string, string>  $files  keyed by path under packages/finance/src
 * @param  list<string>  $writeSlices
 * @return list<string>
 */
function qaFinanceTerminalReadOffenders(array $files, array $writeSlices): array
{
    $found = [];

    foreach ($files as $path => $code) {
        if (! in_array(qaFinanceSliceOf($path), $writeSlices, true)) {
            continue;
        }

        $blanked = (string) preg_replace('/\btenant\s*->\s*get\s*\(\s*\)/', 'tenant', $code);

        if (preg_match(QA_FINANCE_TERMINAL_READ, $blanked) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * The statements of each method of one class, as `method => list<statement>`.
 *
 * @return array<string, list<string>>
 */
function qaFinanceMethodStatements(string $code): array
{
    preg_match_all('/function\s+(\w+)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);

    $methods = [];

    foreach ($matches[1] as $index => [$name, $offset]) {
        $end = $matches[0][$index + 1][1] ?? strlen($code);
        $methods[$name] = explode(';', substr($code, $offset, $end - $offset));
    }

    return $methods;
}

/**
 * Terminal reads, in methods not named `*Unlocked`, whose statement chains neither `lockForUpdate()` nor `sharedLock()`.
 *
 * @param  array<string, string>  $files
 * @return list<string> `file::method`
 */
function qaFinanceUnlockedTerminalOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        foreach (qaFinanceMethodStatements($code) as $method => $statements) {
            if (str_ends_with($method, 'Unlocked')) {
                continue;
            }

            foreach ($statements as $statement) {
                if (preg_match(QA_FINANCE_TERMINAL_READ, $statement) === 1 && preg_match(QA_FINANCE_LOCK_CALL, $statement) !== 1) {
                    $found[] = $path.'::'.$method;

                    break;
                }
            }
        }
    }

    return $found;
}

/**
 * Calls of a `*Unlocked` method from a file, or a method, that is not on the allow-list.
 *
 * @param  array<string, string>  $files  keyed by path under packages/finance/src
 * @param  array<string, list<string>>  $allowed
 * @return list<string> `file -> method`
 */
function qaFinanceUnlockedCallerOffenders(array $files, array $allowed): array
{
    $found = [];

    foreach ($files as $path => $code) {
        if (in_array($path, ['Accounts/AccountRows.php', 'Periods/PeriodRows.php'], true)) {
            continue;
        }

        preg_match_all('/->\s*(\w+Unlocked)\s*\(/', $code, $matches);

        foreach (array_unique($matches[1]) as $method) {
            if (! in_array($method, $allowed[$path] ?? [], true)) {
                $found[] = $path.' -> '.$method;
            }
        }
    }

    return $found;
}

/**
 * Every statement in a file that reads a terminal result without a lock in the same chain.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaFinanceUnlockedStatementOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        foreach (explode(';', $code) as $statement) {
            if (preg_match(QA_FINANCE_TERMINAL_READ, $statement) === 1 && preg_match(QA_FINANCE_LOCK_CALL, $statement) !== 1) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

/**
 * @param  array<string, string>  $files  keyed by repo-relative path
 * @param  list<string>  $exemptBasenames
 * @return list<string>
 */
function qaFinanceWriterOffenders(array $files, array $exemptBasenames): array
{
    $pattern = '/\btable\s*\(\s*[\'"](?:accounts|fiscal_years|fiscal_periods)[\'"]/';
    $found = [];

    foreach ($files as $path => $code) {
        if (in_array(basename($path), $exemptBasenames, true)) {
            continue;
        }

        if (preg_match($pattern, $code) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaFinanceUnreachableValueOffenders(array $files): array
{
    $patterns = [
        '/[\'"]closing[\'"]/',
        '/\bFiscalYearStatus\s*::\s*Closing\b/',
        '/[\'"]is_system[\'"]\s*=>\s*+(?!false\b)/i',
        '/->\s*is_system\s*=(?!=)/',
    ];

    return qaFinancePatternOffenders($patterns, $files);
}

/**
 * Imports and fully-qualified names that leave the contracts namespace and PHP core.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaFinanceContractImportOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        preg_match_all('/^\s*use\s+(?:function\s+|const\s+)?([^;{\s]+)/m', $code, $uses);

        foreach ($uses[1] as $import) {
            if (str_contains($import, '\\') && ! str_starts_with(ltrim($import, '\\'), 'Flexipos\\Contracts\\')) {
                $found[] = $path.' imports '.$import;
            }
        }

        if (preg_match('/\\\\?(?:Illuminate|Symfony|Carbon|Psr|Laravel|Flexipos\\\\(?!Contracts\\\\))/', $code) === 1) {
            $found[] = $path.' names a vendor or module class';
        }
    }

    return $found;
}

/**
 * Classes under packages/ that implement AccountReferrers, by repo-relative path.
 *
 * @return array<string, string>
 */
function qaFinanceReferrerImplementations(): array
{
    $found = [];

    foreach (glob(qaFinanceRepoRoot().'/packages/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $package = basename(dirname($directory));

        foreach (qaFinanceSourceFiles($directory, 'packages/'.$package.'/src/') as $path => $code) {
            if (preg_match('/\bimplements\s+[^{]*\bAccountReferrers\b/', $code) === 1) {
                $found[$path] = $code;
            }
        }
    }

    return $found;
}

// ------------------------------------------------------------------ scope

it('every scanned directory exists and holds the files the rules name, and every slice is classified', function () {
    $paths = array_keys(qaFinancePackageFiles());
    $slices = array_values(array_unique(array_filter(array_map(qaFinanceSliceOf(...), $paths))));

    sort($slices);

    $classified = array_merge(QA_FINANCE_WRITE_SLICES, QA_FINANCE_READ_SLICES);

    sort($classified);

    expect(count($paths))->toBeGreaterThan(60)
        ->and($paths)->toContain('Accounts/AccountRows.php')
        ->and($paths)->toContain('Periods/PeriodRows.php')
        ->and($paths)->toContain('Accounts/DatabaseChartOfAccounts.php')
        ->and($paths)->toContain('Features/CreateAccount/CreateAccountHandler.php')
        ->and($slices)->toBe($classified);

    foreach (array_keys(QA_FINANCE_UNLOCKED_CALLERS) as $path) {
        expect($paths)->toContain($path);
    }

    foreach (QA_FINANCE_OWNING_MIGRATIONS as $name) {
        expect(is_file(qaFinanceRealPath('modules/host/database/migrations-tenant').'/'.$name))->toBeTrue();
    }
});

// ------------------------------------------------------------------ 0.3: write handlers call no terminal read

it('AC-21, AC-44 construction: no write slice calls first, get, exists, count, value or pluck on a builder', function () {
    $offenders = qaFinanceTerminalReadOffenders(qaFinancePackageFiles(), QA_FINANCE_WRITE_SLICES);

    expect($offenders)->toBe([]);
});

it('AC-21, AC-44 construction control: each terminal read in a planted write handler is reported; the connection accessor and a read slice are not', function () {
    $directory = qaFinanceFreshTempDirectory();
    $reads = ['first', 'get', 'exists', 'count', 'value', 'pluck'];

    try {
        mkdir($directory.'/Features/CreateAccount', 0777, true);

        foreach ($reads as $read) {
            file_put_contents($directory.'/Features/CreateAccount/Planted'.$read.'.php', "<?php\n\$this->tenant->get()->table('accounts')->{$read}('id');\n");
        }

        mkdir($directory.'/Features/GetAccount', 0777, true);
        file_put_contents($directory.'/Features/GetAccount/Read.php', "<?php\n\$this->tenant->get()->table('accounts')->first();\n");
        file_put_contents($directory.'/Features/CreateAccount/Clean.php', "<?php\n\$this->tenant->get()->transaction(fn () => \$this->rows->liveForUpdate(\$id));\n// \$q->first()\n");

        $offenders = qaFinanceTerminalReadOffenders(qaFinanceSourceFiles($directory), QA_FINANCE_WRITE_SLICES);

        expect($offenders)->toHaveCount(count($reads))
            ->and(implode("\n", $offenders))->not->toContain('Clean.php')
            ->and(implode("\n", $offenders))->not->toContain('GetAccount');
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ 0.3: the rows classes lock every terminal read

it('AC-21, AC-50 construction: every terminal read in AccountRows and PeriodRows outside a *Unlocked method chains lockForUpdate or sharedLock', function () {
    $files = array_intersect_key(qaFinancePackageFiles(), array_flip(['Accounts/AccountRows.php', 'Periods/PeriodRows.php']));

    expect(array_keys($files))->toHaveCount(2)
        ->and(qaFinanceUnlockedTerminalOffenders($files))->toBe([]);

    // The scan sees the locking methods: each class has several, and the *Unlocked methods are the ones it skips.
    foreach ($files as $code) {
        expect(preg_match_all(QA_FINANCE_LOCK_CALL, $code))->toBeGreaterThan(5)
            ->and(preg_match('/function\s+\w+Unlocked\s*\(/', $code))->toBe(1);
    }
});

it('AC-21, AC-50 construction control: a planted unlocked terminal read is reported by method, a locked one and an *Unlocked one are not', function () {
    $directory = qaFinanceFreshTempDirectory();

    try {
        file_put_contents($directory.'/Rows.php', "<?php\nclass Rows {\n"
            ."public function bare(string \$id): ?object { return \$this->query()->where('id', \$id)->first(); }\n"
            ."public function counted(string \$id): int { return \$this->query()->where('id', \$id)->count(); }\n"
            ."public function locked(string \$id): ?object { return \$this->query()->where('id', \$id)->sharedLock()->first(); }\n"
            ."public function updating(string \$id): ?object { return \$this->query()->where('id', \$id)->lockForUpdate()->first(); }\n"
            ."public function plainUnlocked(string \$id): ?object { return \$this->query()->where('id', \$id)->first(); }\n"
            ."}\n");

        expect(qaFinanceUnlockedTerminalOffenders(qaFinanceSourceFiles($directory)))->toBe(['Rows.php::bare', 'Rows.php::counted']);
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

it('AC-12, AC-21 construction: *Unlocked methods are called from the read slices, the contract reads and the coordinates reads, and nowhere else', function () {
    expect(qaFinanceUnlockedCallerOffenders(qaFinancePackageFiles(), QA_FINANCE_UNLOCKED_CALLERS))->toBe([]);

    // The allow-list is exact, not a ceiling: every entry names a call that is really there, and every read slice is on it.
    $files = qaFinancePackageFiles();

    foreach (QA_FINANCE_UNLOCKED_CALLERS as $path => $methods) {
        foreach ($methods as $method) {
            expect(preg_match('/->\s*'.$method.'\s*\(/', $files[$path]))->toBe(1, "[{$path}] no longer calls [{$method}]: remove it from the allow-list.");
        }
    }

    foreach (QA_FINANCE_READ_SLICES as $slice) {
        expect(array_keys(QA_FINANCE_UNLOCKED_CALLERS))->toContain('Features/'.$slice.'/'.$slice.'Handler.php');
    }
});

it('AC-12, AC-21 construction control: an unlocked read planted in a write handler, or an unlisted method in a listed file, is reported', function () {
    $directory = qaFinanceFreshTempDirectory();

    try {
        mkdir($directory.'/Features/CreateAccount', 0777, true);
        mkdir($directory.'/Features/GetAccount', 0777, true);
        file_put_contents($directory.'/Features/CreateAccount/CreateAccountHandler.php', "<?php\n\$p = \$this->rows->byIdUnlocked(\$id);\n");
        file_put_contents($directory.'/Features/GetAccount/GetAccountHandler.php', "<?php\n\$a = \$this->rows->liveByIdUnlocked(\$id);\n\$b = \$this->rows->byCodeUnlocked(\$c);\n");
        file_put_contents($directory.'/Prose.php', "<?php\n/** byIdUnlocked( is the read slices' */\n\$x = 1;\n");

        $offenders = qaFinanceUnlockedCallerOffenders(qaFinanceSourceFiles($directory), QA_FINANCE_UNLOCKED_CALLERS);

        expect($offenders)->toBe([
            'Features/CreateAccount/CreateAccountHandler.php -> byIdUnlocked',
            'Features/GetAccount/GetAccountHandler.php -> byCodeUnlocked',
        ]);
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ AC-57: assignment and referrers

it('AC-57 construction: assignable() reads the account under a shared lock and never an unlocked read', function () {
    $body = qaFinanceMethodStatements(qaFinancePackageFiles()['Accounts/DatabaseChartOfAccounts.php'])['assignable'] ?? [];
    $text = implode(';', $body);

    expect($text)->not->toBe('')
        ->and(preg_match('/->\s*anySharedLock\s*\(/', $text))->toBe(1)
        ->and(preg_match('/Unlocked\s*\(/', $text))->toBe(0);
});

it('AC-57 construction: every AccountReferrers implementation, catalog and customers included, counts under sharedLock()', function () {
    $implementations = qaFinanceReferrerImplementations();

    expect(array_keys($implementations))->toContain('packages/catalog/src/Finance/CatalogAccountReferrers.php')
        ->and(array_keys($implementations))->toContain('packages/customers/src/Finance/CustomerAccountReferrers.php')
        ->and(qaFinanceUnlockedStatementOffenders($implementations))->toBe([]);

    foreach ($implementations as $code) {
        expect(preg_match_all('/->\s*sharedLock\s*\(/', $code))->toBeGreaterThanOrEqual(1);
    }
});

it('AC-57 construction control: a planted referrer count without a lock is reported, with a lock is not', function () {
    $directory = qaFinanceFreshTempDirectory();

    try {
        file_put_contents($directory.'/Bare.php', "<?php\nfinal class Bare implements AccountReferrers {\n"
            ."public function referrersOf(string \$id): array { \$n = \$c->table('products')->where('x', \$id)->count(); return []; }\n}\n");
        file_put_contents($directory.'/Locked.php', "<?php\nfinal class Locked implements AccountReferrers {\n"
            ."public function referrersOf(string \$id): array { \$n = \$c->table('products')->where('x', \$id)->sharedLock()->count(); return []; }\n}\n");

        $files = qaFinanceSourceFiles($directory);

        expect(qaFinanceUnlockedStatementOffenders($files))->toBe(['Bare.php'])
            ->and(preg_match('/\bimplements\s+[^{]*\bAccountReferrers\b/', $files['Locked.php']))->toBe(1);
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ AC-60: one writer

/**
 * Production source and migrations, never tests: every `packages/<p>/src` but Finance's, every `modules/<m>/src` and
 * `modules/<m>/app`, and the host's `database` tree.
 *
 * @return array<string, string> repo-relative path => code-only text
 */
function qaFinanceNonFinanceProductionFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $root = qaFinanceRepoRoot();
    $files = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $package = basename(dirname($directory));

        if ($package !== 'finance') {
            $files += qaFinanceSourceFiles($directory, 'packages/'.$package.'/src/');
        }
    }

    foreach (glob($root.'/modules/*', GLOB_ONLYDIR) ?: [] as $module) {
        foreach (['src', 'app', 'database'] as $directory) {
            $files += qaFinanceSourceFiles($module.'/'.$directory, 'modules/'.basename($module).'/'.$directory.'/');
        }
    }

    return $files;
}

it('AC-60: outside packages/finance/src and the seed and T-033 contract migrations, no production code or migration names table() on accounts, fiscal_years or fiscal_periods', function () {
    $files = qaFinanceNonFinanceProductionFiles();

    expect(count($files))->toBeGreaterThan(500)
        ->and(array_keys($files))->toContain('packages/catalog/src/Finance/CatalogAccountReferrers.php')
        ->and(array_keys($files))->toContain('modules/host/database/migrations-tenant/2026_11_15_003208_add_owed_account_foreign_keys.php')
        ->and(array_keys($files))->toContain('modules/host/database/migrations-tenant/'.QA_FINANCE_T033_CONTRACT_MIGRATION)
        ->and(qaFinanceWriterOffenders($files, [QA_FINANCE_OWNING_MIGRATIONS[3], QA_FINANCE_T033_CONTRACT_MIGRATION]))->toBe([]);

    // The exemption is real and exact: without it the seed migration and T-033's contract migration are reported (the create-table files use Schema::create and the owed-account foreign-key file reads through variables), and nothing else is.
    $unexempted = qaFinanceWriterOffenders($files, []);

    expect(array_map('basename', $unexempted))->toEqualCanonicalizing([QA_FINANCE_OWNING_MIGRATIONS[3], QA_FINANCE_T033_CONTRACT_MIGRATION]);
});

it('AC-60 control: a planted table() on each of the three tables is reported, a prose mention and an unrelated table are not', function () {
    $directory = qaFinanceFreshTempDirectory();

    try {
        foreach (['accounts', 'fiscal_years', 'fiscal_periods'] as $table) {
            file_put_contents($directory.'/'.$table.'.php', "<?php\n\$c->table('{$table}')->insert([]);\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n/** table('accounts') is Finance's */\n\$c->table('products')->get();\n\$c->from('accounts')->count();\n");

        $offenders = qaFinanceWriterOffenders(qaFinanceSourceFiles($directory), []);

        expect($offenders)->toHaveCount(3)->and($offenders)->not->toContain('Prose.php');
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ AC-27, D-5: values no input reaches

it('AC-37, D-5: no closing status and no is_system assignment other than the literal false exists in Finance source', function () {
    $files = qaFinancePackageFiles();

    expect(qaFinanceUnreachableValueOffenders($files))->toBe([]);

    // The scan sees the one writer of the flag: CreateAccountHandler assigns the literal false.
    expect(preg_match('/[\'"]is_system[\'"]\s*=>\s*false\b/', $files['Features/CreateAccount/CreateAccountHandler.php']))->toBe(1);

    // The seed migration is where is_system is computed, so it is the reason the rule is scoped to Finance's src.
    $seed = SourceRules::codeOnly((string) file_get_contents(qaFinanceRealPath('modules/host/database/migrations-tenant').'/'.QA_FINANCE_OWNING_MIGRATIONS[3]));

    expect(preg_match('/[\'"]is_system[\'"]\s*=>/', $seed))->toBe(1);
});

it('AC-37, D-5 control: each planted spelling is reported, the literal false and prose are not', function () {
    $directory = qaFinanceFreshTempDirectory();
    $spellings = [
        'Literal' => "\$row['status'] = 'closing';",
        'Double' => "\$row['status'] = \"closing\";",
        'Enum' => '$s = FiscalYearStatus::Closing;',
        'True' => "\$r = ['is_system' => true];",
        'Variable' => "\$r = ['is_system' => \$flag];",
        'Property' => '$row->is_system = 1;',
    ];

    try {
        foreach ($spellings as $name => $line) {
            file_put_contents($directory.'/'.$name.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\n\$r = ['is_system' => false];\nif (\$row->is_system === 1) {}\n\$m = ['is_system', 'path'];\n// 'closing' \$r['is_system'] = true\n");

        $offenders = qaFinanceUnreachableValueOffenders(qaFinanceSourceFiles($directory));

        expect($offenders)->toHaveCount(count($spellings))->and($offenders)->not->toContain('Clean.php');
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ AC-44, AC-50: no surface

it('Finance has no routes, no Route::, no Cache::, no dispatch(, no ShouldQueue and no HookGateway', function () {
    expect(is_dir(qaFinanceRepoRoot().'/packages/finance/routes'))->toBeFalse();

    $patterns = ['/\bRoute::/', '/\bCache::/', '/\bdispatch\s*\(/', '/\bShouldQueue\b/', '/\bHookGateway\b/'];

    expect(qaFinancePatternOffenders($patterns, qaFinancePackageFiles()))->toBe([]);

    $directory = qaFinanceFreshTempDirectory();

    try {
        foreach (['Route::get("/x", fn () => 1);', 'Cache::put("k", 1);', 'dispatch(new Job);', 'class J implements ShouldQueue {}', '$g = app(HookGateway::class);'] as $index => $line) {
            file_put_contents($directory.'/Planted'.$index.'.php', "<?php\n{$line}\n");
        }

        file_put_contents($directory.'/Prose.php', "<?php\n// Route::get and Cache::put and dispatch( and ShouldQueue and HookGateway\n\$x = 1;\n");

        $offenders = qaFinancePatternOffenders($patterns, qaFinanceSourceFiles($directory));

        expect($offenders)->toHaveCount(5)->and($offenders)->not->toContain('Prose.php');
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ FP-435: regex discipline

it('FP-435: no preg pattern in Finance source ends in $/ (a trailing newline would pass), every pattern uses \\z', function () {
    $pattern = '/\$\/[a-zA-Z]*[\'"]/';

    expect(qaFinancePatternOffenders([$pattern], qaFinancePackageFiles()))->toBe([]);

    // The scan sees real patterns: Input.php anchors with \z.
    expect(preg_match('/\\\\z\//', qaFinancePackageFiles()['Support/Input.php']))->toBe(1);

    $directory = qaFinanceFreshTempDirectory();

    try {
        file_put_contents($directory.'/Dollar.php', "<?php\n\$ok = preg_match('/^[A-Z]+\$/', \$v);\n");
        file_put_contents($directory.'/DollarFlags.php', "<?php\n\$ok = preg_match('/^[A-Z]+\$/D', \$v);\n");
        file_put_contents($directory.'/Anchored.php', "<?php\n\$ok = preg_match('/\\A[A-Z]+\\z/', \$v);\n");

        $offenders = qaFinancePatternOffenders([$pattern], qaFinanceSourceFiles($directory));

        expect($offenders)->toEqualCanonicalizing(['Dollar.php', 'DollarFlags.php']);
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ AC-51: contracts purity

it('AC-51: packages/contracts/src/Finance imports nothing outside contracts and PHP core', function () {
    $files = qaFinanceSourceFiles(qaFinanceRealPath('packages/contracts/src/Finance'));

    expect(count($files))->toBeGreaterThan(15)
        ->and(qaFinanceContractImportOffenders($files))->toBe([]);

    $directory = qaFinanceFreshTempDirectory();

    try {
        file_put_contents($directory.'/Vendor.php', "<?php\nuse Illuminate\\Support\\Str;\nfinal class A {}\n");
        file_put_contents($directory.'/Module.php', "<?php\nuse Flexipos\\Finance\\Accounts\\AccountRows;\nfinal class B {}\n");
        file_put_contents($directory.'/Inline.php', "<?php\nfinal class C { public function f(): \\Carbon\\Carbon { return 1; } }\n");
        file_put_contents($directory.'/Clean.php', "<?php\nuse Flexipos\\Contracts\\Finance\\AccountType;\nuse RuntimeException;\nfinal class D {}\n");

        $offenders = qaFinanceContractImportOffenders(qaFinanceSourceFiles($directory));

        expect(implode("\n", $offenders))->toContain('Vendor.php')
            ->and(implode("\n", $offenders))->toContain('Module.php')
            ->and(implode("\n", $offenders))->toContain('Inline.php')
            ->and(implode("\n", $offenders))->not->toContain('Clean.php');
    } finally {
        qaFinanceDeleteDirectory($directory);
    }
});
