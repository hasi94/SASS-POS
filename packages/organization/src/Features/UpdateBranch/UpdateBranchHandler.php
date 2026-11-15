<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\UpdateBranch;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\BranchLookup;
use Flexipos\Organization\Support\Fields;
use Flexipos\Organization\Support\TenantConnection;

/**
 * Update a branch's name, address, contact details, timezone, currency, tax
 * number, business type, opening date or sort order (T-014 AC-12).
 *
 * **A field outside that list is refused `validation`, not ignored**: `code`
 * is immutable (D-13), `id` and `deleted_at` are not the caller's, and
 * `is_active` and `default_location_id` change only through their own
 * operations (AC-14, AC-24). Only the fields submitted change, so a PATCH
 * naming `phone` leaves the name alone; an update naming nothing is refused,
 * because an audit row for a change that did not happen would be a false
 * record.
 *
 * It bumps no employee's `perm_version`: a branch's contact details change
 * nothing about anybody's rights.
 */
final class UpdateBranchHandler
{
    public const ACTION = 'branch_updated';

    public const FIELDS = [
        'name', 'address', 'phone', 'email', 'timezone', 'currency',
        'tax_registration_no', 'business_type', 'opening_date', 'sort_order',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateBranch $command): void
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->fields === []) {
            throw OrganizationRefused::validation('An update must name at least one field.');
        }

        $fields = $command->fields;
        $changes = [];

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 100);
        }

        if (Fields::has($fields, 'address')) {
            $changes['address'] = Fields::address($fields);
        }

        foreach (['phone' => 30, 'tax_registration_no' => 50] as $key => $max) {
            if (Fields::has($fields, $key)) {
                $changes[$key] = Fields::optionalText($fields, $key, $max);
            }
        }

        if (Fields::has($fields, 'email')) {
            $changes['email'] = Fields::email($fields);
        }

        if (Fields::has($fields, 'timezone')) {
            $changes['timezone'] = Fields::timezone($fields);
        }

        if (Fields::has($fields, 'currency')) {
            $changes['currency'] = Fields::currency($fields);
        }

        if (Fields::has($fields, 'business_type')) {
            $changes['business_type'] = Fields::businessType($fields);
        }

        if (Fields::has($fields, 'opening_date')) {
            $changes['opening_date'] = Fields::date($fields, 'opening_date');
        }

        if (Fields::has($fields, 'sort_order')) {
            $changes['sort_order'] = Fields::integer($fields, 'sort_order', 0);
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $changes): void {
            $before = BranchLookup::find($connection, $command->branchId, lock: true);

            $connection->table('branches')->where('id', (string) $before->id)->update($changes + [
                'updated_by' => $command->actorId,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $connection->table('branches')->where('id', (string) $before->id)->first();

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                self::ACTION,
                'branch',
                (string) $before->id,
                BranchLookup::audited($before),
                $after === null ? null : BranchLookup::audited($after),
                (string) $before->id,
            );
        });
    }
}
