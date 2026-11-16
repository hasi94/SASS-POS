<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\DeleteSupplier;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Suppliers\SupplierImage;
use Flexipos\Purchasing\Suppliers\SupplierUse;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Delete a supplier (T-034 AC-14, D-7).
 *
 * A soft delete: `deleted_at` is set, the row stays, **the code stays reserved**
 * (the unique index covers every row), `GetSupplier` answers `not_found` and the
 * list omits it. It is refused `supplier_in_use` while a request that names the
 * supplier on a line, or an order for it, is not yet finished
 * ({@see SupplierUse}). There is no restore.
 *
 * **Lock order (Ruling 7, row 2):** the supplier row `FOR UPDATE` is the first and
 * only lock; the documents are then read plainly. A concurrent request line or
 * order that takes the supplier `FOR SHARE` is therefore serialised against this
 * delete. NOT VERIFIED under `ENGINE_HOLD` (plan risk 9).
 *
 * **Idempotent by outcome:** deleting a deleted supplier succeeds, writes nothing
 * and records no audit row.
 */
final class DeleteSupplierHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(DeleteSupplier $command): DeleteSupplierResult
    {
        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::SUPPLIERS_MANAGE, null);
        $supplierId = Identifier::fold($command->supplierId, 'supplier_id');

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $supplierId, $actorId): DeleteSupplierResult {
            $row = Lookup::any($connection, 'suppliers', $supplierId, 'supplier', true);

            if ($row->deleted_at !== null) {
                return new DeleteSupplierResult($supplierId, false);
            }

            $blocker = SupplierUse::blocker($connection, $supplierId);

            if ($blocker !== null) {
                throw PurchasingRefused::because(PurchasingRefusal::SupplierInUse, $blocker);
            }

            $before = SupplierImage::of($row);
            $now = $this->clock->timestamp();

            $connection->table('suppliers')->where('id', $supplierId)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'updated_by' => $actorId,
            ]);

            $after = SupplierImage::of(Lookup::any($connection, 'suppliers', $supplierId, 'supplier'));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::SUPPLIER_DELETED,
                'supplier',
                $supplierId,
                SupplierImage::auditBefore($before),
                SupplierImage::auditAfter($before, $after),
            );

            return new DeleteSupplierResult($supplierId, true);
        });
    }
}
