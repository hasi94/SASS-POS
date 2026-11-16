<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\UpdatePurchaseRequestLine;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Catalog\UnitSnapshot;
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

/**
 * Edit a line of a draft request (T-034 AC-22 to AC-25, AC-39; Ruling 7, D-14).
 *
 * **A field left out keeps what the line holds**; `estimated_cost` and
 * `supplier_id` set to null clear it. When the **unit or the quantity** changes
 * the whole quad is resolved again with `UnitResolver::snapshot()` (D-14) and the
 * product is judged again ({@see ProductFacts}); when only the cost or the supplier
 * changes the stored quad is kept untouched, so a line is never re-snapshotted for
 * a reason that does not concern its unit (AC-24).
 *
 * Resolved before the transaction (Ruling 7); inside it, the header `FOR UPDATE`,
 * then the line, then the supplier `FOR SHARE` **only when the supplier is
 * changed** (a line may keep a supplier that has since been deactivated, and an
 * edit of its quantity is not a reason to refuse it). If the line moved between the
 * plain read the quad was resolved from and the lock, the edit is refused
 * `replay_conflict` rather than written over what it was computed from. A call that
 * changes nothing writes nothing.
 */
final class UpdatePurchaseRequestLineHandler
{
    private const FIELDS = ['request_id', 'line_id', 'quantity', 'unit_id', 'estimated_cost', 'supplier_id'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly ProductFacts $products,
        private readonly RequestLines $lines,
    ) {}

    public function handle(UpdatePurchaseRequestLine $command): UpdatePurchaseRequestLineResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $requestId = Identifier::fold($fields['request_id'] ?? null, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        $read = RequestImage::line(RequestLines::line($connection, $requestId, $fields['line_id'] ?? null));

        $submitted = [];

        if (array_key_exists('quantity', $fields)) {
            $submitted['quantity'] = LineInput::quantity($fields['quantity']);
        }

        if (array_key_exists('unit_id', $fields)) {
            $submitted['unit_id'] = Identifier::fold($fields['unit_id'], 'unit_id');
        }

        if (array_key_exists('estimated_cost', $fields)) {
            $submitted['estimated_cost'] = LineInput::cost($fields['estimated_cost'], 'estimated_cost');
        }

        if (array_key_exists('supplier_id', $fields)) {
            $submitted['supplier_id'] = Identifier::foldNullable($fields['supplier_id'], 'supplier_id');
        }

        $unit = null;

        if ($this->changesQuad($read, $submitted)) {
            $this->products->one($connection, $read['product_id']);

            $unit = $this->lines->snapshot(
                $read['product_id'],
                $submitted['unit_id'] ?? $read['unit_id'],
                $submitted['quantity'] ?? $read['quantity'],
            );
        }

        return $connection->transaction(function () use ($connection, $requestId, $branchId, $read, $submitted, $unit, $actorId): UpdatePurchaseRequestLineResult {
            $locked = DocumentLocks::request($connection, $requestId);

            RequestLines::assertDraft($locked);

            $before = RequestImage::line(RequestLines::line($connection, $requestId, $read['id'], true));

            if ($unit !== null && ($before['unit_id'] !== $read['unit_id'] || ! Money::equals($before['quantity'], $read['quantity']))) {
                throw PurchasingRefused::because(
                    PurchasingRefusal::ReplayConflict,
                    "Line [{$read['id']}] changed while it was being edited.",
                );
            }

            $changes = $this->changes($before, $submitted, $unit);

            if ($changes === []) {
                return new UpdatePurchaseRequestLineResult($read['id'], $requestId, $before, []);
            }

            if (array_key_exists('supplier_id', $changes) && $changes['supplier_id'] !== null) {
                DocumentLocks::activeSupplier($connection, $changes['supplier_id']);
            }

            $connection->table('purchase_request_items')->where('id', $read['id'])->update($changes);

            RequestLines::touch($connection, $requestId, $actorId, $this->clock->timestamp());

            $after = RequestImage::line($connection->table('purchase_request_items')->where('id', $read['id'])->first()
                ?? throw PurchasingRefused::notFound("No purchase request line has id [{$read['id']}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::REQUEST_LINE_UPDATED,
                'purchase_request',
                $requestId,
                $before,
                $after,
                $branchId,
            );

            return new UpdatePurchaseRequestLineResult($read['id'], $requestId, $after, array_keys($changes));
        });
    }

    /**
     * Whether the unit or the quantity is being changed, which is what decides the
     * quad is resolved again.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $submitted
     */
    private function changesQuad(array $line, array $submitted): bool
    {
        if (isset($submitted['unit_id']) && $submitted['unit_id'] !== $line['unit_id']) {
            return true;
        }

        return isset($submitted['quantity']) && ! Money::equals((string) $submitted['quantity'], (string) $line['quantity']);
    }

    /**
     * The columns that differ, as they will be written.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function changes(array $stored, array $submitted, ?UnitSnapshot $unit): array
    {
        $changes = [];

        if ($unit !== null) {
            $quad = [
                'unit_id' => $unit->unitId,
                'quantity' => $unit->quantity,
                'unit_factor' => $unit->unitFactor,
                'base_quantity' => $unit->baseQuantity,
            ];

            foreach ($quad as $column => $value) {
                if ($stored[$column] !== $value) {
                    $changes[$column] = $value;
                }
            }
        }

        foreach (['estimated_cost', 'supplier_id'] as $column) {
            if (array_key_exists($column, $submitted) && $stored[$column] !== $submitted[$column]) {
                $changes[$column] = $submitted[$column];
            }
        }

        return $changes;
    }
}
