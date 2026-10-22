<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateBranch;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\BranchLookup;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\OrgCode;
use Flexipos\Organization\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a branch, its `branch_store` location and the link between them, as
 * one unit (T-014 AC-10, D-11, D-39).
 *
 * **Three writes, one transaction.** The branch row, the location row (the
 * branch's own code and name, `type = branch_store`, sellable and
 * purchasable, negative stock off) and the update that points
 * `branches.default_location_id` at it. A failure at any of them takes the
 * others with it, and the audit row, so no branch exists without its store
 * and no audit row records a branch that does not exist.
 *
 * **A location already holding the branch's code refuses the creation**
 * (`validation`, D-39): the store takes the branch's code, `locations.code` is
 * unique, and a second code invented for the store would be a rule nobody
 * asked for. The check runs before any write; the unique key is still the
 * backstop for a concurrent request, and its violation is the same refusal.
 *
 * Fields the operation does not take are refused, not ignored, so a request
 * naming `is_active`, `default_location_id`, `id` or `deleted_at` is
 * `validation` with nothing written. `timezone` and `currency` have no
 * default (D-13): inventing one would be a guess about the business.
 */
final class CreateBranchHandler
{
    public const ACTION = 'branch_created';

    /** The fields a creation takes. */
    public const FIELDS = [
        'code', 'name', 'address', 'phone', 'email', 'timezone', 'currency',
        'tax_registration_no', 'business_type', 'opening_date', 'sort_order',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateBranch $command): CreateBranchResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $code = OrgCode::normalise($fields['code'] ?? null);
        $name = Fields::requiredText($fields, 'name', 100);
        $values = [
            'address' => Fields::address($fields),
            'phone' => Fields::optionalText($fields, 'phone', 30),
            'email' => Fields::email($fields),
            'timezone' => Fields::timezone($fields),
            'currency' => Fields::currency($fields),
            'tax_registration_no' => Fields::optionalText($fields, 'tax_registration_no', 50),
            'business_type' => Fields::businessType($fields),
            'opening_date' => Fields::date($fields, 'opening_date'),
            'sort_order' => Fields::integer($fields, 'sort_order', 0),
        ];

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $code, $name, $values): CreateBranchResult {
            // Soft-deleted rows count: a code is held forever (AC-2).
            if ($connection->table('branches')->where('code', $code)->exists()
                || $connection->table('locations')->where('code', $code)->exists()) {
                throw OrganizationRefused::validation("The code [{$code}] is already in use.");
            }

            $now = $this->clock->timestamp();
            $branchId = (string) Str::ulid();
            $locationId = (string) Str::ulid();

            try {
                $connection->table('branches')->insert($values + [
                    'id' => $branchId,
                    'code' => $code,
                    'name' => $name,
                    'is_active' => true,
                    'created_by' => $command->actorId,
                    'updated_by' => $command->actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $connection->table('locations')->insert([
                    'id' => $locationId,
                    'branch_id' => $branchId,
                    'code' => $code,
                    'name' => $name,
                    'type' => 'branch_store',
                    'allow_negative_stock' => false,
                    'is_sellable_from' => true,
                    'is_purchasable_to' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_by' => $command->actorId,
                    'updated_by' => $command->actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw OrganizationRefused::validation("The code [{$code}] is already in use.");
            }

            $connection->table('branches')->where('id', $branchId)->update(['default_location_id' => $locationId]);

            $row = $connection->table('branches')->where('id', $branchId)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'branch',
                $branchId,
                null,
                $row === null ? null : BranchLookup::audited($row),
                $branchId,
            );

            return new CreateBranchResult($branchId, $locationId, $code);
        });
    }
}
