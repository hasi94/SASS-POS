<?php

namespace Flexipos\Kernel\Tenancy\Secrets;

use Flexipos\Kernel\Tenancy\Exceptions\SecretWriteRefused;

/**
 * `file://...` - write half, confined to the same root
 * {@see FileSecretResolver} reads from (T-026 Ruling 3, D-6).
 *
 * Writes go to a temporary file in the same directory and are then renamed
 * into place, with mode 0600 - an atomic swap, so a reader never observes a
 * half-written credential. Windows ignores the mode bits; that is recorded
 * here rather than pretended away.
 */
final class FileSecretStore implements WritableSecretStore
{
    public function __construct(private readonly string $root) {}

    public function put(string $reference, Secret $value): void
    {
        $path = FileSecretPath::resolve($reference, $this->root);

        if (is_file($path)) {
            $existing = file_get_contents($path);

            if ($existing === $value->reveal()) {
                // Idempotent: a resumed store_credentials finds its own
                // write and advances (AC-17 negative).
                return;
            }

            throw SecretWriteRefused::forReference($reference);
        }

        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        file_put_contents($temporary, $value->reveal());

        // Windows ignores these bits; POSIX honours them. Recorded, not
        // pretended away (Ruling 3).
        chmod($temporary, 0600);

        rename($temporary, $path);
    }

    public function exists(string $reference): bool
    {
        return is_file(FileSecretPath::resolve($reference, $this->root));
    }

    public function forget(string $reference): void
    {
        $path = FileSecretPath::resolve($reference, $this->root);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
