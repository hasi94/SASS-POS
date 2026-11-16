<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\AddPurchaseRequestLine;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\LineInput;
use Flexipos\Purchasing\Documents\ProductFacts;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestLines;
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
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Add a line to a draft request (T-034 AC-22, AC-23, AC-25, AC-36, AC-39;
 * Rulings 5, 7).
 *
 * **The quad is the resolver's.** `unit_id`, `quantity` (rounded to the unit's
 * precision), `unit_factor` and `base_quantity` are exactly what
 * `UnitResolver::snapshot()` returns, resolved **before** the transaction on plain
 * reads (Ruling 7), so a refusal there writes nothing and holds no lock. The
 * product is judged by {@see ProductFacts} (D-39): unknown, inactive or deleted is
 * `unknown_product`, a variant parent, a bundle or the non-stock type is
 * `product_not_purchasable`.
 *
 * **Inside the transaction**, in lock order: the header `FOR UPDATE` (the status
 * and the line count are read again under it, so `locked` and `too_many_lines` are
 * decided against what is stored now), then the supplier `FOR SHARE` when the line
 * names one (unknown or deleted `unknown_supplier`, inactive `supplier_inactive`).
 *
 * **Replay.** The line id is the caller's. An id that exists is answered, not
 * written: identical input returns it with `replayed = true`, a difference (or
 * another request's line) is `replay_conflict`. A quantity the unit rounded is
 * the same input as its rounded form, so a retry of `2.55` into a one-decimal unit
 * is a replay, not a conflict.
 */
final class AddPurchaseRequestLineHandler
{
    private const FIELDS = ['id', 'request_id', 'product_id', 'unit_id', 'quantity', 'estimated_cost', 'supplier_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly ProductFacts $products,
        private readonly RequestLines $lines,
    ) {}

    public function handle(AddPurchaseRequestLine $command): AddPurchaseRequestLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $lineId = Identifier::fold($fields['id'] ?? null, 'id');
        $requestId = Identifier::fold($fields['request_id'] ?? null, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $unitId = Identifier::fold($fields['unit_id'] ?? null, 'unit_id');
        $quantity = LineInput::quantity($fields['quantity'] ?? null);
        $cost = LineInput::cost($fields['estimated_cost'] ?? null, 'estimated_cost');
        $supplierId = Identifier::foldNullable($fields['supplier_id'] ?? null, 'supplier_id');

        $input = [
            'request_id' => $requestId,
            'product_id' => $productId,
            'unit_id' => $unitId,
            'estimated_cost' => $cost,
            'supplier_id' => $supplierId,
        ];

        $existing = $connection->table('purchase_request_items')->where('id', $lineId)->first();

        if ($existing !== null) {
            return $this->replay($lineId, $existing, $input, $quantity);
        }

        $this->products->one($connection, $productId);
        $unit = $this->lines->snapshot($productId, $unitId, $quantity);

        try {
            return $connection->transaction(function () use ($connection, $lineId, $requestId, $branchId, $productId, $cost, $supplierId, $unit, $actorId): AddPurchaseRequestLineResult {
                $locked = DocumentLocks::request($connection, $requestId);

                RequestLines::assertDraft($locked);

                if ($supplierId !== null) {
                    DocumentLocks::activeSupplier($connection, $supplierId);
                }

                $this->lines->assertRoom($connection, $requestId);

                $connection->table('purchase_request_items')->insert([
                    'id' => $lineId,
                    'request_id' => $requestId,
                    'line_no' => RequestLines::nextLineNo($connection, $requestId),
                    'product_id' => $productId,
                    'unit_id' => $unit->unitId,
                    'quantity' => $unit->quantity,
                    'unit_factor' => $unit->unitFactor,
                    'base_quantity' => $unit->baseQuantity,
                    'estimated_cost' => $cost,
                    'supplier_id' => $supplierId,
                    'purchase_order_item_id' => null,
                ]);

                RequestLines::touch($connection, $requestId, $actorId, $this->clock->timestamp());

                $line = RequestImage::line($connection->table('purchase_request_items')->where('id', $lineId)->first()
                    ?? throw PurchasingRefused::notFound("No purchase request line has id [{$lineId}]."));

                $this->audit->record(
                    PurchasingAuditActions::MODULE,
                    $actorId,
                    PurchasingAuditActions::REQUEST_LINE_ADDED,
                    'purchase_request',
                    $requestId,
                    null,
                    $line,
                    $branchId,
                );

                return new AddPurchaseRequestLineResult($lineId, $requestId, false, $line);
            });
        } catch (UniqueConstraintViolationException $violation) {
            // A concurrent add of this id won the primary key; the transaction rolled back.
            $winner = $connection->table('purchase_request_items')->where('id', $lineId)->first();

            if ($winner === null) {
                throw $violation;
            }

            return $this->replay($lineId, $winner, $input, $quantity);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws PurchasingRefused replay_conflict
     */
    private function replay(string $lineId, object $existing, array $input, string $quantity): AddPurchaseRequestLineResult
    {
        $stored = RequestImage::line($existing);

        foreach ($input as $column => $value) {
            if ($stored[$column] !== $value) {
                throw $this->conflict($lineId, $column);
            }
        }

        if (! Money::equals($stored['quantity'], $quantity)) {
            // The unit may have rounded what was asked: the same request, not a different one.
            try {
                $rounded = $this->lines->snapshot($stored['product_id'], $stored['unit_id'], $quantity)->quantity;
            } catch (PurchasingRefused) {
                throw $this->conflict($lineId, 'quantity');
            }

            if (! Money::equals($stored['quantity'], $rounded)) {
                throw $this->conflict($lineId, 'quantity');
            }
        }

        return new AddPurchaseRequestLineResult($lineId, $stored['request_id'], true, $stored);
    }

    private function conflict(string $lineId, string $column): PurchasingRefused
    {
        return PurchasingRefused::because(
            PurchasingRefusal::ReplayConflict,
            "Purchase request line [{$lineId}] already exists with a different [{$column}].",
        );
    }
}
