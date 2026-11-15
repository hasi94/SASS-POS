<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

/**
 * One role as a reader sees it (AC-23).
 *
 * **One shape for the list and for the detail.** AC-23 asks both queries for
 * the same five facts, and two shapes for one answer is how a field comes to
 * be present in one and missing from the other - the defect a client
 * discovers and nobody's test does. `ListRoles` and `GetRole` therefore
 * build this same object.
 *
 * It carries no `deleted_at` and no timestamps: a soft-deleted role is never
 * rendered at all (it is excluded from the list and refused by the detail
 * query), so a flag saying so would describe a state this type cannot be in.
 */
final class RoleView
{
    /**
     * @param  list<string>  $permissionKeys  sorted, so two roles holding the
     *                                        same set render identically
     */
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $isSystem,
        public readonly array $permissionKeys,
    ) {}
}
