<?php

declare(strict_types=1);

namespace Flexipos\Sales\Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * SQLite's grammar with the lock clause it drops written back as a comment.
 *
 * The stand-in compiles `lockForUpdate()` and `sharedLock()` to nothing, so a
 * query log on it cannot tell a locked read from a plain one. This grammar keeps
 * SQLite's own SQL and appends a comment to a locked select - a comment SQLite
 * ignores - so the statements really execute and the log still shows which of
 * them were locking reads. The comment is the pin, not a lock: nothing here
 * proves the engine blocks, and no test may claim it does.
 *
 * A copy of the organization and pricing packages' own (the FP-331 shape: a test
 * helper is not reachable across package boundaries).
 */
final class LockMarkingGrammar extends SQLiteGrammar
{
    public const FOR_UPDATE = '/* for update */';

    public const SHARED = '/* lock in share mode */';

    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) {
            return ' /* '.$value.' */';
        }

        return $value ? ' '.self::FOR_UPDATE : ' '.self::SHARED;
    }
}
