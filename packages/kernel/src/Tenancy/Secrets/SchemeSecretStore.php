<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\UnsupportedCredentialReference;

/**
 * The write half of {@see SchemeSecretResolver}: dispatches a `scheme://rest`
 * reference to the writable driver registered for that scheme, and refuses
 * an unknown one (T-026 Ruling 3).
 *
 * `env://` is deliberately never registered here: it stays read-only
 * (D-6/D-31), so a `put()` against it is refused the same way any other
 * unregistered scheme is - not by a special case, by having no driver.
 */
final class SchemeSecretStore implements WritableSecretStore
{
    /**
     * @param  array<string, WritableSecretStore>  $drivers
     */
    public function __construct(private readonly array $drivers = []) {}

    public function put(string $reference, Secret $value): void
    {
        $this->driverFor($reference)->put($reference, $value);
    }

    public function exists(string $reference): bool
    {
        return $this->driverFor($reference)->exists($reference);
    }

    public function forget(string $reference): void
    {
        $this->driverFor($reference)->forget($reference);
    }

    private function driverFor(string $reference): WritableSecretStore
    {
        $scheme = SchemeSecretResolver::schemeOf($reference);

        if ($scheme === null) {
            throw UnsupportedCredentialReference::withoutScheme($reference);
        }

        $driver = $this->drivers[$scheme] ?? null;

        if (! $driver instanceof WritableSecretStore) {
            throw UnsupportedCredentialReference::forScheme($reference, $scheme);
        }

        return $driver;
    }
}
