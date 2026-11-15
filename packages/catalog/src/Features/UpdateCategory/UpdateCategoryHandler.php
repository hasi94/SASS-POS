<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateCategory;

use Flexipos\Catalog\Categories\CategoryPath;
use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a category's own fields (`code`, `name`, `image_path`, `colour`,
 * `sort_order`, `is_active`), or move it under a new parent by sending
 * `parent_id` (T-027 AC-1 to AC-4, AC-3a, plan Ruling 7).
 *
 * **A move locks the moving row and the new parent's ancestor rows**, then
 * refuses onto itself or onto one of its own descendants (AC-4), refuses a
 * missing or soft-deleted parent (AC-2), refuses when the deepest affected
 * row (the new row itself, or its deepest descendant) would pass 500
 * characters, naming that row (AC-3a, F-8), and only then rewrites every
 * descendant's `path`/`depth`, including soft-deleted ones (AC-3) - they
 * stay readable by id, so their path must stay true.
 */
final class UpdateCategoryHandler
{
    public const ACTION = 'catalog_category_updated';

    public const FIELDS = ['code', 'name', 'image_path', 'colour', 'sort_order', 'is_active', 'parent_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateCategory $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw CatalogRefused::validation('An update must name at least one field.');
        }

        $categoryId = Identifier::fold($command->categoryId, 'categoryId');

        $changes = [];

        if (Fields::has($fields, 'code')) {
            $changes['code'] = Fields::requiredText($fields, 'code', 50);
        }

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 150);
        }

        if (Fields::has($fields, 'image_path')) {
            $changes['image_path'] = Fields::optionalText($fields, 'image_path', 255);
        }

        if (Fields::has($fields, 'colour')) {
            $changes['colour'] = Fields::optionalText($fields, 'colour', 20);
        }

        if (Fields::has($fields, 'sort_order')) {
            $changes['sort_order'] = Fields::integer($fields, 'sort_order', 0);
        }

        if (Fields::has($fields, 'is_active')) {
            $changes['is_active'] = Fields::flag($fields, 'is_active', true);
        }

        $moving = Fields::has($fields, 'parent_id');
        $newParentId = $moving ? Identifier::foldNullable($fields['parent_id'], 'parent_id') : null;

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $categoryId, $changes, $moving, $newParentId): void {
            $before = $connection->table('categories')->where('id', $categoryId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The category [{$categoryId}] does not exist.");
            }

            if ($moving) {
                self::move($connection, $categoryId, (string) $before->path, (int) $before->depth, $newParentId, $changes);
            }

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                $duplicate = $connection->table('categories')
                    ->whereRaw('lower(code) = ?', [strtolower($changes['code'])])
                    ->where('id', '!=', $categoryId)
                    ->exists();

                if ($duplicate) {
                    throw CatalogRefused::duplicate("The code [{$changes['code']}] is already in use.");
                }
            }

            try {
                $connection->table('categories')->where('id', $categoryId)->update($changes + [
                    'updated_at' => $this->clock->timestamp(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("The code [{$changes['code']}] is already in use.");
            }

            $after = $connection->table('categories')->where('id', $categoryId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'category',
                $categoryId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }

    /**
     * Resolve the new path/depth, refuse an illegal move, and rewrite every
     * descendant. `$changes` is mutated in place with the moved row's own
     * `parent_id`, `path` and `depth`.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws CatalogRefused validation, notFound, cycle, tooDeep
     */
    private static function move(Connection $connection, string $categoryId, string $oldPath, int $oldDepth, ?string $newParentId, array &$changes): void
    {
        if ($newParentId === $categoryId) {
            throw CatalogRefused::cycle("Category [{$categoryId}] cannot be its own parent.");
        }

        $newParentPath = null;
        $newParentDepth = 0;

        if ($newParentId !== null) {
            $newParent = Lookup::live($connection, 'categories', $newParentId, 'parent category', lock: true);
            $newParentPath = (string) $newParent->path;
            $newParentDepth = (int) $newParent->depth;

            if ($newParentPath === $oldPath || str_starts_with($newParentPath, $oldPath.'/')) {
                throw CatalogRefused::cycle("Category [{$categoryId}] cannot move under itself or one of its own descendants.");
            }
        }

        $computed = CategoryPath::compute($newParentPath, $newParentDepth, $categoryId);
        $newPath = $computed['path'];
        $newDepth = $computed['depth'];

        $pattern = self::escapeLike($oldPath).'/%';

        $deepestDescendant = $connection->table('categories')
            ->whereRaw("path LIKE ? ESCAPE '\\'", [$pattern])
            ->orderByRaw('length(path) DESC')
            ->first(['id', 'path', 'depth']);

        $deepestPath = $deepestDescendant === null ? $newPath : CategoryPath::rewriteDescendantPath($oldPath, $newPath, (string) $deepestDescendant->path);
        $deepestId = $deepestDescendant === null ? $categoryId : (string) $deepestDescendant->id;

        if (CategoryPath::exceedsMax($deepestPath)) {
            throw CatalogRefused::tooDeep("Moving category [{$categoryId}] would push [{$deepestId}]'s path past ".CategoryPath::MAX_LENGTH.' characters.');
        }

        $descendants = $connection->table('categories')
            ->whereRaw("path LIKE ? ESCAPE '\\'", [$pattern])
            ->get(['id', 'path', 'depth']);

        foreach ($descendants as $descendant) {
            $connection->table('categories')->where('id', $descendant->id)->update([
                'path' => CategoryPath::rewriteDescendantPath($oldPath, $newPath, (string) $descendant->path),
                'depth' => CategoryPath::rewriteDescendantDepth($oldDepth, $newDepth, (int) $descendant->depth),
            ]);
        }

        $changes['parent_id'] = $newParentId;
        $changes['path'] = $newPath;
        $changes['depth'] = $newDepth;
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
