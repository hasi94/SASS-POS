<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\UnsupportedCredentialReference;

/**
 * Dispatches a `scheme://rest` credential reference to the driver registered
 * for that scheme.
 *
 * Every scheme with no registered driver is refused. That is the whole point:
 * docs/database.md section 4.4 lists `vault://`, `awssm://`, `azkv://`,
 * `file://`, `env://` and `enc://`, and T-003 ships `env://` only (decision
 * D-31). A reference whose store does not exist yet must fail loudly rather
 * than fall through to a default.
 */
final class SchemeSecretResolver implements SecretResolver
{
    /**
     * scheme (without `://`) => driver.
     *
     * @var array<string, SecretResolver>
     */
    private array $drivers;

    /**
     * @param  array<string, SecretResolver>  $drivers
     */
    public function __construct(array $drivers = [])
    {
        $this->drivers = $drivers;
    }

    public function resolve(string $reference): Secret
    {
        $scheme = self::schemeOf($reference);

        if ($scheme === null) {
            throw UnsupportedCredentialReference::withoutScheme($reference);
        }

        $driver = $this->drivers[$scheme] ?? null;

        if (! $driver instanceof SecretResolver) {
            throw UnsupportedCredentialReference::forScheme($reference, $scheme);
        }

        return $driver->resolve($reference);
    }

    /**
     * The scheme of a reference, lowercased, or null when it has none.
     */
    public static function schemeOf(string $reference): ?string
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):\/\//', $reference, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Everything after `scheme://`.
     */
    public static function targetOf(string $reference): string
    {
        $position = strpos($reference, '://');

        return $position === false ? '' : substr($reference, $position + 3);
    }
}
