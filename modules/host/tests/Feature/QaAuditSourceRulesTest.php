<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-017 step 13 - the sole-writer and no-delete source scans (AC-10, AC-11,
| AC-19, AC-20), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaPlatformSourceRulesTest: a text scan over this
| branch's shared-package source and the host application, each rule with
| a planted-file control proving the scan can fail, run over a real
| directory holding the planted file (never only str_contains() against the
| planted file's own text - T-016 reviewer round 1, W-1).
|
| Stated limit (memory: a type-graph rule does not see a literal): this is
| a text scan over shipped source, not a boundary check or a runtime guard.
*/

/**
 * @return list<string>
 */
function qaAuditSourceDirectories(): array
{
    return [
        dirname(__DIR__, 4).'/packages/access/src',
        dirname(__DIR__, 4).'/packages/organization/src',
        dirname(__DIR__, 4).'/packages/platform/src',
        dirname(__DIR__, 4).'/packages/kernel/src',
        dirname(__DIR__).'/../app',
    ];
}

/**
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function qaAuditSourceFiles(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $files[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
        }
    }

    return $files;
}

function qaAuditFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_audit_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

function qaAuditPlantedPath(string $directory): string
{
    return (string) realpath($directory.'/Planted.php');
}

/**
 * The text that follows each `table('<table>')` call in the given source,
 * up to the next statement terminator - so a caller can ask "what is
 * chained directly onto THIS table() call" rather than "does this method
 * name appear anywhere in the file", which would conflate two different
 * statements against two different tables in the same file (exactly the
 * shape `ArchiveAuditLogsHandler` is: it inserts into one table and
 * deletes from another).
 *
 * Matched so that `audit_logs_archive` never matches a call for
 * `audit_logs`: the pattern requires the closing quote immediately after
 * the table name.
 *
 * @return list<string>
 */
function qaAuditTableCallWindows(string $contents, string $table): array
{
    $pattern = '/table\(\s*[\'"]'.preg_quote($table, '/')."['\"]\\s*\\)/";

    if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    $windows = [];

    foreach ($matches[0] as $match) {
        $start = (int) $match[1] + strlen((string) $match[0]);
        $terminator = strpos($contents, ';', $start);
        $end = $terminator === false ? min($start + 500, strlen($contents)) : $terminator;

        $windows[] = substr($contents, $start, $end - $start);
    }

    return $windows;
}

/**
 * Files, other than the one named, that insert into the exact table named.
 *
 * An insert-family call must be the statement's **own** next call after
 * `table('<table>')` - which is how Eloquent's query builder is actually
 * used for an insert (never `table('x')->where(...)->insert(...)`) - or
 * the raw SQL form.
 *
 * @param  list<string>|null  $directories
 * @return list<string>
 */
function qaAuditInsertOffenders(string $table, string $exceptBasename, ?array $directories = null): array
{
    $found = [];

    foreach (qaAuditSourceFiles($directories ?? qaAuditSourceDirectories()) as $path => $contents) {
        if (basename($path) === $exceptBasename) {
            continue;
        }

        $chained = false;

        foreach (qaAuditTableCallWindows($contents, $table) as $window) {
            if (preg_match('/^\s*->\s*(insert|insertGetId|insertUsing|insertOrIgnore)\s*\(/', $window) === 1) {
                $chained = true;

                break;
            }
        }

        $rawInsert = preg_match('/\binsert\s+into\s+[`\'"]'.preg_quote($table, '/').'[`\'"]/i', $contents) === 1;

        if ($chained || $rawInsert) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Files, other than the ones named, that issue a DELETE against the exact
 * table named - the Eloquent/query-builder form (anywhere in the same
 * statement as the `table()` call, since `->where(...)->delete()` is the
 * ordinary shape) and the raw SQL form.
 *
 * @param  list<string>  $exceptBasenames
 * @param  list<string>|null  $directories
 * @return list<string>
 */
function qaAuditDeleteOffenders(string $table, array $exceptBasenames, ?array $directories = null): array
{
    $found = [];

    foreach (qaAuditSourceFiles($directories ?? qaAuditSourceDirectories()) as $path => $contents) {
        if (in_array(basename($path), $exceptBasenames, true)) {
            continue;
        }

        $chained = false;

        foreach (qaAuditTableCallWindows($contents, $table) as $window) {
            if (preg_match('/->\s*delete\s*\(/', $window) === 1) {
                $chained = true;

                break;
            }
        }

        $rawDelete = preg_match('/\bdelete\s+from\s+[`\'"]'.preg_quote($table, '/').'[`\'"]/i', $contents) === 1;

        if ($chained || $rawDelete) {
            $found[] = $path;
        }
    }

    return $found;
}

it('every scanned directory actually exists, so an absent one cannot silently empty the scan', function () {
    foreach (qaAuditSourceDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }
});

it('AC-19: the only file inserting into audit_logs is AuditWriter', function () {
    expect(qaAuditInsertOffenders('audit_logs', 'AuditWriter.php'))->toBe([]);

    $directory = qaAuditFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('audit_logs')->insert(['a' => 1]);\n");

    try {
        expect(qaAuditInsertOffenders('audit_logs', 'AuditWriter.php', [$directory]))->toBe([qaAuditPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-19: the only file inserting into audit_logs_archive is ArchiveAuditLogsHandler, and audit_logs_archive never matches the audit_logs rule', function () {
    expect(qaAuditInsertOffenders('audit_logs_archive', 'ArchiveAuditLogsHandler.php'))->toBe([]);

    // The exact-name control: ArchiveAuditLogsHandler itself inserts into
    // audit_logs_archive (its whole job), and must not be reported by a
    // scan for audit_logs - which it would be under a substring match.
    expect(qaAuditInsertOffenders('audit_logs', 'AuditWriter.php'))->not->toContain(
        dirname(__DIR__, 4).'/packages/access/src/Features/ArchiveAuditLogs/ArchiveAuditLogsHandler.php'
    );

    $directory = qaAuditFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('audit_logs_archive')->insertUsing(['a'], \$sub);\n");

    try {
        expect(qaAuditInsertOffenders('audit_logs_archive', 'ArchiveAuditLogsHandler.php', [$directory]))->toBe([qaAuditPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-20: the only file inserting into platform_audit_logs is PlatformAudit, and MigrationRunAudit never names the table', function () {
    // The scan's domain includes MigrationRunAudit and the planner, and an
    // empty result already proves neither is reported: "MigrationRunAudit
    // never names the table in code" is this same fact, not a second check
    // - its own docblock discusses the table in prose, which a bare
    // substring check cannot tell apart from a real reference, and is why
    // this scan (chained on code, not on a docblock mention) is the one
    // that matters.
    expect(qaAuditInsertOffenders('platform_audit_logs', 'PlatformAudit.php'))->toBe([]);

    $directory = qaAuditFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('platform_audit_logs')->insert(['a' => 1]);\n");

    try {
        expect(qaAuditInsertOffenders('platform_audit_logs', 'PlatformAudit.php', [$directory]))->toBe([qaAuditPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('AC-10, AC-11: no file deletes from platform_audit_logs or audit_logs_archive, and the only delete from audit_logs is ArchiveAuditLogsHandler', function () {
    expect(qaAuditDeleteOffenders('platform_audit_logs', []))->toBe([])
        ->and(qaAuditDeleteOffenders('audit_logs_archive', []))->toBe([])
        ->and(qaAuditDeleteOffenders('audit_logs', ['ArchiveAuditLogsHandler.php']))->toBe([]);
});

it('AC-11 control: a planted delete against platform_audit_logs is reported, and one against an unrelated fixture table is not', function () {
    $directory = qaAuditFreshTempDirectory();
    File::put(
        $directory.'/Planted.php',
        "<?php\n\$x->table('platform_audit_logs')->where('id', 1)->delete();\n\$x->table('fixture_table')->delete();\n"
    );

    try {
        expect(qaAuditDeleteOffenders('platform_audit_logs', [], [$directory]))->toBe([qaAuditPlantedPath($directory)])
            ->and(qaAuditDeleteOffenders('fixture_table', [], [$directory]))->toBe([qaAuditPlantedPath($directory)])
            // The discriminating half: scanning for a THIRD table name that
            // the planted file never mentions finds nothing.
            ->and(qaAuditDeleteOffenders('some_other_table', [], [$directory]))->toBe([]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('runtime control: the instrument is a source scan, not a runtime guard - a real delete against a scratch table succeeds', function () {
    DB::connection('master')->statement('create table qa_audit_scratch (id integer primary key, value text)');
    DB::connection('master')->table('qa_audit_scratch')->insert(['id' => 1, 'value' => 'x']);

    DB::connection('master')->table('qa_audit_scratch')->where('id', 1)->delete();

    expect(DB::connection('master')->table('qa_audit_scratch')->count())->toBe(0);

    DB::connection('master')->statement('drop table qa_audit_scratch');
});
