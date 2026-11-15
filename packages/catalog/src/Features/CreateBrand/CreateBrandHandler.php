<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateBrand;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a product brand (T-027 AC-7, plan Ruling 10).
 *
 * **Both `code` and `name` are independently unique** (plan F-12, the
 * architect's confirmed reading of section 6.3's "code, name unique").
 */
final class CreateBrandHandler
{
    public const ACTION = 'catalog_brand_created';

    public const FIELDS = ['code', 'name', 'logo_path', 'is_active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateBrand $command): CreateBrandResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = Fields::requiredText($fields, 'code', 50);
        $name = Fields::requiredText($fields, 'name', 150);
        $logoPath = Fields::optionalText($fields, 'logo_path', 255);
        $isActive = Fields::flag($fields, 'is_active', true);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $code, $name, $logoPath, $isActive): CreateBrandResult {
            if ($connection->table('brands')->whereRaw('lower(code) = ?', [strtolower($code)])->exists()) {
                throw CatalogRefused::duplicate("The code [{$code}] is already in use.");
            }

            if ($connection->table('brands')->whereRaw('lower(name) = ?', [strtolower($name)])->exists()) {
                throw CatalogRefused::duplicate("The name [{$name}] is already in use.");
            }

            $brandId = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('brands')->insert([
                    'id' => $brandId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'code' => $code,
                    'name' => $name,
                    'logo_path' => $logoPath,
                    'is_active' => $isActive,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("The code [{$code}] or the name [{$name}] is already in use.");
            }

            $row = $connection->table('brands')->where('id', $brandId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'brand',
                $brandId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateBrandResult($brandId);
        });
    }
}
