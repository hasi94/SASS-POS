<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * T-007 FP-172 (AC-2, AC-3, AC-4, AC-5, AC-6, AC-9, AC-10, AC-11, AC-12, AC-42,
 * AC-44) - the gate's own step timer, tools/gate-step.php.
 *
 * On 2026-10-06 the gate outlasted the package manager's per-process limit on a
 * cold cache and reported exit 1 with no test failure: a false red on a clean
 * tree. The wrapper exists so that the gate is the thing that ends the step and
 * the gate is the thing that says so. This file is the proof that it does - and
 * the proof that a pass still looks like a pass and a failure still looks like a
 * failure, which is the half a sentinel gets wrong by printing on every path.
 *
 * Every spawning case forces the limit through the child's environment, so the
 * whole file costs seconds rather than the minutes a real kill of the suite
 * would. The slow, whole-gate forms of AC-4 to AC-8 and AC-45 are qa's and live
 * in the plan's measurement recipes, not here.
 *
 * The file spawns children, so it is registered in toolchainGroupFiles() in
 * tests/Helpers.php; without that entry the registry sweep in
 * ToolchainGroupCoverageTest fails the gate. The last case asserts that, so the
 * reason travels with the file instead of living only in another test's failure
 * message.
 */

/**
 * One run of the step wrapper around a short PHP child.
 *
 * The limit is forced through the child's environment because the package
 * manager exports only COMPOSER_BINARY and COMPOSER_DEV_MODE into a script's
 * child and never the limit - which is why the wrapper resolves it itself, and
 * why this is the only way to pin it per case. `false` removes the variable
 * altogether, so the wrapper has to fall back to the manifest.
 *
 * @param  list<string>  $child  the arguments the wrapper hands to PHP
 */
function gateTimerRun(array $child, string|false $limit): ProcessResult
{
    /** @var array<string, string|bool> $environment */
    $environment = ['COMPOSER_PROCESS_TIMEOUT' => $limit];

    return Process::path(base_path())
        ->env($environment)
        ->timeout(120)
        ->run(array_merge([
            PHP_BINARY,
            base_path('tools'.DIRECTORY_SEPARATOR.'gate-step.php'),
            'probe',
            '--',
        ], $child));
}

/**
 * The host manifest, decoded.
 *
 * @return array<mixed, mixed>
 */
function gateTimerManifest(): array
{
    /** @var array<mixed, mixed> $manifest */
    $manifest = (array) json_decode((string) File::get(base_path('composer.json')), true);

    return $manifest;
}

/**
 * One value out of the host manifest by key path, or null when the path is
 * absent.
 *
 * @param  list<string>  $path
 */
function gateTimerManifestValue(array $path): mixed
{
    $cursor = gateTimerManifest();

    foreach ($path as $key) {
        if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
            return null;
        }

        $cursor = $cursor[$key];
    }

    return $cursor;
}

/**
 * The last line of an output that carries content, trimmed.
 *
 * Trailing blank lines and carriage returns go first: AC-9 is a claim about the
 * last thing a reader sees, not about the last byte.
 */
function gateTimerLastLine(string $output): string
{
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\R/', $output) ?: []),
        static fn (string $line): bool => $line !== ''
    ));

    return $lines === [] ? '' : (string) end($lines);
}

/*
 * The kill. AC-4's per-step form, and the one case that is red against the
 * T-006 tip, where no project code runs on a timeout at all (AC-7).
 */

