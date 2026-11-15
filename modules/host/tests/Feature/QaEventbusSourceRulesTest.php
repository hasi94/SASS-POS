<?php

declare(strict_types=1);

namespace Tests\Feature;

use Flexipos\Eventbus\Tests\Support\PingedConsumer;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| T-018 step 10 - the one-inserter and no-Redis-outside-the-package source
| scans (AC-2, AC-5), qa-accountable, written by dev
|--------------------------------------------------------------------------
|
| The same shape as QaAuditSourceRulesTest: a text scan over this branch's
| shared-package source and the host application, each rule with a
| planted-file control proving the scan can fail, run over a real directory
| holding the planted file.
|
| Its own helpers, calling nothing from QaAuditSourceRulesTest: those
| functions are namespaced in that file, and cross-file reliance on
| load order is the FP-164 defect this project already found once.
|
| Stated limit (memory: a type-graph rule does not see a literal): this is a
| text scan over shipped source, not a boundary check or a runtime guard -
| the boundary check's own half of AC-37 is the deptrac edge (section 5),
| re-run manually in the qa report per the plan's step 10.
*/

/**
 * @return list<string>
 */
function qaEventbusSourceDirectories(): array
{
    $packagesRoot = dirname(__DIR__, 4).'/packages';
    $modulesRoot = dirname(__DIR__, 4).'/modules';

    $directories = [dirname(__DIR__).'/../app'];

    foreach (glob($packagesRoot.'/*', GLOB_ONLYDIR) ?: [] as $package) {
        if (is_dir($package.'/src')) {
            $directories[] = $package.'/src';
        }
    }

    foreach (glob($modulesRoot.'/*', GLOB_ONLYDIR) ?: [] as $module) {
        if (is_dir($module.'/src')) {
            $directories[] = $module.'/src';
        }
    }

    sort($directories);

    return $directories;
}

/**
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function qaEventbusSourceFiles(array $directories): array
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

function qaEventbusFreshTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/qa_eventbus_scan_'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);

    return $directory;
}

function qaEventbusPlantedPath(string $directory): string
{
    return (string) realpath($directory.'/Planted.php');
}

function qaEventbusOutboxWriterRelativePath(): string
{
    return 'packages/eventbus/src/Support/OutboxWriter.php';
}

/**
 * `$path` relative to the repository root, with `/` separators regardless
 * of platform - so an exemption or an allow-list entry names one exact
 * file by its full location, never merely a basename any file anywhere
 * could share (W-6, review round 1).
 */
function qaEventbusRepoRelativePath(string $path): string
{
    $root = str_replace('\\', '/', dirname(__DIR__, 4)).'/';
    $normalised = str_replace('\\', '/', $path);

    return str_starts_with($normalised, $root) ? substr($normalised, strlen($root)) : $normalised;
}

/**
 * The text immediately following each `table('<table>')` call, up to the
 * next statement terminator - so a caller can ask "what is chained
 * directly onto THIS table() call", never merely "does this method name
 * appear anywhere in the file".
 *
 * @return list<string>
 */
function qaEventbusTableCallWindows(string $contents, string $table): array
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
 * `$exceptRepoRelativePath` is matched against the file's own full
 * repo-relative path, never its basename (W-6, review round 1): a basename
 * match would exempt any file merely *named* the same, anywhere in the
 * scanned tree.
 *
 * **Stated limit, unchanged by this fix (W-6):** `table(Foo::TABLE)` -
 * a class constant, the shape this package's own handlers actually use -
 * is invisible to this scan, which only recognises a quoted literal
 * chained onto `table()`. {@see qaEventbusOutboxEventsLiteralFiles()} is
 * the complementary, allow-listed check that catches a second inserter
 * written that way instead.
 *
 * @param  list<string>|null  $directories
 * @return list<string>
 */
