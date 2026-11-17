<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-033 step E1 - the journal construction rules (AC-5, AC-47, AC-48, AC-63;
| the construction halves of AC-25 and AC-26), written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaFinanceSourceRulesTest: its own helpers (nothing is called
| from another Qa*SourceRulesTest file, FP-164), code only through
| SourceRules::codeOnly, and a planted-file control for every rule, run over a
| real directory so the scan itself is shown able to fail.
|
| Why these are source rules. The SQLite stand-in compiles `lockForUpdate` and
| `sharedLock` to nothing and enforces no CHECK, so no behavioural test can see a
| missing lock, a second writer of the journal, or a delete that only an engine
| would refuse. The journal's guarantees (one writer, an append-only record, one
| sanctioned update, locks in a stated order) are therefore constructions, and
| this file reads the construction.
|
| Anti-vacuity: a scan that finds nothing proves nothing, so the write scan is
| shown to find JournalWriter's own three statements (two inserts and the one
| compare-and-set), and every list the rules walk is shown non-empty.
*/

/** The two tables only JournalWriter may write. */
const QA_JOURNAL_TABLE_PATTERN = 'journal_(?:entries|lines)';

/** The one file allowed to write them, by path under the repository root. */
const QA_JOURNAL_WRITER = 'packages/finance/src/Journal/JournalWriter.php';

/** The write verbs a query-builder chain can end in. */
const QA_JOURNAL_BUILDER_WRITE = '/->\s*(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\s*\(/';

const QA_JOURNAL_TERMINAL_READ = '/->\s*(?:first|get|exists|count|value|pluck|find|sole|max|min)\s*\(/';
const QA_JOURNAL_LOCK_CALL = '/->\s*(?:lockForUpdate|sharedLock)\s*\(/';

function qaJournalRoot(): string
{
    return dirname(__DIR__, 4);
}

function qaJournalRealPath(string $relative): string
{
    $real = realpath(qaJournalRoot().'/'.$relative);

    if ($real === false) {
        throw new \RuntimeException("Expected [{$relative}] to exist; a rename must fail this scan loudly, not widen it.");
    }

    return $real;
}

function qaJournalFreshDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_journal_scan_'.bin2hex(random_bytes(6));

    mkdir($directory, 0777, true);

    return $directory;
}

function qaJournalDeleteDirectory(string $directory): void
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
 * @return array<string, string> path under $prefix, forward slashes => code-only text
 */
function qaJournalSourceFiles(string $directory, string $prefix = ''): array
{
    $files = [];

    if (! is_dir($directory)) {
        return $files;
    }

    foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
        $code = SourceRules::codeOnly((string) file_get_contents($file->getRealPath()));
        // `$this->tenant->get()` is the connection accessor, not a terminal read.
        $files[$prefix.str_replace('\\', '/', $file->getRelativePathname())] = (string) preg_replace('/\btenant\s*->\s*get\s*\(\s*\)/', 'tenant', $code);
    }

    return $files;
}

/**
 * Every production file the rules name: `packages/<p>/src`, `modules/<m>/src`, `modules/host/app`, `modules/host/database`.
 * Never tests. Read once per process.
 *
 * @return array<string, string> repo-relative path => code-only text
 */
function qaJournalScannedFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $root = qaJournalRoot();
    $files = [];

    foreach (glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $files += qaJournalSourceFiles($directory, 'packages/'.basename(dirname($directory)).'/src/');
    }

    foreach (glob($root.'/modules/*/src', GLOB_ONLYDIR) ?: [] as $directory) {
        $files += qaJournalSourceFiles($directory, 'modules/'.basename(dirname($directory)).'/src/');
    }

    $files += qaJournalSourceFiles(qaJournalRealPath('modules/host/app'), 'modules/host/app/');
    $files += qaJournalSourceFiles(qaJournalRealPath('modules/host/database'), 'modules/host/database/');

    return $files;
}

