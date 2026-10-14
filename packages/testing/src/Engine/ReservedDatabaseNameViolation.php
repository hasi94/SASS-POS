<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

use RuntimeException;

/**
 * A database name that is not inside the reserved test space was handed to the
 * test-support layer.
 *
 * This is never a recoverable condition: it means code that is about to create
 * or drop a database was pointed at a name the suite does not own. The engine
 * this suite runs against holds 56 schemas, most of them other projects', and
 * there is no backup of any of them.
 */
final class ReservedDatabaseNameViolation extends RuntimeException
{
    public static function because(string $name, string $reason): self
    {
        return new self(sprintf(
            'Refusing to touch the database %s: %s. The test suite may only create or remove a database '
            .'whose name starts with "%s", is entirely lowercase, contains only [a-z0-9_] and is at '
            .'most %d characters. This is an allow-list; there is no deny-list.',
            $name === '' ? '(empty name)' : '"'.$name.'"',
            $reason,
            ReservedDatabaseName::PREFIX,
            ReservedDatabaseName::MAX_LENGTH
        ));
    }
}
