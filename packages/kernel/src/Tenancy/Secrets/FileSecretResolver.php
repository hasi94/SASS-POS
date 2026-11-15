<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\CredentialNotAvailable;

/**
 * `file://...` - read half, confined to one configured root (T-026 D-6,
 * D-29).
 *
 * The default for this project today (docs/database.md section 4.4), and
 * - until this task - never actually implemented: only `env://` was
 * registered. The real VPS placement of the root is FP-221's (D-29); this
 * class only ever reads the configured path.
 */
final class FileSecretResolver implements SecretResolver
{
    public const SCHEME = 'file';

    public function __construct(private readonly string $root) {}

    public function resolve(string $reference): Secret
    {
        $path = FileSecretPath::resolve($reference, $this->root);

        if (! is_file($path)) {
            throw CredentialNotAvailable::forReference($reference, 'no file exists at the resolved path.');
        }

        $value = file_get_contents($path);

        if ($value === false || $value === '') {
            throw CredentialNotAvailable::forReference($reference, 'the file at the resolved path is empty or unreadable.');
        }

        return new Secret($value);
    }
}
