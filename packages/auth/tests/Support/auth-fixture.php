<?php

use Flexipos\Auth\Features\Login\LoginHandler;
use Flexipos\Auth\Features\Logout\LogoutHandler;
use Flexipos\Auth\Features\RefreshToken\RefreshTokenHandler;
use Flexipos\Auth\Features\SelectTenant\SelectTenantHandler;
use Flexipos\Auth\Keys\KeyPairGenerator;
use Flexipos\Auth\Tokens\SigningKeyring;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

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

/*
|--------------------------------------------------------------------------
| T-009 step 8: the accounts, shops and memberships a login runs against
|--------------------------------------------------------------------------
|
| Every helper below seeds the master stand-in **after** it has been migrated
| and never before: migration ...000205 adds a STORED generated column to
| `users`, and SQLite refuses that ALTER once the table holds one row (step 3's
| recorded constraint). `authFixtureMigrateMaster()` first, seeds second,
| always.
|
| `email_lower` is never written by any of these: it is a generated column and
| the engine computes it. A fixture that set it would be testing the fixture.
*/

/**
 * One account, `active` by default, with a real Argon2id digest of $password.
 *
 * The digest comes from the shipped hasher rather than from a literal, so the
 * password these tests sign in with is one the application's own hasher
 * accepts; `phpunit.xml` keeps the work factors low so this costs little.
 *
 * @param  array<string, mixed>  $attributes
 * @return string the account's identifier
 */
function authFixtureUser(string $email, string $password = 'correct-horse-battery-staple', array $attributes = []): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    DB::connection('master')->table('users')->insert(array_merge([
        'id' => $id,
        'name' => 'Fixture Owner',
        'email' => $email,
        'password_hash' => Hash::make($password),
        'status' => 'active',
        'is_platform_staff' => false,
        'failed_attempts' => 0,
        'locked_until' => null,
        'must_change_password' => false,
        'last_login_at' => null,
        'last_login_ip' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ], $attributes));

    return $id;
}

/**
 * One shop, with the commercial status under test.
 *
 * `code` doubles as the name and the slug so a fixture names a tenant once;
 * `sequence` is unique and derived from the row count, because the column is
 * unique and no criterion cares what it holds.
 *
 * @param  array<string, mixed>  $attributes
 * @return string the tenant's identifier
 */
function authFixtureTenant(string $code, string $status = 'active', array $attributes = []): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    DB::connection('master')->table('tenants')->insert(array_merge([
        'id' => $id,
        'sequence' => DB::connection('master')->table('tenants')->count() + 1,
        'code' => $code,
        'slug' => $code,
        'name' => 'Shop '.$code,
        'status' => $status,
        'country' => 'LK',
        'currency' => 'LKR',
        'timezone' => 'Asia/Colombo',
        'business_type' => 'retail',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ], $attributes));

    return $id;
}

/**
 * One membership of a user in a tenant, `active` by default.
 *
 * @param  array<string, mixed>  $attributes
 * @return string the membership's identifier
 */
function authFixtureMembership(string $tenantId, string $userId, array $attributes = []): string
{
    $id = (string) Str::ulid();
    $now = app(RunnerClock::class)->timestamp();

    DB::connection('master')->table('tenant_users')->insert(array_merge([
        'id' => $id,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'employee_id' => null,
        'access_level' => 'owner',
        'status' => 'active',
        'is_default' => false,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ], $attributes));

    return $id;
}

/**
 * One account's row, as an array.
 *
 * @return array<string, mixed>
 */
function authFixtureUserRow(string $userId): array
{
    $row = DB::connection('master')->table('users')->where('id', $userId)->first();

    expect($row)->not->toBeNull();

    return (array) $row;
}

/**
 * The login handler, built by the container so that every binding under test
 * - the clock, the hasher, the keyring, the tenant-selection policy - is the
 * shipped one unless a test has deliberately replaced it.
 */
function authFixtureLoginHandler(): LoginHandler
{
    return app(LoginHandler::class);
}

/**
 * Replace the bound password hasher with one that counts what it was asked to
 * check, and hand it back.
 *
 * The container alias matters here: `Hasher::class` is an alias of
 * `hash.driver`, the default driver, while the `Hash` facade resolves `hash`,
 * the manager. Binding the instance under `Hasher::class` therefore replaces
 * exactly what a handler receives by injection and leaves the fixtures above
 * hashing with the real Argon2id driver - which is what AC-8 needs, since the
 * digests it counts checks against have to be real ones.
 */
