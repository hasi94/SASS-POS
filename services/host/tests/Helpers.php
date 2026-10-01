<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Host test helpers
|--------------------------------------------------------------------------
|
| Pest's BootFiles bootstrapper loads tests/Helpers.php *before* tests/Pest.php
| (see vendor/pestphp/pest/src/Bootstrappers/BootFiles.php's STRUCTURE order),
| on every command including a single-file run, so everything declared here is
| available to Pest.php itself as well as to every test.
|
| Two things live here rather than inside a test file, because more than one
| test file needs them and a bare function declared in whichever file happened
| to be collected first is exactly the trap T-004 FP-164 closes:
|
| - the `toolchain` group registry and its detector (FP-155), and
| - the shared fixture-directory teardown and fixture-name list (FP-155/FP-164).
|
*/

/**
 * Absolute path of the repository root.
 *
 * Computed from this file's own location, not from base_path(): Pest.php calls
 * toolchainGroupFiles() at bootstrap time, before the application container
 * exists, so no framework path helper is available yet.
 */
function toolchainRepositoryRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * The canonical `toolchain` group registry.
 *
 * Every test file here spawns a child process that boots a whole tool - Pint,
 * PHPStan, Deptrac, Pest itself, `svc:make`, `config:cache`, `route:cache` - and
 * exists to prove that toolchain or generator mechanism works (decision D-37).
 * Measured on 2026-09-29: these files account for 60 % of test-case time across
 * 17 % of the cases, and a filtered child run still performs full collection
 * before filtering, so their cost grows with every test file added anywhere.
 *
 * This list is declared in exactly one place and used twice: tests/Pest.php
 * turns it into the group tag, and ToolchainGroupCoverageTest asserts it still
 * equals the set of files that genuinely spawn. Membership is decided by
 * purpose, never merely by "uses Process::" - see toolchainGroupExemptions().
 *
 * Paths are relative to the repository root, with forward slashes.
 *
 * @return list<string>
 */
function toolchainGroupFiles(): array
{
    return [
        'services/host/tests/Feature/ConfigCacheGuardTest.php',
        'services/host/tests/Feature/DeptracBoundaryEnforcementTest.php',
        'services/host/tests/Feature/HostTestSuiteCollectionTest.php',
        'services/host/tests/Feature/PintLintFixRoundTripTest.php',
        'services/host/tests/Feature/QaCacheGuardBranchesTest.php',
        'services/host/tests/Feature/QaGeneratedServiceTestDiscoveryTest.php',
        'services/host/tests/Feature/QaHostSuiteCollectionProofTest.php',
        'services/host/tests/Feature/QaManifestTrustAttackTest.php',
        'services/host/tests/Feature/QaModuleConnectionRemovalTest.php',
        'services/host/tests/Feature/QaRegenerationGuardTest.php',
        'services/host/tests/Feature/QaTestCodeAnalysisCoverageTest.php',
        'services/host/tests/Feature/QaTestSuiteIsolationTest.php',
        'services/host/tests/Feature/RouteCachingTest.php',
        'services/host/tests/Feature/SvcMakeAtomicGenerationTest.php',
        // This one polices the list it is on: it spawns Pest children to prove the
        // group declaration binds, so it belongs to the group it describes. Its
        // sibling ToolchainGroupCoverageTest.php spawns nothing and is therefore
        // deliberately absent, so the registry lint runs in test:fast too.
        'services/host/tests/Feature/ToolchainGroupBindingTest.php',
    ];
}

/**
 * Files that spawn a child process on purpose and are deliberately NOT in the
 * group, so `composer test:fast` still runs them.
 *
 * Decision D-37 and spec AC-5: membership is decided by what a test proves, not
 * by which API it reaches for. QaCredentialSurfaceSweepTest spawns a child to
 * inspect the dump() handler the real application installs - it is a
 * credential-surface proof, and T-003's BUG-1 was found on exactly that
 * surface. The one thing the fast command must never skip is the proof that a
 * tenant password does not leak, so it is exempt by name.
 *
 * Every entry is asserted to exist on disk, so a stale exemption cannot quietly
 * hide a real gap in the registry.
 *
 * @return list<string>
 */
function toolchainGroupExemptions(): array
{
    return [
        'packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php',
    ];
}

/**
 * How a child process is started, as literal token => the pattern that finds it.
 *
 * The keys are literal source fragments so a test can build a synthetic source
 * string from them instead of writing one out - a test file containing a real
 * token would otherwise be reported as a spawner by the sweep below.
 *
 * Every function form is matched only as a bare call, never as a method or
 * static call: a naive /\bexec\s*\(/ also matches $pdo->exec('create table
 * ...'), which would sweep the whole tenancy fixture in as a toolchain test.
 *
 * @return array<string, non-empty-string>
 */
function toolchainSpawnTokens(): array
{
    return [
        'Process::' => '/Process::/',
        'proc_open(' => '/(?<![\w$>:])proc_open\s*\(/',
        'shell_exec(' => '/(?<![\w$>:])shell_exec\s*\(/',
        'popen(' => '/(?<![\w$>:])popen\s*\(/',
        'passthru(' => '/(?<![\w$>:])passthru\s*\(/',
        'exec(' => '/(?<![\w$>:])exec\s*\(/',
    ];
}

/**
 * Whether a piece of PHP source starts a child process.
 */
function toolchainSourceSpawns(string $source): bool
{
    foreach (toolchainSpawnTokens() as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Every *Test.php under every tests directory of a services/ or packages/
 * package whose source starts a child process, relative to the repository root.
 *
 * The same enumeration QaAssertionShapeGuardTest::qaShapeTestFiles() uses, minus
 * the throwaway packages other tests generate while they run: those come and go
 * inside a single suite run, so including them would make this list depend on
 * test order.
 *
 * @return list<string>
 */
function filesSpawningChildProcesses(): array
{
    $root = toolchainRepositoryRoot();
    $spawners = [];

    foreach (['services', 'packages'] as $group) {
        foreach (glob($root.DIRECTORY_SEPARATOR.$group.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'tests', GLOB_ONLYDIR) ?: [] as $directory) {
            if (in_array(basename(dirname($directory)), qaFixturePackageNames(), true)) {
                continue;
            }

            foreach (Finder::create()->in($directory)->files()->name('*Test.php') as $file) {
                if (! toolchainSourceSpawns((string) file_get_contents($file->getPathname()))) {
                    continue;
                }

                $spawners[] = str_replace(
                    '\\',
                    '/',
                    substr($file->getPathname(), strlen($root) + 1)
                );
            }
        }
    }

    sort($spawners);

    return $spawners;
}

/**
 * The registry the group *should* hold, given a set of spawning files: every
 * spawner that is not exempt.
 *
 * Takes the spawners as an argument rather than calling
 * filesSpawningChildProcesses() itself, so the subtraction can be tested on
 * synthetic input without touching the disk.
 *
 * @param  list<string>  $spawners
 * @return list<string>
 */
function toolchainExpectedGroupFiles(array $spawners): array
{
    $expected = array_values(array_diff($spawners, toolchainGroupExemptions()));

    sort($expected);

    return $expected;
}

/**
 * Which of the given repository-relative paths name no file on disk.
 *
 * Applied to the registry and to the exemption list: a registry entry pointing
 * at a deleted file is a silently shrinking group, and a stale exemption is a
 * hole the registry lint would otherwise not see.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function toolchainMissingPaths(array $paths): array
{
    $missing = [];

    foreach ($paths as $path) {
        if (! is_file(toolchainRepositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path))) {
            $missing[] = $path;
        }
    }

    return $missing;
}

/**
 * Try to remove a fixture directory, allowing for Windows. Never throws.
 *
 * File::deleteDirectory() opens a FilesystemIterator over the directory and then
 * rmdir()s it; on Windows the iterator's handle can outlive the unset, so the
 * files go but the directory stays. `svc:make` then refuses the name outright
 * ("... already exists. Nothing was created or modified.") and the next case in
 * the same file fails for a reason that has nothing to do with what it asserts.
 * That is a real, observed flake, not a theory: a cold gate run failed in
 * ServiceRegistryManifestTrustTest, whose eight cases create and delete
 * services/trustfixture in turn.
 *
 * Retried with a real backoff, not in a tight loop. Measured on this platform:
 * while any handle inside the directory is open, unlink() on the files SUCCEEDS
 * and rmdir() on the directory FAILS; the moment the handle closes, rmdir()
 * succeeds immediately. An immediate second and third pass therefore complete in
 * microseconds and cannot outlast whatever is still holding it - which is exactly
 * what happened: a three-attempt tight loop still lost services/trustfixture and
 * the flake recurred. gc_collect_cycles() drops a FilesystemIterator that is only
 * reachable through a reference cycle; the sleep covers everything else. The first
 * attempt never sleeps, so the normal case costs nothing.
 *
 * The reasoning started as QaCredentialSurfaceSweepTest::qaCredDeleteFixtureRoot()'s
 * second pass, generalised so the svc:make teardowns share it instead of each
 * rediscovering it - and then corrected, because a second pass is demonstrably
 * not enough for the heavier generated-package fixtures.
 *
 * Use this variant, and branch on the result, wherever a teardown has more work
 * to do after the delete - restoring a moved directory, above all. See
 * deleteFixtureDirectory() for why that distinction is load-bearing.
 *
 * @return bool whether the directory is gone
 */
function tryDeleteFixtureDirectory(string $directory): bool
{
    for ($attempt = 0; $attempt < 12; $attempt++) {
        if ($attempt > 0) {
            gc_collect_cycles();

            usleep(50_000);
        }

        File::deleteDirectory($directory);

        clearstatcache();

        if (! File::isDirectory($directory)) {
            return true;
        }
    }

    return false;
}

/**
 * Remove a fixture directory, or fail loudly.
 *
 * The default for an ordinary afterEach whose only job is the delete: a leftover
 * directory would otherwise poison a later test silently.
 *
 * Failure is a thrown RuntimeException rather than an expect() call on purpose:
 * this runs from afterEach hooks of tests whose assertion counts must not
 * change, and a leftover directory has to be loud rather than counted.
 *
 * It throws, so it must never be the first statement of a teardown that still
 * has to put something back. QA BUG-3: SvcMakeDemoRegenerationTest's finally
 * deleted the regenerated services/demo and then restored the backup, so one
 * delete failure - exactly the condition this helper exists for - skipped the
 * restore, and the afterEach safety net then saw services/demo still present,
 * declined to restore, and deleted the backup. That path could destroy the
 * committed module and its only copy together. Reach for
 * tryDeleteFixtureDirectory() on any path like that.
 */
function deleteFixtureDirectory(string $directory): void
{
    if (tryDeleteFixtureDirectory($directory)) {
        return;
    }

    throw new RuntimeException(
        'could not remove the fixture directory '.$directory.'; a later test that generates the same '.
        'name will fail for an unrelated reason. Close any process holding a handle inside it and delete it by hand.'
    );
}

/**
 * The exact names of the throwaway service packages this suite generates while
 * it runs (T-002 AC-18, decision D-23).
 *
 * Relocated verbatim from QaToolchainRegistryCoverageTest.php by T-004 FP-164,
 * with no call site changed: two files read it now - the registry-coverage
 * sweep, which must not mistake a fixture for a permanent package needing
 * registration in phpstan.neon.dist/deptrac.yaml, and
 * filesSpawningChildProcesses() above, which must not let a package that exists
 * only for the duration of one test change its result.
 *
 * This used to be `str_contains($name, 'fixture')`, which would also have
 * exempted a legitimately named permanent service - `data-fixtures`, say - from
 * every coverage check in QaToolchainRegistryCoverageTest, silently and forever.
 * An exact list cannot do that: the worst it can do is fail loudly when a new
 * test starts generating a package under a name nobody added here, which is the
 * safe direction to be wrong in.
 *
 * Every test that generates a service package must add its exact name here and
 * delete the package in afterEach. Names currently in use, by file:
 * SvcMakeValidationTest (svcmakefixture, svc-make-fixture),
 * QaServiceNameValidationTest (qa-name-fixture), QaGeneratedServiceTestDiscoveryTest
 * (qadiscoveryfixture), QaServiceRegistryContractTest (qabrokenfixture),
 * SvcMakeGeneratedServiceReachableTest (reachablefixture),
 * UnlistedServiceReturns404Test (unlistedfixture), ServiceRegistryManifestTrustTest
 * (trustfixture), SvcMakeAtomicGenerationTest (atomicfixture),
 * QaManifestTrustAttackTest (qatrustfixture), QaCacheGuardBranchesTest
 * (qacachefixture).
 *
 * @return list<string>
 */
function qaFixturePackageNames(): array
{
    return [
        'atomicfixture',
        'qa-name-fixture',
        'qaatomicfixture',
        'qabrokenfixture',
        'qacachefixture',
        'qadocfixture',
        'qadiscoveryfixture',
        'qatenancyfixture',
        'qatrustfixture',
        'reachablefixture',
        'svc-make-fixture',
        'svcmakefixture',
        'trustfixture',
        'unlistedfixture',
    ];
}