it('ends a step that outlasts its own budget, names what happened, and exits 3', function () {
    /*
     * The values the wrapper has to resolve, derived here from the forced limit
     * by its documented rule rather than written out as literals: at limit 2
     * the reserve is max(2, ceil(0.04)) = 2 and the budget is max(1, 0) = 1.
     * Deriving them is what makes the printed triple below an assertion about
     * the resolution rather than a copy of today's output.
     */
    $limit = 2;
    $reserve = max(2, (int) ceil($limit * 0.02));
    $budget = max(1, $limit - $reserve);

    // The child writes one line before its sleep and would write another after
    // it, so the second line is the executable half of the sentinel's claim
    // that no result was produced.
    $result = gateTimerRun(
        ['-r', 'echo "child tick", PHP_EOL; usleep(8000000); echo "child done", PHP_EOL;'],
        (string) $limit
    );

    $output = $result->output();

    // The refusal this case expects, named: a kill by the gate's own timer,
    // which is the pair (FLEXIPOS-GATE-TIMEOUT, exit 3) and nothing else.
    expect($result->exitCode())->toBe(3)
        ->and($output)->toContain('FLEXIPOS-GATE-TIMEOUT')
        ->and($output)->toContain('step=probe')
        ->and($output)->toContain('NO TEST RESULT WAS PRODUCED.');

    /*
     * And the timer was this file's own rather than the package manager's
     * (R18). That claim is the resolved triple, asserted as one contiguous run
     * of fields: the limit this process read from the environment, the budget
     * it derived from that limit, and the label naming which input won. A
     * wrapper that inherited a limit from elsewhere, derived the budget by a
     * different rule, or printed these fields in another order cannot produce
     * this string - and none of that depends on how long anything took.
     */
    expect($output)->toContain(' budget='.$budget.'s limit='.$limit.'s source=env');

    // Output the child wrote before the kill survives, which is the whole
    // reason a sentinel printed by project code is readable at all...
    expect($output)->toContain('child tick');

    // ...and the line it would have written on completion is absent, which is
    // what NO TEST RESULT WAS PRODUCED asserts in prose: here it is the same
    // claim in a form that reddens if the child was allowed to finish.
    expect($output)->not->toContain('child done');

    /*
     * The timestamp, and explicitly its offset (S-1). The wrapper formats with
     * gmdate(DATE_ATOM) on purpose: this machine's PHP CLI runs Europe/Berlin,
     * so date() here would print +02:00 - matching neither the commits (+05:30)
     * nor UTC, and drifting with configuration. Asserting the +00:00 literal is
     * what makes a later gmdate -> date edit redden instead of silently shifting
     * every timestamp the gate has ever printed.
     */
    expect($output)->toMatch(
        '/FLEXIPOS-GATE-TIMEOUT[^\r\n]* at=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00/'
    );

    // A killed step is never also a completed one.
    expect($output)->not->toContain('FLEXIPOS-GATE-MARGIN');

    /*
     * The elapsed time, bounded below by the budget the same line reports and
     * deliberately not bounded above (R18). A kill cannot precede its budget
     * expiring, so the lower bound is an invariant. A ceiling is not: the
     * child component checks the timeout only between read-loop iterations, so
     * detection latency has no upper bound under load, and any fixed ceiling is
     * a bet against that tail. The bet lost at 2.22s against a 2.0 ceiling - a
     * false red inside the proof that this gate does not produce false reds.
     *
     * So the residual is written down instead of asserted: on this platform no
     * wall-clock upper bound on a kill is assertable. The claim the ceiling was
     * reaching for - that this timer ends the step before the package manager
     * would - is meaningful only inside a real run, and nothing enforces a
     * limit here, because this case spawns the wrapper directly. It is covered
     * empirically by AC-4's per-step recipe, with the sentinel's presence as
     * the discriminator rather than a number (D-3, R3).
     */
    expect(preg_match(
        '/FLEXIPOS-GATE-TIMEOUT[^\r\n]* elapsed=([0-9.]+)s budget=(\d+)s/',
        $output,
        $matches
    ))->toBe(1);

    expect((float) $matches[1])->toBeGreaterThanOrEqual((float) $matches[2]);
});

/*
 * The negative controls: a pass is not a kill (AC-6) and a failure is not a
 * kill (AC-5, wrapper half). Without these, the sentinel assertion above would
 * pass just as well against a wrapper that printed it on every path.
 */