function qaEventbusInsertOffenders(string $table, string $exceptRepoRelativePath, ?array $directories = null): array
{
    $found = [];

    foreach (qaEventbusSourceFiles($directories ?? qaEventbusSourceDirectories()) as $path => $contents) {
        if (qaEventbusRepoRelativePath($path) === $exceptRepoRelativePath) {
            continue;
        }

        $chained = false;

        foreach (qaEventbusTableCallWindows($contents, $table) as $window) {
            if (preg_match('/^\s*->\s*(insert|insertGetId|insertUsing|insertOrIgnore)\s*\(/', $window) === 1) {
                $chained = true;

                break;
            }
        }

        // The quote characters around the table name are optional (W-6):
        // an unquoted raw `insert into outbox_events ...` is valid SQL and
        // was invisible to the original, quote-only pattern. The trailing
        // word boundary still keeps `outbox_events_backup` from matching.
        $rawInsert = preg_match(
            '/\binsert\s+into\s+[`\'"]?'.preg_quote($table, '/').'[`\'"]?\b/i',
            $contents
        ) === 1;

        if ($chained || $rawInsert) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * Every file under `packages/eventbus/src` containing the exact quoted
 * string literal `'outbox_events'` (or `"outbox_events"`) - never a mere
 * textual mention inside a docblock sentence or a command description,
 * both of which read naturally with no quote immediately around the table
 * name (W-6, review round 1).
 *
 * The complementary half of `qaEventbusInsertOffenders()`'s stated limit:
 * a second inserter written as `table(Foo::TABLE)->insert(...)` is
 * invisible to that scan, but every existing writer and reader of this
 * table declares its own `TABLE` constant as a quoted literal exactly this
 * shape - `public const TABLE = 'outbox_events';` - so a second one
 * written the same way is caught here instead.
 *
 * @param  list<string>|null  $directories
 * @return list<string> repo-relative paths, sorted
 */
function qaEventbusOutboxEventsLiteralFiles(?array $directories = null): array
{
    $directories ??= [dirname(__DIR__, 4).'/packages/eventbus/src'];

    $found = [];

    foreach (qaEventbusSourceFiles($directories) as $path => $contents) {
        if (preg_match('/[\'"]outbox_events[\'"]/', $contents) === 1) {
            $found[] = qaEventbusRepoRelativePath($path);
        }
    }

    sort($found);

    return $found;
}

/**
 * Every Redis reference outside `packages/eventbus/src`: facade/class
 * imports, a caseless Redis Streams command verb, or the configured
 * stream/dead-letter stream name (AC-2).
 *
 * `config/` is deliberately outside the scan set: configuration is where
 * the stream name belongs.
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function qaEventbusRedisOffenders(array $files, string $exceptDirectory, string $stream, string $deadLetterStream): array
{
    $found = [];

    $exceptDirectory = rtrim(str_replace('\\', '/', $exceptDirectory), '/');

    $commandPattern = '/\b(XADD|XREADGROUP|XACK|XCLAIM|XAUTOCLAIM|XPENDING|XGROUP|XRANGE|XREVRANGE|XDEL)\b/i';

    foreach ($files as $path => $contents) {
        $normalisedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalisedPath, $exceptDirectory.'/')) {
            continue;
        }

        $hit = str_contains($contents, 'Facades\\Redis')
            || str_contains($contents, 'Redis::')
            || str_contains($contents, 'Illuminate\\Redis')
            || str_contains($contents, 'Contracts\\Redis')
            || preg_match($commandPattern, $contents) === 1
            || str_contains($contents, $stream)
            || str_contains($contents, $deadLetterStream);

        if ($hit) {
            $found[] = $path;
        }
    }

    return $found;
}

it('every scanned directory actually exists, so an absent one cannot silently empty the scan', function () {
    foreach (qaEventbusSourceDirectories() as $directory) {
        expect(is_dir($directory))->toBeTrue("Expected a directory at [{$directory}].");
    }
});

it('AC-5: the only file inserting into outbox_events is OutboxWriter', function () {
    $outboxWriter = qaEventbusOutboxWriterRelativePath();

    expect(qaEventbusInsertOffenders('outbox_events', $outboxWriter))->toBe([]);

    $directory = qaEventbusFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->table('outbox_events')->insert(['a' => 1]);\n");

    try {
        expect(qaEventbusInsertOffenders('outbox_events', $outboxWriter, [$directory]))->toBe([qaEventbusPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }

    $otherTable = qaEventbusFreshTempDirectory();
    File::put($otherTable.'/Planted.php', "<?php\n\$x->table('some_other_table')->insert(['a' => 1]);\n");

    try {
        expect(qaEventbusInsertOffenders('outbox_events', $outboxWriter, [$otherTable]))->toBe([]);
    } finally {
        File::deleteDirectory($otherTable);
    }
});

it('W-6: the exemption matches by full repo-relative path, never by basename - a file merely named OutboxWriter.php elsewhere is not exempt', function () {
    $directory = qaEventbusFreshTempDirectory();
    File::put($directory.'/OutboxWriter.php', "<?php\n\$x->table('outbox_events')->insert(['a' => 1]);\n");

    try {
        // Same basename as the real writer, wrong directory: still
        // reported, because the exemption is matched by full path.
        expect(qaEventbusInsertOffenders('outbox_events', qaEventbusOutboxWriterRelativePath(), [$directory]))
            ->toBe([(string) realpath($directory.'/OutboxWriter.php')]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('W-6: an unquoted raw insert into outbox_events is reported, and outbox_events_backup never matches the outbox_events rule', function () {
    $directory = qaEventbusFreshTempDirectory();
    File::put(
        $directory.'/Planted.php',
        "<?php\n\$pdo->exec('insert into outbox_events (id) values (1)');\n"
    );

    try {
        expect(qaEventbusInsertOffenders('outbox_events', qaEventbusOutboxWriterRelativePath(), [$directory]))
            ->toBe([qaEventbusPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }

    $backup = qaEventbusFreshTempDirectory();
    File::put(
        $backup.'/Planted.php',
        "<?php\n\$pdo->exec('insert into outbox_events_backup (id) values (1)');\n"
    );

    try {
        expect(qaEventbusInsertOffenders('outbox_events', qaEventbusOutboxWriterRelativePath(), [$backup]))->toBe([]);
    } finally {
        File::deleteDirectory($backup);
    }
});

it('W-6: the literal "outbox_events" appears in packages/eventbus/src only inside its known writers and readers', function () {
    expect(qaEventbusOutboxEventsLiteralFiles())->toBe([
        'packages/eventbus/src/Features/ListFailedOutboxEvents/ListFailedOutboxEventsHandler.php',
        'packages/eventbus/src/Features/RelayOutbox/RelayOutboxHandler.php',
        'packages/eventbus/src/Features/ReportOutboxAge/ReportOutboxAgeHandler.php',
        'packages/eventbus/src/Features/RetryFailedOutboxEvent/RetryFailedOutboxEventHandler.php',
        'packages/eventbus/src/Support/OutboxWriter.php',
    ]);

    // The gap qaEventbusInsertOffenders() cannot close (its own docblock
    // states it): a second inserter written as table(Foo::TABLE)->insert,
    // the shape this package's own handlers actually use, declares its
    // own quoted TABLE constant literal exactly like every existing one
    // does - which this allow-list check catches instead.
    $directory = qaEventbusFreshTempDirectory();
    File::put(
        $directory.'/Planted.php',
        "<?php\nfinal class Foo\n{\n    public const TABLE = 'outbox_events';\n}\n\$x->table(Foo::TABLE)->insert(['a' => 1]);\n"
    );

    try {
        expect(qaEventbusInsertOffenders('outbox_events', qaEventbusOutboxWriterRelativePath(), [$directory]))
            ->toBe([]);

        expect(qaEventbusOutboxEventsLiteralFiles([$directory]))
            ->toBe([str_replace('\\', '/', qaEventbusPlantedPath($directory))]);
    } finally {
        File::deleteDirectory($directory);
    }

    // Green again once the scratch file is gone.
    expect(qaEventbusOutboxEventsLiteralFiles())->toHaveCount(5);
});

it('AC-2: outside packages/eventbus/src, no file references Redis directly', function () {
    $stream = (string) config('eventbus.stream', 'flexipos:events');
    $deadLetterStream = (string) config('eventbus.dead_letter_stream', 'flexipos:events:dead');
    $eventbusSrc = dirname(__DIR__, 4).'/packages/eventbus/src';

    $files = qaEventbusSourceFiles(qaEventbusSourceDirectories());

    expect(qaEventbusRedisOffenders($files, $eventbusSrc, $stream, $deadLetterStream))->toBe([]);

    $directory = qaEventbusFreshTempDirectory();
    File::put($directory.'/Planted.php', "<?php\n\$x->command('XADD', ['flexipos:events', '*']);\n");

    try {
        $plantedFiles = qaEventbusSourceFiles([$directory]);
        expect(qaEventbusRedisOffenders($plantedFiles, $eventbusSrc, $stream, $deadLetterStream))
            ->toBe([qaEventbusPlantedPath($directory)]);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('Ruling 1: the proof-of-pipeline consumer imports nothing under the internal Flexipos\Eventbus namespace', function () {
    // The file's own namespace (Flexipos\Eventbus\Tests\Support) is not
    // what this checks - only its `use` imports. A consumer needs nothing
    // but the published contract.
    $reflection = new ReflectionClass(PingedConsumer::class);
    $contents = (string) file_get_contents((string) $reflection->getFileName());

    preg_match_all('/^use\s+([^;]+);/m', $contents, $matches);

    $internalImports = array_values(array_filter(
        $matches[1],
        static fn (string $import): bool => str_starts_with(trim($import), 'Flexipos\\Eventbus\\')
            && ! str_starts_with(trim($import), 'Flexipos\\Eventbus\\Tests\\')
    ));

    expect($internalImports)->toBe([]);
});
