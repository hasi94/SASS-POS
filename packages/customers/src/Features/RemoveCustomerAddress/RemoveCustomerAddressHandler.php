<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\RemoveCustomerAddress;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;

/**
 * Remove a customer address (T-028 plan Ruling 8, AC-18, D-16): a real
 * `DELETE`, since `customer_addresses` carries no `[SOFT]` tag. An unknown
 * id is refused, naming it, rather than a silent no-op.
 */
final class RemoveCustomerAddressHandler
{
    public const ACTION = 'customer_address_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveCustomerAddress $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $addressId = Identifier::fold($command->addressId, 'addressId');

            $before = $connection->table('customer_addresses')->where('id', $addressId)->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer address [{$addressId}] does not exist.");
            }

            $connection->table('customer_addresses')->where('id', $addressId)->delete();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_address',
                $addressId,
                (array) $before,
                null,
            );
        });
    }
}
