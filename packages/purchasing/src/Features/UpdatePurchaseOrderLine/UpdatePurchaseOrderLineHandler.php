<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseOrderLine;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\Amendments;
use Flexipos\Purchasing\Documents\DateInput;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\LineInput;
use Flexipos\Purchasing\Documents\LineQuoter;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderLines;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\ProductFacts;
use Flexipos\Purchasing\Documents\ReceiptRollup;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\TaxGroupChain;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Database\Connection;

/**
 * Edit a line of an order (T-034 AC-42 to AC-49, AC-61, AC-63; Ruling 7, D-14).
 *
 * **A field left out keeps what the line holds**; `expected_date` set to null
 * clears it, and every other field must hold a value. **What is recomputed** is the
 * smallest set that keeps the line true (AC-49):
 *
 * - a **new unit or quantity** resolves the whole quad again with
 *   `UnitResolver::snapshot()` (D-14), judges the product again, and re-quotes the
 *   line;
 * - a **new cost, discount or tax group** alone keeps the stored quad and re-quotes
 *   it ({@see LineQuoter::price()}), so a line is never re-snapshotted for a reason
 *   that does not concern its unit;
 * - **a description or an expected date** alone touches no figure.
 *
 * A group that is **changed** is checked against the tax-group directory (unknown,
 * inactive); one the line already holds is priced as stored, so a later
 * deactivation does not block an edit of its quantity. The pricing date is the
 * order's own date. A re-quote re-sums the header from the stored lines (AC-45) and
 * a total that cannot fit refuses the edit (AC-47).
 *
 * **What a status allows** is {@see Amendments}' one table, asked under the header
 * lock: a draft takes every field; `approved` and `partially_received` only the
 * line's `expected_date` (and, in `partially_received`, only while the line is
 * incomplete); `pending_approval` is `locked`; `received`, `closed` and `cancelled`
 * are `order_closed`. If the line moved between the plain read a re-quote was built
 * from and the lock, the edit is refused `replay_conflict` rather than written over
 * what it was computed from. A call that changes nothing writes nothing.
 */
final class UpdatePurchaseOrderLineHandler
{
    private const FIELDS = ['order_id', 'line_id', 'quantity', 'unit_id', 'unit_cost', 'discount_percent', 'tax_group_id', 'description', 'expected_date'];

    /** The columns a re-quote may move, compared as numbers; every other column is compared as text. */
    private const AMOUNTS = ['quantity', 'base_quantity', 'unit_cost', 'subtotal', 'discount_percent', 'discount_amount', 'tax_amount', 'line_total'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly ProductFacts $products,
        private readonly TaxGroupChain $groups,
        private readonly LineQuoter $quoter,
    ) {}

