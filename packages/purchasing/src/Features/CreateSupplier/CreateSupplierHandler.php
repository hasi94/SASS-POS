<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CreateSupplier;

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
use Flexipos\Purchasing\Support\TenantConnection;
use Flexipos\Purchasing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a supplier (T-034 AC-1 to AC-9, AC-17; Ruling 5, Ruling 10).
 *
 * **The id is the server's** (D-35): a supplied `id` is an unknown field, refused
 * `validation` by {@see Fields::only()} (Q-B3). There is therefore no replay: a
 * retried create names a code that now exists and is `duplicate_code` naming the
 * first row, with one audit row in total (AC-9).
 *
 * **The code check runs twice, on purpose.** {@see Uniqueness} is the
 * case-insensitive pre-check (a soft-deleted holder counts, AC-3); the unique
 * index is the backstop for a concurrent insert, and its violation is caught and
 * answered the same way, naming the holder.
 *
 * The supplier keys are tenant-wide, asked with a null branch (AC-18). The audit
 * row's after image carries `bank_details` as the fixed marker only (AC-17).
 */
final class CreateSupplierHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(CreateSupplier $command): CreateSupplierResult
    {
        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::SUPPLIERS_MANAGE, null);

        Fields::only($command->fields, SupplierFields::ACCEPTED);

        $parsed = SupplierFields::parse($command->fields, true);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $parsed, $actorId): CreateSupplierResult {
            $code = (string) $parsed['code'];

            Uniqueness::assertCodeFree($connection, 'suppliers', $code);

            $id = strtoupper((string) Str::ulid());
            $now = $this->clock->timestamp();

            try {
                $connection->table('suppliers')->insert(array_merge($parsed, [
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'address' => SupplierImage::encode($parsed['address']),
                    'bank_details' => SupplierImage::encode($parsed['bank_details']),
                    'is_active' => true,
                ]));
            } catch (UniqueConstraintViolationException) {
                // A concurrent create won between the pre-check and the insert.
                Uniqueness::assertCodeFree($connection, 'suppliers', $code);

                throw PurchasingRefused::because(PurchasingRefusal::DuplicateCode, "The code [{$code}] is already used.");
            }

            $row = $connection->table('suppliers')->where('id', $id)->first();
            $image = SupplierImage::of($row ?? throw PurchasingRefused::notFound("No supplier has id [{$id}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::SUPPLIER_CREATED,
                'supplier',
                $id,
                null,
                SupplierImage::auditAfter(null, $image),
            );

            return new CreateSupplierResult($id, $image);
        });
    }
}
