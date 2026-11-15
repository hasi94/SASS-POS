<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveModifier;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

final class RemoveModifierHandler
{
    public const ACTION = 'catalog_modifier_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveModifier $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $modifierId = Identifier::fold($command->modifierId, 'modifierId');

            $before = $connection->table('modifiers')->where('id', $modifierId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The modifier [{$modifierId}] does not exist.");
            }

            $connection->table('modifiers')->where('id', $modifierId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'modifier',
                $modifierId,
                (array) $before,
                null,
            );
        });
    }
}
