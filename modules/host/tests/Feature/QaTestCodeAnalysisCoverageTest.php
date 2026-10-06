<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * QA proofs for T-002 AC-16 and AC-17, and for the claim that dev's stub fix is
 * real rather than cosmetic.
 *
 * Adding ../demo/tests to phpstan.neon.dist and deptrac.yaml is a one-line
 * change in each; that both lines are present proves nothing about whether the
 * tools then find anything. Each is therefore proven by planting a real defect
 * inside a module test directory and watching the shipped configuration fail
 * on it.
 *
 * The same applies to the generated PingRouteTest stub, which ships into every
 * module created from now on: it moved from $this->get() to Pest's Laravel
 * helper functions because the former is a genuine level 6 error once test
 * directories are analysed. That is asserted here by planting the old form back
 * and watching PHPStan report it.
 */

function qaStanProbePath(): string
{
    return modulesPath('demo').DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'QaStanProbe.php';
}

function qaRunShippedStan(): ProcessResult
{
    return Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/phpstan'),
        'analyse',
        '--configuration='.base_path('phpstan.neon.dist'),
        '--no-progress',
        '--no-ansi',
        '--error-format=raw',
    ]);
}

function qaBoundaryFixtureRoot(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'qa-boundary-fixture');
}

/**
 * A two-module fixture in which a *test* file of one module imports a class
 * from another module's namespace. Written outside modules/ so the host can
 * never try to boot it and the shipped configuration never sees it.
 */
function qaWriteBoundaryFixture(bool $analyseTestDirectory): string
{
    $root = qaBoundaryFixtureRoot();

    File::deleteDirectory($root);
    File::ensureDirectoryExists($root.'/sales/src');
    File::ensureDirectoryExists($root.'/billing/src');
    File::ensureDirectoryExists($root.'/billing/tests');

    File::put($root.'/sales/src/Invoice.php', "<?php\n\nnamespace Flexipos\\Module\\Sales;\n\nclass Invoice {}\n");
    File::put($root.'/billing/src/Ledger.php', "<?php\n\nnamespace Flexipos\\Module\\Billing;\n\nclass Ledger {}\n");
    File::put($root.'/billing/tests/LedgerSpec.php', <<<'PHP'
        <?php

        namespace Flexipos\Module\Billing\Tests;

        use Flexipos\Module\Sales\Invoice;

        class LedgerSpec
        {
            public function borrow(Invoice $invoice): Invoice
            {
                return $invoice;
            }
        }

        PHP);

    $paths = ['sales/src', 'billing/src'];

    if ($analyseTestDirectory) {
        $paths[] = 'billing/tests';
    }

    File::put($root.'/deptrac.yaml', Yaml::dump([
        'deptrac' => [
            'paths' => $paths,
            'layers' => [
                ['name' => 'Module_Sales', 'collectors' => [['type' => 'classLike', 'value' => '^Flexipos\\\\Module\\\\Sales\\\\.*']]],
                ['name' => 'Module_Billing', 'collectors' => [['type' => 'classLike', 'value' => '^Flexipos\\\\Module\\\\Billing\\\\.*']]],
            ],
            'ruleset' => ['Module_Sales' => null, 'Module_Billing' => null],
        ],
    ], 8));

    return $root.'/deptrac.yaml';
}

function qaRunDeptrac(string $configFile): ProcessResult
{
    return Process::path(base_path())->run([
        PHP_BINARY,
        base_path('vendor/bin/deptrac'),
        'analyse',
        '--config-file='.$configFile,
        '--cache-file='.base_path('storage/framework/cache/.deptrac-qa.cache'),
        '--no-progress',
        '--no-ansi',
    ]);
}

afterEach(function () {
    File::delete(qaStanProbePath());
    File::deleteDirectory(qaBoundaryFixtureRoot());
    File::delete(base_path('storage/framework/cache/.deptrac-qa.cache'));
});

