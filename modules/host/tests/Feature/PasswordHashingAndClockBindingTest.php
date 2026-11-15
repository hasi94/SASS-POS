<?php

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| T-009 step 4: the hasher, the clock and the two configuration files
|--------------------------------------------------------------------------
|
| Three things land in this step and each is asserted here against what it
| would be if the step had not happened:
|
| 1. `Hash::make()` produces an **Argon2id** digest. The framework's own
|    fallback is bcrypt, so without `config/hashing.php` this host would hash
|    every password with the wrong algorithm and nothing would notice. The
|    negative control the plan names is both halves in one case: the same
|    predicate that accepts an Argon2id digest **refuses** the stock bcrypt
|    driver's. One half alone proves nothing - a predicate that accepted
|    everything would pass the first, and a predicate that accepted nothing
|    would pass the second.
| 2. The clock is **one** shared `RunnerClock`, not a fresh one per injection.
|    The control is a class the container does not bind, resolved twice: the
|    default is a new instance every time, which is what the singleton changes.
| 3. The **shipped** values of both configuration files are read from the files
|    themselves, with the suite's own overrides removed - otherwise the only
|    thing asserted would be that phpunit.xml says what phpunit.xml says.
|
| Nothing here asserts a duration. The work factor is reduced under the suite
| for a measured reason (phpunit.xml says which), the spec's security NFR
| forbids a wall-clock assertion, and the algorithm is what is under test.
|
| No database is touched: hashing is a pure function and the clock holds no
| connection.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Whether a digest was produced by Argon2id.
 *
 * One predicate, applied to both sides of the control, so the two halves
 * cannot drift into testing different things. The prefix is PHP's own: a
 * `password_hash($p, PASSWORD_ARGON2ID)` digest begins `$argon2id$`, and
 * `$argon2i$` - one character shorter and a different algorithm - must not
 * satisfy it, which is why the `$` terminator is part of the needle.
 */
function isArgon2idDigest(string $digest): bool
{
    return str_starts_with($digest, '$argon2id$');
}

/**
 * One shipped configuration file, evaluated with the named variables absent.
 *
 * Each is removed from all three places Laravel's `Env` repository reads -
 * `$_SERVER`, `$_ENV` and the process environment - and restored in a
 * `finally`, because phpunit.xml's `<env>` writes only two of them and leaving
 * one unset leaks into every later case in the run.
 *
 * @param  list<string>  $without
 * @return array<string, mixed>
 */
