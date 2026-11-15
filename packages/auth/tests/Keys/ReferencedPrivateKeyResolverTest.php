<?php

use Flexipos\Auth\Keys\ReferencedPrivateKeyResolver;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5: dereferencing a private_key_ref, and its two refusals
|--------------------------------------------------------------------------
|
| D-23 keeps the private half of a signing key out of the database and out of
| the repository, so `signing_keys.private_key_ref` is a reference and
| something outside the schema turns it into material. The shipped resolver
| implements one scheme, `env:`, and refuses everything else by name - a
| `file:` scheme and a secret manager belong to the task that mints production
| keys, and an untested branch shipped beside the one that matters is a branch
| nobody notices is wrong.
|
| Both refusals are asserted because each is a fail-closed path: an unknown
| scheme and a reference that resolves to nothing must both stop a signature
| rather than produce one with an empty key.
|
| No key material is written to disk here: the variable lives in this process
| and dies with it.
|
*/

uses(TestCase::class);

afterEach(function () {
    putenv('AUTH_TEST_RESOLVER_KEY');
    unset($_SERVER['AUTH_TEST_RESOLVER_KEY'], $_ENV['AUTH_TEST_RESOLVER_KEY']);
});

it('reads the material the reference names', function () {
    $_SERVER['AUTH_TEST_RESOLVER_KEY'] = "-----BEGIN PRIVATE KEY-----\nnot-a-real-key\n-----END PRIVATE KEY-----";

    expect((new ReferencedPrivateKeyResolver)->resolve('env:AUTH_TEST_RESOLVER_KEY'))
        ->toBe($_SERVER['AUTH_TEST_RESOLVER_KEY']);
});

it('refuses a scheme it does not implement, naming the one it does', function () {
    expect(fn () => (new ReferencedPrivateKeyResolver)->resolve('file:/etc/flexipos/signing.pem'))
        ->toThrow(RuntimeException::class, 'env:');
});

it('refuses a reference that resolves to nothing rather than signing with an empty key', function () {
    expect(getenv('AUTH_TEST_RESOLVER_KEY'))->toBeFalse();

    expect(fn () => (new ReferencedPrivateKeyResolver)->resolve('env:AUTH_TEST_RESOLVER_KEY'))
        ->toThrow(RuntimeException::class, 'resolves to nothing');

    // Whitespace is nothing too: a variable set to a blank line is a
    // misconfiguration, not a key.
    $_SERVER['AUTH_TEST_RESOLVER_KEY'] = "  \n ";

    expect(fn () => (new ReferencedPrivateKeyResolver)->resolve('env:AUTH_TEST_RESOLVER_KEY'))
        ->toThrow(RuntimeException::class, 'resolves to nothing');
});

it('never puts the material in the message of either refusal', function () {
    $_SERVER['AUTH_TEST_RESOLVER_KEY'] = 'SENTINEL-KEY-MATERIAL';

    $refusal = null;

    try {
        (new ReferencedPrivateKeyResolver)->resolve('secret:AUTH_TEST_RESOLVER_KEY');
    } catch (RuntimeException $thrown) {
        $refusal = $thrown;
    }

    // Asserted outside the catch, so a call that stopped throwing fails here
    // instead of silently asserting nothing.
    expect($refusal)->toBeInstanceOf(RuntimeException::class)
        ->and($refusal->getMessage())->not->toContain('SENTINEL-KEY-MATERIAL');
});
