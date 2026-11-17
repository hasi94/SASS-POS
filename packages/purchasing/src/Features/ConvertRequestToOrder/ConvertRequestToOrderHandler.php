<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ConvertRequestToOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\ExchangeRate;
use Flexipos\Purchasing\Documents\LineQuoter;
use Flexipos\Purchasing\Documents\OrderCurrency;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderRollup;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Documents\ProductFacts;
use Flexipos\Purchasing\Documents\QuotedLine;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Documents\TaxGroupChain;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\BusinessClock;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Turn an approved request into a draft order for one supplier (T-034 AC-30 to
 * AC-33, AC-94; Rulings 3, 5, 7; D-14, D-15, D-41, D-48).
 *
 * **Who.** The conversion writes a request and creates an order, so the actor needs
 * `purchasing.requests.manage` **and** `purchasing.orders.manage`, both asked at
 * the request's **stored** branch (AC-30, AC-85 d).
 *
 * **What is carried.** One draft order with a `PO` number, the request's branch and
 * delivery location, `request_id` set, `expected_date` the request's
 * `required_date`, the three supplier snapshots, the currency by the chain with no
 * explicit step (supplier, then branch), and one line per request line. Each line is
 * the request line's own: the unit quad comes from
 * `UnitResolver::snapshotInherited()` with the request line as the referenced line,
 * so **the stored `unit_factor` survives a ladder that has since moved** (AC-31,
 * D-14); `unit_cost` is its `estimated_cost` (`0.0000` without one); the group is
 * the product's purchase group, else the default; the line is re-quoted by
 * `PurchaseLinePricing` at the new order's own date; its description is the
 * product's name. Each request line's `purchase_order_item_id` is set and the
 * request becomes `converted`, and **stays converted if the order is later
 * cancelled** (AC-33, D-15). Two audit rows are written,
 * `purchasing_request_converted` then `purchasing_order_created`.
 *
 * **Refusals write nothing (AC-32).** Everything that can refuse runs before the
 * transaction on plain reads (Ruling 7): the status, a line naming another supplier
 * (`supplier_mismatch`), the supplier live and active, the branch, every product
 * (one `whereIn`; a product that can no longer be bought is refused as a new line
 * would be), every group and every quote (the resolver's `inherited_unit_mismatch`
 * or `unit_unresolvable`, pricing's `pricing_refused`, `unknown_tax_group`,
 * `inactive_tax_group`) and the header total. The transaction then locks the
 * **request** header first, re-reads its status, takes the supplier `FOR SHARE`
 * (row 2) and takes the number **last**; it runs as an outermost
 * `transaction(..., 2)` (Ruling 3), so a refusal consumes no number.
 *
 * **Replay (Ruling 5, AC-33).** A request already `converted` for this supplier
 * returns its order with `replayed = true` and writes nothing; for a different
 * supplier it is `invalid_transition`. A concurrent second convert waits on the
 * request lock and is answered the same way.
 */
final class ConvertRequestToOrderHandler
{
    private const FIELDS = ['request_id', 'supplier_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly BusinessClock $business,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly DocumentNumbers $numbers,
        private readonly Places $places,
        private readonly ProductFacts $products,
        private readonly TaxGroupChain $groups,
        private readonly LineQuoter $quoter,
    ) {}

