<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\SecretWriteRefused;
use Flexipos\Kernel\Tenancy\Exceptions\UnsupportedCredentialReference;

/**
 * The write half of a credential reference (T-026 D-6).
 *
 * {@see SecretResolver} as shipped is read-only - `env://` cannot be written
 * at all, a process environment is not something a process can durably
 * persist for a *different*, later process to read. This is the new
 * capability `store_credentials` needs: generate a password, write it
 * through a reference, and have a later step (or a resumed one) read it
 * back through the ordinary {@see SecretResolver}.
 */
interface WritableSecretStore
{
    /**
     * A no-op when the stored value already equals `$value`; refused when it
     * is already something else (AC-17 negative) - `put()` never rotates.
     *
     * @throws SecretWriteRefused
     * @throws UnsupportedCredentialReference
     */
    public function put(string $reference, Secret $value): void;

    public function exists(string $reference): bool;

    /**
     * Idempotent: forgetting a reference that is already gone is not an
     * error.
     */
    public function forget(string $reference): void;
}
