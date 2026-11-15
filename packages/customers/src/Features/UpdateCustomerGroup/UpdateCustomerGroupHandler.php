<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomerGroup;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a customer group (T-028 plan Ruling 7, AC-1, AC-2, AC-4, AC-5).
 *
 * **Optimistic concurrency** (AC-4): the request must name `version`; the
 * row is read with `lockForUpdate()`; a mismatch is `stale`, naming the
 * current version; a success increments it.
 */
final class UpdateCustomerGroupHandler
{
    public const ACTION = 'customer_group_updated';

    public const FIELDS = [
        'version', 'code', 'name', 'discount_percent', 'credit_allowed', 'default_credit_limit',
        'default_payment_terms_days', 'is_default', 'loyalty_multiplier',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateCustomerGroup $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $version = Fields::requiredVersion($fields);
        $groupId = Identifier::fold($command->groupId, 'groupId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $groupId, $fields, $version): void {
            $before = $connection->table('customer_groups')->where('id', $groupId)->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer group [{$groupId}] does not exist.");
            }

            if ((int) $before->version !== $version) {
                throw CustomersRefused::stale("The customer group [{$groupId}] is at version [{$before->version}], not [{$version}].");
            }

            $changes = [];

            if (Fields::has($fields, 'code')) {
                $changes['code'] = Fields::requiredText($fields, 'code', 50);
            }

            if (Fields::has($fields, 'name')) {
                $changes['name'] = Fields::requiredText($fields, 'name', 150);
            }

            if (Fields::has($fields, 'discount_percent')) {
                $changes['discount_percent'] = Fields::decimal($fields, 'discount_percent', 4, false, null, 5);
            }

            if (Fields::has($fields, 'credit_allowed')) {
                $changes['credit_allowed'] = Fields::flag($fields, 'credit_allowed', false);
            }

            if (Fields::has($fields, 'default_credit_limit')) {
                $changes['default_credit_limit'] = Fields::decimal($fields, 'default_credit_limit', 4, false, null, 14);
            }

            if (Fields::has($fields, 'default_payment_terms_days')) {
                $changes['default_payment_terms_days'] = Fields::unsignedSmallInt($fields, 'default_payment_terms_days', null);
            }

            if (Fields::has($fields, 'loyalty_multiplier')) {
                $changes['loyalty_multiplier'] = Fields::decimal($fields, 'loyalty_multiplier', 4, false, null, 5);
            }

            $settingDefault = Fields::has($fields, 'is_default') && Fields::flag($fields, 'is_default', false);

            if (Fields::has($fields, 'is_default')) {
                $changes['is_default'] = Fields::flag($fields, 'is_default', false);
            }

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                $duplicate = $connection->table('customer_groups')
                    ->whereRaw('lower(code) = ?', [strtolower($changes['code'])])
                    ->where('id', '!=', $groupId)
                    ->exists();

                if ($duplicate) {
                    throw CustomersRefused::duplicate("The code [{$changes['code']}] is already in use.");
                }
            }

            if ($settingDefault) {
                $connection->table('customer_groups')->where('id', '!=', $groupId)->where('is_default', true)->update(['is_default' => false]);
            }

            $changes['version'] = $version + 1;
            $changes['updated_at'] = $this->clock->timestamp();

            try {
                $connection->table('customer_groups')->where('id', $groupId)->update($changes);
            } catch (UniqueConstraintViolationException) {
                throw CustomersRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('customer_groups')->where('id', $groupId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_group',
                $groupId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
