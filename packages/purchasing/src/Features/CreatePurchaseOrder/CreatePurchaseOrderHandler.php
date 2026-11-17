<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreatePurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Contracts\Organization\DocumentNumbersRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DateInput;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\ExchangeRate;
use Flexipos\Purchasing\Documents\LineInput;
use Flexipos\Purchasing\Documents\OrderCurrency;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderRollup;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\BusinessClock;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Money;
use Flexipos\Purchasing\Support\TenantConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Raise a draft purchase order (T-034 AC-40, AC-41, AC-53, AC-90; Rulings 3, 5,
 * 7; D-17, D-19, D-41).
 *
 * **What is the server's.** The number comes from
 * `DocumentNumbers::next('purchase_order', branch)`, the status is `draft`, the
 * order date is the branch-local business date of the server clock, the supplier
 * name, tax number and payment terms are copied from the supplier, `request_id` is
 * null and every total is zero plus the shipping (AC-45: an order with no lines has
 * every total `0.0000` plus shipping). A supplied `number`, `status`, `order_date`
 * or total is an unknown field, refused `validation` (D-19).
 *
 * **Currency** is the explicit one, else the supplier's, else the branch's
 * (D-41); `currency_required` only when none is a currency (Q-B4).
 *
 * **Order of work (Ruling 7).** Everything that can refuse without a lock runs
 * before the transaction on plain reads: the branch is active, the actor holds
 * `purchasing.orders.manage` **at that branch**, the location is eligible (D-40),
 * the `expected_date` is a real date not before the order date. The transaction
 * then takes the supplier `FOR SHARE` (row 2, so a concurrent deactivation cannot
 * interleave; unknown or deleted is `unknown_supplier`, inactive
 * `supplier_inactive`), resolves the currency from the locked row, and takes the
 * number **last**. It runs as an outermost `transaction(..., 2)`: a deadlock on
 * the first use of a sequence is retried once from the top, never inside a
 * savepoint. A refusal therefore consumes no number (AC-41, AC-90).
 *
 * **Replay (Ruling 5).** The id is the caller's ULID. An id that already exists is
 * answered, not written: identical input is returned with `replayed = true` (no
 * number, audit row or event), any difference, the actor included, is
 * `replay_conflict` naming the id. A concurrent duplicate loses on the primary
 * key, rolls back, and is then answered the same way; a unique violation on the
 * *number* is `numbering_refused`.
 */
final class CreatePurchaseOrderHandler
{
    private const FIELDS = ['id', 'supplier_id', 'branch_id', 'location_id', 'expected_date', 'currency', 'exchange_rate', 'shipping', 'terms', 'notes'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly BusinessClock $business,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly DocumentNumbers $numbers,
        private readonly Places $places,
    ) {}

    public function handle(CreatePurchaseOrder $command): CreatePurchaseOrderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $id = Identifier::fold($fields['id'] ?? null, 'id');
        $supplierId = Identifier::fold($fields['supplier_id'] ?? null, 'supplier_id');
        $branchId = Identifier::fold($fields['branch_id'] ?? null, 'branch_id');
        $locationId = Identifier::fold($fields['location_id'] ?? null, 'location_id');
        $expectedDate = DateInput::optional($fields['expected_date'] ?? null, 'expected_date');
        $currency = ($fields['currency'] ?? null) === null ? null : OrderCurrency::explicit($fields['currency']);
        $rate = ($fields['exchange_rate'] ?? null) === null ? ExchangeRate::DEFAULT : ExchangeRate::input($fields['exchange_rate']);
        $shipping = LineInput::cost($fields['shipping'] ?? null, 'shipping') ?? Money::ZERO;
        $terms = Fields::optionalText($fields, 'terms', 1000);
        $notes = Fields::optionalText($fields, 'notes', 2000);

        $connection = $this->tenant->get();

        $branch = $this->places->branch($connection, $branchId);
        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        $input = [
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'expected_date' => $expectedDate,
            'exchange_rate' => $rate,
            'shipping' => $shipping,
            'terms' => $terms,
            'notes' => $notes,
            'created_by' => $actorId,
        ];

        // A replay is answered before the date and the location are judged again: an
        // order raised yesterday is still the same order tomorrow.
        $existing = $connection->table('purchase_orders')->where('id', $id)->first();

        if ($existing !== null) {
            return $this->replay($connection, $id, $existing, $input, $currency, $branch->currency);
        }