/**
 * Finance's own files, keyed by path under `packages/finance/src`.
 *
 * @return array<string, string>
 */
function qaJournalFinanceFiles(): array
{
    static $files = null;

    return $files ??= qaJournalSourceFiles(qaJournalRealPath('packages/finance/src'));
}

/**
 * Every write to a journal table that a file contains, as `path :: verb`: a builder chain on `table('journal_…')`,
 * `from(` or `into(` ending in a write verb, or a raw `insert into` / `update` / `delete from` / `truncate` /
 * `replace into` naming the table. A file that names a journal table in quotes and also calls a write verb is reported
 * as `path :: split-chain`, so the table cannot be parked in a variable to get past the statement scan.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaJournalWrites(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        foreach (explode(';', $code) as $statement) {
            if (preg_match('/\b(?:table|from|into)\s*\(\s*[\'"]'.QA_JOURNAL_TABLE_PATTERN.'[\'"]/', $statement) === 1
                && preg_match_all(QA_JOURNAL_BUILDER_WRITE, $statement, $verbs) > 0) {
                foreach ($verbs[1] as $verb) {
                    $found[] = $path.' :: '.$verb;
                }
            }

            if (preg_match('/\b(insert\s+(?:ignore\s+)?into|update|delete\s+from|truncate(?:\s+table)?|replace\s+into)\s+`?'.QA_JOURNAL_TABLE_PATTERN.'\b/i', $statement, $raw) === 1) {
                $found[] = $path.' :: raw '.strtolower((string) preg_replace('/\s+/', ' ', $raw[1]));
            }
        }

        if (preg_match('/\$\w+\s*=\s*[^;=]*?\b(?:table|from)\s*\(\s*[\'"]'.QA_JOURNAL_TABLE_PATTERN.'[\'"]/', $code) === 1
            && preg_match('/\$\w+\s*->\s*(?:insert|insertGetId|insertOrIgnore|update|upsert|delete|truncate|increment|decrement)\s*\(/', $code) === 1
            && ! preg_grep('/\A'.preg_quote($path, '/').' :: /', $found)) {
            $found[] = $path.' :: split-chain';
        }
    }

    return $found;
}

/**
 * The writes of a path other than the one file allowed to make them.
 *
 * @param  list<string>  $writes
 * @return list<string>
 */
function qaJournalForeignWrites(array $writes, string $allowedPath): array
{
    return array_values(array_filter($writes, static fn (string $write): bool => ! str_starts_with($write, $allowedPath.' :: ')));
}

/**
 * Deletes and truncates, wherever they are.
 *
 * @param  list<string>  $writes
 * @return list<string>
 */
function qaJournalDeletes(array $writes): array
{
    return array_values(array_filter($writes, static fn (string $write): bool => preg_match('/ :: (?:raw )?(?:delete|truncate|replace)/', $write) === 1));
}

/**
 * The column lists of every `->update([...])` in a file, one list per call.
 *
 * @return list<list<string>>
 */
function qaJournalUpdateKeys(string $code): array
{
    preg_match_all('/->\s*update\s*\(\s*\[(.*?)\]\s*\)/s', $code, $calls);

    $lists = [];

    foreach ($calls[1] as $body) {
        preg_match_all('/[\'"](\w+)[\'"]\s*=>/', $body, $keys);
        $lists[] = $keys[1];
    }

    return $lists;
}

/**
 * The code of every method of a class, as `method => code`.
 *
 * @return array<string, string>
 */
