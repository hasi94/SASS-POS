<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/*
 * FP-169, the configuration half. The behavioural half - which proves the two
 * tools then actually find something - is
 * tests/Feature/HostTestAnalysisEnforcementTest.php.
 *
 * phpstan.neon.dist and deptrac.yaml both listed ../demo/tests and
 * ../../packages/kernel/tests but not the host's own tests directory, so the
 * shared test infrastructure T-004 moved into it - Helpers.php, Pest.php,
 * TestCase.php, which many test files depend on - was the only code in the
 * repository that neither tool ever looked at.
 *
 * This file spawns nothing, so it stays in `composer test:fast` and must NOT be
 * added to toolchainGroupFiles(): the cheap registry check should run in the
 * inner loop, while its expensive sibling does not. Tagging is per file
 * (T-004 D-2).
 */

/**
 * The entries of a PHPStan Neon configuration's `paths:` block.
 *
 * Hand-parsed because this project has no Neon parser: PHPStan ships its own
 * inside its phar and nette/neon is not a Composer dependency here. Returning
 * the list rather than searching the raw text is what lets the assertions below
 * be exact - a plain `toContain('tests')` over the file's source would be
 * satisfied by the pre-existing `../demo/tests` line and would therefore pass
 * even if the host's own entry were deleted.
 *
 * @return list<string>
 */
function hostAnalysisNeonPathEntries(string $neon): array
{
    $entries = [];
    $inside = false;

    foreach (preg_split('/\R/', $neon) ?: [] as $line) {
        if (preg_match('/^\s+paths:\s*$/', $line) === 1) {
            $inside = true;

            continue;
        }

        if (! $inside) {
            continue;
        }

        if (preg_match('/^\s+-\s+(\S+)\s*$/', $line, $matches) === 1) {
            $entries[] = $matches[1];

            continue;
        }

        // Comments and blank lines are part of the block; the next key ends it.
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        break;
    }

    return $entries;
}

/**
 * The shipped Deptrac configuration, parsed.
 *
 * @return array<string, mixed>
 */
function hostAnalysisDeptracConfig(): array
{
    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parseFile(base_path('deptrac.yaml'));

    /** @var array<string, mixed> $deptrac */
    $deptrac = $parsed['deptrac'];

    return $deptrac;
}

it('registers the host\'s own tests directory with both analysers', function () {
    $shipped = File::get(base_path('phpstan.neon.dist'));
    $entries = hostAnalysisNeonPathEntries($shipped);

    // Positive control on the parser, on the same input: a parser that silently
    // returned nothing would make the real assertion below vacuous.
    expect($entries)->toContain('app')
        ->and($entries)->toContain('../demo/tests')
        ->and($entries)->toContain('tests');

    // The negative control AC-23 asks for, run over a copy in memory rather than
    // a scratch checkout: nothing on disk is touched, so this cannot leave the
    // registry edited for the tests that follow.
    $without = str_replace("\n        - tests\n", "\n", $shipped);

    expect($without)->not->toBe($shipped);

    $mutated = hostAnalysisNeonPathEntries($without);

    expect($mutated)->not->toContain('tests')
        // ... and the parser still works on the mutated copy, so the absence
        // above is absence and not a parse failure.
        ->and($mutated)->toContain('../demo/tests');

    $deptrac = hostAnalysisDeptracConfig();

    /** @var list<string> $paths */
    $paths = $deptrac['paths'];

    /** @var list<array<string, mixed>> $layers */
    $layers = $deptrac['layers'];

    /** @var array<string, mixed> $ruleset */
    $ruleset = $deptrac['ruleset'];

    $layerNames = array_column($layers, 'name');

    expect($paths)->toContain('tests')
        // The path alone is inert: the host's Pest files declare no classes and
        // Tests\* belonged to no layer, so nothing in that directory could ever
        // be the depender in a rule. The layer is what makes the path mean
        // something.
        ->and($layerNames)->toContain('HostTests')
        ->and($ruleset)->toHaveKey('HostTests');

    /** @var list<string> $allowed */
    $allowed = $ruleset['HostTests'];

    // The omission is the load-bearing part: granting HostTests any module
    // layer would silently retire the AC-22 proof, so assert it is absent
    // rather than trusting a comment to stop someone adding it.
    expect($allowed)->toContain('HostApp')
        ->and($allowed)->toContain('Package_Kernel')
        ->and(array_filter($allowed, static fn (string $layer): bool => str_starts_with($layer, 'Module_')))->toBe([]);
});
