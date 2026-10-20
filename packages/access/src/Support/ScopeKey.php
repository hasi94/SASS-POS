<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

/**
 * The marker that stands for "all branches" wherever a scope is written out.
 *
 * **One symbol, because three places have to agree on it.** The canonical
 * hash input writes it (Ruling 6), the resolver compares against it, and the
 * till will reproduce it from a published description. A second literal
 * `'*'` anywhere is how two of those come to disagree on a value nobody can
 * see going wrong: a hash is a hash either way.
 *
 * `*` cannot collide with a real scope: a branch id is a 26-character ULID
 * from Crockford's base-32 alphabet, which has no asterisk in it, so no
 * branch can ever be written the way "all branches" is.
 *
 * This is not the §3.3 storage sentinel. That one lives in the database, is
 * a generated column, and exists so a unique key can see two NULLs as equal;
 * this one is a rendering of null for a string that gets hashed. They would
 * be wrong to share a value and wronger to share a class.
 */
final class ScopeKey
{
    /** How "no branch - this contribution covers all of them" is written. */
    public const ALL_BRANCHES = '*';

    /**
     * Render a scope for the canonical form.
     */
    public static function of(?string $branchId): string
    {
        return $branchId ?? self::ALL_BRANCHES;
    }
}
