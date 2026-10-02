<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * T-002 FP-149 (T-001 review finding M-6).
 *
 * `config:cache` bakes MODULES into bootstrap/cache/config.php and the process
 * never reads the variable again, so one image silently stops being able to run
 * a different module subset per deployment stage - the single guarantee this
 * host exists for. The guard makes that disagreement a boot failure.
 *
 * These cases shell out to real artisan child processes because the hazard only
 * exists in a freshly bootstrapped process: bootstrap/cache/config.php is read
 * once, during bootstrap, before any test body could set it up in memory.
 *
 * That file is real, shared state. Every other test in the suite boots
 * uncached, so a leftover cache file here would quietly break unrelated tests in
 * the same run, not merely a later run of this one. Hence the pre-flight
 * assertion, the finally block and the afterEach.
 */

function configCachePath(): string
{
    return base_path('bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php');
}

/**
 * Run one artisan command in a child process with a chosen ambient MODULES.
 *
 * Passing false removes the variable from the child's environment entirely,
 * which is the "container started with no override at all" case.
 */
function configGuardArtisan(string $command, string|false $modules): ProcessResult
{
    return Process::path(base_path())
        ->env(['MODULES' => $modules])
        ->run([PHP_BINARY, base_path('artisan'), $command, '--no-ansi']);
}

afterEach(function () {
    File::delete(configCachePath());
});

it('fails fast when a cached MODULES value disagrees with the process environment', function () {
    expect(File::exists(configCachePath()))
        ->toBeFalse('a leftover bootstrap/cache/config.php from an earlier crashed run would make this test meaningless');

    try {
        expect(configGuardArtisan('config:cache', 'demo')->exitCode())->toBe(0)
            ->and(File::exists(configCachePath()))->toBeTrue();

        // AC-9: the frozen value and the ambient value disagree.
        $mismatch = configGuardArtisan('route:list', 'billing');
        $output = $mismatch->output().$mismatch->errorOutput();

        expect($mismatch->exitCode())->not->toBe(0)
            ->and($output)->toContain('MODULES="demo"')
            ->and($output)->toContain('MODULES="billing"')
            ->and($output)->toContain('config:clear');

        // AC-10: the legitimate case - one image built and started with the
        // same MODULES throughout - boots with no complaint.
        $matching = configGuardArtisan('route:list', 'demo');

        expect($matching->exitCode())->toBe(0)
            ->and($matching->output())->toContain('health/live');

        // Cached, and nothing in the environment is trying to override it.
        // Nobody is being misled, so the guard must stay quiet: a guard that
        // fires here is one people would switch off.
        expect(configGuardArtisan('route:list', false)->exitCode())->toBe(0);
    } finally {
        $clear = configGuardArtisan('config:clear', 'demo');

        // Delete before asserting, so a failed assertion above can never leave
        // the cache file behind for the rest of the run.
        File::delete(configCachePath());

        expect($clear->exitCode())->toBe(0)
            ->and(File::exists(configCachePath()))->toBeFalse();
    }
});

it('stays out of the way entirely when the configuration is not cached', function () {
    expect(File::exists(configCachePath()))->toBeFalse();

    // Uncached, MODULES is read live, so the frozen and ambient values cannot
    // disagree. A name with no package on disk must still fail the ordinary
    // way, never with the cache guard's message.
    $result = configGuardArtisan('route:list', 'billing');
    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->not->toBe(0)
        ->and($output)->toContain('Unknown module "billing" listed in MODULES')
        ->and($output)->not->toContain('Cached configuration disagrees');

    expect(configGuardArtisan('route:list', 'demo')->exitCode())->toBe(0)
        ->and(File::exists(configCachePath()))->toBeFalse();
});
