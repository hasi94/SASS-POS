<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\Amendments;
use Flexipos\Purchasing\Documents\DateInput;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\ExchangeRate;
use Flexipos\Purchasing\Documents\LineInput;
use Flexipos\Purchasing\Documents\OrderCurrency;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderLines;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Edit the header of an order (T-034 AC-12, AC-41, AC-50; Rulings 7, 8).
 *
 * **What a status allows** is {@see Amendments}' one table, asked under the
 * header lock: in `draft` every field below; in `approved` and `partially_received`
 * only `notes`, `terms` and `expected_date` (the post-approval amendment, AC-61 and
 * AC-63, covered by chunk F's dataset); `pending_approval` is `locked`;
 * `received`, `closed` and `cancelled` are `order_closed`. The branch is the
 * order's own and never changes, so there is no `branch_id` field; the permission is
 * asked at the **stored** branch (AC-85 d). A field left out keeps its value; a
 * value equal to the stored one is no change, and a call that changes nothing
 * writes nothing, not even `updated_at`.
 *
 * **A new supplier** is taken `FOR SHARE` after the header (Ruling 7 rows 1 and 2),
 * must be active, and **re-copies the three supplier snapshots** (AC-50); the
 * currency stays as stored unless the call names one. A supplier that is merely
 * kept is not judged again, so an order whose supplier has since been deactivated
 * can still have its notes edited. A new `location_id` must be an eligible delivery
 * location of the order's branch (D-40); a new `expected_date` must be a real date
 * not before the order date. **A new `shipping` re-totals** from the stored lines
 * (AC-45) and a total that cannot fit is `out_of_range` (AC-47). Nothing else is
 * recomputed: the stored lines are not touched (AC-48).
 */
final class UpdatePurchaseOrderHandler
{
    private const FIELDS = ['order_id', 'supplier_id', 'location_id', 'expected_date', 'currency', 'exchange_rate', 'shipping', 'terms', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly Places $places,
    ) {}

    public function handle(UpdatePurchaseOrder $command): UpdatePurchaseOrderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        $submitted = $this->submitted($fields);
        $stored = OrderImage::header($header);

        if (isset($submitted['location_id']) && $submitted['location_id'] !== $stored['location_id']) {
            $this->places->location($connection, $submitted['location_id'], $branchId);
        }

        if (isset($submitted['expected_date']) && $submitted['expected_date'] !== $stored['expected_date'] && $submitted['expected_date'] < $stored['order_date']) {
            throw PurchasingRefused::validation('The expected_date is before the order date.');
        }

        return $connection->transaction(function () use ($connection, $orderId, $submitted, $actorId, $branchId): UpdatePurchaseOrderResult {
            $locked = DocumentLocks::order($connection, $orderId);

            $before = OrderImage::header($locked);
            $changes = [];

            foreach ($submitted as $column => $value) {
                if ($before[$column] !== $value) {
                    $changes[$column] = $value;
                }
            }

            Amendments::assertHeader(OrderStatus::from($before['status']), array_keys($changes));

            if ($changes === []) {
                return new UpdatePurchaseOrderResult($orderId, $before, []);
            }

            if (isset($changes['supplier_id'])) {
                $supplier = DocumentLocks::activeSupplier($connection, $changes['supplier_id']);

                $changes['supplier_name'] = (string) $supplier->name;
                $changes['supplier_tax_no'] = $supplier->tax_no === null ? null : (string) $supplier->tax_no;
                $changes['payment_terms_days'] = (int) $supplier->payment_terms_days;
            }

            if (isset($changes['shipping'])) {
                $changes = array_merge($changes, OrderLines::totals($connection, $orderId, $changes['shipping']));
            }

            $connection->table('purchase_orders')->where('id', $orderId)->update(array_merge($changes, [
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]));

            $after = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_UPDATED,
                'purchase_order',
                $orderId,
                $before,
                $after,
                $branchId,
            );

            return new UpdatePurchaseOrderResult($orderId, $after, array_keys($changes));
        });
    }

    /**
     * The columns the call names, each validated and normalised the way the
     * stored image holds it. An explicit null clears `expected_date`, `terms` and
     * `notes`; it is `validation` for every column that must hold a value.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function submitted(array $fields): array
    {
        $submitted = [];

        foreach (['supplier_id', 'location_id'] as $column) {
            if (array_key_exists($column, $fields)) {
                $submitted[$column] = Identifier::fold($fields[$column], $column);
            }
        }

        if (array_key_exists('expected_date', $fields)) {
            $submitted['expected_date'] = DateInput::optional($fields['expected_date'], 'expected_date');
        }

        if (array_key_exists('currency', $fields)) {
            $submitted['currency'] = OrderCurrency::explicit($fields['currency']);
        }

        if (array_key_exists('exchange_rate', $fields)) {
            $submitted['exchange_rate'] = ExchangeRate::input($fields['exchange_rate']);
        }

        if (array_key_exists('shipping', $fields)) {
            $submitted['shipping'] = LineInput::cost($fields['shipping'], 'shipping')
                ?? throw PurchasingRefused::validation('The shipping must be a decimal string, not null.');
        }

        if (array_key_exists('terms', $fields)) {
            $submitted['terms'] = Fields::optionalText($fields, 'terms', 1000);
        }

        if (array_key_exists('notes', $fields)) {
            $submitted['notes'] = Fields::optionalText($fields, 'notes', 2000);
        }

        return $submitted;
    }
}
