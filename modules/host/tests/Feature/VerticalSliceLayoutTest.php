<?php

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The vertical slice layout, as a check instead of a paragraph
|--------------------------------------------------------------------------
|
| T-008 step 13. `docs/patterns.md` section 3 is the pattern this project is
| organised by - one folder per feature, holding its command or query, its
| handler and its test - and section 8 of that same document records it as
| **enforced nowhere**. This is the first executable form of it. Its subjects
| are the `Schema/Features/` slices of the kernel; it is written to find a
| slice anywhere, so the next module to grow one is covered on the day it does.
|
| **Three rules, and the third is the one the kernel bends.** A slice folder
| `<X>/` must hold `<X>.php` (the command or the query - the data), it must
| hold `<X>Handler.php` (the behaviour), and it must have a test. The pattern
| document draws that test *inside* the slice folder, which works for a module
| and cannot work in a package: `modules/host/phpunit.xml` collects the tests
| directory of each package, so a Test.php file under `packages/kernel/src/` is
| collected by nothing and never runs while the gate stays green. So the rule
| here is the **mirror**: `<package>/src/<path>/Features/<X>/` needs
| `<package>/tests/<path>/Features/<X>/` holding at least one `*Test.php`. A
| slice whose test went missing is a slice nobody is running, which is the
| failure this check exists to make loud.
|
| Both controls are present, as every check in this task has to be: a fixture
| slice missing each part is reported, and a complete fixture slice is not.
| Without them "no violations" is also what a detector that looks at nothing
| returns - and this one scans directories, which is the easiest kind of
| detector to point at an empty set by accident.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Directory names that are not this project's code.
 *
 * Pruned during the walk rather than filtered after it, for the reason
 * DestructiveStatementContainmentTest records: a framework finder's exclude()
 * matches only at the root it was given, so a dependency tree inside a package
 * is walked anyway - tens of thousands of files, every one of them a candidate.
 *
 * @return list<string>
 */
function sliceLayoutPrunedDirectories(): array
{
    return ['vendor', 'node_modules', 'storage', '.git', 'bin', 'obj', 'dist'];
}

/**
 * Every package root in the repository that keeps its code in `src/`.
 *
 * @return list<string>
 */
function sliceLayoutPackageRoots(): array
{
    $repository = dirname(base_path(), 2);
    $roots = [];

    foreach (['packages', 'modules'] as $group) {
        foreach (glob($repository.DIRECTORY_SEPARATOR.$group.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $root) {
            if (is_dir($root.DIRECTORY_SEPARATOR.'src')) {
                $roots[] = $root;
            }
        }
    }

    sort($roots);

    return $roots;
}

/**
 * Every slice folder under one `src/` directory, as absolute paths.
 *
 * A slice is a direct child of a directory called `Features`. Nested deeper
 * than that it is not a slice, it is a slice's own sub-folder, and the pattern
 * says nothing about those.
 *
 * @return list<string>
 */
function sliceLayoutSlicesIn(string $sourceRoot): array
{
    if (! is_dir($sourceRoot)) {
        return [];
    }

    $pruned = sliceLayoutPrunedDirectories();
    $slices = [];

    $walk = static function (string $directory) use (&$walk, $pruned, &$slices): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $pruned, true)) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($path)) {
                continue;
            }

            if ($entry === 'Features') {
                foreach (glob($path.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $slice) {
                    $slices[] = $slice;
                }

                continue;
            }

            $walk($path);
        }
    };

    $walk($sourceRoot);

    sort($slices);

    return $slices;
}

/**
 * What one slice folder is missing, in the order the pattern names the parts.
 *
 * The mirror is the slice's own path with `/src/` replaced by `/tests/`, which
 * is the rule stated once: the leaf path under the package is the same on both
 * sides, so a reader looking for a slice's test knows where it is without a
 * convention per package.
 *
 * @return list<string>
 */
function sliceLayoutViolations(string $slice): array
{
    $name = basename($slice);
    $files = array_map(
        static fn (string $path): string => basename($path),
        glob($slice.DIRECTORY_SEPARATOR.'*.php') ?: []
    );

    $missing = [];

    if (! in_array($name.'.php', $files, true)) {
        $missing[] = 'no '.$name.'.php (the command or the query)';
    }

    if (! in_array($name.'Handler.php', $files, true)) {
        $missing[] = 'no '.$name.'Handler.php';
    }

    $mirror = sliceLayoutMirrorOf($slice);

    if ((glob($mirror.DIRECTORY_SEPARATOR.'*Test.php') ?: []) === []) {
        $missing[] = 'no mirrored test folder at '.$mirror;
    }

    return $missing;
}

/**
 * Where a slice's tests belong.
 */
function sliceLayoutMirrorOf(string $slice): string
{
    $separator = DIRECTORY_SEPARATOR;

    $position = strpos($slice, $separator.'src'.$separator);

    if ($position === false) {
        return $slice;
    }

    return substr($slice, 0, $position).$separator.'tests'.$separator
        .substr($slice, $position + strlen($separator.'src'.$separator));
}

/**
 * Every slice in the repository, with what it is missing.
 *
 * @return array<string, list<string>>
 */
