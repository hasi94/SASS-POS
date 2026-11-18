<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Files;

/**
 * No document is stored under the key (T-038 plan Ruling 6).
 *
 * A kind of {@see DocumentStoreFailed}, so a caller that handles every store
 * failure the same way catches it, and a caller that distinguishes "the row
 * exists but the file does not" catches this one first.
 */
final class DocumentMissing extends DocumentStoreFailed
{
    public static function forKey(): self
    {
        return new self('No document is stored under this key.');
    }
}
