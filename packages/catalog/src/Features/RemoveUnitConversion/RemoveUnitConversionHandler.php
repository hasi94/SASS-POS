<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveUnitConversion;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

/**
 * Remove a unit-to-unit conversion row (T-027 AC-13, AC-14, plan Ruling
 * 10). Already-written `product_components`/document snapshots freeze
 * their own `unit_factor`, so removing a row changes no history.
 */
final class RemoveUnitConversionHandler
{
    public const ACTION = 'catalog_unit_conversion_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveUnitConversion $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $conversionId = Identifier::fold($command->conversionId, 'conversionId');

            $before = $connection->table('unit_conversions')->where('id', $conversionId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The unit conversion [{$conversionId}] does not exist.");
            }

            $connection->table('unit_conversions')->where('id', $conversionId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'unit_conversion',
                $conversionId,
                (array) $before,
                null,
            );
        });
    }
}