function qaJournalMethods(string $code): array
{
    preg_match_all('/function\s+(\w+)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);

    $methods = [];

    foreach ($matches[1] as $index => [$name, $offset]) {
        $end = $matches[0][$index + 1][1] ?? strlen($code);
        $methods[$name] = substr($code, $offset, $end - $offset);
    }

    return $methods;
}

/**
 * Statements, per method not named `*Unlocked`, that read a terminal result without a lock in the same chain.
 *
 * @param  array<string, string>  $files
 * @return list<string> `file::method`
 */
function qaJournalUnlockedTerminalOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        foreach (qaJournalMethods($code) as $method => $body) {
            if (str_ends_with($method, 'Unlocked')) {
                continue;
            }

            foreach (explode(';', $body) as $statement) {
                if (preg_match(QA_JOURNAL_TERMINAL_READ, $statement) === 1 && preg_match(QA_JOURNAL_LOCK_CALL, $statement) !== 1) {
                    $found[] = $path.'::'.$method;

                    break;
                }
            }
        }
    }

    return $found;
}

/**
 * Files that name a journal table in code.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaJournalTableNamers(array $files): array
{
    return array_keys(array_filter($files, static fn (string $code): bool => preg_match('/'.QA_JOURNAL_TABLE_PATTERN.'/', $code) === 1));
}

/**
 * Float arithmetic or a float type in a file: a cast, a conversion, a rounding call or a float declaration. `is_float`
 * (the read-back guard for the stand-in's REAL) is a different token and is not reported.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaJournalFloatOffenders(array $files): array
{
    $found = [];

    foreach ($files as $path => $code) {
        // String literals are blanked: an exception message may say "float"; only code can do arithmetic.
        $code = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $code);

        if (preg_match('/\b(?:float|double)\b|\b(?:floatval|doubleval)\s*\(|\b(?:round|floor|ceil|fdiv)\s*\(|\(\s*(?:float|double|real)\s*\)/', $code) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

// ------------------------------------------------------------------ scope

it('every scanned directory exists and holds the journal files the rules name', function () {
    $paths = array_keys(qaJournalScannedFiles());
    $finance = array_keys(qaJournalFinanceFiles());

    expect(count($paths))->toBeGreaterThan(500)
        ->and($paths)->toContain(QA_JOURNAL_WRITER)
        ->and($paths)->toContain('packages/organization/src/Numbering/DatabaseDocumentNumbers.php')
        ->and($paths)->toContain('modules/host/database/migrations-tenant/2026_11_16_003301_create_journal_entries_table.php')
        ->and($finance)->toContain('Journal/JournalRows.php')
        ->and($finance)->toContain('Journal/DatabasePostingService.php')
        ->and($finance)->toContain('Journal/Ports/JournalAccountUsage.php')
        ->and($finance)->toContain('Features/GetJournalEntry/GetJournalEntryHandler.php')
        ->and($finance)->toContain('Features/ListJournalEntries/ListJournalEntriesHandler.php');
});

// ------------------------------------------------------------------ AC-5, AC-47, AC-48

it('AC-5 anti-vacuity: the write scan finds JournalWriter\'s own two inserts and its one update', function () {
    $files = qaJournalScannedFiles();
    $own = qaJournalWrites([QA_JOURNAL_WRITER => $files[QA_JOURNAL_WRITER]]);

    expect($own)->toBe([
        QA_JOURNAL_WRITER.' :: insert',
        QA_JOURNAL_WRITER.' :: insert',
        QA_JOURNAL_WRITER.' :: update',
    ]);
});

it('AC-5: no file but JournalWriter writes journal_entries or journal_lines, by builder chain or raw statement', function () {
    $writes = qaJournalWrites(qaJournalScannedFiles());

    expect($writes)->not->toBe([])
        ->and(qaJournalForeignWrites($writes, QA_JOURNAL_WRITER))->toBe([]);
});

it('AC-5 control: a planted write on each verb, raw statement and split chain is reported; a read, a prose mention and another table are not', function () {
    $directory = qaJournalFreshDirectory();

    try {
        $planted = [
            "\$c->table('journal_lines')->update(['x' => 1]);",
            "\$c->table(\"journal_entries\")->where('id', 1)->increment('total_debit');",
            "\$c->table('journal_lines')->upsert([], ['id']);",
            "\$c->statement('insert into journal_lines (id) values (1)');",
            "\$c->statement('UPDATE `journal_entries` set status = 1');",
            "\$q = \$c->table('journal_lines');\n\$q->delete();",
        ];

        foreach ($planted as $index => $statement) {
            file_put_contents($directory.'/P'.$index.'.php', "<?php\n".$statement."\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\n/** table('journal_lines')->update([]) is the writer's */\n\$c->table('journal_lines')->sharedLock()->exists();\n\$c->table('products')->update([]);\n\$c->statement('select * from journal_lines');\n");

        $offenders = qaJournalForeignWrites(qaJournalWrites(qaJournalSourceFiles($directory)), 'none');

        expect($offenders)->toHaveCount(count($planted));

        foreach (array_keys($planted) as $index) {
            expect(implode("\n", $offenders))->toContain('P'.$index.'.php');
        }

        expect(implode("\n", $offenders))->not->toContain('Clean.php');
    } finally {
        qaJournalDeleteDirectory($directory);
    }
});

