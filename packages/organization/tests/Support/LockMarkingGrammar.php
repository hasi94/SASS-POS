<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * SQLite's grammar with the lock clause it drops written back as a comment.
 *
 * **Why it exists.** The stand-in compiles `lockForUpdate()` to nothing, so a
 * query log on it cannot tell a locked read from a plain one. The older pin
 * swaps in `MySqlGrammar` under `pretend()`, which executes nothing and so
 * cannot follow a code path that depends on a statement's result (a lost
 * insert race, say). This grammar keeps SQLite's own SQL and appends
 * `/* for update *\/` to a locked select - a comment SQLite ignores - so the
 * statements **really execute** and the log still shows which of them were
 * locking reads. The comment text is the pin, not a lock: nothing here proves
 * the engine blocks, and no test may claim it does.
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