function shippedConfig(string $file, array $without): array
{
    $ambient = [];

    foreach ($without as $name) {
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && ! array_key_exists($name, $ambient)) {
                $ambient[$name] = $value;
            }
        }

        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }

    try {
        /** @var array<string, mixed> $shipped */
        $shipped = require base_path('config/'.$file.'.php');

        return $shipped;
    } finally {
        foreach ($ambient as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Argon2id, with the bcrypt half of the control beside it
|--------------------------------------------------------------------------
*/

it('hashes with Argon2id, and the same assertion refuses the stock bcrypt driver', function () {
    // The platform's hasher.
    $argon = Hash::make('a-password-worth-hashing');

    expect(isArgon2idDigest($argon))->toBeTrue()
        ->and(Hash::check('a-password-worth-hashing', $argon))->toBeTrue()
        ->and(Hash::check('not-that-password', $argon))->toBeFalse();

    // The negative control's second half, which is what makes the first half
    // mean something: the stock driver this host does *not* use produces a
    // digest the same predicate refuses.
    $bcrypt = Hash::driver('bcrypt')->make('a-password-worth-hashing');

    expect(isArgon2idDigest($bcrypt))->toBeFalse()
        ->and(str_starts_with($bcrypt, '$2y$'))->toBeTrue();

    // And the predicate is not merely prefix-blind: Argon2i is a different
    // algorithm and is refused too.
    expect(isArgon2idDigest(Hash::driver('argon')->make('a-password-worth-hashing')))->toBeFalse();
});

it('is the Argon2id driver the manager resolved, and the algorithm exists on this build', function () {
    // Without this, the case above would also pass if `Hash::make()` happened
    // to produce an Argon2id digest for some other reason: the driver the
    // manager chose is the thing `config/hashing.php` exists to change.
    expect(Hash::getDefaultDriver())->toBe('argon2id')
        ->and(defined('PASSWORD_ARGON2ID'))->toBeTrue();

    // The one fact about the digest that is not the prefix: PHP reports the
    // parameters it was built with, and they are the suite's reduced triple -
    // so the phpunit.xml override is reaching the hasher rather than being
    // decorative. The *shipped* factors are asserted from the file below.
    $info = password_get_info(Hash::make('a-password-worth-hashing'));

    expect($info['options'])->toBe(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
});

it('refuses a hash that is not Argon2id rather than quietly answering false', function () {
    // `verify` is on, so presenting a bcrypt digest to the Argon2id hasher
    // throws instead of returning false. That is the behaviour
    // config/hashing.php chose and the reason is written there: a non-Argon2id
    // hash in this master is a fault, not a wrong password.
    $bcrypt = Hash::driver('bcrypt')->make('a-password-worth-hashing');

    expect(fn () => Hash::check('a-password-worth-hashing', $bcrypt))
        ->toThrow(RuntimeException::class);

    // The control: an Argon2id digest goes through the same call unharmed, so
    // the throw above is about the algorithm and not about `check()` itself.
    expect(Hash::check('a-password-worth-hashing', Hash::make('a-password-worth-hashing')))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The shipped configuration, read from the shipped files
|--------------------------------------------------------------------------
*/

it('ships Argon2id and the real work factors, which the suite overrides and nothing else does', function () {
    $shipped = shippedConfig('hashing', ['HASH_DRIVER', 'ARGON_MEMORY', 'ARGON_TIME', 'ARGON_THREADS', 'HASH_VERIFY']);

    expect($shipped['driver'])->toBe('argon2id')
        // The deployment's factors, not the suite's: 1024/1/1 is the gate's
        // own override and must not be what a deployment inherits.
        ->and($shipped['argon'])->toBe([
            'memory' => 65536,
            'threads' => 1,
            'time' => 4,
            'verify' => true,
        ])
        ->and($shipped['rehash_on_login'])->toBeFalse();

    // The control that the reader removed anything at all: with the suite's
    // variables back in place the same file yields the reduced triple.
    $underTheSuite = require base_path('config/hashing.php');

    expect($underTheSuite['argon']['memory'])->toBe(1024)
        ->and($underTheSuite['argon']['time'])->toBe(1);
});

it('ships the authentication figures as numbers, and names no status, algorithm or key', function () {
    $shipped = shippedConfig('authentication', [
        'AUTH_ACCESS_TTL_MINUTES', 'AUTH_REFRESH_TTL_DAYS', 'AUTH_LOCKOUT_THRESHOLD',
        'AUTH_LOCKOUT_MINUTES', 'AUTH_LIMIT_EMAIL', 'AUTH_LIMIT_PASSWORD',
        // T-014: the guessing limit of the public activation route.
        'AUTH_TERMINAL_ACTIVATION_MAX_FAILURES', 'AUTH_TERMINAL_ACTIVATION_WINDOW_MINUTES',
    ]);

    expect($shipped)->toBe([
        'access_ttl_minutes' => 15,
        'refresh_ttl_days' => 14,
        'lockout' => ['threshold' => 5, 'minutes' => 15],
        'terminal_activation' => ['max_failures' => 10, 'window_minutes' => 15],
        'limits' => ['email' => 191, 'password' => 1024],
    ]);

    // **Numbers only**, and this is the assertion that keeps it so. A tenant
    // status, a signing algorithm or a key name appearing here would be an
    // access decision an operator could widen from the environment - which is
    // the one thing the selectable set and the algorithm must not be (plan
    // section 1, Ruling 1). The shape is checked rather than the words,
    // because a word list is what a later edit slips past.
    $walk = static function (array $values) use (&$walk): void {
        foreach ($values as $value) {
            if (is_array($value)) {
                $walk($value);

                continue;
            }

            expect($value)->toBeInt();
        }
    };

    $walk($shipped);

    // And the same figures reach the application through the container, so the
    // file is registered and not merely present.
    expect(config('authentication.access_ttl_minutes'))->toBe(15)
        ->and(config('authentication.lockout.threshold'))->toBe(5)
        ->and(config('authentication.limits.password'))->toBe(1024);
});

/*
|--------------------------------------------------------------------------
| One clock in the process
|--------------------------------------------------------------------------
*/

it('shares one RunnerClock, where the container would otherwise build a new one each time', function () {
    expect(app(RunnerClock::class))->toBe(app(RunnerClock::class));

    // The control, and it is the whole point of the binding: the container's
    // default for a concrete class with a buildable constructor is a fresh
    // instance per resolution. `RunnerClock` is exactly that kind of class -
    // its only parameter has a default - so without the singleton the two
    // resolutions above would be different objects and a test that fixed the
    // clock would fix it for one collaborator only.
    expect(app(stdClass::class))->not->toBe(app(stdClass::class));

    // What the sharing buys: an instance fixed in a test is the instance every
    // later resolution sees.
    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-15 09:00:00.000'));

    expect(app(RunnerClock::class)->timestamp())->toBe('2026-10-15 09:00:00.000');

    app(RunnerClock::class)->advanceMinutes(15);

    expect(app(RunnerClock::class)->timestamp())->toBe('2026-10-15 09:15:00.000')
        // One `DATETIME(3)` rendering, shared with the master schema's columns.
        ->and(RunnerClock::PRECISION)->toBe('Y-m-d H:i:s.v');
});
