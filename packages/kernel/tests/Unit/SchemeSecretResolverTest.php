<?php

use Flexipos\Kernel\Tenancy\Exceptions\CredentialNotAvailable;
use Flexipos\Kernel\Tenancy\Exceptions\UnsupportedCredentialReference;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Tests\TestCase;

/*
 * AC-8. A credential is always a reference dereferenced through a driver; a
 * scheme with no driver is refused outright rather than falling back to
 * anything (decision D-31).
 */

uses(TestCase::class);

function schemeResolverUnderTest(): SchemeSecretResolver
{
    return new SchemeSecretResolver([
        EnvSecretResolver::SCHEME => new EnvSecretResolver(app()),
    ]);
}

function schemeWriteEnvironment(string $variable, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$variable], $_SERVER[$variable]);
        putenv($variable);

        return;
    }

    $_ENV[$variable] = $value;
    $_SERVER[$variable] = $value;
    putenv($variable.'='.$value);
}

afterEach(function () {
    schemeWriteEnvironment('SCHEME_RESOLVER_PROBE', null);
});

it('splits a reference into a scheme and a target', function () {
    expect(SchemeSecretResolver::schemeOf('env://NAME'))->toBe('env')
        ->and(SchemeSecretResolver::schemeOf('VAULT://kv/app'))->toBe('vault')
        ->and(SchemeSecretResolver::schemeOf('no-scheme-at-all'))->toBeNull()
        ->and(SchemeSecretResolver::targetOf('file:///run/secrets/tenant'))->toBe('/run/secrets/tenant')
        ->and(SchemeSecretResolver::targetOf('no-scheme-at-all'))->toBe('');
});

it('resolves an env reference to the raw environment value', function () {
    schemeWriteEnvironment('SCHEME_RESOLVER_PROBE', 's3cret-DO-NOT-LEAK-7f3a');

    $secret = schemeResolverUnderTest()->resolve('env://SCHEME_RESOLVER_PROBE');

    expect($secret)->toBeInstanceOf(Secret::class)
        ->and($secret->reveal())->toBe('s3cret-DO-NOT-LEAK-7f3a');
});

it('does not coerce a password that happens to look like a boolean or null', function () {
    // env() would turn each of these into a bool/null; Env::getRepository()->get()
    // returns the raw string, which is what a password needs.
    foreach (['true', 'false', 'null', '(empty)'] as $value) {
        schemeWriteEnvironment('SCHEME_RESOLVER_PROBE', $value);

        expect(schemeResolverUnderTest()->resolve('env://SCHEME_RESOLVER_PROBE')->reveal())->toBe($value);
    }
});

it('refuses every scheme no driver is registered for, naming the reference', function (string $reference) {
    expect(fn (): Secret => schemeResolverUnderTest()->resolve($reference))
        ->toThrow(UnsupportedCredentialReference::class, $reference);
})->with([
    'vault' => 'vault://kv/flexipos/tenant/000123/app',
    'awssm' => 'awssm://flexipos/tenant/000123/app',
    'azkv' => 'azkv://flexipos-tenant-000123-app',
    'file' => 'file:///run/secrets/tenant_000123_app',
    'enc' => 'enc://01J0000000000000000000000A',
]);

it('refuses a reference with no scheme at all', function () {
    expect(fn (): Secret => schemeResolverUnderTest()->resolve('TENANT_000123_APP_PASSWORD'))
        ->toThrow(UnsupportedCredentialReference::class, 'has no scheme');
});

it('refuses an env reference whose variable is missing or empty', function () {
    schemeWriteEnvironment('SCHEME_RESOLVER_PROBE', null);

    expect(fn (): Secret => schemeResolverUnderTest()->resolve('env://SCHEME_RESOLVER_PROBE'))
        ->toThrow(CredentialNotAvailable::class, 'SCHEME_RESOLVER_PROBE');

    schemeWriteEnvironment('SCHEME_RESOLVER_PROBE', '');

    expect(fn (): Secret => schemeResolverUnderTest()->resolve('env://SCHEME_RESOLVER_PROBE'))
        ->toThrow(CredentialNotAvailable::class, 'is not set, or is empty');
});

it('refuses an env reference that names no variable', function () {
    expect(fn (): Secret => schemeResolverUnderTest()->resolve('env://'))
        ->toThrow(CredentialNotAvailable::class, 'names no environment variable');
});

it('refuses the env scheme outright in production, whatever the variable holds', function () {
    schemeWriteEnvironment('SCHEME_RESOLVER_PROBE', 's3cret-DO-NOT-LEAK-7f3a');

    app()->instance('env', 'production');

    try {
        expect(app()->environment('production'))->toBeTrue();

        expect(fn (): Secret => schemeResolverUnderTest()->resolve('env://SCHEME_RESOLVER_PROBE'))
            ->toThrow(CredentialNotAvailable::class, 'local development only');
    } finally {
        app()->instance('env', 'testing');
    }
});
