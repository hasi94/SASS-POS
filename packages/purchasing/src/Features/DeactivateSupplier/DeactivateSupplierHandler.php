<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\DeactivateSupplier;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Suppliers\SupplierImage;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Deactivate a supplier (T-034 AC-13, AC-17, Ruling 5).
 *
 * `is_active = false` stops new request lines, orders, conversions and the submit
 * of a draft from naming the supplier; documents it already has are untouched.
 * **Idempotent by outcome:** deactivating an inactive supplier succeeds, writes
 * nothing and records no audit row. A soft-deleted supplier is `not_found`.
 */
final class DeactivateSupplierHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(DeactivateSupplier $command): DeactivateSupplierResult
    {
        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::SUPPLIERS_MANAGE, null);
        $supplierId = Identifier::fold($command->supplierId, 'supplier_id');

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $supplierId, $actorId): DeactivateSupplierResult {
            $before = SupplierImage::of(Lookup::live($connection, 'suppliers', $supplierId, 'supplier', true));

            if (! $before['is_active']) {
                return new DeactivateSupplierResult($supplierId, false);
            }

            $connection->table('suppliers')->where('id', $supplierId)->update([
                'is_active' => false,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = SupplierImage::of(Lookup::live($connection, 'suppliers', $supplierId, 'supplier'));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::SUPPLIER_DEACTIVATED,
                'supplier',
                $supplierId,
                SupplierImage::auditBefore($before),
                SupplierImage::auditAfter($before, $after),
            );

            return new DeactivateSupplierResult($supplierId, true);
        });
    }
}
