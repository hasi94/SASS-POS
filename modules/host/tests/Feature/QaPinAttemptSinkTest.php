<?php

use Flexipos\Access\Features\VerifyPin\VerifyPin;
use Flexipos\Access\Features\VerifyPin\VerifyPinHandler;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\PinPolicy;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-010 QA - the ATTEMPTED PIN is a secret too (AC-48, AC-50)
|--------------------------------------------------------------------------
|
| AC-48: "a run that sets a PIN whose value is a known sentinel and then
| fails and succeeds a verification with it". The dev harness
| (`QaPinSinkCaptureTest`) makes the sentinel the PIN that is SET, and the
| failed verification uses a fixed wrong value that is not scanned for. So a
| failed attempt that wrote the attempted value into a sink - the
| `access_log.detail` of a `pin_failed` row is the obvious one, and AC-50
| says "never the attempted PIN (a near-miss is close to the real one)" -
| is invisible to the sink harness. QA ran that mutant (the attempted value
| appended to the `bad_pin` reason): the AC-50 slice assertion went red and
| the sink harness stayed green.
|
| This file makes the sentinel the ATTEMPT. It is submitted as a wrong PIN
| against every cause AC-50 lists (a wrong PIN for an employee who has one,
| for one with none, for a locked one, for a non-acting one, and against a
| code nobody holds), then every column of every table of BOTH databases and
| the application log are scanned for it, raw and reversed.
|
| **Controls, each in this file:** (i) the sentinel planted through a real
| writer (`AccessLogWriter` into `access_log.detail`, and the log channel) is
| found, in the table scan and in the log scan; (ii) a baseline run with a
| different attempt is captured first and the sentinel is asserted absent
| from it, so the digits cannot be explained by ids, counts or timestamps;
| (iii) the scan counts what it examined and asserts a non-empty domain.
|
| **Stated residual (AC-48's, not tested):** the application cannot erase
| what a reverse proxy or web server logs, and a PIN equal to other digits
| stored legitimately is not distinguishable by a scan.
|
*/

require_once __DIR__.'/../../../../packages/auth/tests/Support/auth-fixture.php';
require_once __DIR__.'/../../../../packages/kernel/tests/Support/tenancy-fixture.php';

const QA_T10F_CLOCK = '2026-10-20 10:00:00.000';

beforeEach(function () {
    authFixtureClockAt(QA_T10F_CLOCK);
    authFixtureMigrateMaster();

    AccessTenantFixture::boot();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function qaT10fRedirectLog(): string
{
    $directory = storage_path('framework'.DIRECTORY_SEPARATOR.'testing');
    File::ensureDirectoryExists($directory);

    $path = $directory.DIRECTORY_SEPARATOR.'qa-t10f-'.bin2hex(random_bytes(6)).'.log';

    config()->set('logging.channels.qa_t10f', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('logging.default', 'qa_t10f');
    Log::forgetChannel('qa_t10f');

    return $path;
}

/**
 * Every failing cause of AC-50 with the given attempted PIN, plus a final
 * success so the run also contains a `pin_login`.
 *
 * @return string the printed results, which are a sink of their own
 */
function qaT10fRun(string $attempt, string $suffix): string
{
    $printed = [];

    AccessTenantFixture::switchTo('tenant-a');

    try {
        $hasher = app('hash');

        $make = static function (string $code, array $overrides = []) use ($hasher, $suffix): string {
            return AccessSeed::employee($code.$suffix, $overrides + [
                'pin_hash' => $hasher->make('7391'),
                'pin_set_at' => QA_T10F_CLOCK,
            ]);
        };

        $make('cashier');
        $make('nopin', ['pin_hash' => null, 'pin_set_at' => null]);
        $make('onleave', ['status' => 'on_leave']);
        $make('locked', ['pin_locked_until' => '2026-10-20 11:00:00.000']);

        $handler = app(VerifyPinHandler::class);

        $threshold = app(PinPolicy::class)->lockoutAfter();

        // bad_pin until the lock, and one more while locked.
        for ($i = 0; $i <= $threshold; $i++) {
            $printed[] = var_export($handler->handle(new VerifyPin('cashier'.$suffix, $attempt, '203.0.113.7')), true);
        }

        foreach (['nopin', 'onleave', 'locked', 'ghost'] as $code) {
            $printed[] = var_export($handler->handle(new VerifyPin($code.$suffix, $attempt, '203.0.113.7')), true);
        }
    } finally {
        tenancy()->leave();
    }

    return implode("\n", $printed);
}

/**
 * Every column of every table of both databases, as one text per table.
 *
 * @return array{text: array<string, string>, columns: int}
 */
function qaT10fScanTables(): array
{
    $scan = ['text' => [], 'columns' => 0];

    AccessTenantFixture::switchTo('tenant-a');

    try {
        foreach (['master' => 'master', 'tenant' => AccessTenantFixture::connectionName()] as $label => $connection) {
            $tables = array_values(array_filter(
                array_map(static fn (array $t): string => (string) $t['name'], Schema::connection($connection)->getTables()),
                static fn (string $name): bool => ! str_starts_with($name, 'sqlite_')
            ));

            sort($tables);

            foreach ($tables as $table) {
                $lines = [];

                foreach (DB::connection($connection)->table($table)->get() as $row) {
                    foreach ((array) $row as $column => $value) {
                        $scan['columns']++;
                        $lines[] = $table.'.'.$column.'='.($value === null ? '' : (is_resource($value) ? (string) stream_get_contents($value) : (string) $value));
                    }
                }

                $scan['text'][$label.'.'.$table] = implode("\n", $lines);
            }
        }
    } finally {
        tenancy()->leave();
    }

    return $scan;
}

/**
 * Which sinks hold the needle, in its raw and reversed forms.
 *
 * @param  array<string, string>  $sinks
 * @return list<string>
 */
function qaT10fFind(string $needle, array $sinks): array
{
    $forms = [$needle, strrev($needle), rawurlencode($needle), (string) json_encode($needle)];
    $hits = [];

    foreach ($sinks as $name => $text) {
        foreach ($forms as $form) {
            if ($form !== '' && str_contains($text, $form)) {
                $hits[] = $name;

                break;
            }
        }
    }

    sort($hits);

    return $hits;
}

it('finds the attempted PIN in no table of either database, no log line and no result, over the five causes (AC-48, AC-50)', function () {
    $logPath = qaT10fRedirectLog();

    // The channel is live before anything runs: an empty file is also what a
    // log that was never wired reports.
    Log::info('qa-t10f channel liveness');

    // ---- baseline: a different attempt, and the sentinel proved absent ----
    $baselinePrinted = qaT10fRun('19283746', 'b');
    $baseline = qaT10fScanTables();

    $sentinel = null;

    foreach (['84730196', '60594817', '75203841'] as $candidate) {
        $texts = array_merge($baseline['text'], ['printed' => $baselinePrinted, 'log' => (string) @file_get_contents($logPath)]);

        if (qaT10fFind($candidate, $texts) === []) {
            $sentinel = $candidate;

            break;
        }
    }

    expect($sentinel)->not->toBeNull('no eight-digit sentinel was free of the baseline capture');

    // ---- the run under test ----
    $printed = qaT10fRun($sentinel, 's');

    clearstatcache(true, $logPath);

    $scan = qaT10fScanTables();
    $sinks = array_merge($scan['text'], ['printed' => $printed, 'log' => (string) file_get_contents($logPath)]);

    fwrite(STDOUT, PHP_EOL.'AC-48 attempted-PIN scan: '.count($scan['text']).' tables, '.$scan['columns'].' column values, '
        .'log '.strlen((string) $sinks['log']).' bytes, printed results '.strlen($printed).' bytes'.PHP_EOL);

    // Non-empty domain, and the run really produced the events it claims.
    expect($scan['text'])->not->toBe([])
        ->and($scan['columns'])->toBeGreaterThan(100)
        ->and(substr_count($sinks['tenant.access_log'] ?? '', 'access_log.type=pin_failed'))->toBeGreaterThanOrEqual(9)
        ->and($sinks['log'])->toContain('qa-t10f channel liveness');

    expect(qaT10fFind($sentinel, $sinks))->toBe([], 'the attempted PIN reached a sink');
});

it('finds a sentinel planted through the real writers, in the table scan and in the log scan (control i)', function () {
    $logPath = qaT10fRedirectLog();
    $sentinel = '84730196';

    AccessTenantFixture::switchTo('tenant-a');

    try {
        $employee = AccessSeed::employee('plant');

        // The real writer, with the sentinel where a leaking handler would put it.
        app(AccessLogWriter::class)->pinFailed(AccessSeed::connection(), $employee, 'bad_pin:'.$sentinel, null);
    } finally {
        tenancy()->leave();
    }

    Log::error('planted', ['attempt' => $sentinel]);

    clearstatcache(true, $logPath);

    $scan = qaT10fScanTables();
    $sinks = array_merge($scan['text'], ['log' => (string) file_get_contents($logPath)]);

    expect(qaT10fFind($sentinel, $sinks))->toBe(['log', 'tenant.access_log']);

    // And the reversed form is found too.
    expect(qaT10fFind($sentinel, ['x' => 'reversed:'.strrev($sentinel)]))->toBe(['x']);
});