function authFixtureCountingHasher(?string $blindTo = null): AuthFixtureCountingHasher
{
    $counting = new AuthFixtureCountingHasher(app(Hasher::class), $blindTo);

    app()->instance(Hasher::class, $counting);

    return $counting;
}

/**
 * A hasher that delegates everything and remembers every digest it was asked
 * to verify against.
 *
 * AC-8 is a statement about **how many times** the hasher runs and **against
 * what**: exactly once, against a fixed dummy digest, for an address that
 * matches nobody - the same number of times as for a real account with a
 * wrong password. Neither half is observable from the outside of the
 * handler, and wall-clock timing is explicitly not asserted (NFR), so the
 * count is taken here.
 *
 * It delegates rather than answering, because a double that returned a fixed
 * answer would also be deciding the test's outcome.
 *
 * **$blindTo is AC-8's negative control, and it is a property of the double
 * rather than a mutation of the handler.** The spec requires that "a double
 * that counts zero for the unknown case" turn the criterion red. Handed the
 * dummy digest, this one records every check except that one, so the parity
 * assertion sees zero work on the unknown-address path - which is precisely
 * what a handler that skipped the dummy verification would produce. The
 * control is therefore run against the shipped handler, with nothing in
 * packages/auth/src altered to produce it.
 */
final class AuthFixtureCountingHasher implements Hasher
{
    /**
     * Every digest `check()` was called against, in order.
     *
     * @var list<string>
     */
    public array $checked = [];

    /**
     * \@param  string|null  $blindTo  a digest this double refuses to count
     *                              - AC-8's negative control (step 9)
     */
    public function __construct(private readonly Hasher $inner, private readonly ?string $blindTo = null) {}

    public function count(): int
    {
        return count($this->checked);
    }

    /**
     * @param  string  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    /**
     * @param  string  $value
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        return $this->inner->make($value, $options);
    }

    /**
     * @param  string  $value
     * @param  string|null  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if ($this->blindTo === null || (string) $hashedValue !== $this->blindTo) {
            $this->checked[] = (string) $hashedValue;
        }

        return $this->inner->check($value, $hashedValue, $options);
    }

    /**
     * @param  string  $hashedValue
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }
}

/*
|--------------------------------------------------------------------------
| T-009 step 10: selecting a tenant, and watching what a selection touches
|--------------------------------------------------------------------------
|
| Three instruments arrive here rather than in one test file, because step 11
| needs all three: the handler built by the container, a logger that records
| what it was told, and a master query log that can be read back.
*/

/**
 * The selection handler, built by the container so that every binding under
 * test - the clock, the keyring, the tenant-selection policy - is the shipped
 * one unless a test has deliberately replaced it.
 */
function authFixtureSelectTenantHandler(): SelectTenantHandler
{
    return app(SelectTenantHandler::class);
}

/**
 * Replace the application logger with one that keeps every entry, and hand it
 * back.
 *
 * **Why a recorder and not a spy.** Two different assertions are made against
 * the application log on this task and only one of them is about a call
 * happening: that an operational signing failure is recorded at error level
 * (D-37 - the single trace T-009 leaves), and that no password and no token
 * appears anywhere in what was logged (AC-26). The second is an assertion
 * about *content*, and a mock that verifies a call cannot answer it. So the
 * entries are kept and scanned.
 *
 * **Two registrations, because one does not cover the facade.**
 * `LoggerInterface::class` is an *alias of* `log`, and `Container::instance()`
 * drops the alias it is given before binding - so registering under
 * `LoggerInterface::class` alone replaces what a constructor injection
 * receives and leaves `Log::`, `logger()` and `app('log')` resolving the real
 * `LogManager` (FP-302/BUG-1: the comment here used to claim the facade was
 * covered, and it was not). Binding `log` as well covers all four routes, and
 * `AuthFixtureRecordingLoggerTest` fails if any of them stops landing here.
 *
 * **Install it before anything that logs.** The `Log` facade caches its
 * resolved instance for the test, and `SigningKeyring` is a singleton holding
 * whatever logger it was built with - a recorder bound late is handed to
 * nobody and reports an empty log, which looks exactly like a failure that was
 * never logged.
 */
function authFixtureRecordingLogger(): AuthFixtureRecordingLogger
{
    $logger = new AuthFixtureRecordingLogger;

    app()->instance(LoggerInterface::class, $logger);
    app()->instance('log', $logger);

    Facade::clearResolvedInstance('log');

    return $logger;
}

/**
 * A logger that keeps what it was told, so a test can assert both that an
 * entry is there and that nothing forbidden is in it.
 *
 * It extends `AbstractLogger`, which turns the eight level methods into calls
 * on `log()`, so there is one place the record is made and no level can be
 * recorded differently from the others by accident.
 */
final class AuthFixtureRecordingLogger extends AbstractLogger
{
    /**
     * Every entry, in order.
     *
     * @var list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  string|Stringable  $message
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * The two `LogManager` entry points that are reached through the `log`
     * binding rather than through `LoggerInterface`.
     *
     * Binding this recorder under `log` also puts it where
     * `LogManager::class` resolves, and the framework's deprecation handler
     * resolves exactly that and calls `channel()`. Answering with the
     * recorder itself keeps every route recording instead of fatalling on an
     * undefined method; the entries still arrive through the one `log()`
     * below, so a channel cannot be recorded differently from the default.
     */
    public function channel(?string $channel = null): self
    {
        return $this;
    }

