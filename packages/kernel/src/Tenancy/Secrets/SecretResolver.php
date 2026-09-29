<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\CredentialNotAvailable;
use Flexipos\Kernel\Tenancy\Exceptions\UnsupportedCredentialReference;

/**
 * Turns a credential *reference* into a credential *value*.
 *
 * The mapping never stores a password (docs/database.md section 4.4); it stores
 * a reference such as `env://TENANT_000123_APP_PASSWORD`, dereferenced here on
 * every connection build. Dereferencing every time - never caching the value -
 * is what makes an in-place credential rotation take effect with no
 * invalidation step at all.
 */
interface SecretResolver
{
    /**
     * @throws UnsupportedCredentialReference
     * @throws CredentialNotAvailable
     */
    public function resolve(string $reference): Secret;
}