it('reads the limit from the manifest when none is forced, and puts the margin line last', function () {
    $result = gateTimerRun(['-r', 'echo "child done", PHP_EOL;'], false);

    $output = $result->output();
    $limit = (int) gateTimerManifestValue(['config', 'process-timeout']);

    expect($limit)->toBeGreaterThan(0);

    /*
     * The limit the margin line reports is the manifest's, and since step 1b the
     * line says so itself (R9). That is what makes this the manifest branch of
     * the resolution rather than a case that merely happens to agree with the
     * manifest's number: a wrapper reading the limit from somewhere else would
     * have to label it something else.
     */
    expect($result->exitCode())->toBe(0)
        ->and($output)->toContain('child done')
        ->and($output)->toContain('limit='.$limit.'s')
        ->and($output)->toContain('source=manifest')
        ->and($output)->not->toContain('source=env')
        ->and($output)->not->toContain('FLEXIPOS-GATE-TIMEOUT')
        ->and($output)->not->toContain('FLEXIPOS-GATE-LOW-HEADROOM');

    // source= is the last field before the prose, which on the margin line means
    // the last field of the line.
    expect(gateTimerLastLine($output))->toStartWith('FLEXIPOS-GATE-MARGIN step=probe')
        ->and(gateTimerLastLine($output))->toEndWith('source=manifest');

    // S-1 again, on the wrapper's other gmdate() call site: the kill case's
    // assertion would stay green if only this line were switched to local time.
    expect($output)->toMatch(
        '/FLEXIPOS-GATE-MARGIN[^\r\n]* at=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00/'
    );
});

it('passes a failing step through with its own status, on its own stream, and no sentinel', function () {
    $result = gateTimerRun(['-r', 'fwrite(STDERR, "child failed".PHP_EOL); exit(1);'], '60');

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('child failed')
        ->and($result->output())->not->toContain('FLEXIPOS-GATE-TIMEOUT');

    expect(gateTimerLastLine($result->output()))->toStartWith('FLEXIPOS-GATE-MARGIN');
});

it('lets a step exit 3 of its own accord, with the margin line and without the sentinel', function () {
    /*
     * This is the half that makes the pair (status, sentinel) necessary rather
     * than decorative. A composite script propagates a step's status verbatim,
     * so a wrapped tool that exits 3 itself also reaches the shell as a 3 - and
     * the only thing separating it from a kill is that the sentinel is absent
     * and the margin line is present.
     */
    $result = gateTimerRun(['-r', 'exit(3);'], '60');

    expect($result->exitCode())->toBe(3)
        ->and($result->output())->not->toContain('FLEXIPOS-GATE-TIMEOUT');

    expect(gateTimerLastLine($result->output()))->toStartWith('FLEXIPOS-GATE-MARGIN');
});

/*
 * The margin warning that can actually fire (AC-10, per-step form).
 */

it('warns when a completed step leaves less headroom than the factor requires, and still exits 0', function () {
    /*
     * limit 8 -> reserve max(2, ceil(0.16)) = 2 -> budget 6. A child of about
     * 4.5 s is therefore comfortably inside the budget and comfortably past
     * limit / factor = 4, so the warning fires on a run that passed.
     */
    $result = gateTimerRun(['-r', 'usleep(4500000);'], '8');

    $output = $result->output();

    expect($result->exitCode())->toBe(0)
        ->and($output)->toContain('FLEXIPOS-GATE-LOW-HEADROOM')
        ->and($output)->toContain('raise config.process-timeout')
        ->and($output)->not->toContain('FLEXIPOS-GATE-TIMEOUT');

    // The factor the warning quotes is the manifest's rather than a second copy
    // of it, and the ratio it reports really is below that factor.
    expect(preg_match(
        '/FLEXIPOS-GATE-LOW-HEADROOM step=probe ratio=([0-9.]+) source=(\w+) below the required ([0-9.]+)x \(D-1\)/',
        $output,
        $warning
    ))->toBe(1);

    /*
     * R9's reason for putting the label here, asserted: this line advises
     * raising config.process-timeout, and on this run the limit came from the
     * environment, so that advice is addressed to a knob that is not in force.
     * The label is what lets the reader being advised tell which it is.
     */
    expect($warning[2])->toBe('env');

    expect((float) $warning[3])->toBe((float) gateTimerManifestValue(['extra', 'flexipos', 'gate', 'headroom-factor']))
        ->and((float) $warning[1])->toBeLessThan((float) $warning[3]);

    // The warning comes first; the margin line is still the last thing printed,
    // and it reports the same ratio.
    expect(gateTimerLastLine($output))->toStartWith('FLEXIPOS-GATE-MARGIN');

    expect(preg_match('/FLEXIPOS-GATE-MARGIN[^\r\n]* ratio=([0-9.]+) source=(\w+)/', $output, $margin))->toBe(1);

    expect($margin[1])->toBe($warning[1])
        // And both lines of one run name the same source, which is the forced
        // one - the other direction of the label from the manifest case above.
        ->and($margin[2])->toBe('env');
});