    public function handle(UpdatePurchaseOrderLine $command): UpdatePurchaseOrderLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);
        $orderDate = substr((string) $header->order_date, 0, 10);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        $read = OrderImage::line(OrderLines::line($connection, $orderId, $fields['line_id'] ?? null));
        $submitted = $this->submitted($fields, $orderDate);

        // An early answer for a status or a field that cannot be changed; decided again under the lock below.
        Amendments::assertLine(
            OrderStatus::from((string) $header->status),
            $this->changedFields($read, $submitted),
            ReceiptRollup::isComplete($read['base_quantity'], $read['received_base_quantity']),
        );

        $computed = $this->computed($connection, $read, $submitted, $orderDate);

        return $connection->transaction(function () use ($connection, $orderId, $branchId, $read, $submitted, $computed, $actorId): UpdatePurchaseOrderLineResult {
            $locked = DocumentLocks::order($connection, $orderId);

            $before = OrderImage::line(OrderLines::line($connection, $orderId, $read['id'], true));

            if ($computed !== null && $this->moved($read, $before)) {
                throw PurchasingRefused::because(
                    PurchasingRefusal::ReplayConflict,
                    "Line [{$read['id']}] changed while it was being edited.",
                );
            }

            $candidates = array_merge($submitted, $computed ?? []);
            $changes = [];

            foreach ($candidates as $column => $value) {
                if ($this->differs($column, $before[$column], $value)) {
                    $changes[$column] = $value;
                }
            }

            Amendments::assertLine(
                OrderStatus::from((string) $locked->status),
                array_keys($changes),
                ReceiptRollup::isComplete($before['base_quantity'], $before['received_base_quantity']),
            );

            if ($changes === []) {
                return new UpdatePurchaseOrderLineResult($read['id'], $orderId, $before, OrderImage::header($locked), []);
            }

            $connection->table('purchase_order_items')->where('id', $read['id'])->update($changes);

            $now = $this->clock->timestamp();

            if ($computed !== null) {
                OrderLines::retotal($connection, $orderId, Money::of($locked->shipping), $actorId, $now);
            } else {
                OrderLines::touch($connection, $orderId, $actorId, $now);
            }

            $after = OrderImage::line($connection->table('purchase_order_items')->where('id', $read['id'])->first()
                ?? throw PurchasingRefused::notFound("No purchase order line has id [{$read['id']}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_LINE_UPDATED,
                'purchase_order',
                $orderId,
                $before,
                $after,
                $branchId,
            );

            return new UpdatePurchaseOrderLineResult(
                $read['id'],
                $orderId,
                $after,
                OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}].")),
                array_keys($changes),
            );
        });
    }

    /**
     * The columns the call names, each validated and normalised the way the stored
     * image holds it.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function submitted(array $fields, string $orderDate): array
    {
        $submitted = [];

        if (array_key_exists('quantity', $fields)) {
            $submitted['quantity'] = LineInput::quantity($fields['quantity']);
        }

        if (array_key_exists('unit_id', $fields)) {
            $submitted['unit_id'] = Identifier::fold($fields['unit_id'], 'unit_id');
        }

        if (array_key_exists('unit_cost', $fields)) {
            $submitted['unit_cost'] = LineInput::cost($fields['unit_cost'], 'unit_cost')
                ?? throw PurchasingRefused::validation('The unit_cost must be a decimal string, not null.');
        }

        if (array_key_exists('discount_percent', $fields)) {
            $submitted['discount_percent'] = LineInput::discountPercent($fields['discount_percent']);
        }

        if (array_key_exists('tax_group_id', $fields)) {
            $submitted['tax_group_id'] = Identifier::fold($fields['tax_group_id'], 'tax_group_id');
        }

        if (array_key_exists('description', $fields)) {
            $submitted['description'] = Fields::requiredText($fields, 'description', 255);
        }

        if (array_key_exists('expected_date', $fields)) {
            $submitted['expected_date'] = DateInput::optional($fields['expected_date'], 'expected_date');

            if ($submitted['expected_date'] !== null && $submitted['expected_date'] < $orderDate) {
                throw PurchasingRefused::validation('The line expected_date is before the order date.');
            }
        }

        return $submitted;
    }

    /**
     * The re-quote the call needs, as the columns it decides, or null when no
     * figure changes.
     *
     * @param  array<string, mixed>  $read
     * @param  array<string, mixed>  $submitted
     * @return ?array<string, mixed>
     */
    private function computed(Connection $connection, array $read, array $submitted, string $orderDate): ?array
    {
        $quantity = $submitted['quantity'] ?? $read['quantity'];
        $unitId = $submitted['unit_id'] ?? $read['unit_id'];
        $cost = $submitted['unit_cost'] ?? $read['unit_cost'];
        $discount = $submitted['discount_percent'] ?? $read['discount_percent'];
        $groupId = $submitted['tax_group_id'] ?? $read['tax_group_id'];

        $quadChanged = $unitId !== $read['unit_id'] || ! Money::equals($quantity, $read['quantity']);
        $priceChanged = ! Money::equals($cost, $read['unit_cost'])
            || ! Money::equals($discount, $read['discount_percent'])
            || $groupId !== $read['tax_group_id'];

        if (! $quadChanged && ! $priceChanged) {
            return null;
        }

        if ($groupId !== $read['tax_group_id']) {
            $groupId = $this->groups->resolve($groupId, null);
        }

        if ($quadChanged) {
            $this->products->one($connection, $read['product_id']);

            return $this->quoter->quote($read['product_id'], $unitId, $quantity, $cost, $discount, $groupId, $orderDate)->columns();
        }

        $priced = $this->quoter->price($read['quantity'], $read['base_quantity'], $cost, $discount, $groupId, $orderDate);

        return [
            'unit_cost' => $cost,
            'subtotal' => $priced->subtotal,
            'discount_percent' => $discount,
            'discount_amount' => $priced->discountAmount,
            'tax_group_id' => $groupId,
            'tax_amount' => $priced->tax->total,
            'line_total' => $priced->lineTotal,
        ];
    }

    /**
     * Whether the figures a re-quote was built from are still what the line holds.
     *
     * @param  array<string, mixed>  $read
     * @param  array<string, mixed>  $locked
     */
    private function moved(array $read, array $locked): bool
    {
        foreach (['unit_id', 'tax_group_id'] as $column) {
            if ($read[$column] !== $locked[$column]) {
                return true;
            }
        }

        foreach (['quantity', 'unit_cost', 'discount_percent'] as $column) {
            if (! Money::equals($read[$column], $locked[$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The submitted columns that differ from the line.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $submitted
     * @return list<string>
     */
    private function changedFields(array $line, array $submitted): array
    {
        $changed = [];

        foreach ($submitted as $column => $value) {
            if ($this->differs($column, $line[$column], $value)) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    private function differs(string $column, mixed $stored, mixed $submitted): bool
    {
        if (in_array($column, self::AMOUNTS, true)) {
            return ! Money::equals((string) $stored, (string) $submitted);
        }

        if ($column === 'unit_factor') {
            return RequestImage::factor((string) $stored) !== RequestImage::factor((string) $submitted);
        }

        return $stored !== $submitted;
    }
}
