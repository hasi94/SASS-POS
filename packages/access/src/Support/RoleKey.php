<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * What a role's `key` may look like (AC-19).
 *
 * **The format is `dev`'s reading and it is declared as one.** AC-19 says a
 * key "that is not the role-key format" is refused and no document anywhere
 * states that format, so the narrowest shape consistent with what the
 * project already ships was taken: one lower-case identifier, the shape of
 * the only role key that exists, `owner` ({@see SystemRole::OWNER_KEY}).
 * Widening it later accepts keys that are refused today, which is the
 * reversible direction; narrowing it would orphan rows a shop had already
 * created. A different ruling is a change to this one constant.
 *
 * **Deliberately not the permission-key format.** A permission key is dotted
 * - `employees.manage` - because it names a module and a capability, and
 * {@see PermissionDeclaration::KEY_PATTERN} is
 * the authority for that one. A role is a name a shop chooses, belongs to no
 * module, and a dot in it would read like a permission key in every list the
 * two appear in together. The two rules are separate on purpose and neither
 * file restates the other.
 *
 * **Sixty characters is the column, not a style choice.** `roles.key` is
 * `VARCHAR(60)`, so a key this class accepted and the table could not store
 * would be refused by the database rather than by the request.
 */
final class RoleKey
{
    /** `roles.key` is VARCHAR(60). */
    public const MAX_LENGTH = 60;

    /**
     * One lower-case identifier: a letter, then letters, digits or
     * underscores.
     */
    public const PATTERN = '/^[a-z][a-z0-9_]*$/';

    public static function isValid(string $key): bool
    {
        return strlen($key) <= self::MAX_LENGTH && preg_match(self::PATTERN, $key) === 1;
    }
}
