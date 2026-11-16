<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdateSupplier;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Suppliers\SupplierFields;
use Flexipos\Purchasing\Suppliers\SupplierImage;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Change a supplier (T-034 AC-10, AC-11, AC-12, AC-17; Ruling 7 row 2).
 *
 * **A subset update.** Only the submitted fields are considered, each compared
 * with the stored value: the ones that differ are written, and a call that
 * changes nothing writes nothing, not even `updated_at` or an audit row (AC-10).
 *
 * **The code is immutable** (D-5, AC-11): the submitted code is normalised like a
 * create's, then compared with the stored one, and a different one is
 * `code_immutable` before anything is written. The same code, in any case, is
 * accepted and is not a change.
 *
 * **AC-12 needs no code here**: an order copies the supplier's name, tax number
 * and terms when it is created and never reads them again, so nothing in this
 * slice has anything to leave alone.
 *
 * The supplier row is locked `FOR UPDATE` first and alone, then read again under
 * the lock, so the comparison is against what is stored now (Ruling 7).
 */
final class UpdateSupplierHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(UpdateSupplier $command): UpdateSupplierResult
    {
        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::SUPPLIERS_MANAGE, null);

        $fields = $command->fields;

        Fields::only($fields, array_merge(['supplier_id'], SupplierFields::ACCEPTED));

        $supplierId = Identifier::fold($fields['supplier_id'] ?? null, 'supplier_id');
        $submitted = SupplierFields::parse($fields, false);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $supplierId, $submitted, $actorId): UpdateSupplierResult {
            $row = Lookup::live($connection, 'suppliers', $supplierId, 'supplier', true);
            $before = SupplierImage::of($row);

            if (array_key_exists('code', $submitted)) {
                if ($submitted['code'] !== strtoupper((string) $before['code'])) {
                    throw PurchasingRefused::because(
                        PurchasingRefusal::CodeImmutable,
                        "The code of supplier [{$supplierId}] cannot be changed.",
                    );
                }

                unset($submitted['code']);
            }

            $changes = [];

            foreach ($submitted as $column => $value) {
                if (SupplierImage::differs($column, $before[$column], $value)) {
                    $changes[$column] = $value;
                }
            }

            if ($changes === []) {
                return new UpdateSupplierResult($supplierId, $before, []);
            }

            $now = $this->clock->timestamp();

            $write = $changes;

            foreach (SupplierImage::JSON as $column) {
                if (array_key_exists($column, $write)) {
                    $write[$column] = SupplierImage::encode($write[$column]);
                }
            }

            $connection->table('suppliers')->where('id', $supplierId)->update(array_merge($write, [
                'updated_at' => $now,
                'updated_by' => $actorId,
            ]));

            $after = SupplierImage::of($connection->table('suppliers')->where('id', $supplierId)->first()
                ?? throw PurchasingRefused::notFound("No supplier has id [{$supplierId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::SUPPLIER_UPDATED,
                'supplier',
                $supplierId,
                SupplierImage::auditBefore($before),
                SupplierImage::auditAfter($before, $after),
            );

            return new UpdateSupplierResult($supplierId, $after, array_keys($changes));
        });
    }
}
