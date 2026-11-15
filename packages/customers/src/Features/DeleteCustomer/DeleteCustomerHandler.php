<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\DeleteCustomer;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a customer (T-028 plan Ruling 7, AC-10, D-9).
 *
 * **Refused while `credit_balance` is not zero**, compared as a decimal
 * and not as a string, naming the balance. History (ledger/loyalty rows,
 * addresses, notes) does not block deletion on its own.
 */
final class DeleteCustomerHandler
{
    public const ACTION = 'customer_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteCustomer $command): void
    {
        $customerId = Identifier::fold($command->customerId, 'customerId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $customerId): void {
            $before = $connection->table('customers')->where('id', $customerId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer [{$customerId}] does not exist.");
            }

            if (! BigDecimal::of((string) $before->credit_balance)->isZero()) {
                throw CustomersRefused::balanceOutstanding("Customer [{$customerId}] has an outstanding balance of [{$before->credit_balance}].");
            }

            $now = $this->clock->timestamp();

            $connection->table('customers')->where('id', $customerId)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'version' => (int) $before->version + 1,
            ]);

            $after = $connection->table('customers')->where('id', $customerId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer',
                $customerId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
