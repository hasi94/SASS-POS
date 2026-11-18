<?php

declare(strict_types=1);

namespace Flexipos\Hr\Departments;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * The comparison key of a department or designation name (T-038 AC-18,
 * AC-33, D-22).
 *
 * Two names collide when they are equal after trimming and lower-casing, so
 * `Sales` and `sales ` are the same name. The key is computed in code, not
 * by a database collation, because the check runs in the application (a
 * `[ENGINE]` race, stated in the spec). It is pure: the caller decides which
 * rows are the siblings to compare against (the same parent for a
 * department, the same `department_id` group for a designation) and passes
 * their names.
 */
final class NameKey
{
    public static function of(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * True when `$name` collides with any of `$siblingNames`.
     *
     * @param  list<string>  $siblingNames  the names of the live siblings, excluding the row being changed
     */
    public static function taken(string $name, array $siblingNames): bool
    {
        $key = self::of($name);

        foreach ($siblingNames as $sibling) {
            if (self::of($sibling) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $siblingNames
     *
     * @throws HrRefused name_in_use
     */
    public static function assertFree(string $name, array $siblingNames): void
    {
        if (self::taken($name, $siblingNames)) {
            throw HrRefused::because(HrRefusal::NameInUse, 'Another live row in the same place already has this name.', 'name');
        }
    }
}
