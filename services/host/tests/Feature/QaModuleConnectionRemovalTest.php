<?php

use App\Console\Commands\SvcMakeCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Finder\Finder;

/*
 * QA proof for T-003 AC-19, AC-20, AC-21 and AC-23, and the ruling on T-001
 * AC-25.
 *
 * AC-20 says a generated module declares no connection "anywhere". `dev`'s
 * SvcMakeValidationTest checks the two places it used to be declared; this
 * checks the whole generated tree and the whole stub directory for every token
 * the old model used, so a connection re-appearing in a file nobody thought to
 * list is caught too.
 *
 * T-001 AC-25 is SUPERSEDED here, not regressed: its "database connection
 * configuration entry named for the service" clause is removed by AC-19/AC-20,
 * and every other element it required is re-proved below.
 */

/**
 * The fixture package this file generates. Listed in
 * QaToolchainRegistryCoverageTest::qaFixturePackageNames(), so its transient
 * existence does not turn the registry backstop red mid-run.
 */
function qaModFixtureName(): string
{
    return 'qatenancyfixture';
}

/**
 * Every token the removed per-module connection model used. None of them may
 * appear in a stub or in a generated package.
 *
 * @return array<string, string>
 */
function qaModForbiddenTokens(): array
{
    return [
        'the merge that registered a module connection' => 'mergeConfigFrom',
        'the per-module connection constant' => 'const CONNECTION',
        'a write into the connection list' => 'database.connections',
        'the connection-name placeholder' => '{{connection}}',
        'the schema-name placeholder' => '{{schema}}',
        'the screaming-case env placeholder' => '{{screaming}}',
        'a per-module connection name' => 'svc_'.qaModFixtureName(),
        'a per-module schema name' => 'pos_'.qaModFixtureName(),
    ];
}

/**
 * Relative path => contents for every file under a directory.
 *
 * @return array<string, string>
 */
function qaModTreeContents(string $root): array
{
    $contents = [];

    foreach (Finder::create()->in($root)->files()->ignoreDotFiles(false)->ignoreVCS(true) as $file) {
        $contents[str_replace('\\', '/', $file->getRelativePathname())] = (string) file_get_contents($file->getPathname());
    }

    ksort($contents);

    return $contents;
}

afterEach(function () {
    File::deleteDirectory(servicesPath(qaModFixtureName()));
});

it('ships no stub that can produce a per-module database connection', function () {
    // AC-19, from the stub directory rather than from the command's file list,
    // because a stub the command stopped rendering but nobody deleted would
    // still be the template someone copies.
    $stubs = base_path('stubs'.DIRECTORY_SEPARATOR.'service');

    expect(File::exists($stubs.DIRECTORY_SEPARATOR.'connection.config.php.stub'))->toBeFalse();

    $offenders = [];

    foreach (qaModTreeContents($stubs) as $relative => $contents) {
        foreach (qaModForbiddenTokens() as $why => $token) {
            // The service-name-derived tokens cannot appear in an unrendered
            // stub, so only the literal ones are meaningful here.
            if (str_starts_with($token, 'svc_') || str_starts_with($token, 'pos_')) {
                continue;
            }

            if (str_contains($contents, $token)) {
                $offenders[] = $relative.': '.$why.' ('.$token.')';
            }
        }
    }

    expect($offenders)->toBe([], implode('; ', $offenders));

    // Negative control: an assertion that nothing was found is only evidence if
    // the same sweep finds something when it is there. config/servicehost.php
    // documents the mergeConfigFrom constraint and keeps that literal token, so
    // the detector must report it.
    $control = (string) file_get_contents(config_path('servicehost.php'));
    $found = [];

    foreach (qaModForbiddenTokens() as $why => $token) {
        if (str_contains($control, $token)) {
            $found[] = $why;
        }
    }

    expect($found)->toContain('the merge that registered a module connection');

    // And the generator's own file list no longer maps one.
    $files = (new ReflectionClassConstant(SvcMakeCommand::class, 'FILES'))->getValue();

    expect($files)->toBeArray();

    /** @var array<string, string> $files */
    $targets = array_values($files);

    expect(array_keys($files))->not->toContain('connection.config.php.stub')
        ->and($targets)->not->toContain('config/connection.php');

    foreach ($targets as $target) {
        expect(str_contains($target, 'connection'))->toBeFalse('FILES still renders '.$target);
    }
});

