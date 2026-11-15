<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateModifierGroup;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Create a modifier group (T-027 AC-36, plan Ruling 9).
 *
 * `max_select` is stored `NULL` for "unlimited" (F-9: there is no sentinel
 * integer for it); when it is submitted it must be at least `min_select`.
 */
final class CreateModifierGroupHandler
{
    public const ACTION = 'catalog_modifier_group_created';

    public const FIELDS = ['name', 'min_select', 'max_select', 'is_required', 'sort_order'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateModifierGroup $command): CreateModifierGroupResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $name = Fields::requiredText($fields, 'name', 150);
        $minSelect = Fields::integer($fields, 'min_select', 0);

        if ($minSelect < 0) {
            throw CatalogRefused::validation('The min_select must be at least 0.');
        }

        $maxSelect = null;

        if (Fields::has($fields, 'max_select') && $fields['max_select'] !== null) {
            $maxSelect = Fields::integer($fields, 'max_select', 0);

            if ($maxSelect < $minSelect) {
                throw CatalogRefused::validation("The max_select [{$maxSelect}] must be at least the min_select [{$minSelect}].");
            }
        }

        $isRequired = Fields::flag($fields, 'is_required', false);
        $sortOrder = Fields::integer($fields, 'sort_order', 0);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $name, $minSelect, $maxSelect, $isRequired, $sortOrder): CreateModifierGroupResult {
            $groupId = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('modifier_groups')->insert([
                'id' => $groupId,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
                'name' => $name,
                'min_select' => $minSelect,
                'max_select' => $maxSelect,
                'is_required' => $isRequired,
                'sort_order' => $sortOrder,
            ]);

            $row = $connection->table('modifier_groups')->where('id', $groupId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'modifier_group',
                $groupId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateModifierGroupResult($groupId);
        });
    }
}