it('AC-47: nothing anywhere deletes from or truncates either journal table', function () {
    expect(qaJournalDeletes(qaJournalWrites(qaJournalScannedFiles())))->toBe([]);
});

it('AC-47 control: a planted delete, truncate and raw delete are each reported, even in JournalWriter\'s own path', function () {
    $directory = qaJournalFreshDirectory();

    try {
        file_put_contents($directory.'/A.php', "<?php\n\$c->table('journal_lines')->where('id', 1)->delete();\n");
        file_put_contents($directory.'/B.php', "<?php\n\$c->table('journal_entries')->truncate();\n");
        file_put_contents($directory.'/C.php', "<?php\n\$c->statement('delete from journal_lines where 1');\n");
        file_put_contents($directory.'/D.php', "<?php\n\$c->table('journal_lines')->insert([]);\n");

        $deletes = qaJournalDeletes(qaJournalWrites(qaJournalSourceFiles($directory)));

        expect($deletes)->toHaveCount(3)->and(implode("\n", $deletes))->not->toContain('D.php');
    } finally {
        qaJournalDeleteDirectory($directory);
    }
});

it('AC-47: Finance has no route, no command and no console registration', function () {
    $offenders = [];

    foreach (qaJournalFinanceFiles() as $path => $code) {
        if (preg_match('/\bRoute\s*::|loadRoutesFrom|Illuminate\\\\Console|\bArtisan\s*::|->\s*commands\s*\(|\bextends\s+Command\b|\$signature\b|\bSchedule\b/', $code) === 1) {
            $offenders[] = $path;
        }
    }

    expect(count(qaJournalFinanceFiles()))->toBeGreaterThan(60)->and($offenders)->toBe([]);
});

it('AC-48: the only update in JournalWriter sets exactly status, reversed_by_id and updated_at', function () {
    $code = qaJournalScannedFiles()[QA_JOURNAL_WRITER];
    $lists = qaJournalUpdateKeys($code);

    expect($lists)->toHaveCount(1);

    $keys = $lists[0];
    sort($keys);

    expect($keys)->toBe(['reversed_by_id', 'status', 'updated_at'])
        ->and(preg_match_all('/->\s*update\s*\(/', $code))->toBe(1)
        ->and($code)->toContain("->where('status', 'posted')");
});

it('AC-48 control: a planted extra update key, a second update and a missing key are each reported', function () {
    $good = "<?php\n\$c->table('journal_entries')->update(['status' => 'reversed', 'reversed_by_id' => \$r, 'updated_at' => \$n]);\n";

    $extraKey = str_replace("'updated_at'", "'total_debit' => 0, 'updated_at'", $good);
    $second = $good."\$c->table('journal_lines')->update(['memo' => 'x']);\n";
    $missing = str_replace(", 'updated_at' => \$n", '', $good);

    $sorted = static function (string $code): array {
        $lists = qaJournalUpdateKeys($code);
        $keys = $lists[0] ?? [];
        sort($keys);

        return [count($lists), $keys];
    };

    expect($sorted($good))->toBe([1, ['reversed_by_id', 'status', 'updated_at']])
        ->and($sorted($extraKey))->not->toBe([1, ['reversed_by_id', 'status', 'updated_at']])
        ->and($sorted($second)[0])->toBe(2)
        ->and($sorted($missing))->not->toBe([1, ['reversed_by_id', 'status', 'updated_at']]);
});