it('generates a module that declares no database connection anywhere in its tree', function () {
    // AC-20, swept over every generated file rather than over a list of the
    // places it used to be.
    expect(Artisan::call('svc:make', ['name' => qaModFixtureName()]))->toBe(0);

    $root = servicesPath(qaModFixtureName());
    $tree = qaModTreeContents($root);

    expect($tree)->not->toBe([])
        ->and(array_keys($tree))->not->toContain('config/connection.php')
        // The directory survives as the conventional home for a module's own
        // configuration; it is simply empty.
        ->and(array_keys($tree))->toContain('config/.gitkeep')
        ->and(trim($tree['config/.gitkeep'] ?? 'not empty'))->toBe('');

    $offenders = [];

    foreach ($tree as $relative => $contents) {
        foreach (qaModForbiddenTokens() as $why => $token) {
            if (str_contains($contents, $token)) {
                $offenders[] = $relative.': '.$why.' ('.$token.')';
            }
        }
    }

    expect($offenders)->toBe([], implode('; ', $offenders));

    // The manifest carries neither key, and still carries everything else.
    $manifest = json_decode($tree['composer.json'] ?? '{}', true);

    expect($manifest)->toBeArray();

    /** @var array<string, mixed> $manifest */
    $flexipos = (array) (($manifest['extra'] ?? [])['flexipos'] ?? []);

    expect(array_keys($flexipos))->toBe(['service', 'provider', 'prefix']);

    // The provider has no register() at all now, so there is nothing left that
    // could merge configuration at boot.
    $provider = $tree['src/QatenancyfixtureServiceProvider.php'] ?? '';

    expect($provider)->not->toBe('')
        ->and($provider)->not->toContain('function register')
        ->and($provider)->toContain("const PREFIX = 'api/v1/".qaModFixtureName()."'");
});

it('still generates every other element T-001 AC-25 required', function () {
    // The supersession ruling: exactly one clause of T-001 AC-25 is gone. The
    // rest must be unchanged, or this task broke a criterion instead of
    // replacing one.
    expect(Artisan::call('svc:make', ['name' => qaModFixtureName()]))->toBe(0);

    $root = servicesPath(qaModFixtureName());
    $tree = qaModTreeContents($root);

    foreach ([
        'composer.json',
        'src/QatenancyfixtureServiceProvider.php',
        'src/Http/Controllers/PingController.php',
        'routes/api.php',
        'database/migrations/.gitkeep',
        'src/Consumers/.gitkeep',
        'tests/Feature/PingRouteTest.php',
    ] as $relative) {
        expect(array_keys($tree))->toContain($relative);
    }

    expect(trim($tree['database/migrations/.gitkeep'] ?? 'not empty'))->toBe('')
        ->and(trim($tree['src/Consumers/.gitkeep'] ?? 'not empty'))->toBe('')
        // Routes mounted under the module's own prefix.
        ->and($tree['routes/api.php'] ?? '')->toContain('PingController');
});

it('runs every test a freshly generated module ships, with no further edits', function () {
    // AC-21. The existing QaGeneratedServiceTestDiscoveryTest filters the child
    // run by the package name, which matches only the ping case - so the new
    // "cannot query the tenant connection" case that replaced the connection
    // assertion was never actually executed in a freshly generated package.
    // Running the file by path executes all three.
    expect(Artisan::call('svc:make', ['name' => qaModFixtureName()]))->toBe(0);

    $test = servicesPath(qaModFixtureName())
        .DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'PingRouteTest.php';

    expect(File::exists($test))->toBeTrue();

    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/pest'),
        $test,
        '--colors=never',
    ]);

    $output = $result->output().$result->errorOutput();

    expect($result->exitCode())->toBe(0, $output)
        ->and($output)->not->toContain('No tests found')
        ->and($output)->toMatch('/Tests:\s+3 passed/')
        ->and($output)->toContain('cannot query the tenant connection until a tenant is resolved');
});

it('leaves no connection artefact in the committed demo package', function () {
    // AC-23, and the committed half of AC-22. SvcMakeDemoRegenerationTest proves
    // the tree matches the generator; this proves what the tree says.
    $tree = qaModTreeContents(servicesPath('demo'));

    expect(array_keys($tree))->not->toContain('config/connection.php')
        ->and(array_keys($tree))->toContain('config/.gitkeep');

    $offenders = [];

    foreach ($tree as $relative => $contents) {
        foreach (['mergeConfigFrom', 'const CONNECTION', 'database.connections', 'svc_demo', 'pos_demo'] as $token) {
            if (str_contains($contents, $token)) {
                $offenders[] = $relative.': '.$token;
            }
        }
    }

    expect($offenders)->toBe([], implode('; ', $offenders));

    // Its own test asserts the tenant guard instead of the removed connection,
    // and it is collected by the shipped suite rather than only by this file.
    expect($tree['tests/Feature/PingRouteTest.php'] ?? '')
        ->toContain('cannot query the tenant connection until a tenant is resolved');
});