it('ignores a limit in the environment that is not a positive whole number', function () {
    /*
     * The resolution order is environment, then manifest, then the documented
     * default, and it is asserted in both directions: without the control at
     * the end, "the manifest's limit was reported" would be evidence of
     * nothing, because it is also what a wrapper that never reads the
     * environment prints.
     *
     * The default branch is not reachable from a test while the manifest
     * carries the key, so it is covered by reading rather than by running, and
     * the wrapper's header says so.
     */
    $limit = (int) gateTimerManifestValue(['config', 'process-timeout']);

    expect($limit)->not->toBe(61);

    foreach (['0', '-5', '12.5', 'later', ''] as $rubbish) {
        $result = gateTimerRun(['-r', 'exit(0);'], $rubbish);

        expect($result->exitCode())->toBe(0)
            ->and($result->output())->toContain('limit='.$limit.'s');
    }

    // The control: a value that IS a positive whole number does win, and the
    // kill line names it, which is the only line that carries the label.
    expect(gateTimerRun(['-r', 'exit(0);'], '61')->output())->toContain('limit=61s');

    expect(gateTimerRun(['-r', 'usleep(8000000);'], '2')->output())->toContain('source=env');
});

it('refuses an invocation it cannot run, with a status of its own', function () {
    /*
     * The fourth and last branch of the wrapper's exit space: 0 or the child's
     * own status on a completed step, 3 on a kill, and 2 when the invocation
     * itself is wrong. Asserted because an unexercised branch in the gate's own
     * tooling is the kind of thing that only ever runs on the day the gate is
     * already broken.
     */
    foreach ([['test'], ['test', '--'], ['--', '-r', 'exit(0);']] as $arguments) {
        $result = Process::path(base_path())
            ->timeout(60)
            ->run(array_merge([PHP_BINARY, base_path('tools'.DIRECTORY_SEPARATOR.'gate-step.php')], $arguments));

        expect($result->exitCode())->toBe(2)
            ->and($result->errorOutput())->toContain('usage: php tools/gate-step.php');

        // A misuse is not a kill and not a completed step either.
        expect($result->output())->not->toContain('FLEXIPOS-GATE-TIMEOUT');
        expect($result->output())->not->toContain('FLEXIPOS-GATE-MARGIN');
    }

    // The control: the same wrapper, invoked correctly, does run the child.
    expect(gateTimerRun(['-r', 'echo "ran", PHP_EOL;'], '60')->output())->toContain('ran');
});

/*
 * The manifest cases. These make AC-2, AC-3, AC-11, AC-12 and AC-42 standing
 * assertions instead of prose that decays - FP-171's own remedy. Nothing below
 * spawns anything.
 */

