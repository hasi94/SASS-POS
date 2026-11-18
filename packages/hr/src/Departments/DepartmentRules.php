<?php

declare(strict_types=1);

namespace Flexipos\Hr\Departments;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Exceptions\HrRefused;

/**
 * The parent rules of the department tree (T-038 AC-19, AC-23), as pure
 * functions over an in-memory snapshot.
 *
 * The handler reads (and locks) the live departments and hands them in as
 * `id => ['parent_id' => ?string, 'is_active' => bool]`. **Only live rows are
 * in the snapshot**, so an id absent from it is `reference_not_found`: a
 * soft-deleted parent and a missing one are the same refusal.
 *
 * The checks run in AC-19's fixed order and the first failure wins:
 * `reference_not_found`, `reference_inactive`, `self_reference`, `cycle`,
 * `depth_exceeded`. Levels count the top level as level 1.
 */
final class DepartmentRules
{
    /** The deepest a tree may be, counting the top level as level 1 (AC-19). */
    public const MAX_DEPTH = 10;

    /**
     * Check a new parent for a department being created (`$departmentId`
     * null) or moved.
     *
     * @param  array<string, array{parent_id: ?string, is_active: bool}>  $live
     *
     * @throws HrRefused reference_not_found, reference_inactive, self_reference, cycle or depth_exceeded
     */
    public static function checkParent(?string $departmentId, ?string $newParentId, array $live): void
    {
        if ($newParentId !== null) {
            if (! isset($live[$newParentId])) {
                throw HrRefused::because(HrRefusal::ReferenceNotFound, 'The parent department does not exist.', 'parent_id');
            }

            if (! $live[$newParentId]['is_active']) {
                throw HrRefused::because(HrRefusal::ReferenceInactive, 'The parent department is inactive.', 'parent_id');
            }

            if ($departmentId !== null && $newParentId === $departmentId) {
                throw HrRefused::because(HrRefusal::SelfReference, 'A department cannot be its own parent.', 'parent_id');
            }

            if ($departmentId !== null && self::reaches($newParentId, $departmentId, $live)) {
                throw HrRefused::because(HrRefusal::Cycle, 'The new parent is inside this department.', 'parent_id');
            }
        }

        // The result's deepest level: the levels above the moved node, plus
        // the moved node's own subtree (a create is a subtree of one).
        $above = $newParentId === null ? 0 : self::levelOf($newParentId, $live);
        $below = $departmentId === null ? 1 : self::height($departmentId, $live);

        if ($above + $below > self::MAX_DEPTH) {
            throw HrRefused::because(
                HrRefusal::DepthExceeded,
                'The department tree may be at most '.self::MAX_DEPTH.' levels deep.',
                'parent_id',
            );
        }
    }

    /**
     * True when walking up from `$fromId` through parents arrives at
     * `$targetId` (or loops, which is refused as a cycle too).
     *
     * @param  array<string, array{parent_id: ?string, is_active: bool}>  $live
     */
    public static function reaches(string $fromId, string $targetId, array $live): bool
    {
        $seen = [];
        $cursor = $fromId;

        while ($cursor !== null) {
            if ($cursor === $targetId || isset($seen[$cursor])) {
                return true;
            }

            $seen[$cursor] = true;
            $cursor = $live[$cursor]['parent_id'] ?? null;
        }

        return false;
    }

    /**
     * The level of a department: 1 for a top-level one, counted by walking
     * up through live parents. A broken chain (a parent not in the snapshot)
     * ends the walk, which is what the tree read's "returned at the top
     * level" case needs (AC-27).
     *
     * @param  array<string, array{parent_id: ?string, is_active: bool}>  $live
     */
    public static function levelOf(string $id, array $live): int
    {
        $level = 0;
        $seen = [];
        $cursor = $id;

        while ($cursor !== null && isset($live[$cursor]) && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $level++;
            $cursor = $live[$cursor]['parent_id'];
        }

        return $level;
    }

    /**
     * How many levels the subtree rooted at `$id` spans, itself included: 1
     * for a department with no live child. After a move the **whole subtree**
     * goes with it, so the depth check is against its deepest descendant
     * (AC-19), not the moved node alone.
     *
     * @param  array<string, array{parent_id: ?string, is_active: bool}>  $live
     */
    public static function height(string $id, array $live): int
    {
        $children = [];

        foreach ($live as $childId => $row) {
            if ($row['parent_id'] !== null) {
                $children[$row['parent_id']][] = (string) $childId;
            }
        }

        $deepest = 1;
        $frontier = [$id];
        $seen = [$id => true];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $parent) {
                foreach ($children[$parent] ?? [] as $child) {
                    if (! isset($seen[$child])) {
                        $seen[$child] = true;
                        $next[] = $child;
                    }
                }
            }

            if ($next !== []) {
                $deepest++;
            }

            $frontier = $next;
        }

        return $deepest;
    }
}
