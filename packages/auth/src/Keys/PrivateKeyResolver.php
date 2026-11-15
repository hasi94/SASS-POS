<?php

declare(strict_types=1);

namespace Flexipos\Auth\Keys;

/**
 * Turns a `signing_keys.private_key_ref` into key material, at signing time.
 *
 * The seam exists because of D-23: the private half of a signing key is never
 * in the database and never in the repository, so the row carries a
 * *reference* and something outside the schema dereferences it. Which store
 * that is - an environment variable, a mounted file, a cloud secret manager -
 * is a deployment decision, and the only thing the keyring may know is that it
 * asked for a reference and was handed material.
 *
 * Implementations must never log, dump or wrap the material they return.
 */
interface PrivateKeyResolver
{
    /**
     * The PEM behind one reference.
     *
     * @throws \RuntimeException when the reference names no material, so a
     *                           missing key fails closed rather than signing
     *                           with an empty string
     */
    public function resolve(string $reference): string;
}