it('registers every module test directory with both analysers', function () {
    // The configuration half. The two tests below are the behavioural half.
    expect(File::get(base_path('phpstan.neon.dist')))->toContain('../demo/tests');

    /** @var array<string, mixed> $deptrac */
    $deptrac = Yaml::parseFile(base_path('deptrac.yaml'));

    expect($deptrac['deptrac']['paths'])->toContain('../demo/tests');

    // Every module that ships tests must be in both lists, not just
    // demo - the same manual-registry hazard QaToolchainRegistryCoverageTest
    // guards for src/.
    foreach (glob(modulesPath().DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
        $name = basename($directory);

        if ($name === 'host' || in_array($name, qaFixturePackageNames(), true) || ! is_dir($directory.'/tests')) {
            continue;
        }

        expect(File::get(base_path('phpstan.neon.dist')))->toContain("../{$name}/tests")
            ->and($deptrac['deptrac']['paths'])->toContain("../{$name}/tests");
    }
});

it('fails PHPStan on a real level 6 defect planted inside a module test directory', function () {
    // Two defects at once: an untyped parameter, which only level 6 reports,
    // and the $this->get() form the stub used to ship, which is what adding
    // test directories to the analysis surfaced in the first place.
    File::put(qaStanProbePath(), <<<'PHP'
        <?php

        use Tests\TestCase;

        uses(TestCase::class);

        function qaProbeUntyped($value)
        {
            return $value;
        }

        it('probes the analysis of a module test directory', function () {
            $this->get('/api/v1/demo/ping')->assertOk();
        });

        PHP);

    try {
        $failing = qaRunShippedStan();
        $output = $failing->output().$failing->errorOutput();

        expect($failing->exitCode())->not->toBe(0)
            ->and($output)->toContain('QaStanProbe.php')
            // Level 6's own class of finding: missing parameter type.
            ->and($output)->toContain('qaProbeUntyped() has parameter $value with no type specified')
            // And the exact error dev fixed in the stub and the demo copy.
            ->and($output)->toContain('Call to an undefined method PHPUnit\Framework\TestCase::get()');
    } finally {
        File::delete(qaStanProbePath());
    }

    // The same file, written the way the stub now writes it, is clean - so the
    // fix is the helper function, not a silenced or weakened analysis.
    File::put(qaStanProbePath(), <<<'PHP'
        <?php

        use Tests\TestCase;

        use function Pest\Laravel\get;

        uses(TestCase::class);

        function qaProbeTyped(string $value): string
        {
            return $value;
        }

        it('probes the analysis of a module test directory', function () {
            get('/api/v1/demo/ping')->assertOk();
        });

        PHP);

    try {
        $passing = qaRunShippedStan();

        expect($passing->exitCode())->toBe(0, $passing->output().$passing->errorOutput());
    } finally {
        File::delete(qaStanProbePath());
    }

    expect(File::exists(qaStanProbePath()))->toBeFalse();
});

it('reports a boundary violation when one module test file imports another module namespace', function () {
    // AC-17 to the letter: module to module, from a tests/ directory. The
    // shipped deptrac.yaml has only one module layer today, so the two-layer
    // case is proven on an isolated fixture built the same way - by namespace,
    // not by directory - while dev's DeptracBoundaryEnforcementTest proves the
    // shipped configuration itself catches a test file reaching out of Module_Demo.
    $withTests = qaWriteBoundaryFixture(analyseTestDirectory: true);
    $violating = qaRunDeptrac($withTests);
    $output = $violating->output().$violating->errorOutput();

    expect($violating->exitCode())->not->toBe(0)
        ->and($output)->toContain('Module_Billing')
        ->and($output)->toContain('Module_Sales')
        ->and($output)->toContain('must not depend on')
        ->and($output)->toContain('LedgerSpec');

    // Negative control: the identical fixture with the tests directory left out
    // of paths - which is exactly what the repository shipped before T-002 -
    // reports nothing at all.
    File::delete(base_path('storage/framework/cache/.deptrac-qa.cache'));

    $withoutTests = qaWriteBoundaryFixture(analyseTestDirectory: false);
    $silent = qaRunDeptrac($withoutTests);

    expect($silent->exitCode())->toBe(0)
        ->and($silent->output())->toContain('Violations           0');
});

it('keeps the generated test stub and the committed demo copy identical', function () {
    // The stub ships into every module generated from now on, so a fix applied
    // to only one of the two would leave every future package with the level 6
    // error dev just removed.
    $replacements = [
        '{{name}}' => 'demo',
        '{{studly}}' => 'Demo',
        '{{screaming}}' => 'DEMO',
        '{{connection}}' => 'svc_demo',
        '{{schema}}' => 'pos_demo',
        '{{prefix}}' => 'api/v1/demo',
    ];

    $rendered = strtr(
        str_replace("\r\n", "\n", File::get(base_path('stubs/module/PingRouteTest.php.stub'))),
        $replacements
    );

    $committed = str_replace("\r\n", "\n", File::get(modulesPath('demo').'/tests/Feature/PingRouteTest.php'));

    expect($rendered)->toBe($committed)
        ->and($committed)->toContain('use function Pest\Laravel\get;')
        // No $this->get() call statement survives (the docblock explaining why
        // it was replaced still names it, hence the line-anchored match).
        ->and(preg_match('/^\s*\$this->(get|post|put|patch|delete)\(/m', $committed))->toBe(0)
        ->and($rendered)->not->toContain('{{');
});
