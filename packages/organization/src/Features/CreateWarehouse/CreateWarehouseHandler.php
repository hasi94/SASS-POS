<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\CreateWarehouse;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\BranchGuard;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\Identifier;
use Flexipos\Organization\Support\LocationLookup;
use Flexipos\Organization\Support\OrgCode;
use Flexipos\Organization\Support\PlanCounts;
use Flexipos\Organization\Support\TenantConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a warehouse (T-014 AC-21, AC-22, AC-15, D-11).
 *
 * **A warehouse may belong to a branch or to nobody.** `branch_id` null is a
 * central warehouse; a branch must be real and active (`unknown_branch`,
 * `branch_inactive`), the one rule creating anything under a branch follows.
 * The defaults for an unsupplied flag are D-11's: sellable false, purchasable
 * true, negative stock off. `manager_employee_id` must name a live employee,
 * asked of access through the published directory and never of `employees`.
 *
 * **Every other type is refused `validation`, with nothing written**: the
 * store exists only through a branch's creation, and `transit`,
 * `consignment`, `damaged` and `virtual` are FP-251's. An unknown type is the
 * same refusal.
 */
final class CreateWarehouseHandler
{
    public const ACTION = 'location_created';

    public const FIELDS = [
        'code', 'name', 'type', 'branch_id', 'address', 'manager_employee_id',
        'allow_negative_stock', 'is_sellable_from', 'is_purchasable_to', 'sort_order',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
        private readonly PlanLimits $planLimits,
    ) {}

    /**
     * @return array{location_id: string, code: string}
     */
    public function handle(CreateWarehouse $command): array
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if (($fields['type'] ?? 'warehouse') !== 'warehouse') {
            throw OrganizationRefused::validation('Only a warehouse can be created here.');
        }

        $code = OrgCode::normalise($fields['code'] ?? null);
        $name = Fields::requiredText($fields, 'name', 100);
        $address = Fields::address($fields);
        $negative = Fields::flag($fields, 'allow_negative_stock', false);
        $sellable = Fields::flag($fields, 'is_sellable_from', false);
        $purchasable = Fields::flag($fields, 'is_purchasable_to', true);
        $sort = Fields::integer($fields, 'sort_order', 0);

        $manager = null;

        if (($fields['manager_employee_id'] ?? null) !== null) {
            $manager = Identifier::fold($fields['manager_employee_id'], 'manager_employee_id');

            if (! $this->employees->isLive($manager)) {
                throw OrganizationRefused::validation('The manager_employee_id names no employee.');
            }
        }

        // T-016 AC-2: resolved before the transaction, asserted first inside it.
        $locationLimit = $this->planLimits->forCurrentTenant(LimitKey::Locations);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $fields, $code, $name, $address, $negative, $sellable, $purchasable, $sort, $manager, $locationLimit): array {
            $locationLimit->assertRoomFor(PlanCounts::activeLocations($connection));

            $branchId = null;

            if (($fields['branch_id'] ?? null) !== null) {
                $branchId = (string) BranchGuard::requireActive($connection, $fields['branch_id'])->id;
            }

            // Soft-deleted rows count: a code is held forever.
            if ($connection->table('locations')->where('code', $code)->exists()) {
                throw OrganizationRefused::validation("The code [{$code}] is already in use.");
            }

            $now = $this->clock->timestamp();
            $id = (string) Str::ulid();

            try {
                $connection->table('locations')->insert([
                    'id' => $id,
                    'branch_id' => $branchId,
                    'code' => $code,
                    'name' => $name,
                    'type' => 'warehouse',
                    'address' => $address,
                    'manager_employee_id' => $manager,
                    'allow_negative_stock' => $negative,
                    'is_sellable_from' => $sellable,
                    'is_purchasable_to' => $purchasable,
                    'is_active' => true,
                    'sort_order' => $sort,
                    'created_by' => $command->actorId,
                    'updated_by' => $command->actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw OrganizationRefused::validation("The code [{$code}] is already in use.");
            }

            $row = $connection->table('locations')->where('id', $id)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'location',
                $id,
                null,
                $row === null ? null : LocationLookup::audited($row),
                $branchId,
            );

            return ['location_id' => $id, 'code' => $code];
        });
    }
}
