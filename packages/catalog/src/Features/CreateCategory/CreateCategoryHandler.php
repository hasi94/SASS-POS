<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateCategory;

use Flexipos\Catalog\Categories\CategoryPath;
use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a product category (T-027 AC-1 to AC-3, AC-3a, plan Ruling 7).
 *
 * `path` and `depth` are computed by `CategoryPath`, never supplied by the
 * caller. A create whose own path would pass 500 characters is refused by
 * name, naming the new row itself, before anything is written (AC-3a, F-8).
 */
final class CreateCategoryHandler
{
    public const ACTION = 'catalog_category_created';

    public const FIELDS = ['code', 'name', 'parent_id', 'image_path', 'colour', 'sort_order', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateCategory $command): CreateCategoryResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = Fields::requiredText($fields, 'code', 50);
        $name = Fields::requiredText($fields, 'name', 150);
        $parentId = Identifier::foldNullable(Fields::has($fields, 'parent_id') ? $fields['parent_id'] : null, 'parent_id');
        $imagePath = Fields::optionalText($fields, 'image_path', 255);
        $colour = Fields::optionalText($fields, 'colour', 20);
        $sortOrder = Fields::integer($fields, 'sort_order', 0);
        $isActive = Fields::flag($fields, 'is_active', true);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $code, $name, $parentId, $imagePath, $colour, $sortOrder, $isActive): CreateCategoryResult {
            if ($connection->table('categories')->whereRaw('lower(code) = ?', [strtolower($code)])->exists()) {
                throw CatalogRefused::duplicate("The code [{$code}] is already in use.");
            }

            $parentPath = null;
            $parentDepth = 0;

            if ($parentId !== null) {
                // W-1: locked, because two concurrent creates under the same
                // parent must not both read its path/depth before either commits.
                $parent = Lookup::live($connection, 'categories', $parentId, 'parent category', lock: true);
                $parentPath = (string) $parent->path;
                $parentDepth = (int) $parent->depth;
            }

            $categoryId = (string) Str::ulid();
            $computed = CategoryPath::compute($parentPath, $parentDepth, $categoryId);

            if (CategoryPath::exceedsMax($computed['path'])) {
                throw CatalogRefused::tooDeep("Creating category [{$categoryId}] would push its path past ".CategoryPath::MAX_LENGTH.' characters.');
            }

            $now = $this->clock->timestamp();

            try {
                $connection->table('categories')->insert([
                    'id' => $categoryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'parent_id' => $parentId,
                    'code' => $code,
                    'name' => $name,
                    'path' => $computed['path'],
                    'depth' => $computed['depth'],
                    'image_path' => $imagePath,
                    'colour' => $colour,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("The code [{$code}] is already in use.");
            }

            $row = $connection->table('categories')->where('id', $categoryId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'category',
                $categoryId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateCategoryResult($categoryId);
        });
    }
}