it('keeps the limit finite, positive and above the factor times the slowest cold step', function () {
    $limitValue = gateTimerManifestValue(['config', 'process-timeout']);
    $cacheColdValue = gateTimerManifestValue(['extra', 'flexipos', 'gate', 'cache-cold-slowest-step-seconds']);
    $machineColdValue = gateTimerManifestValue(['extra', 'flexipos', 'gate', 'machine-cold-slowest-step-seconds']);
    $machineFactorValue = gateTimerManifestValue(['extra', 'flexipos', 'gate', 'machine-cold-factor']);
    $factorValue = gateTimerManifestValue(['extra', 'flexipos', 'gate', 'headroom-factor']);

    expect($limitValue)->toBeInt()
        ->and($limitValue)->toBeGreaterThan(0)
        ->and($cacheColdValue)->toBeInt()
        ->and($cacheColdValue)->toBeGreaterThan(0)
        ->and($machineFactorValue)->toBeInt()
        ->and($machineFactorValue)->toBeGreaterThanOrEqual(1)
        ->and($factorValue)->toBeInt()
        ->and($factorValue)->toBeGreaterThanOrEqual(2);

    $limit = (int) $limitValue;
    $factor = (int) $factorValue;

    /*
     * D-12's sentence, executable: AC-2's S is the machine-cold slowest step
     * when one has been measured, and the cache-cold slowest step times the
     * machine-cold factor when none has.
     *
     * The two branches are NOT both tested, and saying so beats implying
     * otherwise. The fallback is the live one, because
     * machine-cold-slowest-step-seconds is null: the only machine-cold figure
     * anyone has observed on this machine is a whole-gate run, not one process
     * (plan R11). The observed branch is covered by reading until somebody
     * measures a step that way, and the assertion below pins which branch runs
     * so that a future edit to the manifest cannot silently swap them.
     */
    $slowestColdStep = is_int($machineColdValue) && $machineColdValue > 0
        ? $machineColdValue
        : (int) $machineFactorValue * (int) $cacheColdValue;

    expect($machineColdValue)->toBeNull()
        ->and($slowestColdStep)->toBeGreaterThan((int) $cacheColdValue);

    // AC-2 as arithmetic rather than as a sentence: the limit clears the factor
    // times the slowest cold step...
    expect($limit)->toBeGreaterThanOrEqual($factor * $slowestColdStep);

    /*
     * ...and so does the budget the wrapper actually enforces, which is the
     * binding number for the test step and is strictly smaller than the limit.
     * This is the half that matters: a limit that clears the factor while the
     * budget does not would kill a healthy suite on schedule.
     *
     * At the shipped values this demands 882 >= 636. Before step 1b, when a
     * single `cold-` key meant the cache-cold figure alone, the same assertion
     * demanded only 882 >= 212 - weaker than the criterion it was guarding.
     */
    $budget = max(1, $limit - max(2, (int) ceil($limit * 0.02)));

    expect($budget)->toBeGreaterThanOrEqual($factor * $slowestColdStep)
        ->and($budget)->toBeLessThan($limit);

    /*
     * And the rest of the plan's rule for choosing the limit, so the number in
     * the manifest reads as a decision rather than as a guess: a whole multiple
     * of 300 (the package manager's own default is one), and never under 600.
     * Both are structural, not measurements, so neither needs a date.
     */
    expect($limit % 300)->toBe(0)
        ->and($limit)->toBeGreaterThanOrEqual(600);
});

it('dates the cold measurement and names the command that re-measures it', function () {
    // AC-11 and AC-42 for the manifest: the only duration written there is the
    // cold measurement, and it carries a parseable date and a re-run recipe.
    $measuredOn = (string) gateTimerManifestValue(['extra', 'flexipos', 'gate', 'measured-on']);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $measuredOn);

    expect($parsed)->toBeInstanceOf(DateTimeImmutable::class);
    expect($parsed === false ? '' : $parsed->format('Y-m-d'))->toBe($measuredOn);

    expect((string) gateTimerManifestValue(['extra', 'flexipos', 'gate', 'measured-by']))->not->toBe('');

    /*
     * AC-11 for the one number in there that was borrowed from somewhere else:
     * the machine-cold factor is not a measurement of a step, it is a ratio of
     * two whole-gate runs, so it carries a sibling string saying where it came
     * from. Without that a reader would take the 3 for a measured per-process
     * figure - which is exactly what machine-cold-slowest-step-seconds being
     * null says nobody has.
     */
    $provenance = (string) preg_replace(
        '/\s+/',
        ' ',
        (string) gateTimerManifestValue(['extra', 'flexipos', 'gate', 'machine-cold-factor-provenance'])
    );

    expect($provenance)->toContain('D-12')
        ->and($provenance)->toContain('MACHINE-COLD')
        ->and($provenance)->toContain('WARM')
        ->and($provenance)->toContain('2026-10-07');

    $remeasure = (string) preg_replace(
        '/\s+/',
        ' ',
        (string) gateTimerManifestValue(['extra', 'flexipos', 'gate', 'remeasure-with'])
    );

    // The recipe has to name the caches to remove and a command to run, or it
    // is a sentence rather than a re-measurement.
    expect($remeasure)->toContain('composer')
        ->and($remeasure)->toContain('phpstan')
        ->and($remeasure)->toContain('deptrac');
});