function sliceLayoutReport(): array
{
    $report = [];

    foreach (sliceLayoutPackageRoots() as $root) {
        foreach (sliceLayoutSlicesIn($root.DIRECTORY_SEPARATOR.'src') as $slice) {
            $violations = sliceLayoutViolations($slice);

            if ($violations !== []) {
                $relative = str_replace(dirname(base_path(), 2).DIRECTORY_SEPARATOR, '', $slice);
                $report[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $violations;
            }
        }
    }

    ksort($report);

    return $report;
}

/*
|--------------------------------------------------------------------------
| The check is looking at something
|--------------------------------------------------------------------------
*/

it('finds the slices this repository actually has', function () {
    $slices = [];

    foreach (sliceLayoutPackageRoots() as $root) {
        foreach (sliceLayoutSlicesIn($root.DIRECTORY_SEPARATOR.'src') as $slice) {
            $slices[] = basename($slice);
        }
    }

    // Named, not counted: a count passes against a walk that found a different
    // set of directories, and these three are the ones T-008 has shipped.
    expect($slices)->toContain('PublishCatalogue')
        ->toContain('PlanMigrationRun')
        ->toContain('ClaimMigrationJob');
});

/*
|--------------------------------------------------------------------------
| The rule, on the real tree
|--------------------------------------------------------------------------
*/

it('gives every slice its command, its handler and a mirrored test folder', function () {
    // A map, so a failure names the slice and what it is missing rather than
    // only a count.
    expect(sliceLayoutReport())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The controls
|--------------------------------------------------------------------------
*/

it('reports a slice missing each part, and passes a complete one', function () {
    $root = storage_path('app'.DIRECTORY_SEPARATOR.'slice-layout-fixture');

    File::deleteDirectory($root);

    try {
        $src = $root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Billing'.DIRECTORY_SEPARATOR.'Features';
        $tests = $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Billing'.DIRECTORY_SEPARATOR.'Features';

        // Complete: command, handler and a mirrored test. The negative control,
        // and it has to come first - a detector that reported everything would
        // pass all three positive controls below.
        File::ensureDirectoryExists($src.DIRECTORY_SEPARATOR.'RefundSale');
        File::put($src.DIRECTORY_SEPARATOR.'RefundSale'.DIRECTORY_SEPARATOR.'RefundSale.php', '<?php');
        File::put($src.DIRECTORY_SEPARATOR.'RefundSale'.DIRECTORY_SEPARATOR.'RefundSaleHandler.php', '<?php');
        File::ensureDirectoryExists($tests.DIRECTORY_SEPARATOR.'RefundSale');
        File::put($tests.DIRECTORY_SEPARATOR.'RefundSale'.DIRECTORY_SEPARATOR.'RefundSaleTest.php', '<?php');

        // Missing the handler - the shape of a feature with nowhere to put its
        // behaviour, which is how a slice becomes a controller again.
        File::ensureDirectoryExists($src.DIRECTORY_SEPARATOR.'VoidSale');
        File::put($src.DIRECTORY_SEPARATOR.'VoidSale'.DIRECTORY_SEPARATOR.'VoidSale.php', '<?php');
        File::ensureDirectoryExists($tests.DIRECTORY_SEPARATOR.'VoidSale');
        File::put($tests.DIRECTORY_SEPARATOR.'VoidSale'.DIRECTORY_SEPARATOR.'VoidSaleTest.php', '<?php');

        // Missing the command object.
        File::ensureDirectoryExists($src.DIRECTORY_SEPARATOR.'SplitBill');
        File::put($src.DIRECTORY_SEPARATOR.'SplitBill'.DIRECTORY_SEPARATOR.'SplitBillHandler.php', '<?php');
        File::ensureDirectoryExists($tests.DIRECTORY_SEPARATOR.'SplitBill');
        File::put($tests.DIRECTORY_SEPARATOR.'SplitBill'.DIRECTORY_SEPARATOR.'SplitBillTest.php', '<?php');

        // Missing the mirrored test folder: the slice is complete and nothing
        // in the gate runs it. This is the one the kernel exception exists for
        // and the one a reader of patterns.md section 3 would not expect.
        File::ensureDirectoryExists($src.DIRECTORY_SEPARATOR.'IssueCredit');
        File::put($src.DIRECTORY_SEPARATOR.'IssueCredit'.DIRECTORY_SEPARATOR.'IssueCredit.php', '<?php');
        File::put($src.DIRECTORY_SEPARATOR.'IssueCredit'.DIRECTORY_SEPARATOR.'IssueCreditHandler.php', '<?php');

        $found = [];

        foreach (sliceLayoutSlicesIn($root.DIRECTORY_SEPARATOR.'src') as $slice) {
            $found[basename($slice)] = sliceLayoutViolations($slice);
        }

        expect(array_keys($found))->toBe(['IssueCredit', 'RefundSale', 'SplitBill', 'VoidSale'])
            ->and($found['RefundSale'])->toBe([])
            ->and($found['VoidSale'])->toBe(['no VoidSaleHandler.php'])
            ->and($found['SplitBill'])->toBe(['no SplitBill.php (the command or the query)'])
            ->and($found['IssueCredit'])->toHaveCount(1)
            ->and($found['IssueCredit'][0])->toStartWith('no mirrored test folder at');
    } finally {
        File::deleteDirectory($root);
    }
});
