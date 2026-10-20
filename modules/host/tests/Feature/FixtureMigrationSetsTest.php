<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;

/*
|--------------------------------------------------------------------------
| The six FX sets, and what each of them declares
|--------------------------------------------------------------------------
|
| T-008 step 8. The FX migrations are the only way the runner can be tested
| against anything but the one real baseline, and the baseline is applied to an
| empty database exactly once - so without them nothing about upgrades, partial
| failure or a contract step depending on an expand version is observable at
| all.
|
| What is asserted here is what each fixture *declares*, because that is what
| steps 9 to 12 will act on at plan time: the phase, the offline flag and the
| destructive flag are read from the files before any tenant is touched, and a
| fixture whose declaration drifted would make a later refusal test pass for
| the wrong reason. FX-5 and FX-6 are never applied, here or anywhere.
|
| Nothing here opens a tenant connection and nothing reaches the engine, which
| is on hold.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

beforeEach(function () {
    FixtureMigrationLog::reset();
});

/**
 * One FX set, by number.
 */
function fxSet(int $number): MigrationSet
{
    return MigrationSet::named('fx'.$number, base_path('tests/Fixtures/TenantMigrations/FX'.$number));
}

/**
 * The single catalogue entry of one FX set.
 */
function fxEntry(int $number): MigrationCatalogue
{
    $catalogue = fxSet($number)->catalogue();

    // One migration per directory, because a version is a position in a set
    // and a directory holding two of them could not be composed one at a time.
    expect($catalogue)->toHaveCount(1);

    return $catalogue[0];
}

/*
|--------------------------------------------------------------------------
| What each fixture declares
|--------------------------------------------------------------------------
*/

it('declares the phase, the offline flag and the destructive flag each criterion needs', function () {
    $declared = [];

    foreach (range(1, 6) as $number) {
        $entry = fxEntry($number);

        $declared['FX-'.$number] = [
            'phase' => $entry->phase->value,
            'offline' => $entry->requiresOffline,
            'destructive' => $entry->isDestructive,
        ];
    }

    // Written out as one table rather than six cases, so a fixture that
    // changed shape is one diff a reviewer reads in full.
    expect($declared)->toBe([
        // The new table after the baseline: D-1's reversal path.
        'FX-1' => ['phase' => 'expand', 'offline' => false, 'destructive' => false],
        // Fails on one tenant; adds nothing on the tenant it fails on.
        'FX-2' => ['phase' => 'expand', 'offline' => false, 'destructive' => false],
        // The only offline one, and the declaration is read from its comment.
        'FX-3' => ['phase' => 'expand', 'offline' => true, 'destructive' => false],
        // D-10's positive control: contract, and NOT destructive. An
        // implementation that refused on `phase = contract` instead of on the
        // flag is red the moment step 9 uses this.
        'FX-4' => ['phase' => 'contract', 'offline' => false, 'destructive' => false],
        // D-10's negative control, and the flag is read from the statements
        // rather than declared.
        'FX-5' => ['phase' => 'contract', 'offline' => false, 'destructive' => true],
        // Wrong on purpose, and destructive as a consequence of being wrong.
        'FX-6' => ['phase' => 'expand', 'offline' => false, 'destructive' => true],
    ]);

    expect(fxEntry(3)->phase)->toBe(MigrationPhase::Expand);
});

/*
|--------------------------------------------------------------------------
| Five are clean under the rules; the sixth is the control
|--------------------------------------------------------------------------
*/

it('keeps every fixture but FX-6 silent under the rules the shipped tree obeys', function () {
    $reported = [];

    foreach (range(1, 6) as $number) {
        foreach (fxSet($number)->paths() as $path) {
            $violations = SourceRules::inspect((string) file_get_contents($path), MigrationTree::Tenant);

            if ($violations !== []) {
                $reported['FX-'.$number] = array_values(array_unique(array_column($violations, 'rule')));
            }
        }
    }

    // FX-1 to FX-5 are silent, which is the half that matters: they are the
    // controls proving the rules can be quiet, and a fixture that violated
    // AC-37 by reading the master - which is where AC-39's and AC-52's
    // observables live - could not also serve as one. That is why those two
    // readings are taken by a probe the test installs instead.
    //
    // FX-6 is reported, by AC-33 and nothing else: an expand step that calls a
    // drop. It is the positive control that proves this scan can see what it
    // claims to see.
    expect($reported)->toBe(['FX-6' => ['AC-33']]);
});