// ------------------------------------------------------------------ AC-63

it('AC-63: no Finance file outside Journal/ and the two journal read slices names a journal table', function () {
    $outside = array_filter(
        qaJournalFinanceFiles(),
        static fn (string $code, string $path): bool => ! str_starts_with($path, 'Journal/')
            && ! str_starts_with($path, 'Features/GetJournalEntry/')
            && ! str_starts_with($path, 'Features/ListJournalEntries/'),
        ARRAY_FILTER_USE_BOTH,
    );

    // Anti-vacuity: the namers inside the allowed area are found by the same scan.
    $inside = qaJournalTableNamers(array_diff_key(qaJournalFinanceFiles(), $outside));

    expect(count($outside))->toBeGreaterThan(40)
        ->and($inside)->toContain('Journal/JournalWriter.php')
        ->and($inside)->toContain('Journal/Ports/JournalAccountUsage.php')
        ->and(qaJournalTableNamers($outside))->toBe([]);
});

it('AC-63 control: a planted journal table name in a file outside the allowed area is reported', function () {
    $directory = qaJournalFreshDirectory();

    try {
        file_put_contents($directory.'/Planted.php', "<?php\n\$c->table('journal_lines')->count();\n");
        file_put_contents($directory.'/Prose.php', "<?php\n/** journal_lines is the journal's */\n\$c->table('accounts')->count();\n");

        expect(qaJournalTableNamers(qaJournalSourceFiles($directory)))->toBe(['Planted.php']);
    } finally {
        qaJournalDeleteDirectory($directory);
    }
});

// ------------------------------------------------------------------ locks (construction halves of AC-25, AC-26)

it('every terminal read in the three journal ports chains sharedLock or lockForUpdate', function () {
    $ports = array_filter(qaJournalFinanceFiles(), static fn (string $code, string $path): bool => str_starts_with($path, 'Journal/Ports/'), ARRAY_FILTER_USE_BOTH);

    expect($ports)->toHaveCount(3);

    foreach ($ports as $path => $code) {
        expect(preg_match(QA_JOURNAL_TERMINAL_READ, $code))->toBe(1, "[{$path}] must read something")
            ->and(preg_match_all('/->\s*sharedLock\s*\(/', $code))->toBe(1, "[{$path}] must chain sharedLock");
    }

    expect(qaJournalUnlockedTerminalOffenders($ports))->toBe([]);
});

it('every terminal read in JournalRows outside a method named *Unlocked chains a lock', function () {
    $rows = ['Journal/JournalRows.php' => qaJournalFinanceFiles()['Journal/JournalRows.php']];

    expect(preg_match_all(QA_JOURNAL_LOCK_CALL, $rows['Journal/JournalRows.php']))->toBeGreaterThanOrEqual(2)
        ->and(array_keys(qaJournalMethods($rows['Journal/JournalRows.php'])))->toContain('byKeyForUpdate', 'entryForUpdate', 'byKeyUnlocked')
        ->and(qaJournalUnlockedTerminalOffenders($rows))->toBe([]);
});

