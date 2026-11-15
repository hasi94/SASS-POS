<?php

namespace Flexipos\Kernel\Tenancy\Exceptions;

/**
 * A `file://` reference resolved to a path outside the configured root (T-026
 * Ruling 3, D-6).
 *
 * Confinement is checked on the canonical path - `..` and symlinks resolved -
 * never on the string alone, so this covers a traversal, an absolute path
 * naming somewhere else, and a symlink planted inside the root that points
 * out of it.
 */
final class SecretPathEscapesRoot extends TenancyException
{
    public static function forReference(string $reference, string $root): self
    {
        return new self(sprintf(
            'Credential reference [%s] resolves outside the configured secrets root [%s].',
            $reference,
            $root
        ));
    }
}