    public function handle(ConvertRequestToOrder $command): ConvertRequestToOrderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $requestId = Identifier::fold($fields['request_id'] ?? null, 'request_id');
        $supplierId = Identifier::fold($fields['supplier_id'] ?? null, 'supplier_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);
        Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        $status = RequestStatus::from((string) $header->status);

        if ($status === RequestStatus::Converted) {
            return $this->replay($connection, $requestId, $supplierId, $header);
        }

        $status->assertCanMoveTo(RequestStatus::Converted);

        /** @var list<array<string, mixed>> $lines */
        $lines = $connection->table('purchase_request_items')->where('request_id', $requestId)->orderBy('line_no')->get()
            ->map(static fn (object $row): array => RequestImage::line($row))->all();

        if ($lines === []) {
            throw PurchasingRefused::because(PurchasingRefusal::NoLines, "Purchase request [{$requestId}] has no lines to convert.");
        }

        foreach ($lines as $line) {
            if ($line['supplier_id'] !== null && $line['supplier_id'] !== $supplierId) {
                throw PurchasingRefused::because(
                    PurchasingRefusal::SupplierMismatch,
                    "Request line [{$line['id']}] names supplier [{$line['supplier_id']}], not [{$supplierId}].",
                );
            }
        }

        $this->assertSupplier($connection, $supplierId);

        $branch = $this->places->branch($connection, $branchId);
        $orderDate = $this->business->businessDate($branch->timezone);

        $facts = $this->products->purchasable($connection, array_map(static fn (array $line): string => $line['product_id'], $lines));
        $quoted = [];

        foreach ($lines as $line) {
            $quoted[] = $this->quoter->quoteInherited(
                $line['product_id'],
                $line['unit_id'],
                $line['unit_factor'],
                $line['unit_id'],
                $line['quantity'],
                $line['estimated_cost'] ?? Money::ZERO,
                Money::ZERO,
                $this->groups->resolve(null, $facts[$line['product_id']]->purchase_tax_group_id),
                $orderDate,
            );
        }

        $totals = OrderRollup::totals(array_map(static fn (QuotedLine $line): array => $line->columns(), $quoted), Money::ZERO);

        try {
            return $connection->transaction(function () use ($connection, $requestId, $supplierId, $branchId, $branch, $orderDate, $lines, $quoted, $facts, $totals, $actorId): ConvertRequestToOrderResult {
                $locked = DocumentLocks::request($connection, $requestId);
                $lockedStatus = RequestStatus::from((string) $locked->status);

                if ($lockedStatus === RequestStatus::Converted) {
                    return $this->replay($connection, $requestId, $supplierId, $locked);
                }

                $lockedStatus->assertCanMoveTo(RequestStatus::Converted);

                $supplier = DocumentLocks::activeSupplier($connection, $supplierId);

                $currency = OrderCurrency::resolve(
                    null,
                    $supplier->currency === null ? null : (string) $supplier->currency,
                    $branch->currency,
                );

                // The number is taken last (Ruling 3, Ruling 7 row 4).
                try {
                    $number = $this->numbers->next('purchase_order', $branchId);
                } catch (DocumentNumbersRefused $refused) {
                    throw PurchasingRefused::fromNumbers($refused);
                }

                $now = $this->clock->timestamp();
                $orderId = strtoupper((string) Str::ulid());
                $before = RequestImage::header($locked);

                $connection->table('purchase_orders')->insert(array_merge([
                    'id' => $orderId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'number' => $number,
                    'supplier_id' => $supplierId,
                    'supplier_name' => (string) $supplier->name,
                    'supplier_tax_no' => $supplier->tax_no === null ? null : (string) $supplier->tax_no,
                    'payment_terms_days' => (int) $supplier->payment_terms_days,
                    'branch_id' => $branchId,
                    'location_id' => $before['location_id'],
                    'request_id' => $requestId,
                    'order_date' => $orderDate,
                    'expected_date' => $before['required_date'],
                    'status' => OrderStatus::Draft->value,
                    'workflow_instance_id' => null,
                    'currency' => $currency,
                    'exchange_rate' => ExchangeRate::DEFAULT,
                    'shipping' => Money::ZERO,
                    'received_value' => Money::ZERO,
                    'terms' => null,
                    'notes' => null,
                ], $totals));

                foreach ($lines as $index => $line) {
                    $lineId = strtoupper((string) Str::ulid());

                    $connection->table('purchase_order_items')->insert(array_merge([
                        'id' => $lineId,
                        'order_id' => $orderId,
                        'line_no' => $index + 1,
                        'product_id' => $line['product_id'],
                        'description' => mb_substr($facts[$line['product_id']]->name, 0, 255),
                        'received_quantity' => Money::ZERO,
                        'received_base_quantity' => Money::ZERO,
                        'expected_date' => null,
                    ], $quoted[$index]->columns()));

                    $connection->table('purchase_request_items')->where('id', $line['id'])->update(['purchase_order_item_id' => $lineId]);
                }

                $connection->table('purchase_requests')->where('id', $requestId)->update([
                    'status' => RequestStatus::Converted->value,
                    'updated_at' => $now,
                    'updated_by' => $actorId,
                ]);

                $request = RequestImage::header($connection->table('purchase_requests')->where('id', $requestId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase request has id [{$requestId}]."));
                $order = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

                $this->audit->record(PurchasingAuditActions::MODULE, $actorId, PurchasingAuditActions::REQUEST_CONVERTED, 'purchase_request', $requestId, $before, $request, $branchId);
                $this->audit->record(PurchasingAuditActions::MODULE, $actorId, PurchasingAuditActions::ORDER_CREATED, 'purchase_order', $orderId, null, $order, $branchId);

                return new ConvertRequestToOrderResult($orderId, $number, false, $order, $this->orderLines($connection, $orderId), $request);
            }, 2);
        } catch (UniqueConstraintViolationException) {
            // Rolled back, number included: the number is the only unique key a conversion adds.
            throw PurchasingRefused::because(
                PurchasingRefusal::NumberingRefused,
                'The document number collided with an existing order.',
            );
        }
    }

    /**
     * The order a converted request made, when it was made for this supplier.
     *
     * @throws PurchasingRefused invalid_transition
     */
    private function replay(Connection $connection, string $requestId, string $supplierId, object $request): ConvertRequestToOrderResult
    {
        $order = $connection->table('purchase_orders')->where('request_id', $requestId)->first();

        if ($order === null || strtoupper((string) $order->supplier_id) !== $supplierId) {
            throw PurchasingRefused::because(
                PurchasingRefusal::InvalidTransition,
                "Purchase request [{$requestId}] is already converted, for a different supplier.",
            );
        }

        $image = OrderImage::header($order);

        return new ConvertRequestToOrderResult($image['id'], $image['number'], true, $image, $this->orderLines($connection, $image['id']), RequestImage::header($request));
    }

    /**
     * The supplier exists, is not deleted and is active, on a plain read; the
     * transaction judges it again under its share lock.
     *
     * @throws PurchasingRefused unknown_supplier, supplier_inactive
     */
    private function assertSupplier(Connection $connection, string $supplierId): void
    {
        $supplier = $connection->table('suppliers')->where('id', $supplierId)->whereNull('deleted_at')->first(['is_active']);

        if ($supplier === null) {
            throw PurchasingRefused::because(PurchasingRefusal::UnknownSupplier, "No supplier has id [{$supplierId}].");
        }

        if (! (bool) $supplier->is_active) {
            throw PurchasingRefused::because(PurchasingRefusal::SupplierInactive, "Supplier [{$supplierId}] is inactive.");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderLines(Connection $connection, string $orderId): array
    {
        return $connection->table('purchase_order_items')->where('order_id', $orderId)->orderBy('line_no')->get()
            ->map(static fn (object $row): array => OrderImage::line($row))->all();
    }
}