    public function driver(?string $driver = null): self
    {
        return $this;
    }

    /**
     * The messages recorded at one level.
     *
     * @return list<string>
     */
    public function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter($this->records, static fn (array $record): bool => $record['level'] === $level)
        ));
    }

    /**
     * Everything that was logged, flattened to one string - message and
     * context both, because a context value is as much a sink as a message
     * (AC-26).
     */
    public function haystack(): string
    {
        return implode("\n", array_map(
            static fn (array $record): string => $record['level'].' '.$record['message'].' '.json_encode($record['context']),
            $this->records
        ));
    }

    /**
     * Whether a sentinel appears anywhere in what was logged.
     *
     * The scan AC-26 needs, and the thing its positive control exercises: a
     * deliberately polluted entry must be reported as found, or an absence
     * proves only that the scan is blind.
     */
    public function contains(string $sentinel): bool
    {
        return str_contains($this->haystack(), $sentinel);
    }
}

/**
 * Run $work with the master query log on, and hand back every statement it
 * issued.
 *
 * AC-52's instrument. The log is cleared first and disabled afterwards, so
 * one test's statements cannot be read by the next, and the return value is
 * the raw `query` strings because that is what "no access to
 * `tenant_databases`" is a statement about.
 *
 * **It proves nothing on its own**: a log that recorded nothing satisfies
 * "contains no `tenant_databases`" perfectly. Every use owes a liveness
 * control - a statement the log *must* contain - which is why this helper
 * returns the statements rather than answering a question about them.
 *
 * @template TReturn
 *
 * @param  callable(): TReturn  $work
 * @return array{0: TReturn, 1: list<string>}
 */
function authFixtureWithMasterQueryLog(callable $work): array
{
    $connection = DB::connection('master');

    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $result = $work();
    } finally {
        $statements = array_map(
            static fn (array $entry): string => (string) $entry['query'],
            $connection->getQueryLog()
        );

        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    return [$result, array_values($statements)];
}

/**
 * The level an operational failure must be logged at (D-37).
 */
function authFixtureErrorLevel(): string
{
    return LogLevel::ERROR;
}

/*
|--------------------------------------------------------------------------
| T-009 step 11: rotating a session, and ending one
|--------------------------------------------------------------------------
*/

/**
 * The refresh handler, built by the container so every binding under test is
 * the shipped one unless a test has deliberately replaced it.
 */
function authFixtureRefreshHandler(): RefreshTokenHandler
{
    return app(RefreshTokenHandler::class);
}

/**
 * The logout handler, built the same way.
 */
function authFixtureLogoutHandler(): LogoutHandler
{
    return app(LogoutHandler::class);
}

/**
 * Every `refresh_tokens` row of one family, oldest first.
 *
 * Ordered by `created_at` and then by `id`, because a rotation inside a
 * stopped clock writes rows that share an instant and a test comparing a
 * chain needs a total order.
 *
 * @return list<array<string, mixed>>
 */
function authFixtureFamilyRows(string $familyId): array
{
    return array_map(
        static fn (object $row): array => (array) $row,
        DB::connection('master')
            ->table('refresh_tokens')
            ->where('family_id', $familyId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all()
    );
}

/**
 * The stored row a presented refresh token hashes to, as an array, or null.
 *
 * The lookup is on the hash and never on the value, which is also the thing
 * AC-32 is about: there is no column holding the plaintext to look it up by.
 *
 * @return array<string, mixed>|null
 */
function authFixtureRefreshRow(string $plaintext): ?array
{
    $row = DB::connection('master')
        ->table('refresh_tokens')
        ->where('token_hash', hash('sha256', $plaintext))
        ->first();

    return $row === null ? null : (array) $row;
}
