<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateBrand;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a brand's `code`, `name`, `logo_path` or `is_active` (T-027 AC-7,
 * plan Ruling 10).
 */
final class UpdateBrandHandler
{
    public const ACTION = 'catalog_brand_updated';

    public const FIELDS = ['code', 'name', 'logo_path', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateBrand $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw CatalogRefused::validation('An update must name at least one field.');
        }

        $brandId = Identifier::fold($command->brandId, 'brandId');

        $changes = [];

        if (Fields::has($fields, 'code')) {
            $changes['code'] = Fields::requiredText($fields, 'code', 50);
        }

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 150);
        }

        if (Fields::has($fields, 'logo_path')) {
            $changes['logo_path'] = Fields::optionalText($fields, 'logo_path', 255);
        }

        if (Fields::has($fields, 'is_active')) {
            $changes['is_active'] = Fields::flag($fields, 'is_active', true);
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $brandId, $changes): void {
            $before = $connection->table('brands')->where('id', $brandId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The brand [{$brandId}] does not exist.");
            }

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                $duplicate = $connection->table('brands')
                    ->whereRaw('lower(code) = ?', [strtolower($changes['code'])])
                    ->where('id', '!=', $brandId)
                    ->exists();

                if ($duplicate) {
                    throw CatalogRefused::duplicate("The code [{$changes['code']}] is already in use.");
                }
            }

            if (isset($changes['name']) && $changes['name'] !== $before->name) {
                $duplicate = $connection->table('brands')
                    ->whereRaw('lower(name) = ?', [strtolower($changes['name'])])
                    ->where('id', '!=', $brandId)
                    ->exists();

                if ($duplicate) {
                    throw CatalogRefused::duplicate("The name [{$changes['name']}] is already in use.");
                }
            }

            try {
                $connection->table('brands')->where('id', $brandId)->update($changes + [
                    'updated_at' => $this->clock->timestamp(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate('The code or the name is already in use.');
            }

            $after = $connection->table('brands')->where('id', $brandId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'brand',
                $brandId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
