<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use InvalidArgumentException;

/**
 * One permission key as the module that owns it declares it (AC-14).
 *
 * **The format rule lives here, once.** AC-14 requires every declared key to
 * be lower-case dotted segments of at most 80 characters; validating at
 * construction means a malformed key cannot exist as a value at all, so the
 * sync, the registry sweep and any future module all get the same answer
 * without restating the regex. A second copy of this rule would be FP-181's
 * shape: one number, two homes.
 *
 * **Eighty characters is the column, not a style choice.** `permissions.key`
 * is `VARCHAR(80)` and is the primary key of that table, so a key this object
 * accepts and the table cannot store would be a refusal discovered at the
 * database rather than at the declaration.
 *
 * `isBranchScoped` is the attribute the resolver reads in D-8 (c): a key
 * declared `false` is conferred only by a null-scope contribution. Flipping it
 * on an existing key is refused by the sync (AC-15), because it would silently
 * change the meaning of every grant already stored.
 */
final class PermissionDeclaration
{
    /** `permissions.key` is VARCHAR(80). */
    public const MAX_KEY_LENGTH = 80;

    /**
     * Lower-case dotted segments: at least two, each starting with a letter,
     * each continuing in lower-case letters, digits or underscores.
     *
     * At least two segments because a key names a thing and an action -
     * `employees.manage`, `access.assign` - and a bare `employees` would be
     * indistinguishable from the module prefix every other key carries.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $group,
        public readonly string $description,
        public readonly bool $isBranchScoped,
    ) {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException(
                "Permission key [{$key}] is longer than ".self::MAX_KEY_LENGTH.' characters.'
            );
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(
                "Permission key [{$key}] is not lower-case dotted segments."
            );
        }

        foreach (['module' => $module, 'group' => $group, 'description' => $description] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Permission key [{$key}] declares no {$name}.");
            }
        }
    }

    /**
     * Whether two declarations of the same key agree on everything else.
     *
     * AC-14 fails a sync in which one key is declared twice with different
     * attributes. "Different" is decided here rather than in the sync, so the
     * comparison cannot drift from the fields this object carries when a
     * later task adds one.
     */
    public function agreesWith(self $other): bool
    {
        return $this->key === $other->key
            && $this->module === $other->module
            && $this->group === $other->group
            && $this->description === $other->description
            && $this->isBranchScoped === $other->isBranchScoped;
    }
}
