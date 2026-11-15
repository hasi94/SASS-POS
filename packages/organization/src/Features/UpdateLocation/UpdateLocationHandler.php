<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\UpdateLocation;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\Identifier;
use Flexipos\Organization\Support\LocationLookup;
use Flexipos\Organization\Support\TenantConnection;

/**
 * Update a location's name, address, manager, flags or sort order
 * (T-014 AC-23).
 *
 * **`code`, `type`, `branch_id` and `is_active` are refused `validation`,
 * never ignored**: the code is immutable (D-13), the type and the branch are
 * what the location is, and activation changes only through its own operation
 * (AC-24). Only the fields submitted change, and an update naming nothing is
 * refused so that no audit row records a change that did not happen.
 */
final class UpdateLocationHandler
{
    public const ACTION = 'location_updated';

    public const FIELDS = [
        'name', 'address', 'manager_employee_id', 'allow_negative_stock',
        'is_sellable_from', 'is_purchasable_to', 'sort_order',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(UpdateLocation $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw OrganizationRefused::validation('An update must name at least one field.');
        }

        $changes = [];

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 100);
        }

        if (Fields::has($fields, 'address')) {
            $changes['address'] = Fields::address($fields);
        }

        if (Fields::has($fields, 'manager_employee_id')) {
            $manager = null;

            if ($fields['manager_employee_id'] !== null) {
                $manager = Identifier::fold($fields['manager_employee_id'], 'manager_employee_id');

                if (! $this->employees->isLive($manager)) {
                    throw OrganizationRefused::validation('The manager_employee_id names no employee.');
                }
            }

            $changes['manager_employee_id'] = $manager;
        }

        foreach (['allow_negative_stock', 'is_sellable_from', 'is_purchasable_to'] as $flag) {
            if (Fields::has($fields, $flag)) {
                $changes[$flag] = Fields::flag($fields, $flag, false);
            }
        }

        if (Fields::has($fields, 'sort_order')) {
            $changes['sort_order'] = Fields::integer($fields, 'sort_order', 0);
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $changes): void {
            $before = LocationLookup::find($connection, $command->locationId, lock: true);

            $connection->table('locations')->where('id', (string) $before->id)->update($changes + [
                'updated_by' => $command->actorId,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $connection->table('locations')->where('id', (string) $before->id)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'location',
                (string) $before->id,
                LocationLookup::audited($before),
                $after === null ? null : LocationLookup::audited($after),
                $before->branch_id === null ? null : (string) $before->branch_id,
            );
        });
    }
}
