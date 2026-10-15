<?php

declare(strict_types=1);

namespace Flexipos\Auth\Keys;

use RuntimeException;

/**
 * The shipped resolver: a reference is a scheme and a name, and the only
 * scheme implemented is `env:`.
 *
 * `env:AUTH_SIGNING_KEY_2026_10` means "the PEM is in that environment
 * variable". That is the whole of it, deliberately:
 *
 * - **One scheme, and it is tested.** A `file:` scheme and a secret-manager
 *   scheme are both plausible and both belong to the key-rotation task that
 *   will actually mint production keys (nothing in T-009 provisions one). An
 *   untested second branch here would be a path no test reaches shipped
 *   alongside the one that matters, and a `file:` scheme would also be the
 *   first thing in this package capable of leaving key material on a disk.
 * - **An unknown scheme is refused by name**, so adding one is a visible
 *   change rather than a silent "the key was empty".
 * - **Nothing here logs, dumps or wraps the material.** The exception messages
 *   name the *reference*, never the value, and a reference is by construction
 *   not a secret.
 */
final class ReferencedPrivateKeyResolver implements PrivateKeyResolver
{
    /**
     * The one scheme this resolver dereferences.
     */
    public const SCHEME = 'env:';

    public function resolve(string $reference): string
    {
        if (! str_starts_with($reference, self::SCHEME)) {
            throw new RuntimeException(sprintf(
                'private_key_ref %s names no scheme this resolver implements; the only one is "%s".',
                $reference,
                self::SCHEME
            ));
        }

        $name = substr($reference, strlen(self::SCHEME));

        $material = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        if (! is_string($material) || trim($material) === '') {
            throw new RuntimeException(sprintf(
                'private_key_ref %s resolves to nothing; the signing key cannot be read.',
                $reference
            ));
        }

        return $material;
    }
}