it('never disables the process timeout in the gate or in any test command', function () {
    /*
     * AC-3 and D-2. The one script allowed to disable the limit is the serve
     * command, and the control at the end proves the matcher finds it there -
     * otherwise "absent from the other four" would be indistinguishable from a
     * matcher that never matches anything.
     */
    foreach (['check', 'test', 'test:fast', 'test:slow'] as $name) {
        $encoded = (string) json_encode(gateTimerManifestValue(['scripts', $name]));

        expect($encoded)->not->toBe('null');
        expect($encoded)->not->toContain('disableProcessTimeout');
        expect($encoded)->not->toContain('COMPOSER_PROCESS_TIMEOUT');
    }

    expect((string) json_encode(gateTimerManifestValue(['scripts', 'dev'])))->toContain('disableProcessTimeout');
});

it('wraps the test step only, and leaves the six gate steps and the group flags alone', function () {
    /*
     * AC-12. The timer is deliberately applied to one step: a kill of lint,
     * stan or deptrac still shows only the package manager's message, which the
     * plan records as a residual (R7) rather than something this commit closes.
     * Asserting it here is what stops it being closed by accident, unnoticed.
     */
    /*
     * T-008 step 0 adds a sixth step, engine:clean, beside probes:clean and for
     * the same measured reason: a killed run leaves a reserved test database
     * behind, and check runs lint, stan and deptrac before any test hook could
     * remove it. The list is still pinned exactly - amended, not loosened - so
     * the timer still wraps one step and only one.
     */
    expect(gateTimerManifestValue(['scripts', 'check']))
        ->toBe(['@probes:clean', '@engine:clean', '@lint', '@stan', '@deptrac', '@test']);

    foreach (['lint', 'stan', 'deptrac', 'test:fast', 'test:slow'] as $name) {
        expect((string) json_encode(gateTimerManifestValue(['scripts', $name])))
            ->not->toContain('gate-step.php');
    }

    expect((string) json_encode(gateTimerManifestValue(['scripts', 'test'])))->toContain('gate-step.php');

    expect((string) json_encode(gateTimerManifestValue(['scripts', 'test:fast'])))
        ->toContain('--exclude-group=toolchain');

    expect((string) json_encode(gateTimerManifestValue(['scripts', 'test:slow'])))
        ->toContain('--group=toolchain');
});

it('describes the test step by pointing at the one authority for the limit', function () {
    /*
     * FP-181: the limit's justification lives in extra.flexipos.gate and every
     * other mention points there rather than repeating the number. Whitespace
     * is collapsed before the phrases are looked for, because a line-oriented
     * search over a wrapped string produced a false pass on this project once
     * already.
     */
    $description = (string) preg_replace(
        '/\s+/',
        ' ',
        (string) gateTimerManifestValue(['scripts-descriptions', 'test'])
    );

    expect($description)->toContain('FLEXIPOS-GATE-TIMEOUT')
        ->and($description)->toContain('extra.flexipos.gate')
        ->and($description)->toContain('exits 3')
        // D-12's vocabulary: "cold" alone is ambiguous now that it names two
        // states, so the description says which one the manifest holds.
        ->and($description)->toContain('cache-cold');

    // And it carries no duration of its own to go stale (AC-11, AC-42).
    expect(preg_match('/\d+\s*(?:s|sec|secs|second|seconds)\b/i', $description))->toBe(0);
});

it('is registered in the toolchain group, because it spawns children', function () {
    // Mechanically required: the registry sweep in ToolchainGroupCoverageTest
    // compares the group against every test file whose source starts a child
    // process, and this one does.
    expect(toolchainGroupFiles())->toContain('modules/host/tests/Feature/GateStepTimerTest.php');
});
