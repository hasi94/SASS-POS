<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\DeleteCustomerGroup;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a customer group (T-028 plan Ruling 7, AC-3; F-19).
 *
 * **Refused while a live customer still references the group**, naming
 * that customer and a count. Soft-deleting the current default clears
 * `is_default` in the same transaction (F-19), so AC-2's backstop never
 * has to represent a deleted default.
 */
final class DeleteCustomerGroupHandler
{
    public const ACTION = 'customer_group_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteCustomerGroup $command): void
    {
        $groupId = Identifier::fold($command->groupId, 'groupId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $groupId): void {
            $before = $connection->table('customer_groups')->where('id', $groupId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer group [{$groupId}] does not exist.");
            }

            $blocking = $connection->table('customers')->where('group_id', $groupId)->whereNull('deleted_at')->first();
            $count = $connection->table('customers')->where('group_id', $groupId)->whereNull('deleted_at')->count();

            if ($blocking !== null) {
                throw CustomersRefused::inUse("The customer group [{$groupId}] is referenced by [{$count}] live customer(s), including [{$blocking->id}].");
            }

            $now = $this->clock->timestamp();

            $connection->table('customer_groups')->where('id', $groupId)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'is_default' => false,
                'version' => (int) $before->version + 1,
            ]);

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
