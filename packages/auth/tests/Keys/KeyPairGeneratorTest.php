<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Flexipos\Auth\Keys\KeyPairGenerator;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5: the one place a key pair is generated, and why it needs one
|--------------------------------------------------------------------------
|
| **The hazard this class exists for, measured on this machine.**
| `openssl_pkey_new()` fails here with no arguments - the OpenSSL error queue
| reports `BIO routines::no such file` - because no `openssl.cnf` is on the
| CLI SAPI's default search path. D-23 has every test generate an ephemeral
| pair at run time, so that call would fail on this machine today. Ruling 1
| therefore puts generation behind this one symbol, which resolves a
| configuration in a fixed order and caches the pair for the life of the
| process.
|
| **All three branches are tested, including the one that fails here.** The
| third branch - no `config` key at all - is what a Linux runner needs, and it
| must exist even though it is the branch that fails on this machine. That is
| why the order is a pure function over an injected candidate list: the branch
| a machine cannot reach is still decidable without touching that machine's
| filesystem, and the test narrows by a *property* (does the candidate exist)
| rather than by naming this machine.
|
| **The ES256 key-size floor is pinned here, not relied on silently.**
| firebase/php-jwt 7.x derives a minimum key size from the algorithm name: for
| ES256 that is 256 bits, and a P-256 key reports exactly 256 - it passes on
| the boundary with no margin. A test that generated any smaller curve would
| fail with `DomainException "Provided key is too short"`, which reads as a
| library problem rather than as our curve choice. Both halves are asserted
| below so the next reader sees the boundary instead of discovering it.
|
| No database, no network, no engine: this file generates keys in memory and
| writes nothing anywhere.
|
*/

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The resolution order, as a pure function over three states
|--------------------------------------------------------------------------
*/

it('prefers the OPENSSL_CNF variable over every candidate beside the binary', function () {
    $fromEnvironment = __FILE__;
    $candidate = __DIR__.DIRECTORY_SEPARATOR.basename(__FILE__);

    // Both exist, so the only thing that can decide is the order.
    expect(is_file($fromEnvironment))->toBeTrue()
        ->and(is_file($candidate))->toBeTrue()
        ->and(KeyPairGenerator::resolveConfigPath($fromEnvironment, [$candidate]))->toBe($fromEnvironment);
});

it('falls through to the first candidate that exists when the variable is unset', function () {
    $missing = __DIR__.DIRECTORY_SEPARATOR.'no-such-openssl.cnf';

    expect(is_file($missing))->toBeFalse()
        ->and(KeyPairGenerator::resolveConfigPath(null, [$missing, __FILE__]))->toBe(__FILE__);
});

it('resolves to no configuration at all when nothing is found, which is the branch a Linux runner takes', function () {
    $missing = __DIR__.DIRECTORY_SEPARATOR.'no-such-openssl.cnf';

    // Not an error and not a fallback to a hard-coded path: a null here means
    // "pass no `config` key", which is the only correct answer on a machine
    // whose OpenSSL finds its own configuration.
    expect(KeyPairGenerator::resolveConfigPath(null, []))->toBeNull()
        ->and(KeyPairGenerator::resolveConfigPath('', [$missing]))->toBeNull();
});

it('refuses an OPENSSL_CNF that names no file instead of silently using another configuration', function () {
    // Absence is a third state. An unset variable means "look at the
    // candidates"; a variable naming a missing file is a misconfiguration, and
    // falling through would silently sign with a configuration the operator did
    // not choose.
    expect(fn () => KeyPairGenerator::resolveConfigPath(__DIR__.DIRECTORY_SEPARATOR.'absent.cnf', [__FILE__]))
        ->toThrow(RuntimeException::class, 'OPENSSL_CNF');
});

it('builds its candidates from the PHP binary and takes one of them on this machine', function () {
    $candidates = KeyPairGenerator::binaryTreeCandidates();

    expect($candidates)->not->toBe([]);

    foreach ($candidates as $candidate) {
        expect($candidate)->toStartWith(dirname(dirname(PHP_BINARY)));
    }

    $existing = array_values(array_filter($candidates, 'is_file'));
    $resolved = KeyPairGenerator::configPath();

    // Narrowed by a property, not by this machine's name: where a candidate
    // exists the resolution must take one, and where none does it must be null.
    if ($existing === []) {
        expect($resolved)->toBeNull();
    } else {
        expect($resolved)->toBeIn($existing);
    }
});

/*
|--------------------------------------------------------------------------
| The pair itself
|--------------------------------------------------------------------------
*/

it('generates a P-256 pair that signs and verifies through the library', function () {
    $pair = KeyPairGenerator::generate();

    expect($pair['private'])->toStartWith('-----BEGIN PRIVATE KEY-----')
        ->and($pair['public'])->toStartWith('-----BEGIN PUBLIC KEY-----');

    $token = JWT::encode(['sub' => 'u-1'], $pair['private'], KeyPairGenerator::ALGORITHM, 'kid-probe');

    expect(JWT::decode($token, new Key($pair['public'], KeyPairGenerator::ALGORITHM))->sub)->toBe('u-1');
});

it('reports exactly the 256 bits ES256 requires, with no margin', function () {
    $details = openssl_pkey_get_details(openssl_pkey_get_private(KeyPairGenerator::generate()['private']));

    expect($details)->toBeArray()
        ->and($details['bits'])->toBe(KeyPairGenerator::KEY_BITS)
        ->and($details['bits'])->toBe(256)
        ->and($details['ec']['curve_name'])->toBe(KeyPairGenerator::CURVE);
});

it('pins the library floor a smaller curve would hit, so that failure reads as the curve and not as the library', function () {
    $options = ['curve_name' => 'secp224r1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    $config = KeyPairGenerator::configPath();

    if ($config !== null) {
        $options['config'] = $config;
    }

    $smaller = openssl_pkey_new($options);

    expect($smaller)->not->toBeFalse();

    openssl_pkey_export($smaller, $pem, null, $config === null ? [] : ['config' => $config]);

    expect(openssl_pkey_get_details($smaller)['bits'])->toBe(224)
        ->and(fn () => JWT::encode(['sub' => 'u-1'], $pem, 'ES256'))
        ->toThrow(DomainException::class, 'Provided key is too short');
});

it('caches one pair per label, so a run pays one keygen and a test can still have two keys', function () {
    expect(KeyPairGenerator::generate())->toBe(KeyPairGenerator::generate())
        ->and(KeyPairGenerator::generate('intruder'))->toBe(KeyPairGenerator::generate('intruder'))
        ->and(KeyPairGenerator::generate()['private'])->not->toBe(KeyPairGenerator::generate('intruder')['private']);
});
