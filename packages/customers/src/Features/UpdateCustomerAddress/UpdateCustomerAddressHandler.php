<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomerAddress;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Features\AddCustomerAddress\AddCustomerAddressHandler;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Update a customer address (T-028 plan Ruling 8, AC-16, AC-17). The
 * `customer_id` itself is immutable here - moving an address between
 * customers is `MergeCustomers`' own concern, never an ordinary update.
 *
 * **Setting `is_default`** clears the previous default of the same
 * `(customer_id, type)` first, the same backstop {@see
 * AddCustomerAddressHandler} uses - the type this row already has, since the
 * type cannot change through this handler either (the unique key reads both
 * columns together, so changing `type` while defaulting would target the
 * wrong clearing scope).
 */
final class UpdateCustomerAddressHandler
{
    public const ACTION = 'customer_address_updated';

    public const FIELDS = ['label', 'address', 'is_default', 'geo'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateCustomerAddress $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $addressId = Identifier::fold($command->addressId, 'addressId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $addressId, $fields): void {
            $before = $connection->table('customer_addresses')->where('id', $addressId)->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer address [{$addressId}] does not exist.");
            }

            $changes = [];

            if (Fields::has($fields, 'label')) {
                $changes['label'] = Fields::optionalText($fields, 'label', 100);
            }

            if (Fields::has($fields, 'address')) {
                $changes['address'] = Fields::json($fields, 'address');
            }

            if (Fields::has($fields, 'geo')) {
                $changes['geo'] = Fields::json($fields, 'geo');
            }

            if (Fields::has($fields, 'is_default')) {
                $isDefault = Fields::flag($fields, 'is_default', false);

                if ($isDefault) {
                    $connection->table('customer_addresses')
                        ->where('customer_id', $before->customer_id)
                        ->where('type', $before->type)
                        ->where('id', '!=', $addressId)
                        ->where('is_default', true)
                        ->update(['is_default' => false]);
                }

                $changes['is_default'] = $isDefault;
            }

            $changes['updated_at'] = $this->clock->timestamp();

            $connection->table('customer_addresses')->where('id', $addressId)->update($changes);

            $after = $connection->table('customer_addresses')->where('id', $addressId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_address',
                $addressId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
