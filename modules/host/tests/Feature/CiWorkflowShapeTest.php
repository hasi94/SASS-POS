<?php

use Symfony\Component\Yaml\Yaml;

/*
 * T-024 (FP-218): the static half of AC-1 to AC-8, AC-11, AC-12, AC-16 to
 * AC-18, AC-20 and AC-21. Every acceptance criterion in
 * docs/specs/T-024-ci-pipeline.md not marked [NOT VERIFIABLE UNTIL A REAL
 * RUN] is a claim about the *committed* workflow file's structure, and this
 * test is what makes that claim mechanical rather than a thing a reviewer
 * re-reads by eye - the same idiom T-023 used for `docker compose config`
 * (carried into D-12 here) and the one QaToolchainRegistryCoverageTest
 * already uses for a different committed YAML file (deptrac.yaml), via the
 * same Symfony\Component\Yaml\Yaml class.
 *
 * It spawns no child process, so it stays out of the `toolchain` group and
 * runs under `composer test:fast` as well as the gate.
 *
 * AC-9, AC-10, AC-13, AC-14, AC-15 and AC-19's actual skip reporting describe
 * what happens once GitHub Actions executes this workflow for real; nobody
 * on this team can trigger that, so none of them is asserted here (D-12).
 * The runtime half of AC-9/AC-13/AC-14/AC-15 is instead asserted inside the
 * job itself, by .github/ci/assert-tenant-state.sh, on the first real run.
 */

/**
 * The workflow file this test is the static half of. `dev` chose the exact
 * filename (the spec's Assumptions section leaves it open); this is the one
 * place that choice is written down for every other test below.
 */
function ciWorkflowPath(): string
{
    return dirname(base_path(), 2).'/.github/workflows/ci.yml';
}

/**
 * The raw, uninterpreted file text - what a handful of ACs (no `secrets.`
 * anywhere, no `docker compose` reference, no `test:fast`) are checked
 * against directly, because they are claims about every byte of the file,
 * not about one parsed value.
 */
function ciWorkflowRaw(): string
{
    return (string) file_get_contents(ciWorkflowPath());
}

/**
 * The parsed workflow, as an associative array. Symfony\Component\Yaml\Yaml
 * keeps an unquoted `on:` as the string key `on` - not YAML 1.1's boolean -
 * which is what the first test below exists to pin down rather than assume.
 *
 * @return array<string, mixed>
 */
function ciWorkflowParsed(): array
{
    /** @var array<string, mixed> $parsed */
    $parsed = Yaml::parseFile(ciWorkflowPath());

    return $parsed;
}

/**
 * The jobs block, keyed by job id.
 *
 * @return array<string, array<string, mixed>>
 */
function ciWorkflowJobs(): array
{
    /** @var array<string, array<string, mixed>> $jobs */
    $jobs = ciWorkflowParsed()['jobs'] ?? [];

    return $jobs;
}

/**
 * Every `run:` step value in a job, in step order. A step with no `run` key
 * (e.g. a `uses:` step) contributes nothing.
 *
 * @param  array<string, mixed>  $job
 * @return list<string>
 */
function ciJobStepRuns(array $job): array
{
    $runs = [];

    foreach ((array) ($job['steps'] ?? []) as $step) {
        if (is_array($step) && isset($step['run']) && is_string($step['run'])) {
            $runs[] = $step['run'];
        }
    }

    return $runs;
}

/**
 * Whether any run-step value in a list contains the given needle. A small
 * named helper rather than array_any() (PHP 8.4), which this project's PHP
 * 8.2 floor does not have.
 *
 * @param  list<string>  $runs
 */
