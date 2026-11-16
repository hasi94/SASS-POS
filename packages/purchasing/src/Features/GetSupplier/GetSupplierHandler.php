<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\GetSupplier;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Suppliers\SupplierImage;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Read one supplier (T-034 AC-15, AC-18, D-36).
 *
 * A reader holding `purchasing.suppliers.view` gets every column **except**
 * `bank_details`, which is absent from the answer, not null; a reader holding
 * `purchasing.suppliers.manage` gets it. Either key is enough to read; neither is
 * `not_authorised`. The key is asked with a null branch (suppliers belong to no
 * branch). A soft-deleted supplier is `not_found`, whatever the reader holds.
 */
final class GetSupplierHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(GetSupplier $query): GetSupplierResult
    {
        // `manage` first: it is the key that sees the bank details, and the first key that allows is the one reported.
        $allowed = Authorise::requireAny(
            $this->permissions,
            $query->actorId,
            [PurchasingPermissions::SUPPLIERS_MANAGE, PurchasingPermissions::SUPPLIERS_VIEW],
            null,
        );

        $supplierId = Identifier::fold($query->supplierId, 'supplier_id');
        $image = SupplierImage::of(Lookup::live($this->tenant->get(), 'suppliers', $supplierId, 'supplier'));

        return new GetSupplierResult(
            $allowed['key'] === PurchasingPermissions::SUPPLIERS_MANAGE ? $image : SupplierImage::withoutBankDetails($image),
        );
    }
}
