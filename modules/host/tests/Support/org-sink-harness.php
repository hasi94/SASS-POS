<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The sink harness the two organization secrecy sweeps share (T-014 AC-27, AC-47)
|--------------------------------------------------------------------------
|
| A secret is looked for where it must not be, in the forms a writer leaves it
| in: raw, JSON-escaped (a context array, an audit column and a response all
| pass through `json_encode`, which turns `/` into `\/` and `"` into `\"`),
| URL-encoded, and reversed. **A scan that finds nothing proves nothing**, so
| each sweep also runs it over a planted copy in every kind of sink and over a
| value that was never issued (the baseline-collision control).
|
| Both databases are enumerated **live**: `sqlite_master` for the tables and
| `pragma table_info` for the columns, one `select` per column, so a column
| added tomorrow is in the scan tomorrow. The count is returned so a sweep can
| print and assert it.
|
| Loaded with `require_once` from the sweeps; never collected; every symbol is
| prefixed `orgSink`.
*/

/**
 * The forms of one secret a sink may hold.
 *
 * @return list<string>
 */
function orgSinkForms(string $value): array
{
    $escaped = substr((string) json_encode($value, JSON_UNESCAPED_UNICODE), 1, -1);
    $reversed = strrev($value);

    return array_values(array_unique([
        $value,
        $escaped,
        // Laravel's own writers encode with unescaped slashes: the form a settings value is stored in.
        substr((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
        urlencode($value),
        rawurlencode($value),
        $reversed,
        substr((string) json_encode($reversed, JSON_UNESCAPED_UNICODE), 1, -1),
        urlencode($reversed),
    ]));
}

/**
 * Every column of every table of one connection, as `connection.table.column`
 * => the values of that column joined, and how many tables and columns that was.
 *
 * @return array{sinks: array<string, string>, tables: int, columns: int}
 */
function orgSinkDump(Connection $connection, string $label): array
{
    $sinks = [];
    $tables = 0;

    /** @var list<object{name: string}> $names */
    $names = $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name");

    foreach ($names as $table) {
        $tables++;

        /** @var list<object{name: string}> $columns */
        $columns = $connection->select('pragma table_info("'.$table->name.'")');

        foreach ($columns as $column) {
            $values = [];

            foreach ($connection->select('select "'.$column->name.'" as v from "'.$table->name.'"') as $row) {
                $values[] = $row->v === null ? '' : (string) $row->v;
            }

            $sinks[$label.'.'.$table->name.'.'.$column->name] = implode("\n", $values);
        }
    }

    return ['sinks' => $sinks, 'tables' => $tables, 'columns' => count($sinks)];
}

/**
 * Point the default log channel at a file at debug level, and return its path.
 */
function orgSinkRedirectLog(): string
{
    $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
    File::ensureDirectoryExists($directory);

    $path = $directory.DIRECTORY_SEPARATOR.'org-sink-'.bin2hex(random_bytes(6)).'.log';

    config()->set('logging.channels.org_sink', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('logging.default', 'org_sink');
    Log::forgetChannel('org_sink');

    return $path;
}

function orgSinkReadLog(string $path): string
{
    clearstatcache(true, $path);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Which sinks hold which secrets, as `sink<-label`, sorted.
 *
 * @param  array<string, string>  $sinks  sink name => text
 * @param  array<string, string>  $secrets  label => value
 * @return list<string>
 */
function orgSinkFind(array $sinks, array $secrets): array
{
    $hits = [];

    foreach ($sinks as $sink => $text) {
        foreach ($secrets as $label => $value) {
            foreach (orgSinkForms($value) as $form) {
                if ($form !== '' && str_contains($text, $form)) {
                    $hits[] = $sink.'<-'.$label;

                    break;
                }
            }
        }
    }

    sort($hits);

    return $hits;
}
