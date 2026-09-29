<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * QA proofs for T-002 AC-9, AC-10 and AC-11.
 *
 * The guard has four branches and two of them are silent, which is the half
 * that is easy to get wrong: a guard that fires on a container legitimately
 * started with the SERVICES it was built with is a defect, not a safety net,
 * because it is the kind people switch off. So both silent branches are
 * asserted here against values that differ from the frozen one only in the
 * ways ServiceRegistry::parse() is documented to normalise away - order,
 * whitespace and duplicates - not merely against a byte-identical string.
 *
 * bootstrap/cache/config.php is real, shared state that every other test in
 * the run depends on being absent, hence the pre-flight assertion, the finally
 * block and the afterEach - the same discipline ConfigCacheGuardTest uses.
 */

function qaCacheFile(): string
{
    return base_path('bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php');
}

/**
 * Run one artisan command in a child process with a chosen ambient SERVICES.
 * Passing false removes the variable from the child environment entirely.
 */
function qaCacheArtisan(string $command, string|false $services): ProcessResult
{
    return Process::path(base_path())
        ->env(['SERVICES' => $services])
        ->run([PHP_BINARY, base_path('artisan'), $command, '--no-ansi']);
}

afterEach(function () {
    File::delete(qaCacheFile());
    File::deleteDirectory(servicesPath('qacachefixture'));
});

it('stays silent for every ambient value that parses to the frozen list, and fails for every one that does not', function () {
    expect(File::exists(qaCacheFile()))
        ->toBeFalse('a leftover bootstrap/cache/config.php would make this test meaningless');

    // Two services, so "same list, different order" is expressible at all.
    expect(Artisan::call('svc:make', ['name' => 'qacachefixture']))->toBe(0);

    try {
        expect(qaCacheArtisan('config:cache', 'demo,qacachefixture')->exitCode())->toBe(0)
            ->and(File::exists(qaCacheFile()))->toBeTrue();

        // AC-10, the legitimate cases. parse() trims, de-duplicates and sorts,
        // so each of these is the same list the image was built with.
        foreach ([
            'identical' => 'demo,qacachefixture',
            'different order' => 'qacachefixture,demo',
            'surrounding whitespace' => "  demo ,\tqacachefixture  ",
            'duplicated entry' => 'demo,qacachefixture,demo',
            'trailing separator' => 'demo,qacachefixture,',
        ] as $label => $ambient) {
            $result = qaCacheArtisan('route:list', $ambient);

            expect($result->exitCode())->toBe(0, $label.': '.$result->errorOutput())
                ->and($result->output().$result->errorOutput())->not->toContain('Cached configuration disagrees');
        }

        // The other silent branch: cached, and nothing in the environment is
        // trying to override it.
        $unset = qaCacheArtisan('route:list', false);

        expect($unset->exitCode())->toBe(0)
            ->and($unset->output().$unset->errorOutput())->not->toContain('Cached configuration disagrees');

        // AC-9, the loud cases. A subset, a superset and an explicit empty
        // value are all genuine disagreements.
        foreach ([
            'subset' => 'demo',
            'superset' => 'demo,qacachefixture,billing',
            'unrelated' => 'billing',
            'explicitly empty' => '',
        ] as $label => $ambient) {
            $result = qaCacheArtisan('route:list', $ambient);
            $output = $result->output().$result->errorOutput();

            expect($result->exitCode())->not->toBe(0, $label)
                ->and($output)->toContain('SERVICES="demo,qacachefixture"')
                ->and($output)->toContain(sprintf('SERVICES="%s"', $ambient))
                ->and($output)->toContain('config:clear');
        }
    } finally {
        $clear = qaCacheArtisan('config:clear', 'demo,qacachefixture');

        // Delete first, assert second: a failed assertion above must not be
        // able to leave the cache file behind for the rest of the run.
        File::delete(qaCacheFile());

        expect($clear->exitCode())->toBe(0);
    }

    expect(File::exists(qaCacheFile()))->toBeFalse();
});

it('never fires while the configuration is not cached, whatever the environment says', function () {
    expect(File::exists(qaCacheFile()))->toBeFalse();

    // The branch the whole existing suite runs under. SERVICES is read live, so
    // there is nothing frozen to disagree with; an unknown name must still fail
    // the ordinary way and with the ordinary message.
    $good = qaCacheArtisan('route:list', 'demo');

    expect($good->exitCode())->toBe(0)
        ->and($good->output())->not->toContain('Cached configuration disagrees');

    $bad = qaCacheArtisan('route:list', 'billing');
    $output = $bad->output().$bad->errorOutput();

    expect($bad->exitCode())->not->toBe(0)
        ->and($output)->toContain('Unknown service "billing"')
        ->and($output)->not->toContain('Cached configuration disagrees')
        ->and(File::exists(qaCacheFile()))->toBeFalse();
});

it('documents all four caching and autoloader constraints in one findable place', function () {
    // AC-11: the content has to exist where a developer about to run these
    // commands will actually read it, and has to say which of the four is
    // guarded by code and which are prose only.
    $config = File::get(base_path('config'.DIRECTORY_SEPARATOR.'servicehost.php'));
    $readme = File::get(dirname(base_path(), 2).DIRECTORY_SEPARATOR.'README.md');

    foreach (['config/servicehost.php' => $config, 'README.md' => $readme] as $where => $text) {
        expect($text)->toContain('config:cache')
            ->and($text)->toContain('mergeConfigFrom')
            ->and($text)->toContain('route:cache')
            ->and($text)->toContain('--classmap-authoritative')
            ->and(strtolower($text))->toContain('guarded')
            ->and(strtolower($text))->toContain('documented only');
    }

    // Three of the four are documentation only; exactly one is code-guarded,
    // and the code it names has to exist.
    expect(substr_count(strtolower($config), 'documented only'))->toBe(3)
        ->and($config)->toContain('guardAgainstStaleCachedServices')
        ->and(File::get(base_path('app'.DIRECTORY_SEPARATOR.'Providers'.DIRECTORY_SEPARATOR.'ServiceHostServiceProvider.php')))
        ->toContain('function guardAgainstStaleCachedServices');
});
