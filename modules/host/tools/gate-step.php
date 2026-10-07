<?php

declare(strict_types=1);

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Run one gate step on the gate's own timer (T-007, FP-172)
|--------------------------------------------------------------------------
|
| On 2026-10-06 the gate outlasted the package manager's per-process limit on a
| cold cache, the test step was killed, and the run reported exit 1 with no test
| failure: a false red on a clean tree. Exit status alone cannot separate "the
| suite failed" from "the suite was killed", and the only message a killed step
| printed came from the package manager rather than from the gate. A gate that
| reports failure when nothing failed teaches people to re-run instead of to
| read, and that - not the delay - is the defect.
|
| So the step owns its own timer. This wrapper ends the child before the package
| manager can, and says so in a line nothing else prints.
|
| Invoked as:
|
|     php tools/gate-step.php <label> -- <command and arguments>
|
| The command runs as a child of this process with PHP_BINARY prepended, from
| modules/host, with stdout and stderr streamed through byte for byte and kept
| apart, so the wrapped tool's own output - progress, colour, failure detail - is
| unaltered.
|
| Three greppable tokens, and no other path prints any of them:
|
|   FLEXIPOS-GATE-TIMEOUT       this timer ended the step; exit status 3
|   FLEXIPOS-GATE-LOW-HEADROOM  the step completed, but the limit in force is
|                               less than headroom-factor times its own elapsed
|                               time, so the next growth of the suite is the one
|                               that reproduces the incident
|   FLEXIPOS-GATE-MARGIN        always the last line of a completed step
|
| A kill is the pair (sentinel, exit 3) and both halves carry weight. A
| composite script propagates a step's status verbatim, so a wrapped tool that
| exits 3 of its own accord also reaches the shell as a 3 - but it arrives with
| the margin line and WITHOUT the sentinel. Neither half alone is unambiguous;
| the pair is. GateStepTimerTest asserts both halves on both paths.
|
| ---------------------------------------------------------------------------
| The limit in force
| ---------------------------------------------------------------------------
|
| Resolved here, in this order, and the source that won is named on EVERY line
| this file prints - as the last field before the prose - so that nobody reading
| a run has to guess which limit was in force (R9). It matters most on the
| low-headroom line, which advises raising config.process-timeout: on a run whose
| limit came from the environment that advice is addressed to the wrong knob, and
| naming the source is what lets the reader being advised tell which:
|
|   1. COMPOSER_PROCESS_TIMEOUT, when it is a positive integer   source=env
|   2. config.process-timeout in the composer.json beside this   source=manifest
|   3. 300, the package manager's documented default             source=default
|
| It has to be resolved here and cannot be inherited: the package manager
| exports only COMPOSER_BINARY and COMPOSER_DEV_MODE into a script's child and
| never the limit (MEASURED 2026-10-06 by architect, recorded in
| docs/plans/T-007-gate-headroom-and-tenancy-hardening.md R2). Re-measure with
|
|     php tools/gate-step.php probe -- -r "print_r(getenv());"
|
| run through `composer run-script`, and look for the name in the listing.
|
| ---------------------------------------------------------------------------
| The budget, as a ratio with a floor
| ---------------------------------------------------------------------------
|
|     reserve = max(2 seconds, 2 % of the limit)
|     budget  = max(1 second,  limit - reserve)
|
| A ratio rather than an absolute figure (FP-171), so it cannot go stale when
| the limit is raised. The reserve covers this process's own boot plus the
| detection latency between the budget expiring and this file writing its line:
| the child-process component notices a timeout on its own read cadence, not on
| the instant.
|
| The 2-second floor is the one absolute duration in this file, so it carries
| its date and the command that re-measures it. MEASURED 2026-10-07 on this
| machine, with the real package manager and the real suite: at a forced limit
| of 2 (budget 1) the sentinel reported elapsed=1.45s, so the latency was
| 0.45 - under a quarter of the floor. Re-measure with
|
|     COMPOSER_PROCESS_TIMEOUT=2 composer test        (run in modules/host)
|
| and subtract `budget=` from `elapsed=` in the FLEXIPOS-GATE-TIMEOUT line.
|
| The dated cold measurement the limit itself is derived from lives in exactly
| one place - extra.flexipos.gate in modules/host/composer.json - and nothing
| here repeats it. The headroom factor is read from the same place, so the
| warning below quotes the manifest rather than a second copy of it.
|
| ---------------------------------------------------------------------------
| Why a child process
| ---------------------------------------------------------------------------
|
| There is no pcntl extension on this PHP, so there is no in-process alarm, and
| max_execution_time does not apply on the command line. The child-process
| component is used rather than a hand-rolled proc_open because on Windows its
| kill is `taskkill /F /T` (vendor/symfony/process/Process.php:1526), which
| reaches the children the test runner spawns of its own; a hand-rolled
| proc_open would leave them behind. It is already a production requirement of
| the framework in composer.lock (symfony/process v7.4.19, required by
| laravel/framework v12.69.2), so this adds no dependency.
|
| Three residuals, written down because the paragraphs above are only true with
| them. On POSIX the same kill signals the direct child only, so a grandchild of
| the test runner can briefly outlive a kill; `probes:clean` running first in
| `check` is what makes that harmless.
|
| And one residual, now that source= is on every line rather than only on a
| kill: source=default alone is covered by reading rather than by running. The
| manifest path above is fixed (__DIR__.'/../composer.json'), so no test can make
| config.process-timeout absent while the manifest carries it - and
| GateStepTimerTest asserts that it does carry it. source=env and
| source=manifest are both asserted, in both directions, on lines a fast test
| really does produce. Before source= moved onto the margin line, source=manifest
| was in the same position as source=default; it is not any more (R9).
|
*/

