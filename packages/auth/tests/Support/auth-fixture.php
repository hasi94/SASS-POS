<?php

use Flexipos\Auth\Keys\KeyPairGenerator;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * T-009 step 5: the signing keys and the clock the token tests run against.
 *
 * A plain file, required by each test that needs it, for the reason
 * packages/kernel/tests/Support/master-mapping-fixture.php exists: a bare
 * function declared in whichever test file was collected first makes a
 * single-file run fail and a full run pass (FP-164).
 *
 * **No key material is committed, and none is written to disk.** D-23 stands:
 * every pair here is generated at run time by KeyPairGenerator, the public half
 * goes into the `signing_keys` row and the private half goes into a process
 * environment variable that the real ReferencedPrivateKeyResolver dereferences
 * - the same `env:NAME` reference a deployment would use. Nothing is written to
 * a file, so a killed process leaves no key behind; the variables die with the
 * process.
 *
 * The connection is the suite's in-memory SQLite master and every helper
 * asserts that from configuration before it migrates: reading configuration
 * cannot open a socket, and an ambient DB_MASTER_DRIVER beats phpunit.xml's
 * <env>, so without the assertion a developer's shell could point these tests
 * at the shared engine, which is on hold.
 */

/**
 * Apply the master tree to the stand-in.
 *
 * Migrate first, seed second, always: SQLite refuses
 * `ALTER TABLE ... ADD COLUMN ... STORED` once a table holds a row, which is
 * what migration ...000205 adds to `users` (step 3's recorded constraint).
 */
function authFixtureMigrateMaster(): void
{
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
}

/**
 * Stop the one clock the auth package reads, at a known instant, and rebind it
 * so every collaborator resolved afterwards sees the same stopped clock.
 *
 * The binding is the part that does the work: RunnerClock is buildable with no
 * registration, so without a singleton the container hands a fresh real clock
 * to each injection and a test that fixed the clock would have fixed it for
 * exactly one collaborator.
 */
function authFixtureClockAt(string $instant): RunnerClock
{
    $clock = RunnerClock::fixedAt($instant);

    app()->instance(RunnerClock::class, $clock);

    return $clock;
}

/**
 * The `private_key_ref` a fixture key row carries: a reference, never a key.
 */
function authFixtureKeyReference(string $kid): string
{
    return 'env:AUTH_TEST_SIGNING_KEY_'.strtoupper(str_replace('-', '_', $kid));
}

/**
 * Install one signing key: an ephemeral pair, a `signing_keys` row holding the
 * public half, and the private half behind the row's own reference.
 *
 * `$label` picks which generated pair is used. KeyPairGenerator caches one pair
 * per label for the life of the process, so the default costs one keygen per
 * run, and a test that needs a *different* pair - AC-29's "signed by a key not
 * in `signing_keys`" - asks for a second label.
 *
 * @return array{kid: string, public: string, private: string}
 */
function authFixtureInstallKey(string $kid, string $status = SigningKeyring::STATUS_ACTIVE, string $label = 'default'): array
{
    $pair = KeyPairGenerator::generate($label);

    putenv(authFixtureKeyName($kid).'='.$pair['private']);
    $_SERVER[authFixtureKeyName($kid)] = $pair['private'];

    DB::connection('master')->table('signing_keys')->insert([
        'kid' => $kid,
        'algorithm' => KeyPairGenerator::ALGORITHM,
        'public_key' => $pair['public'],
        'private_key_ref' => authFixtureKeyReference($kid),
        'status' => $status,
        'created_at' => app(RunnerClock::class)->timestamp(),
        'retired_at' => null,
    ]);

    return ['kid' => $kid, 'public' => $pair['public'], 'private' => $pair['private']];
}

/**
 * The environment variable name one fixture key's reference points at.
 */
function authFixtureKeyName(string $kid): string
{
    return substr(authFixtureKeyReference($kid), strlen('env:'));
}

/**
 * Move one key's status, the way a rotation command will.
 */
function authFixtureSetKeyStatus(string $kid, string $status): void
{
    expect(DB::connection('master')->table('signing_keys')->where('kid', $kid)->update(['status' => $status]))->toBe(1);
}

/**
 * Every row of one master table, as arrays - for the "the plaintext is in no
 * column" scan.
 *
 * @return list<array<string, mixed>>
 */
function authFixtureRows(string $table): array
{
    return array_map(
        static fn (object $row): array => (array) $row,
        DB::connection('master')->table($table)->get()->all()
    );
}
