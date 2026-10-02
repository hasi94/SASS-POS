<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
 * QA proofs for T-002 AC-14 and AC-15, plus the T-001 AC-3 regression the
 * README edit puts at risk.
 *
 * The review's complaint was not that the documentation was thin but that it
 * was false: it told a developer no file inside services/host has to change,
 * and it claimed an MIT licence for a proprietary codebase. Both are asserted
 * against the files themselves, and the first is asserted against what
 * `svc:make` actually prints, because "matching exactly what svc:make's own
 * printed reminder says" is what AC-14 requires and the two drifting apart is
 * how this became wrong in the first place.
 */

function qaRootReadme(): string
{
    return File::get(dirname(base_path(), 2).DIRECTORY_SEPARATOR.'README.md');
}

afterEach(function () {
    deleteFixtureDirectory(servicesPath('qadocfixture'));
});

it('no longer tells a developer that no file inside the host ever changes', function () {
    $readme = qaRootReadme();

    // Every occurrence of the old sentence must now carry its qualifier: it is
    // true for booting an already generated service and false for everything
    // else, which is precisely the distinction AC-14 asks for.
    $flat = (string) preg_replace('/\s+/', ' ', $readme);

    preg_match_all('/no file inside `services\/host\/` needs to change(.{0,12})/', $flat, $matches);

    expect($matches[0])->not->toBe([]);

    foreach ($matches[1] as $tail) {
        expect($tail)->toStartWith(' for that');
    }

    expect($readme)->toContain('phpstan.neon.dist')
        ->and($readme)->toContain('deptrac.yaml')
        ->and($readme)->toContain('Service_Billing')
        ->and($readme)->toContain('ruleset entry')
        ->and($readme)->toContain('QaToolchainRegistryCoverageTest');
});

it('says the same thing svc:make prints, so the two cannot drift again', function () {
    expect(Artisan::call('svc:make', ['name' => 'qadocfixture']))->toBe(0);

    $printed = Artisan::output();
    $readme = qaRootReadme();

    // The reminder the generator prints names both registries, both directories
    // each of them has to list, a layer and a ruleset entry; the README must
    // name the same things. `tests` is in this loop because it was the one the
    // first version of this test missed: the README named src *and* tests while
    // the reminder named only src, which is exactly the drift AC-14 exists to
    // prevent, found by the reviewer rather than by this test.
    foreach (['phpstan.neon.dist', 'deptrac.yaml', 'ruleset', 'src', 'tests'] as $token) {
        expect($printed)->toContain($token)
            ->and($readme)->toContain($token);
    }

    // Named as the two paths a package actually contributes, not merely
    // mentioned somewhere in the sentence.
    expect($printed)->toContain('services/qadocfixture/src')
        ->and($printed)->toContain('services/qadocfixture/tests')
        ->and($printed)->toContain('Service_Qadocfixture')
        ->and($readme)->toContain('Service_Billing');
});

it('states the purpose, the layout and the three links T-001 AC-3 requires', function () {
    $readme = qaRootReadme();

    expect($readme)->toStartWith('# FlexiPOS')
        ->and($readme)->toContain('## Top-level layout')
        ->and($readme)->toContain('[CLAUDE.md](CLAUDE.md)')
        ->and($readme)->toContain('[docs/architecture.md](docs/architecture.md)')
        ->and($readme)->toContain('[docs/backlog.md](docs/backlog.md)')
        ->and($readme)->toContain('`services/`')
        ->and($readme)->toContain('`packages/`');
});

it('asserts no MIT licence anywhere in first-party code or documentation', function () {
    $host = File::get(base_path('README.md'));

    expect(preg_match('/\bMIT\b/', $host))->toBe(0)
        ->and(strtolower($host))->toContain('proprietary');

    // Every first-party manifest agrees with it.
    $manifests = array_merge(
        (array) glob(servicesPath().DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'composer.json'),
        (array) glob(dirname(servicesPath()).DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'composer.json')
    );

    expect($manifests)->not->toBe([]);

    foreach ($manifests as $manifest) {
        /** @var array<string, mixed> $parsed */
        $parsed = (array) json_decode((string) File::get((string) $manifest), true);

        expect($parsed['license'] ?? null)->toBe('proprietary', (string) $manifest);
    }

    // And no committed file under services/ or packages/ claims one either.
    // Two kinds of hit are not a claim about this codebase and are allowed:
    // a third-party asset's own banner inside a vendored build (the stock
    // welcome view inlines Tailwind's), and this test file, which has to name
    // the string in order to look for it. Both are matched narrowly rather than
    // by skipping whole files, so a real MIT claim added to either would still
    // be caught.
    $offenders = [];

    $files = Finder::create()
        ->in([servicesPath(), dirname(servicesPath()).DIRECTORY_SEPARATOR.'packages'])
        ->exclude(['vendor', 'node_modules', 'storage', 'public'])
        ->notName('composer.lock')
        ->notName('QaDocumentationAccuracyTest.php')
        ->files()
        ->ignoreVCS(true);

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file->getPathname());
        $claims = preg_replace('#/\*! [a-z-]+ v[0-9.]+ \| MIT License \| https?://\S+ \*/#', '', $contents);

        if (preg_match('/\bMIT\b/', (string) $claims) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
