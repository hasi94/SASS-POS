<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Categories;

/**
 * `categories.path` and `categories.depth`, computed - never supplied by a
 * caller (T-027 plan Ruling 7; `docs/database.md` section 6.3).
 *
 * **Pure.** `path` is the root-to-self ids joined by `/`; `depth` is the
 * number of ancestors, so a root category has depth 0. With 26-character
 * ULID ids in `VARCHAR(500)`, the deepest legal `depth` is **17** (18
 * segments: `18 * 26 + 17 = 485` characters; one more segment is `19 * 26 +
 * 18 = 512`, over the column - plan F-8). `exceedsMax()` is the one check
 * every create and every move runs, on every row the write would affect,
 * before anything is written.
 */
final class CategoryPath
{
    public const MAX_LENGTH = 500;

    /**
     * @return array{path: string, depth: int}
     */
    public static function compute(?string $parentPath, int $parentDepth, string $id): array
    {
        if ($parentPath === null) {
            return ['path' => $id, 'depth' => 0];
        }

        return ['path' => $parentPath.'/'.$id, 'depth' => $parentDepth + 1];
    }

    public static function exceedsMax(string $path): bool
    {
        return mb_strlen($path) > self::MAX_LENGTH;
    }

    /**
     * A descendant's path, rewritten after its ancestor moved: the old
     * ancestor prefix is swapped for the new one, and whatever followed it
     * (the descendant's own ids) is carried across unchanged.
     */
    public static function rewriteDescendantPath(string $oldAncestorPath, string $newAncestorPath, string $descendantPath): string
    {
        return $newAncestorPath.substr($descendantPath, mb_strlen($oldAncestorPath));
    }

    /**
     * A descendant's depth, rewritten by the same amount the ancestor's own
     * depth changed.
     */
    public static function rewriteDescendantDepth(int $oldAncestorDepth, int $newAncestorDepth, int $descendantDepth): int
    {
        return $descendantDepth + ($newAncestorDepth - $oldAncestorDepth);
    }
}