require __DIR__.'/../vendor/autoload.php';

/**
 * One value out of a decoded manifest by key path, or null when the path is
 * absent.
 *
 * @param  array<mixed, mixed>  $manifest
 * @param  list<string>  $path
 */
function gateStepManifestValue(array $manifest, array $path): mixed
{
    $cursor = $manifest;

    foreach ($path as $key) {
        if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
            return null;
        }

        $cursor = $cursor[$key];
    }

    return $cursor;
}

/**
 * A duration or a ratio, in the two decimals every line of this file prints.
 */
function gateStepNumber(float $value): string
{
    return number_format($value, 2, '.', '');
}

/**
 * The headroom factor as it reads in a sentence: 2 rather than 2.00.
 */
function gateStepFactor(float $factor): string
{
    return rtrim(rtrim(gateStepNumber($factor), '0'), '.');
}

$arguments = array_slice($argv, 1);
$separator = array_search('--', $arguments, true);
$command = is_int($separator) ? array_map('strval', array_slice($arguments, $separator + 1)) : [];

if (! is_int($separator) || $separator < 1 || $command === []) {
    fwrite(STDERR, 'usage: php tools/gate-step.php <label> -- <command and arguments>'.PHP_EOL);

    exit(2);
}

$label = (string) $arguments[0];

/** @var array<mixed, mixed> $manifest */
$manifest = (array) json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

$forced = getenv('COMPOSER_PROCESS_TIMEOUT');
$configured = gateStepManifestValue($manifest, ['config', 'process-timeout']);

if (is_string($forced) && preg_match('/^\d+$/', trim($forced)) === 1 && (int) trim($forced) > 0) {
    $limit = (int) trim($forced);
    $source = 'env';
} elseif ((is_int($configured) || is_string($configured)) && (int) $configured > 0) {
    $limit = (int) $configured;
    $source = 'manifest';
} else {
    $limit = 300;
    $source = 'default';
}

$factor = gateStepManifestValue($manifest, ['extra', 'flexipos', 'gate', 'headroom-factor']);
$headroom = is_int($factor) || is_float($factor) || (is_string($factor) && is_numeric($factor))
    ? (float) $factor
    : 2.0;

$reserve = max(2, (int) ceil($limit * 0.02));
$budget = max(1, $limit - $reserve);

$process = new Process(
    array_merge([PHP_BINARY], $command),
    dirname(__DIR__),
    null,
    null,
    (float) $budget
);

$started = microtime(true);

try {
    $status = $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });
} catch (ProcessTimedOutException) {
    // checkTimeout() has already sent the tree kill; this is the belt to its
    // braces, and it costs nothing because a stopped process returns at once.
    $process->stop(1.0, 9);

    echo 'FLEXIPOS-GATE-TIMEOUT step=', $label,
    ' at=', gmdate(DATE_ATOM),
    ' elapsed=', gateStepNumber(microtime(true) - $started), 's',
    ' budget=', $budget, 's',
    ' limit=', $limit, 's',
    ' source=', $source,
    ' - the gate ended this step on its own timer before the process limit was reached; ',
    'NO TEST RESULT WAS PRODUCED.', PHP_EOL;

    exit(3);
}

$elapsed = microtime(true) - $started;

// Clamped so a step that finishes faster than the clock's resolution reports a
// large ratio instead of dividing by zero.
$ratio = $limit / max($elapsed, 0.01);

if ($ratio < $headroom) {
    echo 'FLEXIPOS-GATE-LOW-HEADROOM step=', $label,
    ' ratio=', gateStepNumber($ratio),
    ' source=', $source,
    ' below the required ', gateStepFactor($headroom), 'x (D-1); ',
    'raise config.process-timeout - see extra.flexipos.gate in modules/host/composer.json.', PHP_EOL;
}

// Always last (AC-9), on every completed run, whatever the child's status.
echo 'FLEXIPOS-GATE-MARGIN step=', $label,
' at=', gmdate(DATE_ATOM),
' elapsed=', gateStepNumber($elapsed), 's',
' limit=', $limit, 's',
' ratio=', gateStepNumber($ratio),
' source=', $source, PHP_EOL;

exit($status);