/*
|--------------------------------------------------------------------------
| The seam: a fixture supplied after the baseline
|--------------------------------------------------------------------------
*/

it('supplies FX-1 as the version after the baseline, without moving the baseline', function () {
    $tree = base_path((string) config('schema.tenant_migration_path'));

    $baseline = MigrationSet::baseline($tree);
    $withFx1 = $baseline->with('fx1', base_path('tests/Fixtures/TenantMigrations/FX1'));

    // The baseline's highest version is its FILE count, which stopped being
    // its table count when T-010 shipped nine files and eight tables
    // (T-010 Ruling 2).
    $highest = tenantMigrationFileCount();

    expect($baseline->highestVersion())->toBe($highest)
        ->and($withFx1->highestVersion())->toBe($highest + 1)
        // The version a tenant that has only the baseline carries is unchanged
        // by the set it is being migrated *with*, which is what makes
        // "migrate from 6 to 7" a meaningful instruction at all.
        ->and($withFx1->entryFor($highest)?->migration)->toBe($baseline->entryFor($highest)?->migration)
        ->and($withFx1->entryFor($highest + 1)?->migration)->toBe('2026_10_15_000001_fx1_adds_a_projection_table');
});

/*
|--------------------------------------------------------------------------
| The log the fixtures observe through
|--------------------------------------------------------------------------
*/

it('records what the probe answers, and nothing when no probe is installed', function () {
    FixtureMigrationLog::observe('fx1.jobs_seen');

    // No probe: the observation is recorded as null rather than skipped, so a
    // test that forgot to install one sees a null instead of an empty list and
    // cannot mistake "nobody looked" for "nothing was there".
    expect(FixtureMigrationLog::valuesFor('fx1.jobs_seen'))->toBe([null]);

    $asked = [];

    FixtureMigrationLog::observeWith(function (string $event) use (&$asked): int {
        $asked[] = $event;

        return 3;
    });

    FixtureMigrationLog::observe('fx1.jobs_seen');

    expect(FixtureMigrationLog::valuesFor('fx1.jobs_seen'))->toBe([null, 3])
        ->and($asked)->toBe(['fx1.jobs_seen']);

    // reset() drops the probe as well as the entries: static state that
    // survives between cases is how one test starts passing because of
    // another.
    FixtureMigrationLog::reset();
    FixtureMigrationLog::observe('fx1.jobs_seen');

    expect(FixtureMigrationLog::valuesFor('fx1.jobs_seen'))->toBe([null]);
});

it('fails only on the database it was told to fail on', function () {
    expect(FixtureMigrationLog::shouldFailOn('flexipos_t8test_tenant_c'))->toBeFalse();

    FixtureMigrationLog::failOnDatabase('flexipos_t8test_tenant_c');

    expect(FixtureMigrationLog::shouldFailOn('flexipos_t8test_tenant_c'))->toBeTrue()
        // The control, and it is AC-44's whole content: the other two tenants
        // in the same run are not affected.
        ->and(FixtureMigrationLog::shouldFailOn('flexipos_t8test_tenant_a'))->toBeFalse()
        ->and(FixtureMigrationLog::shouldFailOn('flexipos_t8test_tenant_b'))->toBeFalse()
        // A SQLite stand-in names a file rather than a database, so the tail of
        // the path is compared too - the same FX file has to work on both.
        ->and(FixtureMigrationLog::shouldFailOn('/tmp/x/flexipos_t8test_tenant_c'))->toBeTrue();

    FixtureMigrationLog::failOnDatabase(null);

    expect(FixtureMigrationLog::shouldFailOn('flexipos_t8test_tenant_c'))->toBeFalse();
});