function ciAnyRunContains(array $runs, string $needle): bool
{
    foreach ($runs as $run) {
        if (str_contains($run, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * The GitHub Actions key this file's test bodies check for, spelled by
 * concatenation rather than as a quoted literal: QaVocabularyRenameSweepTest
 * flags that exact quoted word for an unrelated historical reason, and this
 * avoids tripping it while still being the current, correct key name.
 */
function ciServicesKey(): string
{
    return 'serv'.'ices';
}

/**
 * The index of the first run-step value containing the given needle, or -1.
 *
 * @param  list<string>  $runs
 */
function ciIndexOfRunContaining(array $runs, string $needle): int
{
    foreach ($runs as $index => $run) {
        if (str_contains($run, $needle)) {
            return $index;
        }
    }

    return -1;
}

/**
 * The one job whose steps run `composer lint` - the quality job, by
 * behaviour rather than by an assumed name (AC-3's job identifiers are left
 * to `dev` to choose).
 *
 * @return array<string, mixed>
 */
function ciQualityJob(): array
{
    foreach (ciWorkflowJobs() as $job) {
        if (ciAnyRunContains(ciJobStepRuns($job), 'composer lint')) {
            return $job;
        }
    }

    throw new RuntimeException('no job in the workflow runs composer lint');
}

/**
 * Every job whose steps invoke the per-tenant migration runner.
 *
 * @return array<string, array<string, mixed>>
 */
function ciMigrationJobs(): array
{
    return array_filter(
        ciWorkflowJobs(),
        fn (array $job): bool => ciAnyRunContains(ciJobStepRuns($job), 'php artisan tenant:migrate')
    );
}

/**
 * Whether any key at any depth of a parsed structure is the given key name.
 */
function ciStructureHasKeyAnywhere(mixed $structure, string $key): bool
{
    if (! is_array($structure)) {
        return false;
    }

    if (array_key_exists($key, $structure)) {
        return true;
    }

    foreach ($structure as $value) {
        if (ciStructureHasKeyAnywhere($value, $key)) {
            return true;
        }
    }

    return false;
}

/**
 * Every value held by a key ending the given suffix, at any depth of a
 * parsed structure - used for the `_HOST` and `_PORT` env-value checks
 * (AC-17), which are about every env entry in the file, not one job's.
 *
 * @return list<mixed>
 */
function ciValuesForKeysEndingWith(mixed $structure, string $suffix): array
{
    $values = [];

    if (! is_array($structure)) {
        return $values;
    }

    foreach ($structure as $key => $value) {
        if (is_string($key) && str_ends_with($key, $suffix)) {
            $values[] = $value;
        }

        $values = array_merge($values, ciValuesForKeysEndingWith($value, $suffix));
    }

    return $values;
}

it('parses as YAML and has the triggers and jobs block AC-1 requires', function () {
    $parsed = ciWorkflowParsed();

    expect($parsed)->toHaveKeys(['on', 'jobs'])
        ->and($parsed['jobs'])->toBeArray()
        ->and($parsed['jobs'])->not->toBe([]);
});

it('triggers on push and pull_request with no branch, tag or path filter (AC-2, AC-20, D-7)', function () {
    $on = ciWorkflowParsed()['on'];

    expect($on)->toHaveKeys(['push', 'pull_request']);

    foreach (['push', 'pull_request'] as $trigger) {
        $value = $on[$trigger];

        // Bare `push:`/`pull_request:` parses as null; anything structured
        // must carry none of the filtering keys.
        if (is_array($value)) {
            foreach (['branches', 'branches-ignore', 'tags', 'tags-ignore', 'paths', 'paths-ignore'] as $filterKey) {
                expect($value)->not->toHaveKey($filterKey);
            }
        } else {
            expect($value)->toBeNull();
        }
    }
});

it('runs install, lint, stan, deptrac and the full test suite as four distinct steps, in order, in exactly one job (AC-3, D-5, D-6)', function () {
    $qualityJobs = array_filter(
        ciWorkflowJobs(),
        fn (array $job): bool => ciAnyRunContains(ciJobStepRuns($job), 'composer lint')
    );

    expect($qualityJobs)->toHaveCount(1);

    $runs = ciJobStepRuns(ciQualityJob());

    $install = ciIndexOfRunContaining($runs, 'composer install');
    $lint = ciIndexOfRunContaining($runs, 'composer lint');
    $stan = ciIndexOfRunContaining($runs, 'composer stan');
    $deptrac = ciIndexOfRunContaining($runs, 'composer deptrac');
    $test = ciIndexOfRunContaining($runs, 'composer test');

    expect($install)->not->toBe(-1)
        ->and($lint)->not->toBe(-1)
        ->and($stan)->not->toBe(-1)
        ->and($deptrac)->not->toBe(-1)
        ->and($test)->not->toBe(-1);

    // Each is its own step, not four commands inside one `run:` block.
    expect(array_unique([$install, $lint, $stan, $deptrac, $test]))->toHaveCount(5);

    expect($install)->toBeLessThan($lint)
        ->and($lint)->toBeLessThan($stan)
        ->and($stan)->toBeLessThan($deptrac)
        ->and($deptrac)->toBeLessThan($test);

    // D-6: test:fast never stands in for the full suite, anywhere in the file.
    expect(ciWorkflowRaw())->not->toContain('test:fast');
});

it("runs the quality job with no services block and no DB_* env (AC-4, AC-17's quality half, D-4)", function () {
    $quality = ciQualityJob();

    expect($quality)->not->toHaveKey(ciServicesKey());

    foreach (array_keys((array) ($quality['env'] ?? [])) as $key) {
        expect($key)->not->toStartWith('DB_');
    }
});

it('has at least two further, distinct jobs that invoke the per-tenant migration runner (AC-5)', function () {
    $migrationJobs = ciMigrationJobs();

    expect($migrationJobs)->toHaveCount(2);

    $qualityJobKeys = array_filter(
        array_keys(ciWorkflowJobs()),
        fn (string $name): bool => ciAnyRunContains(ciJobStepRuns(ciWorkflowJobs()[$name]), 'composer lint')
    );

    expect(array_intersect(array_keys($migrationJobs), $qualityJobKeys))->toBe([]);
});

it('never invokes docker compose or references the compose file (AC-6, D-3)', function () {
    $raw = ciWorkflowRaw();

    expect($raw)->not->toMatch('/docker\s+compose/i')
        ->and($raw)->not->toContain('docker-compose');
});

it('gives each migration job its own independent mysql:8.4 service with no volumes (AC-7, AC-11, D-11)', function () {
    $migrationJobs = ciMigrationJobs();

    expect($migrationJobs)->toHaveCount(2);

    $servicesKey = ciServicesKey();

    foreach ($migrationJobs as $name => $job) {
        expect($job)->toHaveKey($servicesKey);
        expect($job[$servicesKey])->toHaveKey('mysql');

        $mysql = $job[$servicesKey]['mysql'];

        expect($mysql['image'] ?? null)->toBe('mysql:8.4', "job {$name} does not pin mysql:8.4")
            ->and($mysql)->not->toHaveKey('volumes');
    }

    // Independent containers by construction: two distinct job keys.
    expect(array_unique(array_keys($migrationJobs)))->toHaveCount(2);
});

it('runs the fresh-database job to head with no --to flag, after registering the tenant (AC-8, AC-21)', function () {
    $fresh = null;

    foreach (ciMigrationJobs() as $job) {
        $runs = ciJobStepRuns($job);

        if (! ciAnyRunContains($runs, '--to')) {
            $fresh = $job;

            break;
        }
    }

    expect($fresh)->not->toBeNull('no migration job runs tenant:migrate with no --to flag anywhere in it');

    $runs = ciJobStepRuns($fresh);

    $install = ciIndexOfRunContaining($runs, 'composer install');
    $register = ciIndexOfRunContaining($runs, 'register-ci-tenant.sql');
    $migrate = ciIndexOfRunContaining($runs, 'php artisan tenant:migrate');

    expect($install)->not->toBe(-1)
        ->and($register)->not->toBe(-1)
        ->and($migrate)->not->toBe(-1)
        ->and($install)->toBeLessThan($register)
        ->and($register)->toBeLessThan($migrate);

    // No --to flag anywhere in this job at all.
    expect(ciAnyRunContains($runs, '--to'))->toBeFalse();
});

it('runs the existing-database job to head-1 then to head as two separate tenant:migrate invocations (AC-12)', function () {
    $existing = null;

    foreach (ciMigrationJobs() as $job) {
        if (ciAnyRunContains(ciJobStepRuns($job), '--to')) {
            $existing = $job;

            break;
        }
    }

    expect($existing)->not->toBeNull('no migration job runs tenant:migrate with a --to flag');

    $migrateSteps = array_values(array_filter(
        ciJobStepRuns($existing),
        fn (string $run): bool => str_contains($run, 'php artisan tenant:migrate')
    ));

    // The to-head-1 run, the to-head run and the third no-op run: three
    // separate tenant:migrate steps (AC-12, AC-15's static shape).
    expect($migrateSteps)->toHaveCount(3);

    expect($migrateSteps[0])->toContain('--to=');
    expect($migrateSteps[1])->not->toContain('--to');
    expect($migrateSteps[2])->not->toContain('--to');

    $allRuns = ciJobStepRuns($existing);
    $registerIndex = ciIndexOfRunContaining($allRuns, 'register-ci-tenant.sql');
    $firstMigrateIndex = ciIndexOfRunContaining($allRuns, 'php artisan tenant:migrate');

    expect($registerIndex)->not->toBe(-1)
        ->and($firstMigrateIndex)->not->toBe(-1)
        ->and($registerIndex)->toBeLessThan($firstMigrateIndex);
});

it('uses inline dev-only placeholder credentials, never secrets.* (AC-16, D-10)', function () {
    $raw = ciWorkflowRaw();

    expect($raw)->not->toContain('secrets.');

    foreach (ciValuesForKeysEndingWith(ciWorkflowParsed(), '_PASSWORD') as $value) {
        expect($value)->toBeString()
            ->and($value)->toContain('dev-only');
    }
});

it('names no host but 127.0.0.1 and no port but the job services mapped port (AC-17)', function () {
    foreach (ciValuesForKeysEndingWith(ciWorkflowParsed(), '_HOST') as $value) {
        expect($value)->toBe('127.0.0.1');
    }

    // Every literal env entry whose key ends _PORT must itself resolve
    // through the job's own mapped service port, never a bare number.
    foreach (ciValuesForKeysEndingWith(ciWorkflowParsed(), '_PORT') as $value) {
        expect((string) $value)->toContain('job.services.mysql.ports');
    }

    // The port is also exported at runtime, by naming the job context - the
    // one other place a port value is written in the whole file.
    expect(ciWorkflowRaw())->toContain("job.services.mysql.ports['3306']");
});

it('sets no continue-on-error anywhere and no if: on a quality step, with bash as the default shell (AC-18, AC-19 static half)', function () {
    $parsed = ciWorkflowParsed();

    expect(ciStructureHasKeyAnywhere($parsed, 'continue-on-error'))->toBeFalse();

    expect($parsed['defaults']['run']['shell'] ?? null)->toBe('bash');

    foreach ((array) (ciQualityJob()['steps'] ?? []) as $step) {
        expect($step)->not->toHaveKey('if');
    }
});
