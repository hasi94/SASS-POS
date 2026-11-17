<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Illuminate\Database\Connection;

/**
 * The locks every document write takes, in the one global order (T-034 Ruling 7):
 * the document header first, then the supplier, then the engine's own locks, then
 * the number sequence last.
 *
 * Both are by primary key and never by range. A plain read decides nothing: a
 * status or a supplier's state is read **again under the lock** by whoever took it.
 * NOT VERIFIED under `ENGINE_HOLD`: the SQLite stand-in has no row locks, so what
 * the tests prove is the order of the statements and the clause sent, never that
 * the engine blocks (the lock-marking grammar's own docblock says the same).
 */
final class DocumentLocks
{
    /**
     * The request header `FOR UPDATE`.
     *
     * @throws PurchasingRefused validation (malformed id), not_found
     */
    public static function request(Connection $connection, mixed $requestId): object
    {
        return Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request', true);
    }

    /**
     * The order header `FOR UPDATE`: row 1 of the lock order for every order write
     * (and for a receipt, which never locks a line first).
     *
     * @throws PurchasingRefused validation (malformed id), not_found
     */
    public static function order(Connection $connection, mixed $orderId): object
    {
        return Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order', true);
    }

    /**
     * A supplier a document is about to name, `FOR SHARE`, and checked live and
     * active under that lock (AC-13, AC-23): an unknown or soft-deleted supplier is
     * `unknown_supplier`, an inactive one `supplier_inactive`. The share lock
     * serialises this against a concurrent deactivate or delete, which take the row
     * `FOR UPDATE` first and alone.
     *
     * @throws PurchasingRefused validation (malformed id), unknown_supplier, supplier_inactive
     */
    public static function activeSupplier(Connection $connection, mixed $supplierId): object
    {
        $id = Identifier::fold($supplierId, 'supplier_id');

        try {
            $supplier = Lookup::live($connection, 'suppliers', $id, 'supplier', Lookup::SHARED);
        } catch (PurchasingRefused $refused) {
            if ($refused->reason === PurchasingRefusal::NotFound) {
                throw PurchasingRefused::because(PurchasingRefusal::UnknownSupplier, "No supplier has id [{$id}].");
            }

            throw $refused;
        }

        if (! (bool) $supplier->is_active) {
            throw PurchasingRefused::because(PurchasingRefusal::SupplierInactive, "Supplier [{$id}] is inactive.");
        }

        return $supplier;
    }
}
