<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

use RuntimeException;

/**
 * Any failure of a {@see DocumentStore} (T-038 plan Ruling 6): a refused key,
 * an unwritable disk, a key that already exists.
 *
 * The message never contains a filesystem path, so an error response or a log
 * line built from it cannot disclose the storage layout.
 */
class DocumentStoreFailed extends RuntimeException
{
    public static function because(string $what): self
    {
        return new self($what);
    }
}
