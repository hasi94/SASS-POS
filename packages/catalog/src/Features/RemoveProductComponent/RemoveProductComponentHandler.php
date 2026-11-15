<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductComponent;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

final class RemoveProductComponentHandler
{
    public const ACTION = 'catalog_product_component_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveProductComponent $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $componentId = Identifier::fold($command->componentId, 'componentId');

            $before = $connection->table('product_components')->where('id', $componentId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The product component [{$componentId}] does not exist.");
            }

            $connection->table('product_components')->where('id', $componentId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_component',
                $componentId,
                (array) $before,
                null,
            );
        });
    }
}