        $this->places->location($connection, $locationId, $branchId);

        $orderDate = $this->business->businessDate($branch->timezone);

        if ($expectedDate !== null && $expectedDate < $orderDate) {
            throw PurchasingRefused::validation('The expected_date is before the order date.');
        }

        try {
            return $connection->transaction(function () use ($connection, $id, $input, $actorId, $currency, $branch, $orderDate): CreatePurchaseOrderResult {
                // Row 2 of the lock order: the supplier, shared. There is no header to lock on a create.
                $supplier = DocumentLocks::activeSupplier($connection, $input['supplier_id']);

                $orderCurrency = OrderCurrency::resolve(
                    $currency,
                    $supplier->currency === null ? null : (string) $supplier->currency,
                    $branch->currency,
                );

                $totals = OrderRollup::totals([], $input['shipping']);

                // The number is taken last (Ruling 3, Ruling 7 row 4).
                try {
                    $number = $this->numbers->next('purchase_order', $input['branch_id']);
                } catch (DocumentNumbersRefused $refused) {
                    throw PurchasingRefused::fromNumbers($refused);
                }

                $now = $this->clock->timestamp();

                $connection->table('purchase_orders')->insert(array_merge([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'number' => $number,
                    'supplier_id' => $input['supplier_id'],
                    'supplier_name' => (string) $supplier->name,
                    'supplier_tax_no' => $supplier->tax_no === null ? null : (string) $supplier->tax_no,
                    'payment_terms_days' => (int) $supplier->payment_terms_days,
                    'branch_id' => $input['branch_id'],
                    'location_id' => $input['location_id'],
                    'request_id' => null,
                    'order_date' => $orderDate,
                    'expected_date' => $input['expected_date'],
                    'status' => OrderStatus::Draft->value,
                    'workflow_instance_id' => null,
                    'currency' => $orderCurrency,
                    'exchange_rate' => $input['exchange_rate'],
                    'shipping' => $input['shipping'],
                    'received_value' => Money::ZERO,
                    'terms' => $input['terms'],
                    'notes' => $input['notes'],
                ], $totals));

                $image = OrderImage::header($connection->table('purchase_orders')->where('id', $id)->first()
                    ?? throw PurchasingRefused::notFound("No purchase order has id [{$id}]."));

                $this->audit->record(
                    PurchasingAuditActions::MODULE,
                    $actorId,
                    PurchasingAuditActions::ORDER_CREATED,
                    'purchase_order',
                    $id,
                    null,
                    $image,
                    $input['branch_id'],
                );

                return new CreatePurchaseOrderResult($id, $number, false, $image);
            }, 2);
        } catch (UniqueConstraintViolationException $violation) {
            // Rolled back, number included. Either a concurrent create took this id (answer
            // it as a replay), or the number collided (numbering_refused, Ruling 3).
            $winner = $connection->table('purchase_orders')->where('id', $id)->first();

            if ($winner !== null) {
                return $this->replay($connection, $id, $winner, $input, $currency, $branch->currency);
            }

            throw PurchasingRefused::because(
                PurchasingRefusal::NumberingRefused,
                'The document number collided with an existing order.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws PurchasingRefused replay_conflict
     */
    private function replay(Connection $connection, string $id, object $existing, array $input, ?string $currency, string $branchCurrency): CreatePurchaseOrderResult
    {
        $stored = OrderImage::header($existing);

        foreach ($input as $column => $value) {
            if ($stored[$column] !== $value) {
                throw $this->conflict($id, $column);
            }
        }

        // An omitted currency is the chain's answer, so it is compared as that answer.
        $supplier = $connection->table('suppliers')->where('id', $input['supplier_id'])->first();

        try {
            $expected = OrderCurrency::resolve(
                $currency,
                $supplier === null || $supplier->currency === null ? null : (string) $supplier->currency,
                $branchCurrency,
            );
        } catch (PurchasingRefused) {
            throw $this->conflict($id, 'currency');
        }

        if ($stored['currency'] !== $expected) {
            throw $this->conflict($id, 'currency');
        }

        return new CreatePurchaseOrderResult($id, $stored['number'], true, $stored);
    }

    private function conflict(string $id, string $column): PurchasingRefused
    {
        return PurchasingRefused::because(
            PurchasingRefusal::ReplayConflict,
            "Purchase order [{$id}] already exists with a different [{$column}].",
        );
    }
}