it('the unlocked-terminal construction control: a bare read, a counted read, a locked read and an *Unlocked read are told apart', function () {
    $planted = [
        'Rows.php' => "<?php\nclass R {\n"
            ."public function bare(string \$id): ?object { return \$this->entries()->where('id', \$id)->first(); }\n"
            ."public function counted(string \$id): int { return \$this->entries()->where('id', \$id)->count(); }\n"
            ."public function locked(string \$id): ?object { return \$this->entries()->where('id', \$id)->lockForUpdate()->first(); }\n"
            ."public function shared(string \$id): bool { return \$this->entries()->where('id', \$id)->sharedLock()->exists(); }\n"
            ."public function plainUnlocked(string \$id): ?object { return \$this->entries()->where('id', \$id)->first(); }\n}\n",
    ];

    $directory = qaJournalFreshDirectory();

    try {
        file_put_contents($directory.'/Rows.php', $planted['Rows.php']);

        expect(qaJournalUnlockedTerminalOffenders(qaJournalSourceFiles($directory)))->toBe(['Rows.php::bare', 'Rows.php::counted']);
    } finally {
        qaJournalDeleteDirectory($directory);
    }
});

it('postable is the only account read in Journal/, and the journal never names the accounts table', function () {
    $calls = [];
    $tables = [];

    foreach (qaJournalFinanceFiles() as $path => $code) {
        if (! str_starts_with($path, 'Journal/')) {
            continue;
        }

        preg_match_all('/->\s*accounts\s*->\s*(\w+)\s*\(/', $code, $matches);

        foreach ($matches[1] as $method) {
            $calls[] = $method;
        }

        if (preg_match('/\b(?:table|from|join)\s*\(\s*[\'"]accounts[\'"]/', $code) === 1) {
            $tables[] = $path;
        }
    }

    expect($calls)->not->toBe([])
        ->and(array_values(array_unique($calls)))->toBe(['postable'])
        ->and($tables)->toBe([]);
});

it('reverse makes no call into JournalReferences (D-35), and post does', function () {
    $methods = qaJournalMethods(qaJournalFinanceFiles()['Journal/DatabasePostingService.php']);

    expect($methods)->toHaveKeys(['post', 'reverse', 'writeReversal'])
        ->and($methods['post'])->toMatch('/->\s*references\s*->\s*check\s*\(/')
        ->and($methods['reverse'])->not->toMatch('/\breferences\b|JournalReferences/')
        ->and($methods['writeReversal'])->not->toMatch('/\breferences\b|JournalReferences/');
});

it('reverse control: a planted reference check inside reverse is seen by the same extraction', function () {
    $methods = qaJournalMethods("<?php\nclass S {\npublic function post(): void { \$this->references->check(1); }\npublic function reverse(): void { \$this->references->check(2); }\n}\n");

    expect($methods['reverse'])->toMatch('/\breferences\b/');
});

// ------------------------------------------------------------------ no float

it('no float arithmetic, cast or declaration exists in Journal/', function () {
    $journal = array_filter(qaJournalFinanceFiles(), static fn (string $code, string $path): bool => str_starts_with($path, 'Journal/'), ARRAY_FILTER_USE_BOTH);

    expect(count($journal))->toBeGreaterThan(12)
        ->and(qaJournalFloatOffenders($journal))->toBe([]);
});

it('no-float control: each planted spelling is reported, is_float and prose are not', function () {
    $directory = qaJournalFreshDirectory();

    try {
        $planted = ['Cast' => '$x = (float) $a;', 'Type' => 'function f(float $a) {}', 'Round' => '$x = round($a, 2);', 'Val' => '$x = floatval($a);'];

        foreach ($planted as $name => $code) {
            file_put_contents($directory.'/'.$name.'.php', "<?php\n".$code."\n");
        }

        file_put_contents($directory.'/Clean.php', "<?php\n/** a float goes through its string form */\n\$ok = is_float(\$a);\n");

        $found = qaJournalFloatOffenders(qaJournalSourceFiles($directory));
        sort($found);

        expect($found)->toBe(['Cast.php', 'Round.php', 'Type.php', 'Val.php']);
    } finally {
        qaJournalDeleteDirectory($directory);
    }
});
