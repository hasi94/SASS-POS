<?php

use Flexipos\Kernel\Tenancy\Exceptions\SecretPathEscapesRoot;
use Flexipos\Kernel\Tenancy\Exceptions\SecretWriteRefused;
use Flexipos\Kernel\Tenancy\Exceptions\UnsupportedCredentialReference;
use Flexipos\Kernel\Tenancy\Secrets\CredentialGenerator;
use Flexipos\Kernel\Tenancy\Secrets\FileSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\FileSecretStore;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretResolver;
use Flexipos\Kernel\Tenancy\Secrets\SchemeSecretStore;
use Flexipos\Kernel\Tenancy\Secrets\Secret;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| T-026 step 3: writable secrets (D-6, D-29)
|--------------------------------------------------------------------------
|
| No TestCase binding: these are pure filesystem/value-object proofs, same
| as SecretTest.
*/

function secretsFixtureRoot(): string
{
    $root = sys_get_temp_dir().'/flexipos-t026-secrets-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function secretsCleanup(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        @unlink($root.'/'.$entry);
    }

    @rmdir($root);
}

it('round-trips a value through put, exists and resolve', function () {
    $root = secretsFixtureRoot();

    try {
        $store = new FileSecretStore($root);
        $resolver = new FileSecretResolver($root);
        $reference = 'file://'.$root.'/tenant_000001_app';

        expect($store->exists($reference))->toBeFalse();

        $store->put($reference, new Secret('s3cret-value'));

        expect($store->exists($reference))->toBeTrue()
            ->and($resolver->resolve($reference)->reveal())->toBe('s3cret-value');
    } finally {
        secretsCleanup($root);
    }
});

it('is a no-op on an equal put, and refuses a different one', function () {
    $root = secretsFixtureRoot();

    try {
        $store = new FileSecretStore($root);
        $reference = 'file://'.$root.'/tenant_000002_app';

        $store->put($reference, new Secret('first-value'));
        $store->put($reference, new Secret('first-value'));

        expect(fn () => $store->put($reference, new Secret('second-value')))
            ->toThrow(SecretWriteRefused::class);
    } finally {
        secretsCleanup($root);
    }
});

it('removes the file on forget, idempotently', function () {
    $root = secretsFixtureRoot();

    try {
        $store = new FileSecretStore($root);
        $reference = 'file://'.$root.'/tenant_000003_app';

        $store->put($reference, new Secret('value'));
        $store->forget($reference);

        expect($store->exists($reference))->toBeFalse();

        // Idempotent: forgetting again is not an error.
        $store->forget($reference);
    } finally {
        secretsCleanup($root);
    }
});

it('refuses an unknown scheme, both for read and write', function () {
    $root = secretsFixtureRoot();

    try {
        $resolver = new SchemeSecretResolver([FileSecretResolver::SCHEME => new FileSecretResolver($root)]);
        $store = new SchemeSecretStore([FileSecretResolver::SCHEME => new FileSecretStore($root)]);

        expect(fn () => $resolver->resolve('vault://something'))->toThrow(UnsupportedCredentialReference::class)
            ->and(fn () => $store->put('vault://something', new Secret('x')))
            ->toThrow(UnsupportedCredentialReference::class);
    } finally {
        secretsCleanup($root);
    }
});

it('refuses a put against env://, which stays read-only', function () {
    $store = new SchemeSecretStore([]);

    expect(fn () => $store->put('env://SOMETHING', new Secret('x')))
        ->toThrow(UnsupportedCredentialReference::class);
});

it('confines both read and write to the configured root', function () {
    $root = secretsFixtureRoot();

    try {
        $store = new FileSecretStore($root);
        $resolver = new FileSecretResolver($root);

        // A traversal out of the root.
        $traversal = 'file://'.$root.'/../escaped';
        expect(fn () => $store->put($traversal, new Secret('x')))->toThrow(SecretPathEscapesRoot::class);

        // An absolute path naming somewhere else entirely.
        $outside = sys_get_temp_dir().'/flexipos-t026-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0700, true);

        try {
            $absolute = 'file://'.$outside.'/secret';
            expect(fn () => $store->put($absolute, new Secret('x')))->toThrow(SecretPathEscapesRoot::class)
                ->and(fn () => $resolver->resolve($absolute))->toThrow(SecretPathEscapesRoot::class);
        } finally {
            @rmdir($outside);
        }
    } finally {
        secretsCleanup($root);
    }
});

it('confines a symlink that points out of the root', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('Symlink confinement is proven on non-Windows only (Ruling 3).');
    }

    $root = secretsFixtureRoot();
    $outside = sys_get_temp_dir().'/flexipos-t026-link-target-'.bin2hex(random_bytes(4));
    mkdir($outside, 0700, true);

    try {
        $link = $root.'/escaped-dir';
        symlink($outside, $link);

        $store = new FileSecretStore($root);

        expect(fn () => $store->put('file://'.$link.'/secret', new Secret('x')))
            ->toThrow(SecretPathEscapesRoot::class);
    } finally {
        @unlink($root.'/escaped-dir');
        secretsCleanup($root);
        @rmdir($outside);
    }
});

it('generates 40 characters from the alphabet, and two values differ', function () {
    $first = CredentialGenerator::generate();
    $second = CredentialGenerator::generate();

    expect($first->reveal())->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($second->reveal())->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($first->reveal())->not->toBe($second->reveal());
});
