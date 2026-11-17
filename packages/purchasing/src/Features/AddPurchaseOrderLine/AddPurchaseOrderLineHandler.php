<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\AddPurchaseOrderLine;

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
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Add a line to a draft purchase order (T-034 AC-42 to AC-47, AC-51, AC-53;
 * Rulings 5, 7; D-14, D-42).
 *
 * **Every figure on the line is another component's.** The unit quad is
 * `UnitResolver::snapshot()`'s and every amount is `PurchaseLinePricing`'s, both
 * through {@see LineQuoter}, **priced at the order's own date** (a rate that began
 * after the order was raised does not apply to a line typed in later). The group is
 * the line's explicit one, else the product's `purchase_tax_group_id`, else the
 * shop's default ({@see TaxGroupChain}); its state is asked before pricing
 * (`unknown_tax_group`, `inactive_tax_group`). The product is judged by
 * {@see ProductFacts} (D-39). The description defaults to the product's name at
 * this moment and is stored. All of that is resolved **before** the transaction on
 * plain reads (Ruling 7), so a refusal there writes nothing and holds no lock.
 *
 * **Inside the transaction** the order header is locked `FOR UPDATE` first; the
 * status and the line count are read again under it (`locked` or `order_closed`,
 * `too_many_lines`), the line is inserted, and the header totals are rewritten from
 * the stored lines. A header total that cannot fit `DECIMAL(18,4)` refuses the line
 * that caused it (AC-47). One audit row is written against the order (D-23).
 *
 * **Replay.** The line id is the caller's. An id that exists is answered, not
 * written: identical input returns it with `replayed = true`, a difference (or
 * another order's line) is `replay_conflict`. A quantity the unit rounded is the
 * same input as its rounded form. A tax group or a description the caller left out
 * is the chain's own answer at the time and is not compared; one the caller named
 * is.
 */
final class AddPurchaseOrderLineHandler
{
    private const FIELDS = ['id', 'order_id', 'product_id', 'unit_id', 'quantity', 'unit_cost', 'discount_percent', 'tax_group_id', 'description', 'expected_date'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly ProductFacts $products,
        private readonly TaxGroupChain $groups,
        private readonly LineQuoter $quoter,
        private readonly OrderLines $orderLines,
    ) {}

    public function handle(AddPurchaseOrderLine $command): AddPurchaseOrderLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $lineId = Identifier::fold($fields['id'] ?? null, 'id');
        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);
        $orderDate = substr((string) $header->order_date, 0, 10);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $unitId = Identifier::fold($fields['unit_id'] ?? null, 'unit_id');
        $quantity = LineInput::quantity($fields['quantity'] ?? null);
        $cost = LineInput::cost($fields['unit_cost'] ?? null, 'unit_cost')
            ?? throw PurchasingRefused::validation('The unit_cost is required.');
        $discount = ($fields['discount_percent'] ?? null) === null ? Money::ZERO : LineInput::discountPercent($fields['discount_percent']);
        $explicitGroup = Identifier::foldNullable($fields['tax_group_id'] ?? null, 'tax_group_id');
        $description = Fields::optionalText($fields, 'description', 255);
        $expectedDate = DateInput::optional($fields['expected_date'] ?? null, 'expected_date');

        if ($expectedDate !== null && $expectedDate < $orderDate) {
            throw PurchasingRefused::validation('The line expected_date is before the order date.');
        }

        $input = [
            'order_id' => $orderId,
            'product_id' => $productId,
            'unit_id' => $unitId,
            'unit_cost' => $cost,
            'discount_percent' => $discount,
            'expected_date' => $expectedDate,
        ];

        $existing = $connection->table('purchase_order_items')->where('id', $lineId)->first();

        if ($existing !== null) {
            return $this->replay($connection, $lineId, $existing, $input, $quantity, $explicitGroup, $description, $orderDate);
        }

        // An early answer for a status that cannot take a line; decided again under the lock below.
        Amendments::assertLineSetChange(OrderStatus::from((string) $header->status));

        $product = $this->products->one($connection, $productId);
        $groupId = $this->groups->resolve($explicitGroup, $product->purchase_tax_group_id);
        $quote = $this->quoter->quote($productId, $unitId, $quantity, $cost, $discount, $groupId, $orderDate);

        try {
            return $connection->transaction(function () use ($connection, $lineId, $orderId, $branchId, $product, $description, $expectedDate, $quote, $actorId): AddPurchaseOrderLineResult {
                $locked = DocumentLocks::order($connection, $orderId);

                Amendments::assertLineSetChange(OrderStatus::from((string) $locked->status));

                $this->orderLines->assertRoom($connection, $orderId);

                $connection->table('purchase_order_items')->insert(array_merge([
                    'id' => $lineId,
                    'order_id' => $orderId,
                    'line_no' => OrderLines::nextLineNo($connection, $orderId),
                    'product_id' => $product->id,
                    'description' => $description ?? mb_substr($product->name, 0, 255),
                    'received_quantity' => Money::ZERO,
                    'received_base_quantity' => Money::ZERO,
                    'expected_date' => $expectedDate,
                ], $quote->columns()));

                // A total that cannot fit refuses the line that caused it (AC-47): the throw rolls the insert back.
                OrderLines::retotal($connection, $orderId, Money::of($locked->shipping), $actorId, $this->clock->timestamp());

                $line = OrderImage::line($connection->table('purchase_order_items')->where('id', $lineId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase order line has id [{$lineId}]."));

                $this->audit->record(
                    PurchasingAuditActions::MODULE,
                    $actorId,
                    PurchasingAuditActions::ORDER_LINE_ADDED,
                    'purchase_order',
                    $orderId,
                    null,
                    $line,
                    $branchId,
                );

                return new AddPurchaseOrderLineResult($lineId, $orderId, false, $line, OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}].")));
            });
        } catch (UniqueConstraintViolationException $violation) {
            // A concurrent add of this id won the primary key; the transaction rolled back.
            $winner = $connection->table('purchase_order_items')->where('id', $lineId)->first();

            if ($winner === null) {
                throw $violation;
            }

            return $this->replay($connection, $lineId, $winner, $input, $quantity, $explicitGroup, $description, $orderDate);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws PurchasingRefused replay_conflict
     */
    private function replay(
        Connection $connection,
        string $lineId,
        object $existing,
        array $input,
        string $quantity,
        ?string $explicitGroup,
        ?string $description,
        string $orderDate,
    ): AddPurchaseOrderLineResult {
        $stored = OrderImage::line($existing);

        foreach ($input as $column => $value) {
            if ($stored[$column] !== $value) {
                throw $this->conflict($lineId, $column);
            }
        }

        if ($explicitGroup !== null && $stored['tax_group_id'] !== $explicitGroup) {
            throw $this->conflict($lineId, 'tax_group_id');
        }

        if ($description !== null && $stored['description'] !== $description) {
            throw $this->conflict($lineId, 'description');
        }

        if (! Money::equals($stored['quantity'], $quantity)) {
            // The unit may have rounded what was asked: the same request, not a different one.
            try {
                $rounded = $this->quoter->quote(
                    $stored['product_id'],
                    $stored['unit_id'],
                    $quantity,
                    $stored['unit_cost'],
                    $stored['discount_percent'],
                    $stored['tax_group_id'],
                    $orderDate,
                )->unit->quantity;
            } catch (PurchasingRefused) {
                throw $this->conflict($lineId, 'quantity');
            }

            if (! Money::equals($stored['quantity'], $rounded)) {
                throw $this->conflict($lineId, 'quantity');
            }
        }

        return new AddPurchaseOrderLineResult(
            $lineId,
            $stored['order_id'],
            true,
            $stored,
            OrderImage::header(Lookup::any($connection, 'purchase_orders', $stored['order_id'], 'purchase order')),
        );
    }

    private function conflict(string $lineId, string $column): PurchasingRefused
    {
        return PurchasingRefused::because(
            PurchasingRefusal::ReplayConflict,
            "Purchase order line [{$lineId}] already exists with a different [{$column}].",
        );
    }
}
